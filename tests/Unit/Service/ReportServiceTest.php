<?php

declare(strict_types=1);

namespace Tests\Unit\Service;

use App\Entity\Enum\PurchaseOrderStatus;
use App\Entity\Enum\Role;
use App\Entity\Enum\SalesOrderStatus;
use App\Entity\PurchaseOrder;
use App\Entity\PurchaseOrderItem;
use App\Entity\SalesOrder;
use App\Entity\SalesOrderItem;
use App\Entity\StockLedger;
use App\Service\ReportService;
use App\Support\Exception\ValidationException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Tests\Unit\Fake\FixedClock;
use Tests\Unit\Fake\InMemoryPurchaseOrderRepository;
use Tests\Unit\Fake\InMemorySalesOrderRepository;
use Tests\Unit\Fake\InMemoryStockLedgerRepository;

/**
 * Unit test ReportService (REPORT-01, FR-027).
 *
 * Tiga hal yang dijaga di sini:
 *   1. Rentang tanggal benar-benar memfilter — bukan sekadar diteruskan.
 *   2. Sales hanya mengekspor order miliknya; pembatasannya ditentukan dari
 *      role yang DI-PASS sebagai argument, bukan dibaca dari session.
 *   3. Rentang di atas 366 hari ditolak (research R-008) — export adalah satu-
 *      satunya endpoint yang mudah dibuat mahal.
 */
final class ReportServiceTest extends TestCase
{
    private const int SALES_OWNER = 10;
    private const int SALES_OTHER = 11;
    private const int ADMIN = 1;

    private InMemoryStockLedgerRepository $ledger;
    private InMemorySalesOrderRepository $salesOrders;
    private ReportService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->ledger = new InMemoryStockLedgerRepository();
        $this->ledger->withLabels(
            [1 => 'SKU-000001'],
            [1 => 'Kabel UTP Cat6'],
            [1 => 'Gudang Pusat Jakarta'],
            [self::ADMIN => 'Admin Utama'],
        );

        $this->ledger->recordAt('2026-03-05 09:00:00');
        $this->ledger->append(StockLedger::receipt(1, 1, 40, 1, self::ADMIN));
        $this->ledger->recordAt('2026-03-20 14:30:00');
        $this->ledger->append(StockLedger::issue(1, 1, 6, 1, self::ADMIN));
        $this->ledger->recordAt('2026-04-02 08:15:00');
        $this->ledger->append(StockLedger::receipt(1, 1, 10, 2, self::ADMIN));

        $this->salesOrders = new InMemorySalesOrderRepository(
            [
                $this->salesOrder(1, 'SO-2026-0001', '2026-03-04', SalesOrderStatus::Fulfilled, self::SALES_OWNER),
                $this->salesOrder(
                    2,
                    'SO-2026-0002',
                    '2026-03-18',
                    SalesOrderStatus::PendingApproval,
                    self::SALES_OWNER,
                ),
                $this->salesOrder(3, 'SO-2026-0003', '2026-03-25', SalesOrderStatus::Approved, self::SALES_OTHER),
                $this->salesOrder(4, 'SO-2026-0004', '2026-04-10', SalesOrderStatus::Draft, self::SALES_OWNER),
            ],
            [1 => 'PT Sinar Abadi'],
            [1 => 'Gudang Pusat Jakarta'],
            [self::SALES_OWNER => 'Sales Satu', self::SALES_OTHER => 'Sales Dua', self::ADMIN => 'Admin Utama'],
        );

        $purchaseOrders = new InMemoryPurchaseOrderRepository(
            [
                // Partial receipt: 10 dipesan, 4 diterima — sisanya harus terlihat.
                $this->purchaseOrder(1, 'PO-2026-0001', '2026-03-06', PurchaseOrderStatus::PartiallyReceived, 10, 4),
                $this->purchaseOrder(2, 'PO-2026-0002', '2026-03-22', PurchaseOrderStatus::Cancelled, 5, 0),
                $this->purchaseOrder(3, 'PO-2026-0003', '2026-04-12', PurchaseOrderStatus::Ordered, 3, 0),
            ],
            [1 => '=HYPERLINK("http://evil")'],
            [1 => 'Gudang Pusat Jakarta'],
            [self::ADMIN => 'Admin Utama'],
        );

