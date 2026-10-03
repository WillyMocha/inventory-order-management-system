<?php

declare(strict_types=1);

namespace Tests\Unit\Fake;

use App\Entity\ProductStock;
use App\Repository\ProductStockRepositoryInterface;

/**
 * Fake in-memory untuk ProductStockRepositoryInterface.
 *
 * lockForUpdate() di sini hanya membaca baris: unit test berjalan single
 * threaded sehingga tidak ada yang perlu dikunci. Perilaku lock yang
 * sesungguhnya dibuktikan integration test dengan dua connection nyata
 * (ConcurrentGoodsIssueTest) — itu yang benar-benar menguji ARCH-02, bukan
 * fake ini.
 */
final class InMemoryProductStockRepository implements ProductStockRepositoryInterface
{
    /** @var array<string, int> "productId:warehouseId" => quantity */
    private array $quantities = [];

    /** @var array<int, string> warehouseId => nama, untuk breakdown */
    private array $warehouseNames = [];

    private int $lockCallCount = 0;

    /**
     * @param array<string, int> $quantities kunci "productId:warehouseId"
     * @param array<int, string> $warehouseNames
     */
    public function __construct(array $quantities = [], array $warehouseNames = [])
    {
        $this->quantities = $quantities;
        $this->warehouseNames = $warehouseNames;
    }

    public function findFor(int $productId, int $warehouseId): ?ProductStock
    {
        $key = $this->key($productId, $warehouseId);

        if (!array_key_exists($key, $this->quantities)) {
            return null;
        }

        return new ProductStock(null, $productId, $warehouseId, $this->quantities[$key]);
    }

    public function lockForUpdate(int $productId, int $warehouseId): ?ProductStock
    {
        $this->lockCallCount++;

        return $this->findFor($productId, $warehouseId);
    }

    /**
     * Berapa kali lock diminta. Dipakai test untuk memastikan Service benar-
     * benar mengunci baris sebelum mengubah stock.
     */
    public function lockCallCount(): int
    {
        return $this->lockCallCount;
    }

    public function adjust(int $productId, int $warehouseId, int $delta): void
    {
        $key = $this->key($productId, $warehouseId);
        $this->quantities[$key] = ($this->quantities[$key] ?? 0) + $delta;
    }

    public function breakdownForProduct(int $productId): array
    {
        $breakdown = [];

        foreach ($this->quantities as $key => $quantity) {
            [$storedProductId, $warehouseId] = array_map(intval(...), explode(':', $key));

            if ($storedProductId !== $productId) {
                continue;
            }

            $breakdown[] = [
                'warehouseId'   => $warehouseId,
                'warehouseName' => $this->warehouseNames[$warehouseId] ?? ('Warehouse ' . $warehouseId),
                'quantity'      => $quantity,
            ];
        }

        return $breakdown;
    }

    public function totalForProduct(int $productId): int
    {
        $total = 0;

        foreach ($this->quantities as $key => $quantity) {
            if (str_starts_with($key, $productId . ':')) {
                $total += $quantity;
            }
        }

        return $total;
    }

    private function key(int $productId, int $warehouseId): string
    {
        return $productId . ':' . $warehouseId;
    }
}
