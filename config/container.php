<?php

/**
 * Wiring object graph secara manual.
 *
 * Bukan DI container: brief §9 menyatakan constructor injection manual sudah
 * memadai untuk menunjukkan Dependency Inversion, dan constitution Principle I
 * memperlakukan container sebagai kompleksitas yang tidak dibenarkan.
 *
 * Seluruh dependency dirakit di satu tempat ini, sehingga object graph dapat
 * dibaca sekaligus — persis yang dibutuhkan saat menelusuri class diagram ke
 * kode pada technical defense.
 */

declare(strict_types=1);

use App\Controller\Api\DashboardApiController;
use App\Controller\Api\StockApiController;
use App\Controller\AuthController;
use App\Controller\DashboardController;
use App\Controller\CategoryController;
use App\Controller\CustomerController;
use App\Controller\HealthController;
use App\Controller\ProductController;
use App\Controller\ReportController;
use App\Controller\PurchaseOrderController;
use App\Controller\SalesOrderController;
use App\Controller\SupplierController;
use App\Controller\UserController;
use App\Controller\WarehouseController;
use App\Repository\Mysql\MysqlCategoryRepository;
use App\Repository\Mysql\MysqlCustomerRepository;
use App\Repository\Mysql\MysqlLoginAttemptRepository;
use App\Repository\Mysql\MysqlProductRepository;
use App\Repository\Mysql\MysqlProductStockRepository;
use App\Repository\Mysql\MysqlPurchaseOrderRepository;
use App\Repository\Mysql\MysqlSalesOrderRepository;
use App\Repository\Mysql\MysqlStockLedgerRepository;
use App\Repository\Mysql\MysqlSupplierRepository;
use App\Repository\Mysql\MysqlUserRepository;
use App\Repository\Mysql\MysqlWarehouseRepository;
use App\Service\AuthService;
use App\Service\DashboardService;
use App\Service\MasterDataService;
use App\Service\PartyService;
use App\Service\ProductImageService;
use App\Service\ProductService;
use App\Service\PurchaseOrderService;
use App\Service\ReportService;
use App\Service\SalesOrderService;
use App\Service\StockService;
use App\Service\UserService;
use App\Support\Authorization;
use App\Support\Csrf;
use App\Support\Database;
use App\Support\Router;
use App\Support\Session;
use App\Support\SystemClock;
use App\Support\View;

/**
 * @param array<string, mixed> $config
 * @return array<string, mixed>
 */
