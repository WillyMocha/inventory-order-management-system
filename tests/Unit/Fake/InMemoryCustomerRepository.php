<?php

declare(strict_types=1);

namespace Tests\Unit\Fake;

use App\Entity\Customer;
use App\Repository\CustomerRepositoryInterface;

final class InMemoryCustomerRepository implements CustomerRepositoryInterface
{
    /** @var array<int, Customer> */
    private array $rows = [];

    private int $nextId = 1;

    /** @var array<int, bool> */
    private array $referenced = [];

    /** @param list<Customer> $customers */
    public function __construct(array $customers = [])
    {
        foreach ($customers as $customer) {
            $this->save($customer);
        }
    }

    /** Menandai customer sebagai sudah dipakai SO (untuk test §1.3). */
    public function markReferenced(int $id): void
    {
        $this->referenced[$id] = true;
    }

    public function findById(int $id): ?Customer
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
            static fn (Customer $c): bool => $c->isActive,
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

    public function save(Customer $customer): int
    {
        $id = $customer->id ?? $this->nextId++;

        $this->rows[$id] = new Customer(
            $id,
            $customer->name,
            $customer->contact,
            $customer->address,
            $customer->isActive,
        );

        if ($id >= $this->nextId) {
            $this->nextId = $id + 1;
        }

        return $id;
    }

    public function setActive(int $id, bool $isActive): void
    {
        $customer = $this->rows[$id] ?? null;

        if ($customer === null) {
            return;
        }

        $this->rows[$id] = new Customer(
            $id,
            $customer->name,
            $customer->contact,
            $customer->address,
            $isActive,
        );
    }

    public function isReferencedByOrder(int $id): bool
    {
        return $this->referenced[$id] ?? false;
    }

    /**
     * @param array{search?: string, active?: bool} $criteria
     * @return list<Customer>
     */
    private function filter(array $criteria): array
    {
        $result = [];

        foreach ($this->rows as $customer) {
            if (($criteria['search'] ?? '') !== '') {
                $needle = strtolower((string) $criteria['search']);
                $haystack = strtolower($customer->name . ' ' . $customer->contact);

                if (!str_contains($haystack, $needle)) {
                    continue;
                }
            }

            if (isset($criteria['active']) && $customer->isActive !== $criteria['active']) {
                continue;
            }

            $result[] = $customer;
        }

        return $result;
    }
}
