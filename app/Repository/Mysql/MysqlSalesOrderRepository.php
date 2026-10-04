<?php

declare(strict_types=1);

namespace App\Repository\Mysql;

use App\Entity\Enum\SalesOrderStatus;
use App\Entity\SalesOrder;
use App\Entity\SalesOrderItem;
use App\Repository\SalesOrderRepositoryInterface;
use RuntimeException;

final class MysqlSalesOrderRepository extends MysqlRepository implements SalesOrderRepositoryInterface
{
    private const string SELECT = 'SELECT id, order_number, customer_id, created_by, approved_by,
                   warehouse_id, status, order_date
              FROM sales_order';

    /** @var array<string, string> */
    private const array SORTABLE = [
        'date'   => 'order_date',
        'number' => 'order_number',
        'status' => 'status',
    ];

    public function findById(int $id): ?SalesOrder
    {
        $row = $this->fetchOne(self::SELECT . ' WHERE id = :id', ['id' => $id]);

        if ($row === null) {
            return null;
        }

        return $this->hydrate($row, $this->loadItems($id));
    }

    /**
     * Item tidak ikut dikunci: setelah Draft, item Sales Order tidak pernah
     * berubah. Yang diperebutkan hanya status di baris header.
     *
     * @throws RuntimeException bila dipanggil di luar transaction.
     */
    public function lockForUpdate(int $id): ?SalesOrder
    {
        if (!$this->pdo()->inTransaction()) {
            throw new RuntimeException('lockForUpdate() wajib dipanggil di dalam transaction.');
        }

        $row = $this->fetchOne(self::SELECT . ' WHERE id = :id FOR UPDATE', ['id' => $id]);

        if ($row === null) {
            return null;
        }

        return $this->hydrate($row, $this->loadItems($id));
    }

    public function orderNumberExists(string $orderNumber): bool
    {
        return $this->fetchInt(
            'SELECT COUNT(*) FROM sales_order WHERE order_number = :n',
            ['n' => $orderNumber],
        ) > 0;
    }

    public function search(array $criteria, int $limit, int $offset): array
    {
        [$where, $params] = $this->buildFilter($criteria);

        $sortColumn = $this->resolveSortColumn(self::SORTABLE, $criteria['sort'] ?? null, 'order_date');
        $direction = $this->resolveDirection($criteria['direction'] ?? null);

        $rows = $this->fetchAll(
            self::SELECT . $where
            . ' ORDER BY ' . $sortColumn . ' ' . $direction . ', id DESC'
            . ' LIMIT :limit OFFSET :offset',
            $params + ['limit' => $limit, 'offset' => $offset],
        );

        return array_map(fn (array $row): SalesOrder => $this->hydrate($row, []), $rows);
    }

    public function countBy(array $criteria): int
    {
        [$where, $params] = $this->buildFilter($criteria);

        return $this->fetchInt('SELECT COUNT(*) FROM sales_order' . $where, $params);
    }

    public function save(SalesOrder $order): int
    {
        if ($order->id !== null) {
            $this->run(
                'UPDATE sales_order
                    SET customer_id = :customer_id, warehouse_id = :warehouse_id,
                        order_date = :order_date, updated_at = NOW()
                  WHERE id = :id',
                [
                    'id'           => $order->id,
                    'customer_id'  => $order->customerId,
                    'warehouse_id' => $order->warehouseId,
                    'order_date'   => $order->orderDate,
                ],
            );

            return $order->id;
        }

        $this->run(
            'INSERT INTO sales_order (order_number, customer_id, created_by, approved_by,
                                      warehouse_id, status, order_date, created_at, updated_at)
                  VALUES (:order_number, :customer_id, :created_by, NULL,
                          :warehouse_id, :status, :order_date, NOW(), NOW())',
            [
                'order_number' => $order->orderNumber,
                'customer_id'  => $order->customerId,
                // created_by selalu berasal dari acting user yang di-pass ke
                // Service, tidak pernah dari payload request (§1.2).
                'created_by'   => $order->createdBy,
                'warehouse_id' => $order->warehouseId,
                'status'       => $order->status->value,
                'order_date'   => $order->orderDate,
            ],
        );

        $orderId = $this->lastInsertId();

        foreach ($order->items as $item) {
            $this->run(
                'INSERT INTO sales_order_item (sales_order_id, product_id, quantity, selling_price)
                      VALUES (:order_id, :product_id, :quantity, :selling_price)',
                [
                    'order_id'      => $orderId,
                    'product_id'    => $item->productId,
                    'quantity'      => $item->quantity,
                    'selling_price' => $item->sellingPrice,
                ],
            );
        }

