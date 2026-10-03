<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Repository\Mysql\MysqlCustomerRepository;
use App\Repository\Mysql\MysqlProductRepository;
use App\Repository\Mysql\MysqlPurchaseOrderRepository;
use App\Repository\Mysql\MysqlSalesOrderRepository;
use App\Repository\Mysql\MysqlSupplierRepository;
use App\Repository\Mysql\MysqlUserRepository;
use PHPUnit\Framework\Attributes\Test;

/**
 * Regression test untuk pencarian (FR-024), terhadap SQL yang sungguhan.
 *
 * Bug yang dijaga di sini pernah mematikan **seluruh enam** fitur search
 * sekaligus: setiap klausa memakai satu nama placeholder DUA KALI —
 * `(name LIKE :search OR sku LIKE :search)` — sementara nilainya diikat sekali.
 * Dengan `ATTR_EMULATE_PREPARES = false`, PDO meneruskan statement apa adanya
 * ke MySQL yang tidak mengenal placeholder bernama berulang, sehingga setiap
 * pencarian berakhir `SQLSTATE[HY093]` dan halamannya membalas 500.
 *
 * Unit test TIDAK DAPAT menangkapnya: fake in-memory mengimplementasikan
 * search() dengan `str_contains` di PHP, sehingga SQL-nya tidak pernah
 * dieksekusi sama sekali. Inilah risiko yang dicatat ADR-001 — test double
 * yang benar secara tipe tetapi tidak menjalankan hal yang sesungguhnya.
 *
 * Karena itu setiap test di sini menjalankan query sungguhan, dan sengaja
 * menguji **kedua sisi klausa OR** — sisi kedua itulah yang dahulu menuntut
 * placeholder kembar.
 */
final class RepositorySearchTest extends IntegrationTestCase
{
    use SalesOrderFixtures;

