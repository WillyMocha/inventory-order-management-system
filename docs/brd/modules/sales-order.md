# Module: Sales Order (`sales-order`)

**Back to**: [00-overview.md](../00-overview.md) · **Last Updated**: 2026-10-04

## Summary

Penjualan ke customer dengan segregation of duties: Sales membuat dan mengajukan, Admin lain
approve atau reject, Warehouse Staff mengeluarkan barang (dikerjakan oleh [stock](stock.md)).

## Capabilities

- **SO-CAP-001** — Membuat SO Draft (customer, warehouse asal, item dengan harga jual dari katalog) — Admin, Sales
- **SO-CAP-008** — Mengedit SO **Draft** (customer, warehouse asal, tanggal, seluruh line) — **pembuat order saja**, Sales maupun Admin (spec 004, D-04)
- **SO-CAP-002** — Mengajukan SO (Draft → PendingApproval) — Admin, Sales
- **SO-CAP-003** — Menyetujui SO — **Admin saja, dan bukan pembuat order** (berlaku juga untuk Admin)
- **SO-CAP-004** — Menolak SO (PendingApproval → Cancelled) — Admin, dengan aturan yang sama
- **SO-CAP-005** — Membatalkan SO sebelum Fulfilled — Admin, Sales (miliknya)
- **SO-CAP-006** — Memproses goods issue untuk SO Approved — Admin, WS
- **SO-CAP-007** — Mencari, memfilter, mengurutkan, dan mem-paginate SO; Sales hanya melihat order miliknya

## Key Entities & Rules

- **SO-ENT-001 SalesOrder** — order_number `SO-YYYYMMDD-####` (unik), customer, created_by, approved_by (nullable),
  approved_at, warehouse asal, status, order_date (`sales_order`, `app/Entity/SalesOrder.php`)
- **SO-ENT-002 SalesOrderItem** — product, quantity, selling_price (snapshot dari katalog saat
  dibuat) (`sales_order_item`)
- **SO-ENT-003 SalesOrderStatus** — `Draft → PendingApproval → Approved → Fulfilled`; `Cancelled`
  sebelum Fulfilled (`app/Entity/Enum/SalesOrderStatus.php`)
- Rule: `approved_by <> created_by` (`SalesOrderApprovalService::requireApprovableOrder`), ditegakkan di
  server; route approve/reject `$adminOnly`
- Rule: order di luar scope Sales menghasilkan **404**, bukan 403 (scoping di WHERE clause)
- Rule: transisi compare-and-set; cancel tidak dapat menimpa order yang sudah Fulfilled
- Rule: edit hanya oleh pembuat order dan hanya selama Draft; Admin lain → 403, Sales lain → 404,
  WS → 403; status diperiksa ulang saat menyimpan (`updateDraft` bersyarat `status = 'Draft'`),
  header dan line dalam satu transaction; harga jual diambil ulang dari katalog (D-04)

## API Surface

**Exposes**

| Kind | Name / Route | Role | Entry file |
| ---- | ------------ | ---- | ---------- |
| HTTP | `GET /sales-orders`, `GET /sales-orders/{id}` | semua role (Sales: miliknya) | [`app/Controller/SalesOrderController.php`](../../../app/Controller/SalesOrderController.php) |
| HTTP | `/sales-orders/create`, `POST /sales-orders`, `POST /{id}/submit`, `POST /{id}/cancel` | Admin, Sales | idem |
| HTTP | `GET /sales-orders/{id}/edit`, `POST /sales-orders/{id}` | Admin, Sales — service: pembuat order saja | idem |
| HTTP | `POST /sales-orders/{id}/approve`, `POST /{id}/reject` | **Admin** | [`app/Controller/SalesOrderApprovalController.php`](../../../app/Controller/SalesOrderApprovalController.php) |
| HTTP | `GET`/`POST /sales-orders/{id}/issue` | Admin, WS | [`app/Controller/GoodsIssueController.php`](../../../app/Controller/GoodsIssueController.php) → `StockService::issueGoods` |
| PHP | `SalesOrderService::create/update/canEdit/assertMayEdit/submit/cancel/requireVisibleOrder/scopeFor/search/count/countByStatus` | — | [`app/Service/SalesOrderService.php`](../../../app/Service/SalesOrderService.php) |
| PHP | `SalesOrderApprovalService::approve/reject` | — | [`app/Service/SalesOrderApprovalService.php`](../../../app/Service/SalesOrderApprovalService.php) |

**Consumes**

- `master-data` — customer dan warehouse aktif
- `product` — katalog aktif, harga jual; JSON available (panduan stock di form)
- `stock` — `issueGoods`, `availableFor`, `movementsForSalesOrder`
- `users` — nama pembuat dan approver

## Data Flow

- Penjualan: Sales → Draft (boleh diedit pembuatnya) → submit (PendingApproval) → Admin lain approve (Approved) → WS issue (Fulfilled; stock berkurang dan ledger `Issue` ditulis)

## Screens / Pages

| Screen | Route | Entry file | Related capability |
| ------ | ----- | ---------- | ------------------ |
| SO list | `/sales-orders` | `views/sales-orders/index.php` | SO-CAP-007 |
| SO form (create / edit) | `/sales-orders/create`, `/sales-orders/{id}/edit` | `views/sales-orders/form.php` | SO-CAP-001, SO-CAP-008 |
| SO detail | `/sales-orders/{id}` | `views/sales-orders/detail.php` | SO-CAP-002…005 |
| Goods issue | `/sales-orders/{id}/issue` | `views/sales-orders/issue.php` | SO-CAP-006 |

## Dependencies

- **Other modules**: master-data, product, stock, users, platform

## Test Coverage

- `SalesOrderService`, `SalesOrderApprovalService` — unit (`SalesOrderServiceTest`, `SalesOrderServiceEditTest`, `OrderSearchTest`)
- Edit Draft: CAS status, penggantian line, rollback, route roles — integration (`EditDraftOrderTest`)
- Segregation of duties, 404 scoping — integration (`ApprovalAuthorizationTest`)
- Race condition status (issue ganda, cancel vs fulfilled) — integration (`ConcurrentGoodsIssueTest`)
- Lapisan HTTP (status code, redirect + flash, form 422, 403/404) — integration (`SalesOrderControllerTest`,
  `SalesOrderActionControllerTest` untuk approval dan goods issue)
- `public/assets/js/order-lines.js`, `validation.js` — tidak ada test otomatis

## Known Gaps / Risks

- Pada 360px, tanggal di baris meta detail terpotong di tanda hubung — known-bugs KB-1.
- SO buatan Admin membutuhkan Admin kedua untuk disetujui (D-01); seed menyediakan `admin2@ioms.test`.

## Change Log

- **2026-10-04**: Approve/reject dipindah ke `SalesOrderApprovalService` + `SalesOrderApprovalController`, goods issue ke `GoodsIssueController` (TD-11; URL dan aturan tidak berubah). Customer, warehouse, dan product nonaktif kini ditolak saat create dan edit (TD-10).
- **2026-10-04**: SO-CAP-008 edit SO Draft oleh pembuatnya (spec 004-edit-draft-orders, D-04).
- **2026-10-03**: Initial version generated from codebase survey.
