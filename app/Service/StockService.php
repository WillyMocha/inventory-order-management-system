<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Enum\PurchaseOrderStatus;
use App\Entity\Enum\ReferenceType;
use App\Entity\Enum\SalesOrderStatus;
use App\Entity\PurchaseOrder;
use App\Entity\PurchaseOrderItem;
use App\Entity\SalesOrder;
use App\Entity\StockLedger;
use App\Entity\User;
use App\Repository\ProductRepositoryInterface;
use App\Repository\ProductStockRepositoryInterface;
use App\Repository\PurchaseOrderRepositoryInterface;
use App\Repository\SalesOrderRepositoryInterface;
use App\Repository\StockLedgerRepositoryInterface;
use App\Repository\WarehouseRepositoryInterface;
use App\Support\Exception\DomainException;
use App\Support\Exception\ForbiddenException;
use App\Support\Exception\NotFoundException;
use App\Support\Exception\ValidationException;
use App\Support\TransactionRunner;
use App\Support\Validator;

/**
 * SATU-SATUNYA jalan quantity stock boleh berubah (ARCH-02).
 *
 * Setiap perubahan stock ditulis bersama baris stock_ledger-nya di dalam
 * transaction yang sama, sehingga invariant NFR-002 selalu berlaku:
 *
 *   SUM(stock_ledger.quantity) = product_stock.quantity
 *   untuk setiap pasangan (product, warehouse)
 *
 * Pencegahan oversell bekerja begini (research R-002):
 *   1. buka transaction;
 *   2. kunci baris product_stock dengan SELECT ... FOR UPDATE;
 *   3. baca ULANG quantity di bawah lock itu — bukan memakai angka yang
 *      dibaca sebelum transaction;
 *   4. verifikasi kecukupan;
 *   5. tulis ledger, kurangi stock, ubah status order;
 *   6. commit.
 *
 * Request kedua untuk baris yang sama menunggu di langkah 2 sampai request
 * pertama commit, lalu membaca quantity yang sudah benar dan ditolak bila tak
 * lagi cukup.
 *
 * Sebelum stock, baris ORDER-nya lebih dulu dikunci dan statusnya dibaca ulang
 * di bawah lock. Tanpa itu, dua request untuk order yang SAMA dapat sama-sama
 * melihat Approved, dan bila stock masih cukup order itu keluar dua kali.
 * Urutan lock selalu order -> product_stock, sehingga tidak membentuk siklus.
 *
 * Melepas transaction atau FOR UPDATE membuat oversell dapat direproduksi —
 * itu critical failure, bukan bug biasa.
 *
 * Lock diambil urut product_id lalu warehouse_id agar dua issue multi-line
 * tidak dapat saling deadlock dengan mengambil baris yang sama dalam urutan
 * berlawanan.
 */
final class StockService
{
    /** Panjang maksimum alasan koreksi, sama dengan kolom stock_ledger.note. */
    private const int NOTE_MAX_LENGTH = 255;

    /** Jumlah koreksi terbaru pada detail product; riwayat penuh lewat export CSV (spec A-007). */
    private const int RECENT_ADJUSTMENTS = 10;

    public function __construct(
        private readonly SalesOrderRepositoryInterface $salesOrders,
        private readonly PurchaseOrderRepositoryInterface $purchaseOrders,
        private readonly ProductStockRepositoryInterface $stocks,
        private readonly StockLedgerRepositoryInterface $ledger,
        private readonly ProductRepositoryInterface $products,
        private readonly WarehouseRepositoryInterface $warehouses,
        private readonly TransactionRunner $transactions,
    ) {
    }

