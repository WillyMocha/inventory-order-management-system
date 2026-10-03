<?php

declare(strict_types=1);

namespace App\Repository\Mysql;

use App\Entity\Enum\Role;
use App\Entity\User;
use App\Repository\UserRepositoryInterface;

final class MysqlUserRepository extends MysqlRepository implements UserRepositoryInterface
{
    private const string SELECT = 'SELECT id, name, email, password_hash, role, is_active FROM `user`';

    public function findById(int $id): ?User
    {
        $row = $this->fetchOne(self::SELECT . ' WHERE id = :id', ['id' => $id]);

        return $row === null ? null : $this->hydrate($row);
    }

    public function findByEmail(string $email): ?User
    {
        $row = $this->fetchOne(self::SELECT . ' WHERE email = :email', ['email' => $email]);

        return $row === null ? null : $this->hydrate($row);
    }

    public function emailExists(string $email, ?int $exceptId = null): bool
    {
        if ($exceptId === null) {
            return $this->fetchInt(
                'SELECT COUNT(*) FROM `user` WHERE email = :email',
                ['email' => $email],
            ) > 0;
        }

        return $this->fetchInt(
            'SELECT COUNT(*) FROM `user` WHERE email = :email AND id <> :id',
            ['email' => $email, 'id' => $exceptId],
        ) > 0;
    }

    public function all(): array
    {
        return array_map(
            fn (array $row): User => $this->hydrate($row),
            $this->fetchAll(self::SELECT . ' ORDER BY name ASC'),
        );
    }

    public function search(array $criteria, int $limit, int $offset): array
    {
        [$where, $params] = $this->buildFilter($criteria);

        $rows = $this->fetchAll(
            self::SELECT . $where . ' ORDER BY name ASC LIMIT :limit OFFSET :offset',
            $params + ['limit' => $limit, 'offset' => $offset],
        );

        return array_map(fn (array $row): User => $this->hydrate($row), $rows);
    }

    public function countBy(array $criteria): int
    {
        [$where, $params] = $this->buildFilter($criteria);

        return $this->fetchInt('SELECT COUNT(*) FROM `user`' . $where, $params);
    }

    public function save(User $user): int
    {
        if ($user->id === null) {
            $this->run(
                'INSERT INTO `user` (name, email, password_hash, role, is_active, created_at, updated_at)
                      VALUES (:name, :email, :password_hash, :role, :is_active, NOW(), NOW())',
                [
                    'name'          => $user->name,
                    'email'         => $user->email,
                    'password_hash' => $user->passwordHash,
                    'role'          => $user->role->value,
                    'is_active'     => $user->isActive ? 1 : 0,
                ],
            );

            return $this->lastInsertId();
        }

        // password_hash tidak diubah di sini. Perubahan password melewati
        // updatePasswordHash(), yang controller-nya menuntut step-up re-auth.
        $this->run(
            'UPDATE `user`
                SET name = :name, email = :email, role = :role, is_active = :is_active, updated_at = NOW()
              WHERE id = :id',
            [
                'id'        => $user->id,
                'name'      => $user->name,
                'email'     => $user->email,
                'role'      => $user->role->value,
                'is_active' => $user->isActive ? 1 : 0,
            ],
        );

        return $user->id;
    }

    public function updatePasswordHash(int $id, string $passwordHash): void
    {
        $this->run(
            'UPDATE `user` SET password_hash = :password_hash, updated_at = NOW() WHERE id = :id',
            ['id' => $id, 'password_hash' => $passwordHash],
        );
    }

    public function setActive(int $id, bool $isActive): void
    {
        $this->run(
            'UPDATE `user` SET is_active = :is_active, updated_at = NOW() WHERE id = :id',
            ['id' => $id, 'is_active' => $isActive ? 1 : 0],
        );
    }

    /**
     * @param array{search?: string, role?: string, active?: bool} $criteria
     * @return array{0: string, 1: array<string, mixed>}
     */
    private function buildFilter(array $criteria): array
    {
        $clauses = [];
        $params = [];

        if (($criteria['search'] ?? '') !== '') {
            // Satu nama placeholder hanya boleh muncul SEKALI per statement:
            // ATTR_EMULATE_PREPARES = false membuat PDO meneruskan statement apa
            // adanya ke MySQL, yang tidak mengenal placeholder bernama berulang
            // (SQLSTATE[HY093]). Karena itu tiap kolom memakai namanya sendiri,
            // dengan nilai yang sama.
            $clauses[] = '(name LIKE :search_name OR email LIKE :search_email)';
            $params['search_name'] = '%' . $criteria['search'] . '%';
            $params['search_email'] = '%' . $criteria['search'] . '%';
        }

        if (($criteria['role'] ?? '') !== '') {
            $clauses[] = 'role = :role';
            $params['role'] = $criteria['role'];
        }

        if (isset($criteria['active'])) {
            $clauses[] = 'is_active = :active';
            $params['active'] = $criteria['active'] ? 1 : 0;
        }

        return [$clauses === [] ? '' : ' WHERE ' . implode(' AND ', $clauses), $params];
    }

    /** @param array<string, mixed> $row */
    private function hydrate(array $row): User
    {
        return new User(
            (int) $row['id'],
            (string) $row['name'],
            (string) $row['email'],
            (string) $row['password_hash'],
            Role::from((string) $row['role']),
            (bool) $row['is_active'],
        );
    }
}
