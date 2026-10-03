<?php

declare(strict_types=1);

namespace App\Repository\Mysql;

use App\Entity\Customer;
use App\Repository\CustomerRepositoryInterface;

final class MysqlCustomerRepository extends MysqlRepository implements CustomerRepositoryInterface
{
    private const string SELECT = 'SELECT id, name, contact, address, is_active FROM customer';

    public function findById(int $id): ?Customer
    {
        $row = $this->fetchOne(self::SELECT . ' WHERE id = :id', ['id' => $id]);

        return $row === null ? null : $this->hydrate($row);
    }

    public function exists(int $id): bool
    {
        return $this->fetchInt('SELECT COUNT(*) FROM customer WHERE id = :id', ['id' => $id]) > 0;
    }

    public function all(): array
    {
        return array_map(
            fn (array $row): Customer => $this->hydrate($row),
            $this->fetchAll(self::SELECT . ' ORDER BY name ASC'),
        );
    }

    public function allActive(): array
    {
        return array_map(
            fn (array $row): Customer => $this->hydrate($row),
            $this->fetchAll(self::SELECT . ' WHERE is_active = 1 ORDER BY name ASC'),
        );
    }

    public function search(array $criteria, int $limit, int $offset): array
    {
        [$where, $params] = $this->buildFilter($criteria);

        $rows = $this->fetchAll(
            self::SELECT . $where . ' ORDER BY name ASC LIMIT :limit OFFSET :offset',
            $params + ['limit' => $limit, 'offset' => $offset],
        );

        return array_map(fn (array $row): Customer => $this->hydrate($row), $rows);
    }

    public function countBy(array $criteria): int
    {
        [$where, $params] = $this->buildFilter($criteria);

        return $this->fetchInt('SELECT COUNT(*) FROM customer' . $where, $params);
    }

    public function save(Customer $customer): int
    {
        if ($customer->id === null) {
            $this->run(
                'INSERT INTO customer (name, contact, address, is_active, created_at, updated_at)
                      VALUES (:name, :contact, :address, :is_active, NOW(), NOW())',
                [
                    'name'      => $customer->name,
                    'contact'   => $customer->contact,
                    'address'   => $customer->address,
                    'is_active' => $customer->isActive ? 1 : 0,
                ],
            );

            return $this->lastInsertId();
        }

        $this->run(
            'UPDATE customer
                SET name = :name, contact = :contact, address = :address,
                    is_active = :is_active, updated_at = NOW()
              WHERE id = :id',
            [
                'id'        => $customer->id,
                'name'      => $customer->name,
                'contact'   => $customer->contact,
                'address'   => $customer->address,
                'is_active' => $customer->isActive ? 1 : 0,
            ],
        );

        return $customer->id;
    }

    public function setActive(int $id, bool $isActive): void
    {
        $this->run(
            'UPDATE customer SET is_active = :is_active, updated_at = NOW() WHERE id = :id',
            ['id' => $id, 'is_active' => $isActive ? 1 : 0],
        );
    }

    /** Customer yang sudah dipakai SO hanya boleh dinonaktifkan (§1.3). */
    public function isReferencedByOrder(int $id): bool
    {
        return $this->fetchInt(
            'SELECT COUNT(*) FROM sales_order WHERE customer_id = :id',
            ['id' => $id],
        ) > 0;
    }

    /**
     * @param array{search?: string, active?: bool} $criteria
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
            $clauses[] = '(name LIKE :search_name OR contact LIKE :search_contact)';
            $params['search_name'] = '%' . $criteria['search'] . '%';
            $params['search_contact'] = '%' . $criteria['search'] . '%';
        }

        if (isset($criteria['active'])) {
            $clauses[] = 'is_active = :active';
            $params['active'] = $criteria['active'] ? 1 : 0;
        }

        return [$clauses === [] ? '' : ' WHERE ' . implode(' AND ', $clauses), $params];
    }

    /** @param array<string, mixed> $row */
    private function hydrate(array $row): Customer
    {
        return new Customer(
            (int) $row['id'],
            (string) $row['name'],
            (string) $row['contact'],
            (string) $row['address'],
            (bool) $row['is_active'],
        );
    }
}
