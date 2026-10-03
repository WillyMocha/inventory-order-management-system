<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Enum\PurchaseOrderStatus;
use App\Entity\Enum\Role;
use App\Entity\Enum\SalesOrderStatus;
use App\Repository\ProductRepositoryInterface;
use App\Repository\PurchaseOrderRepositoryInterface;
use App\Repository\SalesOrderRepositoryInterface;

/**
 * Angka dashboard per role (DASH-01, FR-026).
 *
 * Satu aturan yang memegang seluruh class ini: TIDAK ADA angka yang ditulis
 * tangan. Setiap figure keluar dari method aggregation pada repository —
 * COUNT, SUM, GROUP BY — sehingga mengubah data pasti menggeser angkanya.
 * FR-026 meminta persis itu: "derived from recorded data rather than fixed
 * values".
 *
 * Acting user di-pass sebagai argument, tidak pernah dibaca dari session.
 * Itulah yang membuat scoping Sales — hanya order miliknya — dapat di-unit-
 * test tanpa session sama sekali.
 *
 * Service ini hanya membaca. Tidak ada satu pun jalur tulis di sini, dan
 * stock tidak pernah disentuh selain lewat StockService (ARCH-02).
 */
final class DashboardService
{
    /**
     * Panjang antrean yang ditampilkan di dashboard. Angka RINGKASANNYA tetap
     * dihitung terpisah lewat COUNT, jadi memperpendek daftar tidak pernah
     * mengecilkan angkanya.
     */
    public const int QUEUE_LIMIT = 5;

    public function __construct(
        private readonly ProductRepositoryInterface $products,
        private readonly SalesOrderRepositoryInterface $salesOrders,
        private readonly PurchaseOrderRepositoryInterface $purchaseOrders,
    ) {
    }

    /**
     * Dispatch per role sesuai §1.2. Tiap role mendapat kumpulan figure yang
     * berbeda — bukan satu kumpulan besar yang sebagiannya disembunyikan di
     * view, karena menyembunyikan di view bukan pembatasan.
     *
     * @return array<string, mixed>
     */
    public function forRole(Role $role, int $userId): array
    {
        return match ($role) {
            Role::Admin => $this->adminFigures(),
            Role::Sales => $this->salesFigures($userId),
            Role::WarehouseStaff => $this->warehouseFigures(),
        };
    }

    /**
     * Admin melihat seluruh data: nilai inventori, product di bawah reorder
     * point, dan order per status (FR-026).
     *
     * @return array{
     *     inventoryValue: string,
     *     belowReorderPoint: int,
     *     salesOrdersByStatus: array<string, int>,
     *     purchaseOrdersByStatus: array<string, int>,
     *     pendingApproval: int,
     *     lowStock: list<array{product: \App\Entity\Product, totalQuantity: int}>
     * }
     */
    public function adminFigures(): array
    {
        $salesByStatus = $this->salesOrderTally(null);

        return [
            // Dasar nilainya adalah harga BELI (spec A-007).
            'inventoryValue'         => $this->products->totalInventoryValue(),
            'belowReorderPoint'      => $this->products->countBy(['lowStock' => true, 'active' => true]),
            'salesOrdersByStatus'    => $salesByStatus,
            'purchaseOrdersByStatus' => $this->purchaseOrderTally(),
            'pendingApproval'        => $salesByStatus[SalesOrderStatus::PendingApproval->value],
            'lowStock'               => $this->products->lowStock(self::QUEUE_LIMIT),
        ];
    }

    /**
     * Sales melihat ringkasan order MILIKNYA SENDIRI (FR-026, §1.2).
     *
     * Pembatasannya terjadi di dalam WHERE clause repository, bukan dengan
     * menyaring hasil setelah seluruh order terbaca — sejalan dengan security
     * standard §2: data di luar scope tidak pernah ikut terambil.
     *
     * @return array{
     *     ordersByStatus: array<string, int>,
     *     draft: int,
     *     pendingApproval: int,
     *     approved: int,
     *     fulfilled: int,
     *     cancelled: int,
     *     total: int
     * }
     */
    public function salesFigures(int $userId): array
    {
        $byStatus = $this->salesOrderTally($userId);

        return [
            'ordersByStatus'  => $byStatus,
            'draft'           => $byStatus[SalesOrderStatus::Draft->value],
            'pendingApproval' => $byStatus[SalesOrderStatus::PendingApproval->value],
            'approved'        => $byStatus[SalesOrderStatus::Approved->value],
            'fulfilled'       => $byStatus[SalesOrderStatus::Fulfilled->value],
            'cancelled'       => $byStatus[SalesOrderStatus::Cancelled->value],
            'total'           => array_sum($byStatus),
        ];
    }

    /**
     * Warehouse Staff melihat antrean kerjanya: apa yang menunggu diterima,
     * apa yang menunggu dikeluarkan, dan product apa yang menipis (FR-026).
     *
     * @return array{
     *     receiptQueueCount: int,
     *     issueQueueCount: int,
     *     lowStockCount: int,
     *     awaitingReceipt: list<\App\Entity\PurchaseOrder>,
     *     awaitingIssue: list<\App\Entity\SalesOrder>,
     *     lowStock: list<array{product: \App\Entity\Product, totalQuantity: int}>
     * }
     */
    public function warehouseFigures(): array
    {
        $purchaseByStatus = $this->purchaseOrderTally();
        $salesByStatus = $this->salesOrderTally(null);

        return [
            // Antrean penerimaan mencakup Ordered DAN PartiallyReceived:
            // sisa yang belum diterima tetap pekerjaan yang menunggu (PO-01).
            'receiptQueueCount' => $purchaseByStatus[PurchaseOrderStatus::Ordered->value]
                + $purchaseByStatus[PurchaseOrderStatus::PartiallyReceived->value],
            'issueQueueCount'   => $salesByStatus[SalesOrderStatus::Approved->value],
            'lowStockCount'     => $this->products->countBy(['lowStock' => true, 'active' => true]),
            'awaitingReceipt'   => $this->purchaseOrders->awaitingReceipt(self::QUEUE_LIMIT),
            'awaitingIssue'     => $this->salesOrders->awaitingIssue(self::QUEUE_LIMIT),
            'lowStock'          => $this->products->lowStock(self::QUEUE_LIMIT),
        ];
    }

    /**
     * @param int|null $createdBy bila diisi, hitungan dibatasi milik user itu
     * @return array<string, int>
     */
    private function salesOrderTally(?int $createdBy): array
    {
        return $this->zeroFilled(
            array_map(static fn (SalesOrderStatus $s): string => $s->value, SalesOrderStatus::cases()),
            $this->salesOrders->countByStatus($createdBy),
        );
    }

    /** @return array<string, int> */
    private function purchaseOrderTally(): array
    {
        return $this->zeroFilled(
            array_map(static fn (PurchaseOrderStatus $s): string => $s->value, PurchaseOrderStatus::cases()),
            $this->purchaseOrders->countByStatus(),
        );
    }

    /**
     * GROUP BY hanya mengembalikan status yang benar-benar punya baris. View
     * membutuhkan seluruh status agar kolomnya tidak hilang begitu datanya
     * kosong — status tanpa baris bernilai 0, bukan tidak ada.
     *
     * @param list<string>       $statuses
     * @param array<string, int> $counts
     * @return array<string, int>
     */
    private function zeroFilled(array $statuses, array $counts): array
    {
        $tally = [];

        foreach ($statuses as $status) {
            $tally[$status] = $counts[$status] ?? 0;
        }

        return $tally;
    }
}
