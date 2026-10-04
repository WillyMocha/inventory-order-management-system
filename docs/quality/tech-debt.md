# Tech debt

Jalan pintas yang diambil dan perbaikan idealnya, dicatat apa adanya. Daftar ini ditulis agar
berguna, bukan agar terlihat rapi.

Item yang sudah diperbaiki **tidak dihapus**: keadaan awal, biayanya, dan cara
memperbaikinya tetap dicatat sebagai riwayat. Pelajarannya sama pentingnya dengan
perbaikannya.

## Ringkasan status (2026-10-04)

| ID | Topik | Status |
| --- | --- | --- |
| TD-1 | Nested transaction passthrough | **Selesai.** SAVEPOINT; workaround di `GoodsReceiptTest` dicabut |
| TD-2 | Integration suite tidak pernah dijalankan | **Selesai sebagian.** `composer check` menjalankan semua gate; CI di luar scope brief |
| TD-2b | Fake menyembunyikan SQL yang rusak | **Selesai.** 126/126 method repository MySQL dieksekusi integration suite (diukur ulang 2026-10-04) |
| TD-3 | Jalur filesystem upload tanpa test | **Selesai sebagian.** `read`/`delete`/penolakan `store` teruji; jalur sukses `store` tidak dapat diuji dari CLI |
| TD-4 | Tidak ada test JavaScript | **Selesai.** `node --test` tanpa dependency, dijalankan lewat Docker |
| TD-5 | `.env.example` berisi kredensial development | **Diterima, tidak diubah.** Bukan secret aktif; alasannya di bawah |
| TD-6 | Index untuk sort dan rentang tanggal | **Selesai.** Diukur dengan `EXPLAIN`, lalu `003_date_indexes.sql` |
| TD-7 | Pemeriksaan visual di browser | **Selesai.** Screenshot, keyboard-only, dan kontras WCAG AA |
| TD-8 | `config/database.php` tidak dipakai | **Selesai.** Dihapus beserta `config/env.php` |
| TD-9 | Append-only `stock_ledger` hanya dijaga konvensi | **Selesai.** Trigger MySQL `005_ledger_append_only.sql` |
| TD-10 | Validasi order memeriksa "ada", bukan "aktif" | **Selesai.** `Validator::activeById()` untuk create dan edit |
| TD-11 | Class order melewati batas ukuran Sonar | **Selesai sebagian.** Semua ≤ 20 method; sembilan file `app/` masih > 300 baris |

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
> (`ConcurrentGoodsIssueTest` dan `ConcurrentStockAdjustmentTest`), karena connection kedua tidak
> dapat melihat data yang belum di-commit.

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
> bertanda low), bukan oleh test otomatis. Modul JavaScript lain (`validation.js`,
> `order-lines.js`, `filters.js`, `confirm.js`, `nav-drawer.js`, `main.js`) belum memiliki test.
> Seluruhnya progressive enhancement: aplikasi tetap berfungsi tanpa JavaScript.

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
> - **Screenshot.** 34 screenshot desktop dan 360px dengan Chrome headless, ditambah satu
>   screenshot ring fokus input. Hasilnya nol
>   halaman yang bergeser mendatar, dan tiga cacat UI ditemukan lalu diperbaiki.
> - **Kontras WCAG AA.** 481 elemen teks di 8 halaman diukur. Seluruh badge status ternyata
>   **gagal** (3.07–4.41:1, minimum 4.5:1). Setelah token teks status digelapkan satu
>   tingkat, tidak ada lagi yang gagal (minimum 4.55:1).
> - **Keyboard-only.** Tab dijalankan melalui login, form Sales Order, dan daftar product.
>   Urutan fokusnya logis, tetapi outline fokus input (`--accent-wash` di atas putih,
>   ~1.1:1) praktis tidak terlihat, dan tombol kalender di input tanggal tidak punya
>   indikator sama sekali. Keduanya sudah diperbaiki, dan kini setiap titik fokus punya
>   indikator yang terlihat.
>
> **Diulang 2026-10-03 setelah 002-user-profile-page** (sidebar mendapat item baru): 38
> tangkapan termasuk My profile, 570 elemen teks, dan state error untuk pertama kalinya. Dua
> cacat lama komponen bersama ikut terungkap dan diperbaiki: label `.visually-hidden` di dalam
> tabel melebarkan halaman mobile (`.table-wrap` kini `position: relative`), dan teks alert
> di bawah 4.5:1 (kini memakai token `--*-text`).
>
> **Diulang lagi setelah 003-stock-adjustment**: 42 tangkapan, 688 elemen teks, 5 halaman
> keyboard. Satu cacat lama lagi terungkap dan diperbaiki: `stat-delta--up` hanya 3.3:1 (kini
> memakai `--success-text`).

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

