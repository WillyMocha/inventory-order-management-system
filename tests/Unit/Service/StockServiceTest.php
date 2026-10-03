<?php

declare(strict_types=1);

namespace Tests\Unit\Service;

use App\Entity\Enum\MovementType;
use App\Entity\Enum\ReferenceType;
use App\Entity\Enum\PurchaseOrderStatus;
use App\Entity\Enum\Role;
use App\Entity\Enum\SalesOrderStatus;
use App\Entity\Product;
use App\Entity\PurchaseOrder;
use App\Entity\PurchaseOrderItem;
use App\Entity\SalesOrder;
use App\Entity\SalesOrderItem;
use App\Entity\User;
use App\Service\StockService;
use App\Support\Exception\DomainException;
use App\Support\Exception\NotFoundException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Tests\Unit\Fake\ImmediateTransactionRunner;
use Tests\Unit\Fake\InMemoryProductRepository;
use Tests\Unit\Fake\InMemoryProductStockRepository;
use Tests\Unit\Fake\InMemoryPurchaseOrderRepository;
use Tests\Unit\Fake\InMemorySalesOrderRepository;
use Tests\Unit\Fake\InMemoryStockLedgerRepository;
use Tests\Unit\Fake\InMemoryWarehouseRepository;

/**
 * Unit test StockService — goods issue (SO-01, FR-019 s/d FR-022).
 *
 * Ini menguji ATURAN-nya: order harus Approved, stock harus cukup, ledger
 * harus tertulis satu baris per line, dan tidak ada perubahan apa pun ketika
 * operasinya ditolak.
 *
 * Yang TIDAK diuji di sini adalah perilaku lock-nya sendiri — fake berjalan
 * single threaded sehingga tidak ada yang bisa dikunci. Pembuktian
 * SELECT ... FOR UPDATE dilakukan ConcurrentGoodsIssueTest dengan dua
 * connection MySQL nyata (ARCH-02).
 */
final class StockServiceTest extends TestCase
{
    private const int PRODUCT_A = 30;
    private const int PRODUCT_B = 31;
    private const int WAREHOUSE = 20;
    private const int SECOND_WAREHOUSE = 21;
    private const int SUPPLIER = 40;
    private const int CUSTOMER = 10;

    private InMemorySalesOrderRepository $orders;
    private InMemoryPurchaseOrderRepository $purchaseOrders;

    /** Item id dibuat manual karena fake PO tidak menomori item sendiri. */
    private int $nextPurchaseItemId = 500;
    private InMemoryProductStockRepository $stocks;
    private InMemoryStockLedgerRepository $ledger;
    private ImmediateTransactionRunner $transactions;
    private StockService $service;

    private User $warehouseStaff;
    private User $salesCreator;

    protected function setUp(): void
    {
        parent::setUp();

        $this->salesCreator = new User(3, 'Sales One', 'sales1@ioms.test', 'hash', Role::Sales, true);
        $this->warehouseStaff = new User(5, 'Warehouse One', 'wh1@ioms.test', 'hash', Role::WarehouseStaff, true);

        $this->orders = new InMemorySalesOrderRepository();
        $this->purchaseOrders = new InMemoryPurchaseOrderRepository();

        // Stock awal: 10 unit product A, 4 unit product B, di satu warehouse.
        $this->stocks = new InMemoryProductStockRepository(
            [
                self::PRODUCT_A . ':' . self::WAREHOUSE => 10,
                self::PRODUCT_B . ':' . self::WAREHOUSE => 4,
            ],
            [self::WAREHOUSE => 'Main Warehouse', self::SECOND_WAREHOUSE => 'Second Warehouse'],
        );

        $this->ledger = new InMemoryStockLedgerRepository();
        $this->transactions = new ImmediateTransactionRunner();

        $this->service = new StockService(
            $this->orders,
            $this->purchaseOrders,
            $this->stocks,
            $this->ledger,
            new InMemoryProductRepository([
                new Product(self::PRODUCT_A, 'SKU-A', 'Product A', 1, 'pcs', '1000.00', '1500.00', 5, null, true),
                new Product(self::PRODUCT_B, 'SKU-B', 'Product B', 1, 'pcs', '2000.00', '2500.00', 5, null, true),
            ]),
            new InMemoryWarehouseRepository(),
            $this->transactions,
        );
    }