    /**
     * Mengeluarkan barang untuk Sales Order yang sudah Approved (FR-019 s/d
     * FR-022).
     *
     * Bersifat all-or-nothing: bila satu line saja tidak mencukupi, seluruh
     * operasi ditolak dan tidak ada line lain yang dikurangi. Tidak ada
     * partial issue — sumber tidak menyediakannya untuk penjualan.
     *
     * @throws NotFoundException order tidak ada
     * @throws DomainException   order belum Approved, atau stock tidak cukup
     */
    public function issueGoods(int $orderId, User $actingUser): void
    {
        $this->transactions->transaction(function () use ($orderId, $actingUser): void {
            // Status diperiksa DI DALAM transaction, di bawah lock order.
            // Pembacaan di luar transaction bisa sudah basi saat lock didapat.
            $order = $this->salesOrders->lockForUpdate($orderId);

            if ($order === null) {
                throw new NotFoundException();
            }

            if (!$order->canIssueGoods()) {
                throw new DomainException(sprintf(
                    'Only an approved order can be issued. This order is %s.',
                    $order->status->label(),
                ));
            }

            $this->issueWithinTransaction($order, $actingUser);
        });
    }

    /**
     * Quantity tersedia untuk satu pasangan (product, warehouse).
     *
     * Dipakai form goods issue sebagai PANDUAN yang ditampilkan di samping
     * setiap line. Angka ini dibaca di luar transaction sehingga bisa saja
     * sudah berubah saat form dikirim — keputusan yang mengikat selalu
     * pembacaan di bawah lock di dalam issueGoods().
     */
    public function availableFor(int $productId, int $warehouseId): int
    {
        return $this->stocks->findFor($productId, $warehouseId)->quantity ?? 0;
    }

    /**
     * Menerima barang untuk Purchase Order (FR-014, FR-015, FR-022).
     *
     * Berbeda dari goods issue, receipt BOLEH sebagian: supplier memang dapat
     * mengirim bertahap, dan sisa yang belum datang tetap tercatat sebagai
     * outstanding (PO-01). Yang tidak boleh adalah menerima LEBIH dari yang
     * dipesan (spec A-005).
     *
     * Tetap all-or-nothing per pemanggilan: bila satu line melebihi
     * outstanding-nya, seluruh receipt ditolak dan tidak ada line lain yang
     * bertambah.
     *
     * @param array<int, int> $quantitiesByItemId purchase_order_item.id => quantity diterima
     *
     * @throws NotFoundException order tidak ada
     * @throws DomainException   status tidak menerima receipt, atau quantity tidak sah
     */
    public function receiveGoods(int $orderId, array $quantitiesByItemId, User $actingUser): void
    {
        $this->transactions->transaction(
            function () use ($orderId, $quantitiesByItemId, $actingUser): void {
                // Status DAN received_quantity dibaca ulang di bawah lock, agar
                // dua receipt bersamaan tidak sama-sama merencanakan dari
                // outstanding yang sudah basi.
                $order = $this->purchaseOrders->lockForUpdate($orderId);

                if ($order === null) {
                    throw new NotFoundException();
                }

                if (!$order->canReceiveGoods()) {
                    throw new DomainException(sprintf(
                        'Goods can only be received for an ordered or partially received order. This order is %s.',
                        $order->status->label(),
                    ));
                }

                $this->receiveWithinTransaction($order, $quantitiesByItemId, $actingUser);
            },
        );
    }

