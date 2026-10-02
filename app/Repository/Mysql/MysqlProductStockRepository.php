<?php

declare(strict_types=1);

namespace App\Repository\Mysql;

use App\Entity\ProductStock;
use App\Repository\ProductStockRepositoryInterface;
use RuntimeException;

/**
 * Implementasi MySQL untuk quantity stock.
 *
 * Kelas inilah yang memuat mekanisme pencegahan oversell (ARCH-02,
 * research R-002, ADR-002).
 */
final class MysqlProductStockRepository extends MysqlRepository implements ProductStockRepositoryInterface
{
    public function findFor(int $productId, int $warehouseId): ?ProductStock
    {
        $row = $this->fetchOne(
            'SELECT id, product_id, warehouse_id, quantity
               FROM product_stock
              WHERE product_id = :product_id AND warehouse_id = :warehouse_id',
            ['product_id' => $productId, 'warehouse_id' => $warehouseId],
        );

        return $row === null ? null : $this->hydrate($row);
    }

    /**
     * Pessimistic row lock.
     *
     * SELECT ... FOR UPDATE mengunci baris (product, warehouse) sampai
     * transaction yang sedang berjalan commit atau rollback. Request kedua
     * untuk pasangan yang sama akan MENUNGGU di baris ini, lalu membaca
     * quantity yang sudah diperbarui — sehingga tidak mungkin terjadi oversell
     * maupun lost update.
     *
     * UNIQUE (product_id, warehouse_id) yang membuat ini menjadi lock satu
     * baris, bukan range scan.
     *
     * @throws RuntimeException bila dipanggil di luar transaction — tanpa
     *         transaction, lock akan langsung dilepas dan jaminannya hilang.
     */
    public function lockForUpdate(int $productId, int $warehouseId): ?ProductStock
    {
        if (!$this->pdo()->inTransaction()) {
            throw new RuntimeException(
                'lockForUpdate() wajib dipanggil di dalam transaction. '
                . 'Di luar transaction, lock langsung dilepas dan jaminan anti-oversell hilang.'
            );
        }

        $row = $this->fetchOne(
            'SELECT id, product_id, warehouse_id, quantity
               FROM product_stock
              WHERE product_id = :product_id AND warehouse_id = :warehouse_id
              FOR UPDATE',
            ['product_id' => $productId, 'warehouse_id' => $warehouseId],
        );

        return $row === null ? null : $this->hydrate($row);
    }

    /**
     * Menambah atau mengurangi quantity.
     *
     * Dikerjakan DUA LANGKAH, dan itu disengaja.
     *
     * Bentuk sebelumnya — satu `INSERT ... ON DUPLICATE KEY UPDATE` yang
     * mengirim delta sebagai nilai kandidat insert — TIDAK dapat bekerja di
     * MySQL bersama constraint `quantity >= 0`: MySQL memeriksa CHECK terhadap
     * baris kandidat INSERT lebih dulu, SEBELUM jatuh ke cabang ON DUPLICATE
     * KEY UPDATE. Untuk goods issue delta-nya negatif, sehingga setiap issue
     * ditolak constraint walaupun nilai akhirnya tidak pernah negatif.
     *
     * Karena itu baris dipastikan ada dengan nilai kandidat 0 (selalu lolos
     * CHECK, dan tetap memunculkan error FK bila product atau warehouse tidak
     * sah — berbeda dari INSERT IGNORE yang menelan error itu), lalu delta
     * diterapkan lewat UPDATE.
     *
     * Susunan ini justru mengembalikan CHECK ke peran yang dimaksudkan: jaring
     * pengaman terakhir atas HASIL perubahan. Issue yang melebihi stock kini
     * benar-benar ditolak database bila ada jalur yang lolos pemeriksaan
     * Service.
     *
     * Kedua statement selalu berjalan di dalam transaction milik StockService
     * (ARCH-02), jadi tidak ada keadaan antara yang dapat terlihat transaction
     * lain — baris (product, warehouse) itu sudah dikunci SELECT ... FOR UPDATE.
     */
    public function adjust(int $productId, int $warehouseId, int $delta): void
    {
        $keys = ['product_id' => $productId, 'warehouse_id' => $warehouseId];

        $this->run(
            'INSERT INTO product_stock (product_id, warehouse_id, quantity, updated_at)
                  VALUES (:product_id, :warehouse_id, 0, NOW())
             ON DUPLICATE KEY UPDATE id = id',
            $keys,
        );

        $this->run(
            'UPDATE product_stock
                SET quantity = quantity + :delta, updated_at = NOW()
              WHERE product_id = :product_id AND warehouse_id = :warehouse_id',
            $keys + ['delta' => $delta],
        );
    }

    public function breakdownForProduct(int $productId): array
    {
        $rows = $this->fetchAll(
            'SELECT w.id AS warehouse_id, w.name AS warehouse_name, COALESCE(ps.quantity, 0) AS quantity
               FROM warehouse w
          LEFT JOIN product_stock ps
                 ON ps.warehouse_id = w.id AND ps.product_id = :product_id
              WHERE w.is_active = 1
           ORDER BY w.name ASC',
            ['product_id' => $productId],
        );

        return array_map(
            static fn (array $row): array => [
                'warehouseId'   => (int) $row['warehouse_id'],
                'warehouseName' => (string) $row['warehouse_name'],
                'quantity'      => (int) $row['quantity'],
            ],
            $rows,
        );
    }

    public function totalForProduct(int $productId): int
    {
        return $this->fetchInt(
            'SELECT COALESCE(SUM(quantity), 0) FROM product_stock WHERE product_id = :product_id',
            ['product_id' => $productId],
        );
    }

    /** @param array<string, mixed> $row */
    private function hydrate(array $row): ProductStock
    {
        return new ProductStock(
            (int) $row['id'],
            (int) $row['product_id'],
            (int) $row['warehouse_id'],
            (int) $row['quantity'],
        );
    }
}
