<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Entity\Enum\Role;
use App\Entity\Product;
use App\Entity\StockLedger;
use App\Entity\Warehouse;
use App\Repository\Mysql\MysqlCategoryRepository;
use App\Repository\Mysql\MysqlCustomerRepository;
use App\Repository\Mysql\MysqlProductRepository;
use App\Repository\Mysql\MysqlProductStockRepository;
use App\Repository\Mysql\MysqlPurchaseOrderRepository;
use App\Repository\Mysql\MysqlSalesOrderRepository;
use App\Repository\Mysql\MysqlStockLedgerRepository;
use App\Repository\Mysql\MysqlSupplierRepository;
use App\Repository\Mysql\MysqlUserRepository;
use App\Repository\Mysql\MysqlWarehouseRepository;
use App\Service\StockService;
use PHPUnit\Framework\Attributes\Test;

/**
 * Method repository yang SQL-nya belum pernah dieksekusi integration suite
 * (tech-debt TD-2b).
 *
 * Daftarnya diambil dari coverage integration suite (pcov), bukan ditebak:
 * method yang hanya dipanggil lewat fake in-memory di unit test, sehingga
 * MySQL belum pernah melihat query-nya. Dua bug produksi sebelumnya lolos
 * persis lewat celah ini. Setiap test di sini menjalankan query sungguhan dan
 * memeriksa hasilnya terhadap fixture, bukan sekadar "tidak error".
 */