    /**
     * Koreksi stock dari hasil hitung fisik (spec 003, research R-004).
     *
     * User memasukkan quantity yang BENAR-BENAR dihitung; selisihnya terhadap
     * quantity sistem ditulis sebagai satu baris ledger Adjustment (reference
     * Manual, beserta alasannya) dan stock diubah di transaction yang sama.
     *
     * `$input` adalah body request mentah. Validasi format dilakukan DI SINI,
     * bukan di controller, agar "2.5" atau field kosong menjadi pesan field,
     * bukan angka yang diam-diam terpotong oleh cast.
     *
     * Hanya satu baris product_stock yang dikunci dan tidak ada baris order,
     * sehingga koreksi tidak dapat membentuk siklus lock dengan goods issue
     * (order -> product_stock) maupun goods receipt (ADR-002).
     *
     * @param array<string, mixed> $input warehouse_id, counted_quantity, expected_quantity, note
     * @return array{before: int, after: int, delta: int}
     *
     * @throws ForbiddenException  acting user bukan Admin maupun Warehouse Staff
     * @throws NotFoundException   product tidak ada
     * @throws ValidationException input tidak sah, quantity sudah berubah, atau selisih nol
     */
    public function adjustStock(int $productId, array $input, User $actor): array
    {
        // Pertahanan berlapis di belakang route table (constitution V).
        if (!$actor->isAdmin() && !$actor->isWarehouseStaff()) {
            throw new ForbiddenException('Only Admin and Warehouse Staff can adjust stock.');
        }

        $this->validateAdjustment($input);

        if ($this->products->findById($productId) === null) {
            throw new NotFoundException();
        }

        $warehouseId = (int) $input['warehouse_id'];
        $counted = (int) $input['counted_quantity'];
        $expected = (int) $input['expected_quantity'];
        $note = trim((string) $input['note']);

        return $this->transactions->transaction(
            fn (): array => $this->adjustWithinTransaction(
                $productId,
                $warehouseId,
                $counted,
                $expected,
                $note,
                $actor,
            ),
        );
    }

    /**
     * Koreksi stock terbaru untuk halaman detail product (spec 003 FR-009).
     *
     * Controller hanya memanggilnya untuk Admin dan Warehouse Staff (spec
     * A-009); riwayat ini menyebut nama staf dan alasannya.
     *
     * @return list<array{
     *     createdAt: string,
     *     warehouseName: string,
     *     quantity: int,
     *     balanceAfter: int,
     *     performedByName: string,
     *     note: string
     * }>
     */
    public function recentAdjustments(int $productId): array
    {
        return $this->ledger->recentAdjustmentsForProduct($productId, self::RECENT_ADJUSTMENTS);
    }

    /**
     * Riwayat pergerakan stock yang berasal dari satu Sales Order, untuk
     * ditampilkan pada halaman detail order (FR-023).
     *
     * @return list<StockLedger>
     */
    public function movementsForSalesOrder(int $orderId): array
    {
        return $this->ledger->forReference(ReferenceType::SalesOrder, $orderId);
    }

    /**
     * Riwayat pergerakan stock yang berasal dari satu Purchase Order (FR-023).
     *
     * @return list<StockLedger>
     */
    public function movementsForPurchaseOrder(int $orderId): array
    {
        return $this->ledger->forReference(ReferenceType::PurchaseOrder, $orderId);
    }

    /**
     * Urutan pengambilan lock untuk satu order.
     *
     * Line digabung per pasangan (product, warehouse) lalu diurutkan
     * product_id, kemudian warehouse_id. Penggabungan itu penting: dua line
     * untuk product yang sama harus diperiksa sebagai satu kebutuhan total,
     * kalau tidak masing-masing bisa lolos sendiri-sendiri dan hasilnya
     * oversell.
     *
     * Publik karena inilah aturan anti-deadlock yang perlu dapat diuji
     * langsung (research R-002).
     *
     * @return list<array{0: int, 1: int}> [productId, warehouseId]
     */
    public function lockOrderFor(SalesOrder $order): array
    {
        $pairs = array_map(
            static function (string $key): array {
                [$productId, $warehouseId] = explode(':', $key);

                return [(int) $productId, (int) $warehouseId];
            },
            array_keys($this->requiredQuantities($order)),
        );

        // Urut NUMERIK, bukan leksikografis: sebagai string "30" mendahului
        // "9", sehingga product_id 9 justru terkunci setelah 30 dan urutannya
        // tidak lagi sesuai yang didokumentasikan (research R-002).
        usort(
            $pairs,
            static fn (array $a, array $b): int => [$a[0], $a[1]] <=> [$b[0], $b[1]],
        );

        return $pairs;
    }

