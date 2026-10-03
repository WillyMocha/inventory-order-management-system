<?php

declare(strict_types=1);

namespace App\Repository\Mysql;

use App\Entity\Enum\MovementType;
use App\Entity\Enum\ReferenceType;
use App\Entity\StockLedger;
use App\Repository\StockLedgerRepositoryInterface;

/**
 * Riwayat pergerakan stock — APPEND-ONLY.
 *
 * Tidak ada method update maupun delete, dan itu disengaja: setiap perubahan
 * angka stock harus dapat ditelusuri ke tepat satu baris ledger (§1.3,
 * NFR-002).
 */
final class MysqlStockLedgerRepository extends MysqlRepository implements StockLedgerRepositoryInterface
{
    private const string SELECT = 'SELECT id, product_id, warehouse_id, movement_type, quantity,
                   reference_type, reference_id, performed_by
              FROM stock_ledger';

    public function append(StockLedger $entry): int
    {
        $this->run(
            'INSERT INTO stock_ledger (product_id, warehouse_id, movement_type, quantity,
                                       reference_type, reference_id, performed_by, created_at)
                  VALUES (:product_id, :warehouse_id, :movement_type, :quantity,
                          :reference_type, :reference_id, :performed_by, NOW())',
            [
                'product_id'     => $entry->productId,
                'warehouse_id'   => $entry->warehouseId,
                'movement_type'  => $entry->movementType->value,
                'quantity'       => $entry->quantity,
                'reference_type' => $entry->referenceType->value,
                'reference_id'   => $entry->referenceId,
                'performed_by'   => $entry->performedBy,
            ],
        );

        return $this->lastInsertId();
    }

    public function forReference(ReferenceType $type, int $referenceId): array
    {
        $rows = $this->fetchAll(
            self::SELECT . ' WHERE reference_type = :type AND reference_id = :id ORDER BY created_at ASC, id ASC',
            ['type' => $type->value, 'id' => $referenceId],
        );

        return array_map(fn (array $row): StockLedger => $this->hydrate($row), $rows);
    }

    public function forProductAndWarehouse(int $productId, int $warehouseId, int $limit): array
    {
        $rows = $this->fetchAll(
            self::SELECT
            . ' WHERE product_id = :product_id AND warehouse_id = :warehouse_id'
            . ' ORDER BY created_at DESC, id DESC LIMIT :limit',
            ['product_id' => $productId, 'warehouse_id' => $warehouseId, 'limit' => $limit],
        );

        return array_map(fn (array $row): StockLedger => $this->hydrate($row), $rows);
    }

    public function movementsBetween(string $startDate, string $endDate): array
    {
        return $this->fetchAll(
            'SELECT sl.created_at, p.sku, p.name AS product_name, w.name AS warehouse_name,
                    sl.movement_type, sl.quantity, sl.reference_type, sl.reference_id,
                    u.name AS performed_by_name
               FROM stock_ledger sl
               JOIN product p ON p.id = sl.product_id
               JOIN warehouse w ON w.id = sl.warehouse_id
               JOIN `user` u ON u.id = sl.performed_by
              WHERE sl.created_at >= :start_date AND sl.created_at < (:end_date + INTERVAL 1 DAY)
           ORDER BY sl.created_at ASC, sl.id ASC',
            ['start_date' => $startDate, 'end_date' => $endDate],
        );
    }

    public function sumQuantity(int $productId, int $warehouseId): int
    {
        return $this->fetchInt(
            'SELECT COALESCE(SUM(quantity), 0)
               FROM stock_ledger
              WHERE product_id = :product_id AND warehouse_id = :warehouse_id',
            ['product_id' => $productId, 'warehouse_id' => $warehouseId],
        );
    }

    /** @param array<string, mixed> $row */
    private function hydrate(array $row): StockLedger
    {
        $referenceId = $row['reference_id'];

        return new StockLedger(
            (int) $row['id'],
            (int) $row['product_id'],
            (int) $row['warehouse_id'],
            MovementType::from((string) $row['movement_type']),
            (int) $row['quantity'],
            ReferenceType::from((string) $row['reference_type']),
            $referenceId === null ? null : (int) $referenceId,
            (int) $row['performed_by'],
        );
    }
}
