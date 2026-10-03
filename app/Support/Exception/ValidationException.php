<?php

declare(strict_types=1);

namespace App\Support\Exception;

use RuntimeException;

/**
 * Validasi gagal. Membawa pesan per field agar form dapat menampilkannya di
 * samping input, sekaligus mempertahankan nilai yang sudah diisi (FR-029).
 */
final class ValidationException extends RuntimeException
{
    /**
     * @param array<string, string> $errors field => pesan
     */
    public function __construct(private readonly array $errors, string $message = 'Validation failed.')
    {
        parent::__construct($message);
    }

    /** @return array<string, string> */
    public function errors(): array
    {
        return $this->errors;
    }
}
