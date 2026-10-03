# Tech debt

Jalan pintas yang diambil dan perbaikan idealnya, dicatat apa adanya. Daftar ini ditulis agar
berguna, bukan agar terlihat rapi.

Item yang sudah diperbaiki **tidak dihapus**: keadaan awal, biayanya, dan cara
memperbaikinya tetap dicatat sebagai riwayat. Pelajarannya sama pentingnya dengan
perbaikannya.

## Ringkasan status (2026-10-03)

| ID | Topik | Status |
| --- | --- | --- |
| TD-1 | Nested transaction passthrough | **Selesai.** SAVEPOINT; workaround di `GoodsReceiptTest` dicabut |
| TD-2 | Integration suite tidak pernah dijalankan | **Selesai sebagian.** `composer check` menjalankan semua gate; CI di luar scope brief |
| TD-2b | Fake menyembunyikan SQL yang rusak | **Selesai.** 116/116 method repository MySQL dieksekusi integration suite |
| TD-3 | Jalur filesystem upload tanpa test | **Selesai sebagian.** `read`/`delete`/penolakan `store` teruji; jalur sukses `store` tidak dapat diuji dari CLI |
| TD-4 | Tidak ada test JavaScript | **Selesai.** `node --test` tanpa dependency, dijalankan lewat Docker |
| TD-5 | `.env.example` berisi kredensial development | **Diterima, tidak diubah.** Bukan secret aktif; alasannya di bawah |
| TD-6 | Index untuk sort dan rentang tanggal | **Selesai.** Diukur dengan `EXPLAIN`, lalu `003_date_indexes.sql` |
| TD-7 | Pemeriksaan visual di browser | **Selesai.** Screenshot, keyboard-only, dan kontras WCAG AA |
| TD-8 | `config/database.php` tidak dipakai | **Selesai.** Dihapus beserta `config/env.php` |

---

## TD-1 — `Database::transaction()` mem-passthrough panggilan bersarang

> **Selesai 2026-10-03.** Nested call kini menjadi `SAVEPOINT` /
> `RELEASE SAVEPOINT` / `ROLLBACK TO SAVEPOINT` (`Database::withinSavepoint()`). Kegagalan di
> dalam membatalkan pekerjaan nested call itu saja, sedangkan transaction terluar tetap
> berjalan.
>
> **Bukti.** `GoodsReceiptTest` kini berjalan **di dalam** pembungkus transaction harness:
> override `wrapsInTransaction()` dan pembersihan fixture manualnya dicabut. Sebelum
> perbaikan, versi ber-pembungkus itu terbukti gagal (`Failed asserting that 15 is identical
> to 10` — stock yang seharusnya di-rollback tetap bertambah); sesudahnya lulus.
> `NestedTransactionTest` menguji perilakunya langsung, termasuk dua tingkat bersarang.
>
> Pembungkus kini hanya perlu dimatikan oleh test yang memakai **dua connection**
> (`ConcurrentGoodsIssueTest`), karena connection kedua tidak dapat melihat data yang belum
> di-commit.

**Keadaan awal.** Bila sudah ada transaction yang berjalan, `transaction()` hanya memanggil
callback-nya tanpa membuka transaction sendiri. Di produksi ini benar — hanya transaction
terluar yang boleh commit.

**Masalahnya.** `IntegrationTestCase` membungkus setiap test dalam transaction lalu me-rollback
saat teardown. Akibatnya, **di dalam integration test, transaction milik Service tidak pernah
terbentuk — dan rollback-nya tidak pernah terjadi**. Test yang memeriksa rollback justru
melihat penulisan parsial bertahan, dan gagal karena alasan yang menyesatkan.

**Biaya yang sudah terjadi.** `GoodsReceiptTest` gagal dengan pesan yang terbaca seolah-olah
rollback produksi rusak. Butuh penelusuran untuk memastikan bahwa yang cacat adalah harness,
bukan aplikasinya.

---

