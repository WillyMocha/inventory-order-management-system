<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Enum\SalesOrderStatus;
use App\Entity\SalesOrder;

interface SalesOrderRepositoryInterface
{
    /** Memuat order beserta seluruh item-nya. */
    public function findById(int $id): ?SalesOrder;

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

    public function updateStatus(int $id, SalesOrderStatus $status): void;

    /** Mencatat siapa yang menyetujui dan kapan. */
    public function markApproved(int $id, int $approvedBy, string $approvedAt): void;

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
