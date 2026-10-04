# ADR-001 — Repository interface, bukan PDO langsung di Service

**Status**: Diterima · **Tanggal**: 2026-09-10 · **Sumber**: research R-003, ARCH-01

## Konteks

Brief menuntut pemisahan tiga lapis Controller → Service → Repository beserta **dependency
inversion pada boundary repository**. Yang harus diputuskan: bagaimana Service memperoleh
datanya.

Dua tekanan berlawanan. Di satu sisi, aturan bisnis inti — pencegahan oversell, segregation
of duties pada approval Sales Order — harus dapat di-unit-test dengan cepat dan deterministik.
Di sisi lain, constitution Principle I melarang lapisan tambahan yang tidak menyelesaikan
masalah nyata, dan brief melarang ORM maupun DI container.

Menaruh PDO langsung di dalam Service akan mengikat setiap unit test pada MySQL yang berjalan.
Aturan seperti "approver tidak boleh sama dengan pembuat order" tidak ada hubungannya dengan
database, tetapi tetap akan menuntut database untuk diuji.

## Keputusan

Setiap Service bergantung pada **interface** repository, bukan pada implementasi konkretnya.

```
app/Repository/
├── ProductRepositoryInterface.php      ← yang dilihat Service
├── Mysql/MysqlProductRepository.php    ← produksi (PDO)
└── (tests/Unit/Fake/InMemoryProductRepository.php  ← test)
```

Object graph dirakit dengan tangan di satu file, `config/container.php` — sebuah function biasa
yang mengembalikan object yang sudah jadi, bukan library container.

Larangan yang ditegakkan: tidak ada `new PDO()`, `$_SESSION`, `$_POST`, atau superglobal apa
pun di dalam Service maupun Entity. Acting user **di-pass sebagai argument**, tidak pernah
dibaca dari session.

## Konsekuensi

**Yang diperoleh.**

Unit suite berjalan tanpa database sama sekali, dalam ± 10 detik (jumlah test terkini di
`docs/testing/test-results.md`). Aturan approval
diuji dengan meneruskan dua object `User` berbeda ke `SalesOrderApprovalService::approve()`
(sebelum tech-debt TD-11: `SalesOrderService::approve()`) — tidak
ada session, tidak ada HTTP, tidak ada MySQL.

Object graph terbaca sekaligus dalam satu file. Saat menelusuri class diagram ke kode, seluruh
konstruksinya ada di satu tempat, bukan tersebar pada anotasi.

Fake in-memory memaksa interface tetap jujur. Ketika `InMemorySalesOrderRepository::ordersBetween()`
ternyata mengembalikan kolom lebih sedikit daripada versi MySQL-nya, itu adalah tanda bahwa
kontrak interface-nya kurang tegas — dan diperbaiki, bukan didiamkan.

**Yang dibayar.**

Setiap repository ditulis dua kali. Untuk sebelas repository, itu biaya nyata.

Fake bisa **berbohong**. Inilah risiko terbesar pendekatan ini, dan risikonya terbukti: fake
ledger sempat mengembalikan bentuk baris yang lebih miskin daripada query MySQL-nya, sehingga
unit test bisa lulus untuk bentuk data yang produksinya tidak pernah hasilkan. Mitigasinya
bukan kepercayaan, melainkan integration test terhadap MySQL sungguhan untuk setiap jaminan
yang benar-benar bergantung pada database.

Pelajaran yang paling mahal: `MysqlProductStockRepository::adjust()` mengandung bug yang
membuat **setiap goods issue gagal**, dan fake in-memory-nya sama sekali tidak dapat
menangkapnya karena bug-nya ada pada perilaku CHECK constraint MySQL. Abstraksi repository
menyembunyikan database dari Service — dan sekaligus menyembunyikan bug database dari unit
test. Lihat ADR-002 dan `docs/quality/refactor-log.md`.

## Alternatif yang ditolak

| Alternatif | Alasan ditolak |
| --- | --- |
| **PDO langsung di Service** | Mengikat setiap unit test pada MySQL, dan menghapus dependency inversion yang diminta ARCH-01. |
| **PHP-DI / Symfony DI** | Dilarang tabel teknologi brief dan constitution C-002. |
| **Service locator** | Menyembunyikan dependency, sehingga justru meruntuhkan testability yang sedang diuji ARCH-01. |
| **Auto-wiring berbasis attribute buatan sendiri** | Menulis ulang container — persis yang dilarang. |
| **ORM (Doctrine/Eloquent)** | Dilarang brief. |

## Rujukan

- research R-003, ARCH-01, constitution Principle I
- `config/container.php` — seluruh object graph
- `tests/Unit/Fake/` — implementasi in-memory
- ADR-002 — keputusan concurrency yang bertumpu pada boundary ini
