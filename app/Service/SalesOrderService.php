<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Enum\Role;
use App\Entity\Enum\SalesOrderStatus;
use App\Entity\SalesOrder;
use App\Entity\SalesOrderItem;
use App\Entity\User;
use App\Repository\CustomerRepositoryInterface;
use App\Repository\ProductRepositoryInterface;
use App\Repository\SalesOrderRepositoryInterface;
use App\Repository\WarehouseRepositoryInterface;
use App\Support\ClockInterface;
use App\Support\Exception\DomainException;
use App\Support\Exception\ForbiddenException;
use App\Support\Exception\NotFoundException;
use App\Support\TransactionRunner;
use App\Support\Validator;

/**
 * Alur penjualan ke customer (SO-01, FR-016 s/d FR-018).
 *
 * Service ini memegang aturan paling dijaga dalam sistem: segregation of
 * duties pada approve(). Aturannya ditegakkan DI SINI, bukan dengan
 * menyembunyikan tombol — menyembunyikan tombol bukan kontrol akses
 * (§1.2, security standard §2).
 *
 * Acting user selalu di-pass sebagai argument dan tidak pernah dibaca dari
 * session. Itulah yang membuat aturan approval dapat di-unit-test tanpa
 * session maupun database (constitution Principle III).
 *
 * Service ini tidak pernah menyentuh quantity stock. Pergerakan stock hanya
 * lewat StockService yang sekaligus menulis stock_ledger dalam transaction
 * yang sama (ARCH-02).
 */
final class SalesOrderService
{
    /** Batas percobaan saat menyusun order number yang belum terpakai. */
    private const int ORDER_NUMBER_ATTEMPTS = 100;

    public function __construct(
        private readonly SalesOrderRepositoryInterface $orders,
        private readonly CustomerRepositoryInterface $customers,
        private readonly WarehouseRepositoryInterface $warehouses,
        private readonly ProductRepositoryInterface $products,
        private readonly ClockInterface $clock,
        // Header dan line sebuah edit Draft disimpan dalam satu transaction
        // (spec 004 FR-011, research R-004) — pola yang sama dengan StockService.
        private readonly TransactionRunner $transactions,
    ) {
    }

    /**
     * Membuat Sales Order berstatus Draft.
     *
     * created_by diambil dari $actingUser, tidak pernah dari $data — payload
     * request tidak boleh menentukan siapa pembuat order (FR-016).
     *
     * @param array<string, mixed> $data
     *
     * @throws \App\Support\Exception\ValidationException
     */
    public function create(array $data, User $actingUser): int
    {
        $items = $this->validate($data);

        return $this->orders->save(new SalesOrder(
            null,
            $this->nextOrderNumber(),
            (int) $data['customer_id'],
            (int) $actingUser->id,
            null,
            (int) $data['warehouse_id'],
            SalesOrderStatus::Draft,
            $this->resolveOrderDate($data),
            $items,
        ));
    }

    /**
     * Mengedit Sales Order Draft: customer, warehouse asal, tanggal, dan
     * seluruh line (spec 004-edit-draft-orders).
     *
     * Validasi dan snapshot harga memakai validate() yang sama dengan
     * create(), jadi order hasil edit tidak pernah lebih longgar dari order
     * baru (research R-005). Nomor, status, pembuat, dan approver tidak pernah
     * diambil dari $data.
     *
     * @param array<string, mixed> $data
     *
     * @throws NotFoundException
     * @throws ForbiddenException
     * @throws DomainException
     * @throws \App\Support\Exception\ValidationException
     */
    public function update(int $id, array $data, User $actingUser): void
    {
        $order = $this->requireVisibleOrder($id, $actingUser);
        $this->assertMayEdit($order, $actingUser);

        if ($order->status !== SalesOrderStatus::Draft) {
            throw self::onlyDraftEditable();
        }

        $items = $this->validate($data);
        $edited = new SalesOrder(
            $id,
            $order->orderNumber,
            (int) $data['customer_id'],
            $order->createdBy,
            $order->approvedBy,
            (int) $data['warehouse_id'],
            $order->status,
            $this->resolveOrderDate($data),
        );

        // Header lebih dulu: UPDATE bersyarat status = 'Draft' sekaligus
        // mengunci baris order, sehingga edit lain menunggu dan line dua edit
        // tidak pernah tercampur (research R-004).
        $this->transactions->transaction(function () use ($edited, $items, $id): void {
            if (!$this->orders->updateDraft($edited)) {
                throw self::onlyDraftEditable();
            }

            $this->orders->replaceItems($id, $items);
        });
    }

    /**
     * Apakah tombol Edit layak ditawarkan: order masih Draft DAN acting user
     * boleh mengeditnya. Penolakan sesungguhnya tetap di update().
     */
    public function canEdit(SalesOrder $order, User $actingUser): bool
    {
        return $order->status === SalesOrderStatus::Draft && $this->mayEdit($order, $actingUser);
    }

