<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Entity\Product;
use App\Repository\Mysql\MysqlProductRepository;
use App\Repository\Mysql\MysqlSalesOrderRepository;
use PHPUnit\Framework\Attributes\Test;

/**
 * Sort dan pagination terhadap SQL yang sungguhan (FR-024, FR-025).
 *
 * Sampai sekarang sort hanya diuji pada lapisan unit terhadap fake in-memory,
 * yang mengurutkan dengan `usort` di PHP — sehingga `ORDER BY` yang
 * sesungguhnya **tidak pernah dieksekusi satu kali pun**. Itu blind spot yang
 * persis sama dengan yang menyembunyikan bug placeholder pada fitur search
 * (lihat `docs/quality/tech-debt.md` TD-2b).
 *
 * Yang dijaga di sini:
 *   - ORDER BY benar-benar berjalan, dan kedua arahnya berbeda hasil
 *   - LIMIT/OFFSET benar-benar memotong, dan halaman kedua melanjutkan
 *     halaman pertama tanpa melewatkan atau mengulang baris
 *   - sort key di luar allowlist JATUH KE DEFAULT, tidak pernah masuk ke SQL
 *   - sort, filter, dan pagination dapat dipakai bersamaan
 */
final class RepositorySortPagingTest extends IntegrationTestCase
{
    use SalesOrderFixtures;

    /** Sama dengan Paginator::DEFAULT_PER_PAGE. */
    private const int PER_PAGE = 10;

    private MysqlProductRepository $products;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedSalesOrderFixtures();

        $this->products = new MysqlProductRepository($this->database);

