<?php

declare(strict_types=1);

namespace App\Support;

use App\Entity\Enum\Role;
use App\Support\Exception\NotFoundException;

/**
 * Route table dan dispatch.
 *
 * Setiap entry membawa role yang diizinkan. Guard authorization membaca
 * daftar itu, sehingga hak akses berada satu tempat dengan definisi route dan
 * tidak tersebar di dalam controller (contracts/http-routes.md).
 *
 * Route yang tidak mencantumkan role sama sekali tidak dapat diakses siapa
 * pun — deny by default.
 */
final class Router
{
    /**
     * @var list<array{
     *     method: string,
     *     pattern: string,
     *     regex: string,
     *     params: list<string>,
     *     controller: string,
     *     action: string,
     *     roles: list<Role>|null
     * }>
     */
    private array $routes = [];

    /**
     * Mendaftarkan satu route.
     *
     * @param list<Role>|null $roles null = publik; [] = tidak ada yang boleh.
     */
    public function add(string $method, string $pattern, string $controller, string $action, ?array $roles): void
    {
        $params = [];

        // {id} menjadi named capture group; hanya karakter aman yang cocok.
        $regex = preg_replace_callback(
            '/\{([a-zA-Z_][a-zA-Z0-9_]*)\}/',
            static function (array $matches) use (&$params): string {
                /** @var list<string> $params */
                $params[] = $matches[1];
                return '([^/]+)';
            },
            $pattern,
        ) ?? $pattern;

        $this->routes[] = [
            'method'     => strtoupper($method),
            'pattern'    => $pattern,
            'regex'      => '#^' . $regex . '$#',
            'params'     => $params,
            'controller' => $controller,
            'action'     => $action,
            'roles'      => $roles,
        ];
    }

    /**
     * Mencari route yang cocok dengan method dan path request.
     *
     * @return array{
     *     controller: string,
     *     action: string,
     *     roles: list<Role>|null,
     *     params: array<string, string>
     * }
     *
     * @throws NotFoundException bila tidak ada yang cocok.
     */
    public function match(string $method, string $path): array
    {
        $method = strtoupper($method);
        $normalized = rtrim($path, '/');

        if ($normalized === '') {
            $normalized = '/';
        }

        foreach ($this->routes as $route) {
            if ($route['method'] !== $method) {
                continue;
            }

            if (preg_match($route['regex'], $normalized, $matches) !== 1) {
                continue;
            }

            array_shift($matches);

            /** @var array<string, string> $params */
            $params = [];
            foreach ($route['params'] as $index => $name) {
                $params[$name] = $matches[$index] ?? '';
            }

            return [
                'controller' => $route['controller'],
                'action'     => $route['action'],
                'roles'      => $route['roles'],
                'params'     => $params,
            ];
        }

        throw new NotFoundException();
    }

    /**
     * Jumlah route terdaftar — dipakai smoke test agar route table tidak
     * diam-diam menjadi kosong.
     */
    public function count(): int
    {
        return count($this->routes);
    }
}
