<?php

declare(strict_types=1);

namespace App\Repository\Mysql;

use App\Support\Database;
use PDO;
use PDOStatement;

/**
 * Basis bersama seluruh MySQL repository.
 *
 * Menyediakan akses PDO dan helper query agar boilerplate prepare/execute
 * tidak diulang di sebelas kelas (SonarQube §4: hindari duplikasi).
 *
 * Setiap query WAJIB memakai bound parameter. Tidak ada satu pun tempat di
 * kelas turunan yang boleh menyambung input user ke dalam string SQL.
 */
abstract class MysqlRepository
{
    public function __construct(protected readonly Database $db)
    {
    }

    protected function pdo(): PDO
    {
        return $this->db->pdo();
    }

    /**
     * @param array<string, mixed> $params
     */
    protected function run(string $sql, array $params = []): PDOStatement
    {
        $statement = $this->pdo()->prepare($sql);
        $statement->execute($params);

        return $statement;
    }

    /**
     * @param array<string, mixed> $params
     * @return array<string, mixed>|null
     */
    protected function fetchOne(string $sql, array $params = []): ?array
    {
        $row = $this->run($sql, $params)->fetch();

        return is_array($row) ? $row : null;
    }

    /**
     * @param array<string, mixed> $params
     * @return list<array<string, mixed>>
     */
    protected function fetchAll(string $sql, array $params = []): array
    {
        /** @var list<array<string, mixed>> $rows */
        $rows = $this->run($sql, $params)->fetchAll();

        return $rows;
    }

    /**
     * @param array<string, mixed> $params
     */
    protected function fetchInt(string $sql, array $params = []): int
    {
        $value = $this->run($sql, $params)->fetchColumn();

        return is_numeric($value) ? (int) $value : 0;
    }

    /**
     * @param array<string, mixed> $params
     */
    protected function fetchString(string $sql, array $params = [], string $default = '0'): string
    {
        $value = $this->run($sql, $params)->fetchColumn();

        return is_scalar($value) ? (string) $value : $default;
    }

    protected function lastInsertId(): int
    {
        return (int) $this->pdo()->lastInsertId();
    }

    /**
     * Sort key dipetakan lewat allowlist, tidak pernah diinterpolasi dari
     * input user (FIND-01, security standard §5).
     *
     * @param array<string, string> $allowed key aman => nama kolom
     */
    protected function resolveSortColumn(array $allowed, ?string $requested, string $fallback): string
    {
        if ($requested !== null && isset($allowed[$requested])) {
            return $allowed[$requested];
        }

        return $fallback;
    }

    protected function resolveDirection(?string $requested): string
    {
        return strtolower($requested ?? '') === 'asc' ? 'ASC' : 'DESC';
    }
}
