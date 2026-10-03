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

    /** Kedalaman nested transaction saat ini; menjadi nama savepoint. */
    private int $savepointDepth = 0;

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
                    // rowCount() pada UPDATE menghitung baris yang COCOK, bukan
                    // hanya yang nilainya berubah. Perubahan status bersyarat
                    // (WHERE status = :expected) bergantung pada ini: transisi
                    // PartiallyReceived -> PartiallyReceived tetap sah walau
                    // tidak ada kolom yang berubah.
                    \Pdo\Mysql::ATTR_FOUND_ROWS  => true,
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
     * Nested call menjadi SAVEPOINT di dalam transaction terluar: hanya
     * transaction terluar yang commit, tetapi kegagalan di dalam tetap
     * membatalkan pekerjaan nested call itu sendiri. Dulu nested call hanya
     * menjalankan callback (passthrough), sehingga di bawah pembungkus
     * transaction integration test rollback Service tidak pernah terjadi
     * (tech-debt TD-1).
     *
     * @template T
     * @param callable(): T $callback
     * @return T
     */
    public function transaction(callable $callback): mixed
    {
        $pdo = $this->pdo();

        if ($pdo->inTransaction()) {
            return $this->withinSavepoint($pdo, $callback);
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

    /**
     * Nama savepoint dibangkitkan dari counter internal, tidak pernah dari
     * input, sehingga aman disisipkan ke statement.
     *
     * @template T
     * @param callable(): T $callback
     * @return T
     */
    private function withinSavepoint(PDO $pdo, callable $callback): mixed
    {
        $name = 'ioms_sp_' . ++$this->savepointDepth;
        $pdo->exec('SAVEPOINT ' . $name);

        try {
            $result = $callback();
            $pdo->exec('RELEASE SAVEPOINT ' . $name);

            return $result;
        } catch (Throwable $e) {
            try {
                $pdo->exec('ROLLBACK TO SAVEPOINT ' . $name);
            } catch (PDOException $rollbackFailure) {
                // Deadlock membuat InnoDB me-rollback SELURUH transaction,
                // dan savepoint-nya ikut hilang. Exception asli yang lebih
                // penting; kegagalan rollback cukup dicatat.
                error_log('Rollback to savepoint failed: ' . $rollbackFailure->getMessage());
            }

            throw $e;
        } finally {
            $this->savepointDepth--;
        }
    }
}
