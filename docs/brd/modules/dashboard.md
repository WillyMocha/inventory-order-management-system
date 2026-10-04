# Module: Dashboard (`dashboard`)

**Back to**: [00-overview.md](../00-overview.md) · **Last Updated**: 2026-10-04

## Summary

Satu halaman dashboard yang isinya berbeda per role. Seluruh angka berasal dari query agregasi.

## Capabilities

- **DASH-CAP-001** — Admin: nilai inventori (qty × harga beli), product di bawah reorder point, SO menunggu approval, dan sebaran SO/PO per status
- **DASH-CAP-002** — Sales: ringkasan SO miliknya per status
- **DASH-CAP-003** — Warehouse Staff: antrean goods receipt dan goods issue, serta product low-stock
- **DASH-CAP-004** — Menyediakan daftar low-stock sebagai JSON (Admin, WS)
- **DASH-CAP-005** — Admin dan WS: grafik SVG unit masuk/keluar per hari selama 30 hari terakhir, langsung dari `stock_ledger`, dengan total, net, dan link ke report untuk rentang yang sama (spec 005, **bonus**)

## Key Entities & Rules

- Tidak memiliki entity sendiri; membaca Product, ProductStock, SalesOrder, PurchaseOrder, dan StockLedger
- Rule: Sales tidak menerima data low-stock (403 pada API)
- Rule: angka harus sepakat dengan export CSV ([report](report.md)); dibuktikan `DashboardReportConsistencyTest`
- Rule: grafik stock movement memisah masuk/keluar dari tanda quantity (Adjustment ikut sesuai tandanya), memakai hari kalender UTC seperti report, dan tidak pernah dihitung untuk Sales

## API Surface

**Exposes**

| Kind | Name / Route | Role | Entry file |
| ---- | ------------ | ---- | ---------- |
| HTTP | `GET /`, `GET /dashboard` | semua role | [`app/Controller/DashboardController.php`](../../../app/Controller/DashboardController.php) |
| JSON | `GET /api/dashboard/low-stock` | Admin, WS | [`app/Controller/Api/DashboardApiController.php`](../../../app/Controller/Api/DashboardApiController.php) |
| PHP | `DashboardService::forRole(Role, userId)`, `adminFigures`, `salesFigures`, `warehouseFigures` | — | [`app/Service/DashboardService.php`](../../../app/Service/DashboardService.php) |

**Consumes**

- `product` — `countBy(lowStock)`, `lowStock()`, `totalInventoryValue()`
- `sales-order` — `countByStatus(?createdBy)`, `awaitingIssue()`
- `purchase-order` — `countByStatus()`, `awaitingReceipt()`
- `stock` — `StockLedgerRepositoryInterface::dailyMovementTotals(start, end)` (satu query per dashboard)
- `platform` — `ClockInterface` (jendela 30 hari), `Support\BarChartScale` (geometri grafik)

## Data Flow

- Dashboard: guard → `DashboardController` → `forRole(role, userId)` → figur per role → view role (`views/dashboard/{admin,sales,warehouse}.php`)

## Screens / Pages

| Screen | Route | Entry file | Related capability |
| ------ | ----- | ---------- | ------------------ |
| Admin dashboard | `/dashboard` | `views/dashboard/admin.php` | DASH-CAP-001 |
| Sales dashboard | `/dashboard` | `views/dashboard/sales.php` | DASH-CAP-002 |
| Warehouse dashboard | `/dashboard` | `views/dashboard/warehouse.php` | DASH-CAP-003 |
| Partial | — | `views/dashboard/_low-stock.php`, `_status-tally.php`, `_movement-chart.php` | DASH-CAP-005 |

## Dependencies

- **Other modules**: product, sales-order, purchase-order, stock, platform

## Test Coverage

- `DashboardService` — unit (`DashboardServiceTest`, `DashboardStockMovementTest`)
- `BarChartScale` — unit (`BarChartScaleTest`)
- Halaman dengan grafik per role, empty state, aksesibilitas, link report — integration (`DashboardChartRenderTest`)
- `DashboardApiController` — unit (`DashboardApiControllerTest`)
- Konsistensi dengan export, termasuk grafik = CSV per hari — integration (`DashboardReportConsistencyTest`)

## Known Gaps / Risks

- Menambah role baru menuntut perubahan `forRole()` dan view-nya (pelanggaran OCP yang disengaja; `critique.md` B.2).

## Change Log

- **2026-10-04**: DASH-CAP-005 — grafik stock movement 30 hari untuk Admin dan WS (spec 005, bonus).
- **2026-10-03**: Initial version generated from codebase survey.
