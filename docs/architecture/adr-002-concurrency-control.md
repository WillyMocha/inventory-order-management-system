# ADR-002 — Pencegahan oversell dengan `SELECT ... FOR UPDATE`

**Status**: Diterima · **Tanggal**: 2026-09-10 · **Sumber**: research R-002, ARCH-02

## Konteks

Dua permintaan goods issue dapat tiba bersamaan untuk pasangan (product, warehouse) yang sama.
Tanpa penjagaan, keduanya membaca quantity yang sama, keduanya menganggap stock cukup, dan
keduanya menulis — sehingga stock terjual melebihi yang ada.

Skenario konkretnya:

```
Stock tersisa: 1 unit.

Request A                          Request B
--------------------------------   --------------------------------
baca quantity  -> 1
                                   baca quantity  -> 1
cukup? 1 >= 1  -> ya
                                   cukup? 1 >= 1  -> ya
tulis ledger -1
kurangi stock -> 0
                                   tulis ledger -1
                                   kurangi stock -> -1   ← OVERSELL
```

CLAUDE.md menyatakan hal ini eksplisit: bila oversell dapat direproduksi assessor, itu
**critical failure**. Ada pula invariant yang harus selalu berlaku (NFR-002):
`SUM(stock_ledger.quantity) = product_stock.quantity` untuk setiap pasangan (product, warehouse).

## Keputusan

**Pessimistic row locking dengan `SELECT ... FOR UPDATE` di dalam transaction eksplisit.**

`StockService::issueGoods()` menjalankan, seluruhnya dalam satu transaction:

1. Kunci baris `sales_order` (`SalesOrderRepositoryInterface::lockForUpdate()`) dan periksa
   status `Approved` **di bawah lock itu**, bukan dari pembacaan sebelum transaction
2. Kunci baris `product_stock` untuk setiap pasangan (product, warehouse) — `SELECT ... FOR UPDATE`
3. Baca ULANG quantity di bawah lock itu
4. Verifikasi kecukupan **seluruh** line lebih dulu (fase 1)
5. Baru menulis: baris `stock_ledger`, lalu pengurangan `product_stock` (fase 2)
6. Ubah status menjadi `Fulfilled` lewat compare-and-set
   (`UPDATE ... WHERE status = 'Approved'`)
7. Commit

Request kedua untuk **order yang sama** menunggu di langkah 1, lalu melihat order yang sudah
`Fulfilled` dan ditolak. Tanpa langkah ini, keduanya dapat sama-sama melihat `Approved` dan
mengeluarkan barang dua kali selama stock masih cukup. Request kedua untuk order **lain**
yang memakai product yang sama menunggu di langkah 2, lalu membaca sisa yang sebenarnya dan
ditolak bila tidak lagi mencukupi.

Transisi status lain (submit, approve, reject, cancel) juga memakai compare-and-set, sehingga
cancel yang membaca status lama tidak dapat menimpa order yang sementara itu sudah
`Fulfilled`.

**Goods receipt** memakai pola yang sama: baris `purchase_order` beserta item-nya dikunci,
status dan `received_quantity` dibaca ulang di bawah lock, lalu baris `product_stock` dikunci
dengan urutan `product_id` yang sama seperti issue.

**Urutan lock**: selalu baris order lebih dulu, baru `product_stock`. Bila satu order punya
beberapa line, baris stock dikunci urut `product_id` menaik lalu `warehouse_id` menaik
(`StockService::lockOrderFor()`). Receipt mengunci baris stock dengan urutan `product_id` yang
sama. Dua issue multi-line, maupun issue dan receipt yang menyentuh baris yang sama, karenanya
tidak pernah dapat deadlock dengan mengambil baris dalam urutan berlawanan.

**Dua fase, bukan satu**: seluruh verifikasi selesai sebelum satu pun penulisan, sehingga
penolakan pada line terakhir tidak meninggalkan line pertama yang sudah berkurang.

