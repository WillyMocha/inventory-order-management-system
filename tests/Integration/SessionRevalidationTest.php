<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Entity\Enum\Role;
use App\Entity\User;
use App\Repository\Mysql\MysqlLoginAttemptRepository;
use App\Repository\Mysql\MysqlUserRepository;
use App\Service\AuthService;
use PHPUnit\Framework\Attributes\Test;

/**
 * Validasi ulang user session terhadap MySQL sungguhan (002 FR-012, SC-007).
 *
 * Unit test sudah membuktikan aturannya terhadap fake. Di sini dibuktikan
 * bahwa perubahan yang dilakukan Admin lewat repository MySQL - menonaktifkan
 * akun atau mengganti role - langsung terlihat oleh pemeriksaan berikutnya,
 * tanpa cache di antaranya.
 */
final class SessionRevalidationTest extends IntegrationTestCase
{
    private AuthService $service;
    private MysqlUserRepository $users;
    private User $sales;

    protected function setUp(): void
    {
        parent::setUp();

        $this->users = new MysqlUserRepository($this->database);
        $this->service = new AuthService(
            $this->users,
            new MysqlLoginAttemptRepository($this->database),
            5,
            15,
        );

        $id = $this->users->save(new User(
            null,
            'Revalidation Sales',
            'revalidation-sales@test.local',
            password_hash('Password123!', PASSWORD_DEFAULT),
            Role::Sales,
            true,
        ));

        $user = $this->users->findById($id);
        self::assertNotNull($user);
        $this->sales = $user;
    }

    #[Test]
    public function anActiveUserKeepsTheSession(): void
    {
        $user = $this->service->activeSessionUser((int) $this->sales->id, Role::Sales);

        self::assertNotNull($user);
        self::assertSame('revalidation-sales@test.local', $user->email);
    }

    #[Test]
    public function deactivationEndsTheSessionOnTheNextCheck(): void
    {
        $this->users->setActive((int) $this->sales->id, false);

        self::assertNull($this->service->activeSessionUser((int) $this->sales->id, Role::Sales));
    }

    #[Test]
    public function aRoleChangeEndsTheSessionOnTheNextCheck(): void
    {
        $this->users->save(new User(
            $this->sales->id,
            $this->sales->name,
            $this->sales->email,
            $this->sales->passwordHash,
            Role::WarehouseStaff,
            true,
        ));

        self::assertNull($this->service->activeSessionUser((int) $this->sales->id, Role::Sales));
    }

    #[Test]
    public function aMissingUserEndsTheSession(): void
    {
        self::assertNull($this->service->activeSessionUser(PHP_INT_MAX, Role::Sales));
    }
}
