<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Enum\ReferenceType;
use App\Entity\StockLedger;

/**
 * Riwayat pergerakan stock. Append-only — tidak ada method update maupun
 * delete, dan itu disengaja.
 */
interface StockLedgerRepositoryInterface
{
    public function append(StockLedger $entry): int;

    /** @return list<StockLedger> */
    public function forReference(ReferenceType $type, int $referenceId): array;

    /**
     * @return list<StockLedger>
     */
    public function forProductAndWarehouse(int $productId, int $warehouseId, int $limit): array;

    /**
     * Pergerakan dalam rentang tanggal untuk report CSV (REPORT-01).
     *
     * @return list<array<string, mixed>>
     */
    public function movementsBetween(string $startDate, string $endDate): array;

    /**
     * Jumlah quantity ledger untuk satu pasangan (product, warehouse).
     * Dipakai pemeriksaan rekonsiliasi terhadap product_stock (NFR-002).
     */
    public function sumQuantity(int $productId, int $warehouseId): int;
}
