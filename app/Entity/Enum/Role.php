<?php

declare(strict_types=1);

namespace App\Entity\Enum;

/**
 * Tiga role yang dikenal sistem. Tidak ada role lain dan tidak ada public
 * registration (USR-01).
 */
enum Role: string
{
    case Admin = 'Admin';
    case Sales = 'Sales';
    case WarehouseStaff = 'WarehouseStaff';

    /** Label yang ditampilkan di UI — bahasa Inggris (spec C-007). */
    public function label(): string
    {
        return match ($this) {
            self::Admin => 'Admin',
            self::Sales => 'Sales',
            self::WarehouseStaff => 'Warehouse Staff',
        };
    }
}
