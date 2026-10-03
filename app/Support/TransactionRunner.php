<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Boundary transaction.
 *
 * Satu-satunya alasan interface ini ada: StockService WAJIB dapat di-unit-test
 * tanpa database (constitution Principle III), sekaligus WAJIB menjalankan
 * goods receipt dan goods issue di dalam satu transaction sungguhan
 * (ARCH-02). Dua kewajiban itu tidak dapat dipenuhi bersamaan bila Service
 * bergantung langsung pada kelas konkret Database.
 *
 * Implementasi produksinya adalah Database sendiri — tidak ada kelas pembungkus
 * tambahan. Unit test menukarnya dengan ImmediateTransactionRunner.
 *
 * Callback sengaja TIDAK menerima PDO: Service tidak pernah boleh menyentuh
 * PDO, seluruh akses data tetap lewat repository (ARCH-01).
 */
interface TransactionRunner
{
    /**
     * Menjalankan callback di dalam satu transaction.
     *
     * Commit bila callback selesai tanpa exception, rollback bila tidak.
     *
     * @template T
     * @param callable(): T $callback
     * @return T
     */
    public function transaction(callable $callback): mixed;
}
