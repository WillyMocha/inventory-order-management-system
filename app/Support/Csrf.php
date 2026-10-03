<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Token CSRF per session.
 *
 * Tidak disebut eksplisit oleh brief, tetapi menghilangkannya pada aplikasi
 * dengan cookie session dan endpoint approve adalah cacat desain — biayanya
 * hanya satu helper dan satu hidden field (research R-005).
 *
 * Perbandingan memakai hash_equals agar tidak bocor lewat timing.
 */
final class Csrf
{
    private const string SESSION_KEY = 'csrf_token';
    private const string FIELD_NAME = 'csrf_token';

    public function __construct(private readonly Session $session)
    {
    }

    public function token(): string
    {
        $existing = $this->session->get(self::SESSION_KEY);

        if (is_string($existing) && $existing !== '') {
            return $existing;
        }

        $token = bin2hex(random_bytes(32));
        $this->session->put(self::SESSION_KEY, $token);

        return $token;
    }

    public function isValid(?string $candidate): bool
    {
        $expected = $this->session->get(self::SESSION_KEY);

        if (!is_string($expected) || $expected === '' || $candidate === null || $candidate === '') {
            return false;
        }

        return hash_equals($expected, $candidate);
    }

    public static function fieldName(): string
    {
        return self::FIELD_NAME;
    }

    /** Hidden input siap pakai untuk template form. */
    public function field(): string
    {
        return sprintf(
            '<input type="hidden" name="%s" value="%s">',
            self::FIELD_NAME,
            htmlspecialchars($this->token(), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
        );
    }
}
