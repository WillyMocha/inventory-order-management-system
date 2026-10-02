<?php

declare(strict_types=1);

namespace App\Entity;

use App\Entity\Enum\Role;

/**
 * Akun pengguna. Menyimpan hash password, tidak pernah plaintext (§4.2).
 */
final class User
{
    public function __construct(
        public readonly ?int $id,
        public readonly string $name,
        public readonly string $email,
        public readonly string $passwordHash,
        public readonly Role $role,
        public readonly bool $isActive,
    ) {
    }

    public function isAdmin(): bool
    {
        return $this->role === Role::Admin;
    }

    public function isSales(): bool
    {
        return $this->role === Role::Sales;
    }

    public function isWarehouseStaff(): bool
    {
        return $this->role === Role::WarehouseStaff;
    }

    /** Hanya user aktif yang boleh login (AUTH-01). */
    public function canSignIn(): bool
    {
        return $this->isActive;
    }
}
