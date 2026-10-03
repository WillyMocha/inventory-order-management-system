<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Entity\Enum\ReferenceType;
use App\Entity\StockLedger;
use App\Repository\StockLedgerRepositoryInterface;
use RuntimeException;

/**
 * Dekorator ledger yang gagal pada penulisan kedua.
 *
 * Dipakai HANYA untuk membuktikan rollback: kegagalan harus terjadi setelah
 * sebagian pekerjaan sudah dilakukan, kalau tidak yang teruji hanyalah
 * validasi sebelum operasi dan bukan atomicity-nya.
 */
final class FailOnSecondAppendLedger implements StockLedgerRepositoryInterface
{
    private int $appendCount = 0;

    public function __construct(private readonly StockLedgerRepositoryInterface $inner)
    {
    }

    public function append(StockLedger $entry): int
    {
        $this->appendCount++;

        if ($this->appendCount >= 2) {
            throw new RuntimeException('Forced failure mid-receipt');
        }

        return $this->inner->append($entry);
    }

    public function appendCount(): int
    {
        return $this->appendCount;
    }

    public function forReference(ReferenceType $type, int $referenceId): array
    {
        return $this->inner->forReference($type, $referenceId);
    }

    public function forProductAndWarehouse(int $productId, int $warehouseId, int $limit): array
    {
        return $this->inner->forProductAndWarehouse($productId, $warehouseId, $limit);
    }

    public function movementsBetween(string $startDate, string $endDate): array
    {
        return $this->inner->movementsBetween($startDate, $endDate);
    }

    public function sumQuantity(int $productId, int $warehouseId): int
    {
        return $this->inner->sumQuantity($productId, $warehouseId);
    }
}
