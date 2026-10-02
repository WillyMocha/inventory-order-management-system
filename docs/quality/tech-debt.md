# Tech debt

Jalan pintas yang diambil dan perbaikan idealnya, dicatat apa adanya. Daftar ini ditulis agar
berguna, bukan agar terlihat rapi.

---

## TD-1 — `Database::transaction()` mem-passthrough panggilan bersarang

**Keadaan.** Bila sudah ada transaction yang berjalan, `transaction()` hanya memanggil
callback-nya tanpa membuka transaction sendiri. Di produksi ini benar — hanya transaction
terluar yang boleh commit.

**Masalahnya.** `IntegrationTestCase` membungkus setiap test dalam transaction lalu me-rollback
saat teardown. Akibatnya, **di dalam integration test, transaction milik Service tidak pernah
terbentuk — dan rollback-nya tidak pernah terjadi**. Test yang memeriksa rollback justru
melihat penulisan parsial bertahan, dan gagal karena alasan yang menyesatkan.

**Biaya yang sudah terjadi.** `GoodsReceiptTest` gagal dengan pesan yang terbaca seolah-olah
rollback produksi rusak. Butuh penelusuran untuk memastikan bahwa yang cacat adalah harness,
bukan aplikasinya.

**Penanda saat ini.** Test semacam itu wajib meng-override `wrapsInTransaction()` menjadi
`false` dan membersihkan fixture-nya sendiri. Sudah dilakukan `ConcurrentGoodsIssueTest` dan
`GoodsReceiptTest`, dan disebut pada docblock `IntegrationTestCase`.

**Perbaikan ideal.** Jangan menggantungkannya pada kedisiplinan penulis test berikutnya.
Pilihannya: (a) memakai SAVEPOINT untuk transaction bersarang sehingga rollback dalam benar-benar
bekerja, atau (b) membuat `IntegrationTestCase` gagal keras bila sebuah test memanggil Service
yang membuka transaction sementara pembungkusnya masih aktif.

---

## TD-2 — Integration suite tidak pernah dijalankan sampai Phase 10

**Keadaan.** Suite ditulis sejak Phase 4, tetapi schema database test belum pernah dibangun
(`composer db:test`), sehingga suite-nya tidak pernah dieksekusi sekalipun.

**Biaya yang sudah terjadi.** Sangat besar. Eksekusi pertama menghasilkan **67 error dari 74
test**, dan salah satunya adalah bug production yang membuat **setiap goods issue gagal**
(lihat `refactor-log.md` R-1). Bug itu hidup melewati enam phase tanpa terlihat.

**Pelajarannya, dan ini yang sesungguhnya penting.** Test yang tidak dijalankan tidak
memberikan jaminan apa pun — ia hanya memberi rasa aman. Selama enam phase, keberadaan
`ConcurrentGoodsIssueTest` dianggap sebagai bukti ARCH-02, padahal test itu belum pernah
dieksekusi satu kali pun.

**Perbaikan ideal.** Kedua suite dijalankan di CI pada setiap perubahan. Tanpa itu,
pengulangannya bergantung pada ingatan.

---

## TD-2b — Fake in-memory tidak menjalankan SQL, dan itu sudah dua kali menyembunyikan bug

**Keadaan.** `InMemory*Repository::search()` mengimplementasikan pencarian dengan
`str_contains` di PHP. SQL-nya tidak pernah dieksekusi, sehingga unit test dapat lulus penuh
sementara query yang sesungguhnya tidak dapat berjalan sama sekali.

**Biaya yang sudah terjadi — dua kali, dengan bug yang bentuknya sama persis.**

1. `MysqlProductStockRepository::adjust()` — setiap goods issue gagal (lihat `refactor-log.md`
   R-1).
2. **Seluruh enam fitur search** membalas 500 karena satu nama placeholder dipakai dua kali
   dalam satu statement (`SQLSTATE[HY093]`). Dilaporkan user, bukan ditemukan test.

Keduanya lolos seluruh unit suite karena unit suite memang tidak pernah menyentuh MySQL.

