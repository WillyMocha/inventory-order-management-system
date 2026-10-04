<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Entity\Enum\Role;
use App\Entity\Enum\SalesOrderStatus;
use App\Entity\SalesOrder;
use App\Entity\SalesOrderItem;
use App\Entity\StockLedger;
use App\Repository\Mysql\MysqlProductRepository;
use App\Repository\Mysql\MysqlPurchaseOrderRepository;
use App\Repository\Mysql\MysqlSalesOrderRepository;
use App\Repository\Mysql\MysqlStockLedgerRepository;
use App\Service\DashboardService;
use App\Service\ReportService;
use App\Support\SystemClock;
use PHPUnit\Framework\Attributes\Test;

/**
 * FR-026 dan FR-027 terhadap MySQL nyata: angka dashboard dan isi file export
 * SEPAKAT untuk rentang yang sama.
 *
 * Unit test membuktikan tiap service benar sendiri-sendiri terhadap fake.
 * Yang hanya bisa dibuktikan di sini adalah bahwa SQL sungguhan di balik
 * keduanya membaca data yang sama: GROUP BY pada dashboard dan baris detail
 * pada export berasal dari tabel yang sama, dan jumlahnya tidak boleh
 * berbeda. Kalau salah satunya suatu saat memakai query sendiri, test inilah
 * yang jatuh lebih dulu.
 */
final class DashboardReportConsistencyTest extends IntegrationTestCase
{
    use SalesOrderFixtures;

    /** Rentang yang pasti mencakup seluruh order fixture. */
    private const string RANGE_START = '2026-09-01';
    private const string RANGE_END = '2026-09-30';

    /** Tanggal lampau tetap untuk grafik stock movement (spec 005). */
    private const string CHART_DAY_ONE = '2025-01-10';
    private const string CHART_DAY_TWO = '2025-01-11';
    private const string CHART_DAY_GAP = '2025-01-12';

    private DashboardService $dashboard;
    private ReportService $report;
    private MysqlSalesOrderRepository $salesOrders;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedSalesOrderFixtures();

        $products = new MysqlProductRepository($this->database);
        $this->salesOrders = new MysqlSalesOrderRepository($this->database);
        $purchaseOrders = new MysqlPurchaseOrderRepository($this->database);

        $ledger = new MysqlStockLedgerRepository($this->database);
        $clock = new SystemClock();

