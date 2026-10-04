<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Controller\DashboardController;
use App\Entity\StockLedger;
use App\Entity\User;
use App\Repository\Mysql\MysqlProductRepository;
use App\Repository\Mysql\MysqlPurchaseOrderRepository;
use App\Repository\Mysql\MysqlSalesOrderRepository;
use App\Repository\Mysql\MysqlStockLedgerRepository;
use App\Service\DashboardService;
use App\Support\ClockInterface;
use App\Support\SystemClock;
use PHPUnit\Framework\Attributes\Test;
use Tests\Unit\Fake\FixedClock;

/**
 * Kartu grafik stock movement pada halaman dashboard (spec 005), dirender
 * lewat DashboardController dan View sungguhan terhadap MySQL.
 *
 * Angkanya sudah diuji di DashboardStockMovementTest; di sini yang dibuktikan
 * adalah halaman: kartu muncul untuk Admin dan Warehouse Staff tetapi tidak
 * untuk Sales (FR-011), empty state (FR-009), figur per hari dapat dibaca
 * lewat hover, fokus keyboard, dan screen reader (FR-008), serta link ke
 * report dengan rentang yang sama (FR-010).
 *
 * @phpstan-import-type StockMovement from DashboardService
 */
final class DashboardChartRenderTest extends IntegrationTestCase
{
    use SalesOrderFixtures;
    use OrderControllerHarness;

    private const string CARD_TITLE = 'Stock movement — last 30 days';
    private const string CHART_MARKER = 'class="movement-chart"';
    private const string EMPTY_HEADING = 'No stock moved in the last 30 days';
    private const string DAY_SLOT = 'class="chart-day"';

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedSalesOrderFixtures();
        $this->wireOrderServices();
    }

    protected function tearDown(): void
    {
        $this->resetGlobals();
        parent::tearDown();
    }

    // ------------------------------------------------ US1: per role (FR-011)

    #[Test]
    public function adminAndWarehouseStaffSeeTheChartWithTheLedgerTotals(): void
    {
        $this->receiveToday(5);

        foreach ([$this->admin, $this->warehouseStaff] as $user) {
            $body = $this->dashboardFor($user);
            $figure = $this->figure();

            self::assertStringContainsString(self::CARD_TITLE, $body, $user->email);
            self::assertStringContainsString(self::CHART_MARKER, $body, $user->email);
            self::assertGreaterThanOrEqual(5, $figure['totalIn']);
            self::assertStringContainsString(
                ': ' . $figure['totalIn'] . ' units in, ' . $figure['totalOut'] . ' units out"',
                $body,
                'Total di halaman harus sama dengan figure service',
            );
        }
    }

    #[Test]
    public function salesNeverSeesTheChart(): void
    {
        $this->receiveToday(5);

        $body = $this->dashboardFor($this->salesCreator);

        self::assertStringNotContainsString(self::CARD_TITLE, $body);
        self::assertStringNotContainsString('movement-chart', $body);
    }

    // --------------------------------------------------- US1: empty (FR-009)

    #[Test]
    public function anEmptyWindowShowsTheEmptyStateInsteadOfAChart(): void
    {
        // Jendela 30 hari di tahun 2000 pasti kosong, apa pun isi ioms_test.
        $body = $this->dashboardFor($this->admin, new FixedClock('2000-01-31 10:00:00'));

        self::assertStringContainsString(self::CARD_TITLE, $body);
        self::assertStringContainsString(self::EMPTY_HEADING, $body);
        self::assertStringNotContainsString(self::CHART_MARKER, $body);
    }

    // -------------------------------------------- US2: exact figures (FR-008)

    #[Test]
    public function everyDayHasATitleAndOnlyDaysWithMovementAreTabStops(): void
    {
        $this->receiveToday(5);

        $body = $this->dashboardFor($this->admin);
        $figure = $this->figure();
        $activeDays = count(array_filter($figure['days'], static fn (array $d): bool => $d['in'] + $d['out'] > 0));
        $today = $figure['days'][29];

        self::assertSame(30, substr_count($body, self::DAY_SLOT));
        self::assertSame($activeDays, substr_count($body, 'class="chart-day" tabindex="0"'));
        self::assertStringContainsString(
            '<title>' . (new \DateTimeImmutable($today['date']))->format('j M Y')
            . ' — In ' . $today['in'] . ' · Out ' . $today['out'] . '</title>',
            $body,
        );
    }

    #[Test]
    public function theChartHasASummaryLabelAndAScreenReaderTable(): void
    {
        $this->receiveToday(5);

        $body = $this->dashboardFor($this->admin);
        $figure = $this->figure();

        self::assertStringContainsString('role="img"', $body);
        self::assertStringContainsString(
            'aria-label="Stock movement, last 30 days: ' . $figure['totalIn'] . ' units in, '
            . $figure['totalOut'] . ' units out"',
            $body,
        );
        self::assertSame(1, preg_match('/<div class="visually-hidden">\s*<table>(.*?)<\/table>/s', $body, $table));
        self::assertSame(30, substr_count($table[1], '<tr>') - 1, '30 baris data + 1 baris header');
    }

    // ------------------------------------------------ US3: report link (FR-010)

    #[Test]
    public function theCardLinksToTheReportForTheSameRange(): void
    {
        $this->receiveToday(5);
        $figure = $this->figure();
        $expected = 'href="/reports?start_date=' . $figure['start'] . '&amp;end_date=' . $figure['end'] . '"';

        self::assertStringContainsString($expected, $this->dashboardFor($this->admin));
    }

    #[Test]
    public function theReportLinkIsKeptInTheEmptyState(): void
    {
        $body = $this->dashboardFor($this->admin, new FixedClock('2000-01-31 10:00:00'));

        self::assertStringContainsString('href="/reports?start_date=2000-01-02&amp;end_date=2000-01-31"', $body);
        self::assertStringContainsString('View stock movement report', $body);
    }

    // --------------------------------------------------------------- Helpers

    private function receiveToday(int $quantity): void
    {
        (new MysqlStockLedgerRepository($this->database))->append(
            StockLedger::receipt($this->productId, $this->warehouseId, $quantity, 1, (int) $this->warehouseStaff->id),
        );
    }

    private function dashboardFor(User $user, ?ClockInterface $clock = null): string
    {
        $this->signInAs($user);

        $controller = new DashboardController($this->view, $this->dashboardService($clock), $this->session);
        $response = $controller->index($this->request());

        self::assertSame(200, $response->statusCode());

        return $response->body();
    }

    /**
     * Figure yang sama dengan yang dirender, untuk dibandingkan dengan isi
     * halaman tanpa bergantung pada baris lain di database test.
     *
     * @return StockMovement
     */
    private function figure(): array
    {
        return $this->dashboardService(null)->adminFigures()['stockMovement'];
    }

    private function dashboardService(?ClockInterface $clock): DashboardService
    {
        return new DashboardService(
            new MysqlProductRepository($this->database),
            new MysqlSalesOrderRepository($this->database),
            new MysqlPurchaseOrderRepository($this->database),
            new MysqlStockLedgerRepository($this->database),
            $clock ?? new SystemClock(),
        );
    }
}
