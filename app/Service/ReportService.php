<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Enum\PurchaseOrderStatus;
use App\Entity\Enum\Role;
use App\Entity\Enum\SalesOrderStatus;
use App\Repository\PurchaseOrderRepositoryInterface;
use App\Repository\SalesOrderRepositoryInterface;
use App\Repository\StockLedgerRepositoryInterface;
use App\Support\ClockInterface;
use App\Support\Exception\ValidationException;
use App\Support\Money;
use DateTimeImmutable;

/**
 * Report stock movement dan status order untuk rentang tanggal pilihan user
 * (REPORT-01, FR-027).
 *
 * FR-027 mewajibkan isi export SEPAKAT dengan angka dashboard. Cara menjaganya
 * di sini bukan dengan mencocokkan dua perhitungan, melainkan dengan
 * menghapus kemungkinan keduanya berbeda: Service ini dan DashboardService
 * memanggil METHOD QUERY YANG SAMA pada repository yang sama — tidak ada satu
 * pun SQL yang ditulis dua kali (research R-008). Angka yang tampil di layar
 * report dihitung dari baris yang PERSIS akan ditulis ke file, lewat
 * statusTotals(), sehingga layar dan file tidak punya jalur untuk berbeda.
 *
 * Role acting user di-pass sebagai argument, tidak dibaca dari session, dan
 * scoping Sales diterapkan di dalam WHERE clause repository — bukan dengan
 * menyaring baris setelah terbaca (security standard §2).
 */
final class ReportService
{
    /**
     * Rentang export dibatasi 366 hari (research R-008). Export adalah satu-
     * satunya endpoint yang murah dibuat mahal; satu tahun kabisat penuh sudah
     * mencakup seluruh kebutuhan pelaporan yang disebut brief.
     */
    public const int MAX_RANGE_DAYS = 366;

    /** Rentang yang terisi saat halaman report dibuka tanpa parameter. */
    public const int DEFAULT_RANGE_DAYS = 30;

    private const string DATE_FORMAT = 'Y-m-d';

    /** Karakter pembuka yang membuat spreadsheet memperlakukan sel sebagai rumus. */
    private const string FORMULA_TRIGGERS = "=+-@\t\r";

    /** @var list<string> */
    public const array STOCK_MOVEMENT_FIELDS = [
        'created_at',
        'sku',
        'product_name',
        'warehouse_name',
        'movement_type',
        'quantity',
        'reference_type',
        'reference_id',
        'performed_by_name',
    ];

    /** @var list<string> */
    public const array STOCK_MOVEMENT_HEADER = [
        'Date',
        'SKU',
        'Product',
        'Warehouse',
        'Movement Type',
        'Quantity',
        'Reference Type',
        'Reference',
        'Performed By',
    ];

    /** @var list<string> */
    public const array ORDER_FIELDS = [
        'order_number',
        'order_date',
        'status',
        'customer_name',
        'warehouse_name',
        'created_by_name',
        'approved_by_name',
        'total_value',
    ];

    /** @var list<string> */
    public const array ORDER_HEADER = [
        'Order Number',
        'Order Date',
        'Status',
        'Customer',
        'Warehouse',
        'Created By',
        'Approved By',
        'Total Value (IDR)',
    ];

    /** @var list<string> */
    public const array PURCHASE_ORDER_FIELDS = [
        'order_number',
        'order_date',
        'status',
        'supplier_name',
        'warehouse_name',
        'created_by_name',
        'ordered_quantity',
        'received_quantity',
        'total_value',
    ];

    /** @var list<string> */
    public const array PURCHASE_ORDER_HEADER = [
        'Order Number',
        'Order Date',
        'Status',
        'Supplier',
        'Warehouse',
        'Created By',
        'Ordered Qty',
        'Received Qty',
        'Total Value (IDR)',
    ];

    public function __construct(
        private readonly StockLedgerRepositoryInterface $ledger,
        private readonly SalesOrderRepositoryInterface $salesOrders,
        private readonly PurchaseOrderRepositoryInterface $purchaseOrders,
        private readonly ClockInterface $clock,
    ) {
    }

    /**
     * Scoping kepemilikan menurut role (FR-027, §1.2).
     *
     * Sales hanya boleh mengekspor order miliknya sendiri. Admin dan Warehouse
     * Staff tidak dibatasi — null berarti "tanpa filter pemilik".
     */
    public function scopeFor(Role $role, int $userId): ?int
    {
        return $role === Role::Sales ? $userId : null;
    }

