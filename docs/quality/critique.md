# Critique exercise dan kritik desain sendiri

Dokumen ini punya dua bagian:

- **Bagian A — DESIGN-04 (wajib).** Kritik terhadap cuplikan kode bermasalah yang
  **disediakan assessor**: smell apa yang ada, prinsip SOLID apa yang dilanggar, dan bagaimana
  seharusnya direfaktor. Perbaikannya tidak perlu diimplementasikan; cukup analisis tertulis
  (brief §3.2, FAQ #5).
- **Bagian B — Kritik terhadap desain sendiri.** Tambahan sukarela. Ditulis agar berguna,
  bukan untuk memuji diri sendiri. Kalau bagian ini tidak menyebut satu pun hal yang
  benar-benar mengganggu, ia gagal sebagai kritik.

---

# Bagian A — DESIGN-04: kritik atas cuplikan dari assessor

> **Status: menunggu cuplikan dari assessor.** Brief menyatakan cuplikan disediakan assessor
> (contoh: satu Service yang menangani validasi, penyimpanan, dan pengiriman notifikasi
> sekaligus). Isi di bawah sengaja **belum diisi**. Mengarang cuplikan sendiri lalu
> mengkritiknya tidak memenuhi DESIGN-04, dan memang bukan itu yang diminta.

## A.1 Cuplikan

```php
// Tempel cuplikan dari assessor di sini, apa adanya, beserta sumbernya.
```

Sumber dan tanggal diterima: _…_

## A.2 Smell yang ditemukan

| # | Smell | Letak (baris / method) | Mengapa bermasalah |
| --- | --- | --- | --- |
| 1 | _mis. Long Method, God Class, Feature Envy, Primitive Obsession, Duplicate Code, Shotgun Surgery_ | | |

## A.3 Prinsip SOLID yang dilanggar

| Prinsip | Dilanggar? | Bukti di cuplikan |
| --- | --- | --- |
| **S**ingle Responsibility | | _alasan untuk berubah yang lebih dari satu_ |
| **O**pen/Closed | | |
| **L**iskov Substitution | | |
| **I**nterface Segregation | | |
| **D**ependency Inversion | | _mis. `new` terhadap kelas konkret, akses langsung ke PDO/superglobal_ |

## A.4 Arah refactor

Urutkan menurut risiko, dari yang paling aman lebih dulu, dan sebutkan tekniknya (Extract
Method, Extract Class, Introduce Parameter Object, Replace Conditional with Polymorphism,
Dependency Injection, …):

1. _…_

**Bentuk sesudah refactor** (sketsa class dan dependency-nya, bukan implementasi penuh):

```text
…
```

**Cara memastikan perilaku tidak berubah:** _test apa yang ditulis lebih dulu sebagai jaring
pengaman._

## A.5 Kaitan dengan project ini

Satu atau dua kalimat: pola yang sama di codebase ini, dan bagaimana ia dicegah. Contohnya,
`StockService` dan `SalesOrderService` menerima dependency lewat constructor dan tidak pernah
membuat `new PDO()` (ARCH-01).

---

# Bagian B — Kritik terhadap desain sendiri

Divalidasi ulang terhadap kode pada **2026-10-03**. Klaim yang ternyata salah sudah dikoreksi,
dan koreksinya disebut terang-terangan di tempatnya.

## B.1 Smell yang masih ada

### B.1.1 `StockService` mulai kepanjangan

`issueWithinTransaction()` dan `receiveWithinTransaction()` masing-masing menjalankan pola dua
fase yang sama: kunci baris, verifikasi seluruhnya, baru menulis. Polanya identik, isinya
berbeda. Sejak perbaikan race condition (keduanya kini juga mengunci baris order lebih dulu),
kemiripannya justru bertambah.

Belum digabung karena abstraksi yang menyatukan keduanya — misalnya "operasi stock dua fase"
yang menerima strategi verifikasi dan strategi penulisan — akan lebih sulit dibaca daripada
duplikasi yang ada sekarang, dan justru menyamarkan alur yang paling kritikal di project ini.
Ini keputusan sadar, bukan kelalaian. Kalau muncul alur stock ketiga, keputusan ini harus
ditinjau ulang.

### B.1.2 ~~Duplikasi state query string di controller~~ — sudah diperbaiki

> **Koreksi 2026-10-03.** Versi sebelumnya menyebut duplikasi `queryState()` dan
> `sortCriteriaFrom()` ada di **tiga** controller, dan bahwa salinan keempat akan melewati
> ambang perbaikan. Validasi menunjukkan `queryState()` sudah ada di **enam** controller
> (Customer, Supplier, User, Product, PurchaseOrder, SalesOrder), identik kecuali daftar
> key-nya. `sortCriteriaFrom()` ada di tiga. Ambang yang dokumen ini tetapkan sendiri sudah
> terlewati tanpa disadari.
>
> **Diperbaiki** dengan Move Method ke `Request::queryState()` dan `Request::sortCriteria()`.
> Setiap controller kini hanya mendeklarasikan `FILTER_KEYS`/`SORT_KEYS` miliknya (152 baris
> salinan dihapus; `RequestTest` ditambahkan). Lihat `refactor-log.md` R-6.

Yang masih tersisa: `SalesOrderController` dan `PurchaseOrderController` tetap menyusun array
data view yang besar dan mirip satu sama lain. Isinya berbeda per halaman, jadi ini bukan
duplikasi yang layak diekstrak.

### B.1.3 `Validator` memakai `mixed` pada titik masuknya

Sudah diberi komentar penjelas dan memang dibenarkan — validator ada untuk mengubah nilai
bertipe tidak menentu menjadi nilai terpercaya (`Validator::raw()`). Tetap saja ini titik
terlemah dari sisi tipe di seluruh codebase, dan bila ada bug tipe yang lolos, kemungkinan
besar lewat sini.

### B.1.4 Fake in-memory adalah permukaan kedua yang harus dijaga

Sebelas repository ditulis dua kali (11 interface, 11 `InMemory*Repository`). Setiap
perubahan kontrak interface menuntut dua pembaruan, dan bila satu tertinggal, unit test bisa
lulus untuk perilaku yang produksinya tidak punya. Ini sudah terjadi (lihat `refactor-log.md`
R-2).

Mitigasinya kini lebih kuat. Integration suite mengeksekusi **126 dari 126** method
repository MySQL (`tech-debt.md` TD-2b), sehingga fake tidak lagi menjadi satu-satunya bukti
bahwa sebuah query berjalan. Biaya pemeliharaan dua implementasi tetap ada.

## B.2 Prinsip SOLID — di mana ditegakkan, di mana tidak

| Prinsip | Penilaian |
| --- | --- |
| **S**ingle Responsibility | Sebagian besar terpenuhi. `MasterDataService` dipecah dengan memindahkan Supplier dan Customer ke `PartyService`. `StockService` sengaja menampung dua alur, dengan alasan yang dicatat di `refactor-log.md`. Parsing state query string kini tinggal di `Request`, bukan di enam controller. |
| **O**pen/Closed | **Tidak sepenuhnya, dan sengaja.** Menambah role baru menuntut perubahan pada `Role`, route table, `DashboardService::forRole()`, dan view-nya. Dengan tiga role tetap yang dinyatakan brief, membangun mekanisme role yang extensible adalah over-engineering (spec C-003). `match` yang exhaustive juga membuat PHPStan menunjukkan sendiri setiap tempat yang perlu disentuh. |
| **L**iskov | Terpenuhi. `Mysql*Repository` dan `InMemory*Repository` dapat saling menggantikan, tetapi kelemahannya sudah terbukti: substitusi yang benar secara tipe bisa tetap salah secara perilaku bila fake-nya mengembalikan bentuk data yang berbeda. |
| **I**nterface Segregation | **Paling lemah.** `ProductRepositoryInterface` punya **14** method. `ProductService` memakai hampir semuanya, `DashboardService` hanya **tiga** (`countBy`, `lowStock`, `totalInventoryValue`), sedangkan `PurchaseOrderService`, `SalesOrderService`, dan `StockService` hanya **satu** (`findById`). Interface per konsumen akan lebih tepat, tetapi berarti banyak interface kecil untuk satu implementasi MySQL. Biayanya lebih besar daripada masalah yang dipecahkan pada ukuran project ini. _(Koreksi 2026-10-04: SKU otomatis menambah `highestSkuSequence`, jadi kini 14. Koreksi 2026-10-03: versi sebelumnya menyebut 14 method, `ReportService` sebagai konsumen satu method — padahal ia tidak memakai interface ini sama sekali — dan `DashboardService` dua method.)_ |
| **D**ependency Inversion | Terpenuhi di tempat yang penting. Service bergantung pada interface repository dan `TransactionRunner`. Controller bergantung pada Service konkret, dan itu keputusan sadar (lihat `class-diagram-as-built.md`). |

## B.3 Kesalahan terbesar dalam pengerjaan ini

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

Kesalahan yang sama terulang dalam bentuk lebih kecil: dokumen ini sendiri sempat menyatakan
duplikasi controller masih "di bawah ambang", padahal sudah dua kali lipatnya. Klaim yang tidak
pernah dicek ulang terhadap kode cepat berubah menjadi tidak benar.

Pelajarannya dicatat di `tech-debt.md` TD-2.

## B.4 Arah refactoring bila pekerjaan dilanjutkan

Berurutan menurut manfaat nyata, bukan menurut kerapian. Status per **2026-10-03**:

1. **Jalankan kedua suite secara otomatis.** _Sebagian._ `composer check` menjalankan seluruh
   gate dalam satu perintah, dan schema test dibangun lebih dulu. CI berada di luar scope brief
   (§4.3); bila project dilanjutkan, satu job CI yang menjalankan `composer check` adalah
   langkah berikutnya.
2. ~~**Buat transaction bersarang benar-benar bekerja** (SAVEPOINT).~~ _Selesai_ — `tech-debt.md`
   TD-1.
3. ~~**Tarik state query string ke satu helper.**~~ _Selesai_ — `refactor-log.md` R-6.
4. ~~**Uji jalur filesystem `ProductImageService`.**~~ _Selesai sebagian_ — `tech-debt.md` TD-3.
   Jalur sukses `store()` tidak dapat diuji dari CLI.
5. **Pecah `ProductRepositoryInterface`** hanya bila muncul implementasi kedua yang
   sesungguhnya. Sebelum itu, memecahnya hanya memindahkan kerumitan.
6. **Gabungkan dua alur dua fase di `StockService`** hanya bila muncul alur stock ketiga
   (lihat B.1.1).
