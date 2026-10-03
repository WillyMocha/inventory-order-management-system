<?php

declare(strict_types=1);

namespace App\Entity\Enum;

/**
 * Lifecycle Sales Order (src §1.3):
 * Draft -> PendingApproval -> Approved -> Fulfilled, atau Cancelled pada tahap
 * mana pun sebelum Fulfilled.
 *
 * Reject dari PendingApproval berakhir di Cancelled — sumber menyebut reject
 * sebagai aksi Admin tanpa menyediakan state Rejected tersendiri.
 */
enum SalesOrderStatus: string
{
    case Draft = 'Draft';
    case PendingApproval = 'PendingApproval';
    case Approved = 'Approved';
    case Fulfilled = 'Fulfilled';
    case Cancelled = 'Cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Draft',
            self::PendingApproval => 'Pending Approval',
            self::Approved => 'Approved',
            self::Fulfilled => 'Fulfilled',
            self::Cancelled => 'Cancelled',
        };
    }

    /** Fulfilled dan Cancelled bersifat terminal. */
    public function isTerminal(): bool
    {
        return $this === self::Fulfilled || $this === self::Cancelled;
    }

    /** @return list<self> */
    public function allowedTransitions(): array
    {
        return match ($this) {
            self::Draft => [self::PendingApproval, self::Cancelled],
            self::PendingApproval => [self::Approved, self::Cancelled],
            self::Approved => [self::Fulfilled, self::Cancelled],
            self::Fulfilled, self::Cancelled => [],
        };
    }
}
