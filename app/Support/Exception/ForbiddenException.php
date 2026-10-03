<?php

declare(strict_types=1);

namespace App\Support\Exception;

use RuntimeException;

/**
 * Role pemanggil memang tidak berhak menyentuh route ini sama sekali.
 * Tidak membocorkan apa pun, sehingga 403 aman dipakai di sini.
 */
final class ForbiddenException extends RuntimeException
{
    public function __construct(string $message = 'You do not have access to this resource.')
    {
        parent::__construct($message);
    }
}