    // ---------------------------------------------------- preconditions

    #[Test]
    public function refusesAnOrderThatIsNotApproved(): void
    {
        foreach (
            [
                SalesOrderStatus::Draft,
                SalesOrderStatus::PendingApproval,
                SalesOrderStatus::Fulfilled,
                SalesOrderStatus::Cancelled,
            ] as $status
        ) {
            $id = $this->seedOrder($status, [[self::PRODUCT_A, 2]]);

            try {
                $this->service->issueGoods($id, $this->warehouseStaff);
                self::fail('Order berstatus ' . $status->value . ' tidak boleh di-issue');
            } catch (DomainException) {
                // Tidak boleh ada efek apa pun.
                self::assertSame(10, $this->availableA(), 'Stock berubah padahal operasi ditolak');
                self::assertSame(0, $this->ledger->count(), 'Ledger tertulis padahal operasi ditolak');
            }
        }
    }

    #[Test]
    public function refusesAQuantityExceedingAvailableStock(): void
    {
        // Tersedia 10, diminta 11.
        $id = $this->seedOrder(SalesOrderStatus::Approved, [[self::PRODUCT_A, 11]]);

        $this->expectException(DomainException::class);

        $this->service->issueGoods($id, $this->warehouseStaff);
    }

    #[Test]
    public function nothingChangesWhenTheQuantityIsInsufficient(): void
    {
        $id = $this->seedOrder(SalesOrderStatus::Approved, [[self::PRODUCT_A, 11]]);

        try {
            $this->service->issueGoods($id, $this->warehouseStaff);
        } catch (DomainException) {
            // diabaikan: yang diperiksa adalah efek sampingnya
        }

        self::assertSame(10, $this->availableA());
        self::assertSame(0, $this->ledger->count());
        self::assertSame(SalesOrderStatus::Approved, $this->statusOf($id));
    }

    #[Test]
    public function refusesTheWholeOrderWhenOnlyOneLineIsInsufficient(): void
    {
        // Line pertama cukup (2 dari 10), line kedua tidak (5 dari 4). Tidak
        // boleh ada partial issue: seluruh operasinya gagal (FR-019).
        $id = $this->seedOrder(SalesOrderStatus::Approved, [
            [self::PRODUCT_A, 2],
            [self::PRODUCT_B, 5],
        ]);

        try {
            $this->service->issueGoods($id, $this->warehouseStaff);
            self::fail('Order dengan satu line tidak cukup harus ditolak seluruhnya');
        } catch (DomainException) {
            self::assertSame(10, $this->availableA(), 'Line yang cukup pun tidak boleh dikurangi');
            self::assertSame(4, $this->availableB());
            self::assertSame(0, $this->ledger->count());
            self::assertSame(SalesOrderStatus::Approved, $this->statusOf($id));
        }
    }

    #[Test]
    public function refusesWhenTheProductHasNoStockRowInThatWarehouseAtAll(): void
    {
        $stocks = new InMemoryProductStockRepository([], [self::WAREHOUSE => 'Main Warehouse']);

        $service = new StockService(
            $this->orders,
            $this->purchaseOrders,
            $stocks,
            $this->ledger,
            new InMemoryProductRepository([
                new Product(self::PRODUCT_A, 'SKU-A', 'Product A', 1, 'pcs', '1000.00', '1500.00', 5, null, true),
            ]),
            new InMemoryWarehouseRepository(),
            $this->transactions,
        );

        $id = $this->seedOrder(SalesOrderStatus::Approved, [[self::PRODUCT_A, 1]]);

        $this->expectException(DomainException::class);

        $service->issueGoods($id, $this->warehouseStaff);
    }

    #[Test]
    public function refusesAnOrderThatDoesNotExist(): void
    {
        $this->expectException(NotFoundException::class);

        $this->service->issueGoods(999, $this->warehouseStaff);
    }

    #[Test]
    public function anExactlySufficientQuantityIsAllowed(): void
    {
        // Batas: tersedia 4, diminta 4. Boleh, dan menyisakan nol.
        $id = $this->seedOrder(SalesOrderStatus::Approved, [[self::PRODUCT_B, 4]]);

        $this->service->issueGoods($id, $this->warehouseStaff);

        self::assertSame(0, $this->availableB());
    }