final class RepositoryCoverageTest extends IntegrationTestCase
{
    use SalesOrderFixtures;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedSalesOrderFixtures();
    }

    // -------------------------------------------- countBy (SQL dinamis)

    #[Test]
    public function customerCountAgreesWithItsSearchAndActiveFilter(): void
    {
        $customers = new MysqlCustomerRepository($this->database);

        $this->assertCountMatchesSearch($customers, ['search' => 'Fixture Customer']);
        $this->assertCountMatchesSearch($customers, ['search' => 'Fixture Customer', 'active' => true]);
        self::assertSame(0, $customers->countBy(['search' => 'Fixture Customer', 'active' => false]));
    }

    #[Test]
    public function supplierCountAgreesWithItsSearchAndActiveFilter(): void
    {
        $suppliers = new MysqlSupplierRepository($this->database);

        $this->assertCountMatchesSearch($suppliers, ['search' => 'Fixture Supplier']);
        self::assertSame(0, $suppliers->countBy(['search' => 'Fixture Supplier', 'active' => false]));
    }

    #[Test]
    public function userCountAgreesWithItsSearchRoleAndActiveFilter(): void
    {
        $users = new MysqlUserRepository($this->database);

        $this->assertCountMatchesSearch($users, ['search' => 'fixture-']);
        self::assertSame(1, $users->countBy(['search' => 'fixture-', 'role' => Role::Sales->value]));
        self::assertSame(3, $users->countBy(['search' => 'fixture-', 'active' => true]));
    }

    #[Test]
    public function purchaseOrderCountAgreesWithItsSearchAndStatusFilter(): void
    {
        $this->orderedPurchaseOrder([[$this->productId, 2]]);
        $orders = new MysqlPurchaseOrderRepository($this->database);

        $this->assertCountMatchesSearch($orders, ['search' => 'PO-FIXTURE']);
        self::assertSame(1, $orders->countBy(['search' => 'PO-FIXTURE', 'status' => 'Ordered']));
        self::assertSame(0, $orders->countBy(['search' => 'PO-FIXTURE', 'status' => 'Received']));
    }

    // ------------------------------------ keberadaan dan keunikan record

    #[Test]
    public function existenceAndUniquenessChecksSeeTheFixtureRows(): void
    {
        $products = new MysqlProductRepository($this->database);
        $categoryId = $products->findById($this->productId)->categoryId ?? 0;
        $categories = new MysqlCategoryRepository($this->database);

        self::assertTrue($products->exists($this->productId));
        self::assertTrue($products->skuExists('FIXTURE-SKU-1'));
        // Saat mengedit product itu sendiri, SKU-nya bukan duplikat.
        self::assertFalse($products->skuExists('FIXTURE-SKU-1', $this->productId));

        self::assertTrue($categories->exists($categoryId));
        self::assertTrue($categories->nameExists('Fixture Category'));
        self::assertFalse($categories->nameExists('Fixture Category', $categoryId));
        self::assertSame('Fixture Category', $categories->findById($categoryId)?->name);

        self::assertTrue((new MysqlCustomerRepository($this->database))->exists($this->customerId));
        self::assertTrue((new MysqlSupplierRepository($this->database))->exists($this->supplierId));

        $users = new MysqlUserRepository($this->database);
        self::assertTrue($users->emailExists('fixture-sales@test'));
        self::assertFalse($users->emailExists('fixture-sales@test', (int) $this->salesCreator->id));

        $orderNumber = (new MysqlPurchaseOrderRepository($this->database))
            ->findById($this->orderedPurchaseOrder([[$this->productId, 1]]))->orderNumber ?? '';
        self::assertTrue((new MysqlPurchaseOrderRepository($this->database))->orderNumberExists($orderNumber));
        self::assertFalse((new MysqlSalesOrderRepository($this->database))->orderNumberExists('SO-NOPE-0000'));
    }

    /**
     * highestSkuSequence(): LIKE, SUBSTRING, dan REGEXP dieksekusi MySQL
     * sungguhan — hanya SKU `<prefix><digit>` yang dihitung, dan karakter
     * wildcard pada prefix tidak ikut mencocokkan.
     */
    #[Test]
    public function highestSkuSequenceReadsOnlyNumericSkusWithThePrefix(): void
    {
        $products = new MysqlProductRepository($this->database);
        $categoryId = $products->findById($this->productId)->categoryId ?? 0;

        self::assertSame(0, $products->highestSkuSequence('SKU-'), 'fixture memakai FIXTURE-SKU-*');

        foreach (['SKU-000041', 'SKU-000007', 'SKU-ABC', 'SKU-12X', 'SKUX000999'] as $sku) {
            $products->save(new Product(null, $sku, 'Sequence ' . $sku, $categoryId, 'pcs', '1.00', '1.00', 0, null, true));
        }

        self::assertSame(41, $products->highestSkuSequence('SKU-'));
        // "_" adalah wildcard LIKE; tanpa escape, "SKU_" akan cocok dengan "SKU-".
        self::assertSame(0, $products->highestSkuSequence('SKU_'));
    }

    // -------------------------------------------- daftar untuk dropdown

    #[Test]
    public function listsForDropdownsContainTheFixtureRowsAndHonourActiveState(): void
    {
        $customers = new MysqlCustomerRepository($this->database);
        $suppliers = new MysqlSupplierRepository($this->database);
        $warehouses = new MysqlWarehouseRepository($this->database);
        $products = new MysqlProductRepository($this->database);

        self::assertContains($this->customerId, $this->ids($customers->all()));
        self::assertContains($this->supplierId, $this->ids($suppliers->all()));
        self::assertContains($this->warehouseId, $this->ids($warehouses->all()));
        self::assertContains($this->productId, $this->ids($products->allActive()));
        self::assertNotEmpty((new MysqlCategoryRepository($this->database))->all());
        self::assertNotEmpty((new MysqlUserRepository($this->database))->all());

        // Dinonaktifkan: hilang dari pilihan form, tetap ada di daftar lengkap.
        $customers->setActive($this->customerId, false);
        $suppliers->setActive($this->supplierId, false);
        $warehouses->setActive($this->warehouseId, false);
        $products->setActive($this->productId, false);

        self::assertNotContains($this->customerId, $this->ids($customers->allActive()));
        self::assertNotContains($this->supplierId, $this->ids($suppliers->allActive()));
        self::assertNotContains($this->warehouseId, $this->ids($warehouses->allActive()));
        self::assertNotContains($this->productId, $this->ids($products->allActive()));
        self::assertContains($this->customerId, $this->ids($customers->all()));
        self::assertFalse($customers->findById($this->customerId)->isActive ?? true);
        self::assertFalse($suppliers->findById($this->supplierId)->isActive ?? true);
    }

    #[Test]
    public function aUserCanBeDeactivatedAndGivenANewPasswordHash(): void
    {
        $users = new MysqlUserRepository($this->database);
        $id = (int) $this->salesCreator->id;

        $users->setActive($id, false);
        $users->updatePasswordHash($id, 'new-hash-value');

        $user = $users->findById($id);

        self::assertNotNull($user);
        self::assertFalse($user->isActive);
        self::assertSame('new-hash-value', $user->passwordHash);
    }

    // ------------------------- referensi yang menghalangi hard delete

    #[Test]
    public function referenceChecksBecomeTrueOnceAnOrderUsesTheRecord(): void
    {
        $products = new MysqlProductRepository($this->database);
        $customers = new MysqlCustomerRepository($this->database);
        $suppliers = new MysqlSupplierRepository($this->database);

        self::assertFalse($products->isReferencedByOrder($this->secondProductId));
        self::assertFalse($customers->isReferencedByOrder($this->customerId));
        self::assertFalse($suppliers->isReferencedByOrder($this->supplierId));

        $this->approvedOrder([[$this->secondProductId, 1]]);
        $this->orderedPurchaseOrder([[$this->productId, 1]]);

        self::assertTrue($products->isReferencedByOrder($this->secondProductId), 'dipakai Sales Order');
        self::assertTrue($products->isReferencedByOrder($this->productId), 'dipakai Purchase Order');
        self::assertTrue($customers->isReferencedByOrder($this->customerId));
        self::assertTrue($suppliers->isReferencedByOrder($this->supplierId));
    }

    // --------------------------------------------------- image dan stock

    #[Test]
    public function theImagePathIsStoredAndCleared(): void
    {
        $products = new MysqlProductRepository($this->database);
        $name = str_repeat('c', 32) . '.png';

        $products->updateImagePath($this->productId, $name);
        self::assertSame($name, $products->findById($this->productId)?->imagePath);

        $products->updateImagePath($this->productId, null);
        self::assertNull($products->findById($this->productId)?->imagePath);
    }

    /**
     * ensureRow() (spec 003, research R-002): pasangan baru mendapat baris
     * bernilai 0, pasangan yang sudah ada tidak berubah sedikit pun.
     */
    #[Test]
    public function ensureRowCreatesAZeroRowOnlyWhenTheStockRowIsMissing(): void
    {
        $stocks = new MysqlProductStockRepository($this->database);
        $newWarehouseId = (new MysqlWarehouseRepository($this->database))
            ->save(new Warehouse(null, 'Fixture EnsureRow Warehouse', 'Bandung', true));

        self::assertNull($stocks->findFor($this->productId, $newWarehouseId));

        $stocks->ensureRow($this->productId, $newWarehouseId);
        self::assertSame(0, $stocks->findFor($this->productId, $newWarehouseId)?->quantity);

        $this->setStock($this->productId, $this->warehouseId, 7);
        $stocks->ensureRow($this->productId, $this->warehouseId);
        self::assertSame(7, $this->stockQuantity($this->productId, $this->warehouseId));
    }

    /** recentAdjustmentsForProduct() (spec 003): query window function dieksekusi MySQL. */
    #[Test]
    public function recentAdjustmentsAreReadFromMySql(): void
    {
        $ledger = new MysqlStockLedgerRepository($this->database);
        self::assertCount(0, $ledger->recentAdjustmentsForProduct($this->productId, 10));

        $ledger->append(StockLedger::adjustment(
            $this->productId,
            $this->warehouseId,
            4,
            'Fixture count',
            (int) $this->warehouseStaff->id,
        ));
        (new MysqlProductStockRepository($this->database))->adjust($this->productId, $this->warehouseId, 4);

        $recent = $ledger->recentAdjustmentsForProduct($this->productId, 10);
        self::assertCount(1, $recent);
        self::assertSame(4, $recent[0]['balanceAfter']);
        self::assertSame('Fixture count', $recent[0]['note']);
    }

    #[Test]
    public function stockTotalsAndLedgerHistoryReflectARealReceipt(): void
    {
        $orderId = $this->orderedPurchaseOrder([[$this->productId, 6]]);
        $itemId = $this->purchaseItemIds($orderId)[0];

        $ledger = new MysqlStockLedgerRepository($this->database);
        $stocks = new MysqlProductStockRepository($this->database);

        (new StockService(
            new MysqlSalesOrderRepository($this->database),
            new MysqlPurchaseOrderRepository($this->database),
            $stocks,
            $ledger,
            new MysqlProductRepository($this->database),
            new MysqlWarehouseRepository($this->database),
            $this->database,
        ))->receiveGoods($orderId, [$itemId => 6], $this->warehouseStaff);

        self::assertSame(6, $stocks->totalForProduct($this->productId));

        $history = $ledger->forProductAndWarehouse($this->productId, $this->warehouseId, 10);

        self::assertCount(1, $history);
        self::assertSame(6, $history[0]->quantity);
    }

    // ---------------------------------------------------------- helpers

    /**
     * @param MysqlCustomerRepository|MysqlSupplierRepository|MysqlUserRepository|MysqlPurchaseOrderRepository $repo
     * @param array<string, mixed> $criteria
     */
    private function assertCountMatchesSearch(object $repo, array $criteria): void
    {
        $count = $repo->countBy($criteria);

        self::assertGreaterThan(0, $count);
        self::assertSame(count($repo->search($criteria, 100, 0)), $count);
    }

    /**
     * @param list<object> $rows
     * @return list<int|null>
     */
    private function ids(array $rows): array
    {
        return array_map(static fn (object $row): ?int => property_exists($row, 'id') ? $row->id : null, $rows);
    }
}
