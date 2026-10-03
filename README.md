# Inventory & Order Management System

Aplikasi web untuk mencatat product, mengelola stock di beberapa warehouse, memproses
pembelian dari supplier (Purchase Order → goods receipt) dan penjualan ke customer
(Sales Order → approval → goods issue), dengan dashboard dan report per role.

Dibangun sebagai **modular monolith** server-rendered: PHP 8.4 tanpa framework, MySQL 8,
vanilla JavaScript tanpa build step. Tidak ada ORM, DI container, CSS framework, maupun
admin template — Composer hanya dipakai untuk autoload dan dev dependency.

> **Bahasa**: seluruh string UI berbahasa Inggris. Comment dan dokumentasi berbahasa
> Indonesia dengan istilah teknis tetap bahasa Inggris.

## Fitur

| Area | Isi |
| --- | --- |
| **Authentication** | Login dengan session, rate limit percobaan gagal, regenerasi session id, step-up re-auth sebelum aksi sensitif |
| **User management** | Admin membuat dan menonaktifkan user; tidak ada registrasi publik |
| **Master data** | Category, Warehouse, Supplier, Customer — dinonaktifkan, tidak pernah dihapus |
| **Product** | Katalog dengan SKU unik, harga beli/jual, reorder point, dan upload image |
| **Stock** | Quantity per warehouse, `stock_ledger` append-only, dan invariant `SUM(ledger) = product_stock` |
| **Purchase Order** | Draft → Ordered → PartiallyReceived → Received, dengan goods receipt bertahap |
| **Sales Order** | Draft → PendingApproval → Approved → Fulfilled, dengan approval dan goods issue |
| **Dashboard** | Tiga tampilan berbeda per role, seluruh angkanya dari query aggregation |
| **Report** | Export CSV stock movement, status Sales Order, dan status Purchase Order untuk rentang tanggal pilihan |
| **JSON API** | Ketersediaan stock per warehouse, dengan session auth yang sama dengan halaman |
| **Job** | Routine low-stock yang berjalan di luar request cycle |

## Role

| Role | Tanggung jawab |
| --- | --- |
| **Admin** | User management, master data, approval Sales Order, seluruh report |
| **Sales** | Membuat Sales Order — **tidak boleh approve**, termasuk order miliknya sendiri |
| **Warehouse Staff** | Goods receipt, goods issue, antrean fulfillment |

Pemisahan tanggung jawab ditegakkan **di server**, bukan dengan menyembunyikan tombol di UI.
`SalesOrderService::approve()` memeriksa role Admin **dan** `approved_by <> created_by`.

## Kebutuhan

- Docker dan Docker Compose
- Port `8080` (aplikasi) dan `3307` (MySQL) bebas di host

Tidak perlu memasang PHP maupun MySQL di host. Versi PHP dipatok **8.4 tepat** — tidak di
bawah, tidak di atas; image sudah di-pin dan front controller memuat runtime guard.

## Menjalankan

```bash
cp .env.example .env
docker compose up --build        # http://localhost:8080
```

Migration dan data seed diterapkan otomatis saat container pertama kali naik. Untuk
menerapkannya ulang secara manual:

```bash
docker compose exec app composer db:migrate   # file yang belum dijalankan
docker compose exec app composer db:reset     # hapus lalu bangun ulang dari nol
```

## Akun demo

Seluruh akun memakai password `Password123!`. Tidak ada registrasi publik — setiap akun
dibuat oleh Admin.

| Role | Email |
| --- | --- |
| Admin | `admin@ioms.test`, `admin2@ioms.test` |
| Sales | `sales1@ioms.test`, `sales2@ioms.test` |
| Warehouse Staff | `warehouse1@ioms.test`, `warehouse2@ioms.test` |

Kredensial di atas hanya untuk data seed demo di lingkungan lokal.

Ada dua akun Admin karena approver Sales Order tidak boleh sama dengan pembuatnya — aturan ini
juga berlaku untuk Admin. Sales Order yang dibuat `admin@ioms.test` disetujui oleh
`admin2@ioms.test`, dan sebaliknya. Lihat
[`docs/planning/decisions.md`](docs/planning/decisions.md).

## Test dan quality gate

Seluruh gate dalam **satu perintah**: membangun ulang schema database test, lalu unit,
integration, PHPStan, dan PHPCS. Perintah ini berhenti pada kegagalan pertama dan keluar
dengan kode bukan nol, sehingga tidak ada suite yang terlewat diam-diam:

```bash
docker compose exec app composer check
```

Atau satu perintah per suite:

```bash
docker compose exec app composer test              # unit + integration
docker compose exec app composer test:unit         # tanpa DB, session, atau network
docker compose exec app composer test:integration  # MySQL 8 sungguhan
docker compose exec app composer analyse           # PHPStan level 6
docker compose exec app composer cs                # PHP_CodeSniffer PSR-12
```

