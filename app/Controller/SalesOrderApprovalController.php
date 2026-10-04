<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\User;
use App\Service\SalesOrderApprovalService;
use App\Service\UserService;
use App\Support\Exception\DomainException;
use App\Support\Exception\NotFoundException;
use App\Support\Exception\UnauthenticatedException;
use App\Support\Request;
use App\Support\Response;
use App\Support\Session;

/**
 * Approve dan reject Sales Order (FR-018) - Admin saja per route table.
 *
 * Dipisahkan dari SalesOrderController (tech-debt TD-11). Controller ini tidak
 * memutuskan apa pun: route table menolak non-Admin lebih dulu, dan
 * SalesOrderApprovalService menolak approver yang sama dengan pembuat order
 * (D-01). URL-nya tidak berubah (`/sales-orders/{id}/approve`, `/reject`).
 */
final class SalesOrderApprovalController
{
    /** Halaman detail order; setiap keputusan kembali ke sini. */
    private const string DETAIL_PATH = '/sales-orders/';

    public function __construct(
        private readonly SalesOrderApprovalService $approvals,
        private readonly UserService $users,
        private readonly Session $session,
    ) {
    }

    public function approve(Request $request): Response
    {
        return $this->decide(
            $request,
            function (int $id, User $user): void {
                $this->approvals->approve($id, $user);
            },
            'Sales order approved.',
        );
    }

    public function reject(Request $request): Response
    {
        return $this->decide(
            $request,
            function (int $id, User $user): void {
                $this->approvals->reject($id, $user);
            },
            'Sales order rejected and cancelled.',
        );
    }

    /**
     * Penolakan bisnis (status sudah berubah, transisi tidak sah) kembali ke
     * detail dengan pesan. ForbiddenException dan NotFoundException sengaja
     * tidak ditangkap: front controller merendernya sebagai 403/404.
     *
     * @param callable(int, User): void $action
     */
    private function decide(Request $request, callable $action, string $successMessage): Response
    {
        $id = $this->requireId($request);

        try {
            $action($id, $this->actingUser());
        } catch (DomainException $e) {
            $this->session->flash('error', $e->getMessage());

            return Response::redirect(self::DETAIL_PATH . $id);
        }

        $this->session->flash('success', $successMessage);

        return Response::redirect(self::DETAIL_PATH . $id);
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
