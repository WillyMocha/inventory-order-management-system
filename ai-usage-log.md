# AI usage log

Catatan penggunaan AI pada project ini, sesuai ketentuan submission. Ditulis apa adanya —
termasuk bagian ketika output AI **salah** dan harus ditolak.

**Tool**: Claude (Anthropic) melalui Claude Code, dijalankan dengan alur Spec-Driven
Development (`/rudis.specify` → `/rudis.plan` → `/rudis.tasks` → `/rudis.implement` per phase).

---

## Ringkasan per penggunaan

| # | Tujuan | Ringkasan prompt (disanitasi) | Output dipakai? | Verifikasi |
| --- | --- | --- | --- | --- |
| 1 | Menyusun spec dari Project Brief PDF | "Ubah brief ini menjadi spec: user story, FR, NFR, constraint" | Ya, setelah disunting | Ditelusuri balik ke PDF; asumsi yang tidak ada di brief ditandai A-001…A-012 dan diklarifikasi terpisah |
| 2 | Keputusan desain beserta alternatifnya | "Untuk tiap keputusan, catat alternatif yang ditolak dan alasannya" | Ya | 14 keputusan R-001…R-014; pilihan concurrency ditentukan pemilik project, bukan AI |
| 3 | Schema database | "Turunkan schema dari resource model sumber, mirror apa adanya" | Ya | Diperiksa kolom demi kolom terhadap digest PDF; tidak ada merge atau rename |
| 4 | Implementasi per phase | "Kerjakan phase N sesuai tasks.md" | Sebagian | Setiap phase melewati gate: PHPStan level 6, PHPCS PSR-12, unit test |
| 5 | Unit dan integration test | "Tulis test lebih dulu, pastikan gagal sebelum implementasi" | Ya | Red-green diverifikasi tiap kali; lihat catatan #3 di bawah |
| 6 | Dokumen evidence (ADR, refactor log, kritik) | "Tulis ADR/kritik berdasarkan kode yang benar-benar ada" | Ya | Setiap klaim ditelusuri ke file dan test yang disebut |
| 7 | Fitur 004 edit order Draft (spec → plan → tasks → analyze → implement) | "Tambahkan fitur edit untuk order Draft, aturan di service (hanya Draft, Sales hanya order miliknya)" | Ya, setelah tiga keputusan izin dijawab owner (Q1–Q3) dan temuan `/rudis.analyze` diterapkan | Test ditulis lebih dulu dan gagal dulu; MySQL integration; verifikasi HTTP per role di browser headless; data demo dipulihkan; lihat catatan #5 |
| 8 | Redesign dialog konfirmasi (2026-10-04) | "Ganti semua alert menjadi modal, CSS native tanpa library" | Ya | Modal diperiksa di browser headless pada 375px dan desktop; tanpa library baru |
| 9 | SKU otomatis dan read-only (2026-10-04) | "SKU terisi otomatis dari sequence terakhir, field read-only" | Ya, setelah owner memilih SKU tetap saat edit | Unit + integration test; batas SKU-999999 dibahas dan perilakunya diputuskan owner |
| 10 | Perbaikan tech debt TD-9…TD-11 (`2d25a53`) | "Perbaiki TD-9 sampai TD-11 di tech-debt.md" | Ya, setelah owner memilih ketiganya dikerjakan di kode | Trigger append-only dibuktikan dengan mutation check (test gagal saat trigger dilepas); smoke test HTTP controller baru; `composer check` hijau |
| 11 | Coverage SonarQube dan test controller order (`de0a94d`) | "SonarQube gagal di bagian coverage" | Ya | Akar masalah dibuktikan dulu (tidak ada coverage driver dan `reportPaths`); 50 test HTTP baru; coverage file baru 90–100% diukur dari `coverage/clover.xml` |
| 12 | Export CSV Warehouse Staff diselaraskan dengan brief (`e241412`, `d691222`) | "Bukannya Warehouse Staff hanya laporan stok seperti di brief?" | Ya, setelah owner menyetujui | Pertanyaan owner yang menemukan penyimpangan, bukan AI; 11 test akses; status 200/403 tiap role dicek di aplikasi yang berjalan |
| 13 | Pemeriksaan API-01 dan JOB-01 terhadap brief (`31c1074`) | "Apakah API-01 sudah terpenuhi? Bagaimana cara test JOB-01?" | Ya | Tiga skenario bukti (200/401/404) dijalankan dengan `curl`; contoh quickstart memakai SKU yang tidak ada di seed dan diperbaiki |
| 14 | Bonus grafik stock movement, spec 005 (`7d38520`) | "Dashboard grafik SVG buatan sendiri dari stock_ledger untuk Admin dan Warehouse Staff" (spec → plan → tasks → analyze → implement) | Ya, setelah tiga pertanyaan dijawab owner (Q1–Q3) dan 10 temuan `/rudis.analyze` diterapkan | Test lebih dulu dan gagal dulu; grafik = CSV per hari dibuktikan di MySQL; verifikasi visual 1280/360px dan navigasi keyboard di browser |
| 15 | SOP penggunaan aplikasi (PDF, tidak di-commit) | "Buat SOP semua role dalam PDF dengan screenshot" | Ya, sebagai dokumen internal | Screenshot diambil dari aplikasi yang berjalan; diperbarui setiap fitur berubah (v1.0–v1.4) |

