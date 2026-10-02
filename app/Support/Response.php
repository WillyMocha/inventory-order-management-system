<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Respons HTTP. Menutup empat bentuk yang dipakai aplikasi ini: HTML, JSON,
 * CSV stream, dan redirect.
 */
final class Response
{
    /**
     * @param array<string, string> $headers
     * @param (callable(): void)|null $streamCallback
     */
    private function __construct(
        private readonly int $statusCode,
        private readonly string $body,
        private readonly array $headers = [],
        private readonly mixed $streamCallback = null,
    ) {
    }

    public static function html(string $body, int $status = 200): self
    {
        return new self($status, $body, ['Content-Type' => 'text/html; charset=UTF-8']);
    }

    /**
     * @param array<string, mixed>|list<mixed> $data
     */
    public static function json(array $data, int $status = 200): self
    {
        $encoded = json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);

        return new self($status, $encoded, ['Content-Type' => 'application/json; charset=UTF-8']);
    }

    /**
     * Error envelope seragam untuk /api/* (contracts/openapi.yaml).
     * Pesan selalu generic — tidak pernah memuat detail exception.
     */
    public static function jsonError(string $code, string $message, int $status): self
    {
        return self::json(['error' => ['code' => $code, 'message' => $message]], $status);
    }

    public static function redirect(string $location, int $status = 302): self
    {
        return new self($status, '', ['Location' => $location]);
    }

    /**
     * CSV di-stream langsung ke output agar tidak menyusun string besar di
     * memori (research R-008).
     *
     * @param callable(): void $callback
     */
    public static function csvStream(string $filename, callable $callback): self
    {
        return new self(
            200,
            '',
            [
                'Content-Type' => 'text/csv; charset=UTF-8',
                'Content-Disposition' => 'attachment; filename="' . $filename . '"',
            ],
            $callback,
        );
    }

    /**
     * File biner — dipakai menyajikan image product dari luar document root.
     */
    public static function file(string $contents, string $mimeType): self
    {
        return new self(
            200,
            $contents,
            [
                'Content-Type' => $mimeType,
                'Content-Disposition' => 'inline',
                // Mencegah browser menebak tipe lain dari isi file.
                'X-Content-Type-Options' => 'nosniff',
            ],
        );
    }

    public function statusCode(): int
    {
        return $this->statusCode;
    }

    public function body(): string
    {
        return $this->body;
    }

    /**
     * Nilai satu header, atau null bila tidak diset.
     *
     * Dipakai test untuk memastikan endpoint /api/* benar-benar menyatakan
     * dirinya JSON — contracts/openapi.yaml menuntut itu pada SETIAP respons,
     * termasuk respons error.
     */
    public function header(string $name): ?string
    {
        return $this->headers[$name] ?? null;
    }

    public function send(): void
    {
        http_response_code($this->statusCode);

        foreach ($this->headers as $name => $value) {
            header($name . ': ' . $value, true);
        }

        if (is_callable($this->streamCallback)) {
            ($this->streamCallback)();
            return;
        }

        echo $this->body;
    }
}
