<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Entity\Enum\Role;
use App\Support\Authorization;
use App\Support\Exception\ForbiddenException;
use App\Support\Router;
use App\Support\Session;
use PHPUnit\Framework\Attributes\Test;

/**
 * FR-006: administrasi user hanya untuk Admin, ditegakkan DI SERVER.
 *
 * Test ini membaca route table yang sesungguhnya dari config/routes.php lalu
 * memanggil guard secara langsung untuk setiap route /users*, meniru pemanggil
 * yang melewati UI sepenuhnya. Menyembunyikan menu bukan kontrol akses
 * (security standard §2).
 */
final class UserAccessTest extends IntegrationTestCase
{
    private Router $router;
    private Session $session;
    private Authorization $authorization;

    /** @var list<array{method: string, path: string}> */
    private array $userRoutes = [
        ['method' => 'GET', 'path' => '/users'],
        ['method' => 'GET', 'path' => '/users/create'],
        ['method' => 'POST', 'path' => '/users'],
        ['method' => 'GET', 'path' => '/users/7/edit'],
        ['method' => 'POST', 'path' => '/users/7'],
        ['method' => 'POST', 'path' => '/users/7/toggle-active'],
        ['method' => 'POST', 'path' => '/users/7/password'],
    ];

    protected function setUp(): void
    {
        parent::setUp();

        $this->router = new Router();
        /** @var callable(Router): void $register */
        $register = require dirname(__DIR__, 2) . '/config/routes.php';
        $register($this->router);

        $this->session = new Session('IOMS_TEST_SESSION', false);
        $this->authorization = new Authorization($this->session);

        $_SESSION = [];
    }

    protected function tearDown(): void
    {
        $_SESSION = [];
        parent::tearDown();
    }

    #[Test]
    public function everyUserAdministrationRouteIsRegistered(): void
    {
        foreach ($this->userRoutes as $route) {
            $matched = $this->router->match($route['method'], $route['path']);

            self::assertSame(
                'UserController',
                $matched['controller'],
                $route['method'] . ' ' . $route['path'] . ' harus ditangani UserController',
            );
        }
    }

    #[Test]
    public function salesIsRefusedFromEveryUserAdministrationRoute(): void
    {
        $this->signInAs(Role::Sales);
        $this->assertEveryUserRouteIsRefused();
    }

    #[Test]
    public function warehouseStaffIsRefusedFromEveryUserAdministrationRoute(): void
    {
        $this->signInAs(Role::WarehouseStaff);
        $this->assertEveryUserRouteIsRefused();
    }

    #[Test]
    public function adminReachesEveryUserAdministrationRoute(): void
    {
        $this->signInAs(Role::Admin);

        foreach ($this->userRoutes as $route) {
            $matched = $this->router->match($route['method'], $route['path']);

            $this->authorization->authorizeRoute($matched['roles']);
        }

        self::assertSame(Role::Admin, $this->authorization->currentRole());
    }

    /**
     * Route table TIDAK BOLEH mencantumkan Sales atau WarehouseStaff pada
     * route /users* mana pun. Diperiksa pada datanya, bukan pada perilakunya,
     * sehingga kesalahan konfigurasi terlihat walaupun guard-nya benar.
     */
    #[Test]
    public function noUserAdministrationRouteEvenListsANonAdminRole(): void
    {
        foreach ($this->userRoutes as $route) {
            $matched = $this->router->match($route['method'], $route['path']);

            self::assertNotNull(
                $matched['roles'],
                $route['path'] . ' tidak boleh menjadi route publik',
            );
            self::assertSame(
                [Role::Admin],
                $matched['roles'],
                $route['method'] . ' ' . $route['path'] . ' hanya boleh mengizinkan Admin',
            );
        }
    }

    private function assertEveryUserRouteIsRefused(): void
    {
        foreach ($this->userRoutes as $route) {
            $matched = $this->router->match($route['method'], $route['path']);
            $refused = false;

            try {
                $this->authorization->authorizeRoute($matched['roles']);
            } catch (ForbiddenException) {
                $refused = true;
            }

            self::assertTrue(
                $refused,
                $route['method'] . ' ' . $route['path'] . ' seharusnya ditolak dengan 403',
            );
        }
    }

    private function signInAs(Role $role): void
    {
        $_SESSION['auth_user_id'] = 42;
        $_SESSION['auth_user_role'] = $role->value;
        $_SESSION['auth_user_name'] = 'Test User';
    }
}