    // ---------------------------------------------------------- effects

    #[Test]
    public function stockDecreasesByTheIssuedQuantity(): void
    {
        $id = $this->seedOrder(SalesOrderStatus::Approved, [
            [self::PRODUCT_A, 3],
            [self::PRODUCT_B, 1],
        ]);

        $this->service->issueGoods($id, $this->warehouseStaff);

        self::assertSame(7, $this->availableA());
        self::assertSame(3, $this->availableB());
    }

    #[Test]
    public function writesExactlyOneIssueLedgerRowPerLine(): void
    {
        $id = $this->seedOrder(SalesOrderStatus::Approved, [
            [self::PRODUCT_A, 3],
            [self::PRODUCT_B, 1],
        ]);

        $this->service->issueGoods($id, $this->warehouseStaff);

        $rows = $this->ledger->all();

        self::assertCount(2, $rows);

        foreach ($rows as $row) {
            self::assertSame(MovementType::Issue, $row->movementType);
            self::assertSame(ReferenceType::SalesOrder, $row->referenceType);
            self::assertSame($id, $row->referenceId, 'Ledger harus menyebut sales order sumbernya');
            self::assertSame(self::WAREHOUSE, $row->warehouseId);
            // Acting user, bukan pembuat order — yang mengeluarkan barang
            // adalah yang menekan tombolnya.
            self::assertSame($this->warehouseStaff->id, $row->performedBy);
        }
    }

    #[Test]
    public function issueLedgerQuantitiesAreNegative(): void
    {
        // Konvensi tanda: Receipt positif, Issue negatif, sehingga
        // SUM(quantity) dapat dibandingkan langsung dengan product_stock
        // (NFR-002).
        $id = $this->seedOrder(SalesOrderStatus::Approved, [[self::PRODUCT_A, 3]]);

        $this->service->issueGoods($id, $this->warehouseStaff);

        self::assertSame(-3, $this->ledger->all()[0]->quantity);
    }

    #[Test]
    public function theOrderBecomesFulfilled(): void
    {
        $id = $this->seedOrder(SalesOrderStatus::Approved, [[self::PRODUCT_A, 3]]);

        $this->service->issueGoods($id, $this->warehouseStaff);

        self::assertSame(SalesOrderStatus::Fulfilled, $this->statusOf($id));
    }

    #[Test]
    public function ledgerAndStockAgreeAfterTheIssue(): void
    {
        // Invariant NFR-002 pada tingkat unit: perubahan stock sama dengan
        // jumlah baris ledger yang ditulis operasi ini.
        $id = $this->seedOrder(SalesOrderStatus::Approved, [
            [self::PRODUCT_A, 3],
            [self::PRODUCT_B, 2],
        ]);

        $this->service->issueGoods($id, $this->warehouseStaff);

        self::assertSame(
            10 + $this->ledger->sumQuantity(self::PRODUCT_A, self::WAREHOUSE),
            $this->availableA(),
        );
        self::assertSame(
            4 + $this->ledger->sumQuantity(self::PRODUCT_B, self::WAREHOUSE),
            $this->availableB(),
        );
    }

    #[Test]
    public function aFulfilledOrderCannotBeIssuedASecondTime(): void
    {
        $id = $this->seedOrder(SalesOrderStatus::Approved, [[self::PRODUCT_A, 3]]);
        $this->service->issueGoods($id, $this->warehouseStaff);

        $this->expectException(DomainException::class);

        // Kalau ini lolos, stock akan berkurang dua kali untuk satu order.
        $this->service->issueGoods($id, $this->warehouseStaff);
    }

    // ------------------------------------------------------ transaction

    #[Test]
    public function theWholeIssueRunsInsideOneTransaction(): void
    {
        $id = $this->seedOrder(SalesOrderStatus::Approved, [
            [self::PRODUCT_A, 3],
            [self::PRODUCT_B, 1],
        ]);

        $this->service->issueGoods($id, $this->warehouseStaff);

        // Satu transaction untuk seluruh order, bukan satu per line — kalau
        // per line, kegagalan di line kedua meninggalkan line pertama
        // ter-commit (ARCH-02).
        self::assertSame(1, $this->transactions->wrapCount());
    }

