<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Repository\Mysql\MysqlProductRepository;
use App\Repository\Mysql\MysqlProductStockRepository;
use App\Repository\Mysql\MysqlPurchaseOrderRepository;
use App\Repository\Mysql\MysqlSalesOrderRepository;
use App\Repository\Mysql\MysqlStockLedgerRepository;
use App\Repository\Mysql\MysqlWarehouseRepository;
use App\Service\StockService;
use App\Support\Database;
use App\Support\Exception\ValidationException;
use PDO;
use PDOException;
use PHPUnit\Framework\Attributes\Test;

/**
 * Koreksi stock di bawah konkurensi, dengan dua connection MySQL yang benar-
 * benar terpisah (spec 003 FR-004, FR-006, SC-005; research R-002, R-003).
 *
 * PHPUnit berjalan single threaded, jadi "B menunggu" dibuktikan lewat lock
 * wait timeout pada connection B — pola yang sama dengan
 * ConcurrentGoodsIssueTest — dan penolakan stale dibuktikan pada langkah
 * terpisah setelah A commit. Deterministik, tanpa thread dan tanpa sleep.
 *
 * Stock awal dibentuk lewat adjustStock() sendiri, bukan setStock(), agar
 * invariant SUM(ledger) = product_stock dapat diperiksa di akhir setiap
 * skenario.
 */
final class ConcurrentStockAdjustmentTest extends IntegrationTestCase
{
    use SalesOrderFixtures;

    /** Detik. Pendek agar bukti "B menunggu" tidak memperlambat suite. */
    private const int LOCK_WAIT_TIMEOUT = 2;

    /** Kode error MySQL untuk deadlock — yang TIDAK boleh muncul (research R-002). */
    private const int DEADLOCK = 1213;

    private Database $connectionB;
    private PDO $pdoB;

    /**
     * Dua connection tidak dapat saling melihat data yang belum commit,
     * sehingga fixture harus benar-benar ter-commit.
     */
    protected function wrapsInTransaction(): bool
    {
        return false;
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->connectionB = new Database(self::databaseConfig());
        $this->pdoB = $this->connectionB->pdo();
        $this->pdoB->exec('SET SESSION innodb_lock_wait_timeout = ' . self::LOCK_WAIT_TIMEOUT);

        $this->cleanUpSalesOrderFixtures();
        $this->seedSalesOrderFixtures();
    }

    protected function tearDown(): void
    {
        foreach ([$this->pdo, $this->pdoB] as $connection) {
            if ($connection->inTransaction()) {
                $connection->rollBack();
            }
        }

        $this->cleanUpSalesOrderFixtures();

        parent::tearDown();
    }

    /**
     * (a1) Selama A memegang lock baris stock, koreksi B MENUNGGU — dan setelah
     * timeout tidak meninggalkan perubahan apa pun.
     */
    #[Test]
    public function aCorrectionWaitsWhileTheStockRowIsLocked(): void
    {
        $this->serviceOn($this->database)->adjustStock($this->productId, $this->input('3', '0'), $this->warehouseStaff);

        $this->pdo->beginTransaction();
        $this->lockStockRowOn($this->pdo, $this->productId);

        $error = $this->adjustExpectingLockWait($this->productId, $this->input('2', '3'));
        $this->pdo->rollBack();

        self::assertNotSame(self::DEADLOCK, $error->errorInfo[1] ?? null);
        self::assertSame(3, $this->stockQuantity($this->productId, $this->warehouseId));
        $this->assertLedgerReconciles($this->productId);
    }

    /**
     * (a2) Goods issue terjadi selama penghitungan: koreksi B yang membawa
     * quantity lama ditolak sebagai stale, bukan menghapus efek issue itu.
     */
    #[Test]
    public function aCorrectionBasedOnAQuantityChangedByAGoodsIssueIsRefused(): void
    {
        $this->serviceOn($this->database)->adjustStock($this->productId, $this->input('3', '0'), $this->warehouseStaff);

        $this->serviceOn($this->database)->issueGoods(
            $this->approvedOrder([[$this->productId, 1]]),
            $this->warehouseStaff,
        );

        $refusedWith = null;

        try {
            $this->serviceOn($this->connectionB)
                ->adjustStock($this->productId, $this->input('2', '3'), $this->warehouseStaff);
        } catch (ValidationException $e) {
            $refusedWith = $e->errors();
        }

        self::assertNotNull($refusedWith, 'Koreksi dengan quantity basi seharusnya ditolak');
        self::assertArrayHasKey('stock', $refusedWith);
        self::assertSame(2, $this->stockQuantity($this->productId, $this->warehouseId));
        $this->assertLedgerReconciles($this->productId);
    }

