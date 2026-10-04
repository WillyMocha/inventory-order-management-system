<?php

declare(strict_types=1);

namespace Tests\Unit\Service;

use App\Entity\Customer;
use App\Entity\Enum\Role;
use App\Entity\Enum\SalesOrderStatus;
use App\Entity\Product;
use App\Entity\User;
use App\Entity\Warehouse;
use App\Service\SalesOrderApprovalService;
use App\Service\SalesOrderService;
use App\Support\Exception\DomainException;
use App\Support\Exception\ForbiddenException;
use App\Support\Exception\NotFoundException;
use App\Support\Exception\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Tests\Unit\Fake\FixedClock;
use Tests\Unit\Fake\ImmediateTransactionRunner;
use Tests\Unit\Fake\InMemoryCustomerRepository;
use Tests\Unit\Fake\InMemoryProductRepository;
use Tests\Unit\Fake\InMemorySalesOrderRepository;
use Tests\Unit\Fake\InMemoryWarehouseRepository;

/**
 * Unit test SalesOrderService (SO-01, FR-016 s/d FR-018).
 *
 * Seluruhnya terhadap fake in-memory — tanpa database, tanpa session
 * (constitution Principle III). Acting user di-pass sebagai argument, itulah
 * yang membuat aturan approval dapat diuji tanpa session sama sekali.
 */
final class SalesOrderServiceTest extends TestCase
{
    private InMemorySalesOrderRepository $orders;
    private SalesOrderService $service;
    private SalesOrderApprovalService $approvals;

    private User $admin;
    private User $secondAdmin;
    private User $sales;
    private User $otherSales;
    private User $warehouse;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = new User(1, 'Admin One', 'admin@ioms.test', 'hash', Role::Admin, true);
        $this->secondAdmin = new User(2, 'Admin Two', 'admin2@ioms.test', 'hash', Role::Admin, true);
        $this->sales = new User(3, 'Sales One', 'sales1@ioms.test', 'hash', Role::Sales, true);
        $this->otherSales = new User(4, 'Sales Two', 'sales2@ioms.test', 'hash', Role::Sales, true);
        $this->warehouse = new User(5, 'Warehouse One', 'wh1@ioms.test', 'hash', Role::WarehouseStaff, true);

        $this->orders = new InMemorySalesOrderRepository();
        $clock = new FixedClock('2026-09-11 10:00:00');

        $this->service = new SalesOrderService(
            $this->orders,
            new InMemoryCustomerRepository([
                new Customer(10, 'Customer A', '0800', 'Jakarta', true),
                new Customer(11, 'Closed Customer', '0800', 'Jakarta', false),
            ]),
            new InMemoryWarehouseRepository([
                new Warehouse(20, 'Main Warehouse', 'Jakarta', true),
                new Warehouse(21, 'Closed Warehouse', 'Bogor', false),
            ]),
            new InMemoryProductRepository([
                new Product(30, 'SKU-A', 'Product A', 1, 'pcs', '1000.00', '1500.00', 5, null, true),
                new Product(31, 'SKU-B', 'Product B', 1, 'pcs', '2000.00', '2500.00', 5, null, true),
                new Product(32, 'SKU-C', 'Retired Product', 1, 'pcs', '500.00', '700.00', 5, null, false),
            ]),
            $clock,
            new ImmediateTransactionRunner(),
        );

