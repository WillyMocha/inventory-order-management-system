<?php

declare(strict_types=1);

namespace App\Support\Exception;

use RuntimeException;

/**
 * Aturan bisnis dilanggar — misalnya transisi status yang tidak diizinkan,
 * atau goods issue saat stock tidak mencukupi.
 *
 * Berbeda dari ValidationException: input-nya sah secara bentuk, tetapi
 * operasinya tidak boleh dilakukan pada state saat ini.
 */
final class DomainException extends RuntimeException
{
}
