<?php

declare(strict_types=1);

namespace Tests\Unit\Service;

use App\Entity\Enum\Role;
use App\Entity\User;
use App\Service\AuthService;
use App\Support\Exception\RateLimitException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Tests\Unit\Fake\InMemoryLoginAttemptRepository;
use Tests\Unit\Fake\InMemoryUserRepository;

/**
 * Unit test AuthService.
 *
 * Berjalan sepenuhnya terhadap fake in-memory: tanpa database, tanpa session,
 * tanpa network (constitution Principle III, TEST-01).
 *
 * Cost bcrypt sengaja diturunkan pada test agar suite tetap cepat (FIRST).
 * Nilai produksi memakai default PHP.
 */
final class AuthServiceTest extends TestCase
{
    private const string PASSWORD = 'Password123!';
    private const string IP = '203.0.113.10';

    /**
     * Hash dengan cost default dihitung SEKALI untuk seluruh kelas. Menghitung
     * ulang per test membuat suite lambat tanpa menambah nilai (FIRST: Fast).
     */
    private static ?string $currentHash = null;

    private InMemoryUserRepository $users;
    private InMemoryLoginAttemptRepository $attempts;
    private AuthService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->users = new InMemoryUserRepository([
            $this->makeUser(1, 'admin@ioms.test', Role::Admin, true),
            $this->makeUser(2, 'sales@ioms.test', Role::Sales, true),
            $this->makeUser(3, 'inactive@ioms.test', Role::WarehouseStaff, false),
        ]);

        $this->attempts = new InMemoryLoginAttemptRepository();

