<?php

declare(strict_types=1);

namespace App\Entity;

final class PurchaseOrderItem
{
    public function __construct(
        public readonly ?int $id,
        public readonly ?int $purchaseOrderId,
        public readonly int $productId,
        public readonly int $quantity,
        public readonly int $receivedQuantity,
        public readonly string $purchasePrice,
    ) {
    }

    /**
     * Qty yang belum diterima. PO-01 mewajibkan sisa ini tetap tercatat.
     */
    public function outstandingQuantity(): int
    {
        return $this->quantity - $this->receivedQuantity;
    }

    public function isFullyReceived(): bool
    {
        return $this->outstandingQuantity() === 0;
    }

    /**
     * Penerimaan tidak boleh melebihi outstanding (spec A-005).
     */
    public function canReceive(int $quantity): bool
    {
        return $quantity > 0 && $quantity <= $this->outstandingQuantity();
    }
}