    /**
     * (b) Pasangan yang belum pernah punya baris stock: koreksi kedua MENUNGGU
     * baris yang dibuat ensureRow() koreksi pertama, bukan lolos lewat gap lock
     * lalu berakhir deadlock (research R-002). Setelah A rollback, B berhasil.
     */
    #[Test]
    public function aNeverStockedPairIsSerialisedWithoutDeadlock(): void
    {
        $stocksA = new MysqlProductStockRepository($this->database);

        $this->pdo->beginTransaction();
        $stocksA->ensureRow($this->secondProductId, $this->warehouseId);
        $stocksA->lockForUpdate($this->secondProductId, $this->warehouseId);

        $error = $this->adjustExpectingLockWait($this->secondProductId, $this->input('5', '0'));
        self::assertNotSame(self::DEADLOCK, $error->errorInfo[1] ?? null, 'Gap lock lolos dan berakhir deadlock');

        $this->pdo->rollBack();

        self::assertSame(
            ['before' => 0, 'after' => 5, 'delta' => 5],
            $this->serviceOn($this->connectionB)
                ->adjustStock($this->secondProductId, $this->input('5', '0'), $this->warehouseStaff),
        );
        self::assertSame(5, $this->stockQuantity($this->secondProductId, $this->warehouseId));
        $this->assertLedgerReconciles($this->secondProductId);
    }

    // ---------------------------------------------------------- helper

    /**
     * Menjalankan koreksi pada connection B yang harus berakhir dengan lock wait
     * timeout, dan memastikan B memang menunggu kira-kira selama timeout-nya.
     *
     * @param array<string, string> $input
     */
    private function adjustExpectingLockWait(int $productId, array $input): PDOException
    {
        $startedAt = microtime(true);

        try {
            $this->serviceOn($this->connectionB)->adjustStock($productId, $input, $this->warehouseStaff);
        } catch (PDOException $e) {
            self::assertGreaterThanOrEqual(
                self::LOCK_WAIT_TIMEOUT - 0.5,
                microtime(true) - $startedAt,
                'B harus benar-benar menunggu sampai timeout, bukan gagal seketika',
            );

            return $e;
        }

        self::fail('Connection B tidak menunggu — baris stock tidak terkunci');
    }

    private function lockStockRowOn(PDO $connection, int $productId): void
    {
        $statement = $connection->prepare(
            'SELECT quantity FROM product_stock
              WHERE product_id = :product_id AND warehouse_id = :warehouse_id
              FOR UPDATE',
        );
        $statement->execute(['product_id' => $productId, 'warehouse_id' => $this->warehouseId]);
    }

    private function assertLedgerReconciles(int $productId): void
    {
        self::assertSame(
            $this->stockQuantity($productId, $this->warehouseId),
            (new MysqlStockLedgerRepository($this->database))->sumQuantity($productId, $this->warehouseId),
            'SUM(stock_ledger.quantity) harus sama dengan product_stock.quantity',
        );
    }

    /** @return array<string, string> */
    private function input(string $counted, string $expected): array
    {
        return [
            'warehouse_id'      => (string) $this->warehouseId,
            'counted_quantity'  => $counted,
            'expected_quantity' => $expected,
            'note'              => 'Concurrency fixture count',
        ];
    }

    private function serviceOn(Database $database): StockService
    {
        return new StockService(
            new MysqlSalesOrderRepository($database),
            new MysqlPurchaseOrderRepository($database),
            new MysqlProductStockRepository($database),
            new MysqlStockLedgerRepository($database),
            new MysqlProductRepository($database),
            new MysqlWarehouseRepository($database),
            $database,
        );
    }
}
