<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Enum\Role;
use App\Entity\Enum\SalesOrderStatus;
use App\Entity\SalesOrder;
use App\Entity\User;
use App\Service\MasterDataService;
use App\Service\PartyService;
use App\Service\ProductService;
use App\Service\SalesOrderService;
use App\Service\StockService;
use App\Service\UserService;
use App\Support\Csrf;
use App\Support\Exception\DomainException;
use App\Support\Exception\NotFoundException;
use App\Support\Exception\UnauthenticatedException;
use App\Support\Exception\ValidationException;
use App\Support\Paginator;
use App\Support\Request;
use App\Support\Response;
use App\Support\Session;
use App\Support\View;

/**
 * Sales Order — draft, approval, dan goods issue (SO-01).
 *
 * Peran controller ini HANYA HTTP: membaca request, memanggil Service, memilih
 * view. Tidak ada aturan bisnis di sini. Secara khusus, aturan segregation of
 * duties tidak diputuskan di controller — controller hanya merender aksi yang
 * relevan, sementara yang MENOLAK adalah SalesOrderService. Menyembunyikan
 * tombol bukan kontrol akses (§1.2).
 *
 * Pembatasan role per action datang dari route table dan ditegakkan
 * Authorization guard sebelum request sampai ke sini:
 *   index/show            Admin, Sales, Warehouse Staff
 *   create/store/submit   Admin, Sales
 *   approve/reject        Admin saja
 *   issueForm/issue       Admin, Warehouse Staff
 */
final class SalesOrderController
{
    /** Key query string yang dibawa link pagination dan sort (FIND-01). */
    private const array FILTER_KEYS = ['search', 'status', 'sort', 'direction'];

    /**
     * Key sort yang boleh muncul di query string. Pemetaannya ke nama kolom
     * dilakukan allowlist SORTABLE pada repository — nilai ini tidak pernah
     * masuk ke SQL secara langsung (security standard §5).
     */
    private const array SORT_KEYS = ['date', 'number', 'status'];

    /** Prefix halaman detail; setiap aksi kembali ke sini setelah selesai. */
    private const string DETAIL_PATH = '/sales-orders/';

    public function __construct(
        private readonly View $view,
        private readonly SalesOrderService $salesOrders,
        private readonly StockService $stockService,
        private readonly ProductService $productService,
        private readonly PartyService $parties,
        private readonly MasterDataService $masterData,
        private readonly UserService $users,
        private readonly Session $session,
        private readonly Csrf $csrf,
    ) {
    }

    public function index(Request $request): Response
    {
        $actingUser = $this->actingUser();

        // Scoping kepemilikan Sales masuk ke criteria, sehingga pembatasannya
        // berada di WHERE clause query dan bukan filter setelah data terambil.
        $criteria = $this->criteriaFrom($request) + $this->salesOrders->scopeFor($actingUser);
        $filters = $request->queryState(self::FILTER_KEYS);

        // Sort dipisahkan dari filter: count() tidak peduli urutan.
        $sorted = $criteria + $request->sortCriteria(self::SORT_KEYS, 'desc');

        $paginator = new Paginator(
            $this->salesOrders->count($criteria),
            $request->queryInt('page', 1),
            $filters,
        );

        $orders = $this->salesOrders->search($sorted, $paginator->perPage(), $paginator->offset());

        return Response::html($this->view->render('sales-orders/index', [
            'title'         => 'Sales orders',
            'activeNav'     => 'sales-orders',
            'orders'        => $orders,
            'paginator'     => $paginator,
            'basePath'      => '/sales-orders',
            'filters'       => $filters,
            'hasFilters'    => $filters !== [],
            'statuses'      => SalesOrderStatus::cases(),
            'customerNames' => $this->customerNames($orders),
            'summary'       => $this->summary($actingUser),
            'canCreate'     => $actingUser->isAdmin() || $actingUser->isSales(),
            'scopedToSelf'  => $actingUser->role === Role::Sales,
        ]));
    }

    public function show(Request $request): Response
    {
        $actingUser = $this->actingUser();
        $order = $this->salesOrders->requireVisibleOrder($this->requireId($request), $actingUser);

        return Response::html($this->view->render('sales-orders/detail', $this->detailData($order, $actingUser)));
    }

    public function create(Request $request): Response
    {
        return Response::html($this->renderForm());
    }

    public function store(Request $request): Response
    {
        try {
            $id = $this->salesOrders->create($this->payloadFrom($request), $this->actingUser());
        } catch (ValidationException $e) {
            return Response::html($this->renderForm($this->payloadFrom($request), $e->errors()), 422);
        }

        $this->session->flash('success', 'Sales order created as a draft.');

        return Response::redirect(self::DETAIL_PATH . $id);
    }

