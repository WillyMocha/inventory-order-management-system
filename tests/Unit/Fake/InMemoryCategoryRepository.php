<?php

declare(strict_types=1);

namespace Tests\Unit\Fake;

use App\Entity\Category;
use App\Repository\CategoryRepositoryInterface;

final class InMemoryCategoryRepository implements CategoryRepositoryInterface
{
    /** @var array<int, Category> */
    private array $rows = [];

    private int $nextId = 1;

    /** @param list<Category> $categories */
    public function __construct(array $categories = [])
    {
        foreach ($categories as $category) {
            $this->save($category);
        }
    }

    public function findById(int $id): ?Category
    {
        return $this->rows[$id] ?? null;
    }

    public function exists(int $id): bool
    {
        return isset($this->rows[$id]);
    }

    public function nameExists(string $name, ?int $exceptId = null): bool
    {
        foreach ($this->rows as $id => $category) {
            if (strcasecmp($category->name, $name) === 0 && $id !== $exceptId) {
                return true;
            }
        }

        return false;
    }

    public function all(): array
    {
        return array_values($this->rows);
    }

    public function save(Category $category): int
    {
        $id = $category->id ?? $this->nextId++;

        $this->rows[$id] = new Category($id, $category->name, $category->description);

        if ($id >= $this->nextId) {
            $this->nextId = $id + 1;
        }

        return $id;
    }
}
