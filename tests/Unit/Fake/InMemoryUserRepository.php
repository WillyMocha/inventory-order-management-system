<?php

declare(strict_types=1);

namespace Tests\Unit\Fake;

use App\Entity\User;
use App\Repository\UserRepositoryInterface;

/**
 * Fake in-memory untuk UserRepositoryInterface.
 *
 * Implementasi kedua dari interface yang sama seperti MysqlUserRepository —
 * inilah yang membuat setiap use case dapat di-unit-test tanpa database
 * (ARCH-01, constitution Principle III).
 */
final class InMemoryUserRepository implements UserRepositoryInterface
{
    /** @var array<int, User> */
    private array $rows = [];

    private int $nextId = 1;

    /** @param list<User> $users */
    public function __construct(array $users = [])
    {
        foreach ($users as $user) {
            $this->save($user);
        }
    }

    public function findById(int $id): ?User
    {
        return $this->rows[$id] ?? null;
    }

    public function findByEmail(string $email): ?User
    {
        foreach ($this->rows as $user) {
            if (strcasecmp($user->email, $email) === 0) {
                return $user;
            }
        }

        return null;
    }

    public function emailExists(string $email, ?int $exceptId = null): bool
    {
        foreach ($this->rows as $id => $user) {
            if (strcasecmp($user->email, $email) === 0 && $id !== $exceptId) {
                return true;
            }
        }

        return false;
    }

    public function all(): array
    {
        return array_values($this->rows);
    }

    public function search(array $criteria, int $limit, int $offset): array
    {
        return array_slice($this->filter($criteria), $offset, $limit);
    }

    public function countBy(array $criteria): int
    {
        return count($this->filter($criteria));
    }

    public function save(User $user): int
    {
        $id = $user->id ?? $this->nextId++;

        $this->rows[$id] = new User(
            $id,
            $user->name,
            $user->email,
            $user->passwordHash,
            $user->role,
            $user->isActive,
        );

        if ($id >= $this->nextId) {
            $this->nextId = $id + 1;
        }

        return $id;
    }

    public function updatePasswordHash(int $id, string $passwordHash): void
    {
        $user = $this->rows[$id] ?? null;

        if ($user === null) {
            return;
        }

        $this->rows[$id] = new User(
            $id,
            $user->name,
            $user->email,
            $passwordHash,
            $user->role,
            $user->isActive,
        );
    }

    public function setActive(int $id, bool $isActive): void
    {
        $user = $this->rows[$id] ?? null;

        if ($user === null) {
            return;
        }

        $this->rows[$id] = new User(
            $id,
            $user->name,
            $user->email,
            $user->passwordHash,
            $user->role,
            $isActive,
        );
    }

    /**
     * @param array{search?: string, role?: string, active?: bool} $criteria
     * @return list<User>
     */
    private function filter(array $criteria): array
    {
        $result = [];

        foreach ($this->rows as $user) {
            if (($criteria['search'] ?? '') !== '') {
                $needle = strtolower((string) $criteria['search']);
                $haystack = strtolower($user->name . ' ' . $user->email);

                if (!str_contains($haystack, $needle)) {
                    continue;
                }
            }

            if (($criteria['role'] ?? '') !== '' && $user->role->value !== $criteria['role']) {
                continue;
            }

            if (isset($criteria['active']) && $user->isActive !== $criteria['active']) {
                continue;
            }

            $result[] = $user;
        }

        return $result;
    }
}
