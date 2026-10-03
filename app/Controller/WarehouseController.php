<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Enum\Role;
use App\Service\MasterDataService;
use App\Support\Csrf;
use App\Support\Exception\NotFoundException;
use App\Support\Exception\ValidationException;
use App\Support\Request;
use App\Support\Response;
use App\Support\Session;
use App\Support\View;

/**
 * Warehouse (WH-01).
 *
 * Warehouse Staff boleh MEMBACA daftar warehouse; hanya Admin yang boleh
 * menulis. Pembatasan itu ada pada route table per aksi, bukan seragam.
 */
final class WarehouseController
{
    public function __construct(
        private readonly View $view,
        private readonly MasterDataService $masterData,
        private readonly Session $session,
        private readonly Csrf $csrf,
    ) {
    }

    public function index(Request $request): Response
    {
        return Response::html($this->view->render('warehouses/index', [
            'title'      => 'Warehouses',
            'activeNav'  => 'products',
            'warehouses' => $this->masterData->allWarehouses(),
            'canManage'  => $this->session->role() === Role::Admin,
            'csrf'       => $this->csrf,
        ]));
    }

    public function create(Request $request): Response
    {
        return Response::html($this->renderForm());
    }

    public function store(Request $request): Response
    {
        try {
            $this->masterData->createWarehouse($request->bodyAll());
        } catch (ValidationException $e) {
            return Response::html($this->renderForm(null, $request->bodyAll(), $e->errors()), 422);
        }

        $this->session->flash('success', 'Warehouse created.');

        return Response::redirect('/warehouses');
    }

    public function edit(Request $request): Response
    {
        $warehouse = $this->masterData->requireWarehouse($this->requireId($request));

        return Response::html($this->renderForm($warehouse));
    }

    public function update(Request $request): Response
    {
        $id = $this->requireId($request);
        $warehouse = $this->masterData->requireWarehouse($id);

        try {
            $this->masterData->updateWarehouse($id, $request->bodyAll());
        } catch (ValidationException $e) {
            return Response::html($this->renderForm($warehouse, $request->bodyAll(), $e->errors()), 422);
        }

        $this->session->flash('success', 'Warehouse updated.');

        return Response::redirect('/warehouses');
    }

    public function toggleActive(Request $request): Response
    {
        $this->masterData->toggleWarehouseActive($this->requireId($request));
        $this->session->flash('success', 'Warehouse status updated.');

        return Response::redirect('/warehouses');
    }

    /**
     * @param array<string, mixed> $old
     * @param array<string, string> $errors
     */
    private function renderForm(?object $warehouse = null, array $old = [], array $errors = []): string
    {
        return $this->view->render('warehouses/form', [
            'title'     => $warehouse === null ? 'Create warehouse' : 'Edit warehouse',
            'activeNav' => 'products',
            'warehouse' => $warehouse,
            'old'       => $old,
            'errors'    => $errors,
            'csrf'      => $this->csrf,
        ]);
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
