# Module: Report (`report`)

**Back to**: [00-overview.md](../00-overview.md) · **Last Updated**: 2026-10-04

## Summary

Export CSV untuk rentang tanggal pilihan user: stock movement, status Sales Order, dan status
Purchase Order. Angka di layar dihitung dari baris yang persis akan diekspor.

## Capabilities

- **REP-CAP-001** — Memilih rentang tanggal (default 30 hari, maksimum 366 hari, tanggal kalender yang valid)
- **REP-CAP-002** — Mengekspor status Sales Order — Admin, Sales (hanya order miliknya)
- **REP-CAP-003** — Mengekspor stock movement (ledger) — Admin, WS
- **REP-CAP-004** — Mengekspor status Purchase Order beserta qty dipesan dan diterima — Admin (`decisions.md` D-03)
- **REP-CAP-005** — Menampilkan tally per status yang sama persis dengan isi file

## Key Entities & Rules

- Tidak memiliki entity sendiri; membaca StockLedger, SalesOrder, dan PurchaseOrder
- Rule: rentang kosong tetap menghasilkan file berisi header saja
- Rule: sel yang diawali `= + - @ \t \r` dinetralkan (CSV formula injection, CWE-1236); angka dan uang ditulis polos
- Rule: scoping Sales diterapkan di WHERE clause, bukan dengan menyaring hasil
- Rule: role per export mengikuti matriks §1.2 — Admin semua, Sales order miliknya, Warehouse Staff report stock saja; halaman report hanya menampilkan export milik role itu

## API Surface

**Exposes**

| Kind | Name / Route | Role | Entry file |
| ---- | ------------ | ---- | ---------- |
| HTTP | `GET /reports?start_date=&end_date=` | semua role | [`app/Controller/ReportController.php`](../../../app/Controller/ReportController.php) |
| CSV | `GET /reports/orders.csv` | Admin, Sales (miliknya) | idem |
| CSV | `GET /reports/stock-movement.csv` | Admin, WS | idem |
| CSV | `GET /reports/purchase-orders.csv` | Admin | idem |
| PHP | `ReportService` (validateRange, salesOrders, purchaseOrders, stockMovements, *CsvRow, statusTotals) | — | [`app/Service/ReportService.php`](../../../app/Service/ReportService.php) |

**Consumes**

- `stock` — `StockLedgerRepositoryInterface::movementsBetween`
- `sales-order` — `ordersBetween(start, end, ?createdBy)`
- `purchase-order` — `ordersBetween(start, end)`
- `platform` — `Response::csvStream` (streaming `fputcsv`), `ClockInterface`

## Data Flow

- Export: user → pilih rentang → `validateRange` → baris dari repository → `*CsvRow` (netralisasi formula) → stream CSV; rentang tidak sah dikembalikan ke form beserta pesannya

## Screens / Pages

| Screen | Route | Entry file | Related capability |
| ------ | ----- | ---------- | ------------------ |
| Reports | `/reports` | `views/reports/index.php` | REP-CAP-001…005 |

## Dependencies

- **Other modules**: stock, sales-order, purchase-order, platform

## Test Coverage

- `ReportService` — unit (`ReportServiceTest`, 11/11 method)
- Kolom export terhadap MySQL dan konsistensi dengan dashboard — integration (`DashboardReportConsistencyTest`)
- Role per export di route table, 403 di controller, kartu export per role — integration (`ReportAccessTest`)

## Known Gaps / Risks

- —

## Change Log

- **2026-10-04**: Akses export diselaraskan dengan matriks §1.2: Warehouse Staff hanya report stock (sebelumnya juga export SO dan PO), dan export PO hanya untuk Admin.
- **2026-10-03**: Initial version generated from codebase survey.