---

## TD-9 — Append-only `stock_ledger` hanya dijaga konvensi, bukan database

> **Selesai 2026-10-04.** Migration `database/005_ledger_append_only.sql` menambah dua trigger:
> `trg_stock_ledger_no_update` menolak **setiap** UPDATE, dan `trg_stock_ledger_no_delete` menolak
> DELETE kecuali sesi menyetel `@ioms_allow_ledger_cleanup = 1`. Pengecualian itu hanya dipakai
> pembersihan fixture integration test (`IntegrationTestCase::truncateAll`,
> `SalesOrderFixtures::cleanUpSalesOrderFixtures`), tidak pernah oleh aplikasi. `DROP TABLE`
> (`migrate.php --fresh`) tidak memicu trigger.
>
> Pilihan trigger, bukan privilege: user `ioms_user` dipakai aplikasi **dan** migration, jadi
> mencabut `UPDATE`/`DELETE` darinya akan merusak migration dan seed. Agar user non-SUPER dapat
> membuat trigger saat binary log MySQL 8 aktif, `compose.yaml` kini menyalakan
> `--log-bin-trust-function-creators=1` (container `db` perlu dibuat ulang sekali).
>
> **Bukti.** `LedgerAppendOnlyTest` (6 test): kedua trigger ada; UPDATE ditolak (SQLSTATE 45000),
> juga dengan flag pembersihan; DELETE ditolak; DELETE dengan flag eksplisit berhasil; INSERT tetap
> berjalan. Dengan kedua trigger dihapus sementara di database test, 4 test penolakan gagal —
> test ini memang menangkap kelemahannya. Seluruh suite (termasuk `ConcurrentGoodsIssueTest` yang
> membersihkan ledger) hijau.
>
> **Batas yang tersisa.** Siapa pun yang memegang user database dapat menyetel flag itu atau
> men-DROP trigger-nya. Bedanya, kini keduanya adalah tindakan **eksplisit**, bukan kecelakaan.
> Pemisahan user migration dan user aplikasi adalah langkah berikutnya bila project dilanjutkan.

**Keadaan.** Baris `stock_ledger` tidak pernah di-`UPDATE` atau `DELETE` oleh aplikasi: hanya
`StockService` yang menulis ledger, dan repository-nya tidak punya method update maupun delete.
Tetapi tidak ada trigger maupun pembatasan privilege di MySQL. Satu `UPDATE` manual lewat SQL
client dengan user aplikasi tetap dapat mengubah riwayat stock tanpa jejak.

**Yang sudah ada.** `LedgerReconciliationTest::noLedgerRowIsEverUpdatedOrDeleted` memastikan
jalur aplikasi tidak pernah mengurangi jumlah baris, dan invariant
`SUM(stock_ledger.quantity) = product_stock.quantity` akan menunjukkan ketidakcocokan bila baris
ledger diubah di luar aplikasi.

**Perbaikan ideal.** Trigger `BEFORE UPDATE` dan `BEFORE DELETE` pada `stock_ledger` yang
menolak perubahan, atau user database aplikasi yang hanya diberi `INSERT, SELECT` pada tabel
itu. Belum dikerjakan karena menambah migration dan konfigurasi privilege di luar kebutuhan
brief. Dicatat agar tidak dianggap sudah dijamin.

Sebelumnya kelemahan ini dirujuk dari `erd.md` ke dokumen `sql-training-coverage.md` yang tidak
pernah ada di repository. Rujukan itu kini menunjuk ke sini.

---

## TD-10 — Validasi order memeriksa bahwa referensi **ada**, bukan bahwa ia **aktif**

> **Selesai 2026-10-04.** `Validator::activeById()` (callback mengembalikan status aktif, atau
> null bila record tidak ada) menggantikan `existsById()` untuk customer/supplier dan warehouse di
> `validate()` kedua service order (`existsById()` tetap ada dan masih dipakai `ProductService` untuk
> category, yang memang tidak punya status aktif); line dengan product nonaktif ditolak dengan pesan per baris.
> Karena create dan edit memakai `validate()` yang sama (spec 004 R-005), keduanya ikut
> diperketat — tanpa dua aturan yang berbeda.
>
> **Bukti.** Unit test baru: customer/warehouse nonaktif dan customer tidak dikenal (3, Sales
> Order), supplier/warehouse nonaktif (2, Purchase Order), product nonaktif (2), dan edit Draft
> yang mengarah ke supplier nonaktif (1). Spec 004 FR-009 dan R-005 diselaraskan.

