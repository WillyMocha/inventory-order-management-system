# Pemetaan use case ke unit test

Bukti untuk constitution Principle III dan SC-006: **setiap method yang memuat business rule
punya sekurang-kurangnya satu unit test.**

Dibuat pada T144 dengan menyilangkan seluruh method `public` di `app/Service/` terhadap
`tests/Unit/`. Terakhir diperbarui **2026-10-03** (audit dijalankan ulang terhadap kode).

## Ringkasan

| Service | Method ber-rule tertutup | Tanpa test langsung |
| --- | --- | --- |
| `AuthService` | 4/4 | — |
| `DashboardService` | 4/4 | — |
| `MasterDataService` | 10/10 | — |
| `PartyService` | 16/16 | — |
| `ProductImageService` | 3/6 | `store` `read` `delete` |
| `ProductService` | 11/15 | `totalInventoryValue` `totalStockFor` `updateImagePath` `requireProduct` |
| `PurchaseOrderService` | 7/7 | — |
| `ReportService` | 11/11 | — |
| `SalesOrderService` | 10/10 | — |
| `StockService` | 5/8 | `availableFor` `movementsForSalesOrder` `movementsForPurchaseOrder` |
| `UserService` | 6/7 | `requireUser` |

## Yang ditambahkan pada T144

Audit ini menemukan kekurangan yang nyata, bukan sekadar mencatat keadaan:

| Temuan | Tindakan |
| --- | --- |
| **`MasterDataService` tidak punya file test sama sekali** — 11 method, termasuk aturan validasi dan keunikan nama Category | `tests/Unit/Service/MasterDataServiceTest.php` — 21 test |
| `AuthService::verifyPasswordFor` tidak teruji padahal ia adalah step-up re-auth sebelum aksi sensitif (security standard §7) | 4 test pada `AuthServiceTest` |
| `PartyService` sisi Customer tertinggal dari sisi Supplier — `updateCustomer`, `countCustomers`, `activeCustomers`, `countSuppliers` | 3 test pada `PartyServiceTest` |
| `ProductService::stockBreakdownBySku` dan `availableQuantity` hanya teruji tidak langsung lewat controller API | 5 test pada `ProductServiceTest` |

## Yang ditambahkan pada 003-stock-adjustment

| Method baru | Unit test (`StockServiceAdjustmentTest`) |
| --- | --- |
| `StockService::adjustStock` | 14 test: turun, naik, warehouse yang belum pernah distok, product nonaktif, alasan di-trim, batas 255 karakter setelah trim; Sales ditolak; `counted_quantity` `-1`/`2.5`/`abc`/kosong, `expected_quantity` tidak sah, alasan kosong/terlalu panjang, warehouse kosong/tidak dikenal/nonaktif, product tidak dikenal, quantity basi, selisih nol |
| `StockService::recentAdjustments` | 3 test: terbaru lebih dulu dengan saldo berjalan (melewati Receipt dan Issue), dibatasi 10, kosong |

Setiap penolakan juga memeriksa bahwa stock dan ledger tidak berubah sedikit pun.

## Yang ditambahkan pada 002-user-profile-page

| Method baru | Unit test (`AuthServiceTest`) |
| --- | --- |
| `AuthService::activeSessionUser` | 4 test: user valid, id tidak dikenal, akun nonaktif, role berubah |
| `AuthService::changeOwnPassword` | 11 test: berhasil, password saat ini salah, terlalu pendek, konfirmasi beda, field kosong, sama dengan password lama, nilai disimpan apa adanya; lockout menolak password yang benar, satu kegagalan per tebakan salah, kesalahan format tidak dihitung, keberhasilan menghapus hitungan |

Setiap penolakan juga memeriksa bahwa hash tersimpan tidak berubah.

## Method tanpa test langsung, beserta alasannya

Seluruhnya **bukan** method ber-business-rule. Dicatat di sini secara terbuka, bukan
disembunyikan.

### `ProductImageService::store` / `read` / `delete`

Menyentuh filesystem. Unit suite dijalankan **tanpa filesystem** (lihat `phpunit.xml`),
sehingga ketiganya tidak diuji di sana, tetapi diuji di suite Integration:
`ProductImageStorageTest` memakai direktori sementara untuk `read`, `delete`, dan seluruh jalur
penolakan `store` (termasuk file lokal yang disodorkan sebagai upload). Hanya jalur **sukses**
`store()` yang tidak teruji otomatis, karena `move_uploaded_file()` hanya menerima upload HTTP
sungguhan (`docs/quality/tech-debt.md` TD-3).

