<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Enum\PurchaseOrderStatus;
use App\Entity\PurchaseOrder;
use App\Entity\User;
use App\Service\MasterDataService;
use App\Service\PartyService;
use App\Service\ProductService;
use App\Service\PurchaseOrderService;
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
 * Purchase Order — draft, submit, cancel dan goods receipt (PO-01).
 *
 * HTTP saja: membaca request, memanggil Service, memilih view. Tidak ada
 * aturan bisnis di sini.
 *
 * Role per action TIDAK uniform, dan itu sesuai route table
 * (contracts/http-routes.md):
 *   index/create/store/show/submit   Admin, Warehouse Staff
 *   receiveForm/receive              Admin, Warehouse Staff
 *   cancel                           **Admin saja**
 * Sales tidak punya akses ke satu pun action di sini dan menerima 403 dari
 * Authorization guard sebelum request sampai ke kelas ini.
 */
final class PurchaseOrderController
{
    /** Key query string yang dibawa link pagination dan sort (FIND-01). */
    private const array FILTER_KEYS = ['search', 'status', 'sort', 'direction'];

    /**
     * Key sort yang boleh muncul di query string. Pemetaannya ke nama kolom
     * dilakukan allowlist SORTABLE pada repository — nilai ini tidak pernah
     * masuk ke SQL secara langsung (security standard §5).
     */
    private const array SORT_KEYS = ['date', 'number', 'status'];

    public function __construct(
        private readonly View $view,
        private readonly PurchaseOrderService $purchaseOrders,
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
        $criteria = $this->criteriaFrom($request);
        $filters = $request->queryState(self::FILTER_KEYS);

        // Sort dipisahkan dari filter: count() tidak peduli urutan.
        $sorted = $criteria + $request->sortCriteria(self::SORT_KEYS, 'desc');

        $paginator = new Paginator(
            $this->purchaseOrders->count($criteria),
            $request->queryInt('page', 1),
            $filters,
        );

        $orders = $this->purchaseOrders->search($sorted, $paginator->perPage(), $paginator->offset());

        return Response::html($this->view->render('purchase-orders/index', [
            'title'         => 'Purchase orders',
            'activeNav'     => 'purchase-orders',
            'orders'        => $orders,
            'paginator'     => $paginator,
            'basePath'      => '/purchase-orders',
            'filters'       => $filters,
            'hasFilters'    => $filters !== [],
            'statuses'      => PurchaseOrderStatus::cases(),
            'supplierNames' => $this->supplierNames($orders),
            'summary'       => $this->summary(),
        ]));
    }

    public function show(Request $request): Response
    {
        $order = $this->purchaseOrders->requireOrder($this->requireId($request));

        return Response::html(
            $this->view->render('purchase-orders/detail', $this->detailData($order, $this->actingUser())),
        );
    }

    public function create(Request $request): Response
    {
        return Response::html($this->renderForm());
    }

    public function store(Request $request): Response
    {
        try {
            $id = $this->purchaseOrders->create($this->payloadFrom($request), $this->actingUser());
        } catch (ValidationException $e) {
            return Response::html($this->renderForm($this->payloadFrom($request), $e->errors()), 422);
        }

        $this->session->flash('success', 'Purchase order created as a draft.');

        return Response::redirect('/purchase-orders/' . $id);
    }

    public function submit(Request $request): Response
    {
        return $this->transition(
            $request,
            function (int $id, User $user): void {
                $this->purchaseOrders->submit($id, $user);
            },
            'Purchase order submitted to the supplier.',
        );
    }

    /** Cancel adalah satu-satunya action di sini yang Admin-only. */
    public function cancel(Request $request): Response
    {
        return $this->transition(
            $request,
            function (int $id, User $user): void {
                $this->purchaseOrders->cancel($id, $user);
            },
            'Purchase order cancelled.',
        );
    }

    /**
     * Form goods receipt — setiap line menampilkan outstanding di samping
     * input-nya (FR-014).
     */
    public function receiveForm(Request $request): Response
    {
        $order = $this->purchaseOrders->requireOrder($this->requireId($request));

        if (!$order->canReceiveGoods()) {
            $this->session->flash(
                'error',
                'Goods can only be received for an ordered or partially received order.',
            );

            return Response::redirect('/purchase-orders/' . (int) $order->id);
        }

        return Response::html($this->renderReceiveForm($order));
    }

