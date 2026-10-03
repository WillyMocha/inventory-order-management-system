<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Enum\Role;
use App\Service\DashboardService;
use App\Support\Exception\UnauthenticatedException;
use App\Support\Request;
use App\Support\Response;
use App\Support\Session;
use App\Support\View;

/**
 * Dashboard per role (DASH-01, FR-026, AUTH-01).
 *
 * Controller ini tidak menghitung apa pun. Ia membaca siapa yang sedang
 * masuk, meminta angkanya ke DashboardService, lalu memilih view. Seluruh
 * aturan — termasuk pembatasan Sales hanya melihat order miliknya — ada di
 * Service dan sudah teruji tanpa session.
 *
 * Tiga role mendarat di TIGA VIEW BERBEDA, bukan satu view yang sebagian
 * blok-nya disembunyikan: menyembunyikan blok di template bukan pembatasan
 * akses.
 */
final class DashboardController
{
    public function __construct(
        private readonly View $view,
        private readonly DashboardService $dashboard,
        private readonly Session $session,
    ) {
    }

    public function index(Request $request): Response
    {
        $role = $this->session->role();
        $userId = $this->session->userId();

        if ($role === null || $userId === null) {
            throw new UnauthenticatedException();
        }

        return Response::html($this->view->render(
            $this->templateFor($role),
            [
                'title'     => 'Dashboard',
                'activeNav' => 'dashboard',
                'roleLabel' => $role->label(),
                'userName'  => $this->session->userName() ?? '',
                'figures'   => $this->dashboard->forRole($role, $userId),
            ],
        ));
    }

    /** View per role, mengikuti pembagian region pada §1.2. */
    private function templateFor(Role $role): string
    {
        return match ($role) {
            Role::Admin => 'dashboard/admin',
            Role::Sales => 'dashboard/sales',
            Role::WarehouseStaff => 'dashboard/warehouse',
        };
    }
}