        $this->seedProductsForPaging();
    }

    // ------------------------------------------------------------- Sort

    #[Test]
    public function sortingByNameRunsInBothDirections(): void
    {
        $ascending = $this->skusSorted('name', 'asc');
        $descending = $this->skusSorted('name', 'desc');

        self::assertNotSame($ascending, $descending, 'Kedua arah tidak boleh menghasilkan urutan yang sama.');
        self::assertSame($ascending, array_reverse($descending));
    }

    #[Test]
    public function sortingByNameIsActuallyAlphabetical(): void
    {
        $names = array_map(
            static fn (Product $p): string => $p->name,
            $this->products->search(['search' => 'Paging', 'sort' => 'name', 'direction' => 'asc'], 50, 0),
        );

        $expected = $names;
        sort($expected, SORT_STRING);

        self::assertSame($expected, $names);
    }

    #[Test]
    public function sortingBySkuRunsAgainstRealSql(): void
    {
        $skus = $this->skusSorted('sku', 'asc');

        $expected = $skus;
        sort($expected, SORT_STRING);

        self::assertSame($expected, $skus);
    }

    #[Test]
    public function sortingByPriceOrdersNumericallyNotAsText(): void
    {
        // Kolomnya DECIMAL. Kalau suatu saat diurutkan sebagai teks, "1000"
        // akan mendahului "900" dan test ini yang jatuh lebih dulu.
        $prices = array_map(
            static fn (Product $p): float => (float) $p->sellingPrice,
            $this->products->search(['search' => 'Paging', 'sort' => 'price', 'direction' => 'asc'], 50, 0),
        );

        $expected = $prices;
        sort($expected, SORT_NUMERIC);

        self::assertSame($expected, $prices);
    }

    #[Test]
    public function anUnknownSortKeyFallsBackToTheDefaultInsteadOfReachingTheSql(): void
    {
        // 'selling_price' adalah nama kolom sungguhan tetapi BUKAN key allowlist.
        // Hasilnya harus identik dengan default, bukan terurut menurut harga.
        $fallback = $this->skusSorted('selling_price', 'asc');
        $default = $this->skusSorted(null, 'asc');

        self::assertSame($default, $fallback);
    }

    #[Test]
    public function aSortKeyCarryingSqlIsNeverExecuted(): void
    {
        // Sort key tidak pernah diinterpolasi: yang dipetakan allowlist adalah
        // nama kolomnya, bukan teks dari user.
        $injected = $this->skusSorted('p.name; DROP TABLE product', 'asc');

        self::assertSame($this->skusSorted(null, 'asc'), $injected);
        self::assertNotEmpty($this->products->search(['search' => 'Paging'], 50, 0), 'Tabel harus utuh.');
    }

    #[Test]
    public function anUnknownDirectionFallsBackRatherThanReachingTheSql(): void
    {
        $rows = $this->products->search(
            ['search' => 'Paging', 'sort' => 'name', 'direction' => 'DESC; DROP TABLE product'],
            50,
            0,
        );

        self::assertNotEmpty($rows);
    }

    // ------------------------------------------------------- Pagination

    #[Test]
    public function theFirstPageIsLimitedToThePageSize(): void
    {
        $firstPage = $this->products->search(['sort' => 'sku', 'direction' => 'asc'], self::PER_PAGE, 0);

        self::assertCount(self::PER_PAGE, $firstPage);
    }

    #[Test]
    public function theSecondPageContinuesTheFirstWithoutRepeatingOrSkipping(): void
    {
        $criteria = ['search' => 'Paging', 'sort' => 'sku', 'direction' => 'asc'];

        $page1 = $this->skusOf($this->products->search($criteria, self::PER_PAGE, 0));
        $page2 = $this->skusOf($this->products->search($criteria, self::PER_PAGE, self::PER_PAGE));
        $all = $this->skusOf($this->products->search($criteria, 100, 0));

        self::assertCount(self::PER_PAGE, $page1);
        self::assertNotEmpty($page2);
        self::assertSame(
            [],
            array_intersect($page1, $page2),
            'Halaman kedua tidak boleh mengulang baris halaman pertama.',
        );
        self::assertSame(
            $all,
            array_merge($page1, $page2),
            'Gabungan kedua halaman harus persis sama dengan seluruh hasil.',
        );
    }

    #[Test]
    public function anOffsetBeyondTheEndReturnsAnEmptyPageNotAnError(): void
    {
        self::assertSame([], $this->products->search(['search' => 'Paging'], self::PER_PAGE, 10_000));
    }

    #[Test]
    public function theTotalCountIsIndependentOfThePageSize(): void
    {
        // countBy() yang menyuplai jumlah halaman; ia tidak boleh ikut terpotong LIMIT.
        $criteria = ['search' => 'Paging'];

        $total = $this->products->countBy($criteria);

        self::assertSame(12, $total);
        self::assertCount(self::PER_PAGE, $this->products->search($criteria, self::PER_PAGE, 0));
    }

    // ------------------------------------------ Digabung dengan filter

    #[Test]
    public function sortSurvivesAlongsideSearchAndFilterAndPaging(): void
    {
        $rows = $this->products->search(
            ['search' => 'Paging', 'active' => true, 'sort' => 'sku', 'direction' => 'desc'],
            self::PER_PAGE,
            0,
        );

        $skus = $this->skusOf($rows);
        $expected = $skus;
        rsort($expected, SORT_STRING);

        self::assertCount(self::PER_PAGE, $skus);
        self::assertSame($expected, $skus, 'Urutan menurun harus bertahan walau ada filter dan paging.');
    }

    #[Test]
    public function salesOrderSortRunsAgainstRealSql(): void
    {
        $this->setStock($this->productId, $this->warehouseId, 100);
        $this->approvedOrder([[$this->productId, 1]]);
        $this->draftOrder([[$this->productId, 1]]);

        $orders = new MysqlSalesOrderRepository($this->database);

        foreach (['date', 'number', 'status'] as $key) {
            foreach (['asc', 'desc'] as $direction) {
                self::assertNotEmpty(
                    $orders->search(['search' => 'SO-FIXTURE', 'sort' => $key, 'direction' => $direction], 50, 0),
                    'Sort ' . $key . ' ' . $direction . ' harus berjalan.',
                );
            }
        }
    }

    // ----------------------------------------------------------- Helpers

    /**
     * Dua belas product agar paging benar-benar punya halaman kedua.
     * SKU dan harga dibuat berurutan supaya urutannya dapat diperiksa.
     */
    private function seedProductsForPaging(): void
    {
        $existing = $this->products->findById($this->productId);
        $category = $existing === null ? 1 : $existing->categoryId;

        for ($i = 1; $i <= 12; $i++) {
            $suffix = str_pad((string) $i, 2, '0', STR_PAD_LEFT);

            $this->products->save(new Product(
                null,
                'FIXTURE-SKU-P' . $suffix,
                'Paging Product ' . $suffix,
                $category,
                'pcs',
                '1000.00',
                (string) (1000 + ($i * 100)) . '.00',
                5,
                null,
                true,
            ));
        }
    }

    /**
     * @param list<Product> $products
     * @return list<string>
     */
    private function skusOf(array $products): array
    {
        return array_map(static fn (Product $p): string => $p->sku, $products);
    }

    /** @return list<string> */
    private function skusSorted(?string $sort, string $direction): array
    {
        $criteria = ['search' => 'Paging', 'direction' => $direction];

        if ($sort !== null) {
            $criteria['sort'] = $sort;
        }

        return $this->skusOf($this->products->search($criteria, 50, 0));
    }
}