    /**
     * Goods receipt. Validasi terhadap outstanding dan penulisan ledger
     * berada di StockService di dalam satu transaction (FR-014, FR-015,
     * FR-022).
     */
    public function receive(Request $request): Response
    {
        $order = $this->purchaseOrders->requireOrder($this->requireId($request));
        $quantities = $this->receivedQuantitiesFrom($request);

        try {
            $this->stockService->receiveGoods((int) $order->id, $quantities, $this->actingUser());
        } catch (DomainException $e) {
            // Nilai yang diisi user dipertahankan, dan penolakannya dijelaskan
            // beserta angkanya — bukan redirect yang membuang konteks.
            return Response::html(
                $this->renderReceiveForm(
                    $this->purchaseOrders->requireOrder((int) $order->id),
                    $e->getMessage(),
                    $quantities,
                ),
                422,
            );
        }

        $this->session->flash('success', 'Goods received. Stock and ledger updated.');

        return Response::redirect('/purchase-orders/' . (int) $order->id);
    }

    /**
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

            return Response::redirect('/purchase-orders/' . $id);
        }

        $this->session->flash('success', $successMessage);

        return Response::redirect('/purchase-orders/' . $id);
    }

    /** @return array<string, mixed> */
    private function detailData(PurchaseOrder $order, User $actingUser): array
    {
        $orderId = (int) $order->id;
        $lines = [];

        foreach ($order->items as $item) {
            $lines[] = [
                'itemId'      => (int) $item->id,
                'product'     => $this->productService->requireProduct($item->productId),
                'quantity'    => $item->quantity,
                'received'    => $item->receivedQuantity,
                'outstanding' => $item->outstandingQuantity(),
                'unitPrice'   => $item->purchasePrice,
                'lineTotal'   => $this->lineTotal($item->quantity, $item->purchasePrice),
            ];
        }

        return [
            'title'       => $order->orderNumber,
            'activeNav'   => 'purchase-orders',
            'order'       => $order,
            'lines'       => $lines,
            'orderTotal'  => $this->orderTotal($order),
            'supplier'    => $this->parties->requireSupplier($order->supplierId),
            'warehouse'   => $this->masterData->requireWarehouse($order->warehouseId),
            'creator'     => $this->users->requireUser($order->createdBy),
            'movements'   => $this->stockService->movementsForPurchaseOrder($orderId),
            'canSubmit'   => $order->canTransitionTo(PurchaseOrderStatus::Ordered),
            'canReceive'  => $order->canReceiveGoods(),
            // Hanya Admin yang boleh membatalkan (route table).
            'canCancel'   => $order->canTransitionTo(PurchaseOrderStatus::Cancelled)
                && $actingUser->isAdmin(),
            'csrf'        => $this->csrf,
        ];
    }

    /** @param array<int, int> $submitted */
    private function renderReceiveForm(
        PurchaseOrder $order,
        ?string $error = null,
        array $submitted = [],
    ): string {
        $lines = [];

        foreach ($order->items as $item) {
            $lines[] = [
                'itemId'      => (int) $item->id,
                'product'     => $this->productService->requireProduct($item->productId),
                'quantity'    => $item->quantity,
                'received'    => $item->receivedQuantity,
                'outstanding' => $item->outstandingQuantity(),
                'submitted'   => $submitted[(int) $item->id] ?? null,
            ];
        }

        return $this->view->render('purchase-orders/receive', [
            'title'     => 'Receive goods — ' . $order->orderNumber,
            'activeNav' => 'purchase-orders',
            'order'     => $order,
            'lines'     => $lines,
            'supplier'  => $this->parties->requireSupplier($order->supplierId),
            'warehouse' => $this->masterData->requireWarehouse($order->warehouseId),
            'error'     => $error,
            'csrf'      => $this->csrf,
        ]);
    }