---

## Hal yang layak dicatat, karena di sinilah AI tidak dapat dipercaya begitu saja

### 1. Diagnosis pertama yang salah, dan mahal

Ketika integration suite akhirnya dijalankan dan menghasilkan 67 error, AI menyimpulkan
seluruhnya berasal dari harness test — kesimpulan yang rapi, masuk akal, dan **salah**.

Yang benar baru terlihat setelah bug-nya direproduksi **terisolasi dengan SQL langsung**,
tanpa menebak: `MysqlProductStockRepository::adjust()` mengandung cacat yang membuat setiap
goods issue gagal. Sepuluh dari tiga belas kegagalan berasal dari sana, bukan dari test.

Pelajarannya: penjelasan yang koheren dari AI bukan bukti. Reproduksi terisolasi adalah bukti.

### 2. Output yang ditolak

| Ditolak | Alasan |
| --- | --- |
| Usulan menambahkan DI container | Dilarang brief dan constitution C-002 |
| Usulan memecah `StockService` menjadi issue/receipt terpisah | Akan membuat dua tempat dapat mengubah stock; melanggar ARCH-02 |
| Usulan menambah kolom `version` untuk optimistic locking | Menyimpang dari data model sumber tanpa alasan yang cukup |
| Fake in-memory yang mengembalikan kolom lebih sedikit daripada query MySQL | Membuat unit test lulus untuk bentuk data yang produksinya tidak punya |
| Menandai task T138/T139 selesai | Kesalahan pola `sed` dari AI sendiri; dikoreksi setelah ketahuan |

### 5. Fitur 004: klaim AI yang dikoreksi sebelum menjadi kode

| Klaim / output AI | Yang sebenarnya | Tindakan |
| --- | --- | --- |
| Spec FR-009: validasi order memeriksa customer/supplier/warehouse/product "exist **and be active**" | `validate()` hanya memeriksa **ada**; "aktif" hanya dijaga dropdown form | FR-009 disesuaikan saat planning; celahnya dicatat sebagai tech debt TD-10, bukan diam-diam dianggap aman |
| Quickstart: PO Draft 12 dan 13 sama-sama dibuat `warehouse1` | PO 13 dibuat `admin` (diperiksa dengan query) | Skenario quickstart diperbaiki — justru menjadi uji yang lebih baik (WS ditolak pada PO buatan Admin) |
| Tasks T010: uji race lewat "test-only subclass" fake repository | Kedua fake bersifat `final` | Ditemukan `/rudis.analyze`; diganti hook `failNextUpdateDraft()` pada fake |
| "Baseline hijau" setelah perubahan SKU sebelumnya | PHPCS mengembalikan 1 warning (baris 124 karakter) sehingga `composer check` exit 1 — sebelumnya hanya ekor output yang dibaca | Baris diperbaiki di T001; exit code PHPCS kini selalu diperiksa langsung |
| Judul form edit tampil benar | Pada 360px nomor order terpotong di tanda hubung — baru terlihat setelah screenshot dilihat | `.page-title .tabular { white-space: nowrap; }`, screenshot diambil ulang |

