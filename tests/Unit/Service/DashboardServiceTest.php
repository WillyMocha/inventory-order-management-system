<?php

declare(strict_types=1);

namespace Tests\Unit\Service;

use App\Entity\Enum\PurchaseOrderStatus;
use App\Entity\Enum\Role;
use App\Entity\Enum\SalesOrderStatus;
use App\Entity\Product;
use App\Entity\PurchaseOrder;
use App\Entity\SalesOrder;
use App\Entity\SalesOrderItem;
use App\Service\DashboardService;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Tests\Unit\Fake\FixedClock;
use Tests\Unit\Fake\InMemoryProductRepository;
use Tests\Unit\Fake\InMemoryPurchaseOrderRepository;
use Tests\Unit\Fake\InMemorySalesOrderRepository;
use Tests\Unit\Fake\InMemoryStockLedgerRepository;

/**
 * Unit test DashboardService (DASH-01, FR-026).
 *
 * Yang dibuktikan di sini bukan tata letaknya, melainkan bahwa setiap angka
 * BERASAL DARI DATA: mengubah data menggeser angkanya. Itulah kalimat
 * eksplisit FR-026 — "derived from recorded data rather than fixed values".
 *
 * Selain itu tiap role hanya melihat miliknya: Sales tidak pernah melihat
 * order milik Sales lain, dan pembatasannya terjadi di lapisan query, bukan
 * disaring setelah data terlanjur terbaca.
 */
final class DashboardServiceTest extends TestCase
{
    private const int SALES_OWNER = 10;
    private const int SALES_OTHER = 11;

    private InMemoryProductRepository $products;
    private InMemorySalesOrderRepository $salesOrders;
    private InMemoryPurchaseOrderRepository $purchaseOrders;
    private DashboardService $service;

    protected function setUp(): void
    {
        parent::setUp();

        // Dua product: satu jauh di atas reorder point, satu TEPAT di reorder
        // point — batas "at or below" pada spec A-008 ikut teruji di sini.
        $this->products = new InMemoryProductRepository([
            new Product(1, 'SKU-000001', 'Kabel UTP Cat6', 1, 'roll', '1000000.00', '1400000.00', 10, null, true),
            new Product(2, 'SKU-000002', 'Switch 24-Port', 1, 'pcs', '2000000.00', '2600000.00', 4, null, true),
        ]);
        $this->products->setTotalQuantity(1, 30);
        $this->products->setTotalQuantity(2, 4);

        $this->salesOrders = new InMemorySalesOrderRepository([
            $this->salesOrder(1, 'SO-2026-0001', SalesOrderStatus::Draft, self::SALES_OWNER),
            $this->salesOrder(2, 'SO-2026-0002', SalesOrderStatus::PendingApproval, self::SALES_OWNER),
            $this->salesOrder(3, 'SO-2026-0003', SalesOrderStatus::Approved, self::SALES_OWNER),
            $this->salesOrder(4, 'SO-2026-0004', SalesOrderStatus::PendingApproval, self::SALES_OTHER),
            $this->salesOrder(5, 'SO-2026-0005', SalesOrderStatus::Fulfilled, self::SALES_OTHER),
        ]);

        $this->purchaseOrders = new InMemoryPurchaseOrderRepository([
            $this->purchaseOrder(1, 'PO-2026-0001', PurchaseOrderStatus::Ordered),
            $this->purchaseOrder(2, 'PO-2026-0002', PurchaseOrderStatus::PartiallyReceived),
            $this->purchaseOrder(3, 'PO-2026-0003', PurchaseOrderStatus::Received),
        ]);

        $this->service = new DashboardService(
            $this->products,
            $this->salesOrders,
            $this->purchaseOrders,
            new InMemoryStockLedgerRepository(),
            new FixedClock(),
        );
    }

    // ----------------------------------------------------------- Admin

    #[Test]
    public function adminInventoryValueUsesPurchasePriceAcrossAllWarehouses(): void
    {
        // spec A-007: quantity x harga BELI, bukan harga jual.
        // 30 x 1.000.000 + 4 x 2.000.000 = 38.000.000
        $figures = $this->service->adminFigures();

        self::assertSame('38000000.00', $figures['inventoryValue']);
    }

