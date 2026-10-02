<?php

declare(strict_types=1);

namespace Tests\Unit\Service;

use App\Entity\Product;
use App\Service\ProductService;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Tests\Unit\Fake\InMemoryCategoryRepository;
use Tests\Unit\Fake\InMemoryProductRepository;
use Tests\Unit\Fake\InMemoryProductStockRepository;

/**
 * Unit test kriteria pencarian product (FIND-01, FR-024).
 *
 * Yang diuji adalah PERILAKU kriterianya — pencocokan sebagian pada nama dan
 * SKU, filter category, filter low stock, dan kombinasi ketiganya — terhadap
 * fake in-memory, tanpa database.
 *
 * Low stock sengaja diperiksa terhadap TOTAL seluruh warehouse, bukan per
 * warehouse (spec A-008). Itu sebabnya fake stock ikut diisi.
 */
final class ProductSearchTest extends TestCase
{
    private const int CATEGORY_TOOLS = 1;
    private const int CATEGORY_PARTS = 2;

    private const int WAREHOUSE_A = 20;
    private const int WAREHOUSE_B = 21;

    private InMemoryProductRepository $products;
    private ProductService $service;

    protected function setUp(): void
    {
        parent::setUp();

        // Katalog kecil yang sengaja dibuat tumpang tindih: "Widget" muncul
        // pada dua nama, dan satu SKU memuat kata "widget" juga.
        $this->products = new InMemoryProductRepository([
            // id, sku, name, categoryId, unit, purchase, selling, reorderPoint
            new Product(1, 'WID-001', 'Widget Small', self::CATEGORY_TOOLS, 'pcs', '1000.00', '1500.00', 5, null, true),
            new Product(
                2,
                'WID-002',
                'Widget Large',
                self::CATEGORY_TOOLS,
                'pcs',
                '2000.00',
                '2500.00',
                10,
                null,
                true,
            ),
            new Product(3, 'GEA-001', 'Gearbox', self::CATEGORY_PARTS, 'pcs', '3000.00', '3500.00', 4, null, true),
            new Product(4, 'BOL-WIDGET', 'Bolt', self::CATEGORY_PARTS, 'pcs', '100.00', '150.00', 50, null, true),
            new Product(
                5,
                'RET-001',
                'Retired Gear',
                self::CATEGORY_PARTS,
                'pcs',
                '500.00',
                '750.00',
                2,
                null,
                false,
            ),
        ]);

        // Total per product: 1 -> 2 (low), 2 -> 30 (normal), 3 -> 4 (low, batas
        // sama dengan reorder point), 4 -> 100 (normal), 5 -> 0 (low).
        foreach ([1 => 2, 2 => 30, 3 => 4, 4 => 100, 5 => 0] as $productId => $total) {
            $this->products->setTotalQuantity($productId, $total);
        }

        $this->service = new ProductService(
            $this->products,
            new InMemoryProductStockRepository(
                [
                    '1:' . self::WAREHOUSE_A => 2,
                    '2:' . self::WAREHOUSE_A => 20,
                    '2:' . self::WAREHOUSE_B => 10,
                ],
                [self::WAREHOUSE_A => 'Warehouse A', self::WAREHOUSE_B => 'Warehouse B'],
            ),
            new InMemoryCategoryRepository(),
        );
    }

    // ---------------------------------------------------- name / SKU match

    #[Test]
    public function aPartialNameMatches(): void
    {
        $names = $this->namesFrom(['search' => 'Widget']);

        self::assertSame(['Bolt', 'Widget Large', 'Widget Small'], $names);
    }

    #[Test]
    public function aPartialNameMatchIsCaseInsensitive(): void
    {
        self::assertSame($this->namesFrom(['search' => 'widget']), $this->namesFrom(['search' => 'WIDGET']));
        self::assertNotSame([], $this->namesFrom(['search' => 'wIdGeT']));
    }

    #[Test]
    public function aPartialSkuMatches(): void
    {
        // "WID-" hanya ada pada SKU, bukan pada nama mana pun.
        self::assertSame(['Widget Large', 'Widget Small'], $this->namesFrom(['search' => 'WID-']));
    }

    #[Test]
    public function nameAndSkuAreBothSearched(): void
    {
        // "Bolt" cocok lewat SKU-nya (BOL-WIDGET) saat mencari "widget", dan
        // lewat namanya saat mencari "bolt". Keduanya harus bekerja.
        self::assertContains('Bolt', $this->namesFrom(['search' => 'widget']));
        self::assertSame(['Bolt'], $this->namesFrom(['search' => 'bolt']));
    }

    #[Test]
    public function aMatchInTheMiddleOfATermIsFound(): void
    {
        // Pencocokan sebagian, bukan prefix saja.
        self::assertSame(['Gearbox'], $this->namesFrom(['search' => 'earbo']));
    }

    #[Test]
    public function aSearchThatMatchesNothingReturnsAnEmptyList(): void
    {
        self::assertSame([], $this->namesFrom(['search' => 'nonexistent']));
        self::assertSame(0, $this->service->count(['search' => 'nonexistent']));
    }

    // ----------------------------------------------------- category filter

    #[Test]
    public function theCategoryFilterNarrowsToThatCategory(): void
    {
        self::assertSame(['Widget Large', 'Widget Small'], $this->namesFrom(['categoryId' => self::CATEGORY_TOOLS]));
        self::assertSame(
            ['Bolt', 'Gearbox', 'Retired Gear'],
            $this->namesFrom(['categoryId' => self::CATEGORY_PARTS]),
        );
    }

