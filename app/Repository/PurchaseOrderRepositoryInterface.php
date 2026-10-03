<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Enum\PurchaseOrderStatus;
use App\Entity\PurchaseOrder;

interface PurchaseOrderRepositoryInterface
{
    /** Memuat order beserta seluruh item-nya. */
    public function findById(int $id): ?PurchaseOrder;

    /**
     * Memuat order beserta item-nya sambil mengunci baris header dan item
     * (SELECT ... FOR UPDATE) sampai transaction berjalan selesai.
     *
     * Dipakai goods receipt agar status dan received_quantity dibaca ulang di
     * bawah lock: dua receipt bersamaan tidak dapat sama-sama merencanakan
     * dari outstanding yang sudah basi (ARCH-02). Wajib di dalam transaction.
     */
    public function lockForUpdate(int $id): ?PurchaseOrder;

    public function orderNumberExists(string $orderNumber): bool;

    /**
     * @param array{search?: string, status?: string, sort?: string, direction?: string} $criteria
     * @return list<PurchaseOrder>
     */
    public function search(array $criteria, int $limit, int $offset): array;

    /** @param array{search?: string, status?: string} $criteria */
    public function countBy(array $criteria): int;

    /** Menyimpan order beserta item-nya; mengembalikan id order. */
    public function save(PurchaseOrder $order): int;

    /**
     * Mengubah status HANYA bila status tersimpan masih $expected
     * (compare-and-set). False bila order sudah diubah request lain.
     */
    public function updateStatus(int $id, PurchaseOrderStatus $expected, PurchaseOrderStatus $status): bool;

    /** Menambah received_quantity satu item — dipanggil saat goods receipt. */
    public function addReceivedQuantity(int $itemId, int $quantity): void;

    /** @return array<string, int> status => jumlah, untuk dashboard */
    public function countByStatus(): array;

    /**
     * Order yang menunggu goods receipt — antrean Warehouse Staff (DASH-01).
     *
     * @return list<PurchaseOrder>
     */
    public function awaitingReceipt(int $limit): array;
}