Bagian yang memuat keputusannya justru sudah teruji terpisah dan itulah yang penting:
`validate()` (tipe dari isi file lewat `finfo`, batas ukuran), `generateStoredName()` (nama
acak `bin2hex(random_bytes(16))`), dan `pathFor()` (penyimpanan di luar document root) —
12 test pada `ProductImageServiceTest`. Sisanya adalah pembungkus `move_uploaded_file`,
`file_get_contents`, dan `unlink` di atas ketiga keputusan itu.

### Lookup dan passthrough

| Method | Alasan |
| --- | --- |
| `ProductService::requireProduct` | Lookup + `NotFoundException`. Dijalankan tidak langsung oleh hampir setiap test ProductService lain. |
| `ProductService::totalStockFor`, `totalInventoryValue`, `updateImagePath` | Meneruskan satu panggilan ke repository tanpa aturan tambahan. `totalInventoryValue` diuji perilakunya lewat `DashboardServiceTest` dan integration test. |
| `StockService::availableFor`, `movementsForSalesOrder`, `movementsForPurchaseOrder` | Pembacaan read-only untuk tampilan; tidak ada keputusan di dalamnya. Aturan stock yang sesungguhnya ada di `issueGoods()` dan `receiveGoods()`, keduanya teruji unit maupun integration. |
| `UserService::requireUser` | Lookup + `NotFoundException`. |

**Tidak ada satu pun method ber-rule yang masuk daftar ini.** Bila kelak salah satunya
memperoleh aturan, ia berpindah ke kolom "tertutup" beserta test-nya.

### Support yang memuat aturan

`app/Support` adalah plumbing, tetapi dua method di `Request` memuat aturan FIND-01 dan
karena itu diperlakukan seperti use case:

| Method | Test |
| --- | --- |
| `Request::queryState` | `RequestTest` — key kosong dibuang, nilai non-string diabaikan, urutan key dipertahankan |
| `Request::sortCriteria` | `RequestTest` — key di luar allowlist ditolak, arah tidak sah jatuh ke default |

Accessor lain di `Request` (`queryString`, `input`, `routeParam`, …) hanya membaca superglobal
tanpa keputusan.

## Cakupan di luar unit test

Beberapa jaminan memang tidak dapat dibuktikan unit test dan karena itu diuji sebagai
integration test terhadap MySQL sungguhan:

| Jaminan | Test |
| --- | --- |
| Oversell tidak dapat direproduksi (ARCH-02) | `ConcurrentGoodsIssueTest` — dua connection nyata |
| `SUM(stock_ledger) = product_stock` (NFR-002) | `LedgerReconciliationTest` |
| `adjust()` menerapkan delta negatif dan menolak hasil negatif | `StockAdjustmentTest` |
| Segregation of duties ditegakkan di server (FR-018) | `ApprovalAuthorizationTest` |
| Dashboard dan CSV export sepakat (FR-027) | `DashboardReportConsistencyTest` |
| Kontrak JSON, termasuk 401 JSON bukan halaman HTML (FR-028) | `StockApiTest` |
| Satu order tidak keluar dua kali; cancel tidak menimpa order `Fulfilled` | `ConcurrentGoodsIssueTest` — tiga test snapshot basi |
| Rollback nested transaction lewat SAVEPOINT | `NestedTransactionTest`, `GoodsReceiptTest` |
| Seluruh 116 method repository MySQL benar-benar dieksekusi | `RepositoryCoverageTest`, `RepositorySearchTest`, `RepositorySortPagingTest` |
| Jalur filesystem upload | `ProductImageStorageTest` |
| Akun yang dinonaktifkan atau diganti role-nya langsung kehilangan session (002 FR-012) | `SessionRevalidationTest` |
| Profil hanya milik user di session; `id` pada request diabaikan; batas percobaan dihitung bersama login (002 FR-003, FR-008) | `ProfileFlowTest` |
| Koreksi stock: CHECK alasan dan Manual di MySQL, stock dan ledger berubah bersama, riwayat dengan saldo berjalan, alasan di CSV, role route (003) | `StockAdjustmentFlowTest` |
| Koreksi stock di bawah konkurensi: menunggu lock, quantity basi ditolak, pasangan baru tanpa deadlock (003 FR-004, FR-006) | `ConcurrentStockAdjustmentTest` |

## Cara mengulang audit ini

```bash
for svc in app/Service/*.php; do
  name=$(basename "$svc" .php)
  for m in $(grep -oE "public function [a-zA-Z]+" "$svc" | sed 's/public function //' | grep -v '^__construct$'); do
    # Fake/ sengaja tidak ikut: pemanggilan dari fake bukan test.
    grep -rq -- "->$m(" tests/Unit/Service tests/Unit/Support tests/Unit/Controller \
      || echo "TANPA TEST LANGSUNG  $name::$m"
  done
done
```
