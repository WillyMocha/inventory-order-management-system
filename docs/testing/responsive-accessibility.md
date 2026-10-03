# Pemeriksaan responsive dan accessibility (T133)

Sasaran NFR-006 dan SC-009: seluruh layar utama dapat dipakai pada **360px** maupun desktop.

## Status kejujuran

| Jenis pemeriksaan | Status |
| --- | --- |
| Statis — terhadap CSS dan markup | **Selesai**, hasilnya di bawah |
| Terender — screenshot desktop dan 360px | **Selesai 2026-10-03**, lihat [Pemeriksaan terender](#pemeriksaan-terender-2026-10-03) |
| Keyboard-only dan rasio kontras | **Belum dilakukan**, lihat [Yang tersisa](#yang-tersisa-untuk-dikerjakan-manusia) |

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

**Hasil: 34 dari 34 tangkapan berstatus 200, `overflow` = 0px, `clipped` = 0.** Tabel yang lebih
lebar dari 360px (daftar product/PO/SO, antrean dashboard, line order) digeser di dalam
`.table-wrap` masing-masing.

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

**Kontras belum diukur.** Nilai token-nya dipilih untuk kontras yang memadai, tetapi rasio
WCAG-nya belum dihitung dengan alat.

### Gerak yang dikurangi

`@media (prefers-reduced-motion: reduce)` tersedia dan mematikan transition bagi pengguna yang
memintanya.

## Yang tersisa untuk dikerjakan manusia

1. ~~Buka keempat layar utama pada 360px dan desktop, ambil screenshot.~~ Selesai
   2026-10-03, lihat [Pemeriksaan terender](#pemeriksaan-terender-2026-10-03).
2. Telusuri form dengan keyboard saja — pastikan urutan fokus masuk akal dan focus ring
   selalu terlihat.
3. Ukur rasio kontras teks terhadap latarnya, terutama `.stat-delta--flat` dan `.muted` yang
   memakai `--text-muted`.