    #[Test]
    public function aRefusalPropagatesSoTheTransactionRollsBack(): void
    {
        $id = $this->seedOrder(SalesOrderStatus::Approved, [[self::PRODUCT_A, 99]]);

        try {
            $this->service->issueGoods($id, $this->warehouseStaff);
        } catch (DomainException) {
            // diabaikan
        }

        self::assertSame(1, $this->transactions->failureCount());
    }

    #[Test]
    public function everyStockRowIsLockedBeforeBeingChanged(): void
    {
        $id = $this->seedOrder(SalesOrderStatus::Approved, [
            [self::PRODUCT_A, 3],
            [self::PRODUCT_B, 1],
        ]);

        $this->service->issueGoods($id, $this->warehouseStaff);

        // Dua line, dua baris stock, dua lock.
        self::assertSame(2, $this->stocks->lockCallCount());
    }

    #[Test]
    public function stockRowsAreLockedInAStableOrderToAvoidDeadlock(): void
    {
        // Dua order dengan line dalam urutan terbalik harus tetap mengunci
        // baris dalam urutan product_id yang sama (research R-002).
        $ascending = $this->service->lockOrderFor(
            $this->requireOrder($this->seedOrder(SalesOrderStatus::Approved, [
                [self::PRODUCT_A, 1],
                [self::PRODUCT_B, 1],
            ])),
        );

        $descending = $this->service->lockOrderFor(
            $this->requireOrder($this->seedOrder(SalesOrderStatus::Approved, [
                [self::PRODUCT_B, 1],
                [self::PRODUCT_A, 1],
            ])),
        );

        self::assertSame($ascending, $descending);
        self::assertSame(
            [[self::PRODUCT_A, self::WAREHOUSE], [self::PRODUCT_B, self::WAREHOUSE]],
            $ascending,
        );
    }

    #[Test]
    public function lockOrderIsNumericNotLexicographic(): void
    {
        // Sebagai string "30" mendahului "9". Urutan lock harus numerik,
        // sehingga product_id 9 terkunci SEBELUM 30 (research R-002).
        $order = $this->requireOrder($this->seedOrder(SalesOrderStatus::Approved, [
            [self::PRODUCT_A, 1],
            [9, 1],
        ]));

        self::assertSame(
            [[9, self::WAREHOUSE], [self::PRODUCT_A, self::WAREHOUSE]],
            $this->service->lockOrderFor($order),
        );
    }

    #[Test]
    public function repeatedLinesForTheSameProductAreLockedOnceAndSummed(): void
    {
        // Dua line product yang sama: 6 + 5 = 11 melebihi 10 yang tersedia.
        // Kalau tiap line diperiksa sendiri-sendiri, keduanya lolos dan
        // terjadi oversell.
        $id = $this->seedOrder(SalesOrderStatus::Approved, [
            [self::PRODUCT_A, 6],
            [self::PRODUCT_A, 5],
        ]);

        try {
            $this->service->issueGoods($id, $this->warehouseStaff);
            self::fail('Gabungan dua line melebihi stock, harus ditolak');
        } catch (DomainException) {
            self::assertSame(10, $this->availableA());
            self::assertSame(0, $this->ledger->count());
        }
    }


    // ----------------------------------------------------- goods receipt
    //
    // Sisi masuk dari stock (PO-01, FR-014, FR-015). Bedanya dengan issue:
    // receipt BOLEH sebagian, karena supplier memang dapat mengirim bertahap —
    // sedangkan penjualan tidak punya konsep partial issue.

    #[Test]
    public function receivingPartOfALineSetsPartiallyReceived(): void
    {
        // Diminta 10, diterima 4.
        $poId = $this->seedPurchaseOrder(PurchaseOrderStatus::Ordered, [[self::PRODUCT_A, 10]]);
        $itemId = $this->firstItemId($poId);

        $this->service->receiveGoods($poId, [$itemId => 4], $this->warehouseStaff);

        self::assertSame(PurchaseOrderStatus::PartiallyReceived, $this->poStatusOf($poId));
    }

    #[Test]
    public function theOutstandingQuantityRemainsRecordedAfterAPartialReceipt(): void
    {
        $poId = $this->seedPurchaseOrder(PurchaseOrderStatus::Ordered, [[self::PRODUCT_A, 10]]);
        $itemId = $this->firstItemId($poId);

        $this->service->receiveGoods($poId, [$itemId => 4], $this->warehouseStaff);

        $item = $this->firstItem($poId);

        self::assertSame(4, $item->receivedQuantity);
        self::assertSame(6, $item->outstandingQuantity(), 'Sisa yang belum datang harus tetap tercatat');
        self::assertFalse($item->isFullyReceived());
    }

