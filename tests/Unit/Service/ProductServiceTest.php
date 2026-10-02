<?php

declare(strict_types=1);

namespace Tests\Unit\Service;

use App\Entity\Category;
use App\Entity\Product;
use App\Repository\ProductRepositoryInterface;
use App\Service\ProductService;
use App\Support\Exception\NotFoundException;
use App\Support\Exception\ValidationException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Tests\Unit\Fake\InMemoryCategoryRepository;
use Tests\Unit\Fake\InMemoryProductRepository;
use Tests\Unit\Fake\InMemoryProductStockRepository;

/**
 * Unit test ProductService (PRD-01, WH-01).
 *
 * Tanpa database - seluruhnya terhadap fake in-memory.
 */
final class ProductServiceTest extends TestCase
{
    private InMemoryProductRepository $products;
    private InMemoryProductStockRepository $stocks;
    private ProductService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->products = new InMemoryProductRepository([
            new Product(1, 'SKU-000001', 'Kabel UTP Cat6', 1, 'roll', '1150000.00', '1450000.00', 10, null, true),
            new Product(2, 'SKU-000002', 'Switch 24-Port', 1, 'pcs', '2150000.00', '2750000.00', 4, null, true),
        ]);

        $this->stocks = new InMemoryProductStockRepository(
            ['1:1' => 30, '1:2' => 12, '2:1' => 3, '2:2' => 0],
            [1 => 'Gudang Pusat Jakarta', 2 => 'Gudang Surabaya'],
        );

        $categories = new InMemoryCategoryRepository([
            new Category(1, 'Networking', 'Kategori Networking'),
        ]);

