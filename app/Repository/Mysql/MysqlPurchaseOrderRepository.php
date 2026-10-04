<?php

declare(strict_types=1);

namespace App\Repository\Mysql;

use App\Entity\Enum\PurchaseOrderStatus;
use App\Entity\PurchaseOrder;
use App\Entity\PurchaseOrderItem;
use App\Repository\PurchaseOrderRepositoryInterface;
use RuntimeException;

final class MysqlPurchaseOrderRepository extends MysqlRepository implements PurchaseOrderRepositoryInterface
{
    private const string SELECT = 'SELECT id, order_number, supplier_id, warehouse_id, status,
                   order_date, created_by
              FROM purchase_order';

    /** @var array<string, string> */
    private const array SORTABLE = [
        'date'   => 'order_date',
        'number' => 'order_number',
        'status' => 'status',
    ];

    public function findById(int $id): ?PurchaseOrder
    {
        $row = $this->fetchOne(self::SELECT . ' WHERE id = :id', ['id' => $id]);

        if ($row === null) {
            return null;
        }

        return $this->hydrate($row, $this->loadItems($id));
    }

    /**
     * Item ikut dikunci karena received_quantity-nya yang diperebutkan dua
     * receipt bersamaan. Locking read juga selalu membaca versi terbaru yang
     * sudah commit, bukan snapshot lama transaction ini.
     *
     * @throws RuntimeException bila dipanggil di luar transaction.
     */
    public function lockForUpdate(int $id): ?PurchaseOrder
    {
        if (!$this->pdo()->inTransaction()) {
            throw new RuntimeException('lockForUpdate() wajib dipanggil di dalam transaction.');
        }

        $row = $this->fetchOne(self::SELECT . ' WHERE id = :id FOR UPDATE', ['id' => $id]);

        if ($row === null) {
            return null;
        }

        return $this->hydrate($row, $this->loadItems($id, true));
    }

    public function orderNumberExists(string $orderNumber): bool
    {
        return $this->fetchInt(
            'SELECT COUNT(*) FROM purchase_order WHERE order_number = :n',
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

        // Item tidak dimuat pada listing: halaman daftar tidak memerlukannya
        // dan memuatnya akan menimbulkan N+1 query.
        return array_map(fn (array $row): PurchaseOrder => $this->hydrate($row, []), $rows);
    }

    public function countBy(array $criteria): int
    {
        [$where, $params] = $this->buildFilter($criteria);

        return $this->fetchInt('SELECT COUNT(*) FROM purchase_order' . $where, $params);
    }

    public function save(PurchaseOrder $order): int
    {
        if ($order->id !== null) {
            $this->run(
                'UPDATE purchase_order
                    SET supplier_id = :supplier_id, warehouse_id = :warehouse_id,
                        order_date = :order_date, updated_at = NOW()
                  WHERE id = :id',
                [
                    'id'           => $order->id,
                    'supplier_id'  => $order->supplierId,
                    'warehouse_id' => $order->warehouseId,
                    'order_date'   => $order->orderDate,
                ],
            );

            return $order->id;
        }

        $this->run(
            'INSERT INTO purchase_order (order_number, supplier_id, warehouse_id, status,
                                         order_date, created_by, created_at, updated_at)
                  VALUES (:order_number, :supplier_id, :warehouse_id, :status,
                          :order_date, :created_by, NOW(), NOW())',
            [
                'order_number' => $order->orderNumber,
                'supplier_id'  => $order->supplierId,
                'warehouse_id' => $order->warehouseId,
                'status'       => $order->status->value,
                'order_date'   => $order->orderDate,
                'created_by'   => $order->createdBy,
            ],
        );

        $orderId = $this->lastInsertId();

        $this->insertItems($orderId, $order->items);

        return $orderId;
    }

    public function updateDraft(PurchaseOrder $order): bool
    {
        $params = ['id' => (int) $order->id, 'draft' => PurchaseOrderStatus::Draft->value];

        $updated = $this->run(
            'UPDATE purchase_order
                SET supplier_id = :supplier_id, warehouse_id = :warehouse_id,
                    order_date = :order_date, updated_at = NOW()
              WHERE id = :id AND status = :draft',
            $params + [
                'supplier_id'  => $order->supplierId,
                'warehouse_id' => $order->warehouseId,
                'order_date'   => $order->orderDate,
            ],
        )->rowCount() === 1;

        // Lihat MysqlSalesOrderRepository::updateDraft(): rowCount() 0 dapat
        // berarti "tidak ada yang berubah", bukan "bukan Draft".
        return $updated
            || $this->fetchInt('SELECT COUNT(*) FROM purchase_order WHERE id = :id AND status = :draft', $params) > 0;
    }

    public function replaceItems(int $orderId, array $items): void
    {
        $this->run(
            'DELETE FROM purchase_order_item WHERE purchase_order_id = :order_id',
            ['order_id' => $orderId],
        );

        $this->insertItems($orderId, $items);
    }

