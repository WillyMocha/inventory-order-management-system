<?php

declare(strict_types=1);

namespace App\Entity;

use App\Entity\Enum\PurchaseOrderStatus;

/**
 * Pembelian ke supplier untuk satu warehouse tujuan.
 */
final class PurchaseOrder
{
    /**
     * @param list<PurchaseOrderItem> $items
     */
    public function __construct(
        public readonly ?int $id,
        public readonly string $orderNumber,
        public readonly int $supplierId,
        public readonly int $warehouseId,
        public readonly PurchaseOrderStatus $status,
        public readonly string $orderDate,
        public readonly int $createdBy,
        public readonly array $items = [],
    ) {
    }

    public function canTransitionTo(PurchaseOrderStatus $target): bool
    {
        return in_array($target, $this->status->allowedTransitions(), true);
    }

    /** Goods receipt hanya mungkin saat Ordered atau PartiallyReceived. */
    public function canReceiveGoods(): bool
    {
        return $this->status === PurchaseOrderStatus::Ordered
            || $this->status === PurchaseOrderStatus::PartiallyReceived;
    }

    public function isFullyReceived(): bool
    {
        if ($this->items === []) {
            return false;
        }

        foreach ($this->items as $item) {
            if (!$item->isFullyReceived()) {
                return false;
            }
        }

        return true;
    }

    /** Ada minimal satu item yang sudah diterima sebagian. */
    public function hasAnyReceipt(): bool
    {
        foreach ($this->items as $item) {
            if ($item->receivedQuantity > 0) {
                return true;
            }
        }

        return false;
    }
}
