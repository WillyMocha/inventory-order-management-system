<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Product;
use App\Repository\CategoryRepositoryInterface;
use App\Repository\ProductRepositoryInterface;
use App\Repository\ProductStockRepositoryInterface;
use App\Support\Exception\NotFoundException;
use App\Support\Validator;

/**
 * Katalog product dan visibilitas stock (PRD-01, WH-01).
 *
 * Perhatikan apa yang TIDAK ada di sini: tidak ada operasi delete. §1.3
 * menyatakan product dinonaktifkan, bukan dihapus permanen, sehingga jalur
 * penghapusannya memang tidak dibuat sama sekali - bukan dibuat lalu dijaga.
 *
 * Service ini juga tidak pernah mengubah quantity stock. Perubahan stock hanya
 * melalui StockService yang sekaligus menulis stock_ledger (ARCH-02).
 */
final class ProductService
{
    public function __construct(
        private readonly ProductRepositoryInterface $products,
        private readonly ProductStockRepositoryInterface $stocks,
        private readonly CategoryRepositoryInterface $categories,
    ) {
    }

    /**
     * @param array<string, mixed> $data
     *
     * @throws \App\Support\Exception\ValidationException
     */
    public function create(array $data): int
    {
        $this->validate($data, null);

        return $this->products->save($this->hydrate(null, $data, null, true));
    }

    /**
     * @param array<string, mixed> $data
     *
     * @throws NotFoundException
     * @throws \App\Support\Exception\ValidationException
     */
    public function update(int $id, array $data): void
    {
        $existing = $this->requireProduct($id);

        $this->validate($data, $id);

        // image_path dan is_active tidak diubah lewat form ini: image punya
        // jalurnya sendiri, status punya toggle-nya sendiri.
        $this->products->save($this->hydrate($id, $data, $existing->imagePath, $existing->isActive));
    }

    /** @throws NotFoundException */
    public function toggleActive(int $id): void
    {
        $product = $this->requireProduct($id);

        $this->products->setActive($id, !$product->isActive);
    }

    /**
     * True bila product sudah dipakai pada order line mana pun.
     *
     * Dipakai UI untuk menjelaskan mengapa hanya deactivate yang tersedia
     * (PRD-01).
     */
    public function isReferencedByOrder(int $id): bool
    {
        return $this->products->isReferencedByOrder($id);
    }

    /**
     * Rincian stock per warehouse beserta totalnya (WH-01, FR-011).
     *
     * Low stock dievaluasi terhadap TOTAL seluruh warehouse, bukan per
     * warehouse (spec A-008).
     *
     * @return array{
     *     product: Product,
     *     warehouses: list<array{warehouseId: int, warehouseName: string, quantity: int}>,
     *     total: int,
     *     isLowStock: bool
     * }
     *
     * @throws NotFoundException
     */
    public function stockBreakdown(int $productId): array
    {
        $product = $this->requireProduct($productId);
        $warehouses = $this->stocks->breakdownForProduct($productId);

        $total = 0;
        foreach ($warehouses as $row) {
            $total += $row['quantity'];
        }

        return [
            'product'    => $product,
            'warehouses' => $warehouses,
            'total'      => $total,
            'isLowStock' => $product->isLowStock($total),
        ];
    }

    /**
     * Rincian stock berdasarkan SKU, bukan id (API-01, FR-028).
     *
     * SKU-lah identitas yang dipegang pemanggil API; id internal tidak pernah
     * muncul di contract endpoint availability. SKU yang tidak dikenal
     * menghasilkan NotFound, bukan hasil kosong — "product ini tidak ada" dan
     * "product ini ada tetapi stocknya nol" adalah dua jawaban yang berbeda.
     *
     * @return array{
     *     product: Product,
     *     warehouses: list<array{warehouseId: int, warehouseName: string, quantity: int}>,
     *     total: int,
     *     isLowStock: bool
     * }
     *
     * @throws NotFoundException
     */
    public function stockBreakdownBySku(string $sku): array
    {
        $product = $this->products->findBySku($sku);

        if ($product === null) {
            throw new NotFoundException();
        }

        return $this->stockBreakdown((int) $product->id);
    }

