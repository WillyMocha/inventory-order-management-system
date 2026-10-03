<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Product;
use App\Entity\User;
use App\Entity\Warehouse;
use App\Service\MasterDataService;
use App\Service\ProductService;
use App\Service\StockService;
use App\Service\UserService;
use App\Support\Csrf;
use App\Support\Exception\NotFoundException;
use App\Support\Exception\UnauthenticatedException;
use App\Support\Exception\ValidationException;
use App\Support\Request;
use App\Support\Response;
use App\Support\Session;
use App\Support\View;

/**
 * Koreksi stock dari hasil hitung fisik (spec 003) - Admin dan Warehouse Staff.
 *
 * Pembatasan role ditegakkan route table plus guard, dan diulang di
 * StockService::adjustStock(). Controller ini tidak memvalidasi maupun meng-cast
 * quantity: body request diteruskan mentah ke Service, agar input seperti
 * "2.5" menjadi pesan field alih-alih angka yang terpotong (research R-004).
 * CSRF sudah diperiksa front controller untuk setiap POST (FR-011).
 */
final class StockAdjustmentController
{
    public function __construct(
        private readonly View $view,
        private readonly StockService $stockService,
        private readonly ProductService $productService,
        private readonly MasterDataService $masterData,
        private readonly UserService $users,
        private readonly Session $session,
        private readonly Csrf $csrf,
    ) {
    }

    public function create(Request $request): Response
    {
        $product = $this->productService->requireProduct($this->requireId($request));

        return Response::html($this->renderForm($product, $request->queryInt('warehouse_id')));
    }

    public function store(Request $request): Response
    {
        $product = $this->productService->requireProduct($this->requireId($request));
        $input = $request->bodyAll();

        try {
            $result = $this->stockService->adjustStock((int) $product->id, $input, $this->actingUser());
        } catch (ValidationException $e) {
            // Quantity sistem dibaca ULANG: bila penolakannya karena stock
            // berubah, hidden field kini membawa angka terbaru dan pengiriman
            // berikutnya adalah keputusan sadar user (research R-003).
            return Response::html(
                $this->renderForm($product, $request->inputInt('warehouse_id'), $input, $e->errors()),
                422,
            );
        }

        $warehouse = $this->masterData->requireWarehouse($request->inputInt('warehouse_id'));
        $this->session->flash('success', sprintf(
            'Stock adjusted in %s: %d → %d (%+d).',
            $warehouse->name,
            $result['before'],
            $result['after'],
            $result['delta'],
        ));

        return Response::redirect('/products/' . (int) $product->id);
    }

    /**
     * @param array<string, mixed> $old
     * @param array<string, string> $errors
     */
    private function renderForm(Product $product, int $warehouseId, array $old = [], array $errors = []): string
    {
        $warehouses = $this->masterData->activeWarehouses();
        $selected = $this->selectedWarehouse($warehouses, $warehouseId);

        return $this->view->render('stock-adjustments/form', [
            'title'          => 'Adjust stock',
            'activeNav'      => 'products',
            'product'        => $product,
            'warehouses'     => $warehouses,
            'selected'       => $selected,
            'systemQuantity' => $selected === null
                ? 0
                : $this->stockService->availableFor((int) $product->id, (int) $selected->id),
            'old'            => $old,
            'errors'         => $errors,
            'csrf'           => $this->csrf,
        ]);
    }

    /**
     * Warehouse yang diminta bila aktif; bila tidak, warehouse aktif pertama
     * menurut nama (contracts/http-routes.md).
     *
     * @param list<Warehouse> $warehouses
     */
    private function selectedWarehouse(array $warehouses, int $warehouseId): ?Warehouse
    {
        foreach ($warehouses as $warehouse) {
            if ($warehouse->id === $warehouseId) {
                return $warehouse;
            }
        }

        return $warehouses[0] ?? null;
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