        return $orderId;
    }

    public function updateStatus(int $id, SalesOrderStatus $expected, SalesOrderStatus $status): bool
    {
        // Syarat status pada WHERE dievaluasi InnoDB sebagai current read di
        // bawah row lock, bukan dari snapshot request ini — jadi status yang
        // sudah diubah request lain selalu terlihat.
        return $this->run(
            'UPDATE sales_order SET status = :status, updated_at = NOW()
              WHERE id = :id AND status = :expected',
            ['id' => $id, 'status' => $status->value, 'expected' => $expected->value],
        )->rowCount() === 1;
    }

    public function markApproved(int $id, int $approvedBy, string $approvedAt): bool
    {
        return $this->run(
            'UPDATE sales_order
                SET status = :status, approved_by = :approved_by, approved_at = :approved_at,
                    updated_at = NOW()
              WHERE id = :id AND status = :expected',
            [
                'id'          => $id,
                'status'      => SalesOrderStatus::Approved->value,
                'approved_by' => $approvedBy,
                'approved_at' => $approvedAt,
                'expected'    => SalesOrderStatus::PendingApproval->value,
            ],
        )->rowCount() === 1;
    }

    public function countByStatus(?int $createdBy = null): array
    {
        if ($createdBy === null) {
            $rows = $this->fetchAll('SELECT status, COUNT(*) AS total FROM sales_order GROUP BY status');
        } else {
            $rows = $this->fetchAll(
                'SELECT status, COUNT(*) AS total FROM sales_order WHERE created_by = :id GROUP BY status',
                ['id' => $createdBy],
            );
        }

        $counts = [];
        foreach ($rows as $row) {
            $counts[(string) $row['status']] = (int) $row['total'];
        }

        return $counts;
    }

    public function awaitingIssue(int $limit): array
    {
        $rows = $this->fetchAll(
            self::SELECT
            . " WHERE status = 'Approved'"
            . ' ORDER BY order_date ASC, id ASC LIMIT :limit',
            ['limit' => $limit],
        );

        return array_map(fn (array $row): SalesOrder => $this->hydrate($row, []), $rows);
    }

    public function ordersBetween(string $startDate, string $endDate, ?int $createdBy = null): array
    {
        $sql = 'SELECT so.order_number, so.order_date, so.status, c.name AS customer_name,
                       w.name AS warehouse_name, creator.name AS created_by_name,
                       approver.name AS approved_by_name,
                       COALESCE(SUM(soi.quantity * soi.selling_price), 0) AS total_value
                  FROM sales_order so
                  JOIN customer c ON c.id = so.customer_id
                  JOIN warehouse w ON w.id = so.warehouse_id
                  JOIN `user` creator ON creator.id = so.created_by
             LEFT JOIN `user` approver ON approver.id = so.approved_by
             LEFT JOIN sales_order_item soi ON soi.sales_order_id = so.id
                 WHERE so.order_date >= :start_date AND so.order_date <= :end_date';

        $params = ['start_date' => $startDate, 'end_date' => $endDate];

        // Scoping kepemilikan untuk role Sales dilakukan di dalam WHERE
        // clause, bukan difilter setelah query (security standard §2).
        if ($createdBy !== null) {
            $sql .= ' AND so.created_by = :created_by';
            $params['created_by'] = $createdBy;
        }

        $sql .= ' GROUP BY so.id, so.order_number, so.order_date, so.status, c.name,
                          w.name, creator.name, approver.name
                  ORDER BY so.order_date ASC, so.id ASC';

        return $this->fetchAll($sql, $params);
    }

    /** @return list<SalesOrderItem> */
    private function loadItems(int $orderId): array
    {
        $rows = $this->fetchAll(
            'SELECT id, sales_order_id, product_id, quantity, selling_price
               FROM sales_order_item
              WHERE sales_order_id = :order_id
           ORDER BY id ASC',
            ['order_id' => $orderId],
        );

        return array_map(
            static fn (array $row): SalesOrderItem => new SalesOrderItem(
                (int) $row['id'],
                (int) $row['sales_order_id'],
                (int) $row['product_id'],
                (int) $row['quantity'],
                (string) $row['selling_price'],
            ),
            $rows,
        );
    }

    /**
     * @param array{search?: string, status?: string, createdBy?: int, sort?: string, direction?: string} $criteria
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
            $clauses[] = '(order_number LIKE :search_number'
                . ' OR customer_id IN (SELECT id FROM customer WHERE name LIKE :search_customer))';
            $params['search_number'] = '%' . $criteria['search'] . '%';
            $params['search_customer'] = '%' . $criteria['search'] . '%';
        }

        if (($criteria['status'] ?? '') !== '') {
            $clauses[] = 'status = :status';
            $params['status'] = $criteria['status'];
        }

        // Sales hanya melihat order miliknya sendiri (§1.2).
        if (($criteria['createdBy'] ?? 0) > 0) {
            $clauses[] = 'created_by = :created_by';
            $params['created_by'] = $criteria['createdBy'];
        }

        return [$clauses === [] ? '' : ' WHERE ' . implode(' AND ', $clauses), $params];
    }

    /**
     * @param array<string, mixed> $row
     * @param list<SalesOrderItem> $items
     */
    private function hydrate(array $row, array $items): SalesOrder
    {
        $approvedBy = $row['approved_by'];

        return new SalesOrder(
            (int) $row['id'],
            (string) $row['order_number'],
            (int) $row['customer_id'],
            (int) $row['created_by'],
            $approvedBy === null ? null : (int) $approvedBy,
            (int) $row['warehouse_id'],
            SalesOrderStatus::from((string) $row['status']),
            (string) $row['order_date'],
            $items,
        );
    }
}
