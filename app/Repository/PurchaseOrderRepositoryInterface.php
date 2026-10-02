<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Enum\PurchaseOrderStatus;
use App\Entity\PurchaseOrder;

interface PurchaseOrderRepositoryInterface
{
    /** Memuat order beserta seluruh item-nya. */
    public function findById(int $id): ?PurchaseOrder;

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

    public function updateStatus(int $id, PurchaseOrderStatus $status): void;

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