    #[Test]
    public function anUnknownCategoryReturnsNothing(): void
    {
        self::assertSame([], $this->namesFrom(['categoryId' => 999]));
    }

    // ---------------------------------------------------- low-stock filter

    #[Test]
    public function theLowStockFilterFindsProductsAtOrBelowReorderPoint(): void
    {
        // 1 (2 <= 5), 3 (4 <= 4, batas), 5 (0 <= 2).
        self::assertSame(['Gearbox', 'Retired Gear', 'Widget Small'], $this->namesFrom(['lowStock' => true]));
    }

    #[Test]
    public function theBoundaryCaseCountsAsLowStock(): void
    {
        // Gearbox: total 4, reorder point 4. "at or below" harus menyertakan
        // kesamaan (spec A-008).
        self::assertContains('Gearbox', $this->namesFrom(['lowStock' => true]));
    }

    #[Test]
    public function theLowStockFilterCanBeInvertedToFindHealthyStock(): void
    {
        self::assertSame(['Bolt', 'Widget Large'], $this->namesFrom(['lowStock' => false]));
    }

    #[Test]
    public function lowStockIsJudgedOnTheTotalAcrossWarehousesNotPerWarehouse(): void
    {
        // Widget Large punya 20 di satu warehouse dan 10 di warehouse lain.
        // Per warehouse, 10 <= reorder point 10 akan tampak low; secara total
        // 30 > 10 jelas tidak. Totalnya yang menentukan.
        $breakdown = $this->service->stockBreakdown(2);

        self::assertCount(2, $breakdown['warehouses']);
        self::assertSame(30, $breakdown['total']);
        self::assertFalse($breakdown['isLowStock']);
        self::assertNotContains('Widget Large', $this->namesFrom(['lowStock' => true]));
    }

    // ------------------------------------------------------ active filter

    #[Test]
    public function theActiveFilterHidesDeactivatedProducts(): void
    {
        self::assertNotContains('Retired Gear', $this->namesFrom(['active' => true]));
        self::assertSame(['Retired Gear'], $this->namesFrom(['active' => false]));
    }

    // -------------------------------------------------------- combinations

    #[Test]
    public function searchAndCategoryCombine(): void
    {
        // "widget" cocok dengan tiga product; dibatasi ke Parts hanya Bolt
        // (lewat SKU BOL-WIDGET) yang tersisa.
        self::assertSame(
            ['Bolt'],
            $this->namesFrom(['search' => 'widget', 'categoryId' => self::CATEGORY_PARTS]),
        );
    }

    #[Test]
    public function searchAndLowStockCombine(): void
    {
        // Dari tiga hasil "widget", hanya Widget Small yang low stock.
        self::assertSame(['Widget Small'], $this->namesFrom(['search' => 'widget', 'lowStock' => true]));
    }

    #[Test]
    public function categoryAndLowStockCombine(): void
    {
        self::assertSame(
            ['Gearbox', 'Retired Gear'],
            $this->namesFrom(['categoryId' => self::CATEGORY_PARTS, 'lowStock' => true]),
        );
    }

    #[Test]
    public function allThreeCriteriaCombine(): void
    {
        self::assertSame(
            ['Gearbox'],
            $this->namesFrom([
                'search'     => 'gear',
                'categoryId' => self::CATEGORY_PARTS,
                'lowStock'   => true,
                'active'     => true,
            ]),
        );
    }

    #[Test]
    public function aCombinationWithNoOverlapReturnsNothing(): void
    {
        self::assertSame(
            [],
            $this->namesFrom([
                'search'     => 'widget',
                'categoryId' => self::CATEGORY_TOOLS,
                'lowStock'   => false,
                'active'     => false,
            ]),
        );
    }

    #[Test]
    public function emptyCriteriaReturnTheWholeCatalog(): void
    {
        self::assertCount(5, $this->service->search([], 10, 0));
        self::assertSame(5, $this->service->count([]));
    }

    // -------------------------------------------------- count vs pagination

    #[Test]
    public function countAgreesWithSearchUnderEveryCriteriaCombination(): void
    {
        // Kalau count dan search tidak sepakat, jumlah halaman pagination akan
        // salah dan halaman terakhir bisa kosong.
        $combinations = [
            [],
            ['search' => 'widget'],
            ['categoryId' => self::CATEGORY_PARTS],
            ['lowStock' => true],
            ['lowStock' => false],
            ['active' => true],
            ['search' => 'widget', 'lowStock' => true],
            ['categoryId' => self::CATEGORY_TOOLS, 'active' => true],
        ];

        foreach ($combinations as $criteria) {
            self::assertSame(
                $this->service->count($criteria),
                count($this->service->search($criteria, 100, 0)),
                'count() dan search() harus sepakat untuk: ' . json_encode($criteria),
            );
        }
    }

    #[Test]
    public function theFilterIsAppliedBeforePagingNotAfter(): void
    {
        // Limit 2 atas hasil yang sudah difilter (3 baris) harus memberi 2,
        // bukan 2 dari seluruh katalog lalu difilter menjadi lebih sedikit.
        self::assertCount(2, $this->service->search(['lowStock' => true], 2, 0));
        self::assertCount(1, $this->service->search(['lowStock' => true], 2, 2));
    }

    /**
     * @param array{search?: string, categoryId?: int, lowStock?: bool, active?: bool} $criteria
     * @return list<string> nama product, diurutkan agar assertion stabil
     */
    private function namesFrom(array $criteria): array
    {
        $names = array_map(
            static fn (Product $product): string => $product->name,
            $this->service->search($criteria, 100, 0),
        );

        sort($names);

        return $names;
    }
}
