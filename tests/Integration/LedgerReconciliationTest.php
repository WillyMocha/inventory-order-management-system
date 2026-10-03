<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Repository\Mysql\MysqlProductRepository;
use App\Repository\Mysql\MysqlProductStockRepository;
use App\Repository\Mysql\MysqlPurchaseOrderRepository;
use App\Repository\Mysql\MysqlSalesOrderRepository;
use App\Repository\Mysql\MysqlStockLedgerRepository;
use App\Service\StockService;
use App\Support\Exception\DomainException;
use PHPUnit\Framework\Attributes\Test;

/**
 * NFR-002 dan SC-004: rekonsiliasi ledger terhadap stock.
 *
 * Invariant yang diperiksa, untuk SETIAP pasangan (product, warehouse):
 *
 *   SUM(stock_ledger.quantity) = product_stock.quantity
 *
 * Diperiksa setelah rangkaian campuran receipt dan issue — termasuk yang
 * ditolak, karena operasi yang ditolak juga tidak boleh meninggalkan jejak
 * sebagian.
 *
 * Kedua sisi dijalankan lewat jalur aplikasi yang sesungguhnya: receipt
 * melalui StockService::receiveGoods() atas Purchase Order nyata, issue
 * melalui StockService::issueGoods() atas Sales Order nyata. Tidak ada baris
 * ledger yang ditulis tangan di sini — kalau ada, invariant-nya akan diuji
 * terhadap data buatan test dan bukan terhadap perilaku aplikasi.
 */
final class LedgerReconciliationTest extends IntegrationTestCase
{
    use SalesOrderFixtures;

    private StockService $service;
    private MysqlStockLedgerRepository $ledger;

    protected function setUp(): void
    {
        parent::setUp();

        $this->ledger = new MysqlStockLedgerRepository($this->database);

        $this->service = new StockService(
            new MysqlSalesOrderRepository($this->database),
            new MysqlPurchaseOrderRepository($this->database),
            new MysqlProductStockRepository($this->database),
            $this->ledger,
            new MysqlProductRepository($this->database),
            $this->database,
        );

        $this->seedSalesOrderFixtures();
    }

    #[Test]
    public function ledgerAndStockAgreeAfterAMixedSequenceOfReceiptsAndIssues(): void
    {
        // Receipt: +20 dan +5 untuk product satu, +10 untuk product dua.
        $this->recordReceipt($this->productId, 20);
        $this->recordReceipt($this->productId, 5);
        $this->recordReceipt($this->secondProductId, 10);

        // Issue: 4 dari product satu, lalu 3 dari masing-masing product.
        $this->issue([[$this->productId, 4]]);
        $this->issue([
            [$this->productId, 3],
            [$this->secondProductId, 3],
        ]);

        // 20 + 5 - 4 - 3 = 18, dan 10 - 3 = 7.
        self::assertSame(18, $this->stockQuantity($this->productId, $this->warehouseId));
        self::assertSame(7, $this->stockQuantity($this->secondProductId, $this->warehouseId));

        $this->assertEveryPairReconciles();
    }

    #[Test]
    public function ledgerAndStockStillAgreeWhenSomeIssuesWereRefused(): void
    {
        $this->recordReceipt($this->productId, 5);

        $this->issue([[$this->productId, 2]]);

        // Melebihi sisa — ditolak, dan tidak boleh menulis ledger apa pun.
        $this->attemptIssue([[$this->productId, 99]]);

        $this->issue([[$this->productId, 1]]);
        $this->attemptIssue([[$this->productId, 50]]);

        self::assertSame(2, $this->stockQuantity($this->productId, $this->warehouseId));

        $this->assertEveryPairReconciles();
    }

    #[Test]
    public function aRefusedMultiLineIssueLeavesNoPartialLedgerRow(): void
    {
        $this->recordReceipt($this->productId, 10);
        $this->recordReceipt($this->secondProductId, 1);

        $rowsBefore = $this->ledgerRowCount();

        // Line pertama cukup, line kedua tidak.
        $this->attemptIssue([
            [$this->productId, 2],
            [$this->secondProductId, 5],
        ]);

        self::assertSame(
            $rowsBefore,
            $this->ledgerRowCount(),
            'Operasi yang ditolak tidak boleh menulis satu baris ledger pun',
        );

        $this->assertEveryPairReconciles();
    }

