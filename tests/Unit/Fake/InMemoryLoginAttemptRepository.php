<?php

declare(strict_types=1);

namespace Tests\Unit\Fake;

use App\Repository\LoginAttemptRepositoryInterface;

/**
 * Fake in-memory untuk rate limit login.
 *
 * Tidak memakai jam nyata: seluruh percobaan yang tercatat dianggap berada di
 * dalam window. Unit test mengendalikan jumlahnya secara eksplisit, sehingga
 * test tetap cepat dan deterministik (FIRST).
 */
final class InMemoryLoginAttemptRepository implements LoginAttemptRepositoryInterface
{
    /** @var array<string, int> "email|ip" => jumlah kegagalan */
    private array $failures = [];

    /** @var list<array{email: string, ip: string, succeeded: bool}> */
    private array $recorded = [];

    public function record(string $email, string $ipAddress, bool $succeeded): void
    {
        $this->recorded[] = ['email' => $email, 'ip' => $ipAddress, 'succeeded' => $succeeded];

        if ($succeeded) {
            return;
        }

        $key = $this->key($email, $ipAddress);
        $this->failures[$key] = ($this->failures[$key] ?? 0) + 1;
    }

    public function countRecentFailures(string $email, string $ipAddress, int $withinMinutes): int
    {
        return $this->failures[$this->key($email, $ipAddress)] ?? 0;
    }

    public function clearFailures(string $email, string $ipAddress): void
    {
        unset($this->failures[$this->key($email, $ipAddress)]);
    }

    /**
     * Seluruh percobaan yang tercatat — dipakai test untuk memastikan
     * percobaan dengan email tak terdaftar juga tetap dicatat, agar tidak
     * membocorkan keberadaan akun lewat perbedaan perilaku.
     *
     * @return list<array{email: string, ip: string, succeeded: bool}>
     */
    public function recorded(): array
    {
        return $this->recorded;
    }

    private function key(string $email, string $ipAddress): string
    {
        return strtolower($email) . '|' . $ipAddress;
    }
}