    /**
     * Rentang default saat halaman dibuka tanpa parameter: 30 hari terakhir
     * sampai hari ini. Waktu diambil dari ClockInterface agar deterministik
     * saat di-unit-test (research R-010).
     *
     * @return array{start: string, end: string}
     */
    public function defaultRange(): array
    {
        $today = $this->clock->now();

        return [
            'start' => $today->modify('-' . (self::DEFAULT_RANGE_DAYS - 1) . ' days')->format(self::DATE_FORMAT),
            'end'   => $today->format(self::DATE_FORMAT),
        ];
    }

    /**
     * Memvalidasi rentang yang diminta user.
     *
     * @return array{start: string, end: string}
     *
     * @throws ValidationException
     */
    public function validateRange(string $startDate, string $endDate): array
    {
        $errors = [];

        $start = $this->parseDate($startDate);
        $end = $this->parseDate($endDate);

        if ($start === null) {
            $errors['start_date'] = 'Enter a valid start date (YYYY-MM-DD).';
        }

        if ($end === null) {
            $errors['end_date'] = 'Enter a valid end date (YYYY-MM-DD).';
        }

        if ($start !== null && $end !== null) {
            if ($end < $start) {
                $errors['end_date'] = 'The end date cannot be before the start date.';
            } elseif ($this->inclusiveDays($start, $end) > self::MAX_RANGE_DAYS) {
                $errors['end_date'] = 'Choose a range of at most ' . self::MAX_RANGE_DAYS . ' days.';
            }
        }

        if ($errors !== []) {
            throw new ValidationException($errors);
        }

        return [
            'start' => $startDate,
            'end'   => $endDate,
        ];
    }

    /**
     * Baris stock movement pada rentang tersebut — batas awal dan akhir
     * bersifat inklusif.
     *
     * Ledger tidak punya pemilik, sehingga tidak ada scoping di sini; route-
     * nya sendiri hanya terbuka untuk Admin dan Warehouse Staff.
     *
     * @return list<array<string, mixed>>
     */
    public function stockMovements(string $startDate, string $endDate): array
    {
        return $this->ledger->movementsBetween($startDate, $endDate);
    }

    /**
     * Baris sales order pada rentang tersebut.
     *
     * Method repository yang dipanggil di sini adalah method yang sama dengan
     * yang menyuplai dashboard — itulah alasan keduanya tidak bisa berbeda.
     *
     * @param int|null $createdBy hasil scopeFor(); null berarti seluruh pemilik
     * @return list<array<string, mixed>>
     */
    public function salesOrders(string $startDate, string $endDate, ?int $createdBy): array
    {
        return $this->salesOrders->ordersBetween($startDate, $endDate, $createdBy);
    }

    /**
     * Tally status dihitung DARI BARIS YANG DIEKSPOR, bukan dari query kedua.
     * Inilah yang membuat jumlah pada layar report selalu sama dengan isi file
     * CSV-nya (FR-027).
     *
     * @param list<array<string, mixed>> $rows
     * @return array<string, int>
     */
    public function statusTotals(array $rows): array
    {
        return $this->tally($rows, array_map(
            static fn (SalesOrderStatus $status): string => $status->value,
            SalesOrderStatus::cases(),
        ));
    }

    /**
     * Baris Purchase Order pada rentang tersebut.
     *
     * Tanpa scoping: PO tidak dimiliki Sales, dan route export-nya hanya
     * terbuka untuk Admin dan Warehouse Staff (§1.2).
     *
     * @return list<array<string, mixed>>
     */
    public function purchaseOrders(string $startDate, string $endDate): array
    {
        return $this->purchaseOrders->ordersBetween($startDate, $endDate);
    }

    /**
     * Tally status PO, dihitung dari baris yang diekspor — sama seperti
     * statusTotals(), agar layar dan file tidak dapat berbeda.
     *
     * @param list<array<string, mixed>> $rows
     * @return array<string, int>
     */
    public function purchaseOrderStatusTotals(array $rows): array
    {
        return $this->tally($rows, array_map(
            static fn (PurchaseOrderStatus $status): string => $status->value,
            PurchaseOrderStatus::cases(),
        ));
    }