        // Approve/reject ada di service tersendiri sejak tech-debt TD-11; test
        // approval tetap di file ini karena memakai fixture order yang sama.
        $this->approvals = new SalesOrderApprovalService($this->service, $this->orders, $clock);
    }

    // ------------------------------------------------------------ create

    #[Test]
    public function createsADraftOrderWithItsLines(): void
    {
        $id = $this->service->create($this->validPayload(), $this->sales);

        $order = $this->orders->findById($id);

        self::assertNotNull($order);
        self::assertSame(SalesOrderStatus::Draft, $order->status);
        self::assertSame(10, $order->customerId);
        self::assertSame(20, $order->warehouseId);
        self::assertCount(2, $order->items);
    }

    #[Test]
    public function recordsCreatedByFromTheActingUserNeverFromInput(): void
    {
        // Payload berusaha menyuntikkan created_by milik orang lain. Nilai itu
        // harus diabaikan sepenuhnya — created_by hanya boleh berasal dari
        // acting user yang di-pass ke Service (§1.2, FR-016).
        $payload = $this->validPayload();
        $payload['created_by'] = $this->admin->id;

        $id = $this->service->create($payload, $this->sales);
        $order = $this->orders->findById($id);

        self::assertNotNull($order);
        self::assertSame($this->sales->id, $order->createdBy);
        self::assertNull($order->approvedBy);
    }

    #[Test]
    public function snapshotsTheSellingPriceFromTheCatalogNotFromInput(): void
    {
        // Harga tidak boleh dipercayai dari request: pembeli tidak menentukan
        // harganya sendiri.
        $payload = $this->validPayload();
        $payload['items'][0]['selling_price'] = '1.00';

        $id = $this->service->create($payload, $this->sales);
        $order = $this->orders->findById($id);

        self::assertNotNull($order);
        self::assertSame('1500.00', $order->items[0]->sellingPrice);
    }

    #[Test]
    public function requiresACustomer(): void
    {
        $payload = $this->validPayload();
        $payload['customer_id'] = '';

        $this->expectException(ValidationException::class);

        $this->service->create($payload, $this->sales);
    }

    #[Test]
    public function requiresAnExistingCustomer(): void
    {
        $payload = $this->validPayload();
        $payload['customer_id'] = '999';

        try {
            $this->service->create($payload, $this->sales);
            self::fail('Customer yang tidak ada seharusnya ditolak');
        } catch (ValidationException $e) {
            self::assertArrayHasKey('customer_id', $e->errors());
        }
    }

    #[Test]
    public function requiresASourceWarehouse(): void
    {
        $payload = $this->validPayload();
        $payload['warehouse_id'] = '';

        try {
            $this->service->create($payload, $this->sales);
            self::fail('Warehouse asal wajib diisi');
        } catch (ValidationException $e) {
            self::assertArrayHasKey('warehouse_id', $e->errors());
        }
    }

    #[Test]
    public function requiresAtLeastOneLine(): void
    {
        $payload = $this->validPayload();
        $payload['items'] = [];

        try {
            $this->service->create($payload, $this->sales);
            self::fail('Order tanpa line seharusnya ditolak');
        } catch (ValidationException $e) {
            self::assertArrayHasKey('items', $e->errors());
        }
    }

    #[Test]
    public function rejectsALineWithANonPositiveQuantity(): void
    {
        $payload = $this->validPayload();
        $payload['items'][0]['quantity'] = '0';

        $this->expectException(ValidationException::class);

        $this->service->create($payload, $this->sales);
    }

    #[Test]
    public function rejectsALineReferencingAnUnknownProduct(): void
    {
        $payload = $this->validPayload();
        $payload['items'][0]['product_id'] = '999';

        $this->expectException(ValidationException::class);

        $this->service->create($payload, $this->sales);
    }

    #[Test]
    public function nothingIsPersistedWhenValidationFails(): void
    {
        $payload = $this->validPayload();
        $payload['items'] = [];

        try {
            $this->service->create($payload, $this->sales);
        } catch (ValidationException) {
            // diabaikan: yang diperiksa adalah efek sampingnya
        }

        self::assertSame(0, $this->orders->countBy([]));
    }

    #[Test]
    public function generatesAUniqueOrderNumber(): void
    {
        $first = $this->orders->findById($this->service->create($this->validPayload(), $this->sales));
        $second = $this->orders->findById($this->service->create($this->validPayload(), $this->sales));

        self::assertNotNull($first);
        self::assertNotNull($second);
        self::assertNotSame($first->orderNumber, $second->orderNumber);
        self::assertStringStartsWith('SO-20260911-', $first->orderNumber);
    }

    // --------------------------------------- referensi nonaktif (TD-10)

    /** @return array<string, array{string, string, string}> */
    public static function inactiveReferences(): array
    {
        $inactive = ' is inactive. Choose an active one.';

        return [
            'inactive customer'  => ['customer_id', '11', 'The selected customer' . $inactive],
            'inactive warehouse' => ['warehouse_id', '21', 'The selected source warehouse' . $inactive],
            'unknown customer'   => ['customer_id', '99', 'The selected customer does not exist.'],
        ];
    }

    #[Test]
    #[DataProvider('inactiveReferences')]
    public function anInactiveCustomerOrWarehouseIsRefusedWhenCreating(string $field, string $id, string $message): void
    {
        // Dropdown form hanya berisi record aktif, tetapi request yang dirakit
        // sendiri tidak boleh lolos dari aturan itu (tech-debt TD-10).
        $payload = $this->validPayload();
        $payload[$field] = $id;

        try {
            $this->service->create($payload, $this->sales);
            self::fail('Referensi nonaktif seharusnya ditolak');
        } catch (ValidationException $e) {
            self::assertSame($message, $e->errors()[$field] ?? null);
        }

        self::assertSame(0, $this->orders->countBy([]), 'Tidak ada order yang tersimpan');
    }

    #[Test]
    public function anInactiveProductLineIsRefusedWhenCreating(): void
    {
        $payload = $this->validPayload();
        $payload['items'][1]['product_id'] = '32';

        try {
            $this->service->create($payload, $this->sales);
            self::fail('Product nonaktif seharusnya ditolak');
        } catch (ValidationException $e) {
            self::assertSame(
                'Retired Product is inactive. Choose an active product.',
                $e->errors()['items.1.product_id'] ?? null,
            );
        }
    }

    // ------------------------------------------------------- transitions

    #[Test]
    public function submitMovesADraftToPendingApproval(): void
    {
        $id = $this->service->create($this->validPayload(), $this->sales);

        $this->service->submit($id, $this->sales);

        self::assertSame(SalesOrderStatus::PendingApproval, $this->statusOf($id));
    }

    #[Test]
    public function submitRefusesAnOrderThatIsNotADraft(): void
    {
        $id = $this->service->create($this->validPayload(), $this->sales);
        $this->service->submit($id, $this->sales);

        $this->expectException(DomainException::class);

        $this->service->submit($id, $this->sales);
    }

    #[Test]
    public function cancelIsAllowedFromDraft(): void
    {
        $id = $this->service->create($this->validPayload(), $this->sales);

        $this->service->cancel($id, $this->sales);

        self::assertSame(SalesOrderStatus::Cancelled, $this->statusOf($id));
    }

    #[Test]
    public function cancelIsAllowedFromPendingApproval(): void
    {
        $id = $this->service->create($this->validPayload(), $this->sales);
        $this->service->submit($id, $this->sales);

        $this->service->cancel($id, $this->admin);

        self::assertSame(SalesOrderStatus::Cancelled, $this->statusOf($id));
    }

    #[Test]
    public function cancelIsAllowedFromApproved(): void
    {
        $id = $this->approvedOrder();

        $this->service->cancel($id, $this->admin);

        self::assertSame(SalesOrderStatus::Cancelled, $this->statusOf($id));
    }

    #[Test]
    public function cancelIsRefusedOnceCancelled(): void
    {
        $id = $this->service->create($this->validPayload(), $this->sales);
        $this->service->cancel($id, $this->sales);

        $this->expectException(DomainException::class);

        $this->service->cancel($id, $this->sales);
    }

    #[Test]
    public function cancelIsRefusedOnceFulfilled(): void
    {
        $id = $this->approvedOrder();
        $this->orders->updateStatus($id, SalesOrderStatus::Approved, SalesOrderStatus::Fulfilled);

        $this->expectException(DomainException::class);

        $this->service->cancel($id, $this->admin);
    }

    #[Test]
    public function noTransitionLeavesAFulfilledOrder(): void
    {
        $id = $this->approvedOrder();
        $this->orders->updateStatus($id, SalesOrderStatus::Approved, SalesOrderStatus::Fulfilled);

        foreach (SalesOrderStatus::cases() as $target) {
            self::assertFalse(
                $this->orders->findById($id)?->canTransitionTo($target),
                'Fulfilled bersifat terminal, tidak boleh ada transisi ke ' . $target->value,
            );
        }
    }

    #[Test]
    public function noTransitionLeavesACancelledOrder(): void
    {
        $id = $this->service->create($this->validPayload(), $this->sales);
        $this->service->cancel($id, $this->sales);

        foreach (SalesOrderStatus::cases() as $target) {
            self::assertFalse(
                $this->orders->findById($id)?->canTransitionTo($target),
                'Cancelled bersifat terminal, tidak boleh ada transisi ke ' . $target->value,
            );
        }
    }

    #[Test]
    public function submitRefusesAnOrderThatDoesNotExist(): void
    {
        $this->expectException(NotFoundException::class);

        $this->service->submit(999, $this->sales);
    }

    // ---------------------------------------------------------- approval
    //
    // Bagian ini adalah aturan paling bernilai di seluruh suite: segregation
    // of duties (FR-018, §1.2). Ditegakkan di Service, sehingga pemanggil yang
    // melewati UI tetap ditolak.

    #[Test]
    public function aSalesUserCannotApproveTheirOwnOrder(): void
    {
        $id = $this->pendingOrderCreatedBy($this->sales);

        $this->expectException(ForbiddenException::class);

        $this->approvals->approve($id, $this->sales);
    }

    #[Test]
    public function aSalesUserCannotApproveAnyoneElsesOrderEither(): void
    {
        $id = $this->pendingOrderCreatedBy($this->sales);

        // Tetap ditolak, namun sebagai NotFound dan bukan Forbidden: order itu
        // berada di luar scope Sales lain, dan 403 justru akan mengonfirmasi
        // bahwa record-nya ada (NFR-003). Penolakan yang tidak membocorkan
        // keberadaan record adalah yang lebih kuat dari keduanya.
        $this->expectException(NotFoundException::class);

        $this->approvals->approve($id, $this->otherSales);
    }

    #[Test]
    public function noSalesUserCanEverApproveWhicheverOrderTheyAim(): void
    {
        // Rangkuman FR-018 dari sisi Sales: baik order miliknya sendiri maupun
        // milik orang lain, hasilnya selalu penolakan — hanya jenis
        // exception-nya yang berbeda, dan keduanya menutup jalur approval.
        $own = $this->pendingOrderCreatedBy($this->sales);
        $foreign = $this->pendingOrderCreatedBy($this->otherSales);

        foreach ([$own, $foreign] as $id) {
            try {
                $this->approvals->approve($id, $this->sales);
                self::fail('Sales tidak boleh pernah berhasil approve, order id ' . $id);
            } catch (ForbiddenException | NotFoundException) {
                self::assertSame(
                    SalesOrderStatus::PendingApproval,
                    $this->statusOf($id),
                    'Order harus tetap PendingApproval setelah percobaan ditolak',
                );
            }
        }
    }

    #[Test]
    public function warehouseStaffCannotApprove(): void
    {
        $id = $this->pendingOrderCreatedBy($this->sales);

        $this->expectException(ForbiddenException::class);

        $this->approvals->approve($id, $this->warehouse);
    }

    #[Test]
    public function anAdminApprovesAndBothApproverAndTimestampAreRecorded(): void
    {
        $id = $this->pendingOrderCreatedBy($this->sales);

        $this->approvals->approve($id, $this->admin);

        $order = $this->orders->findById($id);

        self::assertNotNull($order);
        self::assertSame(SalesOrderStatus::Approved, $order->status);
        self::assertSame($this->admin->id, $order->approvedBy);
        self::assertSame('2026-09-11 10:00:00', $this->orders->approvedAt($id));
    }

    #[Test]
    public function anAdminCannotApproveAnOrderTheyCreatedThemselves(): void
    {
        // Aturan berlaku untuk siapa pun, termasuk Admin: approved_by tidak
        // boleh sama dengan created_by.
        $id = $this->pendingOrderCreatedBy($this->admin);

        $this->expectException(ForbiddenException::class);

        $this->approvals->approve($id, $this->admin);
    }

    #[Test]
    public function aSecondAdminCanApproveAnOrderCreatedByTheFirst(): void
    {
        $id = $this->pendingOrderCreatedBy($this->admin);

        $this->approvals->approve($id, $this->secondAdmin);

        self::assertSame(SalesOrderStatus::Approved, $this->statusOf($id));
    }

    #[Test]
    public function approvedByIsNeverEqualToCreatedBy(): void
    {
        foreach ([$this->sales, $this->otherSales, $this->admin] as $creator) {
            $id = $this->pendingOrderCreatedBy($creator);

            try {
                $this->approvals->approve($id, $this->admin);
            } catch (ForbiddenException) {
                // Admin membuat order itu sendiri; penolakannya justru benar.
                continue;
            }

            $order = $this->orders->findById($id);

            self::assertNotNull($order);
            self::assertNotSame($order->createdBy, $order->approvedBy);
        }
    }

    #[Test]
    public function approveRefusesAnOrderThatIsNotPendingApproval(): void
    {
        $id = $this->service->create($this->validPayload(), $this->sales);

        // Masih Draft — belum diajukan, jadi belum ada yang boleh disetujui.
        $this->expectException(DomainException::class);

        $this->approvals->approve($id, $this->admin);
    }

    #[Test]
    public function anApprovedOrderCannotBeApprovedTwice(): void
    {
        $id = $this->pendingOrderCreatedBy($this->sales);
        $this->approvals->approve($id, $this->admin);

        $this->expectException(DomainException::class);

        $this->approvals->approve($id, $this->secondAdmin);
    }

    // ------------------------------------------------------------ reject

    #[Test]
    public function rejectMovesTheOrderToCancelled(): void
    {
        $id = $this->pendingOrderCreatedBy($this->sales);

        $this->approvals->reject($id, $this->admin);

        self::assertSame(SalesOrderStatus::Cancelled, $this->statusOf($id));
    }

    #[Test]
    public function aSalesUserCannotRejectTheirOwnOrder(): void
    {
        $id = $this->pendingOrderCreatedBy($this->sales);

        $this->expectException(ForbiddenException::class);

        $this->approvals->reject($id, $this->sales);
    }

    #[Test]
    public function aSalesUserCannotRejectAnotherUsersOrderEither(): void
    {
        // Di luar scope Sales lain — NotFound, bukan Forbidden (NFR-003).
        $id = $this->pendingOrderCreatedBy($this->sales);

        $this->expectException(NotFoundException::class);

        $this->approvals->reject($id, $this->otherSales);
    }

    #[Test]
    public function anAdminCannotRejectAnOrderTheyCreatedThemselves(): void
    {
        $id = $this->pendingOrderCreatedBy($this->admin);

        $this->expectException(ForbiddenException::class);

        $this->approvals->reject($id, $this->admin);
    }

    #[Test]
    public function rejectRefusesAnOrderThatIsNotPendingApproval(): void
    {
        $id = $this->service->create($this->validPayload(), $this->sales);

        $this->expectException(DomainException::class);

        $this->approvals->reject($id, $this->admin);
    }

    // --------------------------------------------------------- ownership

    #[Test]
    public function salesSeesOnlyTheirOwnOrdersInTheOwnershipScope(): void
    {
        $mine = $this->service->create($this->validPayload(), $this->sales);
        $this->service->create($this->validPayload(), $this->otherSales);

        $visible = $this->service->search($this->service->scopeFor($this->sales), 10, 0);

        self::assertCount(1, $visible);
        self::assertSame($mine, $visible[0]->id);
    }

    #[Test]
    public function adminAndWarehouseSeeEveryOrder(): void
    {
        $this->service->create($this->validPayload(), $this->sales);
        $this->service->create($this->validPayload(), $this->otherSales);

        self::assertCount(2, $this->service->search($this->service->scopeFor($this->admin), 10, 0));
        self::assertCount(2, $this->service->search($this->service->scopeFor($this->warehouse), 10, 0));
    }

    #[Test]
    public function anotherSalesUsersOrderIsNotFoundRatherThanForbidden(): void
    {
        // 404, bukan 403 — keberadaan record milik orang lain tidak boleh
        // bocor (contracts/http-routes.md, NFR-003).
        $id = $this->service->create($this->validPayload(), $this->otherSales);

        $this->expectException(NotFoundException::class);

        $this->service->requireVisibleOrder($id, $this->sales);
    }

    #[Test]
    public function aSalesUserCanReachTheirOwnOrder(): void
    {
        $id = $this->service->create($this->validPayload(), $this->sales);

        self::assertSame($id, $this->service->requireVisibleOrder($id, $this->sales)->id);
    }

    #[Test]
    public function anAdminCanReachAnyOrder(): void
    {
        $id = $this->service->create($this->validPayload(), $this->otherSales);

        self::assertSame($id, $this->service->requireVisibleOrder($id, $this->admin)->id);
    }

    // ----------------------------------------------------------- helpers

    /** @return array<string, mixed> */
    private function validPayload(): array
    {
        return [
            'customer_id'  => '10',
            'warehouse_id' => '20',
            'order_date'   => '2026-09-11',
            'items'        => [
                ['product_id' => '30', 'quantity' => '4'],
                ['product_id' => '31', 'quantity' => '2'],
            ],
        ];
    }

    private function pendingOrderCreatedBy(User $creator): int
    {
        $id = $this->service->create($this->validPayload(), $creator);
        $this->service->submit($id, $creator);

        return $id;
    }

    private function approvedOrder(): int
    {
        $id = $this->pendingOrderCreatedBy($this->sales);
        $this->approvals->approve($id, $this->admin);

        return $id;
    }

    private function statusOf(int $id): ?SalesOrderStatus
    {
        return $this->orders->findById($id)?->status;
    }
}
