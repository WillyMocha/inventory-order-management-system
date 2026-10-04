<?php

declare(strict_types=1);

namespace Tests\Unit\Service;

use App\Entity\StockLedger;
use App\Service\DashboardService;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Tests\Unit\Fake\FixedClock;
use Tests\Unit\Fake\InMemoryProductRepository;
use Tests\Unit\Fake\InMemoryPurchaseOrderRepository;
use Tests\Unit\Fake\InMemorySalesOrderRepository;
use Tests\Unit\Fake\InMemoryStockLedgerRepository;

/**
 * Figure grafik stock movement di dashboard (spec 005-stock-movement-chart).
 *
 * Dipisah dari DashboardServiceTest agar masing-masing tetap ringkas. Yang
 * dibuktikan: jendela 30 hari diambil dari clock dan diisi nol untuk hari
 * kosong (FR-001, FR-006), masuk/keluar dipisah dari tanda quantity termasuk
 * Adjustment (FR-004), totalnya sama dengan jumlah batang (FR-007), dan Sales
 * tidak pernah menerima figure ini maupun memicu query ledger (FR-011).
 */
final class DashboardStockMovementTest extends TestCase
{
    private const string TODAY = '2026-10-04';
    private const string FIRST_DAY = '2026-09-05';
    private const int PRODUCT = 1;
    private const int WAREHOUSE = 1;
    private const int USER = 7;

