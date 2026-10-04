# Bug yang diketahui

Brief §7 (`docs/testing/`). Daftar ini hanya memuat **cacat perilaku yang sudah diamati
langsung** pada aplikasi yang berjalan, beserta cara mereproduksinya. Keterbatasan desain yang
disengaja tidak dicatat di sini, melainkan di
[`../quality/tech-debt.md`](../quality/tech-debt.md) dan bagian *Keterbatasan yang diketahui*
pada [`README.md`](../../README.md).

Diperbarui **2026-10-03**. Tidak ada bug yang diketahui pada alur inti: login, Purchase
Order, Sales Order, goods receipt, goods issue, stock ledger, dan otorisasi. Seluruh bug di
bawah berderajat rendah.

| ID | Bug | Derajat | Status |
| --- | --- | --- | --- |
| KB-1 | Tanggal order terpotong di tanda hubung pada 360px | Rendah (kosmetik) | Terbuka |
| KB-2 | `favicon.ico` membalas 404 | Rendah (kosmetik) | Terbuka |
| KB-3 | Sort key tak dikenal ikut terbawa di link pagination | Rendah | Terbuka |

---

## KB-1 — Tanggal order terpotong di tanda hubung pada 360px

**Reproduksi.** Buka detail Sales Order (mis. `/sales-orders/1`) pada viewport 360px.

**Yang terjadi.** Baris meta di bawah judul (`status · customer · tanggal`) membungkus tepat di
tanda hubung tanggal, sehingga tampil sebagai `2026-08-` lalu `01` di baris berikutnya. Lihat
[`screenshots/15-sales-order-detail-mobile.png`](./screenshots/15-sales-order-detail-mobile.png).

**Dampak.** Hanya tampilan. Tanggal tetap terbaca utuh dan tidak ada data yang hilang.

**Workaround / perbaikan.** Bungkus tanggal dengan `white-space: nowrap` di
`views/sales-orders/detail.php` (pola yang sama berlaku di detail Purchase Order).

## KB-2 — `favicon.ico` membalas 404

**Reproduksi.** Buka halaman mana pun, lalu periksa tab Network atau Console di browser.

**Yang terjadi.** Browser meminta `/favicon.ico` secara otomatis. File itu tidak ada di
`public/`, sehingga front controller membalas 404 dan console mencatat satu error.

**Dampak.** Tidak ada pada fungsi. Hanya satu baris error di console dan tab browser tanpa
ikon.

**Workaround / perbaikan.** Tambahkan `public/favicon.ico` atau `<link rel="icon">` ke
`views/layout/app.php` dan `views/layout/auth.php`.

## KB-3 — Sort key tak dikenal ikut terbawa di link pagination

**Reproduksi.** Sebagai Admin, buka `/sales-orders?sort=evil&direction=asc`, lalu lihat link
halaman 2.

**Yang terjadi.** Link halaman 2 tetap membawa `sort=evil`. `Request::queryState()` meneruskan
nilai key yang terisi apa adanya; validasi allowlist baru dilakukan `Request::sortCriteria()`
saat query dibangun.

**Dampak.** Tidak ada pada data maupun keamanan. Sort key yang tidak dikenal **diabaikan** dan
tidak pernah menjadi bagian SQL (repository memetakan key ke kolom lewat allowlist-nya
sendiri), sehingga urutan jatuh ke default. Yang terjadi hanyalah URL yang membawa parameter
tak berguna.

**Workaround / perbaikan.** Bentuk state link dari hasil `sortCriteria()`, bukan dari query
string mentah, agar hanya sort yang sah yang ikut terbawa.

---

## Cara melaporkan bug baru

Catat: langkah reproduksi, hasil yang diharapkan, hasil yang terjadi, role yang dipakai, dan
viewport bila menyangkut tampilan. Bug pada alur inti ditulis bersama integration test yang
mereproduksinya **sebelum** diperbaiki, sama seperti perbaikan race condition goods issue
(`ConcurrentGoodsIssueTest`).
