<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Entity\Enum\Role;
use App\Entity\User;
use App\Repository\Mysql\MysqlLoginAttemptRepository;
use App\Repository\Mysql\MysqlUserRepository;
use App\Service\AuthService;
use App\Support\Authorization;
use App\Support\Exception\ForbiddenException;
use App\Support\Exception\RateLimitException;
use App\Support\Exception\UnauthenticatedException;
use App\Support\Session;
use PHPUnit\Framework\Attributes\Test;

/**
 * Integration test alur authentication terhadap MySQL sungguhan.
 *
 * Menutup dua hal yang tidak dapat dibuktikan unit test:
 *   1. AuthService bekerja terhadap MysqlUserRepository dan tabel
 *      login_attempt yang sesungguhnya (TEST-02).
 *   2. Authorization guard menolak akses tanpa session dan setelah logout
 *      (FR-003, FR-004).
 *
 * Catatan: session di sini dimanipulasi lewat $_SESSION secara langsung.
 * PHP CLI tidak memiliki session aktif, sehingga session_regenerate_id() tidak
 * dapat dipanggil. Regenerasi ID diverifikasi secara manual pada demo HTTP
 * (quickstart §1); yang diuji di sini adalah keputusan guard-nya.
 */
final class AuthFlowTest extends IntegrationTestCase
{
    private const string PASSWORD = 'Password123!';
    private const string IP = '203.0.113.55';

    private AuthService $service;
    private Authorization $authorization;
    private Session $session;
    private MysqlUserRepository $users;

    protected function setUp(): void
    {
        parent::setUp();

        $this->users = new MysqlUserRepository($this->database);
        $attempts = new MysqlLoginAttemptRepository($this->database);
        $this->service = new AuthService($this->users, $attempts, 5, 15);

        $this->session = new Session('IOMS_TEST_SESSION', false);
        $this->authorization = new Authorization($this->session);

        $_SESSION = [];

        $this->seedUsers();
    }

    protected function tearDown(): void
    {
        $_SESSION = [];
        parent::tearDown();
    }

    // ------------------------------------------------- AuthService + MySQL

    #[Test]
    public function activeUserSignsInAgainstRealDatabase(): void
    {
        $user = $this->service->attempt('active@test.local', self::PASSWORD, self::IP);

        self::assertNotNull($user);
        self::assertSame(Role::Admin, $user->role);
    }

    #[Test]
    public function deactivatedUserIsRefusedAgainstRealDatabase(): void
    {
        self::assertNull($this->service->attempt('disabled@test.local', self::PASSWORD, self::IP));
    }

    /**
     * Rate limit dihitung dari tabel login_attempt yang sesungguhnya,
     * termasuk klausa INTERVAL-nya.
     */
    #[Test]
    public function rateLimitIsEnforcedFromTheRealAttemptTable(): void
    {
        for ($i = 0; $i < 5; $i++) {
            self::assertNull($this->service->attempt('active@test.local', 'Wrong', self::IP));
        }

        $recorded = (int) $this->pdo->query(
            "SELECT COUNT(*) FROM login_attempt WHERE email = 'active@test.local' AND succeeded = 0"
        )?->fetchColumn();
        self::assertSame(5, $recorded);

        $this->expectException(RateLimitException::class);
        $this->service->attempt('active@test.local', self::PASSWORD, self::IP);
    }

    // ------------------------------------------------------ Guard (FR-003)

    #[Test]
    public function protectedRouteIsRefusedWithoutSession(): void
    {
        $this->expectException(UnauthenticatedException::class);
        $this->authorization->authorizeRoute([Role::Admin, Role::Sales, Role::WarehouseStaff]);
    }

    #[Test]
    public function publicRouteIsReachableWithoutSession(): void
    {
        $this->authorization->authorizeRoute(null);

        self::assertFalse($this->session->isAuthenticated());
    }

    /**
     * Deny by default: daftar role kosong berarti tidak ada yang boleh,
     * bahkan Admin yang sudah login.
     */
    #[Test]
    public function anEmptyRoleListIsReachableByNobody(): void
    {
        $this->signInAs(Role::Admin);

        $this->expectException(ForbiddenException::class);
        $this->authorization->authorizeRoute([]);
    }

    #[Test]
    public function aRoleOutsideTheAllowedListIsRefused(): void
    {
        $this->signInAs(Role::Sales);

        $this->expectException(ForbiddenException::class);
        $this->authorization->authorizeRoute([Role::Admin]);
    }

    #[Test]
    public function anAllowedRoleReachesTheRoute(): void
    {
        $this->signInAs(Role::Admin);

        $this->authorization->authorizeRoute([Role::Admin]);

        self::assertSame(Role::Admin, $this->authorization->currentRole());
    }

    // ----------------------------------------------------- Logout (FR-004)

    #[Test]
    public function protectedRouteIsRefusedAgainAfterSignOut(): void
    {
        $this->signInAs(Role::Admin);
        $this->authorization->authorizeRoute([Role::Admin]);

        // Logout tanpa memanggil session_destroy(), yang tidak tersedia di CLI.
        $_SESSION = [];

        $this->expectException(UnauthenticatedException::class);
        $this->authorization->authorizeRoute([Role::Admin]);
    }

    // ---------------------------------------------------------- Ownership

    /**
     * Sales yang membuka record milik user lain menerima 404, bukan 403 -
     * 403 akan mengonfirmasi bahwa record-nya ada (security standard §2).
     */
    #[Test]
    public function salesRequestingAnotherUsersRecordGetsNotFoundNotForbidden(): void
    {
        $this->signInAs(Role::Sales, 4242);

        $this->expectException(\App\Support\Exception\NotFoundException::class);
        $this->authorization->assertOwnershipForSales(9999);
    }

    #[Test]
    public function adminIsNotSubjectToOwnershipScoping(): void
    {
        $this->signInAs(Role::Admin, 1);

        $this->authorization->assertOwnershipForSales(9999);

        self::assertSame(Role::Admin, $this->authorization->currentRole());
    }

    // ------------------------------------------------------------- helpers

    private function signInAs(Role $role, int $userId = 1): void
    {
        $_SESSION['auth_user_id'] = $userId;
        $_SESSION['auth_user_role'] = $role->value;
        $_SESSION['auth_user_name'] = 'Test User';
    }

    private function seedUsers(): void
    {
        $hash = password_hash(self::PASSWORD, PASSWORD_DEFAULT);

        $this->users->save(new User(null, 'Active Admin', 'active@test.local', $hash, Role::Admin, true));
        $this->users->save(
            new User(null, 'Disabled Staff', 'disabled@test.local', $hash, Role::WarehouseStaff, false)
        );
    }
}