    /**
     * Bagian izin dari aturan edit, terlepas dari status order.
     *
     * Hanya PEMBUAT order yang boleh mengedit, termasuk bila ia Admin
     * (Clarifications Q3). Kalau Admin boleh mengubah order buatan orang lain,
     * ia dapat mengubah isinya lalu meng-approve-nya sendiri, dan aturan
     * approved_by <> created_by kehilangan arti. Dipakai update() dan
     * controller agar layar edit dan penyimpanan memeriksa dengan urutan yang
     * sama (contracts "Check order").
     *
     * @throws ForbiddenException
     */
    public function assertMayEdit(SalesOrder $order, User $actingUser): void
    {
        if (!$this->mayEdit($order, $actingUser)) {
            throw new ForbiddenException('You can only edit a draft order you created.');
        }
    }

    /**
     * Draft -> PendingApproval (FR-017).
     *
     * @throws NotFoundException
     * @throws DomainException
     */
    public function submit(int $id, User $actingUser): void
    {
        $order = $this->requireVisibleOrder($id, $actingUser);

        $this->transition($order, SalesOrderStatus::PendingApproval);
    }

    /**
     * Cancel diizinkan pada tahap mana pun sebelum Fulfilled (spec A-004).
     *
     * @throws NotFoundException
     * @throws DomainException
     */
    public function cancel(int $id, User $actingUser): void
    {
        $order = $this->requireVisibleOrder($id, $actingUser);

        $this->transition($order, SalesOrderStatus::Cancelled);
    }

    /**
     * Menyetujui order — inti segregation of duties (FR-018, §1.2).
     *
     * DUA syarat harus terpenuhi bersamaan:
     *   1. role acting user adalah Admin; dan
     *   2. approved_by tidak sama dengan created_by.
     *
     * Syarat kedua berlaku untuk siapa pun termasuk Admin, sehingga seorang
     * Admin pun tidak dapat menyetujui order yang dibuatnya sendiri.
     *
     * @throws NotFoundException
     * @throws ForbiddenException
     * @throws DomainException
     */
    public function approve(int $id, User $actingUser): void
    {
        $order = $this->requireApprovableOrder($id, $actingUser);

        $this->assertCanTransition($order, SalesOrderStatus::Approved);

        $approved = $this->orders->markApproved(
            $id,
            (int) $actingUser->id,
            $this->clock->now()->format('Y-m-d H:i:s'),
        );

        if (!$approved) {
            throw self::changedMeanwhile();
        }
    }

    /**
     * Menolak order. Sumber menyebut reject sebagai aksi Admin tanpa
     * menyediakan state Rejected tersendiri, sehingga order berakhir di
     * Cancelled (data-model.md).
     *
     * Syarat approver-nya sama dengan approve(): hanya Admin, dan tidak boleh
     * order miliknya sendiri.
     *
     * @throws NotFoundException
     * @throws ForbiddenException
     * @throws DomainException
     */
    public function reject(int $id, User $actingUser): void
    {
        $order = $this->requireApprovableOrder($id, $actingUser);

        if ($order->status !== SalesOrderStatus::PendingApproval) {
            throw new DomainException(
                'Only an order awaiting approval can be rejected.',
            );
        }

        $this->transition($order, SalesOrderStatus::Cancelled);
    }

    /**
     * Order yang boleh dilihat acting user, atau NotFound.
     *
     * Sengaja 404 dan bukan 403 untuk resource di luar scope: 403 akan
     * mengonfirmasi bahwa record-nya ada (contracts/http-routes.md, NFR-003).
     *
     * @throws NotFoundException
     */
    public function requireVisibleOrder(int $id, User $actingUser): SalesOrder
    {
        $order = $this->orders->findById($id);

        if ($order === null) {
            throw new NotFoundException();
        }

        // Sales hanya boleh menjangkau order miliknya sendiri.
        if ($actingUser->role === Role::Sales && !$order->isOwnedBy((int) $actingUser->id)) {
            throw new NotFoundException();
        }

        return $order;
    }

    /**
     * Criteria scoping kepemilikan untuk acting user.
     *
     * Untuk Sales menghasilkan filter createdBy sehingga pembatasannya masuk
     * ke WHERE clause query, bukan difilter setelah data terambil (§1.2).
     *
     * @return array{createdBy?: int}
     */
    public function scopeFor(User $actingUser): array
    {
        return $actingUser->role === Role::Sales
            ? ['createdBy' => (int) $actingUser->id]
            : [];
    }

    /**
     * @param array{search?: string, status?: string, createdBy?: int, sort?: string, direction?: string} $criteria
     * @return list<SalesOrder>
     */
    public function search(array $criteria, int $limit, int $offset): array
    {
        return $this->orders->search($criteria, $limit, $offset);
    }