    /**
     * Satu baris CSV Purchase Order, urutannya mengikuti PURCHASE_ORDER_HEADER.
     *
     * @param array<string, mixed> $row
     * @return list<string>
     */
    public function purchaseOrderCsvRow(array $row): array
    {
        $cells = array_map(
            fn (string $field): string => $this->cell($row[$field] ?? null),
            self::PURCHASE_ORDER_FIELDS,
        );

        $cells[8] = Money::formatPlain($cells[8] === '' ? '0' : $cells[8]);

        return $cells;
    }

    /**
     * Setiap status mendapat angka, termasuk nol, agar layar tidak
     * menyembunyikan status yang kosong.
     *
     * @param list<array<string, mixed>> $rows
     * @param list<string>               $statuses
     * @return array<string, int>
     */
    private function tally(array $rows, array $statuses): array
    {
        $totals = array_fill_keys($statuses, 0);

        foreach ($rows as $row) {
            $status = (string) $row['status'];

            if (array_key_exists($status, $totals)) {
                $totals[$status]++;
            }
        }

        return $totals;
    }

    /**
     * Satu baris CSV stock movement, urutannya mengikuti STOCK_MOVEMENT_HEADER.
     *
     * @param array<string, mixed> $row
     * @return list<string>
     */
    public function stockMovementCsvRow(array $row): array
    {
        return array_map(
            fn (string $field): string => $this->cell($row[$field] ?? null),
            self::STOCK_MOVEMENT_FIELDS,
        );
    }

    /**
     * Satu baris CSV order, urutannya mengikuti ORDER_HEADER.
     *
     * @param array<string, mixed> $row
     * @return list<string>
     */
    public function orderCsvRow(array $row): array
    {
        $cells = array_map(
            fn (string $field): string => $this->cell($row[$field] ?? null),
            self::ORDER_FIELDS,
        );

        // Nilai uang ditulis sebagai angka polos tanpa prefix dan pemisah
        // ribuan agar spreadsheet dapat menjumlahkannya kembali (spec A-011).
        $cells[7] = Money::formatPlain($cells[7] === '' ? '0' : $cells[7]);

        return $cells;
    }

    /**
     * Kolom kosong ditulis sebagai string kosong — jangan sampai kata "null"
     * atau "false" ikut mendarat di dalam file.
     *
     * `mixed` dibenarkan: baris berasal dari PDO, yang mengembalikan kolom
     * sebagai string, int, atau null bergantung tipe kolomnya.
     */
    private function cell(mixed $value): string
    {
        return $value === null ? '' : $this->neutralizeFormula((string) $value);
    }

    /**
     * CSV formula injection (CWE-1236).
     *
     * Nama product, customer dan user berasal dari input user, dan sel yang
     * diawali '=', '+', '-', '@', tab atau carriage return akan dieksekusi
     * sebagai rumus begitu file-nya dibuka di spreadsheet. Sel seperti itu
     * diawali tanda kutip tunggal sehingga terbaca sebagai teks biasa.
     *
     * Angka dikecualikan: quantity Issue memang bernilai negatif, dan nilai
     * uang harus tetap dapat dijumlahkan kembali oleh spreadsheet-nya.
     */
    private function neutralizeFormula(string $value): string
    {
        if ($value === '' || $this->isPlainNumber($value)) {
            return $value;
        }

        return str_contains(self::FORMULA_TRIGGERS, $value[0])
            ? "'" . $value
            : $value;
    }

    private function isPlainNumber(string $value): bool
    {
        return preg_match('/^-?\d+(\.\d+)?$/', $value) === 1;
    }

    /**
     * Tanggal harus benar-benar ada di kalender: checkdate() menolak
     * 2026-02-30 yang formatnya sah tetapi tanggalnya tidak pernah ada.
     */
    private function parseDate(string $value): ?DateTimeImmutable
    {
        $parsed = DateTimeImmutable::createFromFormat('!' . self::DATE_FORMAT, $value);

        if ($parsed === false || $parsed->format(self::DATE_FORMAT) !== $value) {
            return null;
        }

        return $parsed;
    }

    /** Kedua ujung rentang ikut dihitung: 1 Januari s.d. 1 Januari = 1 hari. */
    private function inclusiveDays(DateTimeImmutable $start, DateTimeImmutable $end): int
    {
        return (int) $start->diff($end)->days + 1;
    }
}
