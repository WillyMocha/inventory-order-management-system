<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Enum\PurchaseOrderStatus;
use App\Entity\PurchaseOrder;
use App\Entity\PurchaseOrderItem;
use App\Entity\User;
use App\Repository\ProductRepositoryInterface;
use App\Repository\PurchaseOrderRepositoryInterface;
use App\Repository\SupplierRepositoryInterface;
use App\Repository\WarehouseRepositoryInterface;
use App\Support\ClockInterface;
use App\Support\Exception\DomainException;
use App\Support\Exception\NotFoundException;
use App\Support\Validator;

/**
 * Alur pembelian dari supplier (PO-01, FR-012, FR-013).
 *
 * Perhatikan apa yang TIDAK ada di constructor: tidak ada
 * ProductStockRepositoryInterface dan tidak ada StockLedgerRepositoryInterface.
 * Service ini secara struktural tidak mampu menyentuh stock — mengajukan atau
 * membatalkan PO tidak pernah memindahkan barang. Stock hanya berubah lewat
 * StockService::receiveGoods() yang sekaligus menulis stock_ledger dalam
 * transaction yang sama (ARCH-02).
 *
 * Acting user di-pass sebagai argument dan tidak pernah dibaca dari session,
 * konsisten dengan SalesOrderService.
 *
 * Tidak ada scoping kepemilikan di sini: Sales tidak punya akses ke jalur
 * pembelian sama sekali, dan itu ditegakkan route table
 * (contracts/http-routes.md), bukan dengan filter di dalam Service.
 */
final class PurchaseOrderService
{
    /** Batas percobaan saat menyusun order number yang belum terpakai. */
    private const int ORDER_NUMBER_ATTEMPTS = 100;

    public function __construct(
        private readonly PurchaseOrderRepositoryInterface $orders,
        private readonly SupplierRepositoryInterface $suppliers,
        private readonly WarehouseRepositoryInterface $warehouses,
        private readonly ProductRepositoryInterface $products,
        private readonly ClockInterface $clock,
    ) {
    }

    /**
     * Membuat Purchase Order berstatus Draft.
     *
     * created_by diambil dari $actingUser, tidak pernah dari $data (FR-012).
     *
     * @param array<string, mixed> $data
     *
     * @throws \App\Support\Exception\ValidationException
     */
    public function create(array $data, User $actingUser): int
    {
        $items = $this->validate($data);

        return $this->orders->save(new PurchaseOrder(
            null,
            $this->nextOrderNumber(),
            (int) $data['supplier_id'],
            (int) $data['warehouse_id'],
            PurchaseOrderStatus::Draft,
            $this->resolveOrderDate($data),
            (int) $actingUser->id,
            $items,
        ));
    }

    /**
     * Draft -> Ordered (FR-013). Tidak memindahkan stock apa pun.
     *
     * @throws NotFoundException
     * @throws DomainException
     */
    public function submit(int $id, User $actingUser): void
    {
        $order = $this->requireOrder($id);

        $this->assertCanTransition($order, PurchaseOrderStatus::Ordered);

        $this->orders->updateStatus($id, PurchaseOrderStatus::Ordered);
    }

    /**
     * Cancel diizinkan pada tahap mana pun sebelum Received (spec A-004),
     * termasuk setelah sebagian barang diterima.
     *
     * Stock yang sudah masuk TIDAK dikembalikan otomatis — pembatalan bukan
     * pergerakan barang, dan ledger bersifat append-only.
     *
     * @throws NotFoundException
     * @throws DomainException
     */
    public function cancel(int $id, User $actingUser): void
    {
        $order = $this->requireOrder($id);

        $this->assertCanTransition($order, PurchaseOrderStatus::Cancelled);

        $this->orders->updateStatus($id, PurchaseOrderStatus::Cancelled);
    }

    /** @throws NotFoundException */
    public function requireOrder(int $id): PurchaseOrder
    {
        $order = $this->orders->findById($id);

        if ($order === null) {
            throw new NotFoundException();
        }

        return $order;
    }

