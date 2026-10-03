<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Entity\Enum\PurchaseOrderStatus;
use App\Entity\Enum\ReferenceType;
use App\Entity\Enum\SalesOrderStatus;
use App\Repository\Mysql\MysqlCustomerRepository;
use App\Repository\Mysql\MysqlProductRepository;
use App\Repository\Mysql\MysqlProductStockRepository;
use App\Repository\Mysql\MysqlPurchaseOrderRepository;
use App\Repository\Mysql\MysqlSalesOrderRepository;
use App\Repository\Mysql\MysqlStockLedgerRepository;
use App\Repository\Mysql\MysqlWarehouseRepository;
use App\Service\SalesOrderService;
use App\Service\StockService;
use App\Support\Database;
use App\Support\Exception\DomainException;
use App\Support\SystemClock;
use PDO;
use PDOException;
use PHPUnit\Framework\Attributes\Test;

/**
 * NFR-001 dan SC-003: oversell tidak mungkin terjadi (FR-021, FR-022).
 *
 * Ini test paling penting di seluruh suite, dan satu-satunya yang TIDAK boleh
 * dibungkus transaction milik test harness — karena yang diuji justru
 * perilaku transaction dan lock itu sendiri. Karena itu wrapsInTransaction()
 * dimatikan dan pembersihannya dilakukan manual.
 *
 * Skenarionya: satu unit stock, dua order yang masing-masing meminta satu
 * unit, dua connection MySQL yang benar-benar terpisah. Connection A memegang
 * lock, connection B terbukti MENUNGGU (dibuktikan lewat lock wait timeout,
 * bukan lewat sleep yang hasilnya kebetulan), lalu setelah A commit barulah B
 * membaca angka yang benar dan ditolak.
 *
 * Kalau `FOR UPDATE` atau transaction-nya dilepas, B akan membaca stock lama
 * dan keduanya berhasil — itulah oversell yang harus tidak dapat direproduksi.
 *
 * Tiga test terakhir memakai teknik "pembacaan basi": B membuka transaction
 * dan membaca order SEBELUM A commit. Pada REPEATABLE READ, snapshot B tetap
 * melihat status lama — persis keadaan request kedua yang datang bersamaan.
 * Yang diuji: keputusan B tidak boleh diambil dari snapshot itu, melainkan
 * dari pembacaan di bawah lock (FOR UPDATE / UPDATE ... WHERE status = ...).
 * Deterministik, tanpa thread dan tanpa sleep.
 */
final class ConcurrentGoodsIssueTest extends IntegrationTestCase
{
    use SalesOrderFixtures;

    /** Detik. Dibuat pendek agar bukti "B menunggu" tidak memperlambat suite. */
    private const int LOCK_WAIT_TIMEOUT = 2;

    private Database $connectionB;
    private PDO $pdoB;

