# Skenario test manual

Urutan demonstrasi yang disarankan, lengkap dengan hasil yang diharapkan. Melengkapi
[`quickstart.md`](../../specs/001-inventory-order-management/quickstart.md); yang di sini
berfokus pada **apa yang harus dilihat**, bukan pada cara menjalankannya.

Seluruh akun memakai password `Password123!`.

---

## S-1 — Authentication dan pemisahan role (US1)

| Langkah | Hasil yang diharapkan |
| --- | --- |
| Buka `/dashboard` tanpa login | Dialihkan ke `/login` |
| Login dengan password salah | Pesan seragam yang **tidak** menjelaskan bagian mana yang salah |
| Ulangi kegagalan beberapa kali | Rate limit menahan percobaan berikutnya |
| Login `admin@ioms.test` | Dashboard Admin — nilai inventori, below reorder point, menunggu approval |
| Login `sales1@ioms.test` | Dashboard Sales — **hanya ordernya sendiri**; tidak ada nilai inventori |
| Login `warehouse1@ioms.test` | Dashboard Warehouse — antrean receipt, antrean issue, low stock |
| Logout lalu tekan tombol Back | Halaman terlindungi **tidak** dapat dibuka lagi |

Tiga role harus mendarat di **tiga halaman yang berbeda**, bukan satu halaman dengan blok
yang disembunyikan.

---

## S-2 — Master data dan product (US2)

| Langkah | Hasil yang diharapkan |
| --- | --- |
| Sebagai Sales, buka `/users` | 403 — bukan halaman kosong, bukan redirect diam-diam |
| Sebagai Admin, buat category dengan nama yang sudah ada | Ditolak dengan pesan di samping field |
| Buat product dengan SKU yang sudah ada | Ditolak; input yang sudah diisi tetap dipertahankan |
| Buat product dengan harga negatif | Ditolak |
| Unggah file `.exe` yang diganti namanya menjadi `.jpg` | **Ditolak** — tipe ditentukan dari isi file |
| Unggah gambar melebihi batas ukuran | Ditolak |
| Nonaktifkan product yang dipakai order | Berhasil; order lama tetap utuh, product hilang dari pilihan order baru |

---

## S-3 — Purchase Order sampai goods receipt (US3)

| Langkah | Hasil yang diharapkan |
| --- | --- |
| Buat PO dua line, lalu Order | Status `Ordered` |
| Terima sebagian pada satu line | Status `PartiallyReceived`; outstanding tercatat |
| Coba terima melebihi outstanding | Ditolak, menyebut sisa yang sebenarnya |
| Terima seluruh sisanya | Status `Received`; stock bertambah |
| Periksa stock ledger | Satu baris `Receipt` **positif** per penerimaan |

---

## S-4 — Sales Order, approval, goods issue (US4)

| Langkah | Hasil yang diharapkan |
| --- | --- |
| Sebagai `sales1`, buat SO lalu submit | Status `PendingApproval` |
| Sebagai `sales1`, coba approve ordernya sendiri | **Tidak ada tombol approve**, dan POST langsung ke endpoint tetap ditolak server |
| Sebagai `sales2`, buka order milik `sales1` | **404**, bukan 403 — keberadaan record tidak boleh bocor |
| Sebagai `admin`, approve | Berhasil; `approved_by` tercatat |
| Sebagai Warehouse Staff, issue goods | Stock **turun**, baris ledger `Issue` **negatif** tertulis, status `Fulfilled` |
| Coba issue order yang sama dua kali | Ditolak |
| Issue melebihi stock tersedia | Ditolak; **tidak ada** perubahan tersisa sama sekali |

Langkah "approve ordernya sendiri" adalah inti FR-018. Menyembunyikan tombol saja bukan
kontrol akses — pembuktiannya adalah POST langsung.

---

## S-5 — Pencarian dan pagination (US5)

| Langkah | Hasil yang diharapkan |
| --- | --- |
| Cari product menurut nama dan menurut SKU | Keduanya menemukan |
| Terapkan filter category lalu pindah halaman | Filter **bertahan** di halaman kedua |
| Filter yang tidak menemukan apa pun | Empty state yang menawarkan menghapus filter — bukan tabel kosong |
| Sort menurut tanggal, dua arah | Urutan benar-benar berbalik |

---

## S-6 — Dashboard dan export (US6)

| Langkah | Hasil yang diharapkan |
| --- | --- |
| Bandingkan angka dashboard sebelum dan sesudah goods issue | Angkanya **bergeser** — bukan nilai tetap |
| Export order untuk rentang yang mencakup seluruh data | Jumlah baris sama dengan yang tertera di layar |
| Export dua rentang berbeda | Dua file yang berbeda isinya |
| Export rentang tanpa data | File tetap terbentuk, **berisi header saja** |
| Rentang lebih dari 366 hari | Ditolak, dikembalikan ke form beserta nilai yang tadi diisi |
| Sebagai Sales, export order | Hanya ordernya sendiri |

---

## S-7 — JSON API (US7)

| Langkah | Hasil yang diharapkan |
| --- | --- |
| `GET /api/products/{sku}/availability` sambil login | `200` JSON sesuai contract |
| Endpoint yang sama **tanpa** cookie | **`401` JSON**, bukan halaman login HTML |
| SKU yang tidak dikenal | `404` JSON |
| `GET /api/dashboard/low-stock` sebagai Sales | `403` JSON |
| Sama, sebagai Admin dan Warehouse Staff | `200` JSON |

Seluruhnya wajib `Content-Type: application/json` — termasuk responsnya yang error.

---

## S-8 — Low-stock di luar request cycle (US8)

Lihat [`low-stock-job.md`](./low-stock-job.md) untuk prosedur lengkapnya. Inti yang harus
terlihat: angka script **sama persis** dengan angka dashboard.

---

## Screenshot yang diwajibkan UI-01

Empat layar utama, masing-masing pada **desktop** dan **360px**:

| Layar | Berkas desktop | Berkas 360px |
| --- | --- | --- |
| Login | `screenshots/login-desktop.png` | `screenshots/login-360.png` |
| Dashboard (per role) | `screenshots/dashboard-{role}-desktop.png` | `screenshots/dashboard-{role}-360.png` |
| Daftar product | `screenshots/products-desktop.png` | `screenshots/products-360.png` |
| Form Sales Order | `screenshots/sales-order-form-desktop.png` | `screenshots/sales-order-form-360.png` |

> **BELUM DIAMBIL.** Screenshot belum dibuat karena sesi pengerjaan tidak memiliki tooling
> browser. Ini pekerjaan manual yang **harus dijalankan manusia** sebelum submission, bukan
> sesuatu yang dapat diklaim selesai dari pembacaan kode.
>
> Yang perlu diperiksa pada setiap screenshot: tidak ada konten yang terpotong atau meluber,
> navigasi tidak terklip pada 360px, tabel dapat digeser mendatar di dalam wadahnya sendiri
> tanpa membuat halaman ikut bergeser, setiap input punya label, dan focus state terlihat
> jelas saat navigasi keyboard.
>
> Yang sudah diperiksa secara statis dicatat di
> [`responsive-accessibility.md`](./responsive-accessibility.md).