    /**
     * @param array{search?: string, status?: string, sort?: string, direction?: string} $criteria
     * @return list<PurchaseOrder>
     */
    public function search(array $criteria, int $limit, int $offset): array
    {
        return $this->orders->search($criteria, $limit, $offset);
    }

    /** @param array{search?: string, status?: string} $criteria */
    public function count(array $criteria): int
    {
        return $this->orders->countBy($criteria);
    }

    /** @return array<string, int> */
    public function countByStatus(): array
    {
        return $this->orders->countByStatus();
    }

    /** @throws DomainException */
    private function assertCanTransition(PurchaseOrder $order, PurchaseOrderStatus $target): void
    {
        if (!$order->canTransitionTo($target)) {
            throw new DomainException(sprintf(
                'A %s order cannot become %s.',
                $order->status->label(),
                $target->label(),
            ));
        }
    }

    /**
     * Order number unik, berpola PO-YYYYMMDD-#### (research R-009).
     *
     * Keunikan tetap dijaga UNIQUE constraint di database; loop ini hanya
     * menghindari tabrakan yang terduga agar user tidak melihat error.
     */
    private function nextOrderNumber(): string
    {
        $prefix = 'PO-' . $this->clock->now()->format('Ymd') . '-';

        for ($attempt = 1; $attempt <= self::ORDER_NUMBER_ATTEMPTS; $attempt++) {
            $candidate = $prefix . str_pad((string) $attempt, 4, '0', STR_PAD_LEFT);

            if (!$this->orders->orderNumberExists($candidate)) {
                return $candidate;
            }
        }

        return $prefix . str_pad((string) random_int(1000, 9999), 4, '0', STR_PAD_LEFT);
    }

    /** @param array<string, mixed> $data */
    private function resolveOrderDate(array $data): string
    {
        $supplied = trim((string) ($data['order_date'] ?? ''));

        return $supplied === '' ? $this->clock->now()->format('Y-m-d') : $supplied;
    }

    /**
     * Memvalidasi header dan seluruh line, lalu mengembalikan line yang sudah
     * ter-hidrasi dengan received_quantity nol.
     *
     * Harga beli di-snapshot dari katalog, bukan dari payload.
     *
     * @param array<string, mixed> $data
     * @return list<PurchaseOrderItem>
     *
     * @throws \App\Support\Exception\ValidationException
     */
    private function validate(array $data): array
    {
        $validator = Validator::make($data)
            ->required('supplier_id', 'Supplier')
            ->existsById('supplier_id', 'Supplier', fn (int $id): bool => $this->suppliers->exists($id))
            ->required('warehouse_id', 'Destination warehouse')
            ->existsById(
                'warehouse_id',
                'Destination warehouse',
                fn (int $id): bool => $this->warehouses->exists($id),
            );

        if (($data['order_date'] ?? '') !== '') {
            $validator->date('order_date', 'Order date');
        }

        $rawItems = is_array($data['items'] ?? null) ? $data['items'] : [];
        $items = [];

        foreach ($rawItems as $index => $rawItem) {
            if (!is_array($rawItem)) {
                continue;
            }

            $item = $this->validateLine($validator, $rawItem, (int) $index);

            if ($item !== null) {
                $items[] = $item;
            }
        }

        $validator->rule(
            'items',
            $items !== [],
            'Add at least one product line to the order.',
        );

        $validator->validate();

        return $items;
    }

    /** @param array<string, mixed> $rawItem */
    private function validateLine(Validator $validator, array $rawItem, int $index): ?PurchaseOrderItem
    {
        $productId = (int) ($rawItem['product_id'] ?? 0);
        $quantity = (int) ($rawItem['quantity'] ?? 0);
        $field = 'items.' . $index;

        $product = $productId > 0 ? $this->products->findById($productId) : null;

        if ($product === null) {
            $validator->rule($field . '.product_id', false, 'Select a product for every line.');

            return null;
        }

        if ($quantity < 1) {
            $validator->rule($field . '.quantity', false, 'Quantity must be at least 1.');

            return null;
        }

        return new PurchaseOrderItem(
            null,
            null,
            $productId,
            $quantity,
            // Belum ada yang diterima saat order dibuat.
            0,
            $product->purchasePrice,
        );
    }
}