    /**
     * Form edit Sales Order Draft (spec 004).
     *
     * Urutan pemeriksaannya sama dengan SalesOrderService::update() — terlihat
     * (404), boleh mengedit (403), baru status Draft — agar layar dan
     * penyimpanan selalu memberi jawaban yang sama (contracts "Check order").
     */
    public function edit(Request $request): Response
    {
        $actingUser = $this->actingUser();
        $order = $this->salesOrders->requireVisibleOrder($this->requireId($request), $actingUser);
        $this->salesOrders->assertMayEdit($order, $actingUser);

        if ($order->status !== SalesOrderStatus::Draft) {
            $this->session->flash('error', 'Only a draft order can be edited.');

            return Response::redirect(self::DETAIL_PATH . (int) $order->id);
        }

        return Response::html($this->renderForm($this->oldFromOrder($order), [], $order));
    }

    /**
     * Menyimpan edit. Seluruh aturan — pembuat saja, Draft saja, validasi —
     * ditegakkan SalesOrderService; CSRF sudah diperiksa front controller.
     */
    public function update(Request $request): Response
    {
        $actingUser = $this->actingUser();
        $id = $this->requireId($request);

        try {
            $this->salesOrders->update($id, $this->payloadFrom($request), $actingUser);
        } catch (ValidationException $e) {
            $order = $this->salesOrders->requireVisibleOrder($id, $actingUser);

            return Response::html($this->renderForm($this->payloadFrom($request), $e->errors(), $order), 422);
        } catch (DomainException $e) {
            $this->session->flash('error', $e->getMessage());

            return Response::redirect(self::DETAIL_PATH . $id);
        }

        $this->session->flash('success', 'Sales order updated.');

        return Response::redirect(self::DETAIL_PATH . $id);
    }

    public function submit(Request $request): Response
    {
        return $this->transition(
            $request,
            function (int $id, User $user): void {
                $this->salesOrders->submit($id, $user);
            },
            'Sales order submitted for approval.',
        );
    }

    public function cancel(Request $request): Response
    {
        return $this->transition(
            $request,
            function (int $id, User $user): void {
                $this->salesOrders->cancel($id, $user);
            },
            'Sales order cancelled.',
        );
    }

    /**
     * Approve — Admin saja per route table, dan SalesOrderService menolak
     * approver yang sama dengan pembuat order (FR-018).
     */
    public function approve(Request $request): Response
    {
        return $this->transition(
            $request,
            function (int $id, User $user): void {
                $this->salesOrders->approve($id, $user);
            },
            'Sales order approved.',
        );
    }

    public function reject(Request $request): Response
    {
        return $this->transition(
            $request,
            function (int $id, User $user): void {
                $this->salesOrders->reject($id, $user);
            },
            'Sales order rejected and cancelled.',
        );
    }

    /** Form goods issue, menampilkan stock tersedia di samping setiap line. */
    public function issueForm(Request $request): Response
    {
        $actingUser = $this->actingUser();
        $order = $this->salesOrders->requireVisibleOrder($this->requireId($request), $actingUser);

        if (!$order->canIssueGoods()) {
            $this->session->flash('error', 'Only an approved order can be issued.');

            return Response::redirect(self::DETAIL_PATH . (int) $order->id);
        }

        return Response::html($this->renderIssueForm($order));
    }

    /**
     * Goods issue. Seluruh pemeriksaan kecukupan stock berada di StockService
     * di dalam transaction, di bawah lock — controller hanya menyampaikan
     * hasilnya (FR-019 s/d FR-022).
     */
    public function issue(Request $request): Response
    {
        $actingUser = $this->actingUser();
        $order = $this->salesOrders->requireVisibleOrder($this->requireId($request), $actingUser);

        try {
            $this->stockService->issueGoods((int) $order->id, $actingUser);
        } catch (DomainException $e) {
            // Penolakan dijelaskan beserta angkanya, dan halamannya dirender
            // ulang dengan stock terkini — bukan redirect yang membuang
            // konteks (FR-019).
            return Response::html($this->renderIssueForm($order, $e->getMessage()), 422);
        }

        $this->session->flash('success', 'Goods issued. Stock and ledger updated.');

        return Response::redirect(self::DETAIL_PATH . (int) $order->id);
    }

