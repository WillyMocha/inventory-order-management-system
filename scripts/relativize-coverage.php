<?php

/**
 * Mengubah path absolut di laporan coverage menjadi relatif terhadap root project.
 *
 * PHPUnit menulis path seperti /var/www/html/app/Service/StockService.php — path
 * di dalam container app. SonarScanner biasanya berjalan di container lain dengan
 * mount berbeda (mis. /usr/src), sehingga path absolut itu tidak cocok dengan file
 * mana pun dan seluruh coverage terbaca 0%. Path relatif cocok di mana pun scanner
 * dijalankan.
 *
 * Penggunaan (dipanggil oleh `composer test:coverage`):
 *   php scripts/relativize-coverage.php coverage/clover.xml
 *
 * Exit code:
 *   0  berhasil
 *   1  file laporan tidak ada atau tidak dapat ditulis
 */

declare(strict_types=1);

$report = $argv[1] ?? '';
if ($report === '' || !is_file($report)) {
    fwrite(STDERR, "Coverage report not found: {$report}\n");
    exit(1);
}

$prefix = dirname(__DIR__) . '/';
$content = (string) file_get_contents($report);

if (file_put_contents($report, str_replace($prefix, '', $content)) === false) {
    fwrite(STDERR, "Cannot write coverage report: {$report}\n");
    exit(1);
}

echo "Coverage paths made relative to the project root: {$report}\n";
