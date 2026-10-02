<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Controller\Api\DashboardApiController;
use App\Controller\Api\StockApiController;
use App\Entity\Enum\Role;
use App\Repository\Mysql\MysqlCategoryRepository;
use App\Repository\Mysql\MysqlProductRepository;
use App\Repository\Mysql\MysqlProductStockRepository;
use App\Repository\Mysql\MysqlWarehouseRepository;
use App\Service\MasterDataService;
use App\Service\ProductService;
use App\Support\Authorization;
use App\Support\Exception\ForbiddenException;
use App\Support\Exception\NotFoundException;
use App\Support\Exception\UnauthenticatedException;
use App\Support\Request;
use App\Support\Response;
use App\Support\Router;
use App\Support\Session;
use PHPUnit\Framework\Attributes\Test;

/**
 * FR-028 dan API-01 terhadap route table sungguhan dan MySQL sungguhan.
 *
 * Yang dibuktikan di sini adalah tiga keadaan yang membedakan endpoint API dari
 * route HTML:
 *   1. sudah masuk         -> 200 dengan bentuk yang didokumentasikan
 *   2. belum masuk         -> **401 sebagai JSON**, bukan halaman login HTML
 *   3. SKU tidak dikenal   -> 404 sebagai JSON
 * ditambah scoping role pada /api/dashboard/low-stock: Sales menerima 403,
 * Admin dan Warehouse Staff menerima 200 (§1.2, contracts/openapi.yaml).
 *
 * Guard dan penerjemahan exception dipanggil LANGSUNG, meniru caller yang
 * melewati UI sepenuhnya.
 */
final class StockApiTest extends IntegrationTestCase
{
    use SalesOrderFixtures;

    private const string AVAILABILITY_PATH = '/api/products/FIXTURE-SKU-1/availability';
    private const string LOW_STOCK_PATH = '/api/dashboard/low-stock';

    private Router $router;
    private Session $session;
    private Authorization $authorization;

    private StockApiController $stockApi;
    private DashboardApiController $dashboardApi;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedSalesOrderFixtures();

        $this->router = new Router();
        /** @var callable(Router): void $register */
        $register = require dirname(__DIR__, 2) . '/config/routes.php';
        $register($this->router);

        $this->session = new Session('IOMS_TEST_SESSION', false);
        $this->authorization = new Authorization($this->session);

        $productService = new ProductService(
            new MysqlProductRepository($this->database),
            new MysqlProductStockRepository($this->database),
            new MysqlCategoryRepository($this->database),
        );

        $this->stockApi = new StockApiController(
            $productService,
            new MasterDataService(
                new MysqlCategoryRepository($this->database),
                new MysqlWarehouseRepository($this->database),
            ),
        );

        $this->dashboardApi = new DashboardApiController($productService);