    /**
     * Pola yang sama untuk seluruh perubahan status: jalankan, terjemahkan
     * penolakan aturan bisnis menjadi flash, lalu kembali ke detail order.
     *
     * @param callable(int, User): void $action
     */
    private function transition(Request $request, callable $action, string $successMessage): Response
    {
        $id = $this->requireId($request);
        $actingUser = $this->actingUser();

        try {
            $action($id, $actingUser);
        } catch (DomainException $e) {
            $this->session->flash('error', $e->getMessage());

            return Response::redirect(self::DETAIL_PATH . $id);
        }

        $this->session->flash('success', $successMessage);

        return Response::redirect(self::DETAIL_PATH . $id);
    }

    /**
     * @return array<string, mixed>
     */
    private function detailData(SalesOrder $order, User $actingUser): array
    {
        $orderId = (int) $order->id;
        $lines = [];

        foreach ($order->items as $item) {
            $product = $this->productService->requireProduct($item->productId);

            $lines[] = [
                'product'   => $product,
                'quantity'  => $item->quantity,
                'unitPrice' => $item->sellingPrice,
                'lineTotal' => $this->lineTotal($item->quantity, $item->sellingPrice),
            ];
        }

        return [
            'title'        => $order->orderNumber,
            'activeNav'    => 'sales-orders',
            'order'        => $order,
            'lines'        => $lines,
            'orderTotal'   => $this->orderTotal($order),
            'customer'     => $this->parties->requireCustomer($order->customerId),
            'warehouse'    => $this->masterData->requireWarehouse($order->warehouseId),
            'creator'      => $this->users->requireUser($order->createdBy),
            'approver'     => $order->approvedBy === null ? null : $this->users->requireUser($order->approvedBy),
            'movements'    => $this->stockService->movementsForSalesOrder($orderId),
            // Aksi yang DIRENDER. Yang MENOLAK tetap Service — ini hanya agar
            // user tidak ditawari aksi yang pasti gagal.
            'canEdit'      => $this->salesOrders->canEdit($order, $actingUser),
            'canSubmit'    => $order->status === SalesOrderStatus::Draft
                && ($actingUser->isAdmin() || $actingUser->isSales()),
            'canDecide'    => $order->status === SalesOrderStatus::PendingApproval
                && $actingUser->isAdmin()
                && !$order->isCreatedBy((int) $actingUser->id),
            'ownOrderAwaitingApproval' => $order->status === SalesOrderStatus::PendingApproval
                && $order->isCreatedBy((int) $actingUser->id),
            'canIssue'     => $order->canIssueGoods()
                && ($actingUser->isAdmin() || $actingUser->isWarehouseStaff()),
            'canCancel'    => $order->canTransitionTo(SalesOrderStatus::Cancelled)
                && ($actingUser->isAdmin() || $actingUser->isSales()),
            'csrf'         => $this->csrf,
        ];
    }

    private function renderIssueForm(SalesOrder $order, ?string $error = null): string
    {
        $lines = [];

        foreach ($order->items as $item) {
            $lines[] = [
                'product'   => $this->productService->requireProduct($item->productId),
                'quantity'  => $item->quantity,
                'available' => $this->stockService->availableFor($item->productId, $order->warehouseId),
            ];
        }

        return $this->view->render('sales-orders/issue', [
            'title'     => 'Issue goods — ' . $order->orderNumber,
            'activeNav' => 'sales-orders',
            'order'     => $order,
            'lines'     => $lines,
            'warehouse' => $this->masterData->requireWarehouse($order->warehouseId),
            'customer'  => $this->parties->requireCustomer($order->customerId),
            'error'     => $error,
            'csrf'      => $this->csrf,
        ]);
    }

    /**
     * Form yang sama melayani create dan edit; $order terisi berarti mode edit.
     *
     * @param array<string, mixed> $old
     * @param array<string, string> $errors
     */
    private function renderForm(array $old = [], array $errors = [], ?SalesOrder $order = null): string
    {
        return $this->view->render('sales-orders/form', [
            'title'      => $order === null ? 'Create sales order' : 'Edit sales order ' . $order->orderNumber,
            'activeNav'  => 'sales-orders',
            'order'      => $order,
            'old'        => $old,
            'errors'     => $errors,
            'customers'  => $this->parties->activeCustomers(),
            'warehouses' => $this->masterData->activeWarehouses(),
            'products'   => $this->productService->activeCatalog(),
            'today'      => date('Y-m-d'),
            'csrf'       => $this->csrf,
        ]);
    }

