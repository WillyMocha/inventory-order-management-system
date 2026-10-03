<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Product;

interface ProductRepositoryInterface
{
    public function findById(int $id): ?Product;

    public function findBySku(string $sku): ?Product;

    public function exists(int $id): bool;

    public function skuExists(string $sku, ?int $exceptId = null): bool;

    /** @return list<Product> */
    public function allActive(): array;

    /**
     * Search dan filter untuk FIND-01.
     *
     * lowStock difilter berdasarkan total quantity seluruh warehouse
     * dibandingkan reorder_point (spec A-008).
     *
     * sort dipetakan lewat allowlist di implementasinya, tidak pernah
     * diinterpolasi dari input user (security standard §5).
     *
     * @param array{search?: string, categoryId?: int, lowStock?: bool,
     *     active?: bool, sort?: string, direction?: string} $criteria
     * @return list<Product>
     */
    public function search(array $criteria, int $limit, int $offset): array;

    /** @param array{search?: string, categoryId?: int, lowStock?: bool, active?: bool} $criteria */
    public function countBy(array $criteria): int;

    /**
     * Product yang berada pada atau di bawah reorder point. Dipakai dashboard
     * dan script check-low-stock (DASH-01, JOB-01).
     *
     * @return list<array{product: Product, totalQuantity: int}>
     */
    public function lowStock(int $limit): array;

    public function save(Product $product): int;

    public function setActive(int $id, bool $isActive): void;

    /**
     * True bila product sudah dipakai pada order line mana pun — hanya boleh
     * dinonaktifkan, tidak boleh dihapus (PRD-01).
     */
    public function isReferencedByOrder(int $id): bool;

    public function updateImagePath(int $id, ?string $imagePath): void;

    /** Nilai inventori = SUM(quantity * purchase_price) (spec A-007). */
    public function totalInventoryValue(): string;
}
