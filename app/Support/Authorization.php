<?php

declare(strict_types=1);

namespace App\Support;

use App\Entity\Enum\Role;
use App\Support\Exception\ForbiddenException;
use App\Support\Exception\UnauthenticatedException;

/**
 * Guard authorization — DENY BY DEFAULT.
 *
 * Route yang tidak mencantumkan role secara eksplisit tidak dapat diakses
 * siapa pun. Menyembunyikan URL bukan proteksi (security standard §2).
 *
 * Guard ini hanya memeriksa role terhadap route. Scoping kepemilikan — Sales
 * membuka order milik Sales lain menerima 404, bukan 403 — ditegakkan di
 * Service (`SalesOrderService::requireVisibleOrder()`), tempat order-nya
 * memang dibaca.
 */
final class Authorization
{
    public function __construct(private readonly Session $session)
    {
    }

    /**
     * Memeriksa apakah session saat ini boleh mencapai route dengan daftar
     * role tertentu.
     *
     * @param list<Role>|null $allowedRoles null berarti route publik (login).
     *
     * @throws UnauthenticatedException bila belum login.
     * @throws ForbiddenException bila role tidak diizinkan.
     */
    public function authorizeRoute(?array $allowedRoles): void
    {
        // Route publik, misalnya halaman login.
        if ($allowedRoles === null) {
            return;
        }

        if (!$this->session->isAuthenticated()) {
            throw new UnauthenticatedException();
        }

        // Daftar kosong berarti tidak ada yang boleh — inilah deny by default.
        if ($allowedRoles === []) {
            throw new ForbiddenException();
        }

        $role = $this->session->role();

        if ($role === null || !in_array($role, $allowedRoles, true)) {
            throw new ForbiddenException();
        }
    }
}
