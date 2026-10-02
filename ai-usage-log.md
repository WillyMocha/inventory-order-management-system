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
3. **Unit test** — 363 test tanpa database, session, atau network
4. **Integration test** — 82 test terhadap MySQL 8 sungguhan
5. **Verifikasi manual lewat HTTP** untuk alur yang benar-benar penting: goods issue sungguhan
   diperiksa menurunkan stock, menulis dua baris ledger, dan memindahkan order ke Fulfilled

Angka yang disebut di seluruh dokumentasi berasal dari perintah yang benar-benar dijalankan,
bukan dari perkiraan.

---

## Tidak ada data sensitif yang dikirim

Tidak ada kredensial produksi, token, atau data pribadi yang dimasukkan ke dalam prompt.
Kredensial yang muncul di repository hanyalah nilai development untuk database di dalam
container (`.env.example`), dan itu pun dicatat sebagai tech debt TD-5.