    /**
     * Test ini mengelola transaction-nya sendiri — dua connection tidak dapat
     * saling melihat data yang belum commit, sehingga fixture harus benar-
     * benar ter-commit.
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

        // Connection B tidak boleh menunggu tanpa batas: dengan timeout pendek,
        // "B menunggu" menjadi fakta yang terukur.
        $this->pdoB->exec('SET SESSION innodb_lock_wait_timeout = ' . self::LOCK_WAIT_TIMEOUT);

        $this->cleanUpSalesOrderFixtures();
        $this->seedSalesOrderFixtures();
    }

    protected function tearDown(): void
    {
        // Rollback apa pun yang masih menggantung sebelum membersihkan, kalau
        // tidak DELETE-nya sendiri akan ikut menunggu lock.
        foreach ([$this->pdo, $this->pdoB] as $connection) {
            if ($connection->inTransaction()) {
                $connection->rollBack();
            }
        }

        $this->cleanUpSalesOrderFixtures();

        parent::tearDown();
    }

    #[Test]
    public function theSecondConnectionBlocksWhileTheFirstHoldsTheLock(): void
    {
        $this->setStock($this->productId, $this->warehouseId, 1);

        // Connection A mengunci baris dan MENAHANNYA.
        $this->pdo->beginTransaction();
        $this->lockRowOn($this->pdo);

        // Connection B mencoba mengunci baris yang sama.
        $this->pdoB->beginTransaction();

        $blocked = false;
        $startedAt = microtime(true);

        try {
            $this->lockRowOn($this->pdoB);
        } catch (PDOException $e) {
            // Lock wait timeout — inilah buktinya B benar-benar menunggu dan
            // tidak sekadar membaca angka lama.
            $blocked = true;
            self::assertStringContainsStringIgnoringCase('lock', $e->getMessage());
        }

        $waited = microtime(true) - $startedAt;

        $this->pdoB->rollBack();
        $this->pdo->rollBack();

        self::assertTrue(
            $blocked,
            'Connection B TIDAK menunggu — berarti FOR UPDATE tidak menahan baris, dan oversell mungkin terjadi',
        );
        self::assertGreaterThanOrEqual(
            self::LOCK_WAIT_TIMEOUT - 0.5,
            $waited,
            'B harus benar-benar menunggu sampai timeout, bukan gagal seketika',
        );
    }

    #[Test]
    public function twoConcurrentIssuesForOneUnitNeverOversell(): void
    {
        $this->setStock($this->productId, $this->warehouseId, 1);

        $orderA = $this->approvedOrder([[$this->productId, 1]]);
        $orderB = $this->approvedOrder([[$this->productId, 1]]);

        $serviceA = $this->serviceOn($this->database);
        $serviceB = $this->serviceOn($this->connectionB);

        // A berhasil dan commit: stock menjadi 0.
        $serviceA->issueGoods($orderA, $this->warehouseStaff);

        self::assertSame(0, $this->stockQuantity($this->productId, $this->warehouseId));

        // B sekarang membaca angka yang benar di bawah lock, dan harus ditolak.
        $refused = false;

        try {
            $serviceB->issueGoods($orderB, $this->warehouseStaff);
        } catch (DomainException) {
            $refused = true;
        }

        self::assertTrue($refused, 'Order kedua harus ditolak — hanya ada satu unit');

        // Tiga hal yang harus berlaku sekaligus.
        self::assertSame(
            0,
            $this->stockQuantity($this->productId, $this->warehouseId),
            'Stock harus tepat 0 dan tidak pernah negatif',
        );
        self::assertSame(
            SalesOrderStatus::Fulfilled,
            $this->orderStatus($orderA),
            'Order pertama harus Fulfilled',
        );
        self::assertSame(
            SalesOrderStatus::Approved,
            $this->orderStatus($orderB),
            'Order kedua harus tetap Approved, bukan Fulfilled',
        );
    }

    #[Test]
    public function noUpdateIsLostWhenBothConnectionsIssueDifferentUnits(): void
    {
        // Dua unit, dua order masing-masing satu unit: keduanya harus berhasil
        // dan hasil akhirnya tepat 0 — bukan 1, yang akan terjadi bila salah
        // satu update hilang karena read-modify-write tanpa lock.
        $this->setStock($this->productId, $this->warehouseId, 2);

        $orderA = $this->approvedOrder([[$this->productId, 1]]);
        $orderB = $this->approvedOrder([[$this->productId, 1]]);

        $this->serviceOn($this->database)->issueGoods($orderA, $this->warehouseStaff);
        $this->serviceOn($this->connectionB)->issueGoods($orderB, $this->warehouseStaff);

        self::assertSame(
            0,
            $this->stockQuantity($this->productId, $this->warehouseId),
            'Kedua pengurangan harus tercatat — tidak ada update yang hilang',
        );
        self::assertSame(SalesOrderStatus::Fulfilled, $this->orderStatus($orderA));
        self::assertSame(SalesOrderStatus::Fulfilled, $this->orderStatus($orderB));
    }

    #[Test]
    public function theLedgerStillReconcilesAfterTheContention(): void
    {
        // Invariant NFR-002 harus tetap berlaku justru setelah ada perebutan.
        $this->setStock($this->productId, $this->warehouseId, 2);

        $orderA = $this->approvedOrder([[$this->productId, 1]]);
        $orderB = $this->approvedOrder([[$this->productId, 2]]);

        $this->serviceOn($this->database)->issueGoods($orderA, $this->warehouseStaff);

        try {
            // Hanya sisa 1, diminta 2 — ditolak.
            $this->serviceOn($this->connectionB)->issueGoods($orderB, $this->warehouseStaff);
        } catch (DomainException) {
            // diharapkan
        }

        $ledger = new MysqlStockLedgerRepository($this->database);

        self::assertSame(
            $ledger->sumQuantity($this->productId, $this->warehouseId),
            $this->stockQuantity($this->productId, $this->warehouseId) - 2,
            'SUM(ledger) harus menjelaskan tepat selisih dari stock awal',
        );
    }

    #[Test]
    public function theSameOrderIsNeverIssuedTwiceFromAStaleRead(): void
    {
        // Stock cukup untuk DUA kali issue — jadi yang mencegah issue kedua
        // bukan kekurangan stock, melainkan status order yang dibaca ulang.
        $this->setStock($this->productId, $this->warehouseId, 2);
        $orderId = $this->approvedOrder([[$this->productId, 1]]);

        $this->beginStaleReadOnB($orderId, SalesOrderStatus::Approved);

        $this->serviceOn($this->database)->issueGoods($orderId, $this->warehouseStaff);

        $refused = false;

        try {
            $this->serviceOn($this->connectionB)->issueGoods($orderId, $this->warehouseStaff);
        } catch (DomainException) {
            $refused = true;
        }

        // Commit, bukan rollback: bila B sempat menulis, hasilnya harus
        // terlihat oleh assertion di bawah.
        $this->pdoB->commit();

        self::assertTrue($refused, 'Issue kedua untuk order yang sudah Fulfilled harus ditolak');
        self::assertSame(
            1,
            $this->stockQuantity($this->productId, $this->warehouseId),
            'Stock hanya boleh berkurang satu kali untuk satu order',
        );
        self::assertCount(
            1,
            (new MysqlStockLedgerRepository($this->database))->forReference(ReferenceType::SalesOrder, $orderId),
            'Satu order hanya boleh menghasilkan satu baris ledger Issue',
        );
    }

    #[Test]
    public function aCancelNeverOverwritesAnOrderFulfilledMeanwhile(): void
    {
        $this->setStock($this->productId, $this->warehouseId, 1);
        $orderId = $this->approvedOrder([[$this->productId, 1]]);

        $this->beginStaleReadOnB($orderId, SalesOrderStatus::Approved);

        $this->serviceOn($this->database)->issueGoods($orderId, $this->warehouseStaff);

        $refused = false;

        try {
            $this->salesOrderServiceOn($this->connectionB)->cancel($orderId, $this->admin);
        } catch (DomainException) {
            $refused = true;
        }

        $this->pdoB->commit();

        self::assertTrue($refused, 'Cancel yang membaca status lama harus ditolak');
        self::assertSame(
            SalesOrderStatus::Fulfilled,
            $this->orderStatus($orderId),
            'Order yang stock-nya sudah keluar tidak boleh tercatat Cancelled',
        );
    }

    #[Test]
    public function aSecondReceiptNeverPlansFromAStaleOutstanding(): void
    {
        $this->setStock($this->productId, $this->warehouseId, 0);
        $orderId = $this->orderedPurchaseOrder([[$this->productId, 5]]);
        $itemId = $this->purchaseItemIds($orderId)[0];

        // B membaca PO saat outstanding masih 5.
        $this->pdoB->beginTransaction();
        self::assertSame(
            PurchaseOrderStatus::Ordered,
            (new MysqlPurchaseOrderRepository($this->connectionB))->findById($orderId)?->status,
        );

        $this->serviceOn($this->database)->receiveGoods($orderId, [$itemId => 5], $this->warehouseStaff);

        // Tanpa lock, B merencanakan dari outstanding 5 yang sudah basi dan
        // baru dihentikan CHECK constraint sebagai PDOException (error 500).
        // Yang benar: ditolak dengan DomainException yang dapat ditampilkan.
        $refused = false;

        try {
            $this->serviceOn($this->connectionB)->receiveGoods($orderId, [$itemId => 5], $this->warehouseStaff);
        } catch (DomainException) {
            $refused = true;
        }

        $this->pdoB->commit();

        self::assertTrue($refused, 'Receipt kedua untuk PO yang sudah Received harus ditolak');
        self::assertSame(5, $this->stockQuantity($this->productId, $this->warehouseId));
        self::assertCount(
            1,
            (new MysqlStockLedgerRepository($this->database))->forReference(ReferenceType::PurchaseOrder, $orderId),
        );
    }

    /**
     * Membuka transaction pada B dan membaca order, sehingga snapshot B
     * terkunci pada status saat ini — sebelum A mengubahnya.
     */
    private function beginStaleReadOnB(int $orderId, SalesOrderStatus $expected): void
    {
        $this->pdoB->beginTransaction();

        self::assertSame(
            $expected,
            (new MysqlSalesOrderRepository($this->connectionB))->findById($orderId)?->status,
        );
    }

    private function salesOrderServiceOn(Database $database): SalesOrderService
    {
        return new SalesOrderService(
            new MysqlSalesOrderRepository($database),
            new MysqlCustomerRepository($database),
            new MysqlWarehouseRepository($database),
            new MysqlProductRepository($database),
            new SystemClock(),
        );
    }

    private function serviceOn(Database $database): StockService
    {
        return new StockService(
            new MysqlSalesOrderRepository($database),
            new MysqlPurchaseOrderRepository($database),
            new MysqlProductStockRepository($database),
            new MysqlStockLedgerRepository($database),
            new MysqlProductRepository($database),
            $database,
        );
    }

    private function lockRowOn(PDO $connection): void
    {
        $statement = $connection->prepare(
            'SELECT quantity FROM product_stock
              WHERE product_id = :product_id AND warehouse_id = :warehouse_id
              FOR UPDATE',
        );

        $statement->execute([
            'product_id'   => $this->productId,
            'warehouse_id' => $this->warehouseId,
        ]);

        $statement->fetchAll();
    }

    private function orderStatus(int $orderId): ?SalesOrderStatus
    {
        return (new MysqlSalesOrderRepository($this->database))->findById($orderId)?->status;
    }
}
