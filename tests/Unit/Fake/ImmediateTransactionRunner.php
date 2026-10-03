<?php

declare(strict_types=1);

namespace Tests\Unit\Fake;

use App\Support\TransactionRunner;
use Throwable;

/**
 * Fake TransactionRunner untuk unit test.
 *
 * Menjalankan callback langsung — tidak ada transaction sungguhan, karena
 * tidak ada database. Atomicity yang sebenarnya dibuktikan integration test
 * terhadap MySQL nyata (GoodsIssueTest, ConcurrentGoodsIssueTest); fake ini
 * hanya memungkinkan aturan bisnis di dalam callback diuji tanpa database.
 *
 * Yang tetap dapat diperiksa di sini: bahwa Service memang MEMBUNGKUS
 * pekerjaannya (wrapCount) dan bahwa exception dibiarkan naik sehingga
 * transaction produksi akan rollback.
 */
final class ImmediateTransactionRunner implements TransactionRunner
{
    private int $wrapCount = 0;

    private int $failureCount = 0;

    public function transaction(callable $callback): mixed
    {
        $this->wrapCount++;

        try {
            return $callback();
        } catch (Throwable $e) {
            // Di produksi titik inilah rollback terjadi.
            $this->failureCount++;

            throw $e;
        }
    }

    /**
     * Berapa kali Service membungkus pekerjaannya dalam transaction. Dipakai
     * test untuk memastikan pergerakan stock tidak pernah dilakukan di luar
     * transaction (ARCH-02).
     */
    public function wrapCount(): int
    {
        return $this->wrapCount;
    }

    /** Berapa kali callback gagal, artinya produksi akan melakukan rollback. */
    public function failureCount(): int
    {
        return $this->failureCount;
    }
}
