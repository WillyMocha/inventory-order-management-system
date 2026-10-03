<?php

declare(strict_types=1);

namespace App\Repository\Mysql;

use App\Entity\Warehouse;
use App\Repository\WarehouseRepositoryInterface;

final class MysqlWarehouseRepository extends MysqlRepository implements WarehouseRepositoryInterface
{
    private const string SELECT = 'SELECT id, name, location, is_active FROM warehouse';

    public function findById(int $id): ?Warehouse
    {
        $row = $this->fetchOne(self::SELECT . ' WHERE id = :id', ['id' => $id]);

        return $row === null ? null : $this->hydrate($row);
    }

    public function exists(int $id): bool
    {
        return $this->fetchInt('SELECT COUNT(*) FROM warehouse WHERE id = :id', ['id' => $id]) > 0;
    }

    public function all(): array
    {
        return array_map(
            fn (array $row): Warehouse => $this->hydrate($row),
            $this->fetchAll(self::SELECT . ' ORDER BY name ASC'),
        );
    }

    public function allActive(): array
    {
        return array_map(
            fn (array $row): Warehouse => $this->hydrate($row),
            $this->fetchAll(self::SELECT . ' WHERE is_active = 1 ORDER BY name ASC'),
        );
    }

    public function save(Warehouse $warehouse): int
    {
        if ($warehouse->id === null) {
            $this->run(
                'INSERT INTO warehouse (name, location, is_active, created_at, updated_at)
                      VALUES (:name, :location, :is_active, NOW(), NOW())',
                [
                    'name'      => $warehouse->name,
                    'location'  => $warehouse->location,
                    'is_active' => $warehouse->isActive ? 1 : 0,
                ],
            );

            return $this->lastInsertId();
        }

        $this->run(
            'UPDATE warehouse
                SET name = :name, location = :location, is_active = :is_active, updated_at = NOW()
              WHERE id = :id',
            [
                'id'        => $warehouse->id,
                'name'      => $warehouse->name,
                'location'  => $warehouse->location,
                'is_active' => $warehouse->isActive ? 1 : 0,
            ],
        );

        return $warehouse->id;
    }

    public function setActive(int $id, bool $isActive): void
    {
        $this->run(
            'UPDATE warehouse SET is_active = :is_active, updated_at = NOW() WHERE id = :id',
            ['id' => $id, 'is_active' => $isActive ? 1 : 0],
        );
    }

    /** @param array<string, mixed> $row */
    private function hydrate(array $row): Warehouse
    {
        return new Warehouse(
            (int) $row['id'],
            (string) $row['name'],
            (string) $row['location'],
            (bool) $row['is_active'],
        );
    }
}
