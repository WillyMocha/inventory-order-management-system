<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Enum\Role;
use App\Service\AuthService;
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
 * Manajemen user (USR-01) - Admin saja.
 *
 * Pembatasan role ditegakkan route table plus Authorization guard sebelum
 * request sampai ke sini; controller tidak mengulang pemeriksaan itu. Yang
 * ditambahkan di sini adalah step-up re-auth untuk perubahan password
 * (security standard §7).
 */
final class UserController
{
    public function __construct(
        private readonly View $view,
        private readonly UserService $userService,
        private readonly AuthService $authService,
        private readonly Session $session,
        private readonly Csrf $csrf,
    ) {
    }

    public function index(Request $request): Response
    {
        $criteria = $this->criteriaFrom($request);
        $total = $this->userService->count($criteria);

        $paginator = new Paginator($total, $request->queryInt('page', 1), $this->queryState($request));
        $users = $this->userService->search($criteria, $paginator->perPage(), $paginator->offset());

        return Response::html($this->view->render('users/index', [
            'title'      => 'Users',
            'activeNav'  => 'users',
            'users'      => $users,
            'paginator'  => $paginator,
            'basePath'   => '/users',
            'filters'    => $this->queryState($request),
            'hasFilters' => $this->queryState($request) !== [],
            'roles'      => Role::cases(),
            'counts'     => $this->roleCounts(),
        ]));
    }

    public function create(Request $request): Response
    {
        return Response::html($this->renderForm());
    }

    public function store(Request $request): Response
    {
        try {
            $this->userService->create($request->bodyAll());
        } catch (ValidationException $e) {
            return Response::html($this->renderForm(null, $request->bodyAll(), $e->errors()), 422);
        }

        $this->session->flash('success', 'User created.');

        return Response::redirect('/users');
    }

    public function edit(Request $request): Response
    {
        $user = $this->userService->requireUser($this->requireId($request));

        return Response::html($this->renderForm($user));
    }

    public function update(Request $request): Response
    {
        $id = $this->requireId($request);
        $user = $this->userService->requireUser($id);

        try {
            $this->userService->update($id, $request->bodyAll());
        } catch (ValidationException $e) {
            return Response::html($this->renderForm($user, $request->bodyAll(), $e->errors()), 422);
        }

        $this->session->flash('success', 'User updated.');

        return Response::redirect('/users');
    }

    public function toggleActive(Request $request): Response
    {
        $id = $this->requireId($request);
        $actor = $this->userService->requireUser($this->currentUserId());

        try {
            $this->userService->toggleActive($actor, $id);
        } catch (DomainException $e) {
            $this->session->flash('error', $e->getMessage());

            return Response::redirect('/users');
        }

        $this->session->flash('success', 'User status updated.');

        return Response::redirect('/users');
    }

    /**
     * Perubahan password memerlukan step-up re-auth: Admin wajib memasukkan
     * ulang password MILIKNYA SENDIRI (security standard §7).
     */
    public function changePassword(Request $request): Response
    {
        $id = $this->requireId($request);
        $target = $this->userService->requireUser($id);
        $actor = $this->userService->requireUser($this->currentUserId());

        if (!$this->authService->verifyPasswordFor($actor, $request->input('current_password'))) {
            return Response::html(
                $this->renderForm(
                    $target,
                    [],
                    ['current_password' => 'Your own password is incorrect.'],
                ),
                403,
            );
        }

        try {
            $this->userService->changePassword($id, $request->input('new_password'));
        } catch (ValidationException $e) {
            return Response::html($this->renderForm($target, [], $e->errors()), 422);
        }

        $this->session->flash('success', 'Password updated.');

        return Response::redirect('/users');
    }

    /**
     * @param array<string, mixed> $old
     * @param array<string, string> $errors
     */
    private function renderForm(?object $user = null, array $old = [], array $errors = []): string
    {
        return $this->view->render('users/form', [
            'title'     => $user === null ? 'Create user' : 'Edit user',
            'activeNav' => 'users',
            'user'      => $user,
            'old'       => $old,
            'errors'    => $errors,
            'roles'     => Role::cases(),
            'csrf'      => $this->csrf,
        ]);
    }

    /** @return array{search?: string, role?: string} */
    private function criteriaFrom(Request $request): array
    {
        $criteria = [];

        if ($request->queryString('search') !== '') {
            $criteria['search'] = $request->queryString('search');
        }

        if (Role::tryFrom($request->queryString('role')) !== null) {
            $criteria['role'] = $request->queryString('role');
        }

        return $criteria;
    }

    /** @return array<string, string> filter aktif, dipertahankan pada link pagination */
    private function queryState(Request $request): array
    {
        $state = [];

        foreach (['search', 'role'] as $key) {
            if ($request->queryString($key) !== '') {
                $state[$key] = $request->queryString($key);
            }
        }

        return $state;
    }

    /**
     * Total dan jumlah aktif per role. Jumlah aktif dipakai sebagai indikator
     * pada stat tile - lebih berguna daripada mengulang angka totalnya.
     *
     * @return array<string, array{total: int, active: int}>
     */
    private function roleCounts(): array
    {
        $counts = [];

        foreach (Role::cases() as $role) {
            $counts[$role->value] = [
                'total'  => $this->userService->count(['role' => $role->value]),
                'active' => $this->userService->count(['role' => $role->value, 'active' => true]),
            ];
        }

        return $counts;
    }

    private function requireId(Request $request): int
    {
        $id = $request->routeParamInt('id');

        if ($id === null) {
            throw new NotFoundException();
        }

        return $id;
    }

    private function currentUserId(): int
    {
        $id = $this->session->userId();

        if ($id === null) {
            throw new UnauthenticatedException();
        }

        return $id;
    }
}