        $_SESSION = [];
    }

    protected function tearDown(): void
    {
        $_SESSION = [];
        parent::tearDown();
    }

    // ----------------------------------------- Tiga keadaan (T122)

    #[Test]
    public function signedInTheContractShapeIsReturnedAsJson(): void
    {
        $this->setStock($this->productId, $this->warehouseId, 25);
        $this->signInAs(Role::Sales);

        $route = $this->router->match('GET', self::AVAILABILITY_PATH);
        $this->authorization->authorizeRoute($route['roles']);

        $response = $this->stockApi->availability(
            Request::fromGlobals()->withRouteParams(['sku' => 'FIXTURE-SKU-1']),
        );

        self::assertSame(200, $response->statusCode());
        $this->assertJsonContentType($response);

        $body = $this->decode($response);

        self::assertSame('FIXTURE-SKU-1', $body['sku']);
        self::assertSame('Fixture Product One', $body['productName']);
        self::assertSame(25, $body['totalQuantity']);
        self::assertIsBool($body['lowStock']);
        self::assertIsArray($body['warehouses']);
    }

    #[Test]
    public function signedOutTheAnswerIs401AsJsonAndNeverAnHtmlLoginPage(): void
    {
        // Tidak ada session sama sekali.
        $route = $this->router->match('GET', self::AVAILABILITY_PATH);

        $this->expectException(UnauthenticatedException::class);

        $this->authorization->authorizeRoute($route['roles']);
    }

    #[Test]
    public function theUnauthenticatedEnvelopeIsJsonForApiPathsAndARedirectForPages(): void
    {
        // Perbedaan ini ada di Request::expectsJson(), dan inilah yang membuat
        // front controller memilih 401 JSON alih-alih redirect ke /login.
        $apiRequest = $this->requestFor(self::AVAILABILITY_PATH);
        $pageRequest = $this->requestFor('/products');

        self::assertTrue($apiRequest->expectsJson());
        self::assertFalse($pageRequest->expectsJson());

        $envelope = Response::jsonError('unauthorized', 'Authentication required.', 401);

        self::assertSame(401, $envelope->statusCode());
        $this->assertJsonContentType($envelope);
        self::assertSame(
            ['error' => ['code' => 'unauthorized', 'message' => 'Authentication required.']],
            $this->decode($envelope),
        );
    }

    #[Test]
    public function anUnknownSkuIs404(): void
    {
        $this->signInAs(Role::Admin);

        $this->expectException(NotFoundException::class);

        $this->stockApi->availability(
            Request::fromGlobals()->withRouteParams(['sku' => 'SKU-DOES-NOT-EXIST']),
        );
    }

    #[Test]
    public function everyApiResponseDeclaresJsonContentType(): void
    {
        $this->setStock($this->productId, $this->warehouseId, 5);
        $this->signInAs(Role::Admin);

        $responses = [
            $this->stockApi->availability(
                Request::fromGlobals()->withRouteParams(['sku' => 'FIXTURE-SKU-1']),
            ),
            $this->stockApi->available(Request::fromGlobals()->withRouteParams([
                'productId'   => (string) $this->productId,
                'warehouseId' => (string) $this->warehouseId,
            ])),
            $this->dashboardApi->lowStock(Request::fromGlobals()),
            Response::jsonError('not_found', 'Resource not found.', 404),
        ];

        foreach ($responses as $response) {
            $this->assertJsonContentType($response);
            self::assertJson($response->body());
        }
    }

    #[Test]
    public function availableQuantityMatchesTheStockActuallyRecorded(): void
    {
        $this->setStock($this->productId, $this->warehouseId, 17);
        $this->signInAs(Role::Sales);

        $body = $this->decode($this->stockApi->available(
            Request::fromGlobals()->withRouteParams([
                'productId'   => (string) $this->productId,
                'warehouseId' => (string) $this->warehouseId,
            ]),
        ));

        self::assertSame($this->productId, $body['productId']);
        self::assertSame($this->warehouseId, $body['warehouseId']);
        self::assertSame(17, $body['availableQuantity']);
    }

    // ------------------------------------------ Scoping role (T123)

    #[Test]
    public function salesIsRefusedFromTheLowStockEndpoint(): void
    {
        $this->signInAs(Role::Sales);

        $route = $this->router->match('GET', self::LOW_STOCK_PATH);

        $this->expectException(ForbiddenException::class);

        $this->authorization->authorizeRoute($route['roles']);
    }

    #[Test]
    public function adminAndWarehouseStaffReachTheLowStockEndpoint(): void
    {
        $route = $this->router->match('GET', self::LOW_STOCK_PATH);

        foreach ([Role::Admin, Role::WarehouseStaff] as $role) {
            $this->signInAs($role);

            $this->authorization->authorizeRoute($route['roles']);

            $response = $this->dashboardApi->lowStock(Request::fromGlobals());

            self::assertSame(200, $response->statusCode());
            self::assertArrayHasKey('count', $this->decode($response));
        }
    }

    #[Test]
    public function theLowStockRouteTableNeverEvenListsSales(): void
    {
        // Diperiksa pada datanya, bukan pada perilakunya: salah konfigurasi
        // route tetap terlihat walaupun guard-nya benar.
        $route = $this->router->match('GET', self::LOW_STOCK_PATH);

        self::assertNotNull($route['roles'], 'Endpoint low-stock tidak boleh menjadi route publik.');
        self::assertNotContains(Role::Sales, $route['roles']);
        self::assertContains(Role::Admin, $route['roles']);
        self::assertContains(Role::WarehouseStaff, $route['roles']);
    }

    #[Test]
    public function theAvailabilityRoutesAreOpenToEverySignedInRole(): void
    {
        foreach ([self::AVAILABILITY_PATH, '/api/products/1/warehouses/1/available'] as $path) {
            $route = $this->router->match('GET', $path);

            self::assertNotNull($route['roles'], $path . ' tidak boleh menjadi route publik.');

            foreach ([Role::Admin, Role::Sales, Role::WarehouseStaff] as $role) {
                self::assertContains($role, $route['roles'], $path . ' harus terbuka untuk ' . $role->value);
            }
        }
    }

    #[Test]
    public function everyApiRouteIsHandledByAnApiController(): void
    {
        $paths = [
            self::AVAILABILITY_PATH,
            '/api/products/1/warehouses/1/available',
            self::LOW_STOCK_PATH,
        ];

        foreach ($paths as $path) {
            $route = $this->router->match('GET', $path);

            self::assertStringStartsWith(
                'Api\\',
                $route['controller'],
                $path . ' harus ditangani controller di namespace Api.',
            );
        }
    }

    // ------------------------------------------------------- Helpers

    /**
     * Session diisi langsung, bukan lewat Session::login(): login()
     * me-regenerate session id, dan itu tidak dapat dijalankan di CLI. Yang
     * diuji di sini adalah guard-nya, bukan alur login.
     */
    private function signInAs(Role $role): void
    {
        $_SESSION = [
            'auth_user_id'   => $this->userIdFor($role),
            'auth_user_role' => $role->value,
            'auth_user_name' => 'API Test User',
        ];
    }

    private function userIdFor(Role $role): int
    {
        return match ($role) {
            Role::Admin => (int) $this->admin->id,
            Role::Sales => (int) $this->salesCreator->id,
            Role::WarehouseStaff => (int) $this->warehouseStaff->id,
        };
    }

    private function requestFor(string $path): Request
    {
        $_SERVER['REQUEST_URI'] = $path;

        return Request::fromGlobals();
    }

    private function assertJsonContentType(Response $response): void
    {
        self::assertSame('application/json; charset=UTF-8', $response->header('Content-Type'));
    }

    /** @return array<string, mixed> */
    private function decode(Response $response): array
    {
        /** @var array<string, mixed> $decoded */
        $decoded = json_decode($response->body(), true, 512, JSON_THROW_ON_ERROR);

        return $decoded;
    }
}
