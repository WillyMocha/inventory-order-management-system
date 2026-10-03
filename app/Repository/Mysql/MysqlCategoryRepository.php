<?php

declare(strict_types=1);

namespace App\Repository\Mysql;

use App\Entity\Category;
use App\Repository\CategoryRepositoryInterface;

final class MysqlCategoryRepository extends MysqlRepository implements CategoryRepositoryInterface
{
    private const string SELECT = 'SELECT id, name, description FROM category';

    public function findById(int $id): ?Category
    {
        $row = $this->fetchOne(self::SELECT . ' WHERE id = :id', ['id' => $id]);

        return $row === null ? null : $this->hydrate($row);
    }

    public function exists(int $id): bool
    {
        return $this->fetchInt('SELECT COUNT(*) FROM category WHERE id = :id', ['id' => $id]) > 0;
    }

    public function nameExists(string $name, ?int $exceptId = null): bool
    {
        if ($exceptId === null) {
            return $this->fetchInt(
                'SELECT COUNT(*) FROM category WHERE name = :name',
                ['name' => $name],
            ) > 0;
        }

        return $this->fetchInt(
            'SELECT COUNT(*) FROM category WHERE name = :name AND id <> :id',
            ['name' => $name, 'id' => $exceptId],
        ) > 0;
    }

    public function all(): array
    {
        return array_map(
            fn (array $row): Category => $this->hydrate($row),
            $this->fetchAll(self::SELECT . ' ORDER BY name ASC'),
        );
    }

    public function save(Category $category): int
    {
        if ($category->id === null) {
            $this->run(
                'INSERT INTO category (name, description, created_at, updated_at)
                      VALUES (:name, :description, NOW(), NOW())',
                ['name' => $category->name, 'description' => $category->description],
            );

            return $this->lastInsertId();
        }

        $this->run(
            'UPDATE category SET name = :name, description = :description, updated_at = NOW() WHERE id = :id',
            ['id' => $category->id, 'name' => $category->name, 'description' => $category->description],
        );

        return $category->id;
    }

    /** @param array<string, mixed> $row */
    private function hydrate(array $row): Category
    {
        $description = $row['description'];

        return new Category(
            (int) $row['id'],
            (string) $row['name'],
            $description === null ? null : (string) $description,
        );
    }
}
