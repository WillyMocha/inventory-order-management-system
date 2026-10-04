<?php

declare(strict_types=1);

namespace Tests\Unit\Service;

use App\Entity\Customer;
use App\Entity\Enum\Role;
use App\Entity\Enum\SalesOrderStatus;
use App\Entity\Product;
use App\Entity\SalesOrder;
use App\Entity\User;
use App\Entity\Warehouse;
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
 * Unit test edit Sales Order Draft (spec 004-edit-draft-orders, US1).
 *
 * Aturannya (Clarifications Q3, research R-002): hanya PEMBUAT order yang
 * boleh mengedit, apa pun role-nya; Sales yang menjangkau order orang lain
 * mendapat 404; Warehouse Staff tidak pernah; hanya selama Draft. Seluruhnya
 * terhadap fake in-memory (constitution Principle III).
 */
final class SalesOrderServiceEditTest extends TestCase
{
    private const int CUSTOMER = 10;
    private const int OTHER_CUSTOMER = 11;
    private const int WAREHOUSE = 20;
    private const int OTHER_WAREHOUSE = 21;
    private const int PRODUCT_A = 30;
    private const int PRODUCT_B = 31;
    private const string ONLY_DRAFT = 'Only a draft order can be edited.';

    private InMemorySalesOrderRepository $orders;
    private InMemoryProductRepository $products;
    private SalesOrderService $service;

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
        $this->products = new InMemoryProductRepository([
            new Product(self::PRODUCT_A, 'SKU-A', 'Product A', 1, 'pcs', '1000.00', '1500.00', 5, null, true),
            new Product(self::PRODUCT_B, 'SKU-B', 'Product B', 1, 'pcs', '2000.00', '2500.00', 5, null, true),
        ]);

