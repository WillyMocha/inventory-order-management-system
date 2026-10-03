<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Supplier;

interface SupplierRepositoryInterface
{
    public function findById(int $id): ?Supplier;

    public function exists(int $id): bool;

    /** @return list<Supplier> */
    public function all(): array;

    /** @return list<Supplier> */
    public function allActive(): array;

    /**
     * @param array{search?: string, active?: bool} $criteria
     * @return list<Supplier>
     */
    public function search(array $criteria, int $limit, int $offset): array;

    /** @param array{search?: string, active?: bool} $criteria */
    public function countBy(array $criteria): int;

    public function save(Supplier $supplier): int;

    public function setActive(int $id, bool $isActive): void;

    /** Dipakai memutuskan deactivate vs delete (PRD-01, §1.3). */
    public function isReferencedByOrder(int $id): bool;
}
