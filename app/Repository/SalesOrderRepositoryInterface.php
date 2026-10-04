<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Enum\SalesOrderStatus;
use App\Entity\SalesOrder;
use App\Entity\SalesOrderItem;

interface SalesOrderRepositoryInterface
{
    /** Memuat order beserta seluruh item-nya. */
    public function findById(int $id): ?SalesOrder;

    /**
     * Memuat order sambil mengunci baris header-nya (SELECT ... FOR UPDATE)
     * sampai transaction berjalan selesai.
     *
     * Dipakai goods issue agar status dibaca ulang di bawah lock: request
     * kedua untuk order yang sama menunggu, lalu melihat order yang sudah
     * Fulfilled dan ditolak (ARCH-02). Wajib dipanggil di dalam transaction.
     */
    public function lockForUpdate(int $id): ?SalesOrder;

    public function orderNumberExists(string $orderNumber): bool;

    /**
     * createdBy pada criteria adalah scoping kepemilikan untuk role Sales —
     * di-apply di dalam WHERE clause, bukan difilter setelah query (§1.2,
     * security standard §2).
     *
     * @param array{search?: string, status?: string, createdBy?: int, sort?: string, direction?: string} $criteria
     * @return list<SalesOrder>
     */
    public function search(array $criteria, int $limit, int $offset): array;

    /** @param array{search?: string, status?: string, createdBy?: int} $criteria */
    public function countBy(array $criteria): int;

    public function save(SalesOrder $order): int;

    /**
     * Menulis header order Draft yang sedang diedit: customer, warehouse asal,
     * dan tanggal order (spec 004, research R-003).
     *
     * Berlaku HANYA bila status tersimpan masih Draft (compare-and-set);
     * false bila order sudah keluar dari Draft. Nomor order, status, pembuat,
     * dan approver tidak pernah ditulis di sini. Line tidak disentuh — lihat
     * replaceItems().
     */
    public function updateDraft(SalesOrder $order): bool;

    /**
     * Mengganti seluruh line sebuah order dengan $items (research R-004).
     *
     * Aman hanya untuk order Draft: belum ada ledger dan tidak ada yang
     * mereferensikan id line-nya. Wajib dipanggil di dalam transaction milik
     * pemanggil, setelah updateDraft() berhasil, agar header dan line
     * tersimpan bersama atau tidak sama sekali.
     *
     * @param list<SalesOrderItem> $items
     */
    public function replaceItems(int $orderId, array $items): void;

    /**
     * Mengubah status HANYA bila status tersimpan masih $expected
     * (compare-and-set).
     *
     * Mengembalikan false bila order sudah diubah request lain sejak dibaca —
     * misalnya cancel yang datang setelah order terlanjur Fulfilled. Tanpa
     * syarat ini, request yang membaca status lama akan menimpa status baru.
     */
    public function updateStatus(int $id, SalesOrderStatus $expected, SalesOrderStatus $status): bool;

    /**
     * Mencatat siapa yang menyetujui dan kapan. Berlaku hanya bila order masih
     * PendingApproval; false bila sudah diubah request lain.
     */
    public function markApproved(int $id, int $approvedBy, string $approvedAt): bool;

    /**
     * @param int|null $createdBy bila diisi, hitungan dibatasi milik user itu
     * @return array<string, int> status => jumlah
     */
    public function countByStatus(?int $createdBy = null): array;

    /**
     * Order Approved yang menunggu goods issue (DASH-01).
     *
     * @return list<SalesOrder>
     */
    public function awaitingIssue(int $limit): array;

    /**
     * Data order untuk report CSV dalam rentang tanggal (REPORT-01).
     *
     * @return list<array<string, mixed>>
     */
    public function ordersBetween(string $startDate, string $endDate, ?int $createdBy = null): array;
}
