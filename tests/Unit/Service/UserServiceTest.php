<?php

declare(strict_types=1);

namespace Tests\Unit\Service;

use App\Entity\Enum\Role;
use App\Entity\User;
use App\Service\AuthService;
use App\Service\UserService;
use App\Support\Exception\DomainException;
use App\Support\Exception\NotFoundException;
use App\Support\Exception\ValidationException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Tests\Unit\Fake\InMemoryLoginAttemptRepository;
use Tests\Unit\Fake\InMemoryUserRepository;

/**
 * Unit test UserService (USR-01).
 *
 * Seluruhnya terhadap fake in-memory - tanpa database, tanpa session
 * (constitution Principle III).
 */
final class UserServiceTest extends TestCase
{
    private const string PASSWORD = 'Password123!';

    private static ?string $hash = null;

    private InMemoryUserRepository $users;
    private UserService $service;
    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = new User(1, 'Admin One', 'admin@ioms.test', self::hash(), Role::Admin, true);

        $this->users = new InMemoryUserRepository([
            $this->admin,
            new User(2, 'Sales One', 'sales1@ioms.test', self::hash(), Role::Sales, true),
        ]);

        $this->service = new UserService($this->users);
    }

    // ------------------------------------------------------------ create

    #[Test]
    public function createsAUserAndStoresOnlyAHash(): void
    {
        $id = $this->service->create([
            'name'     => 'Warehouse One',
            'email'    => 'wh1@ioms.test',
            'password' => self::PASSWORD,
            'role'     => Role::WarehouseStaff->value,
        ]);

        $created = $this->users->findById($id);

        self::assertNotNull($created);
        self::assertSame('wh1@ioms.test', $created->email);
        self::assertSame(Role::WarehouseStaff, $created->role);
        self::assertTrue($created->isActive);

        // Password plaintext tidak boleh pernah tersimpan (§4.2).
        self::assertNotSame(self::PASSWORD, $created->passwordHash);
        self::assertTrue(password_verify(self::PASSWORD, $created->passwordHash));
    }

    #[Test]
    public function rejectsADuplicateEmail(): void
    {
        $this->expectException(ValidationException::class);

        $this->service->create([
            'name'     => 'Another Admin',
            'email'    => 'admin@ioms.test',
            'password' => self::PASSWORD,
            'role'     => Role::Admin->value,
        ]);
    }

    #[Test]
    public function theDuplicateEmailErrorNamesTheEmailField(): void
    {
        try {
            $this->service->create([
                'name'     => 'Another Admin',
                'email'    => 'admin@ioms.test',
                'password' => self::PASSWORD,
                'role'     => Role::Admin->value,
            ]);
            self::fail('Email duplikat seharusnya ditolak');
        } catch (ValidationException $e) {
            self::assertArrayHasKey('email', $e->errors());
        }
    }

    /**
     * USR-01: role hanya Admin, Sales, atau WarehouseStaff.
     */
    #[Test]
    public function rejectsARoleOutsideTheThreeAllowedValues(): void
    {
        $this->expectException(ValidationException::class);

        $this->service->create([
            'name'     => 'Sneaky',
            'email'    => 'sneaky@ioms.test',
            'password' => self::PASSWORD,
            'role'     => 'SuperAdmin',
        ]);
    }

    #[Test]
    public function rejectsMissingRequiredFields(): void
    {
        try {
            $this->service->create(['name' => '', 'email' => '', 'password' => '', 'role' => '']);
            self::fail('Field kosong seharusnya ditolak');
        } catch (ValidationException $e) {
            self::assertArrayHasKey('name', $e->errors());
            self::assertArrayHasKey('email', $e->errors());
            self::assertArrayHasKey('password', $e->errors());
            self::assertArrayHasKey('role', $e->errors());
        }
    }

    #[Test]
    public function rejectsAMalformedEmail(): void
    {
        $this->expectException(ValidationException::class);

        $this->service->create([
            'name'     => 'Bad Email',
            'email'    => 'not-an-email',
            'password' => self::PASSWORD,
            'role'     => Role::Sales->value,
        ]);
    }

    #[Test]
    public function rejectsAShortPassword(): void
    {
        $this->expectException(ValidationException::class);

        $this->service->create([
            'name'     => 'Short Password',
            'email'    => 'short@ioms.test',
            'password' => 'abc',
            'role'     => Role::Sales->value,
        ]);
    }

    // ------------------------------------------------------------ update

    #[Test]
    public function updatesNameEmailAndRole(): void
    {
        $this->service->update(2, [
            'name'  => 'Sales Renamed',
            'email' => 'renamed@ioms.test',
            'role'  => Role::WarehouseStaff->value,
        ]);

        $updated = $this->users->findById(2);

        self::assertNotNull($updated);
        self::assertSame('Sales Renamed', $updated->name);
        self::assertSame('renamed@ioms.test', $updated->email);
        self::assertSame(Role::WarehouseStaff, $updated->role);
    }

    /**
     * Keeping your own email on update must not trip the uniqueness rule.
     */
    #[Test]
    public function allowsAUserToKeepTheirOwnEmailOnUpdate(): void
    {
        $this->service->update(2, [
            'name'  => 'Sales Renamed',
            'email' => 'sales1@ioms.test',
            'role'  => Role::Sales->value,
        ]);

        $updated = $this->users->findById(2);

        self::assertNotNull($updated);
        self::assertSame('Sales Renamed', $updated->name);
    }

    #[Test]
    public function rejectsTakingAnotherUsersEmailOnUpdate(): void
    {
        $this->expectException(ValidationException::class);

        $this->service->update(2, [
            'name'  => 'Sales One',
            'email' => 'admin@ioms.test',
            'role'  => Role::Sales->value,
        ]);
    }

    #[Test]
    public function updatingAnUnknownUserIsNotFound(): void
    {
        $this->expectException(NotFoundException::class);

        $this->service->update(999, [
            'name'  => 'Ghost',
            'email' => 'ghost@ioms.test',
            'role'  => Role::Sales->value,
        ]);
    }

    // ---------------------------------------------------- toggle active

    #[Test]
    public function deactivatesAndReactivatesAUser(): void
    {
        $this->service->toggleActive($this->admin, 2);
        $after = $this->users->findById(2);
        self::assertNotNull($after);
        self::assertFalse($after->isActive);

        $this->service->toggleActive($this->admin, 2);
        $again = $this->users->findById(2);
        self::assertNotNull($again);
        self::assertTrue($again->isActive);
    }

    /**
     * Menonaktifkan diri sendiri akan mengunci Admin keluar dari sistemnya
     * sendiri - ditolak.
     */
    #[Test]
    public function anAdminCannotDeactivateThemselves(): void
    {
        $this->expectException(DomainException::class);

        $this->service->toggleActive($this->admin, 1);
    }

    /**
     * User yang dinonaktifkan benar-benar tidak dapat login lagi (AUTH-01).
     * Diuji lewat AuthService yang memakai repository yang sama.
     */
    #[Test]
    public function aDeactivatedUserCanNoLongerSignIn(): void
    {
        $auth = new AuthService($this->users, new InMemoryLoginAttemptRepository(), 5, 15);

        self::assertNotNull($auth->attempt('sales1@ioms.test', self::PASSWORD, '203.0.113.1'));

        $this->service->toggleActive($this->admin, 2);

        self::assertNull($auth->attempt('sales1@ioms.test', self::PASSWORD, '203.0.113.2'));
    }

    // -------------------------------------------------- change password

    #[Test]
    public function changesAPasswordAndStoresOnlyTheNewHash(): void
    {
        $before = $this->users->findById(2);
        self::assertNotNull($before);

        $this->service->changePassword(2, 'BrandNewPass1!');

        $after = $this->users->findById(2);
        self::assertNotNull($after);
        self::assertNotSame($before->passwordHash, $after->passwordHash);
        self::assertTrue(password_verify('BrandNewPass1!', $after->passwordHash));
        self::assertFalse(password_verify(self::PASSWORD, $after->passwordHash));
    }

    #[Test]
    public function rejectsAShortNewPassword(): void
    {
        $this->expectException(ValidationException::class);

        $this->service->changePassword(2, 'abc');
    }

    // ------------------------------------------------------------ lists

    #[Test]
    public function listsUsersWithSearchAndRoleFilter(): void
    {
        self::assertCount(2, $this->service->search([], 10, 0));
        self::assertCount(1, $this->service->search(['role' => Role::Admin->value], 10, 0));
        self::assertCount(1, $this->service->search(['search' => 'sales'], 10, 0));
        self::assertSame(2, $this->service->count([]));
    }

    private static function hash(): string
    {
        return self::$hash ??= password_hash(self::PASSWORD, PASSWORD_DEFAULT);
    }
}
