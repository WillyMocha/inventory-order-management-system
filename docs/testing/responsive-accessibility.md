# Pemeriksaan responsive dan accessibility (T133)

Sasaran NFR-006 dan SC-009: seluruh layar utama dapat dipakai pada **360px** maupun desktop.

## Status kejujuran

| Jenis pemeriksaan | Status |
| --- | --- |
| Statis — terhadap CSS dan markup | **Selesai**, hasilnya di bawah |
| Terender — benar-benar dilihat di browser | **BELUM DILAKUKAN** |

Sesi pengerjaan tidak memiliki tooling browser, sehingga spacing, keselarasan grid, harmoni
warna, dan perilaku reflow pada 360px **belum pernah benar-benar dilihat**. Itu tidak boleh
dianggap lulus. Prosedurnya ada di [`test-scenarios.md`](./test-scenarios.md).

Yang **sudah** diverifikasi terhadap aplikasi berjalan: seluruh halaman mengembalikan `200`
dengan isi yang benar, dan tiap keadaan template (terisi, kosong, error validasi) dirender
tanpa error.

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
WCAG-nya belum dihitung dengan alat. Ini bagian dari pemeriksaan terender yang masih
tertunda.

### Gerak yang dikurangi

`@media (prefers-reduced-motion: reduce)` tersedia dan mematikan transition bagi pengguna yang
memintanya.

## Yang tersisa untuk dikerjakan manusia

1. Buka keempat layar utama pada 360px dan desktop, ambil screenshot (lihat
   `test-scenarios.md`).
2. Telusuri form dengan keyboard saja — pastikan urutan fokus masuk akal dan focus ring
   selalu terlihat.
3. Ukur rasio kontras teks terhadap latarnya, terutama `.stat-delta--flat` dan `.muted` yang
   memakai `--text-muted`.