## TD-2 — Integration suite tidak pernah dijalankan sampai Phase 10

> **Selesai sebagian 2026-10-03.** `composer check` menjalankan seluruh gate dalam satu
> perintah dengan urutan: bangun ulang schema database test, unit, integration, PHPStan,
> PHPCS. Perintah ini berhenti pada kegagalan pertama dengan exit code bukan nol. Akar
> masalah awalnya (schema test yang tidak pernah dibangun) kini menjadi langkah pertama
> perintah itu, sehingga integration suite tidak dapat lagi terlewat diam-diam.
>
> **Yang tersisa.** Tidak ada yang menjalankan `composer check` secara otomatis. CI berada
> **di luar scope** brief (§4.3), sehingga tidak ditambahkan. Bila project dilanjutkan, satu
> job CI yang menjalankan `docker compose up` lalu `composer check` sudah cukup.

**Keadaan awal.** Suite ditulis sejak Phase 4, tetapi schema database test belum pernah dibangun
(`composer db:test`), sehingga suite-nya tidak pernah dieksekusi sekalipun.

**Biaya yang sudah terjadi.** Sangat besar. Eksekusi pertama menghasilkan **67 error dari 74
test**, dan salah satunya adalah bug production yang membuat **setiap goods issue gagal**
(lihat `refactor-log.md` R-1). Bug itu hidup melewati enam phase tanpa terlihat.

**Pelajarannya, dan ini yang sesungguhnya penting.** Test yang tidak dijalankan tidak
memberikan jaminan apa pun — ia hanya memberi rasa aman. Selama enam phase, keberadaan
`ConcurrentGoodsIssueTest` dianggap sebagai bukti ARCH-02, padahal test itu belum pernah
dieksekusi satu kali pun.

---

## TD-2b — Fake in-memory tidak menjalankan SQL, dan itu sudah dua kali menyembunyikan bug

> **Selesai 2026-10-03.** Daftar method yang belum pernah dieksekusi terhadap MySQL diambil
> dari **coverage** integration suite (pcov di container sekali pakai), bukan ditebak. Hasilnya
> 36 method, termasuk `countBy` di empat repository (SQL dinamis yang menghitung total
> pagination) dan beberapa SQL statis seperti `isReferencedByOrder`, `setActive`,
> `emailExists`.
>
> `RepositoryCoverageTest` menjalankan semuanya terhadap MySQL dan memeriksa hasilnya
> terhadap fixture. Pemeriksaannya bukan sekadar "tidak error": misalnya `countBy` harus sama
> dengan jumlah baris `search`, dan `isReferencedByOrder` harus berubah menjadi true setelah
> order memakai record-nya. Coverage sesudahnya: **116 dari 116** method repository MySQL
> dieksekusi integration suite.
>
> **Aturan ke depan.** Setiap method repository baru membutuhkan integration test yang
> mengeksekusinya. Fake berguna untuk menguji aturan bisnis, tetapi **tidak pernah** menjadi
> bukti bahwa SQL-nya sah.

**Keadaan awal.** `InMemory*Repository::search()` mengimplementasikan pencarian dengan
`str_contains` di PHP. SQL-nya tidak pernah dieksekusi, sehingga unit test dapat lulus penuh
sementara query yang sesungguhnya tidak dapat berjalan sama sekali.

**Biaya yang sudah terjadi — dua kali, dengan bug yang bentuknya sama persis.**

1. `MysqlProductStockRepository::adjust()` — setiap goods issue gagal (lihat `refactor-log.md`
   R-1).
2. **Seluruh enam fitur search** membalas 500 karena satu nama placeholder dipakai dua kali
   dalam satu statement (`SQLSTATE[HY093]`). Dilaporkan user, bukan ditemukan test.

Keduanya lolos seluruh unit suite karena unit suite memang tidak pernah menyentuh MySQL.
`tests/Integration/RepositorySearchTest.php` sudah menutup jalur search sebelumnya.

---

