<?php

declare(strict_types=1);

namespace App\Support\Exception;

use RuntimeException;

/**
 * Batas percobaan terlampaui (security standard §7). Dipakai rate limit login.
 */
final class RateLimitException extends RuntimeException
{
    public function __construct(string $message = 'Too many attempts. Please try again later.')
    {
        parent::__construct($message);
    }
}
