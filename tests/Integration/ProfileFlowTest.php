<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Controller\ProfileController;
use App\Entity\Enum\Role;
use App\Entity\User;
use App\Repository\Mysql\MysqlLoginAttemptRepository;
use App\Repository\Mysql\MysqlUserRepository;
use App\Service\AuthService;
use App\Service\UserService;
use App\Support\Authorization;
use App\Support\Csrf;
use App\Support\Exception\RateLimitException;
use App\Support\Exception\UnauthenticatedException;
use App\Support\Exception\ValidationException;
use App\Support\Request;
use App\Support\Router;
use App\Support\Session;
use App\Support\View;
use PHPUnit\Framework\Attributes\Test;

/**
 * Halaman profil sendiri terhadap MySQL sungguhan (002-user-profile-page).
 *
 * Yang dibuktikan di sini dan tidak dapat dibuktikan unit test: route table
 * yang sesungguhnya, guard tanpa session, dan bahwa controller mengambil
 * identitas HANYA dari session - parameter `id` pada request diabaikan
 * (FR-003). Pola mengikuti ApprovalAuthorizationTest.
 */
final class ProfileFlowTest extends IntegrationTestCase
{
    private const string PASSWORD = 'Password123!';
    private const string NEW_PASSWORD = 'NewSecret123!';
    private const string IP = '203.0.113.77';
    private const string WRONG_PASSWORD = 'WrongPassword1!';
    private const string SALES_EMAIL = 'profile-sales@test.local';
    private const string ADMIN_EMAIL = 'profile-admin@test.local';

    private Router $router;
    private Session $session;
    private Authorization $authorization;
    private ProfileController $controller;
    private MysqlUserRepository $users;
    private AuthService $auth;

    private User $admin;
    private User $sales;

    protected function setUp(): void
    {
        parent::setUp();

        $this->router = new Router();
        /** @var callable(Router): void $register */
        $register = require dirname(__DIR__, 2) . '/config/routes.php';
        $register($this->router);

        $this->session = new Session('IOMS_TEST_SESSION', false);
        $this->authorization = new Authorization($this->session);

        $this->users = new MysqlUserRepository($this->database);
        $this->auth = new AuthService($this->users, new MysqlLoginAttemptRepository($this->database), 5, 15);
        $csrf = new Csrf($this->session);

        // View dirakit seperti config/container.php agar layout dapat dirender.
        $view = new View(dirname(__DIR__, 2) . '/views');
        $view->share('view', $view);
        $view->share('csrf', $csrf);
        $view->share('session', $this->session);

        $this->controller = new ProfileController(
            $view,
            new UserService($this->users),
            $this->auth,
            $this->session,
            $csrf,
        );

        $_SESSION = [];
        $_GET = [];
        $_POST = [];

        $this->admin = $this->persistUser('Profile Admin', self::ADMIN_EMAIL, Role::Admin);
        $this->sales = $this->persistUser('Profile Sales', self::SALES_EMAIL, Role::Sales);
    }

    protected function tearDown(): void
    {
        $_SESSION = [];
        $_GET = [];
        $_POST = [];
        unset($_SERVER['REMOTE_ADDR']);
        parent::tearDown();
    }

    // ------------------------------------------------------- route table

    #[Test]
    public function theProfileRouteIsOpenToEveryRoleAndOnlyToSignedInUsers(): void
    {
        $matched = $this->router->match('GET', '/profile');

        self::assertSame('ProfileController', $matched['controller']);
        self::assertSame('show', $matched['action']);
        self::assertSame([Role::Admin, Role::Sales, Role::WarehouseStaff], $matched['roles']);
    }

    #[Test]
    public function theProfileRouteRequiresASession(): void
    {
        $matched = $this->router->match('GET', '/profile');

        $this->expectException(UnauthenticatedException::class);
        $this->authorization->authorizeRoute($matched['roles']);
    }

    // ------------------------------------------------- ownership (FR-003)

    #[Test]
    public function theProfileShowsTheSignedInUserAndIgnoresAnIdInTheRequest(): void
    {
        $this->signInAs($this->sales);
        $_GET['id'] = (string) $this->admin->id;
        $_GET['user_id'] = (string) $this->admin->id;

        $response = $this->controller->show(Request::fromGlobals());

        self::assertSame(200, $response->statusCode());
        self::assertStringContainsString(self::SALES_EMAIL, $response->body());
        self::assertStringNotContainsString(self::ADMIN_EMAIL, $response->body());
    }

    // ------------------------------------- ganti password (FR-005, FR-006)

    #[Test]
    public function thePasswordRouteIsOpenToEveryRole(): void
    {
        $matched = $this->router->match('POST', '/profile/password');

        self::assertSame('ProfileController', $matched['controller']);
        self::assertSame('changePassword', $matched['action']);
        self::assertSame([Role::Admin, Role::Sales, Role::WarehouseStaff], $matched['roles']);
    }

    /** SC-004: password lama berhenti berlaku, password baru langsung bisa dipakai. */
    #[Test]
    public function afterAChangeOnlyTheNewPasswordSignsIn(): void
    {
        $this->changeSalesPassword(self::PASSWORD);

        self::assertNull($this->auth->attempt(self::SALES_EMAIL, self::PASSWORD, self::IP));
        self::assertNotNull($this->auth->attempt(self::SALES_EMAIL, self::NEW_PASSWORD, self::IP));
    }

