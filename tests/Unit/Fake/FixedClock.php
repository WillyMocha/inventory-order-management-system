<?php

declare(strict_types=1);

namespace Tests\Unit\Fake;

use App\Support\ClockInterface;
use DateTimeImmutable;

/**
 * Jam tetap untuk unit test.
 *
 * Tanpa ini, setiap aturan yang bergantung tanggal akan rapuh atau tidak
 * dapat diuji sama sekali. Inilah satu abstraksi kecil yang langsung terbayar
 * (research R-010, FIRST: Repeatable).
 */
final class FixedClock implements ClockInterface
{
    private DateTimeImmutable $now;

    public function __construct(string $fixedAt = '2026-09-10 09:00:00')
    {
        $this->now = new DateTimeImmutable($fixedAt);
    }

    public function now(): DateTimeImmutable
    {
        return $this->now;
    }

    /** Memajukan waktu — dipakai test yang perlu dua titik waktu berbeda. */
    public function advance(string $interval): void
    {
        $this->now = $this->now->modify($interval) ?: $this->now;
    }

    public function setTo(string $dateTime): void
    {
        $this->now = new DateTimeImmutable($dateTime);
    }
}
