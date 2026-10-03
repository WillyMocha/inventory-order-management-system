<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Category;

interface CategoryRepositoryInterface
{
    public function findById(int $id): ?Category;

    public function exists(int $id): bool;

    public function nameExists(string $name, ?int $exceptId = null): bool;

    /** @return list<Category> */
    public function all(): array;

    public function save(Category $category): int;
}
