<?php

declare(strict_types=1);

namespace App\Entity;

/**
 * Quantity satu product pada satu warehouse.
 *
 * Baris inilah yang dikunci dengan SELECT ... FOR UPDATE saat goods issue.
 * Quantity tidak pernah negatif — dijaga constraint database sekaligus
 * pemeriksaan di Service.
 */
final class ProductStock
{
    public function __construct(
        public readonly ?int $id,
        public readonly int $productId,
        public readonly int $warehouseId,
        public readonly int $quantity,
    ) {
    }

    public function hasAtLeast(int $needed): bool
    {
        return $this->quantity >= $needed;
    }
}