return static function (array $config): array {
    // --- Infrastruktur -------------------------------------------------
    /** @var array{host: string, port: int, database: string, username: string, password: string} $dbConfig */
    $dbConfig = $config['database'];
    $database = new Database($dbConfig);

    /** @var array{name: string, secure: bool} $sessionConfig */
    $sessionConfig = $config['session'];
    $session = new Session($sessionConfig['name'], $sessionConfig['secure']);

    $csrf = new Csrf($session);
    $authorization = new Authorization($session);
    $clock = new SystemClock();
    $view = new View((string) $config['view_path']);

    // --- Repository ----------------------------------------------------
    // Setiap Service menerima interface-nya, bukan kelas konkret di bawah ini
    // (ARCH-01). Unit test menukar objek-objek ini dengan InMemory*Repository.
    $userRepository = new MysqlUserRepository($database);
    $categoryRepository = new MysqlCategoryRepository($database);
    $warehouseRepository = new MysqlWarehouseRepository($database);
    $supplierRepository = new MysqlSupplierRepository($database);
    $customerRepository = new MysqlCustomerRepository($database);
    $productRepository = new MysqlProductRepository($database);
    $productStockRepository = new MysqlProductStockRepository($database);
    $stockLedgerRepository = new MysqlStockLedgerRepository($database);
    $purchaseOrderRepository = new MysqlPurchaseOrderRepository($database);
    $salesOrderRepository = new MysqlSalesOrderRepository($database);
    $loginAttemptRepository = new MysqlLoginAttemptRepository($database);

    // --- Service -------------------------------------------------------
    // Setiap Service menerima INTERFACE repository lewat constructor, bukan
    // kelas konkret - inilah Dependency Inversion pada boundary repository
    // (ARCH-01). Unit test menukar argumen ini dengan InMemory*Repository.
    /** @var array{login_max_attempts: int, login_window_minutes: int} $securityConfig */
    $securityConfig = $config['security'];

    $authService = new AuthService(
        $userRepository,
        $loginAttemptRepository,
        $securityConfig['login_max_attempts'],
        $securityConfig['login_window_minutes'],
    );

    $userService = new UserService($userRepository);

    $masterDataService = new MasterDataService($categoryRepository, $warehouseRepository);

    $partyService = new PartyService($supplierRepository, $customerRepository);

    $productService = new ProductService($productRepository, $productStockRepository, $categoryRepository);

    $purchaseOrderService = new PurchaseOrderService(
        $purchaseOrderRepository,
        $supplierRepository,
        $warehouseRepository,
        $productRepository,
        $clock,
    );

    $salesOrderService = new SalesOrderService(
        $salesOrderRepository,
        $customerRepository,
        $warehouseRepository,
        $productRepository,
        $clock,
    );

    // StockService menerima Database sebagai TransactionRunner — satu-satunya
    // jalan stock boleh berubah, dan selalu di dalam transaction (ARCH-02).
    $stockService = new StockService(
        $salesOrderRepository,
        $purchaseOrderRepository,
        $productStockRepository,
        $stockLedgerRepository,
        $productRepository,
        $database,
    );

    // Dashboard dan Report memanggil METHOD QUERY YANG SAMA pada repository
    // yang sama — itulah yang membuat angka di layar dan isi file export tidak
    // punya jalur untuk berbeda (research R-008, FR-027).
    $dashboardService = new DashboardService(
        $productRepository,
        $salesOrderRepository,
        $purchaseOrderRepository,
    );

    $reportService = new ReportService(
        $stockLedgerRepository,
        $salesOrderRepository,
        $purchaseOrderRepository,
        $clock,
    );

    /** @var array{path: string, max_bytes: int, allowed_mimes: list<string>} $uploadConfig */
    $uploadConfig = $config['upload'];
    $productImageService = new ProductImageService(
        $uploadConfig['path'],
        $uploadConfig['max_bytes'],
        $uploadConfig['allowed_mimes'],
    );

    // --- Router --------------------------------------------------------
    $router = new Router();
    /** @var callable(Router): void $registerRoutes */
    $registerRoutes = require __DIR__ . '/routes.php';
    $registerRoutes($router);

    // Nilai yang tersedia di seluruh template. View membagikan dirinya
    // sendiri agar layout dapat merender partial (nav, flash, pagination).
    $view->share('view', $view);
    $view->share('csrf', $csrf);
    $view->share('session', $session);

    // --- Controller ------------------------------------------------------
    // Factory, bukan instance: hanya controller yang benar-benar dipakai
    // request ini yang dibangun. Kunci map harus sama dengan nama controller
    // pada config/routes.php.
    //
    // Controller untuk phase berikutnya belum terdaftar di sini; route-nya
    // sudah ada, dan front controller membalas 404 aman sambil mencatat ke
    // server log sampai controller-nya dibuat.
    $controllers = [
        'AuthController' => static fn (): AuthController => new AuthController(
            $view,
            $authService,
            $session,
            $csrf,
        ),
        'DashboardController' => static fn (): DashboardController => new DashboardController(
            $view,
            $dashboardService,
            $session,
        ),
        'ReportController' => static fn (): ReportController => new ReportController(
            $view,
            $reportService,
            $session,
        ),
        'HealthController' => static fn (): HealthController => new HealthController($view),
        // Controller API tidak menerima Session: authentication dan
        // authorization sudah diselesaikan route table beserta guard sebelum
        // request sampai ke controller (contracts/http-routes.md).
        'Api\StockApiController' => static fn (): StockApiController => new StockApiController(
            $productService,
            $masterDataService,
        ),
        'Api\DashboardApiController' => static fn (): DashboardApiController => new DashboardApiController(
            $productService,
        ),
        'UserController' => static fn (): UserController => new UserController(
            $view,
            $userService,
            $authService,
            $session,
            $csrf,
        ),
        'ProductController' => static fn (): ProductController => new ProductController(
            $view,
            $productService,
            $productImageService,
            $masterDataService,
            $session,
            $csrf,
        ),
        'PurchaseOrderController' => static fn (): PurchaseOrderController => new PurchaseOrderController(
            $view,
            $purchaseOrderService,
            $stockService,
            $productService,
            $partyService,
            $masterDataService,
            $userService,
            $session,
            $csrf,
        ),
        'SalesOrderController' => static fn (): SalesOrderController => new SalesOrderController(
            $view,
            $salesOrderService,
            $stockService,
            $productService,
            $partyService,
            $masterDataService,
            $userService,
            $session,
            $csrf,
        ),
        'CategoryController' => static fn (): CategoryController => new CategoryController(
            $view,
            $masterDataService,
            $session,
            $csrf,
        ),
        'WarehouseController' => static fn (): WarehouseController => new WarehouseController(
            $view,
            $masterDataService,
            $session,
            $csrf,
        ),
        'SupplierController' => static fn (): SupplierController => new SupplierController(
            $view,
            $partyService,
            $session,
            $csrf,
        ),
        'CustomerController' => static fn (): CustomerController => new CustomerController(
            $view,
            $partyService,
            $session,
            $csrf,
        ),
    ];

    return [
        'config'                  => $config,
        'controllers'             => $controllers,
        'authService'             => $authService,
        'userService'             => $userService,
        'productService'          => $productService,
        'productImageService'     => $productImageService,
        'masterDataService'       => $masterDataService,
        'partyService'            => $partyService,
        'purchaseOrderService'    => $purchaseOrderService,
        'salesOrderService'       => $salesOrderService,
        'stockService'            => $stockService,
        'dashboardService'        => $dashboardService,
        'reportService'           => $reportService,
        'database'                => $database,
        'session'                 => $session,
        'csrf'                    => $csrf,
        'authorization'           => $authorization,
        'clock'                   => $clock,
        'view'                    => $view,
        'router'                  => $router,

        'userRepository'          => $userRepository,
        'categoryRepository'      => $categoryRepository,
        'warehouseRepository'     => $warehouseRepository,
        'supplierRepository'      => $supplierRepository,
        'customerRepository'      => $customerRepository,
        'productRepository'       => $productRepository,
        'productStockRepository'  => $productStockRepository,
        'stockLedgerRepository'   => $stockLedgerRepository,
        'purchaseOrderRepository' => $purchaseOrderRepository,
        'salesOrderRepository'    => $salesOrderRepository,
        'loginAttemptRepository'  => $loginAttemptRepository,

        // Service dan Controller ditambahkan pada Phase 3 dan seterusnya,
        // masing-masing dirakit dari repository di atas lewat constructor
        // injection.
    ];
};
