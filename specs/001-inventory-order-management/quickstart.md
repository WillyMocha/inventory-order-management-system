# Quickstart — Inventory & Order Management System

**Feature**: `001-inventory-order-management`
**Tanggal**: 2026-09-10

Dokumen ini adalah prosedur yang akan disalin ke `README.md` saat implementasi. Target:
seorang assessor dapat menjalankan aplikasi dari folder bersih dalam waktu di bawah 15 menit
tanpa bertanya (SC-001).

> Catatan bahasa: dokumentasi ditulis dalam bahasa Indonesia, istilah teknis tetap bahasa
> Inggris, dan seluruh UI aplikasi berbahasa Inggris (constitution v1.1.0, spec C-007).

---

## Prasyarat

- Docker Desktop atau Docker Engine dengan Docker Compose v2.
- Port `8080` (aplikasi) dan `3307` (MySQL) bebas di host.
- Tidak ada kebutuhan lain. PHP 8.4, MySQL 8, Composer, PHPUnit, dan PHPStan seluruhnya
  berjalan di dalam container — tidak perlu install apa pun di host.

## 1. Menjalankan aplikasi

```bash
cp .env.example .env
docker compose up -d        # build image bila belum ada; app menunggu healthcheck MySQL
```

Container `app` menjalankan migration otomatis sebelum Apache (`docker/entrypoint.sh` →
`database/migrate.php`). Hanya file yang belum tercatat di `schema_migration` yang diterapkan,
jadi schema dan seed masuk sekali saat first boot, dan restart tidak menyentuh data.

Image `ioms-app:local` dipakai ulang di setiap `up`. Rebuild (`docker compose up -d --build`)
hanya perlu setelah `Dockerfile`, `composer.lock`, atau `docker/entrypoint.sh` berubah;
perubahan kode tidak butuh rebuild karena source di-bind-mount. Prosedur ini diuji dari salinan
bersih pada 2026-10-03.

Buka **http://localhost:8080**.

## 2. Akun demo

Seluruh akun memakai password `Password123!`. Tidak ada registrasi publik — seluruh akun
dibuat oleh Admin (USR-01).

| Role | Email | Dipakai untuk mendemokan |
| --- | --- | --- |
| Admin | `admin@ioms.test` | User management, master data, approval Sales Order |
| Admin | `admin2@ioms.test` | Approve Sales Order yang dibuat `admin@ioms.test` — approver tidak boleh pembuatnya (`docs/planning/decisions.md` D-01) |
| Sales | `sales1@ioms.test` | Membuat Sales Order; **tidak dapat approve** |
| Sales | `sales2@ioms.test` | Membuktikan Sales hanya melihat order miliknya |
| Warehouse Staff | `warehouse1@ioms.test` | Goods receipt dan goods issue |
| Warehouse Staff | `warehouse2@ioms.test` | Antrean fulfillment |

Seed juga menyediakan 2 warehouse, 30 product dengan reorder point bervariasi (beberapa di
bawah reorder point), dan 25+ order gabungan PO/SO dengan status bervariasi termasuk
`PendingApproval` dan `Cancelled` (§7.1, NFR-011).

## 3. Menjalankan test

Seluruh gate dalam satu perintah (schema test, unit, integration, PHPStan, PHPCS; berhenti
pada kegagalan pertama):

```bash
docker compose exec app composer check
```

Atau per suite:

```bash
# Unit test — tanpa database, tanpa session, tanpa network
docker compose exec app composer test:unit

# Integration test — MySQL 8 sungguhan di dalam Docker
docker compose exec app composer test:integration

# Keduanya sekaligus
docker compose exec app composer test
```

## 4. Static analysis

```bash
docker compose exec app composer analyse    # PHPStan level 6
docker compose exec app composer cs         # PHP_CodeSniffer PSR-12
```

Laporan ditulis ke `docs/quality/`. Gate-nya adalah nol critical error; warning yang tersisa
dijelaskan tertulis di direktori yang sama (TEST-03, constitution Principle II).

## 5. Scheduled script (JOB-01)

```bash
docker compose exec app php scripts/check-low-stock.php
```

Mencetak ringkasan product yang berada pada atau di bawah reorder point. Berjalan di luar
web request cycle dan memakai `ProductService` yang sama dengan dashboard — tidak dijadwalkan
otomatis, sesuai §4.3.

---

## Skenario verifikasi (urutan demo yang disarankan)

Delapan langkah berikut menyentuh seluruh requirement wajib. Urutan ini juga cocok dipakai
saat technical defense (§8.1).

### 1. Authentication & session (AUTH-01, AUTH-02)

1. Buka `http://localhost:8080/products` tanpa login → dialihkan ke `/login`.
2. Login dengan password salah → pesan seragam, tidak menjelaskan bagian mana yang salah.
3. Login sebagai ketiga role → masing-masing mendarat di dashboard yang berbeda.
4. Logout, lalu tekan tombol back browser → tetap tidak dapat membuka halaman terlindungi.

### 2. Segregation of duties (§1.2, FR-018, SC-005) — bagian paling penting

1. Login `sales1@ioms.test`, buat Sales Order, submit → status `PendingApproval`.
2. Masih sebagai `sales1`, coba approve order **miliknya sendiri** lewat UI → tombol tidak
   tersedia.
3. Panggil endpoint approve secara langsung untuk membuktikan penegakan di server:
   ```bash
   curl -i -X POST http://localhost:8080/sales-orders/1/approve \
     -b "IOMS_SESSION=<session sales1>" \
     -d "csrf_token=<token>"
   # Harapan: 403 — ditolak di server, bukan hanya disembunyikan di UI
   ```