    /**
     * Inti operasinya, sudah berada di dalam transaction.
     *
     * Exception apa pun dari sini membuat transaction rollback, sehingga stock
     * dan ledger tidak pernah berubah sebagian.
     *
     * @throws DomainException
     */
    private function issueWithinTransaction(SalesOrder $order, User $actingUser): void
    {
        $required = $this->requiredQuantities($order);
        $orderId = (int) $order->id;

        // FASE 1 — kunci seluruh baris dan verifikasi kecukupannya.
        //
        // Seluruh verifikasi selesai SEBELUM satu pun penulisan dilakukan.
        // Dengan begitu penolakan pada line terakhir tidak meninggalkan line
        // pertama yang sudah berubah, bahkan seandainya rollback gagal.
        foreach ($this->lockOrderFor($order) as [$productId, $warehouseId]) {
            $quantity = $required[$productId . ':' . $warehouseId];

            // Kunci baris, lalu baca ulang quantity-nya di bawah lock ini.
            $stock = $this->stocks->lockForUpdate($productId, $warehouseId);
            // ?? juga menangani $stock yang null: operator itu bersemantik isset,
            // sehingga -> di sini tidak akan error untuk baris stock yang belum ada.
            $available = $stock->quantity ?? 0;

            if ($available < $quantity) {
                throw new DomainException($this->insufficientMessage(
                    $productId,
                    $quantity,
                    $available,
                ));
            }
        }

        // FASE 2 — tulis ledger dan kurangi stock.
        foreach ($this->lockOrderFor($order) as [$productId, $warehouseId]) {
            $quantity = $required[$productId . ':' . $warehouseId];

            // Ledger lebih dulu, lalu stock — keduanya di dalam transaction
            // yang sama, jadi keduanya ada atau keduanya tidak ada.
            $this->ledger->append(StockLedger::issue(
                $productId,
                $warehouseId,
                $quantity,
                $orderId,
                (int) $actingUser->id,
            ));

            $this->stocks->adjust($productId, $warehouseId, -$quantity);
        }

        // Order sudah dikunci, jadi compare-and-set ini hanya jaring pengaman.
        if (!$this->salesOrders->updateStatus($orderId, $order->status, SalesOrderStatus::Fulfilled)) {
            throw new DomainException('This order was changed by someone else. Reload the page and try again.');
        }
    }

    /**
     * Inti goods receipt, sudah berada di dalam transaction.
     *
     * Pola dua fase yang sama dengan issue: SELURUH verifikasi selesai sebelum
     * satu pun penulisan, sehingga penolakan pada line terakhir tidak
     * meninggalkan line pertama yang sudah bertambah.
     *
     * @param array<int, int> $quantitiesByItemId
     *
     * @throws DomainException
     */
    private function receiveWithinTransaction(
        PurchaseOrder $order,
        array $quantitiesByItemId,
        User $actingUser,
    ): void {
        $orderId = (int) $order->id;

        // FASE 1 — verifikasi setiap line terhadap outstanding-nya.
        $planned = $this->planReceipt($order, $quantitiesByItemId);

        // FASE 2 — kunci baris stock lalu tulis ledger dan tambah stock.
        //
        // Lock diambil meski receipt hanya menambah: urutan pengambilan yang
        // sama dengan issue (product_id lalu warehouse_id) mencegah deadlock
        // antara receipt dan issue yang menyentuh baris yang sama
        // (research R-002).
        foreach ($planned as $line) {
            $this->stocks->lockForUpdate($line['productId'], $order->warehouseId);
        }

        foreach ($planned as $line) {
            $this->ledger->append(StockLedger::receipt(
                $line['productId'],
                // Warehouse tujuan ada di header PO, bukan per line
                // (data-model.md).
                $order->warehouseId,
                $line['quantity'],
                $orderId,
                (int) $actingUser->id,
            ));

            $this->stocks->adjust($line['productId'], $order->warehouseId, $line['quantity']);

            // received_quantity naik lewat UPDATE ... SET x = x + n, dan
            // CHECK constraint received_quantity <= quantity menjadi jaring
            // pengaman terakhir bila dua receipt bersamaan lolos FASE 1.
            $this->purchaseOrders->addReceivedQuantity($line['itemId'], $line['quantity']);
        }

        $newStatus = $this->statusAfterReceipt($order, $planned);

        if (!$this->purchaseOrders->updateStatus($orderId, $order->status, $newStatus)) {
            throw new DomainException('This order was changed by someone else. Reload the page and try again.');
        }
    }