    /** @param list<PurchaseOrderItem> $items */
    private function insertItems(int $orderId, array $items): void
    {
        foreach ($items as $item) {
            $this->run(
                'INSERT INTO purchase_order_item (purchase_order_id, product_id, quantity,
                                                  received_quantity, purchase_price)
                      VALUES (:order_id, :product_id, :quantity, :received_quantity, :purchase_price)',
                [
                    'order_id'          => $orderId,
                    'product_id'        => $item->productId,
                    'quantity'          => $item->quantity,
                    'received_quantity' => $item->receivedQuantity,
                    'purchase_price'    => $item->purchasePrice,
                ],
            );
        }
    }

    public function updateStatus(int $id, PurchaseOrderStatus $expected, PurchaseOrderStatus $status): bool
    {
        return $this->run(
            'UPDATE purchase_order SET status = :status, updated_at = NOW()
              WHERE id = :id AND status = :expected',
            ['id' => $id, 'status' => $status->value, 'expected' => $expected->value],
        )->rowCount() === 1;
    }

    public function addReceivedQuantity(int $itemId, int $quantity): void
    {
        // CHECK constraint received_quantity <= quantity menjadi jaring
        // pengaman terakhir bila ada jalur yang lolos pemeriksaan Service.
        $this->run(
            'UPDATE purchase_order_item
                SET received_quantity = received_quantity + :quantity
              WHERE id = :id',
            ['id' => $itemId, 'quantity' => $quantity],
        );
    }

    public function countByStatus(): array
    {
        $rows = $this->fetchAll('SELECT status, COUNT(*) AS total FROM purchase_order GROUP BY status');

        $counts = [];
        foreach ($rows as $row) {
            $counts[(string) $row['status']] = (int) $row['total'];
        }

        return $counts;
    }

    public function awaitingReceipt(int $limit): array
    {
        $rows = $this->fetchAll(
            self::SELECT
            . " WHERE status IN ('Ordered', 'PartiallyReceived')"
            . ' ORDER BY order_date ASC, id ASC LIMIT :limit',
            ['limit' => $limit],
        );

        return array_map(fn (array $row): PurchaseOrder => $this->hydrate($row, []), $rows);
    }

    public function ordersBetween(string $startDate, string $endDate): array
    {
        return $this->fetchAll(
            'SELECT po.order_number, po.order_date, po.status, s.name AS supplier_name,
                    w.name AS warehouse_name, creator.name AS created_by_name,
                    COALESCE(SUM(poi.quantity), 0) AS ordered_quantity,
                    COALESCE(SUM(poi.received_quantity), 0) AS received_quantity,
                    COALESCE(SUM(poi.quantity * poi.purchase_price), 0) AS total_value
               FROM purchase_order po
               JOIN supplier s ON s.id = po.supplier_id
               JOIN warehouse w ON w.id = po.warehouse_id
               JOIN `user` creator ON creator.id = po.created_by
          LEFT JOIN purchase_order_item poi ON poi.purchase_order_id = po.id
              WHERE po.order_date >= :start_date AND po.order_date <= :end_date
           GROUP BY po.id, po.order_number, po.order_date, po.status, s.name, w.name, creator.name
           ORDER BY po.order_date ASC, po.id ASC',
            ['start_date' => $startDate, 'end_date' => $endDate],
        );
    }

    /** @return list<PurchaseOrderItem> */
    private function loadItems(int $orderId, bool $forUpdate = false): array
    {
        $rows = $this->fetchAll(
            'SELECT id, purchase_order_id, product_id, quantity, received_quantity, purchase_price
               FROM purchase_order_item
              WHERE purchase_order_id = :order_id
           ORDER BY id ASC' . ($forUpdate ? ' FOR UPDATE' : ''),
            ['order_id' => $orderId],
        );

        return array_map(
            static fn (array $row): PurchaseOrderItem => new PurchaseOrderItem(
                (int) $row['id'],
                (int) $row['purchase_order_id'],
                (int) $row['product_id'],
                (int) $row['quantity'],
                (int) $row['received_quantity'],
                (string) $row['purchase_price'],
            ),
            $rows,
        );
    }

    /**
     * @param array{search?: string, status?: string, sort?: string, direction?: string} $criteria
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
                . ' OR supplier_id IN (SELECT id FROM supplier WHERE name LIKE :search_supplier))';
            $params['search_number'] = '%' . $criteria['search'] . '%';
            $params['search_supplier'] = '%' . $criteria['search'] . '%';
        }

        if (($criteria['status'] ?? '') !== '') {
            $clauses[] = 'status = :status';
            $params['status'] = $criteria['status'];
        }

        return [$clauses === [] ? '' : ' WHERE ' . implode(' AND ', $clauses), $params];
    }

    /**
     * @param array<string, mixed> $row
     * @param list<PurchaseOrderItem> $items
     */
    private function hydrate(array $row, array $items): PurchaseOrder
    {
        return new PurchaseOrder(
            (int) $row['id'],
            (string) $row['order_number'],
            (int) $row['supplier_id'],
            (int) $row['warehouse_id'],
            PurchaseOrderStatus::from((string) $row['status']),
            (string) $row['order_date'],
            (int) $row['created_by'],
            $items,
        );
    }
}
