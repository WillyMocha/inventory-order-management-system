<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Skala sumbu dan tinggi batang untuk grafik SVG di dashboard (spec 005).
 *
 * Hanya geometri tampilan — tidak ada business rule di sini. Angka yang
 * digambar sudah dihitung DashboardService; class ini menjawab "setinggi apa
 * batang bernilai N bila nilai terbesarnya M". Dipisah dari template agar
 * kasus tepinya (semua nol, satu hari yang sangat besar, batang yang terlalu
 * kecil untuk terlihat) dapat di-unit-test (plan: Complexity Tracking).
 */
final class BarChartScale
{
    /**
     * Kelipatan "rapi" untuk maksimum sumbu: 1, 2, atau 5 × 10ⁿ. Bila nilainya
     * melewati 5 × 10ⁿ, sumbu naik ke 10ⁿ⁺¹ (lihat niceCeiling()).
     */
    private const array NICE_STEPS = [1, 2, 5];

    private readonly int $axisMax;

    public function __construct(int $maxValue, private readonly int $plotHeight)
    {
        $this->axisMax = self::niceCeiling(max(1, $maxValue));
    }

    public function axisMax(): int
    {
        return $this->axisMax;
    }

    /**
     * Nilai garis bantu dari 0 sampai maksimum sumbu, selalu bilangan bulat.
     *
     * Jumlah langkahnya mengikuti angka depan sumbu — 10 → 5 langkah,
     * 20 → 4, 50 → 5 — sehingga label sumbu tidak pernah berupa pecahan.
     * Sumbu di bawah 10 dibagi per satuan.
     *
     * @return list<int>
     */
    public function gridlines(): array
    {
        $steps = $this->stepCount();
        $values = [];

        for ($k = 0; $k <= $steps; $k++) {
            $values[] = intdiv($this->axisMax * $k, $steps);
        }

        return $values;
    }

    /**
     * Tinggi batang dalam satuan viewBox. Nol berarti tanpa batang; nilai
     * bukan nol minimal 1 agar pergerakan kecil tetap terlihat di samping
     * hari yang sangat besar (spec Edge Cases).
     */
    public function barHeight(int $value): int
    {
        if ($value <= 0) {
            return 0;
        }

        $height = (int) round($value * $this->plotHeight / $this->axisMax);

        return min($this->plotHeight, max(1, $height));
    }

    private function stepCount(): int
    {
        if ($this->axisMax < 10) {
            return $this->axisMax;
        }

        $magnitude = self::magnitude($this->axisMax);

        return intdiv($this->axisMax, $magnitude) === 2 ? 4 : 5;
    }

    private static function niceCeiling(int $value): int
    {
        $magnitude = self::magnitude($value);

        foreach (self::NICE_STEPS as $step) {
            if ($step * $magnitude >= $value) {
                return $step * $magnitude;
            }
        }

        return 10 * $magnitude;
    }

    /** Pangkat sepuluh terbesar yang tidak melebihi $value. */
    private static function magnitude(int $value): int
    {
        $magnitude = 1;

        while ($magnitude * 10 <= $value) {
            $magnitude *= 10;
        }

        return $magnitude;
    }
}
