<?php

declare(strict_types=1);

namespace Tests\Unit\Fake;

use App\Entity\Enum\PurchaseOrderStatus;
use App\Entity\PurchaseOrder;
use App\Entity\PurchaseOrderItem;
use App\Repository\PurchaseOrderRepositoryInterface;

final class InMemoryPurchaseOrderRepository implements PurchaseOrderRepositoryInterface
{
    /** @var array<int, PurchaseOrder> */
    private array $rows = [];

    private int $nextId = 1;

    /** Id untuk line yang ditulis ulang replaceItems(); jauh dari id fixture. */
    private int $nextItemId = 10_000;

    private bool $failNextUpdateDraft = false;

    /**
     * Nama supplier, warehouse dan user di-inject agar ordersBetween() dapat
     * mengembalikan kolom nama persis seperti query MySQL-nya.
     *
     * @param list<PurchaseOrder> $orders
     * @param array<int, string>  $supplierNames
     * @param array<int, string>  $warehouseNames
     * @param array<int, string>  $userNames
     */
    public function __construct(
        array $orders = [],
        private readonly array $supplierNames = [],
        private readonly array $warehouseNames = [],
        private readonly array $userNames = [],
    ) {
        foreach ($orders as $order) {
            $this->save($order);
        }
    }

    public function findById(int $id): ?PurchaseOrder
    {
        return $this->rows[$id] ?? null;
    }

    /** Tanpa konkurensi di memory, lock cukup berupa pembacaan biasa. */
    public function lockForUpdate(int $id): ?PurchaseOrder
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

    public function save(PurchaseOrder $order): int
    {
        $id = $order->id ?? $this->nextId++;

        $this->rows[$id] = $this->withId($order, $id, $order->status, $order->items);

        if ($id >= $this->nextId) {
            $this->nextId = $id + 1;
        }

        return $id;
    }

    public function updateStatus(int $id, PurchaseOrderStatus $expected, PurchaseOrderStatus $status): bool
    {
        $order = $this->rows[$id] ?? null;

        // Compare-and-set, sama seperti WHERE status = :expected di MySQL.
        if ($order === null || $order->status !== $expected) {
            return false;
        }

        $this->rows[$id] = $this->withId($order, $id, $status, $order->items);

        return true;
    }

    public function updateDraft(PurchaseOrder $order): bool
    {
        $id = (int) $order->id;
        $stored = $this->rows[$id] ?? null;

        if ($this->failNextUpdateDraft) {
            $this->failNextUpdateDraft = false;

            return false;
        }

        // Compare-and-set, sama seperti WHERE status = 'Draft' di MySQL.
        if ($stored === null || $stored->status !== PurchaseOrderStatus::Draft) {
            return false;
        }

        // Hanya header yang dapat diubah; nomor, status, pembuat, dan line
        // diambil dari baris tersimpan.
        $this->rows[$id] = new PurchaseOrder(
            $id,
            $stored->orderNumber,
            $order->supplierId,
            $order->warehouseId,
            $stored->status,
            $order->orderDate,
            $stored->createdBy,
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
            $replaced[] = new PurchaseOrderItem(
                $this->nextItemId++,
                $orderId,
                $item->productId,
                $item->quantity,
                $item->receivedQuantity,
                $item->purchasePrice,
            );
        }

        $this->rows[$orderId] = $this->withId($stored, $orderId, $stored->status, $replaced);
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

    public function addReceivedQuantity(int $itemId, int $quantity): void
    {
        foreach ($this->rows as $orderId => $order) {
            $changed = false;
            $items = [];

            foreach ($order->items as $item) {
                if ($item->id === $itemId) {
                    $items[] = new PurchaseOrderItem(
                        $item->id,
                        $item->purchaseOrderId,
                        $item->productId,
                        $item->quantity,
                        $item->receivedQuantity + $quantity,
                        $item->purchasePrice,
                    );
                    $changed = true;
                    continue;
                }

                $items[] = $item;
            }

            if ($changed) {
                $this->rows[$orderId] = $this->withId($order, $orderId, $order->status, $items);
                return;
            }
        }
    }

    public function countByStatus(): array
    {
        $counts = [];

        foreach ($this->rows as $order) {
            $key = $order->status->value;
            $counts[$key] = ($counts[$key] ?? 0) + 1;
        }

        return $counts;
    }

    public function awaitingReceipt(int $limit): array
    {
        $matching = array_filter(
            $this->rows,
            static fn (PurchaseOrder $o): bool => $o->status === PurchaseOrderStatus::Ordered
                || $o->status === PurchaseOrderStatus::PartiallyReceived,
        );

        return array_slice(array_values($matching), 0, $limit);
    }

    /**
     * Bentuk baris sengaja identik dengan
     * MysqlPurchaseOrderRepository::ordersBetween() — nama dan jumlah kolom
     * yang sama, agar ReportService tidak lulus di sini lalu gagal di MySQL.
     */
    public function ordersBetween(string $startDate, string $endDate): array
    {
        $result = [];

        foreach ($this->rows as $order) {
            if ($order->orderDate < $startDate || $order->orderDate > $endDate) {
                continue;
            }

            $ordered = 0;
            $received = 0;
            $total = 0.0;

            foreach ($order->items as $item) {
                $ordered += $item->quantity;
                $received += $item->receivedQuantity;
                $total += $item->quantity * (float) $item->purchasePrice;
            }

            $result[] = [
                'order_number'      => $order->orderNumber,
                'order_date'        => $order->orderDate,
                'status'            => $order->status->value,
                'supplier_name'     => $this->supplierNames[$order->supplierId] ?? 'Supplier ' . $order->supplierId,
                'warehouse_name'    => $this->warehouseNames[$order->warehouseId] ?? 'Warehouse ' . $order->warehouseId,
                'created_by_name'   => $this->userNames[$order->createdBy] ?? 'User ' . $order->createdBy,
                'ordered_quantity'  => $ordered,
                'received_quantity' => $received,
                'total_value'       => number_format($total, 2, '.', ''),
            ];
        }

        return $result;
    }

    /**
     * @param list<PurchaseOrderItem> $items
     */
    private function withId(
        PurchaseOrder $order,
        int $id,
        PurchaseOrderStatus $status,
        array $items,
    ): PurchaseOrder {
        return new PurchaseOrder(
            $id,
            $order->orderNumber,
            $order->supplierId,
            $order->warehouseId,
            $status,
            $order->orderDate,
            $order->createdBy,
            $items,
        );
    }

    /**
     * @param array{search?: string, status?: string, sort?: string, direction?: string} $criteria
     * @return list<PurchaseOrder>
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

            $result[] = $order;
        }

        return $result;
    }
}
