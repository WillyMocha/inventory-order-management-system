<?php

/**
 * Migration runner sederhana.
 *
 * Menerapkan file .sql pada direktori ini secara berurutan berdasarkan nama
 * file, lalu mencatatnya di schema_migration sehingga tidak dijalankan dua
 * kali. Tidak memakai library migration: schema ini ditulis sekali, dan
 * sebuah dependency untuk itu adalah over-engineering (research R-007, C-003).
 *
 * Penggunaan:
 *   php database/migrate.php                 menerapkan file yang belum dijalankan
 *   php database/migrate.php --fresh         menghapus seluruh tabel lalu re-apply
 *   php database/migrate.php --schema-only   hanya schema, tanpa data seed
 *
 * --schema-only dipakai untuk database integration test: test menyiapkan
 * datanya sendiri lalu me-rollback, sehingga data demo justru membuat test
 * bergantung pada isi seed (melanggar FIRST: Independent).
 */

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use App\Support\Database;

/** @var array<string, mixed> $config */
$config = require __DIR__ . '/../config/app.php';

/** @var array{host: string, port: int, database: string, username: string, password: string} $dbConfig */
$dbConfig = $config['database'];

$database = new Database($dbConfig);
$fresh = in_array('--fresh', $argv, true);
$schemaOnly = in_array('--schema-only', $argv, true);

/**
 * Menulis pesan ke stdout. Script CLI, jadi output memang tujuannya.
 */
$out = static function (string $message): void {
    fwrite(STDOUT, $message . PHP_EOL);
};

try {
    $pdo = $database->pdo();
} catch (Throwable $e) {
    fwrite(STDERR, 'Tidak dapat terhubung ke database: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}

if ($fresh) {
    $out('Menghapus seluruh tabel (--fresh) ...');

    $pdo->exec('SET FOREIGN_KEY_CHECKS = 0');

    /** @var list<string> $tables */
    $tables = $pdo->query('SHOW TABLES')?->fetchAll(PDO::FETCH_COLUMN) ?: [];

    foreach ($tables as $table) {
        $pdo->exec('DROP TABLE IF EXISTS `' . $table . '`');
    }

    $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
    $out('  ' . count($tables) . ' tabel dihapus.');
}

// Tabel pencatat migration harus ada lebih dulu.
$pdo->exec(
    'CREATE TABLE IF NOT EXISTS schema_migration (
        filename   VARCHAR(255) NOT NULL,
        applied_at DATETIME     NOT NULL,
        PRIMARY KEY (filename)
    ) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci'
);

/** @var list<string> $applied */
$applied = $pdo->query('SELECT filename FROM schema_migration')?->fetchAll(PDO::FETCH_COLUMN) ?: [];

$files = glob(__DIR__ . '/*.sql') ?: [];
sort($files, SORT_STRING);

$appliedCount = 0;

foreach ($files as $file) {
    $filename = basename($file);

    if (in_array($filename, $applied, true)) {
        continue;
    }

    if ($schemaOnly && str_contains($filename, 'seed')) {
        $out('  LEWAT  ' . $filename . ' (--schema-only)');
        continue;
    }

    $sql = file_get_contents($file);

    if ($sql === false || trim($sql) === '') {
        $out('  LEWAT  ' . $filename . ' (kosong atau tidak terbaca)');
        continue;
    }

    $out('  APPLY  ' . $filename);

    try {
        // File dijalankan di dalam transaction agar file seed (murni DML)
        // bersifat all-or-nothing.
        //
        // MySQL melakukan IMPLICIT COMMIT pada setiap statement DDL, sehingga
        // untuk file schema transaction-nya sudah tertutup sendiri sebelum
        // baris ini tercapai. Karena itu commit dan rollback selalu diperiksa
        // lebih dulu dengan inTransaction() - tanpa itu, file schema gagal
        // dengan "There is no active transaction".
        $pdo->beginTransaction();
        $pdo->exec($sql);

        if ($pdo->inTransaction()) {
            $pdo->commit();
        }

        $statement = $pdo->prepare(
            'INSERT INTO schema_migration (filename, applied_at) VALUES (:filename, NOW())'
        );
        $statement->execute(['filename' => $filename]);

        $appliedCount++;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        fwrite(STDERR, 'GAGAL pada ' . $filename . ': ' . $e->getMessage() . PHP_EOL);
        exit(1);
    }
}

if ($appliedCount === 0) {
    $out('Tidak ada migration baru. Database sudah mutakhir.');
} else {
    $out($appliedCount . ' migration diterapkan.');
}

exit(0);