## TD-3 — `ProductImageService::store/read/delete` tanpa unit test

> **Selesai sebagian 2026-10-03.** `ProductImageStorageTest` (suite Integration, karena
> menyentuh filesystem) memakai direktori sementara per test dan menguji:
> `read()` mengembalikan isi beserta tipe hasil deteksi `finfo`; file yang hilang dan nama
> yang mencoba keluar direktori ditolak; `delete()` benar-benar menghapus file dan tidak
> gagal untuk product tanpa image; `store()` menolak upload yang ditolak PHP karena ukuran,
> upload yang kosong, dan — yang paling penting — **file lokal yang disodorkan sebagai upload**
> (`is_uploaded_file()`), tanpa menyimpan apa pun.
>
> **Yang tersisa.** Jalur **sukses** `store()` tidak dapat diuji dari CLI:
> `move_uploaded_file()` hanya menerima file yang benar-benar datang lewat HTTP POST. Membuat
> seam agar bisa diuji berarti melonggarkan pemeriksaan keamanan itu demi test, dan itu
> tidak sepadan. Jalur ini diverifikasi lewat demo upload pada PRD-01.

**Keadaan awal.** Ketiganya menyentuh filesystem, sedangkan unit suite dijalankan tanpa
filesystem. Yang teruji hanya bagian keputusannya: `validate()`, `generateStoredName()`, dan
`pathFor()`.

---

## TD-4 — Tidak ada test otomatis untuk `stock-lookup.js`

> **Selesai 2026-10-03.** `tests/js/stock-lookup.test.mjs` memakai test runner bawaan Node
> (`node --test`). Tidak ada dependency, `package.json`, maupun build step, jadi larangan
> framework dan build step di brief tetap terpenuhi. Test-nya dijalankan lewat image
> `node:22-alpine` di Docker, sehingga tidak bergantung pada Node di komputer peserta:
>
> ```bash
> docker run --rm -v "$PWD":/app -w /app node:22-alpine node --test "tests/js/*.test.mjs"
> ```
>
> Yang diuji adalah dua bagian yang memuat keputusan, yang kini diekspor modulnya:
> `fetchAvailable()` (URL ter-encode, `credentials: same-origin`, 401/403/404/500 dan respons
> tanpa angka menjadi `null`, nol tetap angka yang sah, pembatalan diteruskan sebagai
> `AbortError`) dan `render()` (teks, penanda low/ok, batas "tepat sebesar stock"). Ada 11
> test; satu mutasi (`>` menjadi `>=`) terbukti tertangkap.
>
> **Yang tersisa.** Perkabelan event DOM di `initStockLookup()` diperiksa di browser
> sungguhan (Chrome headless: memilih warehouse dan product memunculkan "28 available"
> bertanda low), bukan oleh test otomatis. `validation.js` belum memiliki test.

**Keadaan awal.** Diperiksa hanya dengan `node --check`; alur fetch, pembatalan request yang
saling menyusul, dan debounce-nya belum pernah diuji.

---

## TD-5 — `.env.example` berisi kredensial development yang sesungguhnya

> **Diterima, tidak diubah (ditinjau ulang 2026-10-03).** Nilai ini bukan secret aktif: nilai
> yang sama sudah menjadi default di `compose.yaml`, dan hanya membuka MySQL di dalam
> container lokal. Brief §5.1 justru mewajibkan `.env.example` berisi contoh nilai. Mengganti
> nilainya dengan placeholder yang tidak berjalan akan merusak alur `cp .env.example .env`
> dari folder bersih (§5.1, checklist §10) tanpa menambah keamanan apa pun. Pemeriksaan
> secret ada di [`secret-scan.md`](./secret-scan.md).

**Keadaan.** `DB_PASSWORD=ioms_secret` dan `DB_ROOT_PASSWORD=root_secret` di-commit, dan
memang itulah yang dipakai stack lokal setelah `cp .env.example .env`.

