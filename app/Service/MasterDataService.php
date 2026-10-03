<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Category;
use App\Entity\Warehouse;
use App\Repository\CategoryRepositoryInterface;
use App\Repository\WarehouseRepositoryInterface;
use App\Support\Exception\NotFoundException;
use App\Support\Validator;

/**
 * Master data pendukung: Category dan Warehouse.
 *
 * Keduanya disatukan di sini karena aturannya sangat tipis dan identik
 * bentuknya. Supplier dan Customer TIDAK ikut - keduanya punya relasi order
 * dan aturan referensi sendiri, jadi ditangani PartyService.
 */
final class MasterDataService
{
    public function __construct(
        private readonly CategoryRepositoryInterface $categories,
        private readonly WarehouseRepositoryInterface $warehouses,
    ) {
    }

    // ------------------------------------------------------------ Category

    /** @return list<Category> */
    public function allCategories(): array
    {
        return $this->categories->all();
    }

    /** @throws NotFoundException */
    public function requireCategory(int $id): Category
    {
        $category = $this->categories->findById($id);

        if ($category === null) {
            throw new NotFoundException();
        }

        return $category;
    }

    /**
     * @param array<string, mixed> $data
     *
     * @throws \App\Support\Exception\ValidationException
     */
    public function createCategory(array $data): int
    {
        $this->validateCategory($data, null);

        return $this->categories->save(new Category(
            null,
            trim((string) $data['name']),
            $this->optionalText($data['description'] ?? null),
        ));
    }

    /**
     * @param array<string, mixed> $data
     *
     * @throws NotFoundException
     * @throws \App\Support\Exception\ValidationException
     */
    public function updateCategory(int $id, array $data): void
    {
        $this->requireCategory($id);
        $this->validateCategory($data, $id);

        $this->categories->save(new Category(
            $id,
            trim((string) $data['name']),
            $this->optionalText($data['description'] ?? null),
        ));
    }

    // ----------------------------------------------------------- Warehouse

    /** @return list<Warehouse> */
    public function allWarehouses(): array
    {
        return $this->warehouses->all();
    }

    /** @return list<Warehouse> */
    public function activeWarehouses(): array
    {
        return $this->warehouses->allActive();
    }

    /** @throws NotFoundException */
    public function requireWarehouse(int $id): Warehouse
    {
        $warehouse = $this->warehouses->findById($id);

        if ($warehouse === null) {
            throw new NotFoundException();
        }

        return $warehouse;
    }

    /**
     * @param array<string, mixed> $data
     *
     * @throws \App\Support\Exception\ValidationException
     */
    public function createWarehouse(array $data): int
    {
        $this->validateWarehouse($data);

        return $this->warehouses->save(new Warehouse(
            null,
            trim((string) $data['name']),
            trim((string) $data['location']),
            true,
        ));
    }

    /**
     * @param array<string, mixed> $data
     *
     * @throws NotFoundException
     * @throws \App\Support\Exception\ValidationException
     */
    public function updateWarehouse(int $id, array $data): void
    {
        $existing = $this->requireWarehouse($id);
        $this->validateWarehouse($data);

        $this->warehouses->save(new Warehouse(
            $id,
            trim((string) $data['name']),
            trim((string) $data['location']),
            $existing->isActive,
        ));
    }

    /** @throws NotFoundException */
    public function toggleWarehouseActive(int $id): void
    {
        $warehouse = $this->requireWarehouse($id);

        $this->warehouses->setActive($id, !$warehouse->isActive);
    }

    // ------------------------------------------------------------ helpers

    /** @param array<string, mixed> $data */
    private function validateCategory(array $data, ?int $exceptId): void
    {
        $name = trim((string) ($data['name'] ?? ''));

        $validator = Validator::make($data)
            ->required('name', 'Name')
            ->maxLength('name', 'Name', 150);

        if ($name !== '') {
            $validator->rule(
                'name',
                !$this->categories->nameExists($name, $exceptId),
                'A category with that name already exists.',
            );
        }

        $validator->validate();
    }

    /** @param array<string, mixed> $data */
    private function validateWarehouse(array $data): void
    {
        Validator::make($data)
            ->required('name', 'Name')
            ->maxLength('name', 'Name', 150)
            ->required('location', 'Location')
            ->maxLength('location', 'Location', 255)
            ->validate();
    }

    /**
     * `mixed` dibenarkan di sini: nilainya berasal LANGSUNG dari body request
     * yang belum tervalidasi, sehingga tipenya memang belum diketahui — itulah
     * yang hendak ditentukan method ini (constitution Principle II).
     */
    private function optionalText(mixed $value): ?string
    {
        $text = trim((string) ($value ?? ''));

        return $text === '' ? null : $text;
    }
}