**Keadaan.** `validate()` di `SalesOrderService` dan `PurchaseOrderService` memakai
`existsById()` untuk customer/supplier, warehouse, dan product. Repository `exists()` hanya
memeriksa bahwa baris ada; status `is_active` tidak diperiksa. Yang membatasi pilihan ke
record aktif hanyalah dropdown form, yang memang hanya berisi record aktif. Request yang
dirakit sendiri (DevTools, curl) dapat membuat — dan sejak 004 juga **mengedit** — order yang
mereferensikan customer, supplier, warehouse, atau product yang sudah dinonaktifkan.

**Mengapa tidak diperbaiki di 004.** Edit sengaja memakai `validate()` yang sama dengan create
(research R-005 spec 004): dua aturan berbeda untuk order yang sama akan membuat Draft dapat
dibuat dalam keadaan yang tidak dapat disimpan lagi. Memperketat aturan berarti mengubah perilaku
create juga, jadi itu perubahan tersendiri, bukan bagian fitur edit.

**Dampak.** Rendah: hanya pengguna yang sudah berhak membuat order, dan record nonaktif tetap
ada (tidak pernah di-hard-delete), sehingga tidak ada foreign key yang rusak. Yang dilanggar
adalah aturan bisnis "record nonaktif tidak dapat dipilih di order baru".

**Perbaikan ideal.** Pemeriksaan "ada **dan** aktif" di `validate()` kedua service order —
misalnya `CustomerRepositoryInterface::isActive()` dan padanannya — dipakai create dan edit,
dengan unit test untuk setiap jenis referensi nonaktif.

---

## TD-11 — `SalesOrderService` dan dua controller order melewati batas ukuran class

> **Selesai sebagian 2026-10-04.** Dipecah menurut tanggung jawab, bukan sekadar angka
> (refactor-log R-9 … R-11):
>
> | Class | Sebelum | Sesudah |
> | --- | --- | --- |
> | `SalesOrderService` | 24 method, 497 baris | **19** method, 417 baris |
> | `SalesOrderController` | 27 method, 557 baris | **20** method, 452 baris |
> | `PurchaseOrderController` | 26 method, 544 baris | **20** method, 419 baris |
> | `PurchaseOrderService` | 19 method | 19 method, 382 baris |
> | `SalesOrderApprovalService` (baru) | — | 4 method, 122 baris |
> | `SalesOrderApprovalController` (baru) | — | 6 method, 104 baris |
> | `GoodsIssueController` (baru) | — | 6 method, 130 baris |
> | `GoodsReceiptController` (baru) | — | 7 method, 176 baris |
>
> Seluruh class kini memenuhi Sonar S1448 (≤ 20 method). URL, role, pesan, dan perilaku tidak
> berubah (diverifikasi test dan HTTP).
>
> **Yang tersisa.** Sembilan file di `app/` masih melewati batas 300 baris dari standar SonarQube
> project (diukur 2026-10-04): `StockService` 647, `SalesOrderController` 452,
> `PurchaseOrderController` 419, `SalesOrderService` 417, `ReportService` 414,
> `PurchaseOrderService` 382, `MysqlSalesOrderRepository` 348, `MysqlPurchaseOrderRepository` 320,
> dan `MysqlProductRepository` 301. Sebagian besar isinya adalah docblock yang menjelaskan alasan aturan (constitution VI);
> memangkasnya demi angka akan menghapus penjelasan yang justru diminta. Memecahnya lebih jauh
> (mis. form mapper per order) adalah layer tambahan tanpa masalah nyata saat ini (C-003).

**Keadaan.** Standar SonarQube project (`.rudis/templates/sonarqube-standard.md` §2) membatasi
file ≤ 300 baris; Sonar juga memperingatkan class dengan > 20 method (S1448). Sebelum 004,
`SalesOrderController` (24 method, 479 baris) dan `PurchaseOrderController` (470 baris) sudah
melewatinya, dan `SalesOrderService` (19 method, 405 baris) sudah melewati batas baris. Fitur 004
menambah `update()`, `canEdit()`, `assertMayEdit()`, dan dua helper kecil, sehingga
`SalesOrderService` kini 24 method.

**Mengapa tidak dipecah di 004.** Memecah service dan controller adalah refactor struktural yang
tidak diminta fitur edit, dan constitution C-003 menilai layer tambahan tanpa masalah nyata
sebagai negatif. Method baru sengaja dibuat pendek (masing-masing < 40 baris).

**Perbaikan ideal.** Memisahkan alur edit dan alur approval Sales Order ke class tersendiri
(misalnya `SalesOrderApprovalService`) dan memecah controller per kelompok aksi, dicatat di
refactor log bila dikerjakan.