**Perbaikan ideal.** Untuk deployment sungguhan, nilai ini wajib diganti dan disuntikkan lewat
secret manager, bukan lewat file di repository.

---

## TD-6 — Tidak ada index khusus untuk kolom sort

> **Selesai 2026-10-03, setelah diukur.** `EXPLAIN` terhadap MySQL 8 dengan data seed
> menunjukkan tiga query yang memindai **seluruh** index (`type = index`) lalu melakukan
> filesort. Penyebabnya, index yang ada berawal dari kolom lain (`status` atau `product_id`):
>
> | Query | Sebelum | Sesudah |
> | --- | --- | --- |
> | Daftar SO tanpa filter, urut `order_date` | full index scan, 17 baris, filesort | `ix_sales_order_date`, 10 baris (`LIMIT`), backward index scan, **tanpa filesort** |
> | Report PO: `order_date` BETWEEN | full index scan, filesort | `range` pada `ix_purchase_order_date`, **tanpa filesort** |
> | Report ledger: `created_at` range | full index scan, filesort | `range` pada `ix_ledger_created_at`, **tanpa filesort** |
>
> Index ditambahkan lewat migration baru `database/003_date_indexes.sql`, bukan dengan
> mengubah `001_schema.sql`, karena database yang sudah berjalan hanya menerima file baru.
> Index komposit `(status, order_date)` yang lama tetap dipertahankan untuk daftar ber-filter
> status. Daftar index di [`../planning/erd.md`](../planning/erd.md) sudah diperbarui.

**Keadaan awal.** Sort memakai allowlist nama kolom, tetapi tidak ada index yang dibuat khusus
untuknya.

---

## TD-7 — Pemeriksaan visual belum pernah dilakukan di browser

> **Selesai 2026-10-03.** Rinciannya ada di
> [`../testing/responsive-accessibility.md`](../testing/responsive-accessibility.md):
>
> - **Screenshot.** 34 screenshot desktop dan 360px dengan Chrome headless. Hasilnya nol
>   halaman yang bergeser mendatar, dan tiga cacat UI ditemukan lalu diperbaiki.
> - **Kontras WCAG AA.** 481 elemen teks di 8 halaman diukur. Seluruh badge status ternyata
>   **gagal** (3.07–4.41:1, minimum 4.5:1). Setelah token teks status digelapkan satu
>   tingkat, tidak ada lagi yang gagal (minimum 4.55:1).
> - **Keyboard-only.** Tab dijalankan melalui login, form Sales Order, dan daftar product.
>   Urutan fokusnya logis, tetapi outline fokus input (`--accent-wash` di atas putih,
>   ~1.1:1) praktis tidak terlihat, dan tombol kalender di input tanggal tidak punya
>   indikator sama sekali. Keduanya sudah diperbaiki, dan kini setiap titik fokus punya
>   indikator yang terlihat.

**Keadaan awal.** Seluruh halaman sudah dipastikan mengembalikan 200 lewat HTTP, tetapi
spacing, warna, kontras, dan perilaku responsive pada 360px belum pernah benar-benar dilihat
— tidak ada browser tooling pada sesi pengerjaan.

---

## TD-8 — `config/database.php` adalah PDO factory yang sudah tidak dipakai

> **Selesai 2026-10-03.** `config/database.php` dihapus. `config/env.php` ikut dihapus
> karena satu-satunya yang memuatnya adalah `config/database.php`; aplikasi membaca `.env`
> lewat `config/app.php`. Setelah penghapusan: halaman login, script JOB-01, dan seluruh
> suite tetap berjalan, dan PHP_CodeSniffer kini **nol warning**.

**Keadaan awal.** `ioms_pdo()` tidak dipanggil di mana pun; aplikasi dan test membuat koneksi
lewat `App\Support\Database`. Dua factory PDO membuka peluang konfigurasi koneksi yang berbeda
(mis. `ATTR_EMULATE_PREPARES`) tanpa ada yang menyadari.