    /** @param array{search?: string, status?: string, createdBy?: int} $criteria */
    public function count(array $criteria): int
    {
        return $this->orders->countBy($criteria);
    }

    /** @return array<string, int> */
    public function countByStatus(User $actingUser): array
    {
        return $this->orders->countByStatus(
            $actingUser->role === Role::Sales ? (int) $actingUser->id : null,
        );
    }

    /**
     * Order yang memenuhi syarat approver, sebelum transisinya diperiksa.
     *
     * @throws NotFoundException
     * @throws ForbiddenException
     */
    private function requireApprovableOrder(int $id, User $actingUser): SalesOrder
    {
        $order = $this->requireVisibleOrder($id, $actingUser);

        // Syarat 1 — hanya Admin yang boleh menyetujui. Sales tidak pernah
        // boleh, apa pun order-nya.
        if (!$actingUser->isAdmin()) {
            throw new ForbiddenException('Only an Admin can approve or reject a sales order.');
        }

        // Syarat 2 — approver tidak boleh pembuat order itu sendiri.
        if ($order->isCreatedBy((int) $actingUser->id)) {
            throw new ForbiddenException(
                'You cannot approve or reject an order you created yourself.',
            );
        }

        return $order;
    }

    /**
     * Memvalidasi transisi lalu menyimpannya secara compare-and-set: status
     * baru hanya ditulis bila status tersimpan masih sama dengan yang dibaca.
     *
     * Tanpa syarat itu, cancel yang membaca order sebagai Approved dapat
     * menimpa order yang sementara itu sudah Fulfilled oleh goods issue —
     * stock sudah keluar, tetapi order tercatat Cancelled.
     *
     * @throws DomainException
     */
    private function transition(SalesOrder $order, SalesOrderStatus $target): void
    {
        $this->assertCanTransition($order, $target);

        if (!$this->orders->updateStatus((int) $order->id, $order->status, $target)) {
            throw self::changedMeanwhile();
        }
    }

    /** Warehouse Staff tidak pernah membuat Sales Order, jadi tidak pernah pembuatnya. */
    private function mayEdit(SalesOrder $order, User $actingUser): bool
    {
        return !$actingUser->isWarehouseStaff() && $order->isCreatedBy((int) $actingUser->id);
    }

    private static function onlyDraftEditable(): DomainException
    {
        return new DomainException('Only a draft order can be edited.');
    }

    private static function changedMeanwhile(): DomainException
    {
        return new DomainException(
            'This order was changed by someone else. Reload the page and try again.',
        );
    }

    /** @throws DomainException */
    private function assertCanTransition(SalesOrder $order, SalesOrderStatus $target): void
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
     * Order number unik, berpola SO-YYYYMMDD-#### (research R-009).
     *
     * Keunikan tetap dijaga UNIQUE constraint di database; loop ini hanya
     * menghindari tabrakan yang terduga agar user tidak melihat error.
     */
    private function nextOrderNumber(): string
    {
        $prefix = 'SO-' . $this->clock->now()->format('Ymd') . '-';

        for ($attempt = 1; $attempt <= self::ORDER_NUMBER_ATTEMPTS; $attempt++) {
            $candidate = $prefix . str_pad((string) $attempt, 4, '0', STR_PAD_LEFT);

            if (!$this->orders->orderNumberExists($candidate)) {
                return $candidate;
            }
        }

        // Di luar rentang yang terduga, empat digit acak lebih baik daripada
        // menggagalkan order yang sah.
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
     * ter-hidrasi.
     *
     * Harga jual di-snapshot dari katalog, bukan diambil dari payload: harga
     * tidak boleh ditentukan oleh pengirim request.
     *
     * @param array<string, mixed> $data
     * @return list<SalesOrderItem>
     *
     * @throws \App\Support\Exception\ValidationException
     */
    private function validate(array $data): array
    {
        $validator = Validator::make($data)
            ->required('customer_id', 'Customer')
            ->existsById('customer_id', 'Customer', fn (int $id): bool => $this->customers->exists($id))
            ->required('warehouse_id', 'Source warehouse')
            ->existsById(
                'warehouse_id',
                'Source warehouse',
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

        // Order tanpa satu pun line tidak punya arti (FR-016).
        $validator->rule(
            'items',
            $items !== [],
            'Add at least one product line to the order.',
        );

        $validator->validate();

        return $items;
    }

    /**
     * @param array<string, mixed> $rawItem
     */
    private function validateLine(Validator $validator, array $rawItem, int $index): ?SalesOrderItem
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

        return new SalesOrderItem(
            null,
            null,
            $productId,
            $quantity,
            // Harga di-snapshot dari katalog saat order dibuat.
            $product->sellingPrice,
        );
    }
}
