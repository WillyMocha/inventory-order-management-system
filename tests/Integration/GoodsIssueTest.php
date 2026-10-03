<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Entity\Enum\MovementType;
use App\Entity\Enum\ReferenceType;
use App\Entity\Enum\SalesOrderStatus;
use App\Repository\Mysql\MysqlProductRepository;
use App\Repository\Mysql\MysqlProductStockRepository;
use App\Repository\Mysql\MysqlPurchaseOrderRepository;
use App\Repository\Mysql\MysqlSalesOrderRepository;
use App\Repository\Mysql\MysqlStockLedgerRepository;
use App\Service\StockService;
use App\Support\Exception\DomainException;
use PHPUnit\Framework\Attributes\Test;

/**
 * FR-020 terhadap MySQL nyata: goods issue end-to-end.
 *
 * Order Approved di-issue, stock turun, ledger tertulis, order menjadi
 * Fulfilled — seluruhnya diperiksa dengan membaca ULANG dari database, bukan
 * dari state di memory.
 */
final class GoodsIssueTest extends IntegrationTestCase
{
    use SalesOrderFixtures;

    private StockService $service;
    private MysqlSalesOrderRepository $orders;
    private MysqlProductStockRepository $stocks;
    private MysqlStockLedgerRepository $ledger;

    protected function setUp(): void
    {
        parent::setUp();

        $this->orders = new MysqlSalesOrderRepository($this->database);
        $this->stocks = new MysqlProductStockRepository($this->database);
        $this->ledger = new MysqlStockLedgerRepository($this->database);

        $this->service = new StockService(
            $this->orders,
            new MysqlPurchaseOrderRepository($this->database),
            $this->stocks,
            $this->ledger,
            new MysqlProductRepository($this->database),
            $this->database,
        );

        $this->seedSalesOrderFixtures();
    }

    /**
     * Menaikkan stock lewat goods receipt yang sesungguhnya, sehingga ledger
     * dan product_stock berangkat dari keadaan yang konsisten.
     */
    private function receiveStock(int $productId, int $quantity): void
    {
        $purchaseOrderId = $this->orderedPurchaseOrder([[$productId, $quantity]]);
        $itemId = $this->purchaseItemIds($purchaseOrderId)[0];

        $this->service->receiveGoods($purchaseOrderId, [$itemId => $quantity], $this->warehouseStaff);
    }

    #[Test]
    public function anApprovedOrderIsIssuedAndStockFalls(): void
    {
        $this->setStock($this->productId, $this->warehouseId, 10);
        $orderId = $this->approvedOrder([[$this->productId, 4]]);

        $this->service->issueGoods($orderId, $this->warehouseStaff);

        self::assertSame(6, $this->stockQuantity($this->productId, $this->warehouseId));
        self::assertSame(SalesOrderStatus::Fulfilled, $this->orders->findById($orderId)?->status);
    }

    #[Test]
    public function oneIssueLedgerRowIsWrittenPerLine(): void
    {
        $this->setStock($this->productId, $this->warehouseId, 10);
        $this->setStock($this->secondProductId, $this->warehouseId, 10);

        $orderId = $this->approvedOrder([
            [$this->productId, 3],
            [$this->secondProductId, 2],
        ]);

        $this->service->issueGoods($orderId, $this->warehouseStaff);

        $rows = $this->ledger->forReference(ReferenceType::SalesOrder, $orderId);

        self::assertCount(2, $rows);

        foreach ($rows as $row) {
            self::assertSame(MovementType::Issue, $row->movementType);
            self::assertSame($this->warehouseId, $row->warehouseId);
            // Yang mengeluarkan barang adalah acting user, bukan pembuat order.
            self::assertSame($this->warehouseStaff->id, $row->performedBy);
            self::assertLessThan(0, $row->quantity, 'Quantity Issue harus negatif');
        }
    }

