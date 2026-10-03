<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Warehouse;

interface WarehouseRepositoryInterface
{
    public function findById(int $id): ?Warehouse;

    public function exists(int $id): bool;

    /** @return list<Warehouse> */
    public function all(): array;

    /** @return list<Warehouse> */
    public function allActive(): array;

    public function save(Warehouse $warehouse): int;

    public function setActive(int $id, bool $isActive): void;
}