Test JavaScript (`node --test` bawaan Node, tanpa dependency) dijalankan lewat image Node
resmi, sehingga tidak perlu Node di komputer:

```bash
docker run --rm -v "$PWD":/app -w /app node:22-alpine node --test "tests/js/*.test.mjs"
```

Integration test memakai database terpisah. Schema-nya dibangun **sekali** dengan:

```bash
docker compose exec app composer db:test
```

Hasil terakhir: **505 test PHP (1444 assertion) dan 11 test JavaScript, seluruhnya lulus** — lihat
[`docs/testing/test-results.md`](docs/testing/test-results.md).

## Low-stock check di luar request cycle (JOB-01)

Routine mandiri yang meringkas product pada atau di bawah reorder point. Berjalan tanpa HTTP,
tanpa session, dan memakai `ProductService` **yang sama** dengan dashboard — sehingga script
dan layar tidak bisa memberi jawaban berbeda.

```bash
docker compose exec app php scripts/check-low-stock.php
docker compose exec app php scripts/check-low-stock.php --limit=25
```

Exit code `0` berarti berhasil dijalankan, termasuk saat ada product yang menipis; `1` berarti
gagal dijalankan. Tidak ada cron yang dipasang di image — penjadwalan otomatis berada di luar
scope. Prosedur verifikasi lengkap ada di
[`docs/testing/low-stock-job.md`](docs/testing/low-stock-job.md).

## Dokumentasi

| Dokumen | Isi |
| --- | --- |
| [`specs/001-inventory-order-management/spec.md`](specs/001-inventory-order-management/spec.md) | Requirement — user story, FR, NFR, constraint |
| [`specs/001-inventory-order-management/plan.md`](specs/001-inventory-order-management/plan.md) | Rencana implementasi dan struktur folder |
| [`specs/001-inventory-order-management/research.md`](specs/001-inventory-order-management/research.md) | Keputusan desain beserta alternatif yang ditolak |
| [`specs/001-inventory-order-management/data-model.md`](specs/001-inventory-order-management/data-model.md) | Schema, mirror dari resource model sumber |
| [`specs/001-inventory-order-management/contracts/`](specs/001-inventory-order-management/contracts/) | Route table, matriks authorization, dan JSON API |
| [`specs/001-inventory-order-management/quickstart.md`](specs/001-inventory-order-management/quickstart.md) | Prosedur verifikasi lengkap dan skenario demo |
| [`docs/planning/`](docs/planning/) | User story, scope, backlog, ERD, class diagram initial, dan [catatan keputusan](docs/planning/decisions.md) atas requirement yang ambigu |
| [`docs/architecture/`](docs/architecture/) | Class diagram as-built dan dua ADR |
| [`docs/quality/`](docs/quality/) | Refactor log, tech debt, kritik desain, laporan PHPStan dan PHPCS |
| [`docs/testing/`](docs/testing/) | Hasil test, pemetaan coverage, sweep jalur kegagalan |

## Keterbatasan yang diketahui

Dicatat apa adanya. Rinciannya di [`docs/quality/tech-debt.md`](docs/quality/tech-debt.md).

- **Tidak ada CI.** Seluruh gate dijalankan manual lewat `composer check` — CI berada di luar
  scope brief (§4.3). Selama enam phase integration suite tidak pernah dijalankan dan
  menyembunyikan bug yang membuat setiap goods issue gagal; `composer check` kini membangun
  schema test lebih dulu agar hal itu tidak terulang.
- **`validation.js` belum memiliki test otomatis.** `stock-lookup.js` sudah diuji dengan
  `node --test`; keduanya murni progressive enhancement — aplikasi tetap berfungsi penuh tanpa
  JavaScript.
- **Jalur sukses upload image tidak teruji otomatis.** `move_uploaded_file()` hanya menerima
  upload HTTP sungguhan, sehingga tidak dapat dijalankan dari CLI. Penolakan file palsu,
  pembacaan, dan penghapusan file teruji di `ProductImageStorageTest`.
- **Tidak ada penjadwalan otomatis.** Routine low-stock dijalankan manual, sesuai scope.
- **Tidak ada password reset.** Brief tidak menyediakan registrasi publik; Admin yang
  mengatur ulang password.
- **`.env.example` memuat kredensial development.** Hanya memberi akses ke MySQL di dalam
  container. Untuk deployment sungguhan nilainya wajib diganti.

## Atribusi

Icon berasal dari [Lucide](https://lucide.dev), dilisensikan **ISC**. Sprite SVG-nya disajikan
sendiri dari `public/assets/icons/lucide-sprite.svg` — tidak ada CDN dan tidak ada icon font.