    #[Test]
    public function theInvariantHoldsAcrossTwoWarehousesIndependently(): void
    {
        // Warehouse kedua, agar terbukti invariant-nya berlaku PER pasangan
        // dan bukan hanya pada totalnya.
        $secondWarehouseId = $this->createSecondWarehouse();

        $this->recordReceipt($this->productId, 8, $this->warehouseId);
        $this->recordReceipt($this->productId, 3, $secondWarehouseId);

        $this->issue([[$this->productId, 5]]);

        self::assertSame(3, $this->stockQuantity($this->productId, $this->warehouseId));
        self::assertSame(3, $this->stockQuantity($this->productId, $secondWarehouseId));

        $this->assertEveryPairReconciles();
    }

    #[Test]
    public function noLedgerRowIsEverUpdatedOrDeleted(): void
    {
        // Ledger bersifat append-only: jumlah barisnya hanya boleh bertambah.
        $this->recordReceipt($this->productId, 10);

        $afterReceipt = $this->ledgerRowCount();

        $this->issue([[$this->productId, 2]]);
        $afterIssue = $this->ledgerRowCount();

        self::assertGreaterThan($afterReceipt, $afterIssue);

        $this->attemptIssue([[$this->productId, 999]]);

        self::assertSame(
            $afterIssue,
            $this->ledgerRowCount(),
            'Penolakan tidak menambah baris, dan tidak boleh menghapus yang sudah ada',
        );
    }

    /**
     * Memeriksa invariant untuk SETIAP pasangan (product, warehouse) yang ada
     * di database — bukan hanya yang disentuh test ini.
     */
    private function assertEveryPairReconciles(): void
    {
        $rows = $this->pdo->query(
            'SELECT ps.product_id, ps.warehouse_id, ps.quantity,
                    COALESCE((
                        SELECT SUM(sl.quantity) FROM stock_ledger sl
                         WHERE sl.product_id = ps.product_id
                           AND sl.warehouse_id = ps.warehouse_id
                    ), 0) AS ledger_sum
               FROM product_stock ps',
        )->fetchAll();

        self::assertNotEmpty($rows, 'Tidak ada baris product_stock untuk direkonsiliasi');

        foreach ($rows as $row) {
            self::assertSame(
                (int) $row['quantity'],
                (int) $row['ledger_sum'],
                sprintf(
                    'Invariant NFR-002 dilanggar untuk product %d di warehouse %d: stock %d, SUM(ledger) %d',
                    (int) $row['product_id'],
                    (int) $row['warehouse_id'],
                    (int) $row['quantity'],
                    (int) $row['ledger_sum'],
                ),
            );
        }
    }

    /**
     * Mencatat receipt lewat jalur aplikasi: satu PO berstatus Ordered,
     * diterima penuh melalui StockService::receiveGoods().
     */
    private function recordReceipt(int $productId, int $quantity, ?int $warehouseId = null): void
    {
        $purchaseOrderId = $this->orderedPurchaseOrder([[$productId, $quantity]], $warehouseId);
        $itemId = $this->purchaseItemIds($purchaseOrderId)[0];

        $this->service->receiveGoods($purchaseOrderId, [$itemId => $quantity], $this->warehouseStaff);
    }

    /** @param list<array{0: int, 1: int}> $lines */
    private function issue(array $lines): void
    {
        $this->service->issueGoods($this->approvedOrder($lines), $this->warehouseStaff);
    }

    /**
     * Issue yang DIHARAPKAN ditolak.
     *
     * @param list<array{0: int, 1: int}> $lines
     */
    private function attemptIssue(array $lines): void
    {
        try {
            $this->service->issueGoods($this->approvedOrder($lines), $this->warehouseStaff);
            self::fail('Issue ini seharusnya ditolak karena stock tidak cukup');
        } catch (DomainException) {
            // diharapkan
        }
    }

    private function createSecondWarehouse(): int
    {
        return (new \App\Repository\Mysql\MysqlWarehouseRepository($this->database))->save(
            new \App\Entity\Warehouse(null, 'Fixture Warehouse Site Two', 'Surabaya', true),
        );
    }
}