    #[Test]
    public function receivingTheRemainderReachesReceived(): void
    {
        $poId = $this->seedPurchaseOrder(PurchaseOrderStatus::Ordered, [[self::PRODUCT_A, 10]]);
        $itemId = $this->firstItemId($poId);

        $this->service->receiveGoods($poId, [$itemId => 4], $this->warehouseStaff);
        $this->service->receiveGoods($poId, [$itemId => 6], $this->warehouseStaff);

        self::assertSame(PurchaseOrderStatus::Received, $this->poStatusOf($poId));
        self::assertSame(0, $this->firstItem($poId)->outstandingQuantity());
    }

    #[Test]
    public function receivingEveryLineInOneGoReachesReceivedImmediately(): void
    {
        $poId = $this->seedPurchaseOrder(PurchaseOrderStatus::Ordered, [
            [self::PRODUCT_A, 10],
            [self::PRODUCT_B, 4],
        ]);
        $items = $this->itemIds($poId);

        $this->service->receiveGoods($poId, [$items[0] => 10, $items[1] => 4], $this->warehouseStaff);

        self::assertSame(PurchaseOrderStatus::Received, $this->poStatusOf($poId));
    }

    #[Test]
    public function anOrderWithOneLineStillOutstandingIsOnlyPartiallyReceived(): void
    {
        $poId = $this->seedPurchaseOrder(PurchaseOrderStatus::Ordered, [
            [self::PRODUCT_A, 10],
            [self::PRODUCT_B, 4],
        ]);
        $items = $this->itemIds($poId);

        // Line pertama penuh, line kedua belum disentuh.
        $this->service->receiveGoods($poId, [$items[0] => 10], $this->warehouseStaff);

        self::assertSame(PurchaseOrderStatus::PartiallyReceived, $this->poStatusOf($poId));
    }

    #[Test]
    public function refusesAQuantityAboveTheOutstandingAmount(): void
    {
        // Diminta 10, dicoba terima 11 (spec A-005).
        $poId = $this->seedPurchaseOrder(PurchaseOrderStatus::Ordered, [[self::PRODUCT_A, 10]]);
        $itemId = $this->firstItemId($poId);

        $this->expectException(DomainException::class);

        $this->service->receiveGoods($poId, [$itemId => 11], $this->warehouseStaff);
    }

    #[Test]
    public function nothingChangesWhenAReceiptIsRefusedForOverReceipt(): void
    {
        $poId = $this->seedPurchaseOrder(PurchaseOrderStatus::Ordered, [[self::PRODUCT_A, 10]]);
        $itemId = $this->firstItemId($poId);

        try {
            $this->service->receiveGoods($poId, [$itemId => 11], $this->warehouseStaff);
        } catch (DomainException) {
            // diabaikan: yang diperiksa adalah efek sampingnya
        }

        self::assertSame(10, $this->availableA(), 'Stock tidak boleh berubah');
        self::assertSame(0, $this->ledger->count(), 'Ledger tidak boleh tertulis');
        self::assertSame(0, $this->firstItem($poId)->receivedQuantity);
        self::assertSame(PurchaseOrderStatus::Ordered, $this->poStatusOf($poId));
    }

    #[Test]
    public function refusesAQuantityAboveTheREMAININGOutstandingAfterAPartialReceipt(): void
    {
        // Sisa 6 setelah menerima 4; mencoba 7 harus ditolak.
        $poId = $this->seedPurchaseOrder(PurchaseOrderStatus::Ordered, [[self::PRODUCT_A, 10]]);
        $itemId = $this->firstItemId($poId);

        $this->service->receiveGoods($poId, [$itemId => 4], $this->warehouseStaff);

        try {
            $this->service->receiveGoods($poId, [$itemId => 7], $this->warehouseStaff);
            self::fail('7 melebihi sisa 6, harus ditolak');
        } catch (DomainException) {
            self::assertSame(4, $this->firstItem($poId)->receivedQuantity);
            self::assertSame(14, $this->availableA(), 'Hanya receipt pertama yang boleh terhitung');
        }
    }

