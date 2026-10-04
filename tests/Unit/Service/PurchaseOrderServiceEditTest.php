<?php

declare(strict_types=1);

namespace Tests\Unit\Service;

use App\Entity\Enum\PurchaseOrderStatus;
use App\Entity\Enum\Role;
use App\Entity\Product;
use App\Entity\PurchaseOrder;
use App\Entity\Supplier;
use App\Entity\User;
use App\Entity\Warehouse;
use App\Service\PurchaseOrderService;
use App\Support\Exception\DomainException;
use App\Support\Exception\ForbiddenException;
use App\Support\Exception\NotFoundException;
use App\Support\Exception\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Tests\Unit\Fake\FixedClock;
use Tests\Unit\Fake\ImmediateTransactionRunner;
use Tests\Unit\Fake\InMemoryProductRepository;
use Tests\Unit\Fake\InMemoryPurchaseOrderRepository;
use Tests\Unit\Fake\InMemorySupplierRepository;
use Tests\Unit\Fake\InMemoryWarehouseRepository;

/**
 * Unit test edit Purchase Order Draft (spec 004-edit-draft-orders, US2).
 *
 * Aturannya (Clarifications Q2, research R-002): Admin boleh mengedit PO Draft
 * siapa pun; Warehouse Staff hanya PO Draft buatannya sendiri; Sales tidak
 * pernah; hanya selama Draft. Seluruhnya terhadap fake in-memory.
 */
final class PurchaseOrderServiceEditTest extends TestCase
{
    private const int SUPPLIER = 10;
    private const int OTHER_SUPPLIER = 11;
    private const int WAREHOUSE = 20;
    private const int OTHER_WAREHOUSE = 21;
    private const int PRODUCT_A = 30;
    private const int PRODUCT_B = 31;
    private const string ONLY_DRAFT = 'Only a draft order can be edited.';

    private InMemoryPurchaseOrderRepository $orders;
    private InMemoryProductRepository $products;
    private PurchaseOrderService $service;

    private User $admin;
    private User $warehouseStaff;
    private User $otherWarehouseStaff;
    private User $sales;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = new User(1, 'Admin One', 'admin@ioms.test', 'hash', Role::Admin, true);
        $this->sales = new User(3, 'Sales One', 'sales1@ioms.test', 'hash', Role::Sales, true);
        $this->warehouseStaff = new User(5, 'Warehouse One', 'wh1@ioms.test', 'hash', Role::WarehouseStaff, true);
        $this->otherWarehouseStaff = new User(6, 'Warehouse Two', 'wh2@ioms.test', 'hash', Role::WarehouseStaff, true);

        $this->orders = new InMemoryPurchaseOrderRepository();
        $this->products = new InMemoryProductRepository([
            new Product(self::PRODUCT_A, 'SKU-A', 'Product A', 1, 'pcs', '1000.00', '1500.00', 5, null, true),
            new Product(self::PRODUCT_B, 'SKU-B', 'Product B', 1, 'pcs', '2000.00', '2500.00', 5, null, true),
        ]);