## Bukti, bukan klaim

Jaminan ini dibuktikan `ConcurrentGoodsIssueTest` memakai **dua connection MySQL yang
benar-benar terpisah** — bukan dua object dalam satu connection:

| Test | Yang dibuktikan |
| --- | --- |
| `theSecondConnectionBlocksWhileTheFirstHoldsTheLock` | B benar-benar MENUNGGU — dibuktikan lewat `innodb_lock_wait_timeout`, bukan lewat `sleep` yang hasilnya kebetulan |
| `twoConcurrentIssuesForOneUnitNeverOversell` | Satu unit, dua order masing-masing satu unit → tepat satu berhasil |
| `noUpdateIsLostWhenBothConnectionsIssueDifferentUnits` | Tidak ada lost update |
| `theLedgerStillReconcilesAfterTheContention` | Invariant NFR-002 bertahan setelah perebutan |
| `theSameOrderIsNeverIssuedTwiceFromAStaleRead` | Order yang sama tidak keluar dua kali walau stock cukup — keputusan diambil dari pembacaan di bawah lock order, bukan dari snapshot lama |
| `aCancelNeverOverwritesAnOrderFulfilledMeanwhile` | Compare-and-set: cancel yang membaca `Approved` tidak menimpa order yang sudah `Fulfilled` |
| `aSecondReceiptNeverPlansFromAStaleOutstanding` | Receipt kedua ditolak dengan pesan yang sah, bukan lolos sampai CHECK constraint (error 500) |

Tiga test terakhir memakai snapshot REPEATABLE READ yang sengaja dibuat basi pada connection
kedua. Racenya direproduksi secara deterministik, tanpa thread dan tanpa `sleep`. Ketiganya
sudah dibuktikan **gagal** pada kode sebelum perbaikan.

Kalau `FOR UPDATE` atau transaction-nya dilepas, B akan membaca stock lama dan keduanya
berhasil — test ini gagal lebih dulu.

## Konsekuensi

**Yang diperoleh.** Tidak ada kolom tambahan, sehingga schema tetap mencerminkan data model
sumber persis — tidak ada deviasi yang perlu dijustifikasi. Urutan baca → verifikasi → tulis
ledger → tulis stock terserialisasi secara alami, dan itu penting karena baris ledger dan
perubahan stock wajib sepakat.

**Yang dibayar.** Request kedua menunggu, bukan gagal cepat. Untuk volume pada brief ini
(NFR-011) itu tidak berarti apa-apa. Lock juga hanya berlaku selama transaction, sehingga
transaction harus tetap pendek — tidak boleh ada I/O lambat di dalamnya.

**Jebakan yang sudah ditemukan, dan sudah ditutup.** `Database::transaction()` dulu
memperlakukan panggilan bersarang sebagai passthrough. Karena `IntegrationTestCase` membungkus
setiap test dalam transaction, transaction milik Service di dalam test tidak pernah terbentuk
dan **rollback-nya tidak pernah terjadi**. Panggilan bersarang kini menjadi `SAVEPOINT`, sehingga
rollback di tengah operasi bekerja juga di bawah pembungkus test (`NestedTransactionTest`,
`docs/quality/tech-debt.md` TD-1). Lock `FOR UPDATE` yang diambil di dalam savepoint tetap
ditahan sampai transaction terluar selesai — perilaku InnoDB yang memang dibutuhkan ARCH-02.

## Alternatif yang ditolak

| Alternatif | Alasan ditolak |
| --- | --- |
| **Conditional `UPDATE ... WHERE quantity >= :qty`** | Benar dan sedikit lebih cepat, tetapi penalaran "nol baris terpengaruh berarti orang lain sudah mengambilnya" lebih halus untuk dipertahankan, dan menata insert ledger di sekitarnya lebih rawan. |
| **Optimistic version column + retry** | Menambah kolom `version` yang tidak ada pada data model sumber (deviasi yang harus dijustifikasi), dan memindahkan kerumitan ke loop retry. |
| **Mengandalkan CHECK `quantity >= 0` saja** | Constraint menolak penulisannya, tetapi SETELAH baris ledger tertulis — invariant NFR-002 sudah terlanjur pecah. Constraint adalah jaring pengaman terakhir, bukan mekanisme utamanya. |
| **Table lock** | Memblokir seluruh warehouse untuk satu product; berlebihan. |

