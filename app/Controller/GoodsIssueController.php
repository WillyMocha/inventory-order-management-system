<?php

declare(strict_types=1);

namespace App\Controller;

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
use App\Support\Request;
use App\Support\Response;
use App\Support\Session;
use App\Support\View;

/**
 * Goods issue untuk Sales Order Approved (SO-01, FR-019) - Admin dan Warehouse Staff.
 *
 * Dipisahkan dari SalesOrderController (tech-debt TD-11): goods issue adalah
 * pergerakan stock, bukan pengelolaan order, sama seperti
 * StockAdjustmentController. URL-nya tidak berubah (`/sales-orders/{id}/issue`).
 *
 * Seluruh aturan - kecukupan stock, lock, ledger - ada di
 * StockService::issueGoods() dalam satu transaction (ARCH-02). CSRF diperiksa
 * front controller untuk setiap POST.
 */
final class GoodsIssueController
{
    /** Halaman detail order; tujuan kembali setelah issue. */
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

    public function issueForm(Request $request): Response
    {
        $order = $this->salesOrders->requireVisibleOrder($this->requireId($request), $this->actingUser());

        if (!$order->canIssueGoods()) {
            $this->session->flash('error', 'Only an approved order can be issued.');

            return Response::redirect(self::DETAIL_PATH . (int) $order->id);
        }

        return Response::html($this->renderForm($order));
    }

    public function issue(Request $request): Response
    {
        $actingUser = $this->actingUser();
        $order = $this->salesOrders->requireVisibleOrder($this->requireId($request), $actingUser);

        try {
            $this->stockService->issueGoods((int) $order->id, $actingUser);
        } catch (DomainException $e) {
            // Penolakan dijelaskan beserta angkanya, dan halamannya dirender
            // ulang dengan stock terkini - bukan redirect yang membuang
            // konteks (FR-019).
            return Response::html($this->renderForm($order, $e->getMessage()), 422);
        }

        $this->session->flash('success', 'Goods issued. Stock and ledger updated.');

        return Response::redirect(self::DETAIL_PATH . (int) $order->id);
    }

    private function renderForm(SalesOrder $order, ?string $error = null): string
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
