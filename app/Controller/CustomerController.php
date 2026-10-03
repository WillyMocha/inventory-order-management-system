<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Enum\Role;
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
 * Customer.
 *
 * Sales boleh MEMBACA daftar customer - dibutuhkan saat membuat Sales Order -
 * tetapi hanya Admin yang boleh menulis. Pembatasan itu per aksi pada route
 * table, tidak seragam.
 *
 * Tidak ada aksi delete: customer dinonaktifkan, bukan dihapus (§1.3).
 */
final class CustomerController
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
            $this->partyService->countCustomers($criteria),
            $request->queryInt('page', 1),
            $filters,
        );
        $customers = $this->partyService->searchCustomers($criteria, $paginator->perPage(), $paginator->offset());

        return Response::html($this->view->render('customers/index', [
            'title'      => 'Customers',
            'activeNav'  => 'customers',
            'customers'  => $customers,
            'paginator'  => $paginator,
            'basePath'   => '/customers',
            'filters'    => $filters,
            'hasFilters' => $filters !== [],
            'total'      => $this->partyService->countCustomers([]),
            'activeCount' => $this->partyService->countCustomers(['active' => true]),
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
            $this->partyService->createCustomer($request->bodyAll());
        } catch (ValidationException $e) {
            return Response::html($this->renderForm(null, $request->bodyAll(), $e->errors()), 422);
        }

        $this->session->flash('success', 'Customer created.');

        return Response::redirect('/customers');
    }

    public function edit(Request $request): Response
    {
        $customer = $this->partyService->requireCustomer($this->requireId($request));

        return Response::html($this->renderForm($customer));
    }

    public function update(Request $request): Response
    {
        $id = $this->requireId($request);
        $customer = $this->partyService->requireCustomer($id);

        try {
            $this->partyService->updateCustomer($id, $request->bodyAll());
        } catch (ValidationException $e) {
            return Response::html($this->renderForm($customer, $request->bodyAll(), $e->errors()), 422);
        }

        $this->session->flash('success', 'Customer updated.');

        return Response::redirect('/customers');
    }

    public function toggleActive(Request $request): Response
    {
        $this->partyService->toggleCustomerActive($this->requireId($request));
        $this->session->flash('success', 'Customer status updated.');

        return Response::redirect('/customers');
    }

    /**
     * @param array<string, mixed> $old
     * @param array<string, string> $errors
     */
    private function renderForm(?object $customer = null, array $old = [], array $errors = []): string
    {
        return $this->view->render('customers/form', [
            'title'     => $customer === null ? 'Create customer' : 'Edit customer',
            'activeNav' => 'customers',
            'party'     => $customer,
            'old'       => $old,
            'errors'    => $errors,
            'csrf'      => $this->csrf,
            'kind'      => 'customer',
            'basePath'  => '/customers',
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
