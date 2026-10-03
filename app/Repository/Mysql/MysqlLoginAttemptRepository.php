<?php

declare(strict_types=1);

namespace App\Repository\Mysql;

use App\Repository\LoginAttemptRepositoryInterface;

/**
 * Rate limit login (security standard §7).
 *
 * IP disimpan sebagai VARBINARY hasil inet_pton agar IPv4 dan IPv6 sama-sama
 * tertangani dalam satu kolom.
 */
final class MysqlLoginAttemptRepository extends MysqlRepository implements LoginAttemptRepositoryInterface
{
    public function record(string $email, string $ipAddress, bool $succeeded): void
    {
        $this->run(
            'INSERT INTO login_attempt (email, ip_address, attempted_at, succeeded)
                  VALUES (:email, :ip, NOW(), :succeeded)',
            [
                'email'     => $email,
                'ip'        => $this->packIp($ipAddress),
                'succeeded' => $succeeded ? 1 : 0,
            ],
        );
    }

    public function countRecentFailures(string $email, string $ipAddress, int $withinMinutes): int
    {
        // INTERVAL tidak dapat menerima bound parameter pada MySQL, sehingga
        // nilainya di-cast ke int lebih dulu. Sumbernya adalah konfigurasi
        // aplikasi, bukan input user.
        $minutes = max(1, $withinMinutes);

        return $this->fetchInt(
            'SELECT COUNT(*)
               FROM login_attempt
              WHERE email = :email
                AND ip_address = :ip
                AND succeeded = 0
                AND attempted_at >= (NOW() - INTERVAL ' . $minutes . ' MINUTE)',
            [
                'email' => $email,
                'ip'    => $this->packIp($ipAddress),
            ],
        );
    }

    public function clearFailures(string $email, string $ipAddress): void
    {
        $this->run(
            'DELETE FROM login_attempt WHERE email = :email AND ip_address = :ip AND succeeded = 0',
            ['email' => $email, 'ip' => $this->packIp($ipAddress)],
        );
    }

    /** Bentuk biner IP; alamat tak valid dipetakan ke 0.0.0.0. */
    private function packIp(string $ipAddress): string
    {
        $packed = @inet_pton($ipAddress);

        if ($packed === false) {
            $fallback = inet_pton('0.0.0.0');

            return $fallback === false ? '' : $fallback;
        }

        return $packed;
    }
}
