<?php

declare(strict_types=1);

namespace App\Controller;

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
use App\Support\Request;
use App\Support\Response;
use App\Support\Session;
use App\Support\View;

/**
 * Goods receipt untuk Purchase Order (PO-01, FR-014, FR-015) - Admin dan Warehouse Staff.
 *
 * Dipisahkan dari PurchaseOrderController (tech-debt TD-11): goods receipt
 * adalah pergerakan stock, bukan pengelolaan order, sama seperti
 * StockAdjustmentController. URL-nya tidak berubah (`/purchase-orders/{id}/receive`).
 *
 * Validasi terhadap outstanding dan penulisan ledger berada di
 * StockService::receiveGoods() dalam satu transaction (FR-022, ARCH-02). CSRF
 * diperiksa front controller untuk setiap POST.
 */
final class GoodsReceiptController
{
    /** Halaman detail order; tujuan kembali setelah receipt. */
    private const string DETAIL_PATH = '/purchase-orders/';

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

            return Response::redirect(self::DETAIL_PATH . (int) $order->id);
        }

        return Response::html($this->renderForm($order));
    }

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
                $this->renderForm(
                    $this->purchaseOrders->requireOrder((int) $order->id),
                    $e->getMessage(),
                    $quantities,
                ),
                422,
            );
        }

        $this->session->flash('success', 'Goods received. Stock and ledger updated.');

        return Response::redirect(self::DETAIL_PATH . (int) $order->id);
    }

    /** @param array<int, int> $submitted */
    private function renderForm(PurchaseOrder $order, ?string $error = null, array $submitted = []): string
    {
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
