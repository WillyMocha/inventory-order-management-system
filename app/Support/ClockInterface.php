<?php

declare(strict_types=1);

namespace App\Support;

use DateTimeImmutable;

/**
 * Waktu di-inject agar aturan yang bergantung tanggal bersifat deterministik
 * saat di-unit-test (research R-010, FIRST).
 */
interface ClockInterface
{
    public function now(): DateTimeImmutable;
}
