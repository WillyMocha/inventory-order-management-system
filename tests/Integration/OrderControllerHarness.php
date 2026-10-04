<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Entity\User;
use App\Repository\Mysql\MysqlCategoryRepository;
use App\Repository\Mysql\MysqlCustomerRepository;
use App\Repository\Mysql\MysqlProductRepository;
use App\Repository\Mysql\MysqlProductStockRepository;
use App\Repository\Mysql\MysqlPurchaseOrderRepository;
use App\Repository\Mysql\MysqlSalesOrderRepository;
use App\Repository\Mysql\MysqlStockLedgerRepository;
use App\Repository\Mysql\MysqlSupplierRepository;
use App\Repository\Mysql\MysqlUserRepository;
use App\Repository\Mysql\MysqlWarehouseRepository;
use App\Service\MasterDataService;
use App\Service\PartyService;
use App\Service\ProductService;
use App\Service\PurchaseOrderService;
use App\Service\SalesOrderApprovalService;
use App\Service\SalesOrderService;
use App\Service\StockService;
use App\Service\UserService;
use App\Support\Csrf;
use App\Support\Request;
use App\Support\Response;
use App\Support\Session;
use App\Support\SystemClock;
use App\Support\View;

/**
 * Merakit controller order terhadap MySQL sungguhan, seperti config/container.php.
 *
 * config/container.php tidak dipakai langsung karena ia membuka connection
 * sendiri; di sini seluruh service memakai $this->database milik test, sehingga
 * fixture dan rollback IntegrationTestCase tetap berlaku. Transaction milik
 * service menjadi savepoint di dalam transaction test (Database::transaction()).
 *
 * Yang dibuktikan test pemakai trait ini adalah lapisan HTTP: status code,
 * redirect, flash, dan halaman yang dirender - aturan bisnisnya sudah diuji
 * di unit test service.
 *
 * @phpstan-require-extends IntegrationTestCase
 */
trait OrderControllerHarness
{
    protected View $view;
    protected Session $session;
    protected Csrf $csrf;
    protected UserService $userService;
    protected ProductService $productService;
    protected PartyService $partyService;
    protected MasterDataService $masterDataService;
    protected SalesOrderService $salesOrderService;
    protected SalesOrderApprovalService $approvalService;
    protected PurchaseOrderService $purchaseOrderService;
    protected StockService $stockService;

    protected function wireOrderServices(): void
    {
        $this->session = new Session('IOMS_TEST_SESSION', false);
        $this->csrf = new Csrf($this->session);

        $this->view = new View(dirname(__DIR__, 2) . '/views');
        $this->view->share('view', $this->view);
        $this->view->share('csrf', $this->csrf);
        $this->view->share('session', $this->session);

        $clock = new SystemClock();
        $users = new MysqlUserRepository($this->database);
        $categories = new MysqlCategoryRepository($this->database);
        $warehouses = new MysqlWarehouseRepository($this->database);
        $suppliers = new MysqlSupplierRepository($this->database);
        $customers = new MysqlCustomerRepository($this->database);
        $products = new MysqlProductRepository($this->database);
        $stocks = new MysqlProductStockRepository($this->database);
        $salesOrders = new MysqlSalesOrderRepository($this->database);
        $purchaseOrders = new MysqlPurchaseOrderRepository($this->database);

        $this->userService = new UserService($users);
        $this->productService = new ProductService($products, $stocks, $categories);
        $this->partyService = new PartyService($suppliers, $customers);
        $this->masterDataService = new MasterDataService($categories, $warehouses);
        $this->salesOrderService = new SalesOrderService(
            $salesOrders,
            $customers,
            $warehouses,
            $products,
            $clock,
            $this->database,
        );
        $this->approvalService = new SalesOrderApprovalService($this->salesOrderService, $salesOrders, $clock);
        $this->purchaseOrderService = new PurchaseOrderService(
            $purchaseOrders,
            $suppliers,
            $warehouses,
            $products,
            $clock,
            $this->database,
        );
        $this->stockService = new StockService(
            $salesOrders,
            $purchaseOrders,
            $stocks,
            new MysqlStockLedgerRepository($this->database),
            $products,
            $warehouses,
            $this->database,
        );

        $this->resetGlobals();
    }

    protected function resetGlobals(): void
    {
        $_SESSION = [];
        $_GET = [];
        $_POST = [];
        unset($_SERVER['REQUEST_METHOD'], $_SERVER['REQUEST_URI']);
    }

    protected function signInAs(User $user): void
    {
        $_SESSION['auth_user_id'] = $user->id;
        $_SESSION['auth_user_role'] = $user->role->value;
        $_SESSION['auth_user_name'] = $user->name;
    }

    /**
     * Request seperti yang dibangun front controller: superglobal lalu
     * parameter route.
     *
     * @param array<string, mixed> $body
     * @param array<string, mixed> $query
     */
    protected function request(?int $id = null, array $body = [], array $query = []): Request
    {
        $_SERVER['REQUEST_METHOD'] = $body === [] ? 'GET' : 'POST';
        $_POST = $body;
        $_GET = $query;

        return Request::fromGlobals()->withRouteParams($id === null ? [] : ['id' => (string) $id]);
    }

    protected function assertRedirectWithFlash(
        Response $response,
        string $location,
        string $type,
        string $message,
    ): void {
        self::assertSame(302, $response->statusCode());
        self::assertSame($location, $response->header('Location'));
        self::assertSame(['type' => $type, 'message' => $message], $this->session->pullFlash());
    }
}