        $this->service = new SalesOrderService(
            $this->orders,
            new InMemoryCustomerRepository([
                new Customer(self::CUSTOMER, 'Customer A', '0800', 'Jakarta', true),
                new Customer(self::OTHER_CUSTOMER, 'Customer B', '0800', 'Bandung', true),
            ]),
            new InMemoryWarehouseRepository([
                new Warehouse(self::WAREHOUSE, 'Main Warehouse', 'Jakarta', true),
                new Warehouse(self::OTHER_WAREHOUSE, 'Second Warehouse', 'Surabaya', true),
            ]),
            $this->products,
            new FixedClock('2026-09-11 10:00:00'),
            new ImmediateTransactionRunner(),
        );
    }

    // -------------------------------------------------- yang diizinkan

    #[Test]
    public function theCreatorReplacesHeaderAndLines(): void
    {
        $id = $this->draftBy($this->sales);
        $before = $this->requireOrder($id);

        $this->service->update($id, $this->editPayload(), $this->sales);

        $after = $this->requireOrder($id);
        self::assertSame(self::OTHER_CUSTOMER, $after->customerId);
        self::assertSame(self::OTHER_WAREHOUSE, $after->warehouseId);
        self::assertSame('2026-09-20', $after->orderDate);
        self::assertCount(1, $after->items);
        self::assertSame(self::PRODUCT_B, $after->items[0]->productId);
        self::assertSame(7, $after->items[0]->quantity);

        // FR-008: identitas order tidak pernah berubah oleh edit.
        self::assertSame($before->orderNumber, $after->orderNumber);
        self::assertSame(SalesOrderStatus::Draft, $after->status);
        self::assertSame($this->sales->id, $after->createdBy);
        self::assertNull($after->approvedBy);
    }

    #[Test]
    public function anAdminEditsADraftTheyCreatedThemselves(): void
    {
        $id = $this->draftBy($this->admin);

        $this->service->update($id, $this->editPayload(), $this->admin);

        self::assertSame(self::OTHER_CUSTOMER, $this->requireOrder($id)->customerId);
    }

    #[Test]
    public function linePricesAreReReadFromTheCatalogWhenSaving(): void
    {
        // A-004: Draft belum mengikat siapa pun, jadi harga mengikuti katalog
        // saat disimpan — persis seperti membuat order baru.
        $id = $this->draftBy($this->sales);
        $this->products->save(
            new Product(self::PRODUCT_B, 'SKU-B', 'Product B', 1, 'pcs', '2000.00', '2750.00', 5, null, true),
        );

        $this->service->update($id, $this->editPayload(), $this->sales);

        self::assertSame('2750.00', $this->requireOrder($id)->items[0]->sellingPrice);
    }

    #[Test]
    public function fieldsThatNeverComeFromThePayloadAreIgnored(): void
    {
        $id = $this->draftBy($this->sales);
        $before = $this->requireOrder($id);
        $payload = $this->editPayload() + [
            'created_by'   => $this->otherSales->id,
            'status'       => 'Approved',
            'order_number' => 'SO-HACKED-0001',
            'approved_by'  => $this->admin->id,
        ];
        $payload['items'][0]['selling_price'] = '1.00';

        $this->service->update($id, $payload, $this->sales);

        $after = $this->requireOrder($id);
        self::assertSame($before->orderNumber, $after->orderNumber);
        self::assertSame(SalesOrderStatus::Draft, $after->status);
        self::assertSame($this->sales->id, $after->createdBy);
        self::assertNull($after->approvedBy);
        self::assertSame('2500.00', $after->items[0]->sellingPrice);
    }

    // ------------------------------------------------------- penolakan

    #[Test]
    public function anotherSalesUserGetsNotFound(): void
    {
        $id = $this->draftBy($this->sales);

        $this->assertRefused($id, $this->otherSales, NotFoundException::class);
    }

    #[Test]
    public function anAdminCannotEditADraftSomeoneElseCreated(): void
    {
        // Q3: kalau Admin boleh mengubah isi order buatan Sales, ia dapat
        // mengubah lalu meng-approve-nya sendiri — aturan approver != creator
        // kehilangan arti.
        $id = $this->draftBy($this->sales);

        $this->assertRefused($id, $this->admin, ForbiddenException::class);
        $this->assertRefused($id, $this->secondAdmin, ForbiddenException::class);
    }

    #[Test]
    public function warehouseStaffCanNeverEditASalesOrder(): void
    {
        $id = $this->draftBy($this->sales);

        $this->assertRefused($id, $this->warehouse, ForbiddenException::class);
    }

    /** @return array<string, array{SalesOrderStatus}> */
    public static function nonDraftStatuses(): array
    {
        return [
            'pending approval' => [SalesOrderStatus::PendingApproval],
            'approved'         => [SalesOrderStatus::Approved],
            'cancelled'        => [SalesOrderStatus::Cancelled],
        ];
    }

    #[Test]
    #[DataProvider('nonDraftStatuses')]
    public function onlyADraftCanBeEdited(SalesOrderStatus $status): void
    {
        $id = $this->orderBy($this->sales, $status);

        $this->assertRefused($id, $this->sales, DomainException::class, self::ONLY_DRAFT);
    }

    #[Test]
    public function anOrderThatLeavesDraftBeforeTheSaveIsRefusedAndLinesStay(): void
    {
        // Status diperiksa lagi SAAT menyimpan (research R-003): order yang
        // di-submit di tab lain di antara pembacaan dan penyimpanan ditolak, dan
        // line-nya tidak ikut diganti.
        $id = $this->draftBy($this->sales);
        $this->orders->failNextUpdateDraft();

        $this->assertRefused($id, $this->sales, DomainException::class, self::ONLY_DRAFT);
    }

    /** @return array<string, array{array<string, mixed>, string}> */
    public static function invalidPayloads(): array
    {
        $line = ['product_id' => (string) self::PRODUCT_A, 'quantity' => '2'];
        $base = ['customer_id' => (string) self::CUSTOMER, 'warehouse_id' => (string) self::WAREHOUSE];

        $zeroQuantity = ['product_id' => '30', 'quantity' => '0'];
        $unknownProduct = ['product_id' => '999', 'quantity' => '1'];

        return [
            'no lines'         => [$base + ['items' => []], 'items'],
            'quantity zero'    => [$base + ['items' => [$zeroQuantity]], 'items.0.quantity'],
            'unknown product'  => [$base + ['items' => [$unknownProduct]], 'items.0.product_id'],
            'unknown customer' => [['customer_id' => '999', 'warehouse_id' => '20', 'items' => [$line]], 'customer_id'],
            'no warehouse'     => [['customer_id' => '10', 'warehouse_id' => '', 'items' => [$line]], 'warehouse_id'],
        ];
    }

    /** @param array<string, mixed> $payload */
    #[Test]
    #[DataProvider('invalidPayloads')]
    public function editUsesTheSameValidationAsCreate(array $payload, string $field): void
    {
        $id = $this->draftBy($this->sales);
        $before = $this->requireOrder($id);

        try {
            $this->service->update($id, $payload, $this->sales);
            self::fail('Payload tidak valid seharusnya ditolak');
        } catch (ValidationException $e) {
            self::assertArrayHasKey($field, $e->errors());
        }

        self::assertEquals($before, $this->requireOrder($id), 'Tidak boleh ada partial save');
    }

    // ---------------------------------------------------------- canEdit

    #[Test]
    public function canEditIsTrueOnlyForTheCreatorOfADraft(): void
    {
        $draft = $this->requireOrder($this->draftBy($this->sales));
        $submitted = $this->requireOrder($this->orderBy($this->sales, SalesOrderStatus::PendingApproval));

        self::assertTrue($this->service->canEdit($draft, $this->sales));
        self::assertFalse($this->service->canEdit($draft, $this->otherSales));
        self::assertFalse($this->service->canEdit($draft, $this->admin));
        self::assertFalse($this->service->canEdit($draft, $this->warehouse));
        self::assertFalse($this->service->canEdit($submitted, $this->sales));
    }

    #[Test]
    public function assertMayEditRefusesANonCreatorWhateverTheStatus(): void
    {
        // Urutan pemeriksaan GET dan POST sama (contracts "Check order"):
        // Admin pada order orang lain yang sudah di-submit mendapat 403, bukan
        // pesan "hanya Draft".
        $submitted = $this->requireOrder($this->orderBy($this->sales, SalesOrderStatus::PendingApproval));

        $this->service->assertMayEdit($submitted, $this->sales);

        $this->expectException(ForbiddenException::class);
        $this->service->assertMayEdit($submitted, $this->admin);
    }

    // ---------------------------------------------------------- helper

    /**
     * @param class-string<\Throwable> $exception
     */
    private function assertRefused(int $id, User $actor, string $exception, ?string $message = null): void
    {
        $before = $this->requireOrder($id);

        try {
            $this->service->update($id, $this->editPayload(), $actor);
            self::fail('Edit seharusnya ditolak dengan ' . $exception);
        } catch (\Throwable $e) {
            self::assertInstanceOf($exception, $e);

            if ($message !== null) {
                self::assertSame($message, $e->getMessage());
            }
        }

        self::assertEquals($before, $this->requireOrder($id), 'Order yang ditolak harus tetap utuh');
    }

    private function draftBy(User $creator): int
    {
        return $this->orderBy($creator, SalesOrderStatus::Draft);
    }

    private function orderBy(User $creator, SalesOrderStatus $status): int
    {
        $id = $this->service->create([
            'customer_id'  => (string) self::CUSTOMER,
            'warehouse_id' => (string) self::WAREHOUSE,
            'items'        => [
                ['product_id' => (string) self::PRODUCT_A, 'quantity' => '2'],
                ['product_id' => (string) self::PRODUCT_B, 'quantity' => '1'],
            ],
        ], $creator);

        if ($status !== SalesOrderStatus::Draft) {
            $this->orders->updateStatus($id, SalesOrderStatus::Draft, $status);
        }

        return $id;
    }

    /** @return array<string, mixed> */
    private function editPayload(): array
    {
        return [
            'customer_id'  => (string) self::OTHER_CUSTOMER,
            'warehouse_id' => (string) self::OTHER_WAREHOUSE,
            'order_date'   => '2026-09-20',
            'items'        => [['product_id' => (string) self::PRODUCT_B, 'quantity' => '7']],
        ];
    }

    private function requireOrder(int $id): SalesOrder
    {
        $order = $this->orders->findById($id);
        self::assertNotNull($order);

        return $order;
    }
}
