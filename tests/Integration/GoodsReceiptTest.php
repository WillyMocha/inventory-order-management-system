<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Entity\Enum\MovementType;
use App\Entity\Enum\PurchaseOrderStatus;
use App\Entity\Enum\ReferenceType;
use App\Entity\StockLedger;
use App\Repository\Mysql\MysqlProductRepository;
use App\Repository\Mysql\MysqlProductStockRepository;
use App\Repository\Mysql\MysqlPurchaseOrderRepository;
use App\Repository\Mysql\MysqlSalesOrderRepository;
use App\Repository\Mysql\MysqlStockLedgerRepository;
use App\Repository\Mysql\MysqlWarehouseRepository;
use App\Repository\StockLedgerRepositoryInterface;
use App\Service\StockService;
use App\Support\Exception\DomainException;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;

/**
 * FR-015 dan FR-022 terhadap MySQL nyata: goods receipt end-to-end.
 *
 * Dua hal yang dibuktikan di sini dan tidak dapat dibuktikan unit test:
 *   1. receipt benar-benar menaikkan product_stock dan menulis stock_ledger
 *      yang bersesuaian di database;
 *   2. kegagalan DI TENGAH operasi meninggalkan KEDUANYA tidak berubah —
 *      diuji dengan memaksa exception setelah baris ledger pertama ditulis,
 *      sehingga rollback transaction yang sesungguhnya yang teruji, bukan
 *      sekadar urutan pemanggilan.
 */
final class GoodsReceiptTest extends IntegrationTestCase
{
    use SalesOrderFixtures;

    private StockService $service;
    private MysqlPurchaseOrderRepository $orders;
    private MysqlStockLedgerRepository $ledger;

