<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Enum\Role;
use App\Service\ReportService;
use App\Support\Exception\ForbiddenException;
use App\Support\Exception\UnauthenticatedException;
use App\Support\Exception\ValidationException;
use App\Support\Request;
use App\Support\Response;
use App\Support\Session;
use App\Support\View;

/**
 * Report dan export CSV (REPORT-01, FR-027).
 *
 * Halaman report menampilkan jumlah record yang cocok untuk rentang pilihan
 * user; tombol export mengirim rentang YANG SAMA ke endpoint CSV, dan angka di
 * layar dihitung dari baris yang persis akan ditulis ke file. Itulah sebabnya
 * isi file tidak bisa berbeda dari yang dilihat user.
 *
 * Pembatasan role terjadi di dua lapis yang saling mendukung:
 *   1. Route table — /reports/stock-movement.csv hanya untuk Admin dan
 *      Warehouse Staff; guard menolak Sales sebelum request sampai ke sini.
 *   2. Service — scopeFor() membatasi export order Sales pada miliknya
 *      sendiri, di dalam WHERE clause, bukan dengan menyaring hasil.
 */
final class ReportController
{
    /** Nama file export; rentangnya disisipkan agar dua export tidak tertukar. */
    private const string STOCK_MOVEMENT_FILE = 'stock-movement';
    private const string ORDERS_FILE = 'orders';

    public function __construct(
        private readonly View $view,
        private readonly ReportService $reports,
        private readonly Session $session,
    ) {
    }

    public function index(Request $request): Response
    {
        $role = $this->requireRole();
        $userId = $this->requireUserId();

        $requested = $this->requestedRange($request);
        $errors = [];

        try {
            $range = $this->reports->validateRange($requested['start'], $requested['end']);
        } catch (ValidationException $e) {
            // Rentang yang ditolak tidak boleh mengosongkan halaman: form tetap
            // tampil dengan nilai yang diisi user beserta pesannya (FR-029),
            // dan hitungannya jatuh kembali ke rentang default.
            $errors = $e->errors();
            $range = $this->reports->defaultRange();
        }

        $scope = $this->reports->scopeFor($role, $userId);
        $orderRows = $this->reports->salesOrders($range['start'], $range['end'], $scope);

        // Stock movement bukan bagian Sales (§1.2); untuk role itu angkanya
        // memang tidak dihitung, bukan sekadar tidak ditampilkan.
        $canSeeStockMovement = $this->canSeeStockMovement($role);

        return Response::html($this->view->render('reports/index', [
            'title'               => 'Reports',
            'activeNav'           => 'reports',
            'range'               => $range,
            'requested'           => $requested,
            'errors'              => $errors,
            'orderCount'          => count($orderRows),
            'statusTotals'        => $this->reports->statusTotals($orderRows),
            'movementCount'       => $canSeeStockMovement
                ? count($this->reports->stockMovements($range['start'], $range['end']))
                : 0,
            'canSeeStockMovement' => $canSeeStockMovement,
            'isScoped'            => $scope !== null,
            'maxRangeDays'        => ReportService::MAX_RANGE_DAYS,
        ]));
    }

    /**
     * Export stock movement. Route-nya sudah dibatasi Admin dan Warehouse
     * Staff; pemeriksaan ulang di sini menjaga endpoint tetap tertutup andai
     * route table suatu saat diubah.
     */
    public function stockMovementCsv(Request $request): Response
    {
        if (!$this->canSeeStockMovement($this->requireRole())) {
            throw new ForbiddenException('This report is not available for your role.');
        }

        $requested = $this->requestedRange($request);

        try {
            $range = $this->reports->validateRange($requested['start'], $requested['end']);
        } catch (ValidationException) {
            return $this->backToForm($requested);
        }

        $rows = $this->reports->stockMovements($range['start'], $range['end']);

        return $this->stream(
            self::STOCK_MOVEMENT_FILE,
            $range,
            ReportService::STOCK_MOVEMENT_HEADER,
            array_map(fn (array $row): array => $this->reports->stockMovementCsvRow($row), $rows),
        );
    }

    public function ordersCsv(Request $request): Response
    {
        $role = $this->requireRole();
        $userId = $this->requireUserId();

        $requested = $this->requestedRange($request);

        try {
            $range = $this->reports->validateRange($requested['start'], $requested['end']);
        } catch (ValidationException) {
            return $this->backToForm($requested);
        }

        $rows = $this->reports->salesOrders(
            $range['start'],
            $range['end'],
            $this->reports->scopeFor($role, $userId),
        );

        return $this->stream(
            self::ORDERS_FILE,
            $range,
            ReportService::ORDER_HEADER,
            array_map(fn (array $row): array => $this->reports->orderCsvRow($row), $rows),
        );
    }

    /**
     * Rentang yang diminta lewat query string. Tanpa parameter, halaman tetap
     * langsung dapat dipakai memakai rentang default.
     *
     * @return array{start: string, end: string}
     */
    private function requestedRange(Request $request): array
    {
        $default = $this->reports->defaultRange();

        return [
            'start' => $request->queryString('start_date', $default['start']),
            'end'   => $request->queryString('end_date', $default['end']),
        ];
    }

    /**
     * Rentang tidak sah pada endpoint CSV dikembalikan ke form, bukan dibalas
     * halaman error: user perlu melihat pesannya di samping input yang salah
     * beserta nilai yang tadi diisi (FR-029).
     *
     * @param array{start: string, end: string} $requested
     */
    private function backToForm(array $requested): Response
    {
        return Response::redirect('/reports?' . http_build_query([
            'start_date' => $requested['start'],
            'end_date'   => $requested['end'],
        ]));
    }

    /**
     * Header ditulis lebih dulu, lalu setiap baris di-stream ke php://output —
     * tidak ada string besar yang disusun di memori (research R-008). Rentang
     * kosong tetap menghasilkan file berisi header, bukan file nol byte.
     *
     * @param array{start: string, end: string} $range
     * @param list<string>                      $header
     * @param list<list<string>>                $rows
     */
    private function stream(string $name, array $range, array $header, array $rows): Response
    {
        $filename = $name . '-' . $range['start'] . '-to-' . $range['end'] . '.csv';

        return Response::csvStream($filename, static function () use ($header, $rows): void {
            $handle = fopen('php://output', 'wb');

            if ($handle === false) {
                return;
            }

            fputcsv($handle, $header);

            foreach ($rows as $row) {
                fputcsv($handle, $row);
            }

            fclose($handle);
        });
    }

    private function canSeeStockMovement(Role $role): bool
    {
        return $role === Role::Admin || $role === Role::WarehouseStaff;
    }

    /** @throws UnauthenticatedException */
    private function requireRole(): Role
    {
        $role = $this->session->role();

        if ($role === null) {
            throw new UnauthenticatedException();
        }

        return $role;
    }

    /** @throws UnauthenticatedException */
    private function requireUserId(): int
    {
        $userId = $this->session->userId();

        if ($userId === null) {
            throw new UnauthenticatedException();
        }

        return $userId;
    }
}