    private const int LIMIT = 50;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedSalesOrderFixtures();
    }

    // ------------------------------------------------------------ Product

    #[Test]
    public function productSearchMatchesOnName(): void
    {
        $products = new MysqlProductRepository($this->database);

        $found = $products->search(['search' => 'Fixture Product One'], self::LIMIT, 0);

        self::assertNotEmpty($found);
        self::assertSame('FIXTURE-SKU-1', $found[0]->sku);
    }

    #[Test]
    public function productSearchMatchesOnSku(): void
    {
        // Sisi kedua klausa OR — inilah yang dahulu menuntut placeholder kembar.
        $products = new MysqlProductRepository($this->database);

        $found = $products->search(['search' => 'FIXTURE-SKU-2'], self::LIMIT, 0);

        self::assertCount(1, $found);
        self::assertSame('Fixture Product Two', $found[0]->name);
    }

    #[Test]
    public function productSearchIsAPartialMatch(): void
    {
        $products = new MysqlProductRepository($this->database);

        self::assertGreaterThanOrEqual(2, count($products->search(['search' => 'FIXTURE-SKU'], self::LIMIT, 0)));
    }

    #[Test]
    public function productCountAgreesWithTheSearchItself(): void
    {
        // countBy() memakai klausa yang sama untuk menghitung total pagination.
        // Kalau hanya search() yang diperbaiki, paging-nya yang akan meledak.
        $products = new MysqlProductRepository($this->database);
        $criteria = ['search' => 'FIXTURE-SKU'];

        self::assertSame(
            count($products->search($criteria, self::LIMIT, 0)),
            $products->countBy($criteria),
        );
    }

    #[Test]
    public function productSearchThatMatchesNothingReturnsAnEmptyList(): void
    {
        $products = new MysqlProductRepository($this->database);

        self::assertSame([], $products->search(['search' => 'ZZZ-TIDAK-ADA'], self::LIMIT, 0));
        self::assertSame(0, $products->countBy(['search' => 'ZZZ-TIDAK-ADA']));
    }

    // -------------------------------------------------------- Sales order

    #[Test]
    public function salesOrderSearchMatchesOnOrderNumber(): void
    {
        $this->setStock($this->productId, $this->warehouseId, 50);
        $this->approvedOrder([[$this->productId, 1]]);

        $orders = new MysqlSalesOrderRepository($this->database);

        self::assertNotEmpty($orders->search(['search' => 'SO-FIXTURE'], self::LIMIT, 0));
    }

    #[Test]
    public function salesOrderSearchMatchesOnCustomerName(): void
    {
        // Sisi kedua di sini berupa subquery ke tabel customer.
        $this->setStock($this->productId, $this->warehouseId, 50);
        $this->approvedOrder([[$this->productId, 1]]);

        $orders = new MysqlSalesOrderRepository($this->database);

        self::assertNotEmpty($orders->search(['search' => 'Fixture Customer'], self::LIMIT, 0));
    }

    #[Test]
    public function salesOrderCountAgreesWithTheSearchItself(): void
    {
        $this->setStock($this->productId, $this->warehouseId, 50);
        $this->approvedOrder([[$this->productId, 1]]);

        $orders = new MysqlSalesOrderRepository($this->database);
        $criteria = ['search' => 'Fixture Customer'];

        self::assertSame(
            count($orders->search($criteria, self::LIMIT, 0)),
            $orders->countBy($criteria),
        );
    }

    // ----------------------------------------------------- Purchase order

    #[Test]
    public function purchaseOrderSearchMatchesOnOrderNumber(): void
    {
        $this->orderedPurchaseOrder([[$this->productId, 3]]);

        $orders = new MysqlPurchaseOrderRepository($this->database);

        self::assertNotEmpty($orders->search(['search' => 'PO-FIXTURE'], self::LIMIT, 0));
    }

    #[Test]
    public function purchaseOrderSearchMatchesOnSupplierName(): void
    {
        $this->orderedPurchaseOrder([[$this->productId, 3]]);

        $orders = new MysqlPurchaseOrderRepository($this->database);

        self::assertNotEmpty($orders->search(['search' => 'Fixture Supplier'], self::LIMIT, 0));
    }

    // ------------------------------------------------- Customer, supplier

    #[Test]
    public function customerSearchMatchesOnNameAndOnContact(): void
    {
        $customers = new MysqlCustomerRepository($this->database);

        self::assertNotEmpty($customers->search(['search' => 'Fixture Customer'], self::LIMIT, 0));
        self::assertNotEmpty($customers->search(['search' => '0800'], self::LIMIT, 0));
    }

    #[Test]
    public function supplierSearchMatchesOnNameAndOnContact(): void
    {
        $suppliers = new MysqlSupplierRepository($this->database);

        self::assertNotEmpty($suppliers->search(['search' => 'Fixture Supplier'], self::LIMIT, 0));
        self::assertNotEmpty($suppliers->search(['search' => '0800'], self::LIMIT, 0));
    }

    // --------------------------------------------------------------- User

    #[Test]
    public function userSearchMatchesOnName(): void
    {
        $users = new MysqlUserRepository($this->database);

        self::assertNotEmpty($users->search(['search' => 'Fixture Admin'], self::LIMIT, 0));
    }

    #[Test]
    public function userSearchMatchesOnEmail(): void
    {
        $users = new MysqlUserRepository($this->database);

        $found = $users->search(['search' => 'fixture-wh@test'], self::LIMIT, 0);

        self::assertCount(1, $found);
        self::assertSame('Fixture Warehouse', $found[0]->name);
    }

    // ------------------------------------ Search digabung dengan filter

    #[Test]
    public function searchCombinesWithADropdownFilterRatherThanReplacingIt(): void
    {
        // Filter dropdown sudah berfungsi sebelum perbaikan ini; yang harus
        // dipastikan adalah keduanya tetap dapat dipakai BERSAMAAN.
        $products = new MysqlProductRepository($this->database);

        $both = $products->search(['search' => 'FIXTURE-SKU', 'active' => true], self::LIMIT, 0);

        self::assertGreaterThanOrEqual(2, count($both));

        foreach ($both as $product) {
            self::assertTrue($product->isActive);
        }
    }

    #[Test]
    public function aSearchTermContainingSqlSyntaxIsTreatedAsText(): void
    {
        // Nilainya selalu terikat sebagai parameter, tidak pernah
        // diinterpolasi — tanda kutip dan komentar SQL hanyalah teks.
        $products = new MysqlProductRepository($this->database);

        self::assertSame([], $products->search(["search" => "' OR 1=1 --"], self::LIMIT, 0));
        self::assertSame(0, $products->countBy(["search" => "'; DROP TABLE product; --"]));

        // Tabelnya masih ada, dan fixture-nya masih utuh.
        self::assertNotEmpty($products->search(['search' => 'FIXTURE-SKU'], self::LIMIT, 0));
    }
}
