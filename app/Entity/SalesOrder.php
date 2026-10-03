<?php

declare(strict_types=1);

namespace App\Entity;

use App\Entity\Enum\SalesOrderStatus;

/**
 * Penjualan ke customer dari satu warehouse asal.
 *
 * createdBy dan approvedBy adalah dasar aturan segregation of duties: Service
 * menegakkan approvedBy !== createdBy DAN role approver harus Admin (§1.2,
 * FR-018). Entity menyediakan predikatnya agar aturan tersebut dapat
 * di-unit-test tanpa database maupun session.
 */
final class SalesOrder
{
    /**
     * @param list<SalesOrderItem> $items
     */
    public function __construct(
        public readonly ?int $id,
        public readonly string $orderNumber,
        public readonly int $customerId,
        public readonly int $createdBy,
        public readonly ?int $approvedBy,
        public readonly int $warehouseId,
        public readonly SalesOrderStatus $status,
        public readonly string $orderDate,
        public readonly array $items = [],
    ) {
    }

    public function canTransitionTo(SalesOrderStatus $target): bool
    {
        return in_array($target, $this->status->allowedTransitions(), true);
    }

    /**
     * Seorang user tidak boleh menyetujui order yang dibuatnya sendiri.
     * Aturan ini berlaku untuk siapa pun, termasuk Admin.
     */
    public function isCreatedBy(int $userId): bool
    {
        return $this->createdBy === $userId;
    }

    /** Goods issue hanya untuk order berstatus Approved (SO-01). */
    public function canIssueGoods(): bool
    {
        return $this->status === SalesOrderStatus::Approved;
    }

    public function isOwnedBy(int $userId): bool
    {
        return $this->createdBy === $userId;
    }
}
