<?php

declare(strict_types=1);

namespace App\Support\Exception;

use RuntimeException;

/**
 * Resource tidak ada — ATAU berada di luar scope pemanggil.
 *
 * Kasus kedua sengaja dipetakan ke 404, bukan 403: membalas 403 justru
 * mengonfirmasi bahwa record-nya ada (security standard §2).
 */
final class NotFoundException extends RuntimeException
{
    public function __construct(string $message = 'Resource not found.')
    {
        parent::__construct($message);
    }
}
