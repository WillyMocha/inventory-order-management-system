<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\ProductStock;

/**
 * Boundary repository untuk quantity stock.
 *
 * lockForUpdate() adalah inti pencegahan oversell: implementasi MySQL
 * menerbitkan SELECT ... FOR UPDATE sehingga request kedua menunggu sampai
 * request pertama commit (ARCH-02, research R-002).
 */
interface ProductStockRepositoryInterface
{
    public function findFor(int $productId, int $warehouseId): ?ProductStock;

    /**
     * Mengunci baris stock lalu membaca ulang quantity-nya di dalam
     * transaction yang sedang berjalan.
     *
     * WAJIB dipanggil di dalam transaction. Request lain untuk pasangan
     * (product, warehouse) yang sama akan menunggu sampai transaction ini
     * commit atau rollback.
     */
    public function lockForUpdate(int $productId, int $warehouseId): ?ProductStock;

    /**
     * Menambah atau mengurangi quantity. Delta positif untuk receipt, negatif
     * untuk issue. Baris dibuat bila belum ada.
     */
    public function adjust(int $productId, int $warehouseId, int $delta): void;

    /**
     * Rincian stock per warehouse untuk satu product (WH-01).
     *
     * @return list<array{warehouseId: int, warehouseName: string, quantity: int}>
     */
    public function breakdownForProduct(int $productId): array;

    public function totalForProduct(int $productId): int;
}
