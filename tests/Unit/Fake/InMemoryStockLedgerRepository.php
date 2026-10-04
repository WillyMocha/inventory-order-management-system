<?php

declare(strict_types=1);

namespace Tests\Unit\Fake;

use App\Entity\Enum\MovementType;
use App\Entity\Enum\ReferenceType;
use App\Entity\StockLedger;
use App\Repository\StockLedgerRepositoryInterface;

/**
 * Fake in-memory untuk StockLedgerRepositoryInterface.
 *
 * Append-only, sama seperti implementasi MySQL-nya. Tidak menyediakan cara
 * mengubah atau menghapus baris — kalau fake-nya mengizinkan, unit test bisa
 * lulus untuk perilaku yang produksinya justru melarang.
 */
final class InMemoryStockLedgerRepository implements StockLedgerRepositoryInterface
{
    /** Dipakai bila test tidak menentukan waktu sendiri lewat recordAt(). */
    private const string DEFAULT_TIMESTAMP = '2026-01-01 00:00:00';

    /** @var list<StockLedger> */
    private array $entries = [];

    /** @var list<string> created_at per entry, sejajar indeks dengan $entries */
    private array $createdAt = [];

    private string $nextCreatedAt = self::DEFAULT_TIMESTAMP;

    /** @var array<int, string> */
    private array $productSkus = [];

    /** @var array<int, string> */
    private array $productNames = [];

    /** @var array<int, string> */
    private array $warehouseNames = [];

    /** @var array<int, string> */
    private array $userNames = [];

    private int $nextId = 1;

    /** Jumlah panggilan dailyMovementTotals() — bukti satu query per dashboard (spec 005 NFR-001). */
    private int $dailyTotalsCalls = 0;

    public function append(StockLedger $entry): int
    {
        $id = $this->nextId++;

        $this->entries[] = new StockLedger(
            $id,
            $entry->productId,
            $entry->warehouseId,
            $entry->movementType,
            $entry->quantity,
            $entry->referenceType,
            $entry->referenceId,
            $entry->performedBy,
            $entry->note,
        );

        $this->createdAt[] = $this->nextCreatedAt;

        return $id;
    }

    public function forReference(ReferenceType $type, int $referenceId): array
    {
        return array_values(array_filter(
            $this->entries,
            static fn (StockLedger $e): bool => $e->referenceType === $type && $e->referenceId === $referenceId,
        ));
    }

    public function forProductAndWarehouse(int $productId, int $warehouseId, int $limit): array
    {
        $matching = array_filter(
            $this->entries,
            static fn (StockLedger $e): bool => $e->productId === $productId && $e->warehouseId === $warehouseId,
        );

        return array_slice(array_values($matching), 0, $limit);
    }

    /**
     * Bentuk baris identik dengan MysqlStockLedgerRepository::movementsBetween()
     * — nama kolom yang sama, jumlah kolom yang sama — agar ReportService tidak
     * bisa lulus unit test dengan bentuk yang lebih miskin daripada produksinya.
     *
     * endDate bersifat inklusif, sama seperti `< (:end_date + INTERVAL 1 DAY)`
     * pada query aslinya.
     */
    public function movementsBetween(string $startDate, string $endDate): array
    {
        $result = [];

        foreach ($this->entries as $index => $entry) {
            $createdAt = $this->createdAt[$index];
            $date = substr($createdAt, 0, 10);

            if ($date < $startDate || $date > $endDate) {
                continue;
            }

            $result[] = [
                'created_at'        => $createdAt,
                'sku'               => $this->labelFor($this->productSkus, $entry->productId, 'SKU'),
                'product_name'      => $this->labelFor($this->productNames, $entry->productId, 'Product'),
                'warehouse_name'    => $this->labelFor($this->warehouseNames, $entry->warehouseId, 'Warehouse'),
                'movement_type'     => $entry->movementType->value,
                'quantity'          => $entry->quantity,
                'reference_type'    => $entry->referenceType->value,
                'reference_id'      => $entry->referenceId,
                'performed_by_name' => $this->labelFor($this->userNames, $entry->performedBy, 'User'),
                'note'              => $entry->note,
            ];
        }

        return $result;
    }

