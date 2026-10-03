<?php

declare(strict_types=1);

namespace App\Entity\Enum;

/**
 * Lifecycle Purchase Order (src §1.3):
 * Draft -> Ordered -> PartiallyReceived -> Received, dengan Cancelled dapat
 * dicapai sebelum Received (spec A-004).
 */
enum PurchaseOrderStatus: string
{
    case Draft = 'Draft';
    case Ordered = 'Ordered';
    case PartiallyReceived = 'PartiallyReceived';
    case Received = 'Received';
    case Cancelled = 'Cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Draft',
            self::Ordered => 'Ordered',
            self::PartiallyReceived => 'Partially Received',
            self::Received => 'Received',
            self::Cancelled => 'Cancelled',
        };
    }

    /** Received dan Cancelled bersifat terminal. */
    public function isTerminal(): bool
    {
        return $this === self::Received || $this === self::Cancelled;
    }

    /** @return list<self> */
    public function allowedTransitions(): array
    {
        return match ($this) {
            self::Draft => [self::Ordered, self::Cancelled],
            self::Ordered => [self::PartiallyReceived, self::Received, self::Cancelled],
            self::PartiallyReceived => [self::Received, self::Cancelled],
            self::Received, self::Cancelled => [],
        };
    }
}
