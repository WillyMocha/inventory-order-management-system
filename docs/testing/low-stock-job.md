# JOB-01 — Low-stock check di luar request cycle

Prosedur verifikasi untuk `scripts/check-low-stock.php` (FR-031, research R-012).

## Apa yang dibuktikan routine ini

Business rule low stock tidak terikat pada web. Script ini berjalan **tanpa** HTTP, tanpa
session, dan tanpa authorization guard, tetapi memakai `ProductService` **yang sama** dengan
yang menyuplai dashboard — dirakit `config/container.php` yang sama pula.

Itulah inti keputusan R-012: kalau script menulis query-nya sendiri, script dan dashboard bisa
memberi jawaban berbeda atas pertanyaan yang sama.

Tidak ada cron yang dipasang di image. §4.3 menempatkan penjadwalan otomatis di luar scope,
dan routine ini memang dijalankan manual.

## Menjalankan

```bash
docker compose exec app php scripts/check-low-stock.php
docker compose exec app php scripts/check-low-stock.php --limit=25
```

| Argumen | Arti |
| --- | --- |
| *(tanpa argumen)* | Menampilkan maksimal 100 baris |
| `--limit=N` | Menampilkan maksimal N baris; nilai tidak masuk akal kembali ke default |

| Exit code | Arti |
| --- | --- |
| `0` | Berhasil dijalankan — **termasuk** saat ada product yang menipis |
| `1` | Gagal dijalankan, misalnya database tidak dapat dihubungi |

Menemukan product yang menipis bukan kegagalan, sehingga exit code-nya tetap `0`. Kalau tidak,
setiap pemanggil otomatis akan membaca laporan yang sehat sebagai error.

## Definisi "low stock"

Total quantity **seluruh warehouse** berada **pada atau di bawah** reorder point product
tersebut (spec A-008). Batasnya inklusif: total `4` dengan reorder point `4` sudah terhitung
menipis.

Product yang dinonaktifkan tidak pernah muncul — product itu tidak dibeli lagi, sehingga
memunculkannya hanya menambah derau.

## Prosedur verifikasi

1. **Jalankan apa adanya.**

   ```bash
   docker compose exec app php scripts/check-low-stock.php
   ```

   Harapan: tabel berisi SKU, nama product, stock saat ini, dan reorder point, ditutup
   kalimat jumlah total. Bila tidak ada product yang menipis, script mencetak
   "Every active product is above its reorder point…" tanpa tabel maupun baris total, dan
   tetap keluar dengan kode `0`.

2. **Buktikan angkanya berasal dari data, bukan nilai tetap.** Catat satu SKU pada daftar,
   tambahkan stocknya lewat goods receipt sampai di atas reorder point, lalu jalankan ulang.
   SKU itu harus **hilang** dari daftar dan jumlah totalnya berkurang satu.

3. **Buktikan kesepakatannya dengan dashboard.** Masuk sebagai Admin atau Warehouse Staff dan
   buka `/dashboard`. Angka "Below reorder point" (Admin) atau "Low stock products"
   (Warehouse Staff) harus **sama persis** dengan jumlah total yang dicetak script.

4. **Buktikan batas inklusifnya.** Atur satu product sehingga totalnya sama persis dengan
   reorder point. Product itu harus tetap muncul.

5. **Periksa daftar yang terpotong.** Jalankan dengan `--limit=1` saat ada lebih dari satu
   product menipis. Daftarnya memendek, tetapi **jumlah totalnya tidak boleh ikut mengecil** —
   diikuti keterangan bahwa daftarnya dipotong.

## Unit test yang menjaganya

`tests/Unit/Service/ProductServiceTest.php`, terhadap fake in-memory tanpa database:

| Test | Yang dijaga |
| --- | --- |
| `lowStockListsOnlyProductsAtOrBelowTheirReorderPoint` | Hanya product yang memenuhi syarat |
| `lowStockIncludesTheBoundaryValue` | Total = reorder point tetap terhitung |
| `lowStockCoversBothSidesOfTheReorderPoint` | Kedua sisi batas, agar arah kesalahan terbaca |
| `lowStockTreatsAProductWithNoStockAtAllAsLow` | Nol adalah kasus paling perlu dilaporkan |
| `lowStockNeverReportsADeactivatedProduct` | Product nonaktif tidak menambah derau |
| `lowStockHonoursTheLimit` | `--limit=` benar-benar berlaku |
| `lowStockCarriesTheTotalQuantityUsedToJudgeIt` | Angka yang dicetak = angka yang dinilai |
| `lowStockReturnsAnEmptyListWhenEverythingIsStocked` | Katalog sehat bukan error |

Jalankan:

```bash
docker compose exec app composer test:unit
```
