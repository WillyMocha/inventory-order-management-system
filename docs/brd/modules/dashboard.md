# Module: Dashboard (`dashboard`)

**Back to**: [00-overview.md](../00-overview.md) · **Last Updated**: 2026-10-03

## Summary

Satu halaman dashboard yang isinya berbeda per role. Seluruh angka berasal dari query agregasi.

## Capabilities

- **DASH-CAP-001** — Admin: nilai inventori (qty × harga beli), product di bawah reorder point, SO menunggu approval, dan sebaran SO/PO per status
- **DASH-CAP-002** — Sales: ringkasan SO miliknya per status
- **DASH-CAP-003** — Warehouse Staff: antrean goods receipt dan goods issue, serta product low-stock
- **DASH-CAP-004** — Menyediakan daftar low-stock sebagai JSON (Admin, WS)

## Key Entities & Rules

- Tidak memiliki entity sendiri; membaca Product, ProductStock, SalesOrder, dan PurchaseOrder
- Rule: Sales tidak menerima data low-stock (403 pada API)
- Rule: angka harus sepakat dengan export CSV ([report](report.md)); dibuktikan `DashboardReportConsistencyTest`

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

## Data Flow

- Dashboard: guard → `DashboardController` → `forRole(role, userId)` → figur per role → view role (`views/dashboard/{admin,sales,warehouse}.php`)

## Screens / Pages

| Screen | Route | Entry file | Related capability |
| ------ | ----- | ---------- | ------------------ |
| Admin dashboard | `/dashboard` | `views/dashboard/admin.php` | DASH-CAP-001 |
| Sales dashboard | `/dashboard` | `views/dashboard/sales.php` | DASH-CAP-002 |
| Warehouse dashboard | `/dashboard` | `views/dashboard/warehouse.php` | DASH-CAP-003 |
| Partial | — | `views/dashboard/_low-stock.php`, `_status-tally.php` | — |

## Dependencies

- **Other modules**: product, sales-order, purchase-order, platform

## Test Coverage

- `DashboardService` — unit (`DashboardServiceTest`)
- `DashboardApiController` — unit (`DashboardApiControllerTest`)
- Konsistensi dengan export — integration (`DashboardReportConsistencyTest`)

## Known Gaps / Risks

- Menambah role baru menuntut perubahan `forRole()` dan view-nya (pelanggaran OCP yang disengaja; `critique.md` B.2).

## Change Log

- **2026-10-03**: Initial version generated from codebase survey.
