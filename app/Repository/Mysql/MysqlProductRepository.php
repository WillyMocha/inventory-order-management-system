<?php

declare(strict_types=1);

namespace App\Repository\Mysql;

use App\Entity\Product;
use App\Repository\ProductRepositoryInterface;

final class MysqlProductRepository extends MysqlRepository implements ProductRepositoryInterface
{
    private const string SELECT = 'SELECT p.id, p.sku, p.name, p.category_id, p.unit,
                   p.purchase_price, p.selling_price, p.reorder_point,
                   p.image_path, p.is_active
              FROM product p';

    /**
     * Allowlist sort. Kunci yang tidak terdaftar diabaikan, sehingga nama
     * kolom tidak pernah berasal dari input user (security standard §5).
     *
     * @var array<string, string>
     */
    private const array SORTABLE = [
        'name'  => 'p.name',
        'sku'   => 'p.sku',
        'price' => 'p.selling_price',
    ];

    public function findById(int $id): ?Product
    {
        $row = $this->fetchOne(self::SELECT . ' WHERE p.id = :id', ['id' => $id]);

        return $row === null ? null : $this->hydrate($row);
    }

    public function findBySku(string $sku): ?Product
    {
        $row = $this->fetchOne(self::SELECT . ' WHERE p.sku = :sku', ['sku' => $sku]);

        return $row === null ? null : $this->hydrate($row);
    }

    public function exists(int $id): bool
    {
        return $this->fetchInt('SELECT COUNT(*) FROM product WHERE id = :id', ['id' => $id]) > 0;
    }

    public function skuExists(string $sku, ?int $exceptId = null): bool
    {
        if ($exceptId === null) {
            return $this->fetchInt(
                'SELECT COUNT(*) FROM product WHERE sku = :sku',
                ['sku' => $sku],
            ) > 0;
        }

        return $this->fetchInt(
            'SELECT COUNT(*) FROM product WHERE sku = :sku AND id <> :id',
            ['sku' => $sku, 'id' => $exceptId],
        ) > 0;
    }

    public function highestSkuSequence(string $prefix): int
    {
        // Bagian setelah prefix wajib digit seluruhnya, supaya SKU seperti
        // "SKU-ABC" tidak terbaca sebagai 0 dan tidak mengacaukan urutan.
        return $this->fetchInt(
            'SELECT COALESCE(MAX(CAST(SUBSTRING(sku, :prefix_length + 1) AS UNSIGNED)), 0)
               FROM product
              WHERE sku LIKE :prefix_like
                AND SUBSTRING(sku, :prefix_start + 1) REGEXP \'^[0-9]+$\'',
            [
                'prefix_length' => mb_strlen($prefix),
                'prefix_like'   => addcslashes($prefix, '%_\\') . '%',
                'prefix_start'  => mb_strlen($prefix),
            ],
        );
    }

    public function allActive(): array
    {
        return array_map(
            fn (array $row): Product => $this->hydrate($row),
            $this->fetchAll(self::SELECT . ' WHERE p.is_active = 1 ORDER BY p.name ASC'),
        );
    }

    public function search(array $criteria, int $limit, int $offset): array
    {
        [$where, $params, $having] = $this->buildFilter($criteria);

        $sortColumn = $this->resolveSortColumn(self::SORTABLE, $criteria['sort'] ?? null, 'p.name');

        // Default 'asc', bukan null. Katalog diurutkan menurut nama, dan A→Z
        // adalah urutan yang diharapkan — sedangkan order diurutkan menurut
        // tanggal, yang wajarnya terbaru dulu (DESC). Karena itu default
        // arahnya memang berbeda antara keduanya, dan bukan kelalaian.
        $direction = $this->resolveDirection($criteria['direction'] ?? 'asc');

        $sql = self::SELECT
            . ' LEFT JOIN product_stock ps ON ps.product_id = p.id'
            . $where
            . ' GROUP BY p.id, p.sku, p.name, p.category_id, p.unit, p.purchase_price,'
            . ' p.selling_price, p.reorder_point, p.image_path, p.is_active'
            . $having
            . ' ORDER BY ' . $sortColumn . ' ' . $direction . ', p.id ASC'
            . ' LIMIT :limit OFFSET :offset';

        $rows = $this->fetchAll($sql, $params + ['limit' => $limit, 'offset' => $offset]);

        return array_map(fn (array $row): Product => $this->hydrate($row), $rows);
    }

    public function countBy(array $criteria): int
    {
        [$where, $params, $having] = $this->buildFilter($criteria);

        $sql = 'SELECT COUNT(*) FROM ('
            . 'SELECT p.id FROM product p'
            . ' LEFT JOIN product_stock ps ON ps.product_id = p.id'
            . $where
            . ' GROUP BY p.id, p.reorder_point'
            . $having
            . ') AS filtered';

        return $this->fetchInt($sql, $params);
    }

    public function lowStock(int $limit): array
    {
        $rows = $this->fetchAll(
            self::SELECT
            . ' LEFT JOIN product_stock ps ON ps.product_id = p.id'
            . ' WHERE p.is_active = 1'
            . ' GROUP BY p.id, p.sku, p.name, p.category_id, p.unit, p.purchase_price,'
            . ' p.selling_price, p.reorder_point, p.image_path, p.is_active'
            . ' HAVING COALESCE(SUM(ps.quantity), 0) <= p.reorder_point'
            . ' ORDER BY (COALESCE(SUM(ps.quantity), 0) - p.reorder_point) ASC'
            . ' LIMIT :limit',
            ['limit' => $limit],
        );

        return array_map(
            fn (array $row): array => [
                'product'       => $this->hydrate($row),
                'totalQuantity' => $this->totalForProduct((int) $row['id']),
            ],
            $rows,
        );
    }

