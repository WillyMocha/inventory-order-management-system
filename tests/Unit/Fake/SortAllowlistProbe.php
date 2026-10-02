<?php

declare(strict_types=1);

namespace Tests\Unit\Fake;

use App\Repository\Mysql\MysqlRepository;
use App\Support\Database;

/**
 * Probe untuk menguji helper allowlist sort pada MysqlRepository.
 *
 * resolveSortColumn() dan resolveDirection() adalah protected — memang
 * seharusnya, karena keduanya detail internal repository. Tetapi keduanya juga
 * satu-satunya tempat nama kolom masuk ke SQL tanpa binding, sehingga justru
 * bagian yang paling perlu diuji (security standard §5).
 *
 * Probe ini mengeksposnya tanpa mengubah visibility di kode produksi.
 *
 * Tidak ada koneksi database yang terjadi: Database membuat PDO secara lazy di
 * dalam pdo(), dan probe ini tidak pernah memanggilnya.
 */
final class SortAllowlistProbe extends MysqlRepository
{
    public function __construct()
    {
        parent::__construct(new Database([
            'host'     => 'unused',
            'port'     => 0,
            'database' => 'unused',
            'username' => 'unused',
            'password' => 'unused',
        ]));
    }

    /** @param array<string, string> $allowed */
    public function resolve(array $allowed, ?string $requested, string $fallback): string
    {
        return $this->resolveSortColumn($allowed, $requested, $fallback);
    }

    public function direction(?string $requested): string
    {
        return $this->resolveDirection($requested);
    }
}
