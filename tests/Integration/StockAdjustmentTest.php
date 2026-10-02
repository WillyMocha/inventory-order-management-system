<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Repository\Mysql\MysqlProductStockRepository;
use PDOException;
use PHPUnit\Framework\Attributes\Test;

/**
 * Regression test untuk MysqlProductStockRepository::adjust() (ARCH-02, NFR-002).
 *
 * Bug yang dijaga di sini pernah ada dan berat: `adjust()` memakai satu
 * `INSERT ... ON DUPLICATE KEY UPDATE` dengan delta sebagai nilai kandidat
 * insert. MySQL memeriksa CHECK `quantity >= 0` terhadap baris kandidat INSERT
 * SEBELUM jatuh ke cabang ON DUPLICATE KEY UPDATE, sehingga setiap goods issue
 * — delta-nya negatif — ditolak constraint walaupun nilai akhirnya tidak pernah
 * negatif. Akibatnya goods issue TIDAK PERNAH bisa berhasil.
 *
 * Bug itu tidak terlihat sama sekali sampai integration suite benar-benar
 * dijalankan. Test ini menguji repository-nya LANGSUNG, terpisah dari
 * StockService, supaya kegagalan yang sama di kemudian hari menunjuk tepat ke
 * lapisan yang salah.
 */
final class StockAdjustmentTest extends IntegrationTestCase
{
    use SalesOrderFixtures;

    private MysqlProductStockRepository $stocks;

    protected function setUp(): void
    {
        parent::setUp();

        $this->stocks = new MysqlProductStockRepository($this->database);

        $this->seedSalesOrderFixtures();
    }

    #[Test]
    public function aNegativeDeltaIsAppliedToAnExistingRow(): void
    {
        // Inilah jalur goods issue, dan inilah yang dahulu gagal seluruhnya.
        $this->setStock($this->productId, $this->warehouseId, 10);

        $this->stocks->adjust($this->productId, $this->warehouseId, -4);

        self::assertSame(6, $this->stockQuantity($this->productId, $this->warehouseId));
    }

    #[Test]
    public function aPositiveDeltaIsAppliedToAnExistingRow(): void
    {
        $this->setStock($this->productId, $this->warehouseId, 10);

        $this->stocks->adjust($this->productId, $this->warehouseId, 5);

        self::assertSame(15, $this->stockQuantity($this->productId, $this->warehouseId));
    }

    #[Test]
    public function aPositiveDeltaCreatesTheRowWhenTheresNoneYet(): void
    {
        // Goods receipt pertama untuk sebuah product di warehouse tertentu.
        self::assertSame(0, $this->stockQuantity($this->secondProductId, $this->warehouseId));

        $this->stocks->adjust($this->secondProductId, $this->warehouseId, 7);

        self::assertSame(7, $this->stockQuantity($this->secondProductId, $this->warehouseId));
    }

    #[Test]
    public function bringingStockDownToExactlyZeroIsAllowed(): void
    {
        // Batasnya `>= 0`, bukan `> 0`: mengeluarkan seluruh sisa stock sah.
        $this->setStock($this->productId, $this->warehouseId, 4);

        $this->stocks->adjust($this->productId, $this->warehouseId, -4);

        self::assertSame(0, $this->stockQuantity($this->productId, $this->warehouseId));
    }

    #[Test]
    public function repeatedAdjustmentsAccumulateRatherThanOverwrite(): void
    {
        $this->setStock($this->productId, $this->warehouseId, 10);

        $this->stocks->adjust($this->productId, $this->warehouseId, 5);
        $this->stocks->adjust($this->productId, $this->warehouseId, -3);
        $this->stocks->adjust($this->productId, $this->warehouseId, -2);

        self::assertSame(10, $this->stockQuantity($this->productId, $this->warehouseId));
    }

    #[Test]
    public function theDatabaseStillRefusesAnAdjustmentThatWouldGoNegative(): void
    {
        // Jaring pengaman terakhir ARCH-02. Susunan dua langkah pada adjust()
        // justru MENGEMBALIKAN peran ini: CHECK kini menjaga HASIL perubahan,
        // bukan menolak setiap delta negatif tanpa pandang bulu.
        $this->setStock($this->productId, $this->warehouseId, 3);

        $this->expectException(PDOException::class);

        $this->stocks->adjust($this->productId, $this->warehouseId, -5);
    }

    #[Test]
    public function aRefusedAdjustmentLeavesTheStoredQuantityUntouched(): void
    {
        $this->setStock($this->productId, $this->warehouseId, 3);

        try {
            $this->stocks->adjust($this->productId, $this->warehouseId, -5);
            self::fail('Adjustment yang membuat stock negatif seharusnya ditolak.');
        } catch (PDOException) {
            // diharapkan
        }

        self::assertSame(3, $this->stockQuantity($this->productId, $this->warehouseId));
    }

    #[Test]
    public function adjustingOnePairNeverDisturbsAnother(): void
    {
        $this->setStock($this->productId, $this->warehouseId, 10);
        $this->setStock($this->secondProductId, $this->warehouseId, 20);

        $this->stocks->adjust($this->productId, $this->warehouseId, -4);

        self::assertSame(6, $this->stockQuantity($this->productId, $this->warehouseId));
        self::assertSame(20, $this->stockQuantity($this->secondProductId, $this->warehouseId));
    }
}
