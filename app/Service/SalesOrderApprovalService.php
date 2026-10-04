<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Enum\SalesOrderStatus;
use App\Entity\SalesOrder;
use App\Entity\User;
use App\Repository\SalesOrderRepositoryInterface;
use App\Support\ClockInterface;
use App\Support\Exception\DomainException;
use App\Support\Exception\ForbiddenException;
use App\Support\Exception\NotFoundException;

/**
 * Approve dan reject Sales Order — inti segregation of duties (FR-018, §1.2).
 *
 * Dipisahkan dari SalesOrderService (tech-debt TD-11) agar aturan paling dijaga
 * dalam sistem berada di satu class kecil yang mudah diaudit: hanya Admin, dan
 * approver tidak pernah sama dengan pembuat order — berlaku juga untuk Admin
 * (decisions D-01). Aturannya ditegakkan DI SINI, bukan dengan menyembunyikan
 * tombol (security standard §2).
 *
 * Visibilitas order memakai SalesOrderService::requireVisibleOrder() yang sama,
 * sehingga Sales yang menyentuh order orang lain tetap mendapat 404, bukan 403.
 * Acting user selalu di-pass sebagai argument, tidak pernah dibaca dari session.
 */
final class SalesOrderApprovalService
{
    private const string CHANGED_MEANWHILE = 'This order was changed by someone else. Reload the page and try again.';

    public function __construct(
        private readonly SalesOrderService $salesOrders,
        private readonly SalesOrderRepositoryInterface $orders,
        private readonly ClockInterface $clock,
    ) {
    }

    /**
     * Menyetujui order. DUA syarat harus terpenuhi bersamaan:
     *   1. role acting user adalah Admin; dan
     *   2. approved_by tidak sama dengan created_by.
     *
     * Syarat kedua berlaku untuk siapa pun termasuk Admin, sehingga seorang
     * Admin pun tidak dapat menyetujui order yang dibuatnya sendiri.
     *
     * @throws NotFoundException
     * @throws ForbiddenException
     * @throws DomainException
     */
    public function approve(int $id, User $actingUser): void
    {
        $order = $this->requireApprovableOrder($id, $actingUser);

        if (!$order->canTransitionTo(SalesOrderStatus::Approved)) {
            throw new DomainException(sprintf(
                'A %s order cannot become %s.',
                $order->status->label(),
                SalesOrderStatus::Approved->label(),
            ));
        }

        // markApproved() bersifat compare-and-set: hanya berlaku bila order
        // masih PendingApproval saat ditulis.
        $approved = $this->orders->markApproved(
            $id,
            (int) $actingUser->id,
            $this->clock->now()->format('Y-m-d H:i:s'),
        );

        if (!$approved) {
            throw new DomainException(self::CHANGED_MEANWHILE);
        }
    }

    /**
     * Menolak order. Sumber menyebut reject sebagai aksi Admin tanpa
     * menyediakan state Rejected tersendiri, sehingga order berakhir di
     * Cancelled (data-model.md). Syarat approver-nya sama dengan approve().
     *
     * @throws NotFoundException
     * @throws ForbiddenException
     * @throws DomainException
     */
    public function reject(int $id, User $actingUser): void
    {
        $order = $this->requireApprovableOrder($id, $actingUser);

        if ($order->status !== SalesOrderStatus::PendingApproval) {
            throw new DomainException('Only an order awaiting approval can be rejected.');
        }

        if (!$this->orders->updateStatus($id, SalesOrderStatus::PendingApproval, SalesOrderStatus::Cancelled)) {
            throw new DomainException(self::CHANGED_MEANWHILE);
        }
    }

    /**
     * Order yang memenuhi syarat approver, sebelum transisinya diperiksa.
     *
     * @throws NotFoundException
     * @throws ForbiddenException
     */
    private function requireApprovableOrder(int $id, User $actingUser): SalesOrder
    {
        $order = $this->salesOrders->requireVisibleOrder($id, $actingUser);

        // Syarat 1 — hanya Admin yang boleh menyetujui. Sales tidak pernah
        // boleh, apa pun order-nya.
        if (!$actingUser->isAdmin()) {
            throw new ForbiddenException('Only an Admin can approve or reject a sales order.');
        }

        // Syarat 2 — approver tidak boleh pembuat order itu sendiri.
        if ($order->isCreatedBy((int) $actingUser->id)) {
            throw new ForbiddenException('You cannot approve or reject an order you created yourself.');
        }

        return $order;
    }
}
