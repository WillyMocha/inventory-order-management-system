# Module: Product Catalog (`product`)

**Back to**: [00-overview.md](../00-overview.md) · **Last Updated**: 2026-10-04

## Summary

Katalog product (SKU, harga, reorder point, image), tampilan stock total dan per warehouse,
status low-stock, endpoint JSON availability, dan script low-stock di luar request cycle.

## Capabilities

- **PROD-CAP-001** — Membuat dan mengubah product (Admin), dengan angka ≥ 0. SKU dibuat otomatis oleh server
  dari urutan terakhir (`ProductService::nextSku()`), read-only di form, dan tidak berubah saat edit; nilai `sku`
  dari request diabaikan
- **PROD-CAP-002** — Menonaktifkan product (tidak pernah dihapus bila sudah dipakai order)
- **PROD-CAP-003** — Mengunggah image product (tipe dari isi file, nama acak, disimpan di luar document root)
- **PROD-CAP-004** — Mencari, memfilter (category, low stock), mengurutkan, dan mem-paginate katalog (semua role)
- **PROD-CAP-005** — Melihat stock total dan rincian per warehouse
- **PROD-CAP-006** — Menanyakan ketersediaan stock per warehouse lewat JSON API
- **PROD-CAP-007** — Menjalankan ringkasan low-stock dari CLI (JOB-01)

## Key Entities & Rules

- **PROD-ENT-001 Product** — sku (unik), name, category, unit, purchase_price, selling_price
  (`DECIMAL(15,2)`), reorder_point, image_path, is_active (`product`, `app/Entity/Product.php`)
- **PROD-ENT-002 ProductStock** (dibaca di sini, ditulis oleh [stock](stock.md)) — quantity ≥ 0 per
  (product, warehouse), UNIQUE pasangan
- Rule: low stock berarti total stock seluruh warehouse ≤ reorder_point
- Rule: image hanya JPEG/PNG/WebP (deteksi `finfo` + `getimagesize`), dengan batas ukuran dan nama
  `bin2hex(random_bytes(16))`; disajikan lewat controller

## API Surface

**Exposes**

| Kind | Name / Route | Role | Entry file |
| ---- | ------------ | ---- | ---------- |
| HTTP | `GET /products`, `GET /products/{id}` | semua role | [`app/Controller/ProductController.php`](../../../app/Controller/ProductController.php) |
| HTTP | `/products/create`, `POST /products`, `/products/{id}/edit`, `POST /products/{id}`, `POST /products/{id}/toggle-active` | Admin | idem |
| HTTP | `GET /products/{id}/image` | semua role | idem |
| JSON | `GET /api/products/{sku}/availability` | semua role; 200/401/404 JSON | [`app/Controller/Api/StockApiController.php`](../../../app/Controller/Api/StockApiController.php) |
| JSON | `GET /api/products/{productId}/warehouses/{warehouseId}/available` | semua role (panduan form SO) | idem |
| CLI | `php scripts/check-low-stock.php [--limit=N]` | operator; exit 0/1 | [`scripts/check-low-stock.php`](../../../scripts/check-low-stock.php) |

**Consumes**

- `master-data` — category dan warehouse aktif
- `platform` — `Request`, `Response::json`, `Paginator`; filesystem `storage/uploads`

## Data Flow

- Upload: form multipart → `ProductImageService::store` (`is_uploaded_file`, `finfo`, ukuran, `getimagesize`) → nama acak → `storage/uploads` → `updateImagePath`
- JOB-01: CLI → `config/container.php` → `ProductService::lowStock()` → `ProductRepositoryInterface::lowStock()` (query yang sama dengan dashboard) → tabel stdout

## Screens / Pages

| Screen | Route | Entry file | Related capability |
| ------ | ----- | ---------- | ------------------ |
| Product list | `/products` | `views/products/index.php` | PROD-CAP-004 |
| Product detail | `/products/{id}` | `views/products/detail.php` | PROD-CAP-005 |
| Product form | `/products/create`, `/products/{id}/edit` | `views/products/form.php` | PROD-CAP-001/003 |

## Dependencies

- **Other modules**: master-data, stock (angka stock), platform
- **External**: ekstensi PHP `fileinfo`

## Test Coverage

- `ProductService` — unit (`ProductServiceTest`, `ProductSearchTest`, 12/16 method; sisanya lookup/passthrough)
- `ProductImageService` — unit untuk validasi/nama/path; integration untuk read/delete/penolakan store (`ProductImageStorageTest`)
- JSON API — unit (`StockApiControllerTest`) dan integration (`StockApiTest`)
- JOB-01 — prosedur manual `docs/testing/low-stock-job.md`; aturannya lewat `ProductServiceTest`
- `public/assets/js/stock-lookup.js` — `node --test` (`tests/js/stock-lookup.test.mjs`)

## Known Gaps / Risks

- Jalur sukses `ProductImageService::store()` tidak dapat diuji dari CLI (`move_uploaded_file`) — tech-debt TD-3.
- `favicon.ico` 404 — known-bugs KB-2 (kosmetik, lintas halaman).

## Change Log

- **2026-10-04**: SKU product dibuat otomatis dan read-only (PROD-CAP-001; `nextSku()`,
  `ProductRepositoryInterface::highestSkuSequence()`).
- **2026-10-03**: Initial version generated from codebase survey.
