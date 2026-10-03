# CLAUDE.md

Project context for AI agents (Claude Code reads this automatically). Fill in the
sections below — human-written context is more reliable than auto-generated.

> Bahasa: dokumen ini mengikuti konvensi project — prosa bahasa Indonesia, istilah teknis
> tetap bahasa Inggris. Seluruh UI aplikasi berbahasa Inggris.

## Overview

Inventory & Order Management System — aplikasi web untuk mencatat product, mengelola stock
di beberapa warehouse, memproses pembelian dari supplier (Purchase Order → goods receipt) dan
penjualan ke customer (Sales Order → approval → goods issue), dengan dashboard dan report
per role.

Tiga role: **Admin**, **Sales**, **Warehouse Staff**. Tanggung jawab sengaja dipisah —
Sales tidak boleh approve order, termasuk order miliknya sendiri.

Dokumen acuan resmi ada di `specs/001-inventory-order-management/`:

| Dokumen | Isi |
| --- | --- |
| `spec.md` | Requirement — 8 user story, 31 FR, 11 NFR, 7 constraint |
| `plan.md` | Rencana implementasi, struktur folder, diagram |
| `research.md` | 14 keputusan desain beserta alternatif yang ditolak |
| `data-model.md` | Schema — **mirror** dari resource model sumber |
| `contracts/http-routes.md` | Route table + matriks authorization (sumber kebenaran) |
| `contracts/openapi.yaml` | JSON API surface |
| `inputs/project-brief-resource-model.md` | Digest verbatim dari Project Brief PDF |

`.rudis/memory/constitution.md` (v1.1.0) bersifat mengikat dan mengalahkan preferensi lain.

## Architecture

Standalone modular monolith, server-rendered. **Controller → Service → Repository**, arah
dependency satu arah, dengan **dependency inversion pada boundary repository**.

```
public/index.php  →  Router  →  Authorization guard  →  Controller  →  Service  →  RepositoryInterface
                                                                                    ├── Mysql*Repository (PDO)
                                                                                    └── InMemory*Repository (test)
```

- `app/Controller` — HTTP saja. Membaca request, memanggil Service, memilih view.
- `app/Service` — seluruh business rule. **Inti yang di-unit-test.**
- `app/Repository` — interface + implementasi MySQL dan in-memory.
- `app/Entity` — domain state dan domain rule. Tidak ada SQL, HTTP, atau rendering.
- `app/Support` — plumbing (Router, View, Database, Session, Csrf, Authorization). Tanpa
  business rule.

Dua alur paling kritikal:

1. **Goods issue** (`StockService`) — di dalam satu transaction: `SELECT ... FOR UPDATE` pada
   baris `product_stock`, verifikasi kecukupan, tulis `stock_ledger`, kurangi stock, commit.
   Lock diambil urut `product_id` lalu `warehouse_id` agar tidak deadlock. Lihat ADR-002.
2. **Approval Sales Order** (`SalesOrderService`) — memeriksa role Admin **dan**
   `approved_by <> created_by`. Ditegakkan di server, bukan disembunyikan di UI.

## Conventions

- **PHP 8.4 tepat** — tidak boleh di bawah atau di atas. Image di-pin, ada runtime guard.
- `declare(strict_types=1);` **wajib di setiap file PHP**, termasuk test dan script.
- Seluruh parameter, return type, dan property wajib bertipe. Hindari `mixed`.
- Service menerima dependency lewat **constructor injection**. Dilarang keras: `new PDO()`,
  `$_SESSION`, `$_POST`, atau superglobal apa pun di dalam Service maupun Entity.
- Acting user **di-pass sebagai argument** ke Service, tidak pernah dibaca dari session di
  dalam Service — ini yang membuat aturan approval bisa di-unit-test tanpa session.
- Dilarang: framework backend/frontend, ORM, DI container, CSS framework, JS framework,
  admin template. Composer hanya untuk autoload dan dev dependency.
- Seluruh query memakai **prepared statement**; `ATTR_EMULATE_PREPARES = false`.
- Seluruh output HTML melewati helper `e()`. Tidak ada `echo $var` mentah di template.
- Stock **tidak pernah** diubah selain lewat service yang sekaligus menulis `stock_ledger`
  dalam transaction yang sama.
- **Bahasa**: string UI bahasa Inggris; comment dan dokumentasi bahasa Indonesia; istilah
  teknis tetap bahasa Inggris; identifier dan commit message bahasa Inggris.
  Contoh: `/** Memproses goods issue untuk Sales Order yang sudah Approved. */`
- Uang: `DECIMAL(15,2)`, mata uang IDR saja, tampil sebagai `Rp 1.250.000` (tanpa desimal).

## Build, run, test

Semua dijalankan di dalam Docker; tidak perlu install PHP atau MySQL di host.

```bash
cp .env.example .env
docker compose up --build            # http://localhost:8080

docker compose exec app composer test              # unit + integration
docker compose exec app composer test:unit         # tanpa DB/session/network
docker compose exec app composer test:integration  # MySQL 8 sungguhan
docker compose exec app composer analyse           # PHPStan level 6
docker compose exec app composer cs                # PHP_CodeSniffer PSR-12
docker compose exec app php scripts/check-low-stock.php   # JOB-01
docker compose exec app php -v                     # harus 8.4.x
```

Prosedur verifikasi lengkap dan akun demo ada di
`specs/001-inventory-order-management/quickstart.md`.

## Risky / sensitive areas

- **`StockService` (goods issue/receipt)** — satu-satunya jalan stock boleh berubah. Jika
  transaction atau `FOR UPDATE` dilepas, oversell bisa direproduksi assessor dan itu
  **critical failure**. Invariant: `SUM(stock_ledger.quantity) = product_stock.quantity`
  untuk setiap pasangan (product, warehouse).
- **`SalesOrderService::approve()`** — segregation of duties. Sales tidak boleh approve order
  apa pun, termasuk miliknya. Wajib ditegakkan di server; menyembunyikan tombol saja
  **critical failure**.
- **Authorization guard** — deny by default. Route tanpa role eksplisit tidak dapat diakses
  siapa pun. Resource di luar scope pemanggil menghasilkan **404, bukan 403**, agar
  keberadaan record tidak bocor.
- **Upload image product** — tipe ditentukan dari isi file (`finfo`), bukan dari nama atau
  header client. Nama file acak. Disimpan **di luar document root** dan disajikan lewat
  controller.
- **Ledger bersifat append-only** — jangan pernah UPDATE atau DELETE baris `stock_ledger`.
- **Jangan pernah** memasukkan `.env`, credential aktif, token, atau data PII ke repository
  maupun history.

## How agents should work here

- Discovery-first: read and confirm understanding before changing code.
- Keep changes in scope; state what is OUT OF SCOPE; verify end-to-end.
- Prefer the smallest viable change; ask for approval on the diff.
- **Jangan over-engineering** (spec C-003). Layer, pattern, atau dependency tambahan yang
  tidak menyelesaikan masalah nyata dinilai negatif — setara dengan kode berantakan. Bila
  tetap perlu, catat justifikasinya di tabel Complexity Tracking pada `plan.md`.
- **Setiap use case wajib punya unit test** (constitution Principle III). Use case tanpa unit
  test berarti task belum selesai, bukan selesai sebagian.
- Ikuti `data-model.md` apa adanya — schema **mirror** dari sumber. Jangan merge, rename,
  atau menyederhanakan resource tanpa persetujuan eksplisit.
