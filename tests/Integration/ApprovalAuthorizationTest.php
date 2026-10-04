<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Entity\Category;
use App\Entity\Customer;
use App\Entity\Enum\Role;
use App\Entity\Enum\SalesOrderStatus;
use App\Entity\Product;
use App\Entity\User;
use App\Entity\Warehouse;
use App\Repository\Mysql\MysqlCategoryRepository;
use App\Repository\Mysql\MysqlCustomerRepository;
use App\Repository\Mysql\MysqlProductRepository;
use App\Repository\Mysql\MysqlSalesOrderRepository;
use App\Repository\Mysql\MysqlUserRepository;
use App\Repository\Mysql\MysqlWarehouseRepository;
use App\Service\SalesOrderApprovalService;
use App\Service\SalesOrderService;
use App\Support\Authorization;
use App\Support\Exception\ForbiddenException;
use App\Support\Exception\NotFoundException;
use App\Support\Router;
use App\Support\Session;
use App\Support\SystemClock;
use PHPUnit\Framework\Attributes\Test;

/**
 * FR-018 dan NFR-003 terhadap MySQL nyata: segregation of duties ditegakkan
 * DI SERVER, dan resource di luar scope menghasilkan 404 bukan 403.
 *
 * Test ini memanggil Service dan guard secara LANGSUNG, meniru penyerang yang
 * melewati UI sepenuhnya — POST langsung ke endpoint approve tanpa pernah
 * melihat halaman. Menyembunyikan tombol tidak akan lulus test ini.
 */
final class ApprovalAuthorizationTest extends IntegrationTestCase
{
    private SalesOrderService $service;
    private SalesOrderApprovalService $approvals;
    private MysqlSalesOrderRepository $orders;

    private Router $router;
    private Session $session;
    private Authorization $authorization;

    private User $admin;
    private User $salesOwner;
    private User $salesOther;
    private User $warehouseStaff;

    private int $customerId;
    private int $warehouseId;
    private int $productId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->orders = new MysqlSalesOrderRepository($this->database);

        $this->service = new SalesOrderService(
            $this->orders,
            new MysqlCustomerRepository($this->database),
            new MysqlWarehouseRepository($this->database),
            new MysqlProductRepository($this->database),
            new SystemClock(),
            $this->database,
        );

        // Approve/reject ada di SalesOrderApprovalService sejak tech-debt TD-11;
        // visibilitas order tetap dari SalesOrderService yang sama.
        $this->approvals = new SalesOrderApprovalService($this->service, $this->orders, new SystemClock());

        $this->router = new Router();
        /** @var callable(Router): void $register */
        $register = require dirname(__DIR__, 2) . '/config/routes.php';
        $register($this->router);

        $this->session = new Session('IOMS_TEST_SESSION', false);
        $this->authorization = new Authorization($this->session);

        $_SESSION = [];