    #[Test]
    public function refusesANonPositiveQuantity(): void
    {
        $poId = $this->seedPurchaseOrder(PurchaseOrderStatus::Ordered, [[self::PRODUCT_A, 10]]);
        $itemId = $this->firstItemId($poId);

        foreach ([0, -3] as $quantity) {
            try {
                $this->service->receiveGoods($poId, [$itemId => $quantity], $this->warehouseStaff);
                self::fail('Quantity ' . $quantity . ' seharusnya ditolak');
            } catch (DomainException) {
                self::assertSame(10, $this->availableA());
            }
        }
    }

    #[Test]
    public function refusesAReceiptWithNoLinesAtAll(): void
    {
        $poId = $this->seedPurchaseOrder(PurchaseOrderStatus::Ordered, [[self::PRODUCT_A, 10]]);

        $this->expectException(DomainException::class);

        $this->service->receiveGoods($poId, [], $this->warehouseStaff);
    }

    #[Test]
    public function refusesAnItemThatBelongsToADifferentOrder(): void
    {
        // Item id dari order lain tidak boleh dapat dipakai untuk menambah
        // stock pada order ini.
        $poId = $this->seedPurchaseOrder(PurchaseOrderStatus::Ordered, [[self::PRODUCT_A, 10]]);
        $otherPoId = $this->seedPurchaseOrder(PurchaseOrderStatus::Ordered, [[self::PRODUCT_B, 5]]);
        $foreignItemId = $this->firstItemId($otherPoId);

        try {
            $this->service->receiveGoods($poId, [$foreignItemId => 1], $this->warehouseStaff);
            self::fail('Item milik order lain harus ditolak');
        } catch (DomainException) {
            self::assertSame(0, $this->ledger->count());
            self::assertSame(4, $this->availableB(), 'Stock product order lain tidak boleh tersentuh');
        }
    }

    #[Test]
    public function refusesAnOrderNotInAReceivableStatus(): void
    {
        foreach (
            [
                PurchaseOrderStatus::Draft,
                PurchaseOrderStatus::Received,
                PurchaseOrderStatus::Cancelled,
            ] as $status
        ) {
            $poId = $this->seedPurchaseOrder($status, [[self::PRODUCT_A, 10]]);
            $itemId = $this->firstItemId($poId);

            try {
                $this->service->receiveGoods($poId, [$itemId => 1], $this->warehouseStaff);
                self::fail('Order berstatus ' . $status->value . ' tidak boleh menerima barang');
            } catch (DomainException) {
                self::assertSame(10, $this->availableA());
                self::assertSame(0, $this->ledger->count());
            }
        }
    }

    #[Test]
    public function refusesAPurchaseOrderThatDoesNotExist(): void
    {
        $this->expectException(NotFoundException::class);

        $this->service->receiveGoods(999, [1 => 1], $this->warehouseStaff);
    }

    #[Test]
    public function stockRisesByExactlyTheReceivedQuantity(): void
    {
        $poId = $this->seedPurchaseOrder(PurchaseOrderStatus::Ordered, [
            [self::PRODUCT_A, 10],
            [self::PRODUCT_B, 4],
        ]);
        $items = $this->itemIds($poId);

        $this->service->receiveGoods($poId, [$items[0] => 6, $items[1] => 2], $this->warehouseStaff);

        // Awal 10 dan 4.
        self::assertSame(16, $this->availableA());
        self::assertSame(6, $this->availableB());
    }

    #[Test]
    public function writesExactlyOneReceiptLedgerRowPerReceivedLine(): void
    {
        $poId = $this->seedPurchaseOrder(PurchaseOrderStatus::Ordered, [
            [self::PRODUCT_A, 10],
            [self::PRODUCT_B, 4],
        ]);
        $items = $this->itemIds($poId);

        $this->service->receiveGoods($poId, [$items[0] => 6, $items[1] => 2], $this->warehouseStaff);

        $rows = $this->ledger->all();

        self::assertCount(2, $rows);

        foreach ($rows as $row) {
            self::assertSame(MovementType::Receipt, $row->movementType);
            self::assertSame(ReferenceType::PurchaseOrder, $row->referenceType);
            self::assertSame($poId, $row->referenceId, 'Ledger harus menyebut purchase order sumbernya');
            self::assertSame(self::WAREHOUSE, $row->warehouseId, 'Warehouse tujuan diambil dari header PO');
            self::assertSame($this->warehouseStaff->id, $row->performedBy);
            self::assertGreaterThan(0, $row->quantity, 'Quantity Receipt harus positif');
        }
    }

