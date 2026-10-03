# Module: Sales Order (`sales-order`)

**Back to**: [00-overview.md](../00-overview.md) · **Last Updated**: 2026-10-03

## Summary

Penjualan ke customer dengan segregation of duties: Sales membuat dan mengajukan, Admin lain
approve atau reject, Warehouse Staff mengeluarkan barang (dikerjakan oleh [stock](stock.md)).

## Capabilities

- **SO-CAP-001** — Membuat SO Draft (customer, warehouse asal, item dengan harga jual dari katalog) — Admin, Sales
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
- Rule: `approved_by <> created_by` (`SalesOrderService::requireApprovableOrder`), ditegakkan di
  server; route approve/reject `$adminOnly`
- Rule: order di luar scope Sales menghasilkan **404**, bukan 403 (scoping di WHERE clause)
- Rule: transisi compare-and-set; cancel tidak dapat menimpa order yang sudah Fulfilled

## API Surface

**Exposes**

| Kind | Name / Route | Role | Entry file |
| ---- | ------------ | ---- | ---------- |
| HTTP | `GET /sales-orders`, `GET /sales-orders/{id}` | semua role (Sales: miliknya) | [`app/Controller/SalesOrderController.php`](../../../app/Controller/SalesOrderController.php) |
| HTTP | `/sales-orders/create`, `POST /sales-orders`, `POST /{id}/submit`, `POST /{id}/cancel` | Admin, Sales | idem |
| HTTP | `POST /sales-orders/{id}/approve`, `POST /{id}/reject` | **Admin** | idem |
| HTTP | `GET`/`POST /sales-orders/{id}/issue` | Admin, WS | idem → `StockService::issueGoods` |
| PHP | `SalesOrderService::create/submit/approve/reject/cancel/requireVisibleOrder/scopeFor/search/count/countByStatus` | — | [`app/Service/SalesOrderService.php`](../../../app/Service/SalesOrderService.php) |

**Consumes**

- `master-data` — customer dan warehouse aktif
- `product` — katalog aktif, harga jual; JSON available (panduan stock di form)
- `stock` — `issueGoods`, `availableFor`, `movementsForSalesOrder`
- `users` — nama pembuat dan approver

## Data Flow

- Penjualan: Sales → Draft → submit (PendingApproval) → Admin lain approve (Approved) → WS issue (Fulfilled; stock berkurang dan ledger `Issue` ditulis)

## Screens / Pages

| Screen | Route | Entry file | Related capability |
| ------ | ----- | ---------- | ------------------ |
| SO list | `/sales-orders` | `views/sales-orders/index.php` | SO-CAP-007 |
| SO form | `/sales-orders/create` | `views/sales-orders/form.php` | SO-CAP-001 |
| SO detail | `/sales-orders/{id}` | `views/sales-orders/detail.php` | SO-CAP-002…005 |
| Goods issue | `/sales-orders/{id}/issue` | `views/sales-orders/issue.php` | SO-CAP-006 |

## Dependencies

- **Other modules**: master-data, product, stock, users, platform

## Test Coverage

- `SalesOrderService` — unit (`SalesOrderServiceTest`, `OrderSearchTest`)
- Segregation of duties, 404 scoping — integration (`ApprovalAuthorizationTest`)
- Race condition status (issue ganda, cancel vs fulfilled) — integration (`ConcurrentGoodsIssueTest`)
- `public/assets/js/order-lines.js`, `validation.js` — tidak ada test otomatis

## Known Gaps / Risks

- Pada 360px, tanggal di baris meta detail terpotong di tanda hubung — known-bugs KB-1.
- SO buatan Admin membutuhkan Admin kedua untuk disetujui (D-01); seed menyediakan `admin2@ioms.test`.

## Change Log

- **2026-10-03**: Initial version generated from codebase survey.
