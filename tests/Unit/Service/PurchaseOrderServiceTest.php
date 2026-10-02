<?php

declare(strict_types=1);

namespace Tests\Unit\Service;

use App\Entity\Enum\PurchaseOrderStatus;
use App\Entity\Enum\Role;
use App\Entity\Product;
use App\Entity\Supplier;
use App\Entity\User;
use App\Entity\Warehouse;
use App\Service\PurchaseOrderService;
use App\Support\Exception\DomainException;
use App\Support\Exception\NotFoundException;
use App\Support\Exception\ValidationException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Tests\Unit\Fake\FixedClock;
use Tests\Unit\Fake\InMemoryProductRepository;
use Tests\Unit\Fake\InMemoryPurchaseOrderRepository;
use Tests\Unit\Fake\InMemorySupplierRepository;
use Tests\Unit\Fake\InMemoryWarehouseRepository;

/**
 * Unit test PurchaseOrderService (PO-01, FR-012, FR-013).
 *
 * Seluruhnya terhadap fake in-memory — tanpa database, tanpa session
 * (constitution Principle III).
 *
 * Berbeda dari Sales Order, PO tidak punya scoping kepemilikan: Sales tidak
 * memiliki akses ke jalur pembelian sama sekali, dan itu ditegakkan route
 * table (contracts/http-routes.md), bukan di dalam Service ini.
 */
final class PurchaseOrderServiceTest extends TestCase
{
    private const int SUPPLIER = 10;
    private const int WAREHOUSE = 20;
    private const int PRODUCT_A = 30;
    private const int PRODUCT_B = 31;

    private InMemoryPurchaseOrderRepository $orders;
    private PurchaseOrderService $service;

    private User $admin;
    private User $warehouseStaff;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = new User(1, 'Admin One', 'admin@ioms.test', 'hash', Role::Admin, true);
        $this->warehouseStaff = new User(5, 'Warehouse One', 'wh1@ioms.test', 'hash', Role::WarehouseStaff, true);

        $this->orders = new InMemoryPurchaseOrderRepository();

