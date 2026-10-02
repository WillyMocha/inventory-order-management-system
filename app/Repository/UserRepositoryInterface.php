<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\User;

/**
 * Boundary repository untuk User.
 *
 * Service bergantung pada interface ini, bukan pada implementasi konkret —
 * inilah Dependency Inversion yang diminta ARCH-01. Dua implementasi
 * disediakan: MysqlUserRepository dan InMemoryUserRepository (unit test).
 */
interface UserRepositoryInterface
{
    public function findById(int $id): ?User;

    public function findByEmail(string $email): ?User;

    public function emailExists(string $email, ?int $exceptId = null): bool;

    /** @return list<User> */
    public function all(): array;

    /**
     * @param array{search?: string, role?: string, active?: bool} $criteria
     * @return list<User>
     */
    public function search(array $criteria, int $limit, int $offset): array;

    /** @param array{search?: string, role?: string, active?: bool} $criteria */
    public function countBy(array $criteria): int;

    public function save(User $user): int;

    public function updatePasswordHash(int $id, string $passwordHash): void;

    public function setActive(int $id, bool $isActive): void;
}