## Catatan — constraint sebagai jaring pengaman, dan bug yang pernah melumpuhkannya

`ck_product_stock_quantity` (`quantity >= 0`) adalah lapis terakhir bila ada jalur yang lolos
pemeriksaan Service.

Selama satu periode, lapis itu justru melumpuhkan fiturnya. `adjust()` memakai satu
`INSERT ... ON DUPLICATE KEY UPDATE` dengan delta sebagai nilai kandidat insert; MySQL
memeriksa CHECK terhadap baris kandidat itu **sebelum** jatuh ke cabang UPDATE, sehingga
setiap delta negatif — yaitu setiap goods issue — ditolak walaupun nilai akhirnya tidak pernah
negatif.

Perbaikannya memastikan baris ada dengan nilai kandidat 0, lalu menerapkan delta lewat
`UPDATE`. Susunan itu **mengembalikan** CHECK ke peran yang dimaksudkan: menjaga HASIL
perubahan. Dijaga `StockAdjustmentTest`, yang sudah diverifikasi benar-benar gagal pada kode
lama.

## Addendum 2026-10-03 — koreksi stock (spec 003)

Koreksi stock dari hasil hitung fisik (`StockService::adjustStock()`) memakai mekanisme yang
sama: satu transaction, `SELECT ... FOR UPDATE` pada baris `product_stock`, quantity dibaca
ulang di bawah lock, lalu ledger `Adjustment` dan perubahan stock ditulis bersama. Dua hal
baru:

1. **Baris dipastikan ada sebelum dikunci** (`ProductStockRepositoryInterface::ensureRow()`).
   Koreksi boleh dilakukan di gudang yang belum pernah menyimpan product itu. Tanpa baris,
   `FOR UPDATE` hanya memasang gap lock; gap lock tidak saling menghalangi, sehingga dua koreksi
   pertama untuk pasangan yang sama lolos bersamaan dan baru bertabrakan sebagai deadlock saat
   insert. Baris bernilai 0 membuat kasus itu kembali menjadi lock satu baris biasa, dan tidak
   melanggar invariant karena jumlah ledger untuk pasangan tanpa pergerakan juga 0.
2. **Pemeriksaan stale di bawah lock.** Form membawa quantity yang dilihat user. Bila quantity
   di bawah lock berbeda, koreksi ditolak — bukan diterapkan buta — sehingga goods issue yang
   terjadi selama penghitungan tidak terhapus.

Koreksi hanya mengunci satu baris `product_stock` dan tidak mengunci baris order, sehingga tidak
dapat membentuk siklus lock dengan goods issue (order → stock) maupun goods receipt.

Dibuktikan `ConcurrentStockAdjustmentTest` dengan dua connection: B menunggu selama A memegang
baris; koreksi dengan quantity basi ditolak setelah goods issue A commit; dan untuk pasangan yang
belum pernah distok, B menunggu baris dari `ensureRow()` milik A (lock wait, bukan deadlock).

## Rujukan

- research R-002, ARCH-02, NFR-002, SC-003
- `app/Service/StockService.php` — `issueGoods()`, `issueWithinTransaction()`
- `app/Repository/Mysql/MysqlProductStockRepository.php` — `lockForUpdate()`, `adjust()`
- `tests/Integration/ConcurrentGoodsIssueTest.php`, `StockAdjustmentTest.php`
- Spec 003: `StockService::adjustStock()`, `ensureRow()`, `tests/Integration/ConcurrentStockAdjustmentTest.php`