    /**
     * @param array<string, mixed> $old
     * @param array<string, string> $errors
     */
    private function renderForm(array $old = [], array $errors = []): string
    {
        return $this->view->render('purchase-orders/form', [
            'title'      => 'Create purchase order',
            'activeNav'  => 'purchase-orders',
            'old'        => $old,
            'errors'     => $errors,
            'suppliers'  => $this->parties->activeSuppliers(),
            'warehouses' => $this->masterData->activeWarehouses(),
            'products'   => $this->productService->activeCatalog(),
            'today'      => date('Y-m-d'),
            'csrf'       => $this->csrf,
        ]);
    }

    /**
     * created_by sengaja tidak diambil dari body — Service mengambilnya dari
     * acting user.
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

            if ($productId === '' && $quantity === '') {
                continue;
            }

            $items[] = ['product_id' => $productId, 'quantity' => $quantity];
        }

        return [
            'supplier_id'  => $request->input('supplier_id'),
            'warehouse_id' => $request->input('warehouse_id'),
            'order_date'   => $request->input('order_date'),
            'items'        => $items,
        ];
    }

    /**
     * Quantity yang diterima, dikunci pada item id.
     *
     * Line yang dibiarkan kosong dihilangkan, bukan dikirim sebagai nol:
     * menerima sebagian line saja adalah hal yang wajar.
     *
     * @return array<int, int>
     */
    private function receivedQuantitiesFrom(Request $request): array
    {
        $body = $request->bodyAll();
        $raw = is_array($body['received'] ?? null) ? $body['received'] : [];

        $quantities = [];

        foreach ($raw as $itemId => $quantity) {
            $itemId = (int) $itemId;
            $value = trim((string) (is_scalar($quantity) ? $quantity : ''));

            if ($itemId <= 0 || $value === '') {
                continue;
            }

            $quantities[$itemId] = (int) $value;
        }

        return $quantities;
    }

    /** @return array{total: int, awaitingReceipt: int, draft: int} */
    private function summary(): array
    {
        $counts = $this->purchaseOrders->countByStatus();

        return [
            'total'           => array_sum($counts),
            'awaitingReceipt' => ($counts[PurchaseOrderStatus::Ordered->value] ?? 0)
                + ($counts[PurchaseOrderStatus::PartiallyReceived->value] ?? 0),
            'draft'           => $counts[PurchaseOrderStatus::Draft->value] ?? 0,
        ];
    }

    /**
     * Supplier yang sudah dinonaktifkan tetap harus muncul pada order lama,
     * jadi yang diambil adalah supplier milik order yang tampil (FR-009).
     *
     * @param list<PurchaseOrder> $orders
     * @return array<int, string>
     */
    private function supplierNames(array $orders): array
    {
        $names = [];

        foreach ($orders as $order) {
            if (array_key_exists($order->supplierId, $names)) {
                continue;
            }

            $names[$order->supplierId] = $this->parties->requireSupplier($order->supplierId)->name;
        }

        return $names;
    }

    private function orderTotal(PurchaseOrder $order): string
    {
        $total = 0;

        foreach ($order->items as $item) {
            $total += $this->rupiah($item->purchasePrice) * $item->quantity;
        }

        return (string) $total;
    }

    /**
     * Total line dalam rupiah satuan penuh, dihitung sebagai integer.
     *
     * bcmath tidak dipasang di image (Dockerfile hanya memasang pdo_mysql),
     * dan rupiah tidak memakai sen dalam praktik — konvensi yang sama dipakai
     * Support\Money (spec A-011).
     */
    private function lineTotal(int $quantity, string $unitPrice): string
    {
        return (string) ($this->rupiah($unitPrice) * $quantity);
    }

    private function rupiah(string $amount): int
    {
        return (int) round((float) $amount);
    }

    /** @return array{search?: string, status?: string} */
    private function criteriaFrom(Request $request): array
    {
        $criteria = [];

        if ($request->queryString('search') !== '') {
            $criteria['search'] = $request->queryString('search');
        }

        $status = $request->queryString('status');
        if ($status !== '' && PurchaseOrderStatus::tryFrom($status) instanceof PurchaseOrderStatus) {
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