    /** SC-005: perubahan yang ditolak tidak mengubah apa pun. */
    #[Test]
    public function aRejectedChangeLeavesTheStoredHashIdentical(): void
    {
        $before = $this->storedHash((int) $this->sales->id);

        $this->expectRejectedChange(self::WRONG_PASSWORD);

        self::assertSame($before, $this->storedHash((int) $this->sales->id));
    }

    /**
     * SC-002: id user lain yang diselipkan ke body request diabaikan. Hanya
     * password milik user yang sedang login yang berubah.
     */
    #[Test]
    public function anIdSmuggledIntoTheBodyCannotChangeAnotherUsersPassword(): void
    {
        $adminHashBefore = $this->storedHash((int) $this->admin->id);

        $this->signInAs($this->sales);
        $_POST = [
            'id' => (string) $this->admin->id,
            'user_id' => (string) $this->admin->id,
            'current_password' => self::PASSWORD,
            'new_password' => self::NEW_PASSWORD,
            'new_password_confirmation' => self::NEW_PASSWORD,
        ];

        $response = $this->controller->changePassword(Request::fromGlobals());

        self::assertSame(302, $response->statusCode());
        self::assertSame('/profile', $response->header('Location'));
        self::assertSame($adminHashBefore, $this->storedHash((int) $this->admin->id));
        self::assertTrue(password_verify(self::NEW_PASSWORD, $this->storedHash((int) $this->sales->id)));
    }

    /** Kegagalan validasi dirender ulang dengan 422 tanpa mengembalikan nilai password (NFR-001). */
    #[Test]
    public function aValidationFailureIsRenderedWithoutEchoingAnyPassword(): void
    {
        $this->signInAs($this->sales);
        $_POST = [
            'current_password' => self::WRONG_PASSWORD,
            'new_password' => self::NEW_PASSWORD,
            'new_password_confirmation' => self::NEW_PASSWORD,
        ];

        $response = $this->controller->changePassword(Request::fromGlobals());

        self::assertSame(422, $response->statusCode());
        self::assertStringContainsString('Your current password is incorrect.', $response->body());
        self::assertStringNotContainsString(self::WRONG_PASSWORD, $response->body());
        self::assertStringNotContainsString(self::NEW_PASSWORD, $response->body());
    }

    // -------------------------- batas percobaan bersama login (FR-008)

    #[Test]
    public function wrongGuessesOnTheProfileLockSignInToo(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->expectRejectedChange(self::WRONG_PASSWORD);
        }

        $this->expectException(RateLimitException::class);
        $this->auth->attempt(self::SALES_EMAIL, self::PASSWORD, self::IP);
    }

    #[Test]
    public function wrongGuessesAtSignInLockTheProfileToo(): void
    {
        for ($i = 0; $i < 5; $i++) {
            self::assertNull($this->auth->attempt(self::SALES_EMAIL, self::WRONG_PASSWORD, self::IP));
        }

        $this->expectException(RateLimitException::class);
        $this->changeSalesPassword(self::PASSWORD);
    }

    #[Test]
    public function aLockedOutProfileIsRenderedWith429AndTheFormStaysVisible(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->auth->attempt(self::SALES_EMAIL, self::WRONG_PASSWORD, self::IP);
        }

        $this->signInAs($this->sales);
        $_SERVER['REMOTE_ADDR'] = self::IP;
        $_POST = [
            'current_password' => self::PASSWORD,
            'new_password' => self::NEW_PASSWORD,
            'new_password_confirmation' => self::NEW_PASSWORD,
        ];

        $response = $this->controller->changePassword(Request::fromGlobals());

        self::assertSame(429, $response->statusCode());
        self::assertStringContainsString('Too many incorrect attempts. Try again later.', $response->body());
        self::assertStringContainsString('name="current_password"', $response->body());
        self::assertFalse(password_verify(self::NEW_PASSWORD, $this->storedHash((int) $this->sales->id)));
    }

    // ---------------------------------------------------------- helper

    private function changeSalesPassword(string $current): void
    {
        $this->auth->changeOwnPassword($this->sales, $current, self::NEW_PASSWORD, self::NEW_PASSWORD, self::IP);
    }

    private function expectRejectedChange(string $current): void
    {
        try {
            $this->changeSalesPassword($current);
        } catch (ValidationException) {
            return;
        }

        self::fail('Password saat ini yang salah seharusnya ditolak');
    }

    private function storedHash(int $id): string
    {
        $user = $this->users->findById($id);
        self::assertNotNull($user);

        return $user->passwordHash;
    }

    private function signInAs(User $user): void
    {
        $_SESSION['auth_user_id'] = $user->id;
        $_SESSION['auth_user_role'] = $user->role->value;
        $_SESSION['auth_user_name'] = $user->name;
    }

    private function persistUser(string $name, string $email, Role $role): User
    {
        $id = $this->users->save(new User(
            null,
            $name,
            $email,
            password_hash(self::PASSWORD, PASSWORD_DEFAULT),
            $role,
            true,
        ));

        $user = $this->users->findById($id);
        self::assertNotNull($user);

        return $user;
    }
}
