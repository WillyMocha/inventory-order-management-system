<?php

declare(strict_types=1);

namespace Tests\Unit\Support;

use App\Support\Money;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Unit test Money (spec A-011).
 *
 * lineTotal() menggantikan dua salinan helper di controller Sales Order dan
 * Purchase Order (tech-debt TD-11), sehingga aturan pembulatannya kini diuji di
 * satu tempat.
 */
final class MoneyTest extends TestCase
{
    #[Test]
    public function formatsWholeRupiahWithDotThousandsSeparators(): void
    {
        self::assertSame('Rp 1.250.000', Money::format('1250000.00'));
        self::assertSame('1250000', Money::formatPlain('1250000.00'));
    }

    #[Test]
    public function lineTotalMultipliesWholeRupiahByQuantity(): void
    {
        self::assertSame('4680000', Money::lineTotal(9, '520000.00'));
        self::assertSame('0', Money::lineTotal(0, '520000.00'));
    }

    #[Test]
    public function lineTotalRoundsThePriceBeforeMultiplying(): void
    {
        // Harga dibulatkan ke rupiah penuh lebih dulu, persis seperti total yang
        // tampil per line; 1.50 × 3 menjadi 2 × 3, bukan 4.5 yang dibulatkan.
        self::assertSame('6', Money::lineTotal(3, '1.50'));
    }
}