    #[Test]
    public function adminInventoryValueMovesWhenStockMoves(): void
    {
        $before = $this->service->adminFigures()['inventoryValue'];

        $this->products->setTotalQuantity(1, 31);

        // Satu unit tambahan seharga 1.000.000 — angkanya wajib ikut bergerak.
        self::assertSame('38000000.00', $before);
        self::assertSame('39000000.00', $this->service->adminFigures()['inventoryValue']);
    }

    #[Test]
    public function adminCountsProductsAtOrBelowReorderPoint(): void
    {
        // Product 2 berada TEPAT di reorder point (4 = 4) dan tetap dihitung.
        self::assertSame(1, $this->service->adminFigures()['belowReorderPoint']);
    }

    #[Test]
    public function adminBelowReorderCountMovesWhenStockMoves(): void
    {
        $this->products->setTotalQuantity(1, 10);

        self::assertSame(2, $this->service->adminFigures()['belowReorderPoint']);
    }

    #[Test]
    public function adminSeesSalesOrdersOfEveryOwnerByStatus(): void
    {
        $byStatus = $this->service->adminFigures()['salesOrdersByStatus'];

        self::assertSame(1, $byStatus[SalesOrderStatus::Draft->value]);
        self::assertSame(2, $byStatus[SalesOrderStatus::PendingApproval->value]);
        self::assertSame(1, $byStatus[SalesOrderStatus::Approved->value]);
        self::assertSame(1, $byStatus[SalesOrderStatus::Fulfilled->value]);
    }

    #[Test]
    public function statusTallyIsZeroFilledSoTheViewNeverMissesAColumn(): void
    {
        $byStatus = $this->service->adminFigures()['salesOrdersByStatus'];

        // Tidak ada satu pun order Cancelled, dan key-nya tetap ada bernilai 0.
        self::assertArrayHasKey(SalesOrderStatus::Cancelled->value, $byStatus);
        self::assertSame(0, $byStatus[SalesOrderStatus::Cancelled->value]);
        self::assertCount(count(SalesOrderStatus::cases()), $byStatus);
    }

    #[Test]
    public function adminPendingApprovalCountsEveryOwner(): void
    {
        // Satu milik SALES_OWNER, satu milik SALES_OTHER.
        self::assertSame(2, $this->service->adminFigures()['pendingApproval']);
    }

    #[Test]
    public function adminSeesPurchaseOrdersByStatus(): void
    {
        $byStatus = $this->service->adminFigures()['purchaseOrdersByStatus'];

        self::assertSame(1, $byStatus[PurchaseOrderStatus::Ordered->value]);
        self::assertSame(1, $byStatus[PurchaseOrderStatus::PartiallyReceived->value]);
        self::assertSame(1, $byStatus[PurchaseOrderStatus::Received->value]);
        self::assertSame(0, $byStatus[PurchaseOrderStatus::Draft->value]);
    }

    // ----------------------------------------------------------- Sales

    #[Test]
    public function salesSeesOnlyTheirOwnOrders(): void
    {
        $figures = $this->service->salesFigures(self::SALES_OWNER);

        self::assertSame(3, $figures['total']);
        self::assertSame(1, $figures['ordersByStatus'][SalesOrderStatus::Draft->value]);
        self::assertSame(1, $figures['ordersByStatus'][SalesOrderStatus::PendingApproval->value]);
        self::assertSame(1, $figures['ordersByStatus'][SalesOrderStatus::Approved->value]);
        self::assertSame(0, $figures['ordersByStatus'][SalesOrderStatus::Fulfilled->value]);
    }

    #[Test]
    public function twoSalesUsersSeeDifferentFigures(): void
    {
        $owner = $this->service->salesFigures(self::SALES_OWNER);
        $other = $this->service->salesFigures(self::SALES_OTHER);

        self::assertSame(3, $owner['total']);
        self::assertSame(2, $other['total']);
        self::assertSame(0, $owner['fulfilled']);
        self::assertSame(1, $other['fulfilled']);
    }