    /**
     * Test ini memeriksa ROLLBACK milik StockService DI DALAM pembungkus
     * transaction IntegrationTestCase.
     *
     * Transaction Service yang bersarang menjadi SAVEPOINT (TD-1), sehingga
     * kegagalan di tengah receipt benar-benar dibatalkan walaupun transaction
     * terluarnya milik harness. Dulu test ini harus mematikan pembungkusnya dan
     * membersihkan fixture manual; kini tidak perlu lagi.
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->orders = new MysqlPurchaseOrderRepository($this->database);
        $this->ledger = new MysqlStockLedgerRepository($this->database);

        $this->service = $this->serviceWithLedger($this->ledger);

        $this->seedSalesOrderFixtures();
    }

    // -------------------------------------------------------- happy path

    #[Test]
    public function aReceiptIncreasesProductStockEndToEnd(): void
    {
        $this->setStock($this->productId, $this->warehouseId, 10);

        $orderId = $this->orderedPurchaseOrder([[$this->productId, 8]]);
        $itemId = $this->purchaseItemIds($orderId)[0];

        $this->service->receiveGoods($orderId, [$itemId => 8], $this->warehouseStaff);

        self::assertSame(18, $this->stockQuantity($this->productId, $this->warehouseId));
        self::assertSame(PurchaseOrderStatus::Received, $this->orders->findById($orderId)?->status);
    }

    #[Test]
    public function theReceiptWritesMatchingStockLedgerRows(): void
    {
        $this->setStock($this->productId, $this->warehouseId, 0);
        $this->setStock($this->secondProductId, $this->warehouseId, 0);

        $orderId = $this->orderedPurchaseOrder([
            [$this->productId, 8],
            [$this->secondProductId, 3],
        ]);
        $items = $this->purchaseItemIds($orderId);

        $this->service->receiveGoods($orderId, [$items[0] => 8, $items[1] => 3], $this->warehouseStaff);

        $rows = $this->ledger->forReference(ReferenceType::PurchaseOrder, $orderId);

        self::assertCount(2, $rows);

        foreach ($rows as $row) {
            self::assertSame(MovementType::Receipt, $row->movementType);
            self::assertSame($this->warehouseId, $row->warehouseId);
            self::assertSame($this->warehouseStaff->id, $row->performedBy);
            self::assertGreaterThan(0, $row->quantity, 'Quantity Receipt harus positif');
        }

        // Stock dan ledger harus sepakat untuk setiap product.
        self::assertSame(8, $this->stockQuantity($this->productId, $this->warehouseId));
        self::assertSame(3, $this->stockQuantity($this->secondProductId, $this->warehouseId));
        self::assertSame(8, $this->ledger->sumQuantity($this->productId, $this->warehouseId));
        self::assertSame(3, $this->ledger->sumQuantity($this->secondProductId, $this->warehouseId));
    }

    #[Test]
    public function aPartialReceiptLeavesTheOrderPartiallyReceivedWithOutstandingTracked(): void
    {
        $this->setStock($this->productId, $this->warehouseId, 0);

        $orderId = $this->orderedPurchaseOrder([[$this->productId, 10]]);
        $itemId = $this->purchaseItemIds($orderId)[0];

        $this->service->receiveGoods($orderId, [$itemId => 4], $this->warehouseStaff);

        $order = $this->orders->findById($orderId);

        self::assertNotNull($order);
        self::assertSame(PurchaseOrderStatus::PartiallyReceived, $order->status);
        self::assertSame(4, $order->items[0]->receivedQuantity);
        self::assertSame(6, $order->items[0]->outstandingQuantity());
        self::assertSame(4, $this->stockQuantity($this->productId, $this->warehouseId));
    }

    #[Test]
    public function receivingTheRemainderCompletesTheOrder(): void
    {
        $this->setStock($this->productId, $this->warehouseId, 0);

        $orderId = $this->orderedPurchaseOrder([[$this->productId, 10]]);
        $itemId = $this->purchaseItemIds($orderId)[0];

        $this->service->receiveGoods($orderId, [$itemId => 4], $this->warehouseStaff);
        $this->service->receiveGoods($orderId, [$itemId => 6], $this->warehouseStaff);

        self::assertSame(PurchaseOrderStatus::Received, $this->orders->findById($orderId)?->status);
        self::assertSame(10, $this->stockQuantity($this->productId, $this->warehouseId));

        // Dua receipt, dua baris ledger — bukan satu yang di-update.
        self::assertCount(2, $this->ledger->forReference(ReferenceType::PurchaseOrder, $orderId));
    }

    #[Test]
    public function anOverReceiptIsRefusedAndChangesNothing(): void
    {
        $this->setStock($this->productId, $this->warehouseId, 5);

        $orderId = $this->orderedPurchaseOrder([[$this->productId, 10]]);
        $itemId = $this->purchaseItemIds($orderId)[0];

        $ledgerBefore = $this->ledgerRowCount();

        try {
            $this->service->receiveGoods($orderId, [$itemId => 11], $this->warehouseStaff);
            self::fail('Menerima 11 dari 10 harus ditolak');
        } catch (DomainException) {
            self::assertSame(5, $this->stockQuantity($this->productId, $this->warehouseId));
            self::assertSame($ledgerBefore, $this->ledgerRowCount());

            $order = $this->orders->findById($orderId);

            self::assertNotNull($order);
            self::assertSame(PurchaseOrderStatus::Ordered, $order->status);
            self::assertSame(0, $order->items[0]->receivedQuantity);
        }
    }

    // --------------------------------------- forced mid-operation failure

    #[Test]
    public function aFailureMidOperationLeavesNEITHERStockNorLedgerChanged(): void
    {
        // Inilah inti T082. Ledger dibungkus dekorator yang melempar exception
        // pada penulisan KEDUA, jadi kegagalannya terjadi setelah baris
        // pertama sudah ditulis dan stock pertama sudah bertambah — tepat di
        // tengah operasi. Rollback harus membatalkan keduanya.
        $this->setStock($this->productId, $this->warehouseId, 10);
        $this->setStock($this->secondProductId, $this->warehouseId, 20);

        $orderId = $this->orderedPurchaseOrder([
            [$this->productId, 5],
            [$this->secondProductId, 7],
        ]);
        $items = $this->purchaseItemIds($orderId);

        $failing = new FailOnSecondAppendLedger($this->ledger);
        $service = $this->serviceWithLedger($failing);

        $ledgerBefore = $this->ledgerRowCount();

        try {
            $service->receiveGoods($orderId, [$items[0] => 5, $items[1] => 7], $this->warehouseStaff);
            self::fail('Kegagalan yang dipaksakan seharusnya naik ke pemanggil');
        } catch (RuntimeException $e) {
            self::assertSame('Forced failure mid-receipt', $e->getMessage());
        }

        // Bukti bahwa kegagalannya benar-benar di TENGAH, bukan sebelum mulai.
        self::assertSame(
            2,
            $failing->appendCount(),
            'Dekorator harus sudah menulis satu baris lalu gagal di baris kedua',
        );

        // Dan tidak satu pun perubahan yang bertahan.
        self::assertSame(
            10,
            $this->stockQuantity($this->productId, $this->warehouseId),
            'Stock product pertama harus kembali ke nilai semula',
        );
        self::assertSame(20, $this->stockQuantity($this->secondProductId, $this->warehouseId));
        self::assertSame($ledgerBefore, $this->ledgerRowCount(), 'Tidak boleh ada baris ledger yang bertahan');

        $order = $this->orders->findById($orderId);

        self::assertNotNull($order);
        self::assertSame(PurchaseOrderStatus::Ordered, $order->status, 'Status order tidak boleh maju');
        self::assertSame(0, $order->items[0]->receivedQuantity, 'received_quantity harus ikut ter-rollback');
        self::assertSame(0, $order->items[1]->receivedQuantity);
    }

    #[Test]
    public function theOrderCanStillBeReceivedNormallyAfterAFailedAttempt(): void
    {
        // Rollback tidak boleh meninggalkan order dalam keadaan macet.
        $this->setStock($this->productId, $this->warehouseId, 0);

        $orderId = $this->orderedPurchaseOrder([
            [$this->productId, 5],
            [$this->secondProductId, 7],
        ]);
        $items = $this->purchaseItemIds($orderId);

        $failingService = $this->serviceWithLedger(new FailOnSecondAppendLedger($this->ledger));

        try {
            $failingService->receiveGoods($orderId, [$items[0] => 5, $items[1] => 7], $this->warehouseStaff);
        } catch (RuntimeException) {
            // diharapkan
        }

        // Sekarang dengan ledger normal.
        $this->service->receiveGoods($orderId, [$items[0] => 5, $items[1] => 7], $this->warehouseStaff);

        self::assertSame(5, $this->stockQuantity($this->productId, $this->warehouseId));
        self::assertSame(PurchaseOrderStatus::Received, $this->orders->findById($orderId)?->status);
    }

    private function serviceWithLedger(StockLedgerRepositoryInterface $ledger): StockService
    {
        return new StockService(
            new MysqlSalesOrderRepository($this->database),
            $this->orders,
            new MysqlProductStockRepository($this->database),
            $ledger,
            new MysqlProductRepository($this->database),
            new MysqlWarehouseRepository($this->database),
            $this->database,
        );
    }
}
