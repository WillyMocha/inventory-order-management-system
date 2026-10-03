<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Pembungkus request HTTP dengan accessor bertipe.
 *
 * Superglobal hanya disentuh di sini. Controller dan Service menerima nilai
 * lewat objek ini, sehingga Service tidak pernah membaca $_POST atau $_GET
 * (constitution Principle I).
 */
final class Request
{
    /** Seluruh path di bawah prefix ini dilayani sebagai JSON, termasuk error-nya. */
    private const string API_PREFIX = '/api';

    /**
     * @param array<string, mixed> $query
     * @param array<string, mixed> $body
     * @param array<string, mixed> $files
     * @param array<string, string> $routeParams
     */
    private function __construct(
        private readonly string $method,
        private readonly string $path,
        private readonly array $query,
        private readonly array $body,
        private readonly array $files,
        private array $routeParams,
        private readonly string $ipAddress,
    ) {
    }

    public static function fromGlobals(): self
    {
        /** @var array<string, mixed> $query */
        $query = $_GET;
        /** @var array<string, mixed> $body */
        $body = $_POST;
        /** @var array<string, mixed> $files */
        $files = $_FILES;

        $method = is_string($_SERVER['REQUEST_METHOD'] ?? null)
            ? strtoupper($_SERVER['REQUEST_METHOD'])
            : 'GET';

        $uri = is_string($_SERVER['REQUEST_URI'] ?? null) ? $_SERVER['REQUEST_URI'] : '/';
        $path = parse_url($uri, PHP_URL_PATH);
        $path = is_string($path) ? $path : '/';

        $ip = is_string($_SERVER['REMOTE_ADDR'] ?? null) ? $_SERVER['REMOTE_ADDR'] : '0.0.0.0';

        return new self($method, rtrim($path, '/') ?: '/', $query, $body, $files, [], $ip);
    }

    public function method(): string
    {
        return $this->method;
    }

    public function path(): string
    {
        return $this->path;
    }

    public function ipAddress(): string
    {
        return $this->ipAddress;
    }

    public function isPost(): bool
    {
        return $this->method === 'POST';
    }

    /**
     * Route /api/* memerlukan penanganan error berbentuk JSON (API-01).
     *
     * Prefix telanjang '/api' ikut dihitung. Tanpa itu, path tersebut — dan
     * '/api/' yang dinormalisasi menjadi '/api' — akan dibalas halaman error
     * HTML, tepat perilaku yang dilarang contract untuk surface API.
     */
    public function expectsJson(): bool
    {
        return $this->path === self::API_PREFIX
            || str_starts_with($this->path, self::API_PREFIX . '/');
    }

    /** @param array<string, string> $params */
    public function withRouteParams(array $params): self
    {
        $clone = clone $this;
        $clone->routeParams = $params;

        return $clone;
    }

    public function routeParam(string $key): ?string
    {
        return $this->routeParams[$key] ?? null;
    }

    public function routeParamInt(string $key): ?int
    {
        $value = $this->routeParam($key);

        return $value !== null && ctype_digit($value) ? (int) $value : null;
    }

    public function queryString(string $key, string $default = ''): string
    {
        $value = $this->query[$key] ?? null;

        return is_string($value) ? trim($value) : $default;
    }

    public function queryInt(string $key, int $default = 0): int
    {
        $value = $this->query[$key] ?? null;

        return is_numeric($value) ? (int) $value : $default;
    }

    /** @return array<string, mixed> */
    public function queryAll(): array
    {
        return $this->query;
    }

    /**
     * Nilai query string yang terisi untuk key-key tertentu — state filter yang
     * dibawa link pagination dan sort, agar filter tetap aktif saat berpindah
     * halaman (FIND-01).
     *
     * Dulu disalin sebagai method private di enam controller, berbeda hanya
     * pada daftar key-nya (refactor-log R-6).
     *
     * @param list<string> $keys
     * @return array<string, string>
     */
    public function queryState(array $keys): array
    {
        $state = [];

        foreach ($keys as $key) {
            $value = $this->queryString($key);

            if ($value !== '') {
                $state[$key] = $value;
            }
        }

        return $state;
    }

    /**
     * Sort key dan arah yang diminta, hanya bila key-nya ada di allowlist.
     *
     * Allowlist ini lapis pertama; repository tetap memetakan key ke nama kolom
     * lewat allowlist-nya sendiri, sehingga input user tidak pernah menjadi
     * bagian SQL. Arah selain lawan dari default jatuh ke default.
     *
     * @param list<string>  $allowedKeys
     * @param 'asc'|'desc'  $defaultDirection
     * @return array{sort?: string, direction?: string}
     */
    public function sortCriteria(array $allowedKeys, string $defaultDirection): array
    {
        $sort = $this->queryString('sort');

        if (!in_array($sort, $allowedKeys, true)) {
            return [];
        }

        $opposite = $defaultDirection === 'asc' ? 'desc' : 'asc';

        return [
            'sort'      => $sort,
            'direction' => strtolower($this->queryString('direction')) === $opposite ? $opposite : $defaultDirection,
        ];
    }

    public function input(string $key, string $default = ''): string
    {
        $value = $this->body[$key] ?? null;

        return is_string($value) ? trim($value) : $default;
    }

    public function inputInt(string $key, int $default = 0): int
    {
        $value = $this->body[$key] ?? null;

        return is_numeric($value) ? (int) $value : $default;
    }

    /** @return array<string, mixed> */
    public function bodyAll(): array
    {
        return $this->body;
    }

    /** @return array<string, mixed>|null */
    public function file(string $key): ?array
    {
        $value = $this->files[$key] ?? null;

        return is_array($value) ? $value : null;
    }
}
