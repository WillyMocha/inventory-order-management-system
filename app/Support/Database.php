<?php

declare(strict_types=1);

namespace App\Support;

use PDO;
use PDOException;
use RuntimeException;
use Throwable;

/**
 * Factory PDO sekaligus pembungkus transaction.
 *
 * ATTR_EMULATE_PREPARES sengaja dimatikan: dengan emulation aktif, PDO
 * melakukan interpolasi di sisi client dan klaim "prepared statement" menjadi
 * jauh lebih lemah daripada kelihatannya (research R-007, §4.2).
 */
final class Database implements TransactionRunner
{
    private ?PDO $pdo = null;

    /**
     * @param array{host: string, port: int, database: string, username: string, password: string} $config
     */
    public function __construct(private readonly array $config)
    {
    }

    public function pdo(): PDO
    {
        if ($this->pdo instanceof PDO) {
            return $this->pdo;
        }

        $dsn = sprintf(
            'mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
            $this->config['host'],
            $this->config['port'],
            $this->config['database'],
        );

        try {
            $this->pdo = new PDO(
                $dsn,
                $this->config['username'],
                $this->config['password'],
                [
                    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    // Prepared statement yang sesungguhnya, bukan emulasi.
                    PDO::ATTR_EMULATE_PREPARES   => false,
                    PDO::ATTR_STRINGIFY_FETCHES  => false,
                ],
            );
        } catch (PDOException $e) {
            // Pesan asli hanya masuk ke server log; user tidak pernah melihat
            // detail koneksi database.
            error_log('Database connection failed: ' . $e->getMessage());
            throw new RuntimeException('Database connection failed.', 0, $e);
        }

        return $this->pdo;
    }

    /**
     * Menjalankan callback di dalam satu transaction eksplisit.
     *
     * Commit bila callback selesai tanpa exception, rollback bila tidak.
     * Inilah pembungkus yang dipakai goods receipt dan goods issue sehingga
     * penulisan stock_ledger dan perubahan product_stock tidak pernah terpisah
     * (ARCH-02, constitution Principle IV).
     *
     * Nested call diizinkan dan ikut transaction terluar — hanya transaction
     * terluar yang melakukan commit.
     *
     * @template T
     * @param callable(): T $callback
     * @return T
     */
    public function transaction(callable $callback): mixed
    {
        $pdo = $this->pdo();

        if ($pdo->inTransaction()) {
            return $callback();
        }

        $pdo->beginTransaction();

        // Status transaction dilacak lewat flag lokal, bukan dengan memanggil
        // inTransaction() lagi: pemanggilan kedua membuat static analysis
        // menyimpulkan kondisinya selalu false karena hasil panggilan pertama
        // sudah dipersempit di atas.
        $committed = false;

        try {
            $result = $callback();
            $pdo->commit();
            $committed = true;

            return $result;
        } finally {
            if (!$committed) {
                $pdo->rollBack();
            }
        }
    }
}