        $this->service = new PurchaseOrderService(
            $this->orders,
            new InMemorySupplierRepository([
                new Supplier(self::SUPPLIER, 'Supplier A', '0800', 'Jakarta', true),
                new Supplier(self::OTHER_SUPPLIER, 'Supplier B', '0800', 'Bandung', true),
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
    public function theCreatingWarehouseStaffReplacesHeaderAndLines(): void
    {
        $id = $this->draftBy($this->warehouseStaff);
        $before = $this->requireOrder($id);

        $this->service->update($id, $this->editPayload(), $this->warehouseStaff);

        $after = $this->requireOrder($id);
        self::assertSame(self::OTHER_SUPPLIER, $after->supplierId);
        self::assertSame(self::OTHER_WAREHOUSE, $after->warehouseId);
        self::assertSame('2026-09-20', $after->orderDate);
        self::assertCount(1, $after->items);
        self::assertSame(self::PRODUCT_B, $after->items[0]->productId);
        self::assertSame(12, $after->items[0]->quantity);
        self::assertSame(0, $after->items[0]->receivedQuantity);

        // FR-008: identitas order tidak pernah berubah oleh edit.
        self::assertSame($before->orderNumber, $after->orderNumber);
        self::assertSame(PurchaseOrderStatus::Draft, $after->status);
        self::assertSame($this->warehouseStaff->id, $after->createdBy);
    }

    #[Test]
    public function anAdminEditsAnyDraftIncludingOneByWarehouseStaff(): void
    {
        $id = $this->draftBy($this->warehouseStaff);

        $this->service->update($id, $this->editPayload(), $this->admin);

        $after = $this->requireOrder($id);
        self::assertSame(self::OTHER_SUPPLIER, $after->supplierId);
        self::assertSame($this->warehouseStaff->id, $after->createdBy, 'pembuat tetap, bukan Admin yang mengedit');
    }

    #[Test]
    public function unitCostsAreReReadFromTheCatalogWhenSaving(): void
    {
        $id = $this->draftBy($this->warehouseStaff);
        $this->products->save(
            new Product(self::PRODUCT_B, 'SKU-B', 'Product B', 1, 'pcs', '2100.00', '2500.00', 5, null, true),
        );
        $payload = $this->editPayload();
        $payload['items'][0]['purchase_price'] = '1.00';

        $this->service->update($id, $payload, $this->warehouseStaff);

        self::assertSame('2100.00', $this->requireOrder($id)->items[0]->purchasePrice);
    }

    // ------------------------------------------------------- penolakan

    #[Test]
    public function warehouseStaffCannotEditAnotherUsersDraft(): void
    {
        $byOtherStaff = $this->draftBy($this->otherWarehouseStaff);
        $byAdmin = $this->draftBy($this->admin);

        $this->assertRefused($byOtherStaff, $this->warehouseStaff, ForbiddenException::class);
        $this->assertRefused($byAdmin, $this->warehouseStaff, ForbiddenException::class);
    }

    #[Test]
    public function salesCanNeverEditAPurchaseOrder(): void
    {
        $id = $this->draftBy($this->admin);

        $this->assertRefused($id, $this->sales, ForbiddenException::class);
    }

    #[Test]
    public function anUnknownOrderIsNotFound(): void
    {
        $this->expectException(NotFoundException::class);

        $this->service->update(999, $this->editPayload(), $this->admin);
    }

    /** @return array<string, array{PurchaseOrderStatus}> */
    public static function nonDraftStatuses(): array
    {
        return [
            'ordered'            => [PurchaseOrderStatus::Ordered],
            'partially received' => [PurchaseOrderStatus::PartiallyReceived],
            'received'           => [PurchaseOrderStatus::Received],
            'cancelled'          => [PurchaseOrderStatus::Cancelled],
        ];
    }

    #[Test]
    #[DataProvider('nonDraftStatuses')]
    public function onlyADraftCanBeEdited(PurchaseOrderStatus $status): void
    {
        $id = $this->orderBy($this->warehouseStaff, $status);

        $this->assertRefused($id, $this->admin, DomainException::class, self::ONLY_DRAFT);
    }

    #[Test]
    public function anOrderThatLeavesDraftBeforeTheSaveIsRefusedAndLinesStay(): void
    {
        $id = $this->draftBy($this->warehouseStaff);
        $this->orders->failNextUpdateDraft();

        $this->assertRefused($id, $this->warehouseStaff, DomainException::class, self::ONLY_DRAFT);
    }

    /** @return array<string, array{array<string, mixed>, string}> */
    public static function invalidPayloads(): array
    {
        $line = ['product_id' => (string) self::PRODUCT_A, 'quantity' => '2'];
        $base = ['supplier_id' => (string) self::SUPPLIER, 'warehouse_id' => (string) self::WAREHOUSE];

        $zeroQuantity = ['product_id' => '30', 'quantity' => '0'];

        return [
            'no lines'         => [$base + ['items' => []], 'items'],
            'quantity zero'    => [$base + ['items' => [$zeroQuantity]], 'items.0.quantity'],
            'unknown supplier' => [['supplier_id' => '999', 'warehouse_id' => '20', 'items' => [$line]], 'supplier_id'],
            'no warehouse'     => [['supplier_id' => '10', 'warehouse_id' => '', 'items' => [$line]], 'warehouse_id'],
        ];
    }

    /** @param array<string, mixed> $payload */
    #[Test]
    #[DataProvider('invalidPayloads')]
    public function editUsesTheSameValidationAsCreate(array $payload, string $field): void
    {
        $id = $this->draftBy($this->warehouseStaff);
        $before = $this->requireOrder($id);

        try {
            $this->service->update($id, $payload, $this->warehouseStaff);
            self::fail('Payload tidak valid seharusnya ditolak');
        } catch (ValidationException $e) {
            self::assertArrayHasKey($field, $e->errors());
        }

        self::assertEquals($before, $this->requireOrder($id), 'Tidak boleh ada partial save');
    }

    // ---------------------------------------------------------- canEdit

    #[Test]
    public function canEditFollowsTheRoleAndCreatorRules(): void
    {
        $byStaff = $this->requireOrder($this->draftBy($this->warehouseStaff));
        $ordered = $this->requireOrder($this->orderBy($this->warehouseStaff, PurchaseOrderStatus::Ordered));

        self::assertTrue($this->service->canEdit($byStaff, $this->warehouseStaff));
        self::assertTrue($this->service->canEdit($byStaff, $this->admin));
        self::assertFalse($this->service->canEdit($byStaff, $this->otherWarehouseStaff));
        self::assertFalse($this->service->canEdit($byStaff, $this->sales));
        self::assertFalse($this->service->canEdit($ordered, $this->admin));
    }

    #[Test]
    public function assertMayEditRefusesWhateverTheStatus(): void
    {
        $ordered = $this->requireOrder($this->orderBy($this->warehouseStaff, PurchaseOrderStatus::Ordered));

        $this->service->assertMayEdit($ordered, $this->admin);

        $this->expectException(ForbiddenException::class);
        $this->service->assertMayEdit($ordered, $this->otherWarehouseStaff);
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
        return $this->orderBy($creator, PurchaseOrderStatus::Draft);
    }

    private function orderBy(User $creator, PurchaseOrderStatus $status): int
    {
        $id = $this->service->create([
            'supplier_id'  => (string) self::SUPPLIER,
            'warehouse_id' => (string) self::WAREHOUSE,
            'items'        => [
                ['product_id' => (string) self::PRODUCT_A, 'quantity' => '4'],
                ['product_id' => (string) self::PRODUCT_B, 'quantity' => '3'],
            ],
        ], $creator);

        if ($status !== PurchaseOrderStatus::Draft) {
            $this->orders->updateStatus($id, PurchaseOrderStatus::Draft, $status);
        }

        return $id;
    }

    /** @return array<string, mixed> */
    private function editPayload(): array
    {
        return [
            'supplier_id'  => (string) self::OTHER_SUPPLIER,
            'warehouse_id' => (string) self::OTHER_WAREHOUSE,
            'order_date'   => '2026-09-20',
            'items'        => [['product_id' => (string) self::PRODUCT_B, 'quantity' => '12']],
        ];
    }

    private function requireOrder(int $id): PurchaseOrder
    {
        $order = $this->orders->findById($id);
        self::assertNotNull($order);

        return $order;
    }
}