    /**
     * Isi form edit dari order tersimpan, dalam bentuk yang sama dengan
     * payloadFrom() — sehingga form cukup memakai logika isi-ulang yang sudah
     * ada untuk create.
     *
     * @return array<string, mixed>
     */
    private function oldFromOrder(SalesOrder $order): array
    {
        $items = [];

        foreach ($order->items as $item) {
            $items[] = ['product_id' => (string) $item->productId, 'quantity' => (string) $item->quantity];
        }

        return [
            'customer_id'  => (string) $order->customerId,
            'warehouse_id' => (string) $order->warehouseId,
            'order_date'   => $order->orderDate,
            'items'        => $items,
        ];
    }

    /**
     * Menyusun payload order dari request.
     *
     * created_by sengaja TIDAK diambil dari body — Service mengambilnya dari
     * acting user (§1.2).
     *
     * @return array<string, mixed>
     */
    private function payloadFrom(Request $request): array
    {
        $body = $request->bodyAll();
        $rawItems = is_array($body['items'] ?? null) ? $body['items'] : [];

        $items = [];

        foreach ($rawItems as $rawItem) {
            if (!is_array($rawItem)) {
                continue;
            }

            $productId = trim((string) ($rawItem['product_id'] ?? ''));
            $quantity = trim((string) ($rawItem['quantity'] ?? ''));

            // Baris kosong dari form dinamis diabaikan, bukan dianggap error:
            // user menambah baris lalu membiarkannya kosong itu wajar.
            if ($productId === '' && $quantity === '') {
                continue;
            }

            $items[] = ['product_id' => $productId, 'quantity' => $quantity];
        }

        return [
            'customer_id'  => $request->input('customer_id'),
            'warehouse_id' => $request->input('warehouse_id'),
            'order_date'   => $request->input('order_date'),
            'items'        => $items,
        ];
    }

    /** @return array{total: int, pendingApproval: int, awaitingIssue: int} */
    private function summary(User $actingUser): array
    {
        $counts = $this->salesOrders->countByStatus($actingUser);

        return [
            'total'           => array_sum($counts),
            'pendingApproval' => $counts[SalesOrderStatus::PendingApproval->value] ?? 0,
            'awaitingIssue'   => $counts[SalesOrderStatus::Approved->value] ?? 0,
        ];
    }

    /**
     * Peta id => nama customer untuk baris yang benar-benar tampil, agar
     * tabel tidak perlu satu query per baris (maksimal 10 baris per halaman).
     *
     * Customer yang sudah dinonaktifkan tetap harus muncul pada order lama,
     * jadi yang diambil adalah customer milik order yang tampil — bukan hanya
     * yang aktif (FR-009).
     *
     * @param list<SalesOrder> $orders
     * @return array<int, string>
     */
    private function customerNames(array $orders): array
    {
        $names = [];

        foreach ($orders as $order) {
            if (array_key_exists($order->customerId, $names)) {
                continue;
            }

            $names[$order->customerId] = $this->parties->requireCustomer($order->customerId)->name;
        }

        return $names;
    }

    private function orderTotal(SalesOrder $order): string
    {
        $total = 0;

        foreach ($order->items as $item) {
            $total += $this->rupiah($item->sellingPrice) * $item->quantity;
        }

        return (string) $total;
    }

    /**
     * Total satu line, dalam rupiah satuan penuh.
     *
     * Dihitung sebagai INTEGER, bukan float dan bukan bcmath: bcmath tidak
     * dipasang di image (Dockerfile hanya memasang pdo_mysql), sehingga
     * memakainya akan fatal di container meskipun jalan di host. Rupiah tidak
     * memakai sen dalam praktik, jadi satuan penuh sudah tepat — konvensi yang
     * sama dipakai Support\Money (spec A-011).
     */
    private function lineTotal(int $quantity, string $unitPrice): string
    {
        return (string) ($this->rupiah($unitPrice) * $quantity);
    }

    /** DECIMAL(15,2) menjadi rupiah satuan penuh. */
    private function rupiah(string $amount): int
    {
        return (int) round((float) $amount);
    }

    /** @return array{search?: string, status?: string, sort?: string, direction?: string} */
    private function criteriaFrom(Request $request): array
    {
        $criteria = [];

        if ($request->queryString('search') !== '') {
            $criteria['search'] = $request->queryString('search');
        }

        $status = $request->queryString('status');
        if ($status !== '' && SalesOrderStatus::tryFrom($status) instanceof SalesOrderStatus) {
            $criteria['status'] = $status;
        }

        return $criteria;
    }

    private function actingUser(): User
    {
        $id = $this->session->userId();

        if ($id === null) {
            throw new UnauthenticatedException();
        }

        return $this->users->requireUser($id);
    }

    private function requireId(Request $request): int
    {
        $id = $request->routeParamInt('id');

        if ($id === null) {
            throw new NotFoundException();
        }

        return $id;
    }
}
