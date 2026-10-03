<?php

declare(strict_types=1);

namespace Tests\Unit\Fake;

use App\Entity\Supplier;
use App\Repository\SupplierRepositoryInterface;

final class InMemorySupplierRepository implements SupplierRepositoryInterface
{
    /** @var array<int, Supplier> */
    private array $rows = [];

    private int $nextId = 1;

    /** @var array<int, bool> */
    private array $referenced = [];

    /** @param list<Supplier> $suppliers */
    public function __construct(array $suppliers = [])
    {
        foreach ($suppliers as $supplier) {
            $this->save($supplier);
        }
    }

    /** Menandai supplier sebagai sudah dipakai PO (untuk test §1.3). */
    public function markReferenced(int $id): void
    {
        $this->referenced[$id] = true;
    }

    public function findById(int $id): ?Supplier
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
            static fn (Supplier $s): bool => $s->isActive,
        ));
    }

    public function search(array $criteria, int $limit, int $offset): array
    {
        return array_slice($this->filter($criteria), $offset, $limit);
    }

    public function countBy(array $criteria): int
    {
        return count($this->filter($criteria));
    }

    public function save(Supplier $supplier): int
    {
        $id = $supplier->id ?? $this->nextId++;

        $this->rows[$id] = new Supplier(
            $id,
            $supplier->name,
            $supplier->contact,
            $supplier->address,
            $supplier->isActive,
        );

        if ($id >= $this->nextId) {
            $this->nextId = $id + 1;
        }

        return $id;
    }

    public function setActive(int $id, bool $isActive): void
    {
        $supplier = $this->rows[$id] ?? null;

        if ($supplier === null) {
            return;
        }

        $this->rows[$id] = new Supplier(
            $id,
            $supplier->name,
            $supplier->contact,
            $supplier->address,
            $isActive,
        );
    }

    public function isReferencedByOrder(int $id): bool
    {
        return $this->referenced[$id] ?? false;
    }

    /**
     * @param array{search?: string, active?: bool} $criteria
     * @return list<Supplier>
     */
    private function filter(array $criteria): array
    {
        $result = [];

        foreach ($this->rows as $supplier) {
            if (($criteria['search'] ?? '') !== '') {
                $needle = strtolower((string) $criteria['search']);
                $haystack = strtolower($supplier->name . ' ' . $supplier->contact);

                if (!str_contains($haystack, $needle)) {
                    continue;
                }
            }

            if (isset($criteria['active']) && $supplier->isActive !== $criteria['active']) {
                continue;
            }

            $result[] = $supplier;
        }

        return $result;
    }
}
