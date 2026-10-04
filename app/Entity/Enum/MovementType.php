<?php

declare(strict_types=1);

namespace App\Entity\Enum;

/**
 * Tipe pergerakan pada stock_ledger (src §1.3).
 *
 * Adjustment adalah koreksi stock dari hasil hitung fisik, dibuat hanya oleh
 * StockService::adjustStock() dengan reference Manual dan alasan wajib
 * (spec 003-stock-adjustment). Brief menyediakan nilainya tanpa requirement
 * alurnya; spec 001 A-006 semula mencadangkannya.
 */
enum MovementType: string
{
    case Receipt = 'Receipt';
    case Issue = 'Issue';
    case Adjustment = 'Adjustment';

    public function label(): string
    {
        return match ($this) {
            self::Receipt => 'Receipt',
            self::Issue => 'Issue',
            self::Adjustment => 'Adjustment',
        };
    }
}
