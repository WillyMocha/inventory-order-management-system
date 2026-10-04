<?php

declare(strict_types=1);

namespace Tests\Unit\Support;

use App\Support\BarChartScale;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Geometri grafik batang di dashboard (spec 005, research R-005).
 *
 * Hanya aritmetika tampilan: sumbu dibulatkan ke angka "rapi", garis bantu
 * selalu bilangan bulat, dan batang tidak pernah melewati tinggi area plot
 * maupun hilang ketika nilainya kecil.
 */
final class BarChartScaleTest extends TestCase
{
    private const int PLOT_HEIGHT = 160;

    /** @return array<string, array{0: int, 1: int}> */
    public static function axisMaxima(): array
    {
        return [
            'nol menjadi 1'      => [0, 1],
            'satu'               => [1, 1],
            'dua'                => [2, 2],
            'tiga naik ke 5'     => [3, 5],
            'tujuh naik ke 10'   => [7, 10],
            'sepuluh tetap'      => [10, 10],
            'dua belas ke 20'    => [12, 20],
            'empat puluh ke 50'  => [43, 50],
            'seratus satu ke 200' => [101, 200],
            '450 ke 500'         => [450, 500],
            '501 ke 1000'        => [501, 1000],
        ];
    }

    #[Test]
    #[DataProvider('axisMaxima')]
    public function theAxisMaximumRoundsUpToOneTwoOrFiveTimesAPowerOfTen(int $maxValue, int $expected): void
    {
        self::assertSame($expected, (new BarChartScale($maxValue, self::PLOT_HEIGHT))->axisMax());
    }

    /** @return array<string, array{0: int, 1: list<int>}> */
    public static function gridlineSets(): array
    {
        return [
            'sumbu 1'    => [1, [0, 1]],
            'sumbu 2'    => [2, [0, 1, 2]],
            'sumbu 5'    => [5, [0, 1, 2, 3, 4, 5]],
            'sumbu 10'   => [7, [0, 2, 4, 6, 8, 10]],
            'sumbu 20'   => [12, [0, 5, 10, 15, 20]],
            'sumbu 50'   => [43, [0, 10, 20, 30, 40, 50]],
            'sumbu 500'  => [450, [0, 100, 200, 300, 400, 500]],
        ];
    }

    /** @param list<int> $expected */
    #[Test]
    #[DataProvider('gridlineSets')]
    public function gridlinesAreWholeNumbersFromZeroToTheAxisMaximum(int $maxValue, array $expected): void
    {
        self::assertSame($expected, (new BarChartScale($maxValue, self::PLOT_HEIGHT))->gridlines());
    }

    #[Test]
    public function aBarIsProportionalToItsValue(): void
    {
        $scale = new BarChartScale(450, self::PLOT_HEIGHT); // sumbu 500

        self::assertSame(80, $scale->barHeight(250));
        self::assertSame(144, $scale->barHeight(450));
    }

    #[Test]
    public function zeroHasNoBarButAnyMovementIsAtLeastOnePixel(): void
    {
        $scale = new BarChartScale(450, self::PLOT_HEIGHT);

        self::assertSame(0, $scale->barHeight(0));
        self::assertSame(1, $scale->barHeight(1));
    }

    #[Test]
    public function noBarExceedsThePlotHeight(): void
    {
        foreach ([1, 7, 10, 99, 450, 1000] as $maxValue) {
            $scale = new BarChartScale($maxValue, self::PLOT_HEIGHT);

            self::assertLessThanOrEqual(self::PLOT_HEIGHT, $scale->barHeight($maxValue), 'max ' . $maxValue);
        }
    }

    #[Test]
    public function aGridlineValueMapsToItsHeightOnTheSameScaleAsTheBars(): void
    {
        $scale = new BarChartScale(43, self::PLOT_HEIGHT); // sumbu 50

        self::assertSame(self::PLOT_HEIGHT, $scale->barHeight($scale->axisMax()));
        self::assertSame(64, $scale->barHeight(20));
    }
}
