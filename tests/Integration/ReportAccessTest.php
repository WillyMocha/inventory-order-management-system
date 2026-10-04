<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Controller\ReportController;
use App\Entity\Enum\Role;
use App\Entity\User;
use App\Repository\Mysql\MysqlPurchaseOrderRepository;
use App\Repository\Mysql\MysqlSalesOrderRepository;
use App\Repository\Mysql\MysqlStockLedgerRepository;
use App\Service\ReportService;
use App\Support\Exception\ForbiddenException;
use App\Support\Router;
use App\Support\SystemClock;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;

/**
 * Siapa boleh mengunduh export CSV mana (REPORT-01, matriks §1.2).
 *
 * Brief: "Download CSV report" — Admin semua, Sales order miliknya, Warehouse
 * Staff report stock saja. Dibuktikan di tiga lapis: route table, pemeriksaan
 * ulang di controller (berlaku walau route table suatu saat diubah), dan
 * halaman report yang hanya menampilkan export milik role itu.
 */
final class ReportAccessTest extends IntegrationTestCase
{
    use SalesOrderFixtures;
    use OrderControllerHarness;

    private const string STOCK_CARD = 'Stock movement report';
    private const string ORDER_CARD = 'Order status report';
    private const string PURCHASE_CARD = 'Purchase order status report';

    private ReportController $controller;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedSalesOrderFixtures();
        $this->wireOrderServices();

        $this->controller = new ReportController(
            $this->view,
            new ReportService(
                new MysqlStockLedgerRepository($this->database),
                new MysqlSalesOrderRepository($this->database),
                new MysqlPurchaseOrderRepository($this->database),
                new SystemClock(),
            ),
            $this->session,
        );
    }

    protected function tearDown(): void
    {
        $this->resetGlobals();
        parent::tearDown();
    }

    // --------------------------------------------------------- route table

    /** @return array<string, array{0: string, 1: list<Role>}> */
    public static function exportRoles(): array
    {
        return [
            'stock movement' => ['/reports/stock-movement.csv', [Role::Admin, Role::WarehouseStaff]],
            'sales orders'   => ['/reports/orders.csv', [Role::Admin, Role::Sales]],
            'purchase orders' => ['/reports/purchase-orders.csv', [Role::Admin]],
        ];
    }

    /** @param list<Role> $roles */
    #[Test]
    #[DataProvider('exportRoles')]
    public function eachExportIsOpenOnlyToTheRolesTheBriefNames(string $path, array $roles): void
    {
        $router = new Router();
        /** @var callable(Router): void $register */
        $register = require dirname(__DIR__, 2) . '/config/routes.php';
        $register($router);

        self::assertSame($roles, $router->match('GET', $path)['roles']);
    }

    // ------------------------------------------- controller (defence in depth)

    #[Test]
    public function warehouseStaffCannotExportSalesOrders(): void
    {
        $this->signInAs($this->warehouseStaff);

        $this->expectException(ForbiddenException::class);
        $this->controller->ordersCsv($this->request());
    }

    #[Test]
    public function warehouseStaffCannotExportPurchaseOrders(): void
    {
        $this->signInAs($this->warehouseStaff);

        $this->expectException(ForbiddenException::class);
        $this->controller->purchaseOrdersCsv($this->request());
    }

    #[Test]
    public function salesCannotExportPurchaseOrders(): void
    {
        $this->signInAs($this->salesCreator);

        $this->expectException(ForbiddenException::class);
        $this->controller->purchaseOrdersCsv($this->request());
    }

    #[Test]
    public function salesCannotExportStockMovement(): void
    {
        $this->signInAs($this->salesCreator);

        $this->expectException(ForbiddenException::class);
        $this->controller->stockMovementCsv($this->request());
    }

    #[Test]
    public function warehouseStaffCanStillExportStockMovement(): void
    {
        $this->signInAs($this->warehouseStaff);

        self::assertSame(200, $this->controller->stockMovementCsv($this->request())->statusCode());
    }

    // ---------------------------------------------------------- report page

    /** @return array<string, array{0: string, 1: list<string>, 2: list<string>}> */
    public static function visibleCards(): array
    {
        return [
            'admin'           => ['admin', [self::STOCK_CARD, self::ORDER_CARD, self::PURCHASE_CARD], []],
            'sales'           => ['salesCreator', [self::ORDER_CARD], [self::STOCK_CARD, self::PURCHASE_CARD]],
            'warehouse staff' => ['warehouseStaff', [self::STOCK_CARD], [self::ORDER_CARD, self::PURCHASE_CARD]],
        ];
    }

    /**
     * @param list<string> $shown
     * @param list<string> $hidden
     */
    #[Test]
    #[DataProvider('visibleCards')]
    public function theReportPageOffersOnlyTheExportsTheRoleMayDownload(string $user, array $shown, array $hidden): void
    {
        $this->signInAs($this->fixtureUser($user));

        $response = $this->controller->index($this->request());

        self::assertSame(200, $response->statusCode());
        foreach ($shown as $card) {
            self::assertStringContainsString($card, $response->body());
        }
        foreach ($hidden as $card) {
            self::assertStringNotContainsString($card, $response->body());
        }
    }

    private function fixtureUser(string $property): User
    {
        return match ($property) {
            'admin'          => $this->admin,
            'salesCreator'   => $this->salesCreator,
            'warehouseStaff' => $this->warehouseStaff,
            default          => self::fail('Unknown fixture user ' . $property),
        };
    }
}
