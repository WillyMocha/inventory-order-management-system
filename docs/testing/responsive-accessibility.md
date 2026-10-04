# Pemeriksaan responsive dan accessibility (T133)

Sasaran NFR-006 dan SC-009: seluruh layar utama dapat dipakai pada **360px** maupun desktop.

## Status kejujuran

| Jenis pemeriksaan | Status |
| --- | --- |
| Statis — terhadap CSS dan markup | **Selesai**, hasilnya di bawah |
| Terender — screenshot desktop dan 360px | **Selesai 2026-10-03**, lihat [Pemeriksaan terender](#pemeriksaan-terender-2026-10-03) |
| Keyboard-only dan rasio kontras WCAG AA | **Selesai 2026-10-03**, lihat [Audit keyboard dan kontras](#audit-keyboard-dan-kontras-2026-10-03) |

## Audit keyboard dan kontras (2026-10-03)

Diukur dengan Chrome headless terhadap stack Docker. Hasil mentahnya ada di
[`a11y-audit.json`](./a11y-audit.json).

**Kontras.** Untuk setiap elemen teks yang terlihat dihitung rasio WCAG antara warna teks dan
latar efektifnya (lapisan latar semi-transparan ikut dihitung). Ambangnya 4.5:1 untuk teks
normal dan 3:1 untuk teks besar. Ada 688 elemen di 14 pengukuran: login, dashboard Admin dan
Warehouse Staff, daftar product (berisi dan kosong), detail Sales Order, form Sales Order,
report, **My profile** dalam tiga state (berisi, gagal validasi 422, dan flash sukses), serta
**koreksi stock**: form, state 422, dan detail product dengan kartu "Stock adjustments".
Diukur ulang setelah 002-user-profile-page dan 003-stock-adjustment.

| | Sebelum | Sesudah |
| --- | --- | --- |
| Elemen gagal | 47, seluruhnya badge status | **0** |
| Rasio terendah | 3.07:1 (Pending / Low stock) | **4.55:1** |

Penyebabnya: teks badge memakai warna status 500/600 (`--warning`, `--success`, …) di atas
latar pucatnya. Perbaikannya adalah token teks status satu tingkat lebih gelap
(`--warning-text`, `--success-text`, `--info-text`, `--danger-text`, dan `--neutral-600` untuk
Draft) di `tokens.css`. Warna status aslinya tetap dipakai untuk ikon dan aksen non-teks,
sehingga makna warnanya tidak berubah.

**Keyboard-only.** Tab ditekan berulang kali melalui halaman login, form Sales Order, daftar
product, My profile, dan form koreksi stock. Setiap titik fokus dicatat beserta indikatornya.
Pada My profile urutannya: navigasi (termasuk "My profile" dan blok nama yang kini berupa link),
Sign out, ketiga field password, lalu tombol "Change password". Pada form koreksi stock:
navigasi, "Back to product", pilihan warehouse dan "Show", Counted quantity, Reason, "Record
adjustment", lalu "Cancel". Seluruhnya memiliki indikator fokus.

- Urutan fokusnya logis: navigasi, lalu aksi halaman, field form, line order, dan tombol
  submit. Tidak ada elemen yang terlewat atau terjebak.
- **Ditemukan dan diperbaiki:** outline fokus input memakai `--accent-wash` (#eff6ff) di atas
  putih, dengan rasio ~1.1:1. Fokus pada field praktis hanya ditandai perubahan warna border
  1px. Outline-nya kini `--accent` 2px (5.2:1), sama dengan indikator global. Lihat
  [`screenshots/19-input-focus-ring-desktop.png`](./screenshots/19-input-focus-ring-desktop.png).
- **Ditemukan dan diperbaiki:** tombol kalender di dalam input tanggal menerima fokus di
  shadow DOM tanpa indikator apa pun. Selector `.input:focus-within` kini ikut mencakupnya.
- Sesudah perbaikan, **setiap** titik fokus di ketiga halaman memiliki indikator yang
  terlihat.

## Pemeriksaan terender (2026-10-03)

Bukti untuk UI-01 dan VIEW-01. Seluruh gambar ada di [`screenshots/`](./screenshots/), dan
hasil pengukuran mentahnya ada di [`screenshots/run.json`](./screenshots/run.json).

**Cara.** Google Chrome headless dijalankan lewat `puppeteer-core` terhadap stack Docker
(`http://localhost:8080`) dengan data seed. Setiap role login lewat form login sungguhan di
browser context terpisah. Viewport-nya **1366×768** (desktop) dan **360×740** (mobile, mode
touch). Tinggi gambar mengikuti tinggi halaman. Script-nya alat bantu sekali jalan, tidak
termasuk aplikasi, dan tidak di-commit.

Selain gambarnya, setiap halaman diukur dengan dua cara:

- `overflow`: selisih `scrollWidth` dokumen terhadap lebar viewport. Nilai di atas 0 berarti
  seluruh halaman bergeser mendatar.
- `clipped`: tabel yang lebih lebar dari wadahnya **tanpa** ancestor `overflow-x: auto/scroll`,
  yaitu isi yang terpotong dan tidak dapat dijangkau user.

**Hasil: 42 dari 42 tangkapan sesuai harapan (38 berstatus 200, empat tangkapan state gagal
validasi berstatus 422), `overflow` = 0px, `clipped` = 0.** Tabel yang lebih lebar dari 360px
(daftar product/PO/SO, antrean dashboard, line order, riwayat koreksi stock) digeser di dalam
`.table-wrap` masing-masing. Seluruh tangkapan diambil ulang setelah 002-user-profile-page
(sidebar mendapat item **My profile**) dan 003-stock-adjustment (detail product mendapat tombol
"Adjust stock" dan kartu riwayat untuk Admin dan Warehouse Staff).

| Layar | Desktop | 360px |
| --- | --- | --- |
| Login | [01](./screenshots/01-login-desktop.png) | [01](./screenshots/01-login-mobile.png) |
| Dashboard Admin | [02](./screenshots/02-admin-dashboard-desktop.png) | [02](./screenshots/02-admin-dashboard-mobile.png) |
| Dashboard Sales | [03](./screenshots/03-sales-dashboard-desktop.png) | [03](./screenshots/03-sales-dashboard-mobile.png) |
| Dashboard Warehouse Staff | [04](./screenshots/04-warehouse-dashboard-desktop.png) | [04](./screenshots/04-warehouse-dashboard-mobile.png) |
| Daftar product (berisi data) | [05](./screenshots/05-products-list-desktop.png) | [05](./screenshots/05-products-list-mobile.png) |
| Daftar product, filter low stock | [06](./screenshots/06-products-low-stock-filter-desktop.png) | — |
| Daftar product (empty state) | [07](./screenshots/07-products-empty-desktop.png) | [07](./screenshots/07-products-empty-mobile.png) |
| Detail product (stock per warehouse) | [08](./screenshots/08-product-detail-desktop.png) | [08](./screenshots/08-product-detail-mobile.png) |
| Form product | [09](./screenshots/09-product-form-desktop.png) | [09](./screenshots/09-product-form-mobile.png) |
| Daftar Purchase Order | [10](./screenshots/10-purchase-orders-list-desktop.png) | [10](./screenshots/10-purchase-orders-list-mobile.png) |
| Daftar Purchase Order (empty state) | [11](./screenshots/11-purchase-orders-empty-desktop.png) | [11](./screenshots/11-purchase-orders-empty-mobile.png) |
| Detail Purchase Order | [12](./screenshots/12-purchase-order-detail-desktop.png) | [12](./screenshots/12-purchase-order-detail-mobile.png) |
| Daftar Sales Order | [13](./screenshots/13-sales-orders-list-desktop.png) | [13](./screenshots/13-sales-orders-list-mobile.png) |
| Daftar Sales Order (empty state) | [14](./screenshots/14-sales-orders-empty-desktop.png) | [14](./screenshots/14-sales-orders-empty-mobile.png) |
| Detail Sales Order | [15](./screenshots/15-sales-order-detail-desktop.png) | [15](./screenshots/15-sales-order-detail-mobile.png) |
| Form Sales Order (sebagai Sales) | [16](./screenshots/16-sales-order-form-desktop.png) | [16](./screenshots/16-sales-order-form-mobile.png) |
| Report dan export CSV | [17](./screenshots/17-reports-desktop.png) | [17](./screenshots/17-reports-mobile.png) |
| Drawer navigasi terbuka | — | [18](./screenshots/18-mobile-nav-open-mobile.png) |
| My profile (sebagai Sales) | [20](./screenshots/20-profile-desktop.png) | [20](./screenshots/20-profile-mobile.png) |
| My profile, ganti password gagal (422) | [21](./screenshots/21-profile-password-error-desktop.png) | [21](./screenshots/21-profile-password-error-mobile.png) |
| Koreksi stock (sebagai Warehouse Staff) | [22](./screenshots/22-stock-adjustment-form-desktop.png) | [22](./screenshots/22-stock-adjustment-form-mobile.png) |
| Koreksi stock ditolak, selisih nol (422) | [23](./screenshots/23-stock-adjustment-error-desktop.png) | [23](./screenshots/23-stock-adjustment-error-mobile.png) |

Empty state ditangkap lewat pencarian yang pasti tidak cocok (`?search=zz-no-such-...`), bukan
dengan mengosongkan database. Pesan yang tampil sama, dan data demo tetap utuh.

### Yang ditemukan dan diperbaiki dari gambar

Tiga cacat ini **tidak** terdeteksi oleh pemeriksaan HTTP maupun oleh angka `overflow`.
Ketiganya baru terlihat setelah gambarnya benar-benar dilihat.

| Temuan | Dampak | Perbaikan |
| --- | --- | --- |
| Dropdown product pada line order menyusut menjadi dua huruf ("Se") pada 360px | Form PO/SO praktis tidak dapat dipakai di mobile (melanggar UI-01) | `.table .select { min-width: 14rem; }`. Baris yang lebih lebar digeser di dalam `.table-wrap` |
| Label wajib tertulis "Customer \* \*" pada form PO dan SO | Penanda wajib ganda | Form PO/SO memakai `class="field-label field-required"` seperti form lain, bukan `*` literal yang ditambah `::after` |
| Topbar mobile turun 24px dari tepi atas layar | Celah abu-abu di atas topbar | Pada `max-width: 640px`, padding atas `.page` dipindah menjadi `margin-bottom` topbar |

### Ditemukan saat mengambil ulang bukti (002-user-profile-page)

Keduanya cacat lama di komponen bersama, bukan akibat halaman profil. Keduanya baru terlihat
karena pengukuran kali ini memakai mode touch penuh dan untuk pertama kalinya merender state
error.

| Temuan | Dampak | Perbaikan |
| --- | --- | --- |
| Pada 360px (mode touch), daftar product/PO/SO dan form SO melebar menjadi 479–661px | Seluruh halaman dapat digeser mendatar di ponsel, bukan hanya tabelnya | Label `.visually-hidden` (`position: absolute`) di dalam sel tabel lolos dari area scroll karena `.table-wrap` tidak positioned. `.table-wrap` kini `position: relative` |
| Teks `alert--error` hanya 4.41:1 di atas latarnya (`alert--success` lebih rendah lagi) | Ringkasan kesalahan form dan flash gagal WCAG AA | Alert memakai token `--*-text`, sama seperti badge status (perbaikan kontras sebelumnya) |

### Ditemukan saat mengambil ulang bukti (003-stock-adjustment)

| Temuan | Dampak | Perbaikan |
| --- | --- | --- |
| Pilihan warehouse dan tombol "Show" pada form koreksi terpisah dua baris | Kontrol yang berpasangan tampak tidak berhubungan | Class `.inline-picker`: select mengisi sisa ruang, tombol tetap di sampingnya, juga pada 360px |
| Teks `stat-delta--up` (misalnya "above reorder point of 10" di detail product) hanya 3.3:1 di atas putih | Gagal WCAG AA pada setiap stat tile yang sedang "baik"; audit sebelumnya kebetulan hanya melihat varian `down` | `stat-delta--up/--down` memakai token `--*-text`, sama seperti badge dan alert |

### Masih terlihat, belum diperbaiki

- Pada detail Sales Order di 360px, tanggal di baris meta dapat terpotong di tanda hubung
  ("2026-08-" / "01"). Hanya kosmetik, isinya tetap terbaca.

## Hasil pemeriksaan statis

### Label pada setiap input

Seluruh `input`, `select`, dan `textarea` yang punya `id` memiliki `<label for="...">` yang
bersesuaian. Pada baris line order, label memakai kelas `.visually-hidden` — tetap terbaca
screen reader, tidak memakan ruang pada layar sempit.

Diperiksa dengan menelusuri setiap `id` di `views/` dan mencari `for` yang cocok pada file
yang sama.

### Focus state

`:focus-visible` didefinisikan global dengan `outline: 2px solid var(--accent)` dan
`outline-offset: 2px`. Tidak ada satu pun `outline: none` di seluruh stylesheet — diperiksa
dengan pencarian langsung.

### Tabel pada layar sempit

Setiap tabel dibungkus `.table-wrap { overflow-x: auto }`, sehingga tabel lebar digeser **di
dalam wadahnya sendiri** dan tidak membuat seluruh halaman ikut bergeser mendatar.

### Reflow pada `max-width: 640px`

| Elemen | Perilaku |
| --- | --- |
| `.nav-inner` / `.nav-links` | Membungkus ke baris sendiri, dapat digeser mendatar, `min-width: 0` agar benar-benar dapat menyusut |
| `.stat-grid` | Menjadi satu kolom |
| `.form-grid` | Menjadi satu kolom |
| `.page-header` | Menumpuk, judul dan tombol tidak lagi berebut satu baris |
| `.pagination` | Menumpuk |
| `.form-actions .btn` | Melebar mengisi baris |

Di luar media query, `.stat-grid` memakai `repeat(auto-fit, minmax(220px, 1fr))` yang sudah
muat pada 360px, dan `.toolbar` serta `.tally` memakai `flex-wrap: wrap` sejak awal.

### Yang diperbaiki pada pass ini

Tiga aturan ditambahkan ke blok `max-width: 640px`, karena tanpa itu lebar intrinsik
elemennya dapat mendorong halaman melewati 360px:

```css
/* Field filter melebar penuh; tanpa ini input dengan placeholder panjang
   mempertahankan lebar intrinsiknya. */
.toolbar .field { flex: 1 1 100%; min-width: 0; }
.toolbar .btn   { flex: 1 1 auto; justify-content: center; }

/* Lima badge status tidak boleh memaksa halaman menggeser mendatar. */
.tally-item     { flex: 1 1 100%; justify-content: space-between; }
```

### Tidak ada lebar tetap yang melebihi 360px

Dicari `width` dan `min-width` bernilai ≥ 370px di seluruh stylesheet — tidak ada.

### Warna berasal dari token

Seluruh warna merujuk custom property pada `tokens.css`. Tidak ada hex langsung di `app.css` —
dinyatakan pada header file dan diperiksa ulang pada pass ini.

**Kontras sudah diukur** — lihat [Audit keyboard dan kontras](#audit-keyboard-dan-kontras-2026-10-03).

### Gerak yang dikurangi

`@media (prefers-reduced-motion: reduce)` tersedia dan mematikan transition bagi pengguna yang
memintanya.

## Yang tersisa untuk dikerjakan manusia

1. ~~Buka keempat layar utama pada 360px dan desktop, ambil screenshot.~~ Selesai
   2026-10-03, lihat [Pemeriksaan terender](#pemeriksaan-terender-2026-10-03).
2. ~~Telusuri form dengan keyboard saja.~~ Selesai 2026-10-03.
3. ~~Ukur rasio kontras teks terhadap latarnya.~~ Selesai 2026-10-03.
4. Uji dengan screen reader sungguhan (NVDA atau VoiceOver). Belum dilakukan: audit di atas
   memeriksa label dan fokus, bukan pengalaman membaca halaman secara utuh.
