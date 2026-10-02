<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\User;
use App\Repository\LoginAttemptRepositoryInterface;
use App\Repository\UserRepositoryInterface;
use App\Support\Exception\RateLimitException;

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
}
