<?php

declare(strict_types=1);

namespace App\Controller;

use App\Service\PartyService;
use App\Support\Csrf;
use App\Support\Exception\NotFoundException;
use App\Support\Exception\ValidationException;
use App\Support\Paginator;
use App\Support\Request;
use App\Support\Response;
use App\Support\Session;
use App\Support\View;

/**
 * Supplier - Admin saja, ditegakkan route table.
 *
 * Tidak ada aksi delete: supplier dinonaktifkan, bukan dihapus (§1.3).
 */
final class SupplierController
{
    public function __construct(
        private readonly View $view,
        private readonly PartyService $partyService,
        private readonly Session $session,
        private readonly Csrf $csrf,
    ) {
    }

    public function index(Request $request): Response
    {
        $criteria = $this->criteriaFrom($request);
        $filters = $this->queryState($request);

        $paginator = new Paginator(
            $this->partyService->countSuppliers($criteria),
            $request->queryInt('page', 1),
            $filters,
        );
        $suppliers = $this->partyService->searchSuppliers($criteria, $paginator->perPage(), $paginator->offset());

        return Response::html($this->view->render('suppliers/index', [
            'title'      => 'Suppliers',
            'activeNav'  => 'suppliers',
            'suppliers'  => $suppliers,
            'paginator'  => $paginator,
            'basePath'   => '/suppliers',
            'filters'    => $filters,
            'hasFilters' => $filters !== [],
            'total'      => $this->partyService->countSuppliers([]),
            'activeCount' => $this->partyService->countSuppliers(['active' => true]),
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
            $this->partyService->createSupplier($request->bodyAll());
        } catch (ValidationException $e) {
            return Response::html($this->renderForm(null, $request->bodyAll(), $e->errors()), 422);
        }

        $this->session->flash('success', 'Supplier created.');

        return Response::redirect('/suppliers');
    }

    public function edit(Request $request): Response
    {
        $supplier = $this->partyService->requireSupplier($this->requireId($request));

        return Response::html($this->renderForm($supplier));
    }

    public function update(Request $request): Response
    {
        $id = $this->requireId($request);
        $supplier = $this->partyService->requireSupplier($id);

        try {
            $this->partyService->updateSupplier($id, $request->bodyAll());
        } catch (ValidationException $e) {
            return Response::html($this->renderForm($supplier, $request->bodyAll(), $e->errors()), 422);
        }

        $this->session->flash('success', 'Supplier updated.');

        return Response::redirect('/suppliers');
    }

    public function toggleActive(Request $request): Response
    {
        $this->partyService->toggleSupplierActive($this->requireId($request));
        $this->session->flash('success', 'Supplier status updated.');

        return Response::redirect('/suppliers');
    }

    /**
     * @param array<string, mixed> $old
     * @param array<string, string> $errors
     */
    private function renderForm(?object $supplier = null, array $old = [], array $errors = []): string
    {
        return $this->view->render('suppliers/form', [
            'title'     => $supplier === null ? 'Create supplier' : 'Edit supplier',
            'activeNav' => 'suppliers',
            'party'     => $supplier,
            'old'       => $old,
            'errors'    => $errors,
            'csrf'      => $this->csrf,
            'kind'      => 'supplier',
            'basePath'  => '/suppliers',
        ]);
    }

    /** @return array{search?: string, active?: bool} */
    private function criteriaFrom(Request $request): array
    {
        $criteria = [];

        if ($request->queryString('search') !== '') {
            $criteria['search'] = $request->queryString('search');
        }

        $status = $request->queryString('status');
        if ($status === 'active') {
            $criteria['active'] = true;
        } elseif ($status === 'inactive') {
            $criteria['active'] = false;
        }

        return $criteria;
    }

    /** @return array<string, string> */
    private function queryState(Request $request): array
    {
        $state = [];

        foreach (['search', 'status'] as $key) {
            if ($request->queryString($key) !== '') {
                $state[$key] = $request->queryString($key);
            }
        }

        return $state;
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