    #[Test]
    public function stockAndLedgerAgreeAfterTheIssue(): void
    {
        // Invariant NFR-002 pada satu pasangan (product, warehouse).
        //
        // Stock awal DIBENTUK LEWAT GOODS RECEIPT, bukan setStock(): invariant
        // ini berbunyi SUM(stock_ledger) = product_stock, sehingga stock yang
        // disuntikkan langsung tanpa baris ledger membuatnya mustahil terpenuhi
        // sejak awal. SalesOrderFixtures::setStock() menyatakan syarat ini pada
        // docblock-nya.
        $this->receiveStock($this->productId, 10);
        $orderId = $this->approvedOrder([[$this->productId, 4]]);

        $this->service->issueGoods($orderId, $this->warehouseStaff);

        self::assertSame(
            $this->ledger->sumQuantity($this->productId, $this->warehouseId),
            $this->stockQuantity($this->productId, $this->warehouseId),
        );
    }

    #[Test]
    public function anIssueExceedingStockChangesNothingAtAll(): void
    {
        $this->setStock($this->productId, $this->warehouseId, 3);
        $orderId = $this->approvedOrder([[$this->productId, 4]]);

        $ledgerCountBefore = $this->ledgerRowCount();

        try {
            $this->service->issueGoods($orderId, $this->warehouseStaff);
            self::fail('Issue melebihi stock harus ditolak');
        } catch (DomainException) {
            // Transaction harus sudah rollback: tidak ada yang berubah.
            self::assertSame(3, $this->stockQuantity($this->productId, $this->warehouseId));
            self::assertSame($ledgerCountBefore, $this->ledgerRowCount());
            self::assertSame(SalesOrderStatus::Approved, $this->orders->findById($orderId)?->status);
        }
    }

    #[Test]
    public function aMultiLineIssueIsAllOrNothing(): void
    {
        // Line pertama cukup, line kedua tidak. Tidak boleh ada satu pun line
        // yang ter-commit.
        $this->setStock($this->productId, $this->warehouseId, 10);
        $this->setStock($this->secondProductId, $this->warehouseId, 1);

        $orderId = $this->approvedOrder([
            [$this->productId, 2],
            [$this->secondProductId, 5],
        ]);

        $ledgerCountBefore = $this->ledgerRowCount();

        try {
            $this->service->issueGoods($orderId, $this->warehouseStaff);
            self::fail('Order dengan satu line tidak cukup harus ditolak seluruhnya');
        } catch (DomainException) {
            self::assertSame(
                10,
                $this->stockQuantity($this->productId, $this->warehouseId),
                'Line yang cukup pun tidak boleh dikurangi',
            );
            self::assertSame(1, $this->stockQuantity($this->secondProductId, $this->warehouseId));
            self::assertSame($ledgerCountBefore, $this->ledgerRowCount());
        }
    }

    #[Test]
    public function anOrderThatIsNotApprovedIsRefused(): void
    {
        $this->setStock($this->productId, $this->warehouseId, 10);
        $orderId = $this->draftOrder([[$this->productId, 1]]);

        $this->expectException(DomainException::class);

        $this->service->issueGoods($orderId, $this->warehouseStaff);
    }

    #[Test]
    public function theSameOrderCannotBeIssuedTwice(): void
    {
        $this->setStock($this->productId, $this->warehouseId, 10);
        $orderId = $this->approvedOrder([[$this->productId, 4]]);

        $this->service->issueGoods($orderId, $this->warehouseStaff);

        try {
            $this->service->issueGoods($orderId, $this->warehouseStaff);
            self::fail('Order Fulfilled tidak boleh di-issue ulang');
        } catch (DomainException) {
            // Stock hanya boleh turun sekali.
            self::assertSame(6, $this->stockQuantity($this->productId, $this->warehouseId));
        }
    }

    #[Test]
    public function stockNeverGoesNegative(): void
    {
        $this->setStock($this->productId, $this->warehouseId, 1);

        foreach ([[[$this->productId, 2]], [[$this->productId, 5]]] as $lines) {
            $orderId = $this->approvedOrder($lines);

            try {
                $this->service->issueGoods($orderId, $this->warehouseStaff);
            } catch (DomainException) {
                // diharapkan
            }

            self::assertGreaterThanOrEqual(
                0,
                $this->stockQuantity($this->productId, $this->warehouseId),
                'product_stock.quantity tidak boleh pernah negatif',
            );
        }
    }
}