    #[Test]
    public function salesFiguresNeverTotalMoreThanTheAdminView(): void
    {
        $admin = $this->service->adminFigures();
        $owner = $this->service->salesFigures(self::SALES_OWNER);
        $other = $this->service->salesFigures(self::SALES_OTHER);

        self::assertSame(
            array_sum($admin['salesOrdersByStatus']),
            $owner['total'] + $other['total'],
        );
    }

    #[Test]
    public function aSalesUserWithNoOrdersSeesZeroesNotAnError(): void
    {
        $figures = $this->service->salesFigures(999);

        self::assertSame(0, $figures['total']);
        self::assertSame(0, $figures['draft']);
        self::assertCount(count(SalesOrderStatus::cases()), $figures['ordersByStatus']);
    }

    // ------------------------------------------------- Warehouse Staff

    #[Test]
    public function warehouseReceiptQueueCountsOrderedAndPartiallyReceived(): void
    {
        $figures = $this->service->warehouseFigures();

        // Received sudah selesai dan tidak boleh ikut terhitung.
        self::assertSame(2, $figures['receiptQueueCount']);
        self::assertCount(2, $figures['awaitingReceipt']);
    }

    #[Test]
    public function warehouseIssueQueueCountsApprovedSalesOrdersOnly(): void
    {
        $figures = $this->service->warehouseFigures();

        self::assertSame(1, $figures['issueQueueCount']);
        self::assertCount(1, $figures['awaitingIssue']);
        self::assertSame('SO-2026-0003', $figures['awaitingIssue'][0]->orderNumber);
    }

    #[Test]
    public function warehouseLowStockListAndCountAgree(): void
    {
        $figures = $this->service->warehouseFigures();

        self::assertSame(1, $figures['lowStockCount']);
        self::assertCount(1, $figures['lowStock']);
        self::assertSame('SKU-000002', $figures['lowStock'][0]['product']->sku);
    }

    #[Test]
    public function warehouseQueuesMoveWhenAnOrderIsApproved(): void
    {
        $before = $this->service->warehouseFigures()['issueQueueCount'];

        $this->salesOrders->updateStatus(2, SalesOrderStatus::PendingApproval, SalesOrderStatus::Approved);

        self::assertSame(1, $before);
        self::assertSame(2, $this->service->warehouseFigures()['issueQueueCount']);
    }

    // -------------------------------------------------------- Dispatch

    #[Test]
    public function eachRoleReceivesADifferentFigureSet(): void
    {
        $admin = $this->service->forRole(Role::Admin, 1);
        $sales = $this->service->forRole(Role::Sales, self::SALES_OWNER);
        $warehouse = $this->service->forRole(Role::WarehouseStaff, 2);

        self::assertArrayHasKey('inventoryValue', $admin);
        self::assertArrayHasKey('ordersByStatus', $sales);
        self::assertArrayHasKey('receiptQueueCount', $warehouse);

        // Tidak ada kebocoran ke arah sebaliknya.
        self::assertArrayNotHasKey('inventoryValue', $sales);
        self::assertArrayNotHasKey('inventoryValue', $warehouse);
    }

    // --------------------------------------------------------- Helpers

    private function salesOrder(int $id, string $number, SalesOrderStatus $status, int $createdBy): SalesOrder
    {
        $awaitingDecision = $status === SalesOrderStatus::Draft
            || $status === SalesOrderStatus::PendingApproval;

        return new SalesOrder(
            $id,
            $number,
            1,
            $createdBy,
            $awaitingDecision ? null : 1,
            1,
            $status,
            '2026-03-0' . $id,
            [new SalesOrderItem(null, $id, 1, 2, '1400000.00')],
        );
    }

    private function purchaseOrder(int $id, string $number, PurchaseOrderStatus $status): PurchaseOrder
    {
        return new PurchaseOrder($id, $number, 1, 1, $status, '2026-03-0' . $id, 1, []);
    }
}
