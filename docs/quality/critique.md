# Kritik terhadap desain sendiri

DESIGN-04. Ditulis untuk berguna, bukan untuk memuji diri sendiri. Kalau bagian ini tidak
menyebut satu pun hal yang benar-benar mengganggu, ia gagal sebagai kritik.

---

## 1. Smell yang masih ada

### 1.1 `StockService` mulai kepanjangan

`issueWithinTransaction()` dan `receiveWithinTransaction()` masing-masing menjalankan pola dua
fase yang sama: kunci baris, verifikasi seluruhnya, baru menulis. Polanya identik, isinya
berbeda.

Belum digabung karena abstraksi yang menyatukan keduanya — misalnya "operasi stock dua fase"
yang menerima strategi verifikasi dan strategi penulisan — akan lebih sulit dibaca daripada
duplikasi yang ada sekarang, dan justru menyamarkan alur yang paling kritikal di project ini.
Ini keputusan sadar, bukan kelalaian. Kalau muncul alur stock ketiga, keputusan ini harus
ditinjau ulang.

### 1.2 Controller menyusun banyak array untuk view

`SalesOrderController` dan `PurchaseOrderController` menyusun array data view yang cukup besar
dan mirip satu sama lain. Ada duplikasi nyata pada pembentukan `queryState()` dan
`sortCriteriaFrom()` di tiga controller.

Perbaikan yang tepat adalah satu trait atau helper kecil untuk state query string. Tidak
dikerjakan karena tiga salinan masih di bawah ambang yang membuat perubahan berisiko — tetapi
salinan keempat akan melewatinya.

### 1.3 `Validator` memakai `mixed` pada titik masuknya

Sudah diberi komentar penjelas dan memang dibenarkan — validator ada untuk mengubah nilai
bertipe tidak menentu menjadi nilai terpercaya. Tetap saja ini titik terlemah dari sisi tipe di
seluruh codebase, dan bila ada bug tipe yang lolos, kemungkinan besar lewat sini.

### 1.4 Fake in-memory adalah permukaan kedua yang harus dijaga

Sebelas repository ditulis dua kali. Setiap perubahan kontrak interface menuntut dua
pembaruan, dan bila satu tertinggal, unit test bisa lulus untuk perilaku yang produksinya
tidak punya. Ini sudah terjadi sekali (lihat `refactor-log.md` R-2).

---

## 2. Prinsip SOLID — di mana ditegakkan, di mana tidak

| Prinsip | Penilaian |
| --- | --- |
| **S**ingle Responsibility | Sebagian besar terpenuhi. `MasterDataService` dipecah dengan memindahkan Supplier dan Customer ke `PartyService`. `StockService` sengaja menampung dua alur, dengan alasan yang dicatat di `refactor-log.md`. |
| **O**pen/Closed | **Tidak sepenuhnya, dan sengaja.** Menambah role baru menuntut perubahan pada `Role`, route table, `DashboardService::forRole()`, dan view-nya. Dengan tiga role tetap yang dinyatakan brief, membangun mekanisme role yang extensible adalah over-engineering (spec C-003). `match` yang exhaustive juga membuat kompilernya sendiri menunjukkan setiap tempat yang perlu disentuh. |
| **L**iskov | Terpenuhi. `Mysql*Repository` dan `InMemory*Repository` dapat saling menggantikan — dan kelemahannya sudah terbukti: substitusi yang benar secara tipe bisa tetap salah secara perilaku bila fake-nya mengembalikan bentuk data yang berbeda. |
| **I**nterface Segregation | **Paling lemah.** `ProductRepositoryInterface` punya 14 method; `ReportService` hanya butuh satu, `DashboardService` hanya butuh dua. Interface per konsumen akan lebih tepat, tetapi berarti banyak interface kecil untuk satu implementasi MySQL — biayanya lebih besar daripada masalah yang dipecahkan pada ukuran project ini. |
| **D**ependency Inversion | Terpenuhi di tempat yang penting. Service bergantung pada interface repository; Controller bergantung pada Service konkret, dan itu keputusan sadar (lihat `class-diagram-as-built.md`). |

---

## 3. Kesalahan terbesar dalam pengerjaan ini

Bukan soal struktur kode, melainkan cara kerja.

**Integration suite ditulis tetapi tidak pernah dijalankan selama enam phase.** Selama itu,
keberadaan `ConcurrentGoodsIssueTest` dianggap sebagai bukti bahwa ARCH-02 aman. Ia bukan
bukti — ia hanya file.

Saat akhirnya dijalankan, 67 dari 74 test error, dan salah satu penyebabnya adalah bug
production yang membuat **setiap goods issue gagal**. Fitur inti FR-020 tidak berfungsi sama
sekali, melewati enam phase, di area yang justru dinyatakan CLAUDE.md sebagai paling kritikal.

Ada pula kesalahan diagnosis yang layak dicatat: pada pemeriksaan pertama, seluruh kegagalan
disimpulkan berasal dari harness test — kesimpulan yang nyaman dan salah. Yang benar baru
terlihat setelah bug-nya direproduksi terisolasi dengan SQL langsung, tanpa menebak.

Pelajarannya dicatat di `tech-debt.md` TD-2.

---

## 4. Arah refactoring bila pekerjaan dilanjutkan

Berurutan menurut manfaat nyata, bukan menurut kerapian:

1. **Jalankan kedua suite di CI.** Satu-satunya perbaikan yang mencegah terulangnya kesalahan
   terbesar di atas. Segala hal lain di daftar ini kalah penting.
2. **Buat transaction bersarang benar-benar bekerja** (SAVEPOINT) atau gagal keras. Sekarang
   kebenarannya bergantung pada penulis test berikutnya yang ingat meng-override
   `wrapsInTransaction()`.
3. **Tarik state query string ke satu helper** begitu controller keempat membutuhkannya.
4. **Uji jalur filesystem `ProductImageService`** dengan integration test berdirektori
   sementara — upload adalah permukaan serangan, dan bagian yang belum teruji justru yang
   menyentuh disk.
5. **Pecah `ProductRepositoryInterface`** hanya bila muncul implementasi kedua yang
   sesungguhnya. Sebelum itu, memecahnya hanya memindahkan kerumitan.
