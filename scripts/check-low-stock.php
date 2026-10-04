<?php

/**
 * Ringkasan product yang berada pada atau di bawah reorder point (JOB-01, FR-031).
 *
 * Entry point CLI yang berdiri sendiri, DI LUAR request cycle sepenuhnya:
 * tidak ada session, tidak ada authorization guard, tidak ada HTTP. Itulah
 * yang dibuktikan script ini — business rule-nya tidak terikat pada web.
 *
 * Yang dipakai di sini adalah ProductService::lowStock(), dirakit oleh
 * config/container.php yang sama dengan aplikasi web. Ia menjalankan query
 * repository yang SAMA (ProductRepositoryInterface::lowStock()) dengan daftar
 * low-stock di dashboard dan /api/dashboard/low-stock. Menulis query sendiri di
 * script ini akan membuat script dan dashboard bisa berbeda jawaban
 * (research R-012) — justru kebalikan dari yang diinginkan.
 *
 * Tidak ada cron yang dipasang di image; §4.3 menempatkan penjadwalan otomatis
 * di luar scope, dan script ini memang dijalankan manual.
 *
 * Penggunaan:
 *   docker compose exec app php scripts/check-low-stock.php
 *   docker compose exec app php scripts/check-low-stock.php --limit=25
 *
 * Exit code:
 *   0  berhasil dijalankan — termasuk saat tidak ada satu pun product menipis
 *   1  gagal dijalankan, misalnya database tidak dapat dihubungi
 *
 * Menemukan product yang menipis BUKAN kegagalan, sehingga exit code-nya tetap
 * 0: kalau tidak, setiap pemanggil otomatis akan menganggap laporan yang sehat
 * sebagai error.
 */

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use App\Entity\Product;
use App\Service\ProductService;

/** Batas baris default. Dapat dinaikkan lewat --limit= bila katalognya besar. */
const DEFAULT_LIMIT = 100;

/** Lebar kolom output, dipilih agar muat pada terminal 80 kolom. */
const COLUMN_WIDTHS = ['sku' => 16, 'name' => 34, 'stock' => 9, 'reorder' => 9];

$out = static function (string $message = ''): void {
    fwrite(STDOUT, $message . PHP_EOL);
};

$fail = static function (string $message): never {
    fwrite(STDERR, $message . PHP_EOL);
    exit(1);
};

/**
 * Membaca --limit= dari argumen. Nilai yang tidak masuk akal dikembalikan ke
 * default, bukan dijadikan error: ini laporan, bukan perintah yang berbahaya.
 *
 * @param list<string> $arguments
 */
$limitFrom = static function (array $arguments): int {
    foreach ($arguments as $argument) {
        if (!str_starts_with($argument, '--limit=')) {
            continue;
        }

        $value = (int) substr($argument, strlen('--limit='));

        return $value > 0 ? $value : DEFAULT_LIMIT;
    }

    return DEFAULT_LIMIT;
};

/** Memotong teks yang lebih panjang dari kolomnya agar tabel tetap lurus. */
$fit = static function (string $value, int $width): string {
    $trimmed = mb_strlen($value) > $width
        ? mb_substr($value, 0, $width - 1) . '…'
        : $value;

    return $trimmed . str_repeat(' ', max(0, $width - mb_strlen($trimmed)));
};

// --- Boot -----------------------------------------------------------------

try {
    /** @var array<string, mixed> $config */
    $config = require __DIR__ . '/../config/app.php';

    /** @var callable(array<string, mixed>): array<string, mixed> $buildContainer */
    $buildContainer = require __DIR__ . '/../config/container.php';
    $container = $buildContainer($config);
} catch (Throwable $e) {
    $fail('Tidak dapat memuat konfigurasi: ' . $e->getMessage());
}

$productService = $container['productService'];

if (!$productService instanceof ProductService) {
    $fail('Container tidak menyediakan ProductService.');
}

// --- Query ----------------------------------------------------------------

$limit = $limitFrom(array_slice($argv, 1));

try {
    $rows = $productService->lowStock($limit);
    // Jumlah total dihitung terpisah, sehingga daftar yang terpotong --limit
    // tidak pernah mengecilkan angka yang dilaporkan.
    $total = $productService->count(['lowStock' => true, 'active' => true]);
} catch (Throwable $e) {
    $fail('Tidak dapat membaca data stock: ' . $e->getMessage());
}

// --- Report ---------------------------------------------------------------

$out('Low stock report — ' . date('Y-m-d H:i:s'));
$out('Products at or below their reorder point, across all warehouses.');
$out();

if ($rows === []) {
    $out('Every active product is above its reorder point. Nothing to restock.');
    exit(0);
}

$out(
    $fit('SKU', COLUMN_WIDTHS['sku'])
    . $fit('PRODUCT', COLUMN_WIDTHS['name'])
    . $fit('IN STOCK', COLUMN_WIDTHS['stock'])
    . $fit('REORDER', COLUMN_WIDTHS['reorder']),
);
$out(str_repeat('-', array_sum(COLUMN_WIDTHS)));

foreach ($rows as $row) {
    /** @var Product $product */
    $product = $row['product'];

    $out(
        $fit($product->sku, COLUMN_WIDTHS['sku'])
        . $fit($product->name, COLUMN_WIDTHS['name'])
        . $fit((string) $row['totalQuantity'], COLUMN_WIDTHS['stock'])
        . $fit((string) $product->reorderPoint, COLUMN_WIDTHS['reorder']),
    );
}

$out();
$out($total === 1 ? '1 product needs restocking.' : $total . ' products need restocking.');

if ($total > count($rows)) {
    $out('Showing the first ' . count($rows) . '. Use --limit= to see more.');
}

exit(0);