    #[Test]
    public function aLineNotBeingReceivedGetsNoLedgerRow(): void
    {
        $poId = $this->seedPurchaseOrder(PurchaseOrderStatus::Ordered, [
            [self::PRODUCT_A, 10],
            [self::PRODUCT_B, 4],
        ]);
        $items = $this->itemIds($poId);

        $this->service->receiveGoods($poId, [$items[0] => 6], $this->warehouseStaff);

        self::assertCount(1, $this->ledger->all());
        self::assertSame(4, $this->availableB(), 'Line yang tidak diterima tidak boleh mengubah stock');
    }

    #[Test]
    public function ledgerAndStockAgreeAfterTheReceipt(): void
    {
        $poId = $this->seedPurchaseOrder(PurchaseOrderStatus::Ordered, [[self::PRODUCT_A, 10]]);
        $itemId = $this->firstItemId($poId);

        $this->service->receiveGoods($poId, [$itemId => 6], $this->warehouseStaff);

        // Invariant NFR-002 diukur sebagai delta dari stock awal 10.
        self::assertSame(
            10 + $this->ledger->sumQuantity(self::PRODUCT_A, self::WAREHOUSE),
            $this->availableA(),
        );
    }

    #[Test]
    public function aReceiptAndAnIssueReconcileTogether(): void
    {
        // Rangkaian campuran pada satu pasangan (product, warehouse):
        // mulai 10, terima 6, keluarkan 4 -> 12, dan ledger menjelaskan +2.
        $poId = $this->seedPurchaseOrder(PurchaseOrderStatus::Ordered, [[self::PRODUCT_A, 10]]);
        $this->service->receiveGoods($poId, [$this->firstItemId($poId) => 6], $this->warehouseStaff);

        $soId = $this->seedOrder(SalesOrderStatus::Approved, [[self::PRODUCT_A, 4]]);
        $this->service->issueGoods($soId, $this->warehouseStaff);

        self::assertSame(12, $this->availableA());
        self::assertSame(2, $this->ledger->sumQuantity(self::PRODUCT_A, self::WAREHOUSE));
        self::assertSame(
            10 + $this->ledger->sumQuantity(self::PRODUCT_A, self::WAREHOUSE),
            $this->availableA(),
        );
    }

    #[Test]
    public function theWholeReceiptRunsInsideOneTransaction(): void
    {
        $poId = $this->seedPurchaseOrder(PurchaseOrderStatus::Ordered, [
            [self::PRODUCT_A, 10],
            [self::PRODUCT_B, 4],
        ]);
        $items = $this->itemIds($poId);

        $this->service->receiveGoods($poId, [$items[0] => 6, $items[1] => 2], $this->warehouseStaff);

        self::assertSame(1, $this->transactions->wrapCount());
    }

    #[Test]
    public function aRefusedReceiptPropagatesSoTheTransactionRollsBack(): void
    {
        $poId = $this->seedPurchaseOrder(PurchaseOrderStatus::Ordered, [[self::PRODUCT_A, 10]]);

        try {
            $this->service->receiveGoods($poId, [$this->firstItemId($poId) => 99], $this->warehouseStaff);
        } catch (DomainException) {
            // diabaikan
        }

        self::assertSame(1, $this->transactions->failureCount());
    }

    #[Test]
    public function aMultiLineReceiptIsAllOrNothing(): void
    {
        // Line pertama sah, line kedua melebihi outstanding. Tidak boleh ada
        // line yang tercatat.
        $poId = $this->seedPurchaseOrder(PurchaseOrderStatus::Ordered, [
            [self::PRODUCT_A, 10],
            [self::PRODUCT_B, 4],
        ]);
        $items = $this->itemIds($poId);

        try {
            $this->service->receiveGoods($poId, [$items[0] => 5, $items[1] => 9], $this->warehouseStaff);
            self::fail('Receipt dengan satu line melebihi outstanding harus ditolak seluruhnya');
        } catch (DomainException) {
            self::assertSame(10, $this->availableA(), 'Line yang sah pun tidak boleh ditambahkan');
            self::assertSame(4, $this->availableB());
            self::assertSame(0, $this->ledger->count());
            self::assertSame(PurchaseOrderStatus::Ordered, $this->poStatusOf($poId));
        }
    }

