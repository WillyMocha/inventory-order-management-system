<?php

declare(strict_types=1);

namespace App\Entity;

/**
 * Item katalog. Harga disimpan sebagai string agar presisi DECIMAL tidak
 * hilang lewat konversi float — mata uang tidak pernah direpresentasikan
 * sebagai binary floating point (research R-007).
 */
final class Product
{
    public function __construct(
        public readonly ?int $id,
        public readonly string $sku,
        public readonly string $name,
        public readonly int $categoryId,
        public readonly string $unit,
        public readonly string $purchasePrice,
        public readonly string $sellingPrice,
        public readonly int $reorderPoint,
        public readonly ?string $imagePath,
        public readonly bool $isActive,
    ) {
    }

    /**
     * Low stock bila total quantity seluruh warehouse berada pada atau di
     * bawah reorder point (spec A-008).
     */
    public function isLowStock(int $total): bool
    {
        return $total <= $this->reorderPoint;
    }

    public function hasImage(): bool
    {
        return $this->imagePath !== null && $this->imagePath !== '';
    }
}
