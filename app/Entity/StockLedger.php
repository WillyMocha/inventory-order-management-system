<?php

declare(strict_types=1);

namespace App\Entity;

use App\Entity\Enum\MovementType;
use App\Entity\Enum\ReferenceType;

/**
 * Satu baris riwayat pergerakan stock. Bersifat append-only — tidak pernah
 * di-UPDATE maupun di-DELETE.
 *
 * Konvensi tanda: quantity positif untuk Receipt, negatif untuk Issue, dan
 * bertanda sesuai selisihnya untuk Adjustment.
 * Invariant yang harus selalu berlaku (NFR-002):
 * SUM(quantity) per (product, warehouse) = product_stock.quantity.
 */
final class StockLedger
{
    public function __construct(
        public readonly ?int $id,
        public readonly int $productId,
        public readonly int $warehouseId,
        public readonly MovementType $movementType,
        public readonly int $quantity,
        public readonly ReferenceType $referenceType,
        public readonly ?int $referenceId,
        public readonly int $performedBy,
        // Alasan koreksi. Wajib untuk Adjustment, selalu null untuk Receipt dan
        // Issue (spec 003, data-model D-1; dijaga CHECK di database).
        public readonly ?string $note = null,
    ) {
    }

    /**
     * Membuat baris Receipt. Quantity selalu dinormalisasi menjadi positif.
     */
    public static function receipt(
        int $productId,
        int $warehouseId,
        int $quantity,
        int $purchaseOrderId,
        int $performedBy,
    ): self {
        return new self(
            null,
            $productId,
            $warehouseId,
            MovementType::Receipt,
            abs($quantity),
            ReferenceType::PurchaseOrder,
            $purchaseOrderId,
            $performedBy,
        );
    }

    /**
     * Membuat baris Issue. Quantity selalu dinormalisasi menjadi negatif.
     */
    public static function issue(
        int $productId,
        int $warehouseId,
        int $quantity,
        int $salesOrderId,
        int $performedBy,
    ): self {
        return new self(
            null,
            $productId,
            $warehouseId,
            MovementType::Issue,
            -abs($quantity),
            ReferenceType::SalesOrder,
            $salesOrderId,
            $performedBy,
        );
    }

    /**
     * Membuat baris Adjustment untuk koreksi stock manual (spec 003).
     *
     * Berbeda dari receipt() dan issue(), tanda quantity TIDAK dinormalisasi:
     * selisih hasil hitung bisa positif maupun negatif. Tidak ada order yang
     * dirujuk, sehingga reference-nya Manual tanpa id.
     */
    public static function adjustment(
        int $productId,
        int $warehouseId,
        int $delta,
        string $note,
        int $performedBy,
    ): self {
        return new self(
            null,
            $productId,
            $warehouseId,
            MovementType::Adjustment,
            $delta,
            ReferenceType::Manual,
            null,
            $performedBy,
            $note,
        );
    }
}