        $this->service = new ReportService(
            $this->ledger,
            $this->salesOrders,
            $purchaseOrders,
            new FixedClock('2026-04-15 10:00:00'),
        );
    }

    // ------------------------------------------------ Validasi rentang

    #[Test]
    public function aRangeLongerThan366DaysIsRejected(): void
    {
        $this->expectException(ValidationException::class);

        // 2026-01-01 s.d. 2027-01-03 = 367 hari inklusif.
        $this->service->validateRange('2026-01-01', '2027-01-03');
    }

    #[Test]
    public function aRangeOfExactly366DaysIsAccepted(): void
    {
        // Batasnya inklusif: 366 hari masih boleh, 367 tidak.
        $range = $this->service->validateRange('2026-01-01', '2027-01-01');

        self::assertSame('2026-01-01', $range['start']);
        self::assertSame('2027-01-01', $range['end']);
    }

    #[Test]
    public function theMaximumRangeIsReportedInTheErrorMessage(): void
    {
        try {
            $this->service->validateRange('2026-01-01', '2027-01-03');
            self::fail('Rentang berlebih seharusnya ditolak.');
        } catch (ValidationException $e) {
            self::assertArrayHasKey('end_date', $e->errors());
            self::assertStringContainsString('366', $e->errors()['end_date']);
        }
    }

    #[Test]
    public function anEndDateBeforeTheStartDateIsRejected(): void
    {
        $this->expectException(ValidationException::class);

        $this->service->validateRange('2026-03-31', '2026-03-01');
    }

    #[Test]
    public function aMalformedDateIsRejectedRatherThanCoerced(): void
    {
        $this->expectException(ValidationException::class);

        $this->service->validateRange('31-03-2026', '2026-04-01');
    }

    #[Test]
    public function anImpossibleCalendarDateIsRejected(): void
    {
        $this->expectException(ValidationException::class);

        // Bentuknya benar tetapi tanggalnya tidak ada.
        $this->service->validateRange('2026-02-30', '2026-03-01');
    }

    #[Test]
    public function aMissingDateFallsBackToTheDefaultRangeNotAnError(): void
    {
        // Halaman report dibuka tanpa parameter apa pun: harus langsung dapat
        // dipakai, bukan menampilkan error validasi.
        $range = $this->service->defaultRange();

        self::assertSame('2026-04-15', $range['end']);
        self::assertSame('2026-03-17', $range['start']);
    }

    // -------------------------------------------- Filter rentang nyata

    #[Test]
    public function stockMovementsAreFilteredByTheRequestedRange(): void
    {
        $all = $this->service->stockMovements('2026-03-01', '2026-04-30');
        $march = $this->service->stockMovements('2026-03-01', '2026-03-31');

        self::assertCount(3, $all);
        self::assertCount(2, $march);
    }

    #[Test]
    public function theRangeBoundariesAreInclusiveOnBothEnds(): void
    {
        $exact = $this->service->stockMovements('2026-03-05', '2026-03-20');

        self::assertCount(2, $exact);
    }

    #[Test]
    public function twoDifferentRangesProduceTwoDifferentExports(): void
    {
        $first = $this->service->stockMovements('2026-03-01', '2026-03-10');
        $second = $this->service->stockMovements('2026-03-21', '2026-04-30');

        self::assertCount(1, $first);
        self::assertCount(1, $second);
        self::assertNotSame($first[0]['created_at'], $second[0]['created_at']);
    }

    #[Test]
    public function anEmptyRangeYieldsNoRowsRatherThanAnError(): void
    {
        self::assertSame([], $this->service->stockMovements('2026-01-01', '2026-01-31'));
        self::assertSame([], $this->service->salesOrders('2026-01-01', '2026-01-31', null));
    }

    // ------------------------------------------------- Scoping per role

    #[Test]
    public function salesIsScopedToTheirOwnOrders(): void
    {
        self::assertSame(self::SALES_OWNER, $this->service->scopeFor(Role::Sales, self::SALES_OWNER));
    }

    #[Test]
    public function adminAndWarehouseStaffAreNotScoped(): void
    {
        self::assertNull($this->service->scopeFor(Role::Admin, self::ADMIN));
        self::assertNull($this->service->scopeFor(Role::WarehouseStaff, 2));
    }

    #[Test]
    public function aSalesExportContainsOnlyTheirOwnOrders(): void
    {
        $rows = $this->service->salesOrders('2026-03-01', '2026-04-30', self::SALES_OWNER);

        self::assertCount(3, $rows);

        foreach ($rows as $row) {
            self::assertSame('Sales Satu', $row['created_by_name']);
        }
    }

    #[Test]
    public function twoSalesUsersExportDifferentOrders(): void
    {
        $owner = $this->service->salesOrders('2026-03-01', '2026-04-30', self::SALES_OWNER);
        $other = $this->service->salesOrders('2026-03-01', '2026-04-30', self::SALES_OTHER);

        self::assertCount(3, $owner);
        self::assertCount(1, $other);
        self::assertSame('SO-2026-0003', $other[0]['order_number']);
    }

    #[Test]
    public function anUnscopedExportContainsEveryOwner(): void
    {
        self::assertCount(4, $this->service->salesOrders('2026-03-01', '2026-04-30', null));
    }

    // ----------------------------------------------- Bentuk baris & CSV

    #[Test]
    public function stockMovementRowsCarryEveryDocumentedColumn(): void
    {
        $row = $this->service->stockMovements('2026-03-01', '2026-03-10')[0];

        foreach (ReportService::STOCK_MOVEMENT_FIELDS as $field) {
            self::assertArrayHasKey($field, $row);
        }
    }

    #[Test]
    public function orderRowsCarryEveryDocumentedColumn(): void
    {
        $row = $this->service->salesOrders('2026-03-01', '2026-03-10', null)[0];

        foreach (ReportService::ORDER_FIELDS as $field) {
            self::assertArrayHasKey($field, $row);
        }
    }

    #[Test]
    public function aCsvRowIsOrderedToMatchItsHeader(): void
    {
        $row = $this->service->salesOrders('2026-03-01', '2026-03-10', null)[0];
        $cells = $this->service->orderCsvRow($row);

        self::assertCount(count(ReportService::ORDER_HEADER), $cells);
        self::assertSame('SO-2026-0001', $cells[0]);
        self::assertSame('2026-03-04', $cells[1]);
        self::assertSame('Fulfilled', $cells[2]);
    }

    #[Test]
    public function aMissingApproverBecomesAnEmptyCellNeverTheWordNull(): void
    {
        $row = $this->service->salesOrders('2026-03-18', '2026-03-18', null)[0];
        $cells = $this->service->orderCsvRow($row);

        self::assertSame('SO-2026-0002', $cells[0]);
        self::assertSame('', $cells[6]);
    }

    #[Test]
    public function moneyInCsvIsPlainDigitsSoItCanBeSummedAgain(): void
    {
        $row = $this->service->salesOrders('2026-03-01', '2026-03-10', null)[0];
        $cells = $this->service->orderCsvRow($row);

        // 2 x 1.400.000 tanpa prefix mata uang dan tanpa pemisah ribuan.
        self::assertSame('2800000', $cells[7]);
    }

    #[Test]
    public function aStockMovementCsvRowIsOrderedToMatchItsHeader(): void
    {
        $row = $this->service->stockMovements('2026-03-01', '2026-03-10')[0];
        $cells = $this->service->stockMovementCsvRow($row);

        self::assertCount(count(ReportService::STOCK_MOVEMENT_HEADER), $cells);
        self::assertSame('2026-03-05 09:00:00', $cells[0]);
        self::assertSame('SKU-000001', $cells[1]);
        self::assertSame('Receipt', $cells[4]);
        self::assertSame('40', $cells[5]);
    }

    // ------------------------------------- CSV formula injection (CWE-1236)

    #[Test]
    public function aNameThatLooksLikeAFormulaIsWrittenAsText(): void
    {
        // Nama customer berasal dari input user. Tanpa penjagaan, sel ini
        // dieksekusi sebagai rumus begitu file-nya dibuka di spreadsheet.
        $row = [
            'order_number'     => 'SO-2026-0009',
            'order_date'       => '2026-03-04',
            'status'           => 'Draft',
            'customer_name'    => '=1+1',
            'warehouse_name'   => '@SUM(A1:A9)',
            'created_by_name'  => "\tTabbed",
            'approved_by_name' => '+62 812 0000',
            'total_value'      => '1400000.00',
        ];

        $cells = $this->service->orderCsvRow($row);

        self::assertSame("'=1+1", $cells[3]);
        self::assertSame("'@SUM(A1:A9)", $cells[4]);
        self::assertSame("'\tTabbed", $cells[5]);
        self::assertSame("'+62 812 0000", $cells[6]);
    }

    #[Test]
    public function anOrdinaryNameIsLeftExactlyAsItIs(): void
    {
        $row = $this->service->salesOrders('2026-03-01', '2026-03-10', null)[0];
        $cells = $this->service->orderCsvRow($row);

        self::assertSame('PT Sinar Abadi', $cells[3]);
        self::assertSame('SO-2026-0001', $cells[0]);
    }

    #[Test]
    public function aNegativeQuantityStaysANumberAndIsNotQuoted(): void
    {
        // Quantity Issue memang negatif. Kalau ikut diberi kutip, spreadsheet
        // tidak bisa menjumlahkannya lagi — penjagaan tidak boleh merusak data.
        $row = $this->service->stockMovements('2026-03-20', '2026-03-20')[0];
        $cells = $this->service->stockMovementCsvRow($row);

        self::assertSame('-6', $cells[5]);
    }

    // ------------------------------------- Kesepakatan dengan dashboard

    #[Test]
    public function statusTotalsAreDerivedFromTheExportedRowsThemselves(): void
    {
        $rows = $this->service->salesOrders('2026-03-01', '2026-04-30', null);
        $totals = $this->service->statusTotals($rows);

        self::assertSame(1, $totals[SalesOrderStatus::Fulfilled->value]);
        self::assertSame(1, $totals[SalesOrderStatus::PendingApproval->value]);
        self::assertSame(1, $totals[SalesOrderStatus::Approved->value]);
        self::assertSame(1, $totals[SalesOrderStatus::Draft->value]);
        self::assertSame(0, $totals[SalesOrderStatus::Cancelled->value]);

        // Jumlah seluruh tally harus sama dengan jumlah baris yang diekspor —
        // inilah yang membuat angka di layar dan isi file tidak bisa berbeda.
        self::assertSame(count($rows), array_sum($totals));
    }

    #[Test]
    public function aScopedTallyAgreesWithTheScopedExport(): void
    {
        $rows = $this->service->salesOrders('2026-03-01', '2026-04-30', self::SALES_OWNER);

        self::assertSame(count($rows), array_sum($this->service->statusTotals($rows)));
    }

    // ------------------------------------------ Purchase order export

    #[Test]
    public function purchaseOrdersAreFilteredByTheRequestedRange(): void
    {
        $rows = $this->service->purchaseOrders('2026-03-01', '2026-03-31');

        self::assertSame(
            ['PO-2026-0001', 'PO-2026-0002'],
            array_map(static fn (array $row): mixed => $row['order_number'], $rows),
        );
    }

    #[Test]
    public function aPurchaseOrderCsvRowShowsWhatIsStillOutstanding(): void
    {
        $row = $this->service->purchaseOrders('2026-03-06', '2026-03-06')[0];
        $cells = $this->service->purchaseOrderCsvRow($row);

        self::assertCount(count(ReportService::PURCHASE_ORDER_HEADER), $cells);
        self::assertSame('PO-2026-0001', $cells[0]);
        self::assertSame('PartiallyReceived', $cells[2]);
        self::assertSame('10', $cells[6], 'Ordered Qty');
        self::assertSame('4', $cells[7], 'Received Qty');
        // 10 x 250.000, angka polos agar dapat dijumlahkan spreadsheet.
        self::assertSame('2500000', $cells[8]);
    }

    #[Test]
    public function aSupplierNameThatLooksLikeAFormulaIsWrittenAsText(): void
    {
        $row = $this->service->purchaseOrders('2026-03-06', '2026-03-06')[0];

        self::assertSame('\'=HYPERLINK("http://evil")', $this->service->purchaseOrderCsvRow($row)[3]);
    }

    #[Test]
    public function purchaseOrderTotalsCoverEveryStatusAndMatchTheExport(): void
    {
        $rows = $this->service->purchaseOrders('2026-03-01', '2026-04-30');
        $totals = $this->service->purchaseOrderStatusTotals($rows);

        self::assertSame(
            array_map(static fn (PurchaseOrderStatus $s): string => $s->value, PurchaseOrderStatus::cases()),
            array_keys($totals),
        );
        self::assertSame(1, $totals[PurchaseOrderStatus::PartiallyReceived->value]);
        self::assertSame(1, $totals[PurchaseOrderStatus::Cancelled->value]);
        self::assertSame(1, $totals[PurchaseOrderStatus::Ordered->value]);
        self::assertSame(0, $totals[PurchaseOrderStatus::Received->value]);
        self::assertSame(count($rows), array_sum($totals));
    }

    // --------------------------------------------------------- Helpers

    private function purchaseOrder(
        int $id,
        string $number,
        string $orderDate,
        PurchaseOrderStatus $status,
        int $quantity,
        int $received,
    ): PurchaseOrder {
        return new PurchaseOrder(
            $id,
            $number,
            1,
            1,
            $status,
            $orderDate,
            self::ADMIN,
            [new PurchaseOrderItem(null, $id, 1, $quantity, $received, '250000.00')],
        );
    }

    private function salesOrder(
        int $id,
        string $number,
        string $orderDate,
        SalesOrderStatus $status,
        int $createdBy,
    ): SalesOrder {
        $awaitingDecision = $status === SalesOrderStatus::Draft
            || $status === SalesOrderStatus::PendingApproval;

        return new SalesOrder(
            $id,
            $number,
            1,
            $createdBy,
            $awaitingDecision ? null : self::ADMIN,
            1,
            $status,
            $orderDate,
            [new SalesOrderItem(null, $id, 1, 2, '1400000.00')],
        );
    }
}