    /**
     * Aturan tanggal sama dengan movementsBetween() milik fake ini; masuk dan
     * keluar dipisah dari tanda quantity, seperti versi MySQL.
     */
    public function dailyMovementTotals(string $startDate, string $endDate): array
    {
        $this->dailyTotalsCalls++;

        $totals = [];

        foreach ($this->entries as $index => $entry) {
            $date = substr($this->createdAt[$index], 0, 10);

            if ($date < $startDate || $date > $endDate) {
                continue;
            }

            $totals[$date] ??= ['in' => 0, 'out' => 0];
            $totals[$date][$entry->quantity > 0 ? 'in' : 'out'] += abs($entry->quantity);
        }

        ksort($totals);

        return $totals;
    }

    public function dailyTotalsCalls(): int
    {
        return $this->dailyTotalsCalls;
    }

    /** @param array<int, string> $labels */
    private function labelFor(array $labels, int $id, string $fallbackPrefix): string
    {
        return $labels[$id] ?? $fallbackPrefix . ' ' . $id;
    }

    /**
     * Timestamp yang dipakai append() berikutnya. Di produksi kolom
     * created_at diisi NOW(); di sini waktunya dikendalikan test agar
     * pemfilteran rentang tanggal dapat diuji secara deterministik.
     */
    public function recordAt(string $timestamp): void
    {
        $this->nextCreatedAt = $timestamp;
    }

    /**
     * Label yang dipakai kolom nama pada movementsBetween().
     *
     * @param array<int, string> $productSkus
     * @param array<int, string> $productNames
     * @param array<int, string> $warehouseNames
     * @param array<int, string> $userNames
     */
    public function withLabels(
        array $productSkus = [],
        array $productNames = [],
        array $warehouseNames = [],
        array $userNames = [],
    ): void {
        $this->productSkus = $productSkus;
        $this->productNames = $productNames;
        $this->warehouseNames = $warehouseNames;
        $this->userNames = $userNames;
    }

    /**
     * Bentuk baris identik dengan versi MySQL. Saldo berjalan dihitung dari
     * seluruh pergerakan warehouse yang sama, diurutkan seperti window
     * function-nya (created_at, lalu id).
     */
    public function recentAdjustmentsForProduct(int $productId, int $limit): array
    {
        $indexes = array_keys(array_filter(
            $this->entries,
            static fn (StockLedger $e): bool => $e->productId === $productId,
        ));
        usort(
            $indexes,
            fn (int $a, int $b): int => [$this->createdAt[$a], $this->entries[$a]->id]
                <=> [$this->createdAt[$b], $this->entries[$b]->id],
        );

        $balances = [];
        $adjustments = [];

        foreach ($indexes as $index) {
            $entry = $this->entries[$index];
            $balances[$entry->warehouseId] = ($balances[$entry->warehouseId] ?? 0) + $entry->quantity;

            if ($entry->movementType !== MovementType::Adjustment) {
                continue;
            }

            $adjustments[] = [
                'createdAt'       => $this->createdAt[$index],
                'warehouseName'   => $this->labelFor($this->warehouseNames, $entry->warehouseId, 'Warehouse'),
                'quantity'        => $entry->quantity,
                'balanceAfter'    => $balances[$entry->warehouseId],
                'performedByName' => $this->labelFor($this->userNames, $entry->performedBy, 'User'),
                'note'            => (string) $entry->note,
            ];
        }

        return array_slice(array_reverse($adjustments), 0, $limit);
    }

    public function sumQuantity(int $productId, int $warehouseId): int
    {
        $sum = 0;

        foreach ($this->entries as $entry) {
            if ($entry->productId === $productId && $entry->warehouseId === $warehouseId) {
                $sum += $entry->quantity;
            }
        }

        return $sum;
    }

    /**
     * Seluruh baris yang tercatat — dipakai test untuk memverifikasi bahwa
     * setiap pergerakan stock menghasilkan tepat satu baris ledger.
     *
     * @return list<StockLedger>
     */
    public function all(): array
    {
        return $this->entries;
    }

    public function count(): int
    {
        return count($this->entries);
    }
}