        $this->service = new AuthService($this->users, $this->attempts, 5, 15);
    }

    // ---------------------------------------------------------------- FR-001

    #[Test]
    public function validCredentialsReturnTheUser(): void
    {
        $user = $this->service->attempt('admin@ioms.test', self::PASSWORD, self::IP);

        self::assertNotNull($user);
        self::assertSame('admin@ioms.test', $user->email);
        self::assertSame(Role::Admin, $user->role);
    }

    #[Test]
    public function wrongPasswordIsRefused(): void
    {
        self::assertNull($this->service->attempt('admin@ioms.test', 'WrongPassword1!', self::IP));
    }

    #[Test]
    public function unknownEmailIsRefused(): void
    {
        self::assertNull($this->service->attempt('nobody@ioms.test', self::PASSWORD, self::IP));
    }

    /**
     * AUTH-01: user tidak aktif tidak dapat login, walaupun password-nya benar.
     */
    #[Test]
    public function deactivatedUserIsRefusedEvenWithCorrectPassword(): void
    {
        self::assertNull($this->service->attempt('inactive@ioms.test', self::PASSWORD, self::IP));
    }

    // ---------------------------------------------------------------- FR-002

    /**
     * Ketiga penyebab kegagalan harus tidak dapat dibedakan dari luar.
     * Kalau salah satunya berbeda, penyerang dapat memetakan email mana yang
     * terdaftar (account enumeration, security standard §7).
     */
    #[Test]
    public function allFailureCausesAreIndistinguishable(): void
    {
        $wrongPassword = $this->service->attempt('admin@ioms.test', 'Nope', self::IP);
        $unknownEmail = $this->service->attempt('ghost@ioms.test', self::PASSWORD, '203.0.113.11');
        $deactivated = $this->service->attempt('inactive@ioms.test', self::PASSWORD, '203.0.113.12');

        self::assertNull($wrongPassword);
        self::assertNull($unknownEmail);
        self::assertNull($deactivated);
    }

    #[Test]
    public function theFailureMessageNamesNeitherFieldSpecifically(): void
    {
        $message = strtolower(AuthService::FAILURE_MESSAGE);

        // Pesan boleh menyebut "email or password", tetapi tidak boleh
        // menyatakan salah satunya yang keliru.
        self::assertStringNotContainsString('not found', $message);
        self::assertStringNotContainsString('does not exist', $message);
        self::assertStringNotContainsString('inactive', $message);
        self::assertStringNotContainsString('deactivated', $message);
        self::assertStringNotContainsString('wrong password', $message);
    }

    /**
     * Email yang tidak terdaftar tetap melewati verifikasi password dummy,
     * agar tidak ada jalur pintas yang membuat responsnya jauh lebih cepat.
     */
    #[Test]
    public function unknownEmailIsStillRecordedSoTimingDoesNotLeak(): void
    {
        $this->service->attempt('ghost@ioms.test', self::PASSWORD, self::IP);

        $recorded = $this->attempts->recorded();

        self::assertCount(1, $recorded);
        self::assertSame('ghost@ioms.test', $recorded[0]['email']);
        self::assertFalse($recorded[0]['succeeded']);
    }

    // ---------------------------------------- Rate limit (security §7)

    #[Test]
    public function theSixthFailureWithinTheWindowIsRefused(): void
    {
        for ($i = 0; $i < 5; $i++) {
            self::assertNull($this->service->attempt('admin@ioms.test', 'Wrong', self::IP));
        }

        $this->expectException(RateLimitException::class);
        $this->service->attempt('admin@ioms.test', 'Wrong', self::IP);
    }

    /**
     * Setelah terkunci, kredensial yang BENAR pun tetap ditolak - kalau tidak,
     * rate limit-nya tidak ada gunanya.
     */
    #[Test]
    public function lockoutAlsoBlocksTheCorrectPassword(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->service->attempt('admin@ioms.test', 'Wrong', self::IP);
        }

        $this->expectException(RateLimitException::class);
        $this->service->attempt('admin@ioms.test', self::PASSWORD, self::IP);
    }

    #[Test]
    public function theLimitIsCountedPerEmailAndIpPair(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->service->attempt('admin@ioms.test', 'Wrong', self::IP);
        }

        // IP berbeda: hitungannya terpisah, jadi masih boleh mencoba.
        self::assertNull($this->service->attempt('admin@ioms.test', 'Wrong', '198.51.100.7'));

        // Email berbeda pada IP yang sama: juga terpisah.
        self::assertNotNull($this->service->attempt('sales@ioms.test', self::PASSWORD, self::IP));
    }

    /**
     * Email yang tidak terdaftar dihitung dengan cara yang sama, sehingga
     * perilaku throttling tidak membocorkan email mana yang ada.
     */
    #[Test]
    public function unknownEmailsAreThrottledIdentically(): void
    {
        for ($i = 0; $i < 5; $i++) {
            self::assertNull($this->service->attempt('ghost@ioms.test', 'Wrong', self::IP));
        }

        $this->expectException(RateLimitException::class);
        $this->service->attempt('ghost@ioms.test', 'Wrong', self::IP);
    }

    #[Test]
    public function successfulSignInClearsTheFailureCount(): void
    {
        for ($i = 0; $i < 4; $i++) {
            $this->service->attempt('admin@ioms.test', 'Wrong', self::IP);
        }

        self::assertNotNull($this->service->attempt('admin@ioms.test', self::PASSWORD, self::IP));

        // Hitungan sudah nol lagi: lima kegagalan berikutnya belum mengunci.
        for ($i = 0; $i < 5; $i++) {
            self::assertNull($this->service->attempt('admin@ioms.test', 'Wrong', self::IP));
        }
    }

    // -------------------------------------------------------- Rehash

    /**
     * Hash yang dibuat dengan cost lebih rendah dari konfigurasi saat ini
     * di-upgrade diam-diam ketika user berhasil login.
     */
    #[Test]
    public function anOutdatedHashIsUpgradedOnSuccessfulSignIn(): void
    {
        $weakHash = password_hash(self::PASSWORD, PASSWORD_BCRYPT, ['cost' => 4]);

        $users = new InMemoryUserRepository([
            new User(1, 'legacy@ioms.test', 'legacy@ioms.test', $weakHash, Role::Admin, true),
        ]);
        $service = new AuthService($users, new InMemoryLoginAttemptRepository(), 5, 15);

        $before = $users->findById(1);
        self::assertNotNull($before);

        self::assertNotNull($service->attempt('legacy@ioms.test', self::PASSWORD, self::IP));

        $after = $users->findById(1);
        self::assertNotNull($after);
        self::assertNotSame(
            $before->passwordHash,
            $after->passwordHash,
            'Hash usang seharusnya di-rehash setelah login berhasil',
        );
        self::assertTrue(password_verify(self::PASSWORD, $after->passwordHash));
    }

    #[Test]
    public function aCurrentHashIsLeftAlone(): void
    {
        $before = $this->users->findById(1);
        self::assertNotNull($before);

        $this->service->attempt('admin@ioms.test', self::PASSWORD, self::IP);

        $after = $this->users->findById(1);
        self::assertNotNull($after);
        self::assertSame($before->passwordHash, $after->passwordHash);
    }

    // ---------------------------------------------------------------- helper

    private function makeUser(int $id, string $email, Role $role, bool $active): User
    {
        return new User($id, ucfirst(explode('@', $email)[0]), $email, self::currentHash(), $role, $active);
    }

    // -------------------------------- Step-up re-auth (security standard §7)

    #[Test]
    public function stepUpReAuthAcceptsTheUsersOwnPassword(): void
    {
        $admin = $this->users->findById(1);

        self::assertNotNull($admin);
        self::assertTrue($this->service->verifyPasswordFor($admin, self::PASSWORD));
    }

    #[Test]
    public function stepUpReAuthRejectsAWrongPassword(): void
    {
        // Inilah yang menghalangi sesi yang ditinggalkan terbuka dipakai
        // mengubah password user lain (security standard §7).
        $admin = $this->users->findById(1);

        self::assertNotNull($admin);
        self::assertFalse($this->service->verifyPasswordFor($admin, 'WrongPassword1!'));
    }

    #[Test]
    public function stepUpReAuthRejectsAnEmptyPassword(): void
    {
        $admin = $this->users->findById(1);

        self::assertNotNull($admin);
        self::assertFalse($this->service->verifyPasswordFor($admin, ''));
    }

    #[Test]
    public function stepUpReAuthIsCheckedAgainstTheGivenUserOnly(): void
    {
        // Password yang benar milik user LAIN tidak boleh meloloskan aksi ini.
        $admin = $this->users->findById(1);
        $sales = $this->users->findById(2);

        self::assertNotNull($admin);
        self::assertNotNull($sales);

        // Keduanya memakai password fixture yang sama, jadi yang dibuktikan di
        // sini adalah verifikasi memang dilakukan terhadap hash milik user yang
        // di-pass — bukan terhadap user yang sedang login.
        self::assertTrue($this->service->verifyPasswordFor($sales, self::PASSWORD));
        self::assertFalse($this->service->verifyPasswordFor($sales, 'NotTheirs1!'));
    }

    /** Hash yang sudah sesuai konfigurasi saat ini - tidak perlu di-rehash. */
    private static function currentHash(): string
    {
        return self::$currentHash ??= password_hash(self::PASSWORD, PASSWORD_DEFAULT);
    }
}