### 6. Setelah fitur 004: koreksi selama pengerjaan

| Klaim / output AI | Yang sebenarnya | Tindakan |
| --- | --- | --- |
| Menambahkan `// phpcs:ignore` agar baris panjang lolos PHPCS | Constitution melarang menekan pemeriksaan | Ditarik; baris dipecah sungguhan |
| Coverage SonarQube 0% dianggap kurang test | Laporan coverage tidak pernah dibuat maupun dikirim ke SonarQube | Diperbaiki konfigurasinya dulu; baru setelah diukur, controller yang benar-benar 0% diberi test |
| Export CSV untuk Warehouse Staff dianggap sesuai karena tercatat di D-03 | D-03 hanya membahas cakupan "status order", bukan role; brief dan spec 001 menyebut Warehouse Staff hanya report stock | Route, controller, dan view dibatasi; D-03 dikoreksi dan penyimpangan lamanya dicatat terbuka |
| Grafik 005 "lulus" setelah test render hijau | Halaman melebar 21px di 360px karena `<table class="visually-hidden">` (tabel tidak mau selebar 1px) — baru terlihat lewat pengukuran di browser | Tabel dibungkus `<div class="visually-hidden">`; ukuran ulang di 360px tanpa overflow |
| Garis bantu sumbu grafik selalu 4 langkah (rencana) | Sumbu 10 dibagi 4 menghasilkan label 2,5 | Langkah dipilih 4 atau 5 agar label selalu bulat; deviasi dicatat di implementation log |
| Script rudis `setup-plan.ps1` menulis plan ke `specs/fix/correct-business-flow/` | Script menurunkan folder dari nama branch, bukan folder spec | Folder salah dihapus; template disalin manual ke `specs/005-…` |

### 3. Test yang ditulis tetapi tidak dijalankan

Integration suite ditulis sejak Phase 4 dan **tidak pernah dieksekusi sampai Phase 10** karena
schema database test belum dibangun. Selama enam phase, keberadaan file test dianggap sebagai
jaminan.

Ini kegagalan proses, bukan kegagalan tool — tetapi AI tidak mengangkatnya sebagai masalah
sampai suite-nya benar-benar dijalankan. Dicatat di `docs/quality/critique.md`.

### 4. Regression test diverifikasi benar-benar menangkap bug-nya

Setelah bug `adjust()` diperbaiki, `StockAdjustmentTest` diuji dengan **mengembalikan SQL lama
sementara**: 4 error muncul, lalu perbaikan dipasang lagi dan 8/8 lulus. Regression test yang
tidak gagal pada kode lama tidak membuktikan apa pun.

---

## Verifikasi yang berlaku untuk seluruh output

Tidak ada kode yang masuk tanpa melewati:

1. **PHPStan level 6** — tanpa baseline, tanpa `@phpstan-ignore`, tanpa penurunan level
2. **PHP_CodeSniffer PSR-12** — termasuk sniff `declare(strict_types=1)`
3. **Unit test** — 491 test tanpa database, session, atau network (angka 2026-10-04)
4. **Integration test** — 266 test terhadap MySQL 8 sungguhan (angka 2026-10-04)
5. **Verifikasi manual lewat HTTP** untuk alur yang benar-benar penting: goods issue sungguhan
   diperiksa menurunkan stock, menulis dua baris ledger, dan memindahkan order ke Fulfilled
6. **Verifikasi tampilan di browser headless** untuk perubahan UI — screenshot dilihat, overflow
   horizontal di 360px diukur, bukan diasumsikan

Angka yang disebut di seluruh dokumentasi berasal dari perintah yang benar-benar dijalankan,
bukan dari perkiraan.

---

## Tidak ada data sensitif yang dikirim

Tidak ada kredensial produksi, token, atau data pribadi yang dimasukkan ke dalam prompt.
Kredensial yang muncul di repository hanyalah nilai development untuk database di dalam
container (`.env.example`), dan itu pun dicatat sebagai tech debt TD-5.