    #[Test]
    public function receivingIntoAWarehouseWithNoExistingStockRowCreatesIt(): void
    {
        // Product B belum pernah ada di warehouse kedua.
        $poId = $this->seedPurchaseOrder(
            PurchaseOrderStatus::Ordered,
            [[self::PRODUCT_B, 3]],
            self::SECOND_WAREHOUSE,
        );

        $this->service->receiveGoods($poId, [$this->firstItemId($poId) => 3], $this->warehouseStaff);

        self::assertSame(
            3,
            $this->stocks->findFor(self::PRODUCT_B, self::SECOND_WAREHOUSE)->quantity ?? 0,
        );
        // Warehouse asal tidak boleh ikut berubah.
        self::assertSame(4, $this->availableB());
    }

    // ----------------------------------------------------------- helpers

    /** @param list<array{0: int, 1: int}> $lines [productId, quantity] */
    private function seedOrder(SalesOrderStatus $status, array $lines): int
    {
        $items = [];

        foreach ($lines as $line) {
            $items[] = new SalesOrderItem(null, null, $line[0], $line[1], '1500.00');
        }

        return $this->orders->save(new SalesOrder(
            null,
            'SO-20260911-' . str_pad((string) ($this->orders->countBy([]) + 1), 4, '0', STR_PAD_LEFT),
            self::CUSTOMER,
            (int) $this->salesCreator->id,
            $status === SalesOrderStatus::Draft || $status === SalesOrderStatus::PendingApproval ? null : 1,
            self::WAREHOUSE,
            $status,
            '2026-09-11',
            $items,
        ));
    }

    private function requireOrder(int $id): SalesOrder
    {
        $order = $this->orders->findById($id);

        self::assertNotNull($order);

        return $order;
    }

    private function availableA(): int
    {
        return $this->stocks->findFor(self::PRODUCT_A, self::WAREHOUSE)->quantity ?? 0;
    }

    private function availableB(): int
    {
        return $this->stocks->findFor(self::PRODUCT_B, self::WAREHOUSE)->quantity ?? 0;
    }

    private function statusOf(int $id): ?SalesOrderStatus
    {
        return $this->orders->findById($id)?->status;
    }

    /**
     * @param list<array{0: int, 1: int}> $lines [productId, quantity]
     */
    private function seedPurchaseOrder(
        PurchaseOrderStatus $status,
        array $lines,
        int $warehouseId = self::WAREHOUSE,
    ): int {
        $items = [];
        $nextItemId = $this->nextPurchaseItemId;

        foreach ($lines as $line) {
            $items[] = new PurchaseOrderItem($nextItemId++, null, $line[0], $line[1], 0, '1000.00');
        }

        $this->nextPurchaseItemId = $nextItemId;

        return $this->purchaseOrders->save(new PurchaseOrder(
            null,
            'PO-20260911-' . str_pad((string) ($this->purchaseOrders->countBy([]) + 1), 4, '0', STR_PAD_LEFT),
            self::SUPPLIER,
            $warehouseId,
            $status,
            '2026-09-11',
            (int) $this->warehouseStaff->id,
            $items,
        ));
    }

    /** @return list<int> */
    private function itemIds(int $purchaseOrderId): array
    {
        $order = $this->purchaseOrders->findById($purchaseOrderId);

        self::assertNotNull($order);

        return array_map(static fn (PurchaseOrderItem $i): int => (int) $i->id, $order->items);
    }

    private function firstItemId(int $purchaseOrderId): int
    {
        return $this->itemIds($purchaseOrderId)[0];
    }

    private function firstItem(int $purchaseOrderId): PurchaseOrderItem
    {
        $order = $this->purchaseOrders->findById($purchaseOrderId);

        self::assertNotNull($order);

        return $order->items[0];
    }

    private function poStatusOf(int $purchaseOrderId): ?PurchaseOrderStatus
    {
        return $this->purchaseOrders->findById($purchaseOrderId)?->status;
    }
}
