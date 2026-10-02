<?php

declare(strict_types=1);

namespace App\Entity;

final class SalesOrderItem
{
    public function __construct(
        public readonly ?int $id,
        public readonly ?int $salesOrderId,
        public readonly int $productId,
        public readonly int $quantity,
        public readonly string $sellingPrice,
    ) {
    }
}