        $this->service = new ProductService($this->products, $this->stocks, $categories);
    }

    /**
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private function validData(array $overrides = []): array
    {
        return array_merge([
            'sku'            => 'SKU-000099',
            'name'           => 'New Product',
            'category_id'    => 1,
            'unit'           => 'pcs',
            'purchase_price' => '100000',
            'selling_price'  => '135000',
            'reorder_point'  => 5,
        ], $overrides);
    }

    // ------------------------------------------------------------- FR-008

    #[Test]
    public function createsAProduct(): void
    {
        $id = $this->service->create($this->validData());
        $created = $this->products->findById($id);

        self::assertNotNull($created);
        self::assertSame('SKU-000099', $created->sku);
        self::assertTrue($created->isActive);
    }

    #[Test]
    public function rejectsADuplicateSku(): void
    {
        try {
            $this->service->create($this->validData(['sku' => 'SKU-000001']));
            self::fail('SKU duplikat seharusnya ditolak');
        } catch (ValidationException $e) {
            self::assertArrayHasKey('sku', $e->errors());
        }
    }

    #[Test]
    public function allowsAProductToKeepItsOwnSkuOnUpdate(): void
    {
        $this->service->update(1, $this->validData(['sku' => 'SKU-000001', 'name' => 'Renamed']));

        $updated = $this->products->findById(1);
        self::assertNotNull($updated);
        self::assertSame('Renamed', $updated->name);
    }

    #[Test]
    public function rejectsANegativePurchasePrice(): void
    {
        try {
            $this->service->create($this->validData(['purchase_price' => '-1']));
            self::fail('Harga beli negatif seharusnya ditolak');
        } catch (ValidationException $e) {
            self::assertArrayHasKey('purchase_price', $e->errors());
        }
    }

    #[Test]
    public function rejectsANegativeSellingPrice(): void
    {
        try {
            $this->service->create($this->validData(['selling_price' => '-0.01']));
            self::fail('Harga jual negatif seharusnya ditolak');
        } catch (ValidationException $e) {
            self::assertArrayHasKey('selling_price', $e->errors());
        }
    }

    #[Test]
    public function rejectsANegativeReorderPoint(): void
    {
        try {
            $this->service->create($this->validData(['reorder_point' => -5]));
            self::fail('Reorder point negatif seharusnya ditolak');
        } catch (ValidationException $e) {
            self::assertArrayHasKey('reorder_point', $e->errors());
        }
    }

    /** Nol diperbolehkan - batasnya "tidak negatif", bukan "harus positif". */
    #[Test]
    public function acceptsZeroForPricesAndReorderPoint(): void
    {
        $id = $this->service->create($this->validData([
            'purchase_price' => '0',
            'selling_price'  => '0',
            'reorder_point'  => 0,
        ]));

        self::assertNotNull($this->products->findById($id));
    }

    #[Test]
    public function rejectsAnUnknownCategory(): void
    {
        try {
            $this->service->create($this->validData(['category_id' => 999]));
            self::fail('Category yang tidak ada seharusnya ditolak');
        } catch (ValidationException $e) {
            self::assertArrayHasKey('category_id', $e->errors());
        }
    }

    #[Test]
    public function nothingIsPersistedWhenValidationFails(): void
    {
        $before = $this->products->countBy([]);

        try {
            $this->service->create($this->validData(['sku' => '', 'purchase_price' => '-5']));
        } catch (ValidationException) {
            // diharapkan
        }

        self::assertSame($before, $this->products->countBy([]), 'Tidak boleh ada partial save');
    }

    // ------------------------------------------------------------- FR-009

    /**
     * PRD-01 dan §1.3: product dinonaktifkan, TIDAK PERNAH dihapus permanen.
     *
     * Aturannya ditegakkan secara struktural - ProductService sama sekali
     * tidak memiliki operasi delete, dan route table tidak punya endpoint
     * delete. Test ini mengunci ketiadaan itu, sehingga menambahkannya kelak
     * akan langsung gagal.
     */
    #[Test]
    public function neitherTheServiceNorTheRepositoryContractExposesADeleteOperation(): void
    {
        // Reflection dipakai agar pemeriksaannya benar-benar berjalan saat
        // runtime terhadap bentuk kelas yang sebenarnya, bukan disimpulkan
        // static analysis dari kode test-nya sendiri.
        $destructive = ['delete', 'remove', 'destroy', 'purge', 'hardDelete'];

        foreach ([ProductService::class, ProductRepositoryInterface::class] as $subject) {
            $methods = array_map(
                static fn (\ReflectionMethod $m): string => strtolower($m->getName()),
                (new \ReflectionClass($subject))->getMethods(),
            );

            foreach ($destructive as $forbidden) {
                self::assertNotContains(
                    strtolower($forbidden),
                    $methods,
                    $subject . ' tidak boleh mengekspos ' . $forbidden
                        . '() - product dinonaktifkan, bukan dihapus (§1.3)',
                );
            }
        }
    }

    #[Test]
    public function aProductUsedByAnOrderIsReportedAsReferencedAndStillDeactivates(): void
    {
        $this->products->markReferenced(1);

        self::assertTrue($this->service->isReferencedByOrder(1));

        $this->service->toggleActive(1);

        $after = $this->products->findById(1);
        self::assertNotNull($after, 'Product harus tetap ada setelah dinonaktifkan');
        self::assertFalse($after->isActive);
    }

    #[Test]
    public function anUnreferencedProductIsReportedAsNotReferenced(): void
    {
        self::assertFalse($this->service->isReferencedByOrder(2));
    }

    /** Deactivate lalu activate kembali - record-nya tidak pernah hilang. */
    #[Test]
    public function deactivationIsReversible(): void
    {
        $this->service->toggleActive(1);
        $this->service->toggleActive(1);

        $after = $this->products->findById(1);
        self::assertNotNull($after);
        self::assertTrue($after->isActive);
    }

    #[Test]
    public function deactivatedProductsAreExcludedFromTheActiveCatalog(): void
    {
        $this->service->toggleActive(1);

        $skus = array_map(static fn (Product $p): string => $p->sku, $this->service->activeCatalog());

        self::assertNotContains('SKU-000001', $skus);
        self::assertContains('SKU-000002', $skus);
    }

    #[Test]
    public function togglingAnUnknownProductIsNotFound(): void
    {
        $this->expectException(NotFoundException::class);
        $this->service->toggleActive(999);
    }

    // ------------------------------------------------------------- FR-011

    /**
     * WH-01: satu product dapat memiliki stock berbeda di tiap warehouse;
     * tampilan menunjukkan total DAN rinciannya.
     */
    #[Test]
    public function stockBreakdownReturnsPerWarehouseRowsAndTheTotal(): void
    {
        $breakdown = $this->service->stockBreakdown(1);

        self::assertSame(42, $breakdown['total'], '30 di Jakarta + 12 di Surabaya');
        self::assertCount(2, $breakdown['warehouses']);
        self::assertSame(30, $breakdown['warehouses'][0]['quantity']);
        self::assertSame('Gudang Pusat Jakarta', $breakdown['warehouses'][0]['warehouseName']);
        self::assertSame(12, $breakdown['warehouses'][1]['quantity']);
    }

    #[Test]
    public function stockBreakdownFlagsLowStockAgainstTheTotalNotPerWarehouse(): void
    {
        // Product 2: 3 + 0 = 3 total, reorder point 4 -> low stock.
        $breakdown = $this->service->stockBreakdown(2);

        self::assertSame(3, $breakdown['total']);
        self::assertTrue($breakdown['isLowStock']);

        // Product 1: 42 total, reorder point 10 -> tidak low stock.
        self::assertFalse($this->service->stockBreakdown(1)['isLowStock']);
    }

    #[Test]
    public function stockBreakdownForAnUnknownProductIsNotFound(): void
    {
        $this->expectException(NotFoundException::class);
        $this->service->stockBreakdown(999);
    }

    // ------------------------------------------- Lookup untuk JSON API (FR-028)

    #[Test]
    public function stockBreakdownCanBeLookedUpBySku(): void
    {
        // SKU-lah identitas yang dipegang pemanggil API; id internal tidak
        // pernah muncul di contract endpoint availability.
        $breakdown = $this->service->stockBreakdownBySku('SKU-000001');

        self::assertSame(1, $breakdown['product']->id);
        self::assertSame(42, $breakdown['total']);
    }

    #[Test]
    public function anUnknownSkuIsNotFoundRatherThanAnEmptyBreakdown(): void
    {
        // "Product ini tidak ada" dan "ada tetapi stocknya nol" adalah dua
        // jawaban yang berbeda, dan API harus membedakannya.
        $this->expectException(NotFoundException::class);

        $this->service->stockBreakdownBySku('SKU-TIDAK-ADA');
    }

    #[Test]
    public function availableQuantityReadsOnePairOnly(): void
    {
        self::assertSame(30, $this->service->availableQuantity(1, 1));
        self::assertSame(12, $this->service->availableQuantity(1, 2));
    }

    #[Test]
    public function aPairWithNoStockRowIsZeroNotAnError(): void
    {
        // Pasangannya sah, stocknya saja yang kosong.
        self::assertSame(0, $this->service->availableQuantity(2, 2));
    }

    #[Test]
    public function availableQuantityForAnUnknownProductIsNotFound(): void
    {
        $this->expectException(NotFoundException::class);

        $this->service->availableQuantity(999, 1);
    }

    #[Test]
    public function lowStockListsOnlyProductsAtOrBelowTheirReorderPoint(): void
    {
        $this->products->setTotalQuantity(1, 42);
        $this->products->setTotalQuantity(2, 3);

        $low = $this->service->lowStock(10);

        self::assertCount(1, $low);
        self::assertSame('SKU-000002', $low[0]['product']->sku);
    }

    /** Batas inklusif: total sama dengan reorder point tetap dihitung low. */
    #[Test]
    public function lowStockIncludesTheBoundaryValue(): void
    {
        $this->products->setTotalQuantity(1, 10);
        $this->products->setTotalQuantity(2, 99);

        $low = $this->service->lowStock(10);

        self::assertCount(1, $low);
        self::assertSame('SKU-000001', $low[0]['product']->sku);
    }

    /**
     * Satu di bawah batas dan satu di atasnya, diperiksa berpasangan.
     *
     * Batas "at or below" (spec A-008) hanya benar-benar terkunci bila KEDUA
     * sisinya diuji: kalau perbandingannya kelak berubah menjadi `<`, test
     * batas saja yang jatuh, dan test ini menjelaskan arah kesalahannya.
     */
    #[Test]
    public function lowStockCoversBothSidesOfTheReorderPoint(): void
    {
        $this->products->setTotalQuantity(1, 9);
        $this->products->setTotalQuantity(2, 5);

        $skus = array_map(
            static fn (array $row): string => $row['product']->sku,
            $this->service->lowStock(10),
        );

        // Product 1: 9 <= 10 -> low. Product 2: 5 > 4 -> tidak low.
        self::assertSame(['SKU-000001'], $skus);
    }

    #[Test]
    public function lowStockTreatsAProductWithNoStockAtAllAsLow(): void
    {
        // Nol adalah nilai paling rendah yang mungkin; product tanpa satu pun
        // baris stock justru yang paling perlu dilaporkan (JOB-01).
        $this->products->setTotalQuantity(1, 0);
        $this->products->setTotalQuantity(2, 0);

        self::assertCount(2, $this->service->lowStock(10));
    }

    #[Test]
    public function lowStockNeverReportsADeactivatedProduct(): void
    {
        // Product yang dinonaktifkan tidak dibeli lagi, sehingga memunculkannya
        // pada ringkasan restock hanya menambah derau.
        $this->products->setTotalQuantity(1, 0);
        $this->products->setTotalQuantity(2, 0);

        $this->service->toggleActive(1);

        $low = $this->service->lowStock(10);

        self::assertCount(1, $low);
        self::assertSame('SKU-000002', $low[0]['product']->sku);
    }

    #[Test]
    public function lowStockHonoursTheLimit(): void
    {
        $this->products->setTotalQuantity(1, 0);
        $this->products->setTotalQuantity(2, 0);

        self::assertCount(1, $this->service->lowStock(1));
    }

    #[Test]
    public function lowStockCarriesTheTotalQuantityUsedToJudgeIt(): void
    {
        // Script JOB-01 mencetak angka ini; kalau yang terbawa bukan total
        // yang dipakai menilai, laporannya membantah dirinya sendiri.
        $this->products->setTotalQuantity(1, 4);
        $this->products->setTotalQuantity(2, 99);

        $low = $this->service->lowStock(10);

        self::assertSame(4, $low[0]['totalQuantity']);
        self::assertSame(10, $low[0]['product']->reorderPoint);
    }

    #[Test]
    public function lowStockReturnsAnEmptyListWhenEverythingIsStocked(): void
    {
        $this->products->setTotalQuantity(1, 500);
        $this->products->setTotalQuantity(2, 500);

        self::assertSame([], $this->service->lowStock(10));
    }
}
