<?php

declare(strict_types=1);

namespace App\Repository;

/**
 * Pendukung rate limit login (security standard §7).
 *
 * Percobaan dicatat walaupun email-nya tidak terdaftar, agar perbedaan waktu
 * respons tidak membocorkan keberadaan akun.
 */
interface LoginAttemptRepositoryInterface
{
    public function record(string $email, string $ipAddress, bool $succeeded): void;

    /**
     * Jumlah kegagalan untuk pasangan (email, IP) dalam rentang menit terakhir.
     */
    public function countRecentFailures(string $email, string $ipAddress, int $withinMinutes): int;

    /** Dipanggil setelah login berhasil agar hitungannya kembali nol. */
    public function clearFailures(string $email, string $ipAddress): void;
}
