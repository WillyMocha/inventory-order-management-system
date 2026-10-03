<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Enum\Role;
use App\Entity\User;
use App\Repository\LoginAttemptRepositoryInterface;
use App\Repository\UserRepositoryInterface;
use App\Support\Exception\RateLimitException;
use App\Support\Exception\ValidationException;
use App\Support\Validator;

/**
 * Authentication (AUTH-01).
 *
 * Service ini tidak pernah menyentuh session maupun superglobal. IP address
 * di-pass masuk sebagai argument, sama seperti acting user pada Service lain -
 * itulah yang membuat seluruh aturan di sini dapat di-unit-test tanpa session
 * dan tanpa database (constitution Principle I dan III).
 *
 * Tiga penyebab kegagalan - email tidak terdaftar, password salah, dan akun
 * dinonaktifkan - sengaja dibuat TIDAK dapat dibedakan dari luar. Membedakannya
 * akan memberi penyerang cara memetakan email mana yang terdaftar
 * (account enumeration, security standard §7).
 */
final class AuthService
{
    /**
     * Satu-satunya pesan kegagalan login. Tidak menyebutkan bagian mana yang
     * keliru (FR-002).
     */
    public const string FAILURE_MESSAGE = 'Email or password is incorrect.';

    /**
     * Satu-satunya pesan untuk password saat ini yang salah pada halaman
     * profil (002 NFR-004).
     */
    public const string WRONG_CURRENT_PASSWORD_MESSAGE = 'Your current password is incorrect.';

    /**
     * Hash dummy untuk email yang tidak terdaftar.
     *
     * Tanpa ini, permintaan dengan email tak dikenal akan kembali jauh lebih
     * cepat karena melewatkan bcrypt, dan selisih waktunya cukup untuk
     * membedakan email yang terdaftar dari yang tidak.
     */
    private const string DUMMY_HASH = '$2y$12$usesomesillystringfoeueaOAsE/lVMS.p05KRuS8XkGe0ZjqfvWy';

    public function __construct(
        private readonly UserRepositoryInterface $users,
        private readonly LoginAttemptRepositoryInterface $attempts,
        private readonly int $maxAttempts,
        private readonly int $windowMinutes,
    ) {
    }

    /**
     * Memverifikasi kredensial.
     *
     * @return User|null user bila berhasil; null untuk SETIAP penyebab
     *                   kegagalan, tanpa membedakannya.
     *
     * @throws RateLimitException bila batas percobaan untuk pasangan
     *                            (email, IP) sudah terlampaui.
     */
    public function attempt(string $email, string $password, string $ipAddress): ?User
    {
        $email = trim($email);

        // Rate limit diperiksa lebih dulu, dan berlaku juga untuk email yang
        // tidak terdaftar - kalau tidak, perilaku throttling sendiri akan
        // membocorkan email mana yang ada (security standard §7).
        if ($this->isLockedOut($email, $ipAddress)) {
            throw new RateLimitException();
        }

        $user = $this->users->findByEmail($email);

        // Selalu jalankan password_verify, termasuk saat user tidak ada,
        // agar biaya waktunya setara.
        $hash = $user !== null ? $user->passwordHash : self::DUMMY_HASH;
        $passwordMatches = password_verify($password, $hash);

        if ($user === null || !$passwordMatches || !$user->canSignIn()) {
            $this->attempts->record($email, $ipAddress, false);

            return null;
        }

        $this->rehashIfNeeded($user, $password);

        $this->attempts->record($email, $ipAddress, true);
        $this->attempts->clearFailures($email, $ipAddress);

        return $user;
    }

    private function isLockedOut(string $email, string $ipAddress): bool
    {
        $failures = $this->attempts->countRecentFailures($email, $ipAddress, $this->windowMinutes);

        return $failures >= $this->maxAttempts;
    }

