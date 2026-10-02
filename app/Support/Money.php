<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Format mata uang. IDR adalah satu-satunya mata uang yang dipakai
 * (spec A-011): prefix "Rp", pemisah ribuan titik, tanpa desimal.
 *
 * Nilai diterima sebagai string agar presisi DECIMAL dari database tidak
 * hilang lewat konversi float.
 */
final class Money
{
    private const string CURRENCY_PREFIX = 'Rp ';
    private const string THOUSANDS_SEPARATOR = '.';

    /**
     * Contoh: "1250000.00" menjadi "Rp 1.250.000".
     */
    public static function format(string $amount): string
    {
        $normalized = self::toWholeRupiah($amount);

        return self::CURRENCY_PREFIX . number_format($normalized, 0, ',', self::THOUSANDS_SEPARATOR);
    }

    /**
     * Format tanpa prefix — dipakai pada kolom CSV agar mudah diolah kembali.
     */
    public static function formatPlain(string $amount): string
    {
        return (string) self::toWholeRupiah($amount);
    }

    /**
     * Rupiah dibulatkan ke satuan penuh: sen tidak dipakai dalam praktik.
     */
    private static function toWholeRupiah(string $amount): int
    {
        return (int) round((float) $amount);
    }
}