    public function save(Product $product): int
    {
        $params = [
            'sku'            => $product->sku,
            'name'           => $product->name,
            'category_id'    => $product->categoryId,
            'unit'           => $product->unit,
            'purchase_price' => $product->purchasePrice,
            'selling_price'  => $product->sellingPrice,
            'reorder_point'  => $product->reorderPoint,
            'is_active'      => $product->isActive ? 1 : 0,
        ];

        if ($product->id === null) {
            $this->run(
                'INSERT INTO product (sku, name, category_id, unit, purchase_price, selling_price,
                                      reorder_point, image_path, is_active, created_at, updated_at)
                      VALUES (:sku, :name, :category_id, :unit, :purchase_price, :selling_price,
                              :reorder_point, :image_path, :is_active, NOW(), NOW())',
                $params + ['image_path' => $product->imagePath],
            );

            return $this->lastInsertId();
        }

        // image_path diubah lewat updateImagePath() agar edit tanpa upload
        // tidak menghapus image yang sudah ada.
        $this->run(
            'UPDATE product
                SET sku = :sku, name = :name, category_id = :category_id, unit = :unit,
                    purchase_price = :purchase_price, selling_price = :selling_price,
                    reorder_point = :reorder_point, is_active = :is_active, updated_at = NOW()
              WHERE id = :id',
            $params + ['id' => $product->id],
        );

        return $product->id;
    }

    public function setActive(int $id, bool $isActive): void
    {
        $this->run(
            'UPDATE product SET is_active = :is_active, updated_at = NOW() WHERE id = :id',
            ['id' => $id, 'is_active' => $isActive ? 1 : 0],
        );
    }

    public function isReferencedByOrder(int $id): bool
    {
        $count = $this->fetchInt(
            'SELECT
                (SELECT COUNT(*) FROM purchase_order_item WHERE product_id = :po_id)
              + (SELECT COUNT(*) FROM sales_order_item WHERE product_id = :so_id)',
            ['po_id' => $id, 'so_id' => $id],
        );

        return $count > 0;
    }

    public function updateImagePath(int $id, ?string $imagePath): void
    {
        $this->run(
            'UPDATE product SET image_path = :image_path, updated_at = NOW() WHERE id = :id',
            ['id' => $id, 'image_path' => $imagePath],
        );
    }

    /** Nilai inventori memakai harga beli (spec A-007). */
    public function totalInventoryValue(): string
    {
        return $this->fetchString(
            'SELECT COALESCE(SUM(ps.quantity * p.purchase_price), 0)
               FROM product_stock ps
               JOIN product p ON p.id = ps.product_id',
        );
    }

    private function totalForProduct(int $productId): int
    {
        return $this->fetchInt(
            'SELECT COALESCE(SUM(quantity), 0) FROM product_stock WHERE product_id = :id',
            ['id' => $productId],
        );
    }

    /**
     * @param array{search?: string, categoryId?: int, lowStock?: bool, active?: bool} $criteria
     * @return array{0: string, 1: array<string, mixed>, 2: string}
     */
    private function buildFilter(array $criteria): array
    {
        $clauses = [];
        $params = [];

        if (($criteria['search'] ?? '') !== '') {
            // Satu nama placeholder hanya boleh muncul SEKALI per statement:
            // ATTR_EMULATE_PREPARES = false membuat PDO meneruskan statement apa
            // adanya ke MySQL, yang tidak mengenal placeholder bernama berulang
            // (SQLSTATE[HY093]). Karena itu tiap kolom memakai namanya sendiri,
            // dengan nilai yang sama.
            $clauses[] = '(p.name LIKE :search_name OR p.sku LIKE :search_sku)';
            $params['search_name'] = '%' . $criteria['search'] . '%';
            $params['search_sku'] = '%' . $criteria['search'] . '%';
        }

        if (($criteria['categoryId'] ?? 0) > 0) {
            $clauses[] = 'p.category_id = :category_id';
            $params['category_id'] = $criteria['categoryId'];
        }

        if (isset($criteria['active'])) {
            $clauses[] = 'p.is_active = :active';
            $params['active'] = $criteria['active'] ? 1 : 0;
        }

        // Low stock dievaluasi terhadap total seluruh warehouse (spec A-008),
        // sehingga harus berada di HAVING, bukan WHERE.
        $having = '';
        if (isset($criteria['lowStock'])) {
            $having = $criteria['lowStock']
                ? ' HAVING COALESCE(SUM(ps.quantity), 0) <= p.reorder_point'
                : ' HAVING COALESCE(SUM(ps.quantity), 0) > p.reorder_point';
        }

        return [
            $clauses === [] ? '' : ' WHERE ' . implode(' AND ', $clauses),
            $params,
            $having,
        ];
    }

    /** @param array<string, mixed> $row */
    private function hydrate(array $row): Product
    {
        $imagePath = $row['image_path'];

        return new Product(
            (int) $row['id'],
            (string) $row['sku'],
            (string) $row['name'],
            (int) $row['category_id'],
            (string) $row['unit'],
            (string) $row['purchase_price'],
            (string) $row['selling_price'],
            (int) $row['reorder_point'],
            $imagePath === null ? null : (string) $imagePath,
            (bool) $row['is_active'],
        );
    }
}
