<?php

declare(strict_types=1);

namespace Tests\Unit\Fake;

use App\Entity\Warehouse;
use App\Repository\WarehouseRepositoryInterface;

final class InMemoryWarehouseRepository implements WarehouseRepositoryInterface
{
    /** @var array<int, Warehouse> */
    private array $rows = [];

    private int $nextId = 1;

    /** @param list<Warehouse> $warehouses */
    public function __construct(array $warehouses = [])
    {
        foreach ($warehouses as $warehouse) {
            $this->save($warehouse);
        }
    }

    public function findById(int $id): ?Warehouse
    {
        return $this->rows[$id] ?? null;
    }

    public function exists(int $id): bool
    {
        return isset($this->rows[$id]);
    }

    public function all(): array
    {
        return array_values($this->rows);
    }

    public function allActive(): array
    {
        return array_values(array_filter(
            $this->rows,
            static fn (Warehouse $w): bool => $w->isActive,
        ));
    }

    public function save(Warehouse $warehouse): int
    {
        $id = $warehouse->id ?? $this->nextId++;

        $this->rows[$id] = new Warehouse($id, $warehouse->name, $warehouse->location, $warehouse->isActive);

        if ($id >= $this->nextId) {
            $this->nextId = $id + 1;
        }

        return $id;
    }

    public function setActive(int $id, bool $isActive): void
    {
        $warehouse = $this->rows[$id] ?? null;

        if ($warehouse === null) {
            return;
        }

        $this->rows[$id] = new Warehouse($id, $warehouse->name, $warehouse->location, $isActive);
    }
}