        $this->seedFixtures();
    }

    protected function tearDown(): void
    {
        $_SESSION = [];
        parent::tearDown();
    }

    // ------------------------------------------- route table enforcement

    #[Test]
    public function theApproveAndRejectRoutesAreAdminOnlyInTheRouteTable(): void
    {
        // Diperiksa pada DATA route table, sehingga kesalahan konfigurasi
        // terlihat walaupun guard-nya sendiri benar.
        foreach (['approve', 'reject'] as $action) {
            $matched = $this->router->match('POST', '/sales-orders/7/' . $action);

            self::assertSame('SalesOrderApprovalController', $matched['controller']);
            self::assertNotNull($matched['roles'], $action . ' tidak boleh menjadi route publik');
            self::assertSame(
                [Role::Admin],
                $matched['roles'],
                'POST /sales-orders/{id}/' . $action . ' hanya boleh mengizinkan Admin',
            );
        }
    }

    #[Test]
    public function theGuardRefusesSalesAndWarehouseStaffAtTheApproveRoute(): void
    {
        $matched = $this->router->match('POST', '/sales-orders/7/approve');

        foreach ([Role::Sales, Role::WarehouseStaff] as $role) {
            $this->signInAs($role, 99);

            $refused = false;

            try {
                $this->authorization->authorizeRoute($matched['roles']);
            } catch (ForbiddenException) {
                $refused = true;
            }

            self::assertTrue($refused, $role->value . ' harus ditolak di route approve');
        }
    }

    // ------------------------------------------------ service enforcement

    #[Test]
    public function theCreatingSalesUserIsRefusedEvenWhenCallingApproveDirectly(): void
    {
        // Inilah serangan yang sesungguhnya: POST langsung ke endpoint, tanpa
        // pernah melihat UI. Service yang harus menolak.
        $orderId = $this->pendingOrderCreatedBy($this->salesOwner);

        $refused = false;

        try {
            $this->approvals->approve($orderId, $this->salesOwner);
        } catch (ForbiddenException | NotFoundException) {
            $refused = true;
        }

        self::assertTrue($refused, 'Sales pembuat order tidak boleh dapat menyetujui order itu');

        // Dan statusnya tidak boleh bergerak sedikit pun.
        self::assertSame(SalesOrderStatus::PendingApproval, $this->statusOf($orderId));
        self::assertNull($this->orders->findById($orderId)?->approvedBy);
    }

    #[Test]
    public function noSalesUserCanApproveAnyOrderAtAll(): void
    {
        $ownOrder = $this->pendingOrderCreatedBy($this->salesOwner);
        $foreignOrder = $this->pendingOrderCreatedBy($this->salesOther);

        foreach ([$ownOrder, $foreignOrder] as $orderId) {
            $refused = false;

            try {
                $this->approvals->approve($orderId, $this->salesOwner);
            } catch (ForbiddenException | NotFoundException) {
                $refused = true;
            }

            self::assertTrue($refused, 'Sales tidak boleh menyetujui order id ' . $orderId);
            self::assertSame(SalesOrderStatus::PendingApproval, $this->statusOf($orderId));
        }
    }

    #[Test]
    public function warehouseStaffCannotApproveEitherEvenCallingTheServiceDirectly(): void
    {
        $orderId = $this->pendingOrderCreatedBy($this->salesOwner);

        $this->expectException(ForbiddenException::class);

        $this->approvals->approve($orderId, $this->warehouseStaff);
    }

    #[Test]
    public function anAdminApprovesAndTheApproverIsPersisted(): void
    {
        $orderId = $this->pendingOrderCreatedBy($this->salesOwner);

        $this->approvals->approve($orderId, $this->admin);

        $order = $this->orders->findById($orderId);

        self::assertNotNull($order);
        self::assertSame(SalesOrderStatus::Approved, $order->status);
        self::assertSame($this->admin->id, $order->approvedBy);

        // Yang paling penting dari baris ini: approver BUKAN pembuat order.
        self::assertNotSame($order->createdBy, $order->approvedBy);

        // approved_at benar-benar tertulis di kolomnya.
        self::assertNotNull($this->approvedAtColumn($orderId));
    }

    #[Test]
    public function anAdminStillCannotApproveAnOrderTheyCreatedThemselves(): void
    {
        $orderId = $this->pendingOrderCreatedBy($this->admin);

        $this->expectException(ForbiddenException::class);

        $this->approvals->approve($orderId, $this->admin);
    }

    #[Test]
    public function anOrderCreatedByAnAdminIsApprovedByAnotherAdmin(): void
    {
        // Karena itulah seed menyediakan dua akun Admin: tanpa Admin kedua,
        // order buatan Admin tidak pernah dapat disetujui siapa pun
        // (docs/planning/decisions.md, D-01).
        $secondAdmin = $this->persistUser(
            new MysqlUserRepository($this->database),
            'Second Admin',
            'approval-admin2@test',
            Role::Admin,
        );
        $orderId = $this->pendingOrderCreatedBy($this->admin);

        $this->approvals->approve($orderId, $secondAdmin);

        $order = $this->orders->findById($orderId);

        self::assertNotNull($order);
        self::assertSame(SalesOrderStatus::Approved, $order->status);
        self::assertSame($secondAdmin->id, $order->approvedBy);
    }

    // -------------------------------------------------- ownership scoping

    #[Test]
    public function aSalesUserRequestingAnotherSalesUsersOrderGetsNotFoundNotForbidden(): void
    {
        // 404, bukan 403 — 403 akan mengonfirmasi bahwa record-nya ada, dan
        // itu sudah membocorkan keberadaannya (NFR-003).
        $foreignOrder = $this->pendingOrderCreatedBy($this->salesOther);

        $this->expectException(NotFoundException::class);

        $this->service->requireVisibleOrder($foreignOrder, $this->salesOwner);
    }

    #[Test]
    public function theRefusalForAForeignOrderIsIndistinguishableFromAMissingOne(): void
    {
        $foreignOrder = $this->pendingOrderCreatedBy($this->salesOther);

        $foreignMessage = null;
        $missingMessage = null;

        try {
            $this->service->requireVisibleOrder($foreignOrder, $this->salesOwner);
        } catch (NotFoundException $e) {
            $foreignMessage = $e->getMessage();
        }

        try {
            $this->service->requireVisibleOrder(99999999, $this->salesOwner);
        } catch (NotFoundException $e) {
            $missingMessage = $e->getMessage();
        }

        // Pesan yang berbeda pun sudah cukup untuk membedakan "ada tapi bukan
        // milikmu" dari "tidak ada".
        self::assertNotNull($foreignMessage);
        self::assertSame($missingMessage, $foreignMessage);
    }

    #[Test]
    public function aSalesUserReachesTheirOwnOrder(): void
    {
        $ownOrder = $this->pendingOrderCreatedBy($this->salesOwner);

        self::assertSame(
            $ownOrder,
            $this->service->requireVisibleOrder($ownOrder, $this->salesOwner)->id,
        );
    }

    #[Test]
    public function adminAndWarehouseStaffReachAnyOrder(): void
    {
        $order = $this->pendingOrderCreatedBy($this->salesOwner);

        foreach ([$this->admin, $this->warehouseStaff] as $user) {
            self::assertSame(
                $order,
                $this->service->requireVisibleOrder($order, $user)->id,
                $user->role->value . ' harus dapat menjangkau order siapa pun',
            );
        }
    }

    #[Test]
    public function theOwnershipScopeIsAppliedInTheQueryNotAfterwards(): void
    {
        $this->pendingOrderCreatedBy($this->salesOwner);
        $this->pendingOrderCreatedBy($this->salesOther);

        $ownScope = $this->service->scopeFor($this->salesOwner);

        // count() memakai criteria yang sama dengan search(): kalau scoping
        // hanya memfilter hasil setelah query, count akan ikut salah dan
        // pagination-nya bocor.
        self::assertSame(1, $this->service->count($ownScope));
        self::assertCount(1, $this->service->search($ownScope, 10, 0));
        self::assertSame(2, $this->service->count($this->service->scopeFor($this->admin)));
    }

    // ----------------------------------------------------------- fixtures

    private function seedFixtures(): void
    {
        $users = new MysqlUserRepository($this->database);

        $this->admin = $this->persistUser($users, 'Approval Admin', 'approval-admin@test', Role::Admin);
        $this->salesOwner = $this->persistUser($users, 'Approval Sales A', 'approval-sales-a@test', Role::Sales);
        $this->salesOther = $this->persistUser($users, 'Approval Sales B', 'approval-sales-b@test', Role::Sales);
        $this->warehouseStaff = $this->persistUser(
            $users,
            'Approval Warehouse',
            'approval-wh@test',
            Role::WarehouseStaff,
        );

        $customers = new MysqlCustomerRepository($this->database);
        $this->customerId = $customers->save(new Customer(null, 'Approval Customer', '0800', 'Jakarta', true));

        $warehouses = new MysqlWarehouseRepository($this->database);
        $this->warehouseId = $warehouses->save(new Warehouse(null, 'Approval Warehouse Site', 'Jakarta', true));

        $categories = new MysqlCategoryRepository($this->database);
        $categoryId = $categories->save(new Category(null, 'Approval Category', 'Fixture category'));

        $products = new MysqlProductRepository($this->database);
        $this->productId = $products->save(new Product(
            null,
            'APPROVAL-SKU-1',
            'Approval Product',
            $categoryId,
            'pcs',
            '1000.00',
            '1500.00',
            5,
            null,
            true,
        ));
    }

    private function persistUser(MysqlUserRepository $users, string $name, string $email, Role $role): User
    {
        $id = $users->save(new User(
            null,
            $name,
            $email,
            password_hash('Password123!', PASSWORD_DEFAULT),
            $role,
            true,
        ));

        $user = $users->findById($id);

        self::assertNotNull($user);

        return $user;
    }

    private function pendingOrderCreatedBy(User $creator): int
    {
        $id = $this->service->create([
            'customer_id'  => (string) $this->customerId,
            'warehouse_id' => (string) $this->warehouseId,
            'order_date'   => '2026-09-11',
            'items'        => [['product_id' => (string) $this->productId, 'quantity' => '2']],
        ], $creator);

        $this->service->submit($id, $creator);

        return $id;
    }

    private function statusOf(int $id): ?SalesOrderStatus
    {
        return $this->orders->findById($id)?->status;
    }

    private function approvedAtColumn(int $id): ?string
    {
        $statement = $this->pdo->prepare('SELECT approved_at FROM sales_order WHERE id = :id');
        $statement->execute(['id' => $id]);

        $value = $statement->fetchColumn();

        return is_string($value) ? $value : null;
    }

    private function signInAs(Role $role, int $userId): void
    {
        $_SESSION['auth_user_id'] = $userId;
        $_SESSION['auth_user_role'] = $role->value;
        $_SESSION['auth_user_name'] = 'Test User';
    }
}