        $this->service = new PurchaseOrderService(
            $this->orders,
            new InMemorySupplierRepository([new Supplier(self::SUPPLIER, 'Supplier A', '0800', 'Jakarta', true)]),
            new InMemoryWarehouseRepository([new Warehouse(self::WAREHOUSE, 'Main Warehouse', 'Jakarta', true)]),
            new InMemoryProductRepository([
                new Product(self::PRODUCT_A, 'SKU-A', 'Product A', 1, 'pcs', '1000.00', '1500.00', 5, null, true),
                new Product(self::PRODUCT_B, 'SKU-B', 'Product B', 1, 'pcs', '2000.00', '2500.00', 5, null, true),
            ]),
            new FixedClock('2026-09-11 10:00:00'),
        );
    }

    // ------------------------------------------------------------ create

    #[Test]
    public function createsADraftOrderWithItsLines(): void
    {
        $id = $this->service->create($this->validPayload(), $this->warehouseStaff);

        $order = $this->orders->findById($id);

        self::assertNotNull($order);
        self::assertSame(PurchaseOrderStatus::Draft, $order->status);
        self::assertSame(self::SUPPLIER, $order->supplierId);
        self::assertSame(self::WAREHOUSE, $order->warehouseId);
        self::assertCount(2, $order->items);
    }

    #[Test]
    public function everyLineStartsWithNothingReceived(): void
    {
        $id = $this->service->create($this->validPayload(), $this->warehouseStaff);
        $order = $this->orders->findById($id);

        self::assertNotNull($order);

        foreach ($order->items as $item) {
            self::assertSame(0, $item->receivedQuantity);
            self::assertSame($item->quantity, $item->outstandingQuantity());
        }

        self::assertFalse($order->hasAnyReceipt());
        self::assertFalse($order->isFullyReceived());
    }

    #[Test]
    public function recordsCreatedByFromTheActingUserNeverFromInput(): void
    {
        $payload = $this->validPayload();
        $payload['created_by'] = $this->admin->id;

        $id = $this->service->create($payload, $this->warehouseStaff);
        $order = $this->orders->findById($id);

        self::assertNotNull($order);
        self::assertSame($this->warehouseStaff->id, $order->createdBy);
    }

    #[Test]
    public function snapshotsThePurchasePriceFromTheCatalogNotFromInput(): void
    {
        // Harga beli tidak boleh ditentukan oleh pengirim request.
        $payload = $this->validPayload();
        $payload['items'][0]['purchase_price'] = '1.00';

        $id = $this->service->create($payload, $this->warehouseStaff);
        $order = $this->orders->findById($id);

        self::assertNotNull($order);
        self::assertSame('1000.00', $order->items[0]->purchasePrice);
    }

    #[Test]
    public function requiresASupplier(): void
    {
        $payload = $this->validPayload();
        $payload['supplier_id'] = '';

        try {
            $this->service->create($payload, $this->warehouseStaff);
            self::fail('Supplier wajib diisi');
        } catch (ValidationException $e) {
            self::assertArrayHasKey('supplier_id', $e->errors());
        }
    }

    #[Test]
    public function requiresAnExistingSupplier(): void
    {
        $payload = $this->validPayload();
        $payload['supplier_id'] = '999';

        try {
            $this->service->create($payload, $this->warehouseStaff);
            self::fail('Supplier yang tidak ada seharusnya ditolak');
        } catch (ValidationException $e) {
            self::assertArrayHasKey('supplier_id', $e->errors());
        }
    }

    #[Test]
    public function requiresADestinationWarehouse(): void
    {
        $payload = $this->validPayload();
        $payload['warehouse_id'] = '';

        try {
            $this->service->create($payload, $this->warehouseStaff);
            self::fail('Warehouse tujuan wajib diisi');
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
            $this->service->create($payload, $this->warehouseStaff);
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

        $this->service->create($payload, $this->warehouseStaff);
    }

    #[Test]
    public function rejectsALineReferencingAnUnknownProduct(): void
    {
        $payload = $this->validPayload();
        $payload['items'][0]['product_id'] = '999';

        $this->expectException(ValidationException::class);

        $this->service->create($payload, $this->warehouseStaff);
    }

    #[Test]
    public function nothingIsPersistedWhenValidationFails(): void
    {
        $payload = $this->validPayload();
        $payload['items'] = [];

        try {
            $this->service->create($payload, $this->warehouseStaff);
        } catch (ValidationException) {
            // diabaikan: yang diperiksa adalah efek sampingnya
        }

        self::assertSame(0, $this->orders->countBy([]));
    }

    #[Test]
    public function generatesAUniqueOrderNumber(): void
    {
        $first = $this->orders->findById($this->service->create($this->validPayload(), $this->admin));
        $second = $this->orders->findById($this->service->create($this->validPayload(), $this->admin));

        self::assertNotNull($first);
        self::assertNotNull($second);
        self::assertNotSame($first->orderNumber, $second->orderNumber);

        // Prefix PO membedakannya dari Sales Order, yang memakai SO.
        self::assertStringStartsWith('PO-20260911-', $first->orderNumber);
    }

    // ------------------------------------------------------- transitions

    #[Test]
    public function submitMovesADraftToOrdered(): void
    {
        $id = $this->service->create($this->validPayload(), $this->warehouseStaff);

        $this->service->submit($id, $this->warehouseStaff);

        self::assertSame(PurchaseOrderStatus::Ordered, $this->statusOf($id));
    }

    #[Test]
    public function submitRefusesAnOrderThatIsNotADraft(): void
    {
        $id = $this->orderedOrder();

        $this->expectException(DomainException::class);

        $this->service->submit($id, $this->warehouseStaff);
    }

    #[Test]
    public function cancelIsAllowedFromDraft(): void
    {
        $id = $this->service->create($this->validPayload(), $this->warehouseStaff);

        $this->service->cancel($id, $this->admin);

        self::assertSame(PurchaseOrderStatus::Cancelled, $this->statusOf($id));
    }

    #[Test]
    public function cancelIsAllowedFromOrdered(): void
    {
        $id = $this->orderedOrder();

        $this->service->cancel($id, $this->admin);

        self::assertSame(PurchaseOrderStatus::Cancelled, $this->statusOf($id));
    }

    #[Test]
    public function cancelIsAllowedFromPartiallyReceived(): void
    {
        // Cancel diizinkan pada tahap mana pun SEBELUM Received (spec A-004),
        // termasuk setelah sebagian barang masuk.
        $id = $this->orderedOrder();
        $this->orders->updateStatus($id, PurchaseOrderStatus::PartiallyReceived);

        $this->service->cancel($id, $this->admin);

        self::assertSame(PurchaseOrderStatus::Cancelled, $this->statusOf($id));
    }

    #[Test]
    public function cancelIsRefusedOnceReceived(): void
    {
        $id = $this->orderedOrder();
        $this->orders->updateStatus($id, PurchaseOrderStatus::Received);

        $this->expectException(DomainException::class);

        $this->service->cancel($id, $this->admin);
    }

    #[Test]
    public function cancelIsRefusedOnceCancelled(): void
    {
        $id = $this->service->create($this->validPayload(), $this->warehouseStaff);
        $this->service->cancel($id, $this->admin);

        $this->expectException(DomainException::class);

        $this->service->cancel($id, $this->admin);
    }

    #[Test]
    public function noTransitionLeavesAReceivedOrder(): void
    {
        $id = $this->orderedOrder();
        $this->orders->updateStatus($id, PurchaseOrderStatus::Received);

        foreach (PurchaseOrderStatus::cases() as $target) {
            self::assertFalse(
                $this->orders->findById($id)?->canTransitionTo($target),
                'Received bersifat terminal, tidak boleh ada transisi ke ' . $target->value,
            );
        }
    }

    #[Test]
    public function noTransitionLeavesACancelledOrder(): void
    {
        $id = $this->service->create($this->validPayload(), $this->warehouseStaff);
        $this->service->cancel($id, $this->admin);

        foreach (PurchaseOrderStatus::cases() as $target) {
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

        $this->service->submit(999, $this->warehouseStaff);
    }

    #[Test]
    public function cancelRefusesAnOrderThatDoesNotExist(): void
    {
        $this->expectException(NotFoundException::class);

        $this->service->cancel(999, $this->admin);
    }

    #[Test]
    public function noStockMovesWhenAnOrderIsMerelySubmitted(): void
    {
        // PO-01: mengajukan order TIDAK memindahkan stock. Stock hanya berubah
        // lewat goods receipt di StockService (ARCH-02). PurchaseOrderService
        // memang tidak menerima repository stock apa pun — itu jaminannya di
        // tingkat konstruktor, dan test ini menegaskan maksudnya.
        $id = $this->service->create($this->validPayload(), $this->warehouseStaff);
        $this->service->submit($id, $this->warehouseStaff);

        $order = $this->orders->findById($id);

        self::assertNotNull($order);
        self::assertFalse($order->hasAnyReceipt());

        foreach ($order->items as $item) {
            self::assertSame(0, $item->receivedQuantity);
        }
    }

    // ------------------------------------------------------------ lookup

    #[Test]
    public function requireOrderThrowsNotFoundForAnUnknownId(): void
    {
        $this->expectException(NotFoundException::class);

        $this->service->requireOrder(999);
    }

    #[Test]
    public function requireOrderReturnsTheOrderWithItsLines(): void
    {
        $id = $this->service->create($this->validPayload(), $this->warehouseStaff);

        $order = $this->service->requireOrder($id);

        self::assertSame($id, $order->id);
        self::assertCount(2, $order->items);
    }

    #[Test]
    public function countByStatusReportsEveryStatusPresent(): void
    {
        $this->service->create($this->validPayload(), $this->warehouseStaff);
        $this->orderedOrder();

        $counts = $this->service->countByStatus();

        self::assertSame(1, $counts[PurchaseOrderStatus::Draft->value] ?? 0);
        self::assertSame(1, $counts[PurchaseOrderStatus::Ordered->value] ?? 0);
    }

    // ----------------------------------------------------------- helpers

    /** @return array<string, mixed> */
    private function validPayload(): array
    {
        return [
            'supplier_id'  => (string) self::SUPPLIER,
            'warehouse_id' => (string) self::WAREHOUSE,
            'order_date'   => '2026-09-11',
            'items'        => [
                ['product_id' => (string) self::PRODUCT_A, 'quantity' => '10'],
                ['product_id' => (string) self::PRODUCT_B, 'quantity' => '4'],
            ],
        ];
    }

    private function orderedOrder(): int
    {
        $id = $this->service->create($this->validPayload(), $this->warehouseStaff);
        $this->service->submit($id, $this->warehouseStaff);

        return $id;
    }

    private function statusOf(int $id): ?PurchaseOrderStatus
    {
        return $this->orders->findById($id)?->status;
    }
}
