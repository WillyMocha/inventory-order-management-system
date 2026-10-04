# Module: Master Data (`master-data`)

**Back to**: [00-overview.md](../00-overview.md) · **Last Updated**: 2026-10-03

## Summary

Data referensi yang dipakai alur transaksi: Category, Warehouse, Supplier, dan Customer. Data ini
dinonaktifkan, tidak pernah dihapus.

## Capabilities

- **MD-CAP-001** — Mengelola category (Admin)
- **MD-CAP-002** — Mengelola warehouse dan status aktifnya (Admin; Warehouse Staff hanya melihat)
- **MD-CAP-003** — Mengelola supplier dan status aktifnya (Admin)
- **MD-CAP-004** — Mengelola customer dan status aktifnya (Admin; Sales melihat daftar untuk membuat SO)
- **MD-CAP-005** — Menjelaskan mengapa record yang sudah dipakai order hanya dapat dinonaktifkan

## Key Entities & Rules

- **MD-ENT-001 Category** — name (unik), description (`category`)
- **MD-ENT-002 Warehouse** — name, location, is_active (`warehouse`)
- **MD-ENT-003 Supplier** — name, contact, address, is_active (`supplier`)
- **MD-ENT-004 Customer** — name, contact, address, is_active (`customer`). Entity terpisah dari
  Supplier, walau field-nya identik
- Rule: FK ke order memakai `ON DELETE RESTRICT`; `isReferencedByOrder()` dipakai UI untuk
  menjelaskan deaktivasi
- Rule: hanya record aktif yang muncul di pilihan form order

## API Surface

**Exposes**

| Kind | Name / Route | Role | Entry file |
| ---- | ------------ | ---- | ---------- |
| HTTP | `/categories` (index, create, store, edit, update) | Admin | `app/Controller/CategoryController.php` |
| HTTP | `/warehouses` (+ `toggle-active`) | index: Admin, WS · tulis: Admin | `app/Controller/WarehouseController.php` |
| HTTP | `/suppliers` (+ `toggle-active`) | Admin | `app/Controller/SupplierController.php` |
| HTTP | `/customers` (+ `toggle-active`) | index: Admin, Sales · tulis: Admin | `app/Controller/CustomerController.php` |
| PHP | `MasterDataService` (category, warehouse) | — | [`app/Service/MasterDataService.php`](../../../app/Service/MasterDataService.php) |
| PHP | `PartyService` (supplier, customer) | — | [`app/Service/PartyService.php`](../../../app/Service/PartyService.php) |

**Consumes**

- `platform` — repository MySQL, `Validator`, `Request::queryState`, `Paginator`

## Data Flow

- Nonaktifkan: Admin → `toggle-active` → Service membalik `is_active` → record hilang dari pilihan form, riwayat order tetap utuh

## Screens / Pages

| Screen | Route | Entry file | Related capability |
| ------ | ----- | ---------- | ------------------ |
| Categories | `/categories` | `views/categories/{index,form}.php` | MD-CAP-001 |
| Warehouses | `/warehouses` | `views/warehouses/{index,form}.php` | MD-CAP-002 |
| Suppliers | `/suppliers` | `views/suppliers/{index,form}.php` (`views/layout/_party-*.php`) | MD-CAP-003 |
| Customers | `/customers` | `views/customers/{index,form}.php` (`views/layout/_party-*.php`) | MD-CAP-004 |

## Dependencies

- **Other modules**: platform. Dipakai oleh product, purchase-order, dan sales-order.

## Test Coverage

- `MasterDataService`, `PartyService` — unit (`MasterDataServiceTest`, `PartyServiceTest`)
- SQL repository (search, countBy, setActive, isReferencedByOrder) — integration
  (`RepositorySearchTest`, `RepositoryCoverageTest`)

## Known Gaps / Risks

- Supplier dan Customer berbagi aturan validasi lewat `PartyService` dan partial view; sebuah
  perubahan pada satu party mudah ikut mengenai yang lain.

## Change Log

- **2026-10-03**: Initial version generated from codebase survey.
