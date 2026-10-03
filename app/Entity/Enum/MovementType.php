<?php

declare(strict_types=1);

namespace App\Entity\Enum;

/**
 * Tipe pergerakan pada stock_ledger (src §1.3).
 *
 * Adjustment ada pada model sumber namun tidak ada screen yang membuatnya —
 * tidak satu pun requirement pada brief meminta manual stock adjustment
 * (spec A-006). Nilai ini disediakan untuk koreksi di luar alur normal.
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