    /**
     * Memvalidasi permintaan receipt dan mengembalikannya dalam urutan lock
     * yang stabil.
     *
     * @param array<int, int> $quantitiesByItemId
     * @return list<array{itemId: int, productId: int, quantity: int}>
     *
     * @throws DomainException
     */
    private function planReceipt(PurchaseOrder $order, array $quantitiesByItemId): array
    {
        /** @var array<int, PurchaseOrderItem> $itemsById */
        $itemsById = [];
        foreach ($order->items as $item) {
            $itemsById[(int) $item->id] = $item;
        }

        $planned = [];

        foreach ($quantitiesByItemId as $itemId => $quantity) {
            $itemId = (int) $itemId;
            $quantity = (int) $quantity;

            // Baris yang dibiarkan kosong pada form bukan kesalahan — supplier
            // boleh mengirim sebagian line saja.
            if ($quantity === 0) {
                continue;
            }

            if ($quantity < 0) {
                throw new DomainException('A received quantity cannot be negative.');
            }

            // Item id harus benar-benar milik order ini. Tanpa pemeriksaan ini
            // item order lain dapat dipakai untuk menambah stock di sini.
            $item = $itemsById[$itemId] ?? null;

            if ($item === null) {
                throw new DomainException('That order line does not belong to this purchase order.');
            }

            if (!$item->canReceive($quantity)) {
                throw new DomainException($this->overReceiptMessage($item, $quantity));
            }

            $planned[] = [
                'itemId'    => $itemId,
                'productId' => $item->productId,
                'quantity'  => $quantity,
            ];
        }

        if ($planned === []) {
            throw new DomainException('Enter a quantity for at least one line to receive.');
        }

        // Urut numerik menurut product_id, sama seperti jalur issue.
        usort($planned, static fn (array $a, array $b): int => $a['productId'] <=> $b['productId']);

        return $planned;
    }

    /**
     * Status PO setelah receipt ini: Received bila tidak ada lagi outstanding,
     * PartiallyReceived bila masih ada (FR-014).
     *
     * Dihitung dari entity di memory ditambah rencana receipt, bukan dengan
     * membaca ulang order dari repository — pembacaan ulang di tengah
     * transaction tidak dijamin memuat perubahan yang belum commit pada setiap
     * implementasi repository.
     *
     * @param list<array{itemId: int, productId: int, quantity: int}> $planned
     */
    private function statusAfterReceipt(PurchaseOrder $order, array $planned): PurchaseOrderStatus
    {
        $receivedNow = [];
        foreach ($planned as $line) {
            $receivedNow[$line['itemId']] = ($receivedNow[$line['itemId']] ?? 0) + $line['quantity'];
        }

        foreach ($order->items as $item) {
            $outstanding = $item->outstandingQuantity() - ($receivedNow[(int) $item->id] ?? 0);

            if ($outstanding > 0) {
                return PurchaseOrderStatus::PartiallyReceived;
            }
        }

        return PurchaseOrderStatus::Received;
    }

    /** Pesan penolakan menyebut angkanya agar user tahu apa yang harus diubah. */
    private function overReceiptMessage(PurchaseOrderItem $item, int $requested): string
    {
        $name = $this->productLabel($item->productId);

        if ($requested < 1) {
            return sprintf('Received quantity for %s must be at least 1.', $name);
        }

        return sprintf(
            'Cannot receive %d of %s: only %d still outstanding.',
            $requested,
            $name,
            $item->outstandingQuantity(),
        );
    }

