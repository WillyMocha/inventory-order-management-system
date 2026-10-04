<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Support\Database;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * Basis seluruh integration test.
 *
 * Setiap test dibungkus transaction yang di-rollback saat teardown, sehingga
 * test tetap independen dan cepat tanpa perlu membangun ulang database
 * (FIRST: Independent, Fast, Repeatable — TEST-03).
 *
 * Transaction milik Service tetap bekerja di dalam pembungkus ini: nested
 * Database::transaction() menjadi SAVEPOINT, sehingga rollback di tengah
 * operasi benar-benar terjadi (lihat NestedTransactionTest, tech-debt TD-1).
 *
 * Pembungkus hanya perlu dimatikan — dengan meng-override
 * wrapsInTransaction() — oleh test yang memakai DUA connection, seperti
 * ConcurrentGoodsIssueTest: connection kedua tidak dapat melihat data yang
 * belum di-commit connection pertama.
 */
abstract class IntegrationTestCase extends TestCase
{
    protected Database $database;

    protected PDO $pdo;

    private bool $transactionStarted = false;

    protected function setUp(): void
    {
        parent::setUp();

        $this->database = new Database(self::databaseConfig());
        $this->pdo = $this->database->pdo();

        if ($this->wrapsInTransaction()) {
            $this->pdo->beginTransaction();
            $this->transactionStarted = true;
        }
    }

    protected function tearDown(): void
    {
        if ($this->transactionStarted && $this->pdo->inTransaction()) {
            $this->pdo->rollBack();
        }

        $this->transactionStarted = false;

        parent::tearDown();
    }

    /**
     * Override menjadi false pada test yang mengelola transaction sendiri,
     * misalnya pengujian lock dengan dua connection.
     */
    protected function wrapsInTransaction(): bool
    {
        return true;
    }

    /**
     * Connection kedua yang benar-benar terpisah.
     *
     * Inilah yang memungkinkan pembuktian SELECT ... FOR UPDATE: connection
     * pertama memegang lock, connection kedua terbukti menunggu (ARCH-02).
     */
    protected function newSeparateConnection(): PDO
    {
        return (new Database(self::databaseConfig()))->pdo();
    }

    /**
     * @return array{host: string, port: int, database: string, username: string, password: string}
     */
    protected static function databaseConfig(): array
    {
        /** @var array<string, mixed> $config */
        $config = require dirname(__DIR__, 2) . '/config/app.php';

        /** @var array{host: string, port: int, database: string, username: string, password: string} $database */
        $database = $config['database'];

        return $database;
    }

    /**
     * Menghapus isi tabel dengan urutan yang menghormati foreign key.
     * Dipakai test yang butuh kondisi awal benar-benar kosong.
     */
    protected function truncateAll(): void
    {
        $tables = [
            'stock_ledger',
            'sales_order_item',
            'sales_order',
            'purchase_order_item',
            'purchase_order',
            'product_stock',
            'product',
            'category',
            'supplier',
            'customer',
            'warehouse',
            'login_attempt',
            'user',
        ];

        $this->pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
        // stock_ledger dijaga trigger append-only (005_ledger_append_only.sql);
        // pengosongan fixture adalah satu-satunya DELETE yang sah, dan harus
        // dinyatakan eksplisit untuk sesi ini saja.
        $this->pdo->exec('SET @ioms_allow_ledger_cleanup = 1');

        foreach ($tables as $table) {
            $this->pdo->exec('DELETE FROM `' . $table . '`');
        }

        $this->pdo->exec('SET @ioms_allow_ledger_cleanup = NULL');
        $this->pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
    }
}
