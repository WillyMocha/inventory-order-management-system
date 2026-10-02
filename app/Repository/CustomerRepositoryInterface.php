<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Customer;

interface CustomerRepositoryInterface
{
    public function findById(int $id): ?Customer;

    public function exists(int $id): bool;

    /** @return list<Customer> */
    public function all(): array;

    /** @return list<Customer> */
    public function allActive(): array;

    /**
     * @param array{search?: string, active?: bool} $criteria
     * @return list<Customer>
     */
    public function search(array $criteria, int $limit, int $offset): array;

    /** @param array{search?: string, active?: bool} $criteria */
    public function countBy(array $criteria): int;

    public function save(Customer $customer): int;

    public function setActive(int $id, bool $isActive): void;

    public function isReferencedByOrder(int $id): bool;
}