    /**
     * Meng-upgrade hash yang dibuat dengan parameter lama. Dijalankan diam-diam
     * saat login berhasil, ketika password plaintext-nya memang tersedia.
     */
    private function rehashIfNeeded(User $user, string $password): void
    {
        if ($user->id === null || !password_needs_rehash($user->passwordHash, PASSWORD_DEFAULT)) {
            return;
        }

        $this->users->updatePasswordHash($user->id, password_hash($password, PASSWORD_DEFAULT));
    }

    /**
     * Verifikasi ulang password milik user yang sedang login.
     *
     * Dipakai sebagai step-up re-auth sebelum aksi sensitif, misalnya ketika
     * Admin mengubah password user lain (security standard §7).
     */
    public function verifyPasswordFor(User $user, string $password): bool
    {
        return password_verify($password, $user->passwordHash);
    }

    /**
     * User mengganti password miliknya sendiri (002 FR-005, FR-006, research R-004).
     *
     * Password saat ini berfungsi sebagai re-auth: tanpa itu, sesi yang
     * ditinggalkan terbuka cukup untuk mengambil alih akun (security
     * standard §7). Urutan pemeriksaan disengaja - lihat research R-004.
     *
     * @throws RateLimitException bila batas percobaan untuk pasangan
     *                            (email, IP) sudah terlampaui - counter yang
     *                            sama dengan login (FR-008, research R-001).
     * @throws ValidationException bila input tidak valid, password saat ini
     *                             salah, atau password baru sama dengan yang lama.
     */
    public function changeOwnPassword(
        User $actor,
        string $currentPassword,
        string $newPassword,
        string $confirmation,
        string $ipAddress,
    ): void {
        // Diperiksa paling awal, sebelum password diverifikasi: client yang
        // terkunci tidak boleh mendapat informasi apa pun, termasuk ketika
        // tebakannya kebetulan benar.
        if ($this->isLockedOut($actor->email, $ipAddress)) {
            throw new RateLimitException();
        }

        // Aturan format tidak membutuhkan rahasia apa pun, sehingga tidak
        // dihitung sebagai tebakan password.
        Validator::make([
            'current_password' => $currentPassword,
            'new_password' => $newPassword,
        ])
            ->required('current_password', 'Current password')
            ->required('new_password', 'New password')
            ->minLength('new_password', 'New password', User::MIN_PASSWORD_LENGTH)
            ->rule(
                'new_password_confirmation',
                $newPassword === $confirmation,
                'The new password and its confirmation do not match.',
            )
            ->validate();

        if (!password_verify($currentPassword, $actor->passwordHash)) {
            $this->attempts->record($actor->email, $ipAddress, false);

            throw new ValidationException(['current_password' => self::WRONG_CURRENT_PASSWORD_MESSAGE]);
        }

        // Diperiksa SETELAH password saat ini terbukti benar. Kalau sebelumnya,
        // pesan ini akan menjadi oracle untuk menebak password yang tersimpan.
        if (password_verify($newPassword, $actor->passwordHash)) {
            throw new ValidationException([
                'new_password' => 'Choose a password different from your current one.',
            ]);
        }

        $this->users->updatePasswordHash((int) $actor->id, password_hash($newPassword, PASSWORD_DEFAULT));
        $this->attempts->clearFailures($actor->email, $ipAddress);
    }

    /**
     * Memvalidasi ulang user yang tercatat di session (002 FR-012, research R-008).
     *
     * Session hanya menyimpan id dan role saat login. Tanpa pemeriksaan ini,
     * akun yang dinonaktifkan atau diganti role-nya oleh Admin tetap memegang
     * hak akses lama sampai user logout sendiri.
     *
     * @return User|null user bila masih ada, aktif, dan role-nya sama dengan
     *                   role di session; null bila session harus diakhiri.
     */
    public function activeSessionUser(int $userId, Role $sessionRole): ?User
    {
        $user = $this->users->findById($userId);

        if ($user === null || !$user->canSignIn() || $user->role !== $sessionRole) {
            return null;
        }

        return $user;
    }
}