    /**
     * Aturan format koreksi stock. Warehouse harus ada dan aktif; product
     * nonaktif tetap boleh dikoreksi karena barang fisiknya mungkin masih ada
     * (spec A-006).
     *
     * @param array<string, mixed> $input
     *
     * @throws ValidationException
     */
    private function validateAdjustment(array $input): void
    {
        $warehouseId = $input['warehouse_id'] ?? null;
        $warehouse = is_string($warehouseId) && ctype_digit($warehouseId)
            ? $this->warehouses->findById((int) $warehouseId)
            : null;

        // Alasan dinilai SETELAH di-trim, sama dengan nilai yang disimpan.
        Validator::make(['note' => trim((string) ($input['note'] ?? ''))] + $input)
            ->required('warehouse_id', 'Warehouse')
            ->rule('warehouse_id', $warehouse !== null && $warehouse->isActive, 'Choose an active warehouse.')
            ->required('counted_quantity', 'Counted quantity')
            ->integerMin('counted_quantity', 'Counted quantity', 0)
            ->required('expected_quantity', 'System quantity')
            ->integerMin('expected_quantity', 'System quantity', 0)
            ->required('note', 'Reason')
            ->maxLength('note', 'Reason', self::NOTE_MAX_LENGTH)
            ->validate();
    }

    /**
     * Inti koreksi stock, sudah berada di dalam transaction.
     *
     * Baris dipastikan ada LEBIH DULU agar FOR UPDATE benar-benar mengunci satu
     * baris, juga untuk gudang yang belum pernah menyimpan product ini
     * (research R-002). Quantity dibaca ulang di bawah lock itu, lalu
     * dibandingkan dengan quantity yang dilihat user: bila berbeda, stock
     * berubah selama penghitungan dan koreksi ditolak, bukan diterapkan buta
     * (spec FR-004).
     *
     * @return array{before: int, after: int, delta: int}
     *
     * @throws ValidationException
     */
    private function adjustWithinTransaction(
        int $productId,
        int $warehouseId,
        int $counted,
        int $expected,
        string $note,
        User $actor,
    ): array {
        $this->stocks->ensureRow($productId, $warehouseId);
        $current = $this->stocks->lockForUpdate($productId, $warehouseId)->quantity ?? 0;

        if ($current !== $expected) {
            throw new ValidationException(['stock' => sprintf(
                'The stock in this warehouse changed to %d while you were counting. '
                . 'Check your count and submit again.',
                $current,
            )]);
        }

        $delta = $counted - $current;

        if ($delta === 0) {
            throw new ValidationException([
                'counted_quantity' => 'The count matches the system quantity — nothing to adjust.',
            ]);
        }

        // Ledger lebih dulu, lalu stock — keduanya ada atau keduanya tidak ada.
        $this->ledger->append(StockLedger::adjustment($productId, $warehouseId, $delta, $note, (int) $actor->id));
        $this->stocks->adjust($productId, $warehouseId, $delta);

        return ['before' => $current, 'after' => $counted, 'delta' => $delta];
    }

    /**
     * Kebutuhan quantity per pasangan (product, warehouse), digabung dari
     * seluruh line order.
     *
     * @return array<string, int> "productId:warehouseId" => quantity
     */
    private function requiredQuantities(SalesOrder $order): array
    {
        $required = [];

        foreach ($order->items as $item) {
            // Seluruh line satu Sales Order keluar dari warehouse asal yang
            // sama — warehouse ada di header order, bukan per line
            // (data-model.md).
            $key = $item->productId . ':' . $order->warehouseId;

            $required[$key] = ($required[$key] ?? 0) + $item->quantity;
        }

        return $required;
    }

    /**
     * Pesan penolakan menyebut angkanya, karena "not enough stock" tidak
     * memberi tahu user apa yang harus diperbaiki (FR-019).
     */
    private function insufficientMessage(int $productId, int $required, int $available): string
    {
        return sprintf(
            'Not enough stock for %s: %d requested, %d available.',
            $this->productLabel($productId),
            $required,
            $available,
        );
    }

    /**
     * Nama product untuk pesan penolakan: "Nama (SKU)", atau id-nya bila
     * product sudah tidak ditemukan.
     */
    private function productLabel(int $productId): string
    {
        $product = $this->products->findById($productId);

        return $product === null
            ? 'product #' . $productId
            : $product->name . ' (' . $product->sku . ')';
    }
}
