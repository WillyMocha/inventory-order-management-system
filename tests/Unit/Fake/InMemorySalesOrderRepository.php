<?php

declare(strict_types=1);

namespace Tests\Unit\Fake;

use App\Entity\Enum\SalesOrderStatus;
use App\Entity\SalesOrder;
use App\Entity\SalesOrderItem;
use App\Repository\SalesOrderRepositoryInterface;

final class InMemorySalesOrderRepository implements SalesOrderRepositoryInterface
{
    /** @var array<int, SalesOrder> */
    private array $rows = [];

    private int $nextId = 1;

    /** Id untuk line yang ditulis ulang replaceItems(); jauh dari id fixture. */
    private int $nextItemId = 10_000;

    private bool $failNextUpdateDraft = false;

    /** @var array<int, string> orderId => approvedAt */
    private array $approvedAt = [];

    /**
     * Nama customer, warehouse dan user di-inject agar ordersBetween() dapat
     * mengembalikan kolom nama persis seperti query MySQL-nya. Kosong pun
     * tetap valid: nama dibangkitkan dari id.
     *
     * @param list<SalesOrder>   $orders
     * @param array<int, string> $customerNames
     * @param array<int, string> $warehouseNames
     * @param array<int, string> $userNames
     */
    public function __construct(
        array $orders = [],
        private readonly array $customerNames = [],
        private readonly array $warehouseNames = [],
        private readonly array $userNames = [],
    ) {
        foreach ($orders as $order) {
            $this->save($order);
        }
    }

    public function findById(int $id): ?SalesOrder
    {
        return $this->rows[$id] ?? null;
    }

    /** Tanpa konkurensi di memory, lock cukup berupa pembacaan biasa. */
    public function lockForUpdate(int $id): ?SalesOrder
    {
        return $this->findById($id);
    }

    public function orderNumberExists(string $orderNumber): bool
    {
        foreach ($this->rows as $order) {
            if ($order->orderNumber === $orderNumber) {
                return true;
            }
        }

        return false;
    }

    public function search(array $criteria, int $limit, int $offset): array
    {
        return array_slice($this->filter($criteria), $offset, $limit);
    }

    public function countBy(array $criteria): int
    {
        return count($this->filter($criteria));
    }

    public function save(SalesOrder $order): int
    {
        $id = $order->id ?? $this->nextId++;

        $this->rows[$id] = new SalesOrder(
            $id,
            $order->orderNumber,
            $order->customerId,
            $order->createdBy,
            $order->approvedBy,
            $order->warehouseId,
            $order->status,
            $order->orderDate,
            $order->items,
        );

        if ($id >= $this->nextId) {
            $this->nextId = $id + 1;
        }

        return $id;
    }

    public function updateStatus(int $id, SalesOrderStatus $expected, SalesOrderStatus $status): bool
    {
        $order = $this->rows[$id] ?? null;

        // Compare-and-set, sama seperti WHERE status = :expected di MySQL.
        if ($order === null || $order->status !== $expected) {
            return false;
        }

        $this->rows[$id] = new SalesOrder(
            $id,
            $order->orderNumber,
            $order->customerId,
            $order->createdBy,
            $order->approvedBy,
            $order->warehouseId,
            $status,
            $order->orderDate,
            $order->items,
        );

        return true;
    }

    public function updateDraft(SalesOrder $order): bool
    {
        $id = (int) $order->id;
        $stored = $this->rows[$id] ?? null;

        if ($this->failNextUpdateDraft) {
            $this->failNextUpdateDraft = false;

            return false;
        }

        // Compare-and-set, sama seperti WHERE status = 'Draft' di MySQL.
        if ($stored === null || $stored->status !== SalesOrderStatus::Draft) {
            return false;
        }

        // Hanya header yang dapat diubah; nomor, pembuat, approver, status,
        // dan line diambil dari baris tersimpan.
        $this->rows[$id] = new SalesOrder(
            $id,
            $stored->orderNumber,
            $order->customerId,
            $stored->createdBy,
            $stored->approvedBy,
            $order->warehouseId,
            $stored->status,
            $order->orderDate,
            $stored->items,
        );

        return true;
    }

    public function replaceItems(int $orderId, array $items): void
    {
        $stored = $this->rows[$orderId] ?? null;

        if ($stored === null) {
            return;
        }

        $replaced = [];
        foreach ($items as $item) {
            $replaced[] = new SalesOrderItem(
                $this->nextItemId++,
                $orderId,
                $item->productId,
                $item->quantity,
                $item->sellingPrice,
            );
        }

        $this->rows[$orderId] = new SalesOrder(
            $orderId,
            $stored->orderNumber,
            $stored->customerId,
            $stored->createdBy,
            $stored->approvedBy,
            $stored->warehouseId,
            $stored->status,
            $stored->orderDate,
            $replaced,
        );
    }