**Mitigasi sekarang.** `tests/Integration/RepositorySearchTest.php` menjalankan query search
yang sungguhan untuk keenam repository, menguji **kedua sisi** setiap klausa OR. Sudah
diverifikasi benar-benar gagal pada kode lama (7 error).

**Perbaikan ideal.** Setiap method repository yang membangun SQL secara dinamis — search,
filter, sort, rentang tanggal — membutuhkan sekurang-kurangnya satu integration test yang
mengeksekusinya. Fake berguna untuk menguji aturan bisnis, tetapi **tidak pernah** menjadi
bukti bahwa SQL-nya sah.

---

## TD-3 — `ProductImageService::store/read/delete` tanpa unit test

**Keadaan.** Ketiganya menyentuh filesystem, sedangkan unit suite dijalankan tanpa filesystem.

**Mitigasi.** Bagian yang memuat keputusan sudah teruji terpisah: `validate()` (tipe dari isi
file lewat `finfo`, batas ukuran), `generateStoredName()` (nama acak), dan `pathFor()`
(penyimpanan di luar document root). Sisanya pembungkus tipis `move_uploaded_file`,
`file_get_contents`, dan `unlink`.

**Perbaikan ideal.** Integration test yang memakai direktori sementara, memverifikasi file
benar-benar mendarat di luar document root dan benar-benar terhapus.

---

## TD-4 — Tidak ada test otomatis untuk `stock-lookup.js`

**Keadaan.** Modul ini memanggil endpoint availability dan menampilkan angka panduan di
samping setiap line Sales Order. Diperiksa hanya dengan `node --check`; alur fetch,
pembatalan request yang saling menyusul, dan debounce-nya belum pernah diuji.

**Mengapa diterima.** Brief melarang framework dan build step; menambahkan test runner
JavaScript berarti menambah toolchain untuk satu modul. Perilakunya juga murni panduan — form
tetap dapat dikirim tanpa JavaScript sama sekali, dan kecukupan stock yang mengikat diperiksa
server di dalam transaction.

**Perbaikan ideal.** Bila JavaScript bertambah banyak, satu test runner tanpa build step
(misalnya `node --test`) menjadi sepadan.

---

## TD-5 — `.env.example` berisi kredensial development yang sesungguhnya

**Keadaan.** `DB_PASSWORD=ioms_secret` dan `DB_ROOT_PASSWORD=root_secret` di-commit, dan
memang itulah yang dipakai stack lokal setelah `cp .env.example .env`.

**Mengapa diterima.** Nilainya hanya memberi akses ke MySQL di dalam container yang tidak
terekspos ke luar host, dan quickstart memang mengandalkan `cp` yang langsung berjalan.
Tidak ada kredensial produksi di repository.

**Perbaikan ideal.** Untuk deployment sungguhan, nilai ini wajib diganti dan disuntikkan lewat
secret manager, bukan lewat file di repository.

---

## TD-6 — Tidak ada index khusus untuk kolom sort

**Keadaan.** Sort memakai allowlist nama kolom, tetapi tidak ada index yang dibuat khusus
untuknya. Pada volume brief (NFR-011: 30 product, 25 order) ini tidak terasa.

**Perbaikan ideal.** Bila datanya tumbuh, kolom sort yang sering dipakai — `order_date`,
`product.name` — memerlukan index-nya sendiri. Ukur dulu, jangan menebak.

---

## TD-7 — Pemeriksaan visual belum pernah dilakukan di browser

**Keadaan.** Seluruh halaman sudah dipastikan mengembalikan 200 dengan isi yang benar lewat
HTTP, dan template-nya dieksekusi untuk seluruh keadaan (terisi, kosong, error). Tetapi
**spacing, keselarasan grid, harmoni warna, dan perilaku responsive pada 360px belum pernah
benar-benar dilihat** — tidak ada browser tooling pada sesi pengerjaan.

**Perbaikan ideal.** T133 dan T149 menuntut pemeriksaan dan screenshot sungguhan pada 360px
dan desktop. Itu harus dijalankan manusia di browser sebelum submission.