    private InMemoryStockLedgerRepository $ledger;
    private DashboardService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->ledger = new InMemoryStockLedgerRepository();
        $this->service = new DashboardService(
            new InMemoryProductRepository([]),
            new InMemorySalesOrderRepository([]),
            new InMemoryPurchaseOrderRepository([]),
            $this->ledger,
            new FixedClock(self::TODAY . ' 10:00:00'),
        );
    }

    #[Test]
    public function theWindowIsThirtyConsecutiveDaysEndingTodayOldestFirst(): void
    {
        $movement = $this->service->adminFigures()['stockMovement'];

        self::assertSame(self::FIRST_DAY, $movement['start']);
        self::assertSame(self::TODAY, $movement['end']);
        self::assertCount(DashboardService::MOVEMENT_DAYS, $movement['days']);
        self::assertSame(self::FIRST_DAY, $movement['days'][0]['date']);
        self::assertSame(self::TODAY, $movement['days'][29]['date']);

        $expected = new \DateTimeImmutable(self::FIRST_DAY);
        foreach ($movement['days'] as $day) {
            self::assertSame($expected->format('Y-m-d'), $day['date']);
            $expected = $expected->modify('+1 day');
        }
    }

    #[Test]
    public function daysWithoutMovementAreZeroNotMissing(): void
    {
        $this->record('2026-09-20 08:00:00', StockLedger::receipt(self::PRODUCT, self::WAREHOUSE, 5, 1, self::USER));

        $days = $this->daysByDate();

        self::assertSame(['date' => '2026-09-19', 'in' => 0, 'out' => 0], $days['2026-09-19']);
        self::assertSame(['date' => '2026-09-20', 'in' => 5, 'out' => 0], $days['2026-09-20']);
    }

    #[Test]
    public function receiptsCountAsInAndIssuesAsOut(): void
    {
        $this->record('2026-10-01 08:00:00', StockLedger::receipt(self::PRODUCT, self::WAREHOUSE, 5, 1, self::USER));
        $this->record('2026-10-01 15:00:00', StockLedger::issue(self::PRODUCT, self::WAREHOUSE, 3, 2, self::USER));

        self::assertSame(['date' => '2026-10-01', 'in' => 5, 'out' => 3], $this->daysByDate()['2026-10-01']);
    }

    #[Test]
    public function adjustmentsCountBySign(): void
    {
        // Clarification Q2: koreksi positif = masuk, negatif = keluar.
        $this->record('2026-10-02 08:00:00', $this->adjustment(4));
        $this->record('2026-10-02 09:00:00', $this->adjustment(-2));

        self::assertSame(['date' => '2026-10-02', 'in' => 4, 'out' => 2], $this->daysByDate()['2026-10-02']);
    }

    #[Test]
    public function totalsEqualTheSumOfTheBarsAndNetEqualsTheStockChange(): void
    {
        $this->record('2026-09-06 08:00:00', StockLedger::receipt(self::PRODUCT, self::WAREHOUSE, 10, 1, self::USER));
        $this->record('2026-09-15 08:00:00', StockLedger::issue(self::PRODUCT, self::WAREHOUSE, 4, 2, self::USER));
        $this->record('2026-10-04 08:00:00', $this->adjustment(-1));

        $movement = $this->service->adminFigures()['stockMovement'];

        self::assertSame(array_sum(array_column($movement['days'], 'in')), $movement['totalIn']);
        self::assertSame(array_sum(array_column($movement['days'], 'out')), $movement['totalOut']);
        self::assertSame(10, $movement['totalIn']);
        self::assertSame(5, $movement['totalOut']);
        // Net = jumlah seluruh quantity ledger di jendela: 10 - 4 - 1.
        self::assertSame(5, $movement['net']);
    }

    #[Test]
    public function movementsOutsideTheWindowAreIgnored(): void
    {
        $this->record('2026-09-04 23:59:59', StockLedger::receipt(self::PRODUCT, self::WAREHOUSE, 50, 1, self::USER));
        $this->record('2026-09-05 00:00:00', StockLedger::receipt(self::PRODUCT, self::WAREHOUSE, 2, 1, self::USER));

        $movement = $this->service->adminFigures()['stockMovement'];

        self::assertSame(2, $movement['totalIn']);
        self::assertSame(2, $movement['days'][0]['in']);
    }

    #[Test]
    public function anEmptyLedgerGivesThirtyZeroDaysAndZeroTotals(): void
    {
        $movement = $this->service->adminFigures()['stockMovement'];

        self::assertCount(DashboardService::MOVEMENT_DAYS, $movement['days']);
        self::assertSame(0, $movement['totalIn']);
        self::assertSame(0, $movement['totalOut']);
        self::assertSame(0, $movement['net']);
    }

    #[Test]
    public function warehouseStaffSeesTheSameFigureAsAdmin(): void
    {
        $this->record('2026-10-03 08:00:00', StockLedger::receipt(self::PRODUCT, self::WAREHOUSE, 7, 1, self::USER));

        self::assertSame(
            $this->service->adminFigures()['stockMovement'],
            $this->service->warehouseFigures()['stockMovement'],
        );
    }

    #[Test]
    public function eachAdminOrWarehouseDashboardRunsExactlyOneLedgerQuery(): void
    {
        $this->service->adminFigures();
        self::assertSame(1, $this->ledger->dailyTotalsCalls());

        $this->service->warehouseFigures();
        self::assertSame(2, $this->ledger->dailyTotalsCalls());
    }

    #[Test]
    public function salesNeverReceivesTheFigureNorTriggersALedgerQuery(): void
    {
        $figures = $this->service->salesFigures(self::USER);

        self::assertArrayNotHasKey('stockMovement', $figures);
        self::assertSame(0, $this->ledger->dailyTotalsCalls());
    }

    // --------------------------------------------------------- Helpers

    private function record(string $timestamp, StockLedger $entry): void
    {
        $this->ledger->recordAt($timestamp);
        $this->ledger->append($entry);
    }

    private function adjustment(int $delta): StockLedger
    {
        return StockLedger::adjustment(self::PRODUCT, self::WAREHOUSE, $delta, 'Physical count', self::USER);
    }

    /** @return array<string, array{date: string, in: int, out: int}> */
    private function daysByDate(): array
    {
        $days = [];

        foreach ($this->service->adminFigures()['stockMovement']['days'] as $day) {
            $days[$day['date']] = $day;
        }

        return $days;
    }
}