    /**
     * Khusus test: updateDraft() berikutnya mengembalikan false tanpa
     * mengubah apa pun. Memodelkan order yang keluar dari Draft di antara
     * pembacaan dan penyimpanan (spec 004, research R-003) — kelas ini final,
     * jadi perilaku itu tidak dapat dibuat lewat subclass.
     */
    public function failNextUpdateDraft(): void
    {
        $this->failNextUpdateDraft = true;
    }

    public function markApproved(int $id, int $approvedBy, string $approvedAt): bool
    {
        $order = $this->rows[$id] ?? null;

        if ($order === null || $order->status !== SalesOrderStatus::PendingApproval) {
            return false;
        }

        $this->rows[$id] = new SalesOrder(
            $id,
            $order->orderNumber,
            $order->customerId,
            $order->createdBy,
            $approvedBy,
            $order->warehouseId,
            SalesOrderStatus::Approved,
            $order->orderDate,
            $order->items,
        );

        $this->approvedAt[$id] = $approvedAt;

        return true;
    }

    public function approvedAt(int $id): ?string
    {
        return $this->approvedAt[$id] ?? null;
    }

    public function countByStatus(?int $createdBy = null): array
    {
        $counts = [];

        foreach ($this->rows as $order) {
            if ($createdBy !== null && $order->createdBy !== $createdBy) {
                continue;
            }

            $key = $order->status->value;
            $counts[$key] = ($counts[$key] ?? 0) + 1;
        }

        return $counts;
    }

    public function awaitingIssue(int $limit): array
    {
        $matching = array_filter(
            $this->rows,
            static fn (SalesOrder $o): bool => $o->status === SalesOrderStatus::Approved,
        );

        return array_slice(array_values($matching), 0, $limit);
    }

    /**
     * Bentuk baris di sini sengaja dibuat identik dengan
     * MysqlSalesOrderRepository::ordersBetween() — nama kolom yang sama,
     * jumlah kolom yang sama. Kalau fake mengembalikan bentuk yang lebih
     * miskin, ReportService bisa lulus unit test lalu gagal terhadap database
     * sungguhan.
     */
    public function ordersBetween(string $startDate, string $endDate, ?int $createdBy = null): array
    {
        $result = [];

        foreach ($this->rows as $order) {
            if ($order->orderDate < $startDate || $order->orderDate > $endDate) {
                continue;
            }

            if ($createdBy !== null && $order->createdBy !== $createdBy) {
                continue;
            }

            $result[] = [
                'order_number'      => $order->orderNumber,
                'order_date'        => $order->orderDate,
                'status'            => $order->status->value,
                'customer_name'     => $this->nameFor($this->customerNames, $order->customerId, 'Customer'),
                'warehouse_name'    => $this->nameFor($this->warehouseNames, $order->warehouseId, 'Warehouse'),
                'created_by_name'   => $this->nameFor($this->userNames, $order->createdBy, 'User'),
                'approved_by_name'  => $order->approvedBy === null
                    ? null
                    : $this->nameFor($this->userNames, $order->approvedBy, 'User'),
                'total_value'       => $this->totalValueOf($order),
            ];
        }

        return $result;
    }

    /** @param array<int, string> $names */
    private function nameFor(array $names, int $id, string $fallbackPrefix): string
    {
        return $names[$id] ?? $fallbackPrefix . ' ' . $id;
    }

    /** Sama seperti SUM(quantity * selling_price) pada query MySQL-nya. */
    private function totalValueOf(SalesOrder $order): string
    {
        $total = 0.0;

        foreach ($order->items as $item) {
            $total += $item->quantity * (float) $item->sellingPrice;
        }

        return number_format($total, 2, '.', '');
    }

    /**
     * @param array{search?: string, status?: string, createdBy?: int, sort?: string, direction?: string} $criteria
     * @return list<SalesOrder>
     */
    private function filter(array $criteria): array
    {
        $result = [];

        foreach ($this->rows as $order) {
            if (($criteria['search'] ?? '') !== '') {
                $needle = strtolower((string) $criteria['search']);

                if (!str_contains(strtolower($order->orderNumber), $needle)) {
                    continue;
                }
            }

            if (($criteria['status'] ?? '') !== '' && $order->status->value !== $criteria['status']) {
                continue;
            }

            // Scoping kepemilikan Sales (§1.2).
            if (($criteria['createdBy'] ?? 0) > 0 && $order->createdBy !== $criteria['createdBy']) {
                continue;
            }

            $result[] = $order;
        }

        return $result;
    }
}
