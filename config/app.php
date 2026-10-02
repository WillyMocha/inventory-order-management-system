<?php

/**
 * Loader environment. Membaca .env bila ada (untuk penggunaan di luar Docker),
 * lalu memvalidasi bahwa seluruh key wajib terisi.
 *
 * Gagal keras saat boot jika ada key yang hilang — lebih baik berhenti dengan
 * pesan jelas daripada berjalan dengan konfigurasi setengah jadi.
 */

declare(strict_types=1);

$rootPath = dirname(__DIR__);

// Di dalam Docker, environment variable sudah di-inject oleh Compose.
// Di luar Docker, file .env dibaca sebagai fallback.
$envFile = $rootPath . '/.env';
if (is_readable($envFile)) {
    $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines === false ? [] : $lines as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#')) {
            continue;
        }
        $parts = explode('=', $line, 2);
        if (count($parts) !== 2) {
            continue;
        }
        $key = trim($parts[0]);
        $value = trim($parts[1], " \t\n\r\0\x0B\"'");
        if (getenv($key) === false) {
            putenv($key . '=' . $value);
        }
    }
}

/**
 * Membaca environment variable, dengan default opsional.
 *
 * @throws RuntimeException bila key wajib tidak tersedia.
 */
$env = static function (string $key, ?string $default = null): string {
    $value = getenv($key);
    if ($value === false || $value === '') {
        if ($default === null) {
            throw new RuntimeException(
                'Environment variable wajib "' . $key . '" belum diset. '
                . 'Salin .env.example menjadi .env, atau periksa compose.yaml.'
            );
        }
        return $default;
    }
    return $value;
};

$appEnv = $env('APP_ENV', 'local');
$isTesting = $appEnv === 'testing';

return [
    'env'        => $appEnv,
    'url'        => $env('APP_URL', 'http://localhost:8080'),
    'debug'      => false, // Stack trace tidak pernah ditampilkan ke user (ERR-01).
    'root_path'  => $rootPath,
    'view_path'  => $rootPath . '/views',

    'session' => [
        'name'   => 'IOMS_SESSION',
        'secure' => filter_var($env('SESSION_SECURE', 'false'), FILTER_VALIDATE_BOOLEAN),
    ],

    'database' => [
        'host'     => $env('DB_HOST', '127.0.0.1'),
        'port'     => (int) $env('DB_PORT', '3306'),
        // Integration test memakai database terpisah agar tidak pernah
        // menyentuh data demo.
        'database' => $isTesting
            ? $env('DB_DATABASE_TEST', 'ioms_test')
            : $env('DB_DATABASE', 'ioms'),
        'username' => $env('DB_USERNAME', 'ioms_user'),
        'password' => $env('DB_PASSWORD', 'ioms_secret'),
    ],

    'upload' => [
        'path'          => $env('UPLOAD_PATH', $rootPath . '/storage/uploads'),
        'max_bytes'     => 2 * 1024 * 1024,
        'allowed_mimes' => ['image/jpeg', 'image/png', 'image/webp'],
    ],

    'security' => [
        // Rate limit login (research R-005, security standard §7).
        'login_max_attempts'  => 5,
        'login_window_minutes' => 15,
    ],

    'pagination' => [
        'per_page' => 10, // FIND-01 menetapkan 10 baris per halaman.
    ],
];
