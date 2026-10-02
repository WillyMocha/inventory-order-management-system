<?php

declare(strict_types=1);

namespace App\Support;

use App\Entity\Enum\Role;
use App\Support\Exception\ForbiddenException;
use App\Support\Exception\NotFoundException;
use App\Support\Exception\UnauthenticatedException;

/**
 * Guard authorization — DENY BY DEFAULT.
 *
 * Route yang tidak mencantumkan role secara eksplisit tidak dapat diakses
 * siapa pun. Menyembunyikan URL bukan proteksi (security standard §2).
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

    public function requireRole(Role ...$roles): void
    {
        $this->authorizeRoute(array_values($roles));
    }

    public function currentRole(): ?Role
    {
        return $this->session->role();
    }

    public function currentUserId(): int
    {
        $id = $this->session->userId();

        if ($id === null) {
            throw new UnauthenticatedException();
        }

        return $id;
    }

    public function hasRole(Role $role): bool
    {
        return $this->session->role() === $role;
    }

    /**
     * Menolak akses ke resource yang ada tetapi berada di luar scope
     * pemanggil, dengan 404 alih-alih 403.
     *
     * Contoh: seorang Sales membuka Sales Order milik Sales lain. Membalas 403
     * akan mengonfirmasi bahwa order tersebut ada (security standard §2).
     *
     * @throws NotFoundException
     */
    public function denyAsNotFound(): never
    {
        throw new NotFoundException();
    }

    /**
     * Sales hanya boleh menyentuh record miliknya sendiri; Admin dan Warehouse
     * Staff tidak dibatasi kepemilikan.
     */
    public function assertOwnershipForSales(int $ownerId): void
    {
        if ($this->currentRole() !== Role::Sales) {
            return;
        }

        if ($ownerId !== $this->currentUserId()) {
            $this->denyAsNotFound();
        }
    }
}
