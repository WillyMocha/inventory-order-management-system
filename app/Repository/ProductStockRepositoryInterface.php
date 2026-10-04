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
     * Memastikan baris stock untuk pasangan (product, warehouse) ada, dengan
     * quantity 0 bila belum pernah ada. Baris yang sudah ada tidak berubah.
     *
     * Dipanggil sebelum lockForUpdate() pada koreksi stock (spec 003, research
     * R-002): tanpa baris, SELECT ... FOR UPDATE hanya memasang gap lock, dua
     * koreksi pertama untuk pasangan yang sama lolos bersamaan, lalu bertabrakan
     * sebagai deadlock saat insert. Baris bernilai 0 tidak melanggar invariant,
     * karena jumlah ledger untuk pasangan tanpa pergerakan juga 0.
     */
    public function ensureRow(int $productId, int $warehouseId): void;

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
