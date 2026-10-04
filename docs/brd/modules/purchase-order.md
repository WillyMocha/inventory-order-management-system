# Module: Purchase Order (`purchase-order`)

**Back to**: [00-overview.md](../00-overview.md) · **Last Updated**: 2026-10-03

## Summary

Pembelian ke supplier: membuat PO, mengajukannya, membatalkannya, dan memicu goods receipt
(dikerjakan oleh [stock](stock.md)).

## Capabilities

- **PO-CAP-001** — Membuat PO Draft dengan supplier, warehouse tujuan, dan item (product, qty, harga beli) — Admin dan Warehouse Staff
- **PO-CAP-002** — Mengajukan PO (Draft → Ordered) — Admin dan Warehouse Staff (`decisions.md` D-02)
- **PO-CAP-003** — Membatalkan PO sebelum Received — Admin saja
- **PO-CAP-004** — Mencatat goods receipt penuh atau sebagian — Admin dan Warehouse Staff
- **PO-CAP-005** — Mencari (nomor/supplier), memfilter status, mengurutkan, dan mem-paginate PO

## Key Entities & Rules

- **PO-ENT-001 PurchaseOrder** — order_number `PO-YYYYMMDD-####` (unik), supplier, warehouse
  tujuan, status, order_date, created_by (`purchase_order`, `app/Entity/PurchaseOrder.php`)
- **PO-ENT-002 PurchaseOrderItem** — product, quantity, received_quantity (CHECK ≤ quantity),
  purchase_price (`purchase_order_item`, CASCADE ke header)
- **PO-ENT-003 PurchaseOrderStatus** — `Draft → Ordered → PartiallyReceived → Received`; `Cancelled`
  dari tahap mana pun sebelum `Received` (`app/Entity/Enum/PurchaseOrderStatus.php`)
- Rule: transisi status bersifat compare-and-set; cancel tidak mengembalikan stock yang sudah
  diterima (ledger append-only)

## API Surface

**Exposes**

| Kind | Name / Route | Role | Entry file |
| ---- | ------------ | ---- | ---------- |
| HTTP | `GET /purchase-orders`, `/create`, `POST /purchase-orders`, `GET /{id}` | Admin, WS | [`app/Controller/PurchaseOrderController.php`](../../../app/Controller/PurchaseOrderController.php) |
| HTTP | `POST /purchase-orders/{id}/submit` | Admin, WS | idem |
| HTTP | `POST /purchase-orders/{id}/cancel` | **Admin** | idem |
| HTTP | `GET`/`POST /purchase-orders/{id}/receive` | Admin, WS | idem → `StockService::receiveGoods` |
| PHP | `PurchaseOrderService::create/submit/cancel/requireOrder/search/count/countByStatus` | — | [`app/Service/PurchaseOrderService.php`](../../../app/Service/PurchaseOrderService.php) |

**Consumes**

- `master-data` — supplier dan warehouse aktif
- `product` — product aktif dan harga beli
- `stock` — `receiveGoods`, `movementsForPurchaseOrder`

## Data Flow

- Pembelian: Admin/WS → buat Draft → submit (Ordered) → receive sebagian (PartiallyReceived) → receive sisanya (Received); setiap receipt menambah stock dan menulis ledger `Receipt`

## Screens / Pages

| Screen | Route | Entry file | Related capability |
| ------ | ----- | ---------- | ------------------ |
| PO list | `/purchase-orders` | `views/purchase-orders/index.php` | PO-CAP-005 |
| PO form | `/purchase-orders/create` | `views/purchase-orders/form.php` | PO-CAP-001 |
| PO detail | `/purchase-orders/{id}` | `views/purchase-orders/detail.php` | PO-CAP-002/003 |
| Goods receipt | `/purchase-orders/{id}/receive` | `views/purchase-orders/receive.php` | PO-CAP-004 |

## Dependencies

- **Other modules**: master-data, product, stock, platform

## Test Coverage

- `PurchaseOrderService` — unit (`PurchaseOrderServiceTest`, `OrderSearchTest`)
- Goods receipt end-to-end, partial, rollback — integration (`GoodsReceiptTest`, `ConcurrentGoodsIssueTest::aSecondReceiptNeverPlansFromAStaleOutstanding`)
- `public/assets/js/order-lines.js` — tidak ada test otomatis

## Known Gaps / Risks

- `order-lines.js` (tambah/hapus baris item) tanpa test otomatis; form tetap jalan tanpa JS.
- Format nomor order tidak konsisten: aplikasi membuat `PO-YYYYMMDD-####` / `SO-YYYYMMDD-####`, sedangkan seed (`database/generate-seed.php`) memakai `PO-2026-####` / `SO-2026-####`. Tidak berbahaya karena keduanya unik, tetapi terlihat berbeda saat demo.

## Change Log

- **2026-10-03**: Initial version generated from codebase survey.
