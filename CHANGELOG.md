# Changelog

Seluruh perubahan penting pada project ini dicatat di file ini. Formatnya mengikuti
[Keep a Changelog](https://keepachangelog.com/id-ID/1.1.0/), dan penomoran versinya mengikuti
[Semantic Versioning](https://semver.org/lang/id/).

## [1.0.0] — 2026-10-04

Rilis pertama Inventory & Order Management System (IOMS): aplikasi web untuk mencatat product,
mengelola stock di beberapa warehouse, memproses pembelian dari supplier (Purchase Order → goods
receipt) dan penjualan ke customer (Sales Order → approval → goods issue), dengan dashboard dan
report per role.

### Sorotan

- **Stock tidak pernah bisa "hilang" tanpa jejak.** Setiap perubahan stock lewat satu jalur
  (`StockService`) yang menulis `stock_ledger` dan `product_stock` dalam satu transaction.
  Invariant `SUM(stock_ledger.quantity) = product_stock.quantity` dijaga untuk setiap pasangan
  product–warehouse, dan ledger ditolak MySQL bila di-`UPDATE` atau di-`DELETE` (trigger).
- **Oversell tidak dapat direproduksi.** Goods issue dan goods receipt mengunci baris stock
  dengan `SELECT … FOR UPDATE` dalam urutan tetap (ADR-002); dibuktikan test dua connection
  MySQL sungguhan.
- **Segregation of duties ditegakkan di server.** Sales tidak dapat meng-approve order apa pun,
  dan approver selalu berbeda dari pembuat order — termasuk untuk Admin (D-01).
- **Dashboard dan report dihitung dari data, bukan angka statis**, termasuk grafik stock movement
  30 hari yang dibaca langsung dari ledger.

### Fitur

**Akses dan akun**
- Login dan logout berbasis session, dengan batas 5 percobaan gagal per 15 menit dan pesan gagal
  yang seragam (tidak membocorkan email mana yang terdaftar).
- Tiga role — **Admin**, **Sales**, **Warehouse Staff** — dengan guard *deny by default*.
- Halaman profil sendiri dan ganti password sendiri untuk semua role; session divalidasi ulang
  setiap request, sehingga user yang dinonaktifkan langsung kehilangan akses.
- User management oleh Admin; tidak ada registrasi publik.

**Master data dan product**
- Category, Warehouse, Supplier, Customer — tidak pernah dihapus; Warehouse, Supplier, dan
  Customer dapat dinonaktifkan.
- Katalog product dengan SKU otomatis (read-only), harga beli/jual, reorder point, dan upload
  image yang divalidasi dari isi file serta disimpan di luar document root.
- Pencarian, filter, sort, dan pagination di setiap daftar.

**Stock**
- Quantity per warehouse dengan riwayat lengkap di `stock_ledger` (append-only).
- Koreksi stock dari hasil hitung fisik (Adjustment) oleh Admin dan Warehouse Staff, dengan
  alasan wajib dan aman dari race condition.

**Purchase Order**
- Alur `Draft → Ordered → PartiallyReceived → Received`, atau `Cancelled`.
- Goods receipt bertahap dengan outstanding per line.
- PO Draft dapat diedit: Admin untuk PO siapa pun, Warehouse Staff untuk PO buatannya (D-04).

**Sales Order**
- Alur `Draft → PendingApproval → Approved → Fulfilled`, atau `Cancelled` sebelum Fulfilled.
- Approval dan reject oleh Admin lain; goods issue oleh Admin atau Warehouse Staff.
- SO Draft dapat diedit pembuatnya saja (D-04).
- Sales hanya melihat order miliknya; order Sales lain menghasilkan 404, bukan 403.

**Dashboard dan report**
- Dashboard berbeda per role: nilai inventory, product di bawah reorder point, antrean approval,
  ringkasan status SO/PO, antrean goods receipt dan goods issue.
- Export CSV dengan rentang tanggal (maksimal 366 hari), dibatasi per role sesuai brief:
  Admin semua, Sales status order miliknya, Warehouse Staff report stock.
- Angka di halaman report dihitung dari baris yang sama persis dengan isi file.

**JSON API dan job**
- `GET /api/products/{sku}/availability` — stock per warehouse; 200 / 401 / 404 sebagai JSON,
  dengan session auth yang sama dengan halaman.
- `GET /api/dashboard/low-stock` (Admin, Warehouse Staff).
- `php scripts/check-low-stock.php` — ringkasan product di bawah reorder point, berjalan di luar
  request cycle.

**Antarmuka**
- Responsive sampai lebar 360px, dapat dioperasikan dengan keyboard, kontras WCAG AA.
- Dialog konfirmasi `<dialog>` yang aksesibel sebelum aksi penting seperti submit, cancel,
  approve/reject, goods receipt/issue, koreksi stock, dan menonaktifkan record.
- Tanpa framework, CSS framework, atau library JavaScript; seluruh JavaScript bersifat
  progressive enhancement.

### Bonus

- **Grafik SVG buatan sendiri** di dashboard Admin dan Warehouse Staff: unit masuk/keluar per
  hari selama 30 hari terakhir dari `stock_ledger`, sama persis dengan export CSV per hari
  ([spec 005](specs/005-stock-movement-chart/spec.md)).
- **Integration test tambahan** — 264 test terhadap MySQL 8, jauh di atas minimum 3 (TEST-02).

### Kualitas dan bukti

| Pemeriksaan | Hasil |
| --- | --- |
| Unit test (tanpa DB, session, network) | 491 test, 1398 assertion — lulus |
| Integration test (MySQL 8 sungguhan) | 264 test, 981 assertion — lulus |
| JavaScript test (`node --test`) | 18 test — lulus |
| PHPStan level 6 | 0 error, tanpa baseline dan tanpa `@phpstan-ignore` |
| PHP_CodeSniffer PSR-12 | 0 error, 0 warning (170 file) |
| Method repository MySQL yang dieksekusi integration suite | 126 dari 126 |

Satu perintah menjalankan seluruh gate: `docker compose exec app composer check`.
Laporan coverage untuk SonarQube: `docker compose exec app composer test:coverage`.

Dokumen design dan evidence: class diagram initial dan as-built, ERD, ADR-001 (repository
abstraction) dan ADR-002 (concurrency control), catatan keputusan D-01…D-04, refactor log,
register tech debt, kritik desain, laporan static analysis, dan
[AI usage log](ai-usage-log.md). Lihat bagian **Dokumentasi** di [README](README.md).

### Instalasi

Hanya membutuhkan Docker dan Docker Compose.

```bash
cp .env.example .env
docker compose up --build        # http://localhost:8080
```

Migration dan data demo diterapkan otomatis saat container pertama kali naik. Akun demo
(password `Password123!`): `admin@ioms.test`, `admin2@ioms.test`, `sales1@ioms.test`,
`sales2@ioms.test`, `warehouse1@ioms.test`, `warehouse2@ioms.test`.

Teknologi: PHP 8.4 (dipatok tepat), MySQL 8.0, Apache, PHPUnit 11.5, PHPStan 2, PHP_CodeSniffer 3.

### Keterbatasan yang diketahui

- Tidak ada CI; gate dijalankan manual lewat `composer check`.
- Routine low-stock dijalankan manual; tidak ada penjadwalan otomatis (di luar scope brief).
- Tidak ada password reset mandiri; Admin yang mengatur ulang password.
- Satu "hari" pada grafik dan report dihitung dalam UTC (07:00–07:00 WIB).
- Sembilan file di `app/` melewati batas 300 baris SonarQube; seluruh class ≤ 20 method
  (tech debt TD-11).
- `.env.example` memuat kredensial development untuk MySQL di dalam container; wajib diganti
  untuk deployment sungguhan.
- Bug kosmetik yang masih terbuka: [KB-1…KB-3](docs/testing/known-bugs.md).

### Interpretasi brief yang belum dikonfirmasi trainer

Dicatat di [`docs/planning/decisions.md`](docs/planning/decisions.md), lengkap dengan perubahan
yang diperlukan bila tafsirannya ditolak:

- **D-01** — Admin juga tidak boleh meng-approve Sales Order buatannya sendiri.
- **D-02** — Warehouse Staff boleh membuat dan mengajukan Purchase Order sampai Ordered.
- **D-03** — Export "status order" mencakup Sales Order dan Purchase Order.
- **D-04** — Edit order Draft adalah tambahan atas permintaan owner, di luar brief.

[1.0.0]: https://github.com/WillyMocha/inventory-order-management-system/releases/tag/v1.0.0
