<?php

declare(strict_types=1);

namespace Tests\Unit\Fake;

use App\Entity\Product;
use App\Repository\ProductRepositoryInterface;

final class InMemoryProductRepository implements ProductRepositoryInterface
{
    /** @var array<int, Product> */
    private array $rows = [];

    private int $nextId = 1;

    /** @var array<int, bool> productId => dipakai order */
    private array $referenced = [];

    /** @var array<int, int> productId => total quantity seluruh warehouse */
    private array $totals = [];

    /** @param list<Product> $products */
    public function __construct(array $products = [])
    {
        foreach ($products as $product) {
            $this->save($product);
        }
    }

    /** Menandai product sebagai sudah dipakai order (untuk test PRD-01). */
    public function markReferenced(int $productId): void
    {
        $this->referenced[$productId] = true;
    }

    /** Menyetel total stock agar filter low-stock dapat diuji. */
    public function setTotalQuantity(int $productId, int $total): void
    {
        $this->totals[$productId] = $total;
    }

    public function findById(int $id): ?Product
    {
        return $this->rows[$id] ?? null;
    }

    public function findBySku(string $sku): ?Product
    {
        foreach ($this->rows as $product) {
            if ($product->sku === $sku) {
                return $product;
            }
        }

        return null;
    }

    public function exists(int $id): bool
    {
        return isset($this->rows[$id]);
    }

    public function skuExists(string $sku, ?int $exceptId = null): bool
    {
        foreach ($this->rows as $id => $product) {
            if ($product->sku === $sku && $id !== $exceptId) {
                return true;
            }
        }

        return false;
    }

    public function highestSkuSequence(string $prefix): int
    {
        $highest = 0;

        foreach ($this->rows as $product) {
            if (!str_starts_with($product->sku, $prefix)) {
                continue;
            }

            $suffix = substr($product->sku, strlen($prefix));

            if ($suffix !== '' && ctype_digit($suffix)) {
                $highest = max($highest, (int) $suffix);
            }
        }

        return $highest;
    }

    public function allActive(): array
    {
        return array_values(array_filter(
            $this->rows,
            static fn (Product $p): bool => $p->isActive,
        ));
    }

    public function search(array $criteria, int $limit, int $offset): array
    {
        $matching = $this->filter($criteria);

        $this->sort($matching, $criteria['sort'] ?? null, $criteria['direction'] ?? 'asc');

        return array_slice($matching, $offset, $limit);
    }

    /**
     * Mengurutkan hasil sesuai key sort yang diizinkan.
     *
     * Key yang tidak dikenal jatuh ke 'name', persis seperti allowlist pada
     * implementasi MySQL-nya — kalau fake ini menerima key apa pun, unit test
     * bisa lulus untuk perilaku yang produksinya justru menolak.
     *
     * @param list<Product> $products
     */
    private function sort(array &$products, ?string $sort, ?string $direction): void
    {
        $comparators = [
            'name'  => static fn (Product $a, Product $b): int => strcasecmp($a->name, $b->name),
            'sku'   => static fn (Product $a, Product $b): int => strcasecmp($a->sku, $b->sku),
            'price' => static fn (Product $a, Product $b): int
                => (float) $a->sellingPrice <=> (float) $b->sellingPrice,
        ];

        $comparator = $comparators[$sort ?? ''] ?? $comparators['name'];
        $descending = strtolower($direction ?? '') !== 'asc';

        usort(
            $products,
            static fn (Product $a, Product $b): int => $descending
                ? $comparator($b, $a)
                : $comparator($a, $b),
        );
    }

    public function countBy(array $criteria): int
    {
        return count($this->filter($criteria));
    }

    public function lowStock(int $limit): array
    {
        $result = [];

        foreach ($this->rows as $id => $product) {
            if (!$product->isActive) {
                continue;
            }

            $total = $this->totals[$id] ?? 0;

            if ($product->isLowStock($total)) {
                $result[] = ['product' => $product, 'totalQuantity' => $total];
            }
        }

        return array_slice($result, 0, $limit);
    }

    public function save(Product $product): int
    {
        $id = $product->id ?? $this->nextId++;

        $this->rows[$id] = new Product(
            $id,
            $product->sku,
            $product->name,
            $product->categoryId,
            $product->unit,
            $product->purchasePrice,
            $product->sellingPrice,
            $product->reorderPoint,
            $product->imagePath,
            $product->isActive,
        );

        if ($id >= $this->nextId) {
            $this->nextId = $id + 1;
        }

        return $id;
    }

    public function setActive(int $id, bool $isActive): void
    {
        $product = $this->rows[$id] ?? null;

        if ($product === null) {
            return;
        }

        $this->rows[$id] = new Product(
            $id,
            $product->sku,
            $product->name,
            $product->categoryId,
            $product->unit,
            $product->purchasePrice,
            $product->sellingPrice,
            $product->reorderPoint,
            $product->imagePath,
            $isActive,
        );
    }

    public function isReferencedByOrder(int $id): bool
    {
        return $this->referenced[$id] ?? false;
    }

    public function updateImagePath(int $id, ?string $imagePath): void
    {
        $product = $this->rows[$id] ?? null;

        if ($product === null) {
            return;
        }

        $this->rows[$id] = new Product(
            $id,
            $product->sku,
            $product->name,
            $product->categoryId,
            $product->unit,
            $product->purchasePrice,
            $product->sellingPrice,
            $product->reorderPoint,
            $imagePath,
            $product->isActive,
        );
    }

    public function totalInventoryValue(): string
    {
        $value = 0.0;

        foreach ($this->rows as $id => $product) {
            $value += ($this->totals[$id] ?? 0) * (float) $product->purchasePrice;
        }

        return number_format($value, 2, '.', '');
    }

    /**
     * @param array{search?: string, categoryId?: int, lowStock?: bool, active?: bool} $criteria
     * @return list<Product>
     */
    private function filter(array $criteria): array
    {
        $result = [];

        foreach ($this->rows as $id => $product) {
            if (($criteria['search'] ?? '') !== '') {
                $needle = strtolower((string) $criteria['search']);
                $haystack = strtolower($product->name . ' ' . $product->sku);

                if (!str_contains($haystack, $needle)) {
                    continue;
                }
            }

            if (($criteria['categoryId'] ?? 0) > 0 && $product->categoryId !== $criteria['categoryId']) {
                continue;
            }

            if (isset($criteria['active']) && $product->isActive !== $criteria['active']) {
                continue;
            }

            if (isset($criteria['lowStock'])) {
                $isLow = $product->isLowStock($this->totals[$id] ?? 0);

                if ($isLow !== $criteria['lowStock']) {
                    continue;
                }
            }

            $result[] = $product;
        }

        return $result;
    }
}
