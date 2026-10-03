<?php

declare(strict_types=1);

namespace App\Entity\Enum;

/**
 * Discriminator referensi pada stock_ledger.
 *
 * Sumber menyebut referensi sebagai "PO/SO id" tanpa menjelaskan cara
 * membedakan keduanya; satu FK nullable ke dua tabel tidak dapat
 * diekspresikan, sehingga discriminator inilah yang membuat maksud sumber
 * dapat direpresentasikan (data-model.md).
 */
enum ReferenceType: string
{
    case PurchaseOrder = 'PurchaseOrder';
    case SalesOrder = 'SalesOrder';
    case Manual = 'Manual';
}
