<?php

declare(strict_types=1);

namespace App\Support\Exception;

use RuntimeException;

/**
 * Tidak ada session yang valid. Route HTML dialihkan ke /login; route /api/*
 * menerima 401 dalam bentuk JSON (API-01).
 */
final class UnauthenticatedException extends RuntimeException
{
    public function __construct(string $message = 'Authentication required.')
    {
        parent::__construct($message);
    }
}