    /**
     * Quantity on hand satu pasangan product dan warehouse (FR-028).
     *
     * Nilainya INDIKATIF. Keputusan yang mengikat tetap terjadi di dalam
     * transaction goods issue dengan SELECT ... FOR UPDATE (ARCH-02) — angka
     * di sini bisa sudah basi saat goods issue berjalan, dan itu memang
     * diharapkan.
     *
     * Pasangan yang sah tanpa baris stock bernilai 0, bukan NotFound: product
     * dan warehouse-nya ada, stocknya saja yang kosong. Keberadaan warehouse
     * diperiksa pemanggil.
     *
     * @throws NotFoundException bila product tidak ada
     */
    public function availableQuantity(int $productId, int $warehouseId): int
    {
        $this->requireProduct($productId);

        $stock = $this->stocks->findFor($productId, $warehouseId);

        return $stock === null ? 0 : $stock->quantity;
    }

    /**
     * @param array{search?: string, categoryId?: int, lowStock?: bool, active?: bool} $criteria
     * @return list<Product>
     */
    public function search(array $criteria, int $limit, int $offset): array
    {
        return $this->products->search($criteria, $limit, $offset);
    }

    /** @param array{search?: string, categoryId?: int, lowStock?: bool, active?: bool} $criteria */
    public function count(array $criteria): int
    {
        return $this->products->countBy($criteria);
    }

    /** @return list<Product> */
    public function activeCatalog(): array
    {
        return $this->products->allActive();
    }

    /**
     * Product pada atau di bawah reorder point. Dipakai dashboard (DASH-01)
     * dan script check-low-stock (JOB-01).
     *
     * @return list<array{product: Product, totalQuantity: int}>
     */
    public function lowStock(int $limit): array
    {
        return $this->products->lowStock($limit);
    }

    public function totalInventoryValue(): string
    {
        return $this->products->totalInventoryValue();
    }

    public function totalStockFor(int $productId): int
    {
        return $this->stocks->totalForProduct($productId);
    }

    public function updateImagePath(int $id, ?string $imagePath): void
    {
        $this->products->updateImagePath($id, $imagePath);
    }

    /** @throws NotFoundException */
    public function requireProduct(int $id): Product
    {
        $product = $this->products->findById($id);

        if ($product === null) {
            throw new NotFoundException();
        }

        return $product;
    }

    /** @param array<string, mixed> $data */
    private function hydrate(?int $id, array $data, ?string $imagePath, bool $isActive): Product
    {
        return new Product(
            $id,
            strtoupper(trim((string) $data['sku'])),
            trim((string) $data['name']),
            (int) $data['category_id'],
            trim((string) $data['unit']),
            $this->normalizeMoney((string) $data['purchase_price']),
            $this->normalizeMoney((string) $data['selling_price']),
            (int) $data['reorder_point'],
            $imagePath,
            $isActive,
        );
    }

    /** DECIMAL(15,2) - dinormalisasi sebagai string agar presisi tidak hilang. */
    private function normalizeMoney(string $value): string
    {
        return number_format((float) $value, 2, '.', '');
    }

    /**
     * @param array<string, mixed> $data
     * @param int|null $exceptId id yang dikecualikan dari pemeriksaan SKU unik
     */
    private function validate(array $data, ?int $exceptId): void
    {
        $sku = strtoupper(trim((string) ($data['sku'] ?? '')));

        $validator = Validator::make($data)
            ->required('sku', 'SKU')
            ->maxLength('sku', 'SKU', 64)
            ->required('name', 'Name')
            ->maxLength('name', 'Name', 200)
            ->required('unit', 'Unit')
            ->maxLength('unit', 'Unit', 32)
            ->required('category_id', 'Category')
            ->existsById('category_id', 'Category', fn (int $id): bool => $this->categories->exists($id))
            ->required('purchase_price', 'Purchase price')
            ->decimalMin('purchase_price', 'Purchase price', 0)
            ->required('selling_price', 'Selling price')
            ->decimalMin('selling_price', 'Selling price', 0)
            ->required('reorder_point', 'Reorder point')
            ->integerMin('reorder_point', 'Reorder point', 0);

        if ($sku !== '') {
            $validator->rule(
                'sku',
                !$this->products->skuExists($sku, $exceptId),
                'That SKU is already in use.',
            );
        }

        $validator->validate();
    }
}