        $this->dashboard = new DashboardService($products, $this->salesOrders, $purchaseOrders, $ledger, $clock);
        $this->report = new ReportService($ledger, $this->salesOrders, $purchaseOrders, $clock);
    }

    // -------------------------------------- Dashboard vs export (FR-027)

    #[Test]
    public function theExportAndTheDashboardReportTheSameStatusTotals(): void
    {
        $this->setStock($this->productId, $this->warehouseId, 100);

        $this->approvedOrder([[$this->productId, 2]]);
        $this->approvedOrder([[$this->productId, 3]]);
        $this->draftOrder([[$this->productId, 1]]);

        $rows = $this->report->salesOrders(self::RANGE_START, self::RANGE_END, null);
        $exported = $this->report->statusTotals($rows);
        $onScreen = $this->dashboard->adminFigures()['salesOrdersByStatus'];

        // Rentangnya mencakup seluruh order yang ada, jadi kedua tally wajib
        // identik — bukan sekadar berdekatan.
        self::assertSame($onScreen, $exported);
    }

    #[Test]
    public function theIssueQueueFigureEqualsTheApprovedOrdersInTheExport(): void
    {
        $this->setStock($this->productId, $this->warehouseId, 100);

        $this->approvedOrder([[$this->productId, 2]]);
        $this->approvedOrder([[$this->productId, 3]]);
        $this->draftOrder([[$this->productId, 1]]);

        $rows = $this->report->salesOrders(self::RANGE_START, self::RANGE_END, null);

        self::assertSame(
            $this->dashboard->warehouseFigures()['issueQueueCount'],
            $this->report->statusTotals($rows)[SalesOrderStatus::Approved->value],
        );
    }

    #[Test]
    public function aSalesDashboardAndASalesExportAgreeOnTheSameScope(): void
    {
        $this->setStock($this->productId, $this->warehouseId, 100);

        $this->approvedOrder([[$this->productId, 2]]);
        $this->draftOrder([[$this->productId, 1]]);

        $salesUserId = (int) $this->salesCreator->id;
        $scope = $this->report->scopeFor(Role::Sales, $salesUserId);

        $rows = $this->report->salesOrders(self::RANGE_START, self::RANGE_END, $scope);
        $figures = $this->dashboard->salesFigures($salesUserId);

        self::assertSame($salesUserId, $scope);
        self::assertSame($figures['ordersByStatus'], $this->report->statusTotals($rows));
        self::assertSame($figures['total'], count($rows));
    }

    #[Test]
    public function aSalesExportNeverContainsAnotherUsersOrder(): void
    {
        $this->setStock($this->productId, $this->warehouseId, 100);
        $this->approvedOrder([[$this->productId, 2]]);

        // Order milik Admin, dibuat lewat jalur repository yang sama.
        $this->salesOrders->save($this->foreignOrder());

        $scoped = $this->report->salesOrders(
            self::RANGE_START,
            self::RANGE_END,
            (int) $this->salesCreator->id,
        );
        $unscoped = $this->report->salesOrders(self::RANGE_START, self::RANGE_END, null);

        $numbers = array_column($scoped, 'order_number');

        self::assertNotContains('SO-FOREIGN-TEMP', $numbers);
        self::assertCount(count($scoped) + 1, $unscoped);
    }

    // ---------------------------------------- Dua rentang, dua hasil

    #[Test]
    public function twoDifferentRangesProduceTwoDifferentExports(): void
    {
        $this->setStock($this->productId, $this->warehouseId, 100);
        $this->approvedOrder([[$this->productId, 2]]);

        $covering = $this->report->salesOrders(self::RANGE_START, self::RANGE_END, null);
        $outside = $this->report->salesOrders('2026-01-01', '2026-01-31', null);

        self::assertNotEmpty($covering);
        self::assertSame([], $outside);
    }

    #[Test]
    public function theRangeBoundariesAreInclusiveAgainstRealSql(): void
    {
        $this->setStock($this->productId, $this->warehouseId, 100);
        $this->approvedOrder([[$this->productId, 2]]);

        // Fixture memakai order_date 2026-09-11; rentang satu hari tepat di
        // tanggal itu harus tetap mengembalikan barisnya.
        self::assertCount(1, $this->report->salesOrders('2026-09-11', '2026-09-11', null));
        self::assertSame([], $this->report->salesOrders('2026-09-12', '2026-09-12', null));
    }

    // ------------------------------------- Isi baris export (FR-027)

    #[Test]
    public function exportedOrderRowsCarryEveryDocumentedColumn(): void
    {
        $this->setStock($this->productId, $this->warehouseId, 100);
        $this->approvedOrder([[$this->productId, 2]]);

        $row = $this->report->salesOrders(self::RANGE_START, self::RANGE_END, null)[0];

        foreach (ReportService::ORDER_FIELDS as $field) {
            self::assertArrayHasKey($field, $row, 'Kolom ' . $field . ' hilang dari query MySQL.');
        }

        self::assertCount(count(ReportService::ORDER_HEADER), $this->report->orderCsvRow($row));
    }

    #[Test]
    public function exportedPurchaseOrderRowsCarryEveryDocumentedColumn(): void
    {
        $orderId = $this->orderedPurchaseOrder([[$this->productId, 4], [$this->secondProductId, 6]]);
        $orderNumber = (new MysqlPurchaseOrderRepository($this->database))->findById($orderId)?->orderNumber;

        $rows = array_values(array_filter(
            $this->report->purchaseOrders(self::RANGE_START, self::RANGE_END),
            static fn (array $row): bool => $row['order_number'] === $orderNumber,
        ));

        self::assertCount(1, $rows, 'Satu PO harus menjadi satu baris, bukan satu baris per item.');

        foreach (ReportService::PURCHASE_ORDER_FIELDS as $field) {
            self::assertArrayHasKey($field, $rows[0], 'Kolom ' . $field . ' hilang dari query MySQL.');
        }

        $cells = $this->report->purchaseOrderCsvRow($rows[0]);

        self::assertCount(count(ReportService::PURCHASE_ORDER_HEADER), $cells);
        self::assertSame('Ordered', $cells[2]);
        self::assertSame('10', $cells[6], 'Ordered Qty dijumlahkan dari seluruh item');
        self::assertSame('0', $cells[7]);
    }

    #[Test]
    public function exportedStockMovementRowsCarryEveryDocumentedColumn(): void
    {
        $this->setStock($this->productId, $this->warehouseId, 100);
        $this->appendLedgerRow();

        $today = (new SystemClock())->now()->format('Y-m-d');
        $rows = $this->report->stockMovements($today, $today);

        self::assertNotEmpty($rows, 'Baris ledger hari ini seharusnya terbaca.');

        foreach (ReportService::STOCK_MOVEMENT_FIELDS as $field) {
            self::assertArrayHasKey($field, $rows[0], 'Kolom ' . $field . ' hilang dari query MySQL.');
        }

        self::assertCount(
            count(ReportService::STOCK_MOVEMENT_HEADER),
            $this->report->stockMovementCsvRow($rows[0]),
        );
    }

    #[Test]
    public function theInventoryValueFigureUsesThePurchasePrice(): void
    {
        // spec A-007. Fixture product one berharga beli 1000.00.
        $this->setStock($this->productId, $this->warehouseId, 7);
        $this->setStock($this->secondProductId, $this->warehouseId, 0);

        $value = (float) $this->dashboard->adminFigures()['inventoryValue'];

        self::assertSame(7000.0, $value);
    }

    // ---------------------------- Stock movement chart (spec 005, FR-013)

    #[Test]
    public function dailyMovementTotalsSplitEveryMovementTypeBySign(): void
    {
        $this->seedTwoChartDays();

        $totals = (new MysqlStockLedgerRepository($this->database))
            ->dailyMovementTotals(self::CHART_DAY_ONE, self::CHART_DAY_TWO);

        // Receipt 10 + Adjustment +4 masuk; Issue 3 + Adjustment -2 keluar.
        self::assertSame(
            [
                self::CHART_DAY_ONE => ['in' => 14, 'out' => 5],
                self::CHART_DAY_TWO => ['in' => 6, 'out' => 1],
            ],
            $totals,
        );
    }

    #[Test]
    public function aDayWithoutMovementIsAbsentFromTheDailyTotals(): void
    {
        $this->seedTwoChartDays();

        $totals = (new MysqlStockLedgerRepository($this->database))
            ->dailyMovementTotals(self::CHART_DAY_ONE, self::CHART_DAY_GAP);

        self::assertArrayNotHasKey(self::CHART_DAY_GAP, $totals);
    }

    #[Test]
    public function dailyMovementTotalsEqualTheExportedRowsOfTheSameDay(): void
    {
        $this->seedTwoChartDays();
        $ledger = new MysqlStockLedgerRepository($this->database);

        foreach ([self::CHART_DAY_ONE, self::CHART_DAY_TWO] as $day) {
            $expected = ['in' => 0, 'out' => 0];

            foreach ($ledger->movementsBetween($day, $day) as $row) {
                $quantity = (int) $row['quantity'];
                $expected[$quantity > 0 ? 'in' : 'out'] += abs($quantity);
            }

            self::assertSame($expected, $ledger->dailyMovementTotals($day, $day)[$day] ?? null, 'Hari ' . $day);
        }
    }

    #[Test]
    public function movementsOfADeactivatedProductStillCount(): void
    {
        $this->seedTwoChartDays();
        (new MysqlProductRepository($this->database))->setActive($this->secondProductId, false);

        $totals = (new MysqlStockLedgerRepository($this->database))
            ->dailyMovementTotals(self::CHART_DAY_TWO, self::CHART_DAY_TWO);

        // Hari kedua hanya berisi pergerakan product kedua.
        self::assertSame(['in' => 6, 'out' => 1], $totals[self::CHART_DAY_TWO] ?? null);
    }

    #[Test]
    public function theDashboardChartMatchesTheStockMovementExportForItsWindow(): void
    {
        // Baris hari ini (NOW()): data dua tanggal lampau di atas berada di
        // luar jendela 30 hari dashboard.
        $ledger = new MysqlStockLedgerRepository($this->database);
        $staff = (int) $this->warehouseStaff->id;
        $this->appendLedgerRow();
        $ledger->append(StockLedger::issue($this->productId, $this->warehouseId, 2, 1, $staff));
        $ledger->append(StockLedger::adjustment($this->productId, $this->warehouseId, 3, 'Found in count', $staff));
        $ledger->append(StockLedger::adjustment($this->productId, $this->warehouseId, -1, 'Damaged', $staff));

        $movement = $this->dashboard->adminFigures()['stockMovement'];

        $expected = [];
        $net = 0;
        foreach ($this->report->stockMovements($movement['start'], $movement['end']) as $row) {
            $day = substr((string) $row['created_at'], 0, 10);
            $quantity = (int) $row['quantity'];
            $expected[$day] ??= ['in' => 0, 'out' => 0];
            $expected[$day][$quantity > 0 ? 'in' : 'out'] += abs($quantity);
            $net += $quantity;
        }

        foreach ($movement['days'] as $day) {
            self::assertSame(
                $expected[$day['date']] ?? ['in' => 0, 'out' => 0],
                ['in' => $day['in'], 'out' => $day['out']],
                'Hari ' . $day['date'],
            );
        }
        self::assertSame($net, $movement['net'], 'Net grafik = jumlah quantity di export');
    }

    // ------------------------------------------------------- Helpers

    /**
     * Pergerakan pada dua tanggal lampau yang tidak disentuh test lain:
     * keempat tipe, termasuk Adjustment positif dan negatif (spec 005 Q2).
     */
    private function seedTwoChartDays(): void
    {
        $dayOne = self::CHART_DAY_ONE . ' 09:00:00';
        $dayTwo = self::CHART_DAY_TWO . ' 23:59:59';

        $this->insertLedgerAt($this->productId, 'Receipt', 10, 'PurchaseOrder', $dayOne);
        $this->insertLedgerAt($this->productId, 'Issue', -3, 'SalesOrder', $dayOne);
        $this->insertLedgerAt($this->productId, 'Adjustment', 4, 'Manual', $dayOne);
        $this->insertLedgerAt($this->productId, 'Adjustment', -2, 'Manual', $dayOne);
        $this->insertLedgerAt($this->secondProductId, 'Receipt', 6, 'PurchaseOrder', $dayTwo);
        $this->insertLedgerAt($this->secondProductId, 'Issue', -1, 'SalesOrder', $dayTwo);
    }

    /**
     * Baris ledger dengan created_at eksplisit. appendLedgerRow() selalu
     * memakai NOW(); di sini tanggalnya harus tetap. INSERT tetap diizinkan
     * trigger append-only, dan transaction test membatalkannya.
     */
    private function insertLedgerAt(
        int $productId,
        string $movementType,
        int $quantity,
        string $referenceType,
        string $createdAt,
    ): void {
        $this->pdo->prepare(
            'INSERT INTO stock_ledger (product_id, warehouse_id, movement_type, quantity, reference_type,
                                       reference_id, performed_by, note, created_at)
                  VALUES (:product_id, :warehouse_id, :movement_type, :quantity, :reference_type,
                          :reference_id, :performed_by, :note, :created_at)',
        )->execute([
            'product_id'     => $productId,
            'warehouse_id'   => $this->warehouseId,
            'movement_type'  => $movementType,
            'quantity'       => $quantity,
            'reference_type' => $referenceType,
            // Reference polimorfik tanpa foreign key; ck_ledger_reference_id
            // hanya menuntut id terisi untuk PO/SO dan kosong untuk Manual.
            'reference_id'   => $referenceType === 'Manual' ? null : 1,
            'performed_by'   => (int) $this->warehouseStaff->id,
            'note'           => $movementType === 'Adjustment' ? 'Fixture count' : null,
            'created_at'     => $createdAt,
        ]);
    }

    private function foreignOrder(): SalesOrder
    {
        return new SalesOrder(
            null,
            'SO-FOREIGN-TEMP',
            $this->customerId,
            (int) $this->admin->id,
            null,
            $this->warehouseId,
            SalesOrderStatus::Draft,
            '2026-09-11',
            [new SalesOrderItem(null, null, $this->productId, 1, '1500.00')],
        );
    }

    /**
     * Satu baris ledger ditulis lewat repository-nya sendiri — append-only,
     * sama seperti jalur aplikasi.
     */
    private function appendLedgerRow(): void
    {
        (new MysqlStockLedgerRepository($this->database))->append(
            StockLedger::receipt(
                $this->productId,
                $this->warehouseId,
                5,
                $this->orderedPurchaseOrder([[$this->productId, 5]]),
                (int) $this->warehouseStaff->id,
            ),
        );
    }
}