4. Login `sales2@ioms.test` → order milik `sales1` tidak muncul di daftar; membuka URL-nya
   secara langsung menghasilkan **404**, bukan 403 (agar keberadaan record tidak bocor).
5. Login `admin@ioms.test` → approve berhasil, `approved_by` tercatat.

### 3. Purchase Order & goods receipt (PO-01)

1. Sebagai Admin atau Warehouse Staff, buat PO berisi 2 item, lalu submit → `Ordered`.
2. Catat **partial receipt** untuk sebagian qty → status menjadi `PartiallyReceived`, stock
   naik tepat sebesar qty yang diterima, outstanding qty tetap tercatat.
3. Coba menerima melebihi outstanding qty → ditolak (A-005).
4. Terima sisanya → status `Received`.
5. Buka riwayat movement pada order tersebut → terdapat baris `Receipt` per line.

### 4. Goods issue & pencegahan oversell (SO-01, ARCH-02, SC-003)

1. Sebagai Warehouse Staff, coba goods issue untuk order yang **belum** `Approved` → ditolak.
2. Coba goods issue melebihi stock tersedia → ditolak, stock tidak berubah sama sekali.
3. Goods issue normal → stock turun, baris `Issue` tertulis, order menjadi `Fulfilled`.
4. Skenario concurrency terkontrol:
   ```bash
   docker compose exec app composer test:integration -- --filter ConcurrentGoodsIssue
   ```
   Test membuka dua connection sungguhan: connection pertama menahan lock, connection kedua
   terbukti menunggu, lalu setelah commit pertama, request kedua ditolak. Stock tidak pernah
   negatif dan tidak ada update yang saling menimpa.

### 5. Konsistensi ledger (NFR-002, SC-004)

```sql
-- Harus mengembalikan 0 baris. Setiap angka stock harus dapat ditelusuri ke ledger.
SELECT ps.product_id, ps.warehouse_id, ps.quantity, COALESCE(SUM(sl.quantity), 0) AS ledger_sum
FROM product_stock ps
LEFT JOIN stock_ledger sl
  ON sl.product_id = ps.product_id AND sl.warehouse_id = ps.warehouse_id
GROUP BY ps.product_id, ps.warehouse_id, ps.quantity
HAVING ps.quantity <> ledger_sum;
```

### 6. Search, filter, sort & pagination (FIND-01)

1. Daftar product: cari berdasarkan nama, lalu berdasarkan SKU.
2. Filter kategori + status stock (low stock / normal) secara bersamaan.
3. Pindah ke halaman 2 lalu 3 → 10 baris per halaman, filter tetap aktif, URL tetap dapat
   dibagikan.
4. Daftar order: filter status, urutkan tanggal naik lalu turun.

### 7. Dashboard, report & JSON API (DASH-01, REPORT-01, API-01)

1. Bandingkan dashboard ketiga role → isinya berbeda sesuai hak akses.
2. Ubah satu transaksi, muat ulang dashboard → angka ikut berubah (bukan angka statis).
3. Export CSV untuk dua rentang tanggal berbeda → isinya berbeda dan cocok dengan dashboard.
4. JSON API, tiga kondisi:
   ```bash
   # 200 — dengan session
   curl -i -b "IOMS_SESSION=<session>" \
     http://localhost:8080/api/products/SKU-000001/availability

   # 401 sebagai JSON, bukan halaman HTML login
   curl -i http://localhost:8080/api/products/SKU-000001/availability

   # 404 untuk SKU yang tidak ada
   curl -i -b "IOMS_SESSION=<session>" \
     http://localhost:8080/api/products/SKU-TIDAK-ADA/availability
   ```

### 8. Validation, error handling & responsive (VAL-01, ERR-01, UI-01)

1. Submit form product dengan reorder point negatif → ditolak, data tidak tersimpan, input
   yang sudah diisi dipertahankan.
2. Upload file bertipe tidak valid sebagai image product → ditolak.
3. Buka URL record yang tidak ada → halaman 404 yang aman, tanpa stack trace.
4. Kecilkan browser ke lebar 360px → login, dashboard, daftar, detail, dan form tetap dapat
   digunakan, navigasi dan tabel tidak terpotong.

---

## Troubleshooting

| Gejala | Penyebab & solusi |
| --- | --- |
| `port is already allocated` | Ubah port host pada `.env` (`APP_PORT`, `DB_HOST_PORT`), lalu `docker compose up -d` lagi |
| `localhost:8080` belum merespons saat pertama kali start | Image sedang di-build dan MySQL masih menginisialisasi volume. Service `app` menunggu healthcheck MySQL (lewat TCP), lalu menjalankan migration. Pantau dengan `docker compose logs -f app` sampai muncul `migration diterapkan` |
| Container `app` berhenti dan log menunjukkan `GAGAL pada …sql` | Migration gagal, dan aplikasi sengaja tidak dijalankan di atas schema setengah jadi. Perbaiki penyebabnya, lalu `docker compose up -d` lagi |
| Perubahan `Dockerfile` atau `docker/entrypoint.sh` tidak berlaku | Image lama masih dipakai ulang. Jalankan `docker compose up -d --build` |
| Integration test gagal seluruhnya | Schema database test belum dibangun. Jalankan `docker compose exec app composer db:test` (atau `composer check`, yang membangunnya lebih dulu). **Bukan** `db:reset` — perintah itu membangun ulang database demo |
| Ingin mengulang dari data bersih | `docker compose down -v`, lalu `docker compose up -d` — flag `-v` menghapus volume database, dan migration + seed berjalan lagi otomatis |
| Ingin memastikan versi PHP | `docker compose exec app php -v` → harus 8.4.x (spec C-001) |
