# Sweep jalur kegagalan (T132)

Setiap Edge Case pada `spec.md` ditelusuri ke kode yang menanganinya dan ke test yang
membuktikannya. Dua hal yang diperiksa untuk setiap skenario:

1. **Tidak ada penyimpanan parsial** — perubahan terjadi seluruhnya atau tidak sama sekali.
2. **Tidak ada detail teknis yang sampai ke user** — tidak pernah ada exception message,
   stack trace, atau nama file (FR-030, ERR-01).

Diperiksa 2026-09-14 terhadap seluruh class di `app/Controller/`.

---

## Hasil per skenario

### 1. Dua goods issue bersamaan, stock hanya cukup untuk satu

**Penanganan**: `StockService::issueGoods()` — transaction + `SELECT ... FOR UPDATE`, lock
urut `product_id` lalu `warehouse_id`. Yang kedua menunggu, membaca sisa sebenarnya, lalu
ditolak.
**Tidak ada penyimpanan parsial**: seluruh verifikasi (fase 1) selesai sebelum satu pun
penulisan (fase 2).
**Ke user**: `DomainException` → pesan bisnis yang jelas, bukan detail teknis.
**Bukti**: `ConcurrentGoodsIssueTest::twoConcurrentIssuesForOneUnitNeverOversell` — dua
connection MySQL sungguhan.

### 2. Goods receipt atau issue gagal di tengah jalan

**Penanganan**: keduanya berjalan di dalam satu transaction; kegagalan apa pun me-rollback
stock DAN ledger bersama-sama.
**Bukti**: `GoodsReceiptTest::aFailureMidOperationLeavesNEITHERStockNorLedgerChanged` —
memakai `FailOnSecondAppendLedger` untuk gagal tepat setelah baris ledger pertama tertulis,
lalu memastikan stock, ledger, status order, dan `received_quantity` semuanya kembali.
**Catatan**: test ini berjalan di dalam pembungkus transaction harness; rollback Service
tetap terjadi karena transaction bersarang memakai SAVEPOINT (`tech-debt.md` TD-1).

### 3. Sales memanggil endpoint approval langsung untuk ordernya sendiri

**Penanganan**: dua lapis. Route table membatasi `/sales-orders/{id}/approve` pada Admin, dan
`SalesOrderApprovalService::approve()` memeriksa role Admin **dan** `approved_by <> created_by` di
server.
**Ke user**: `ForbiddenException` → halaman 403 yang aman.
**Bukti**: `ApprovalAuthorizationTest` memanggil Service dan guard LANGSUNG, meniru penyerang
yang melewati UI sepenuhnya. Menyembunyikan tombol tidak akan lulus test ini.

### 4. Sales meminta order atau export milik Sales lain

**Penanganan**: scoping ada di dalam WHERE clause repository (`createdBy`), bukan penyaringan
setelah data terbaca. Resource di luar scope menghasilkan **404, bukan 403**, agar keberadaan
record tidak bocor (security standard §2).
**Bukti**: `ApprovalAuthorizationTest`, `DashboardReportConsistencyTest::aSalesExportNeverContainsAnotherUsersOrder`.

### 5. Order dibatalkan saat PendingApproval atau Approved

**Penanganan**: `SalesOrderStatus::allowedTransitions()` — Cancelled dan Fulfilled bersifat
terminal.
**Bukti**: `cancelIsAllowedFromPendingApproval`, `cancelIsAllowedFromApproved`,
`cancelIsRefusedOnceCancelled`, `cancelIsRefusedOnceFulfilled`, `noTransitionLeavesACancelledOrder`.

### 6. Goods receipt melebihi quantity yang masih outstanding

**Penanganan**: `StockService` memverifikasi setiap line terhadap sisa outstanding sebelum
menulis apa pun.
**Ke user**: `DomainException` menyebut product dan sisa yang sebenarnya — informatif tanpa
detail teknis.
**Bukti**: `refusesAQuantityAboveTheOutstandingAmount`,
`refusesAQuantityAboveTheREMAININGOutstandingAfterAPartialReceipt`.

### 7. Product dinonaktifkan saat masih ada pada order terbuka

**Penanganan**: tidak ada operasi delete sama sekali — hanya `toggleActive`. Form order baru
memakai `ProductService::activeCatalog()`, sehingga product nonaktif tidak muncul sebagai
pilihan; order lama tetap utuh dan dapat dilihat karena menyimpan harga saat order dibuat.
**Bukti**: `ProductServiceTest::lowStockNeverReportsADeactivatedProduct` dan
`isReferencedByOrder()` yang dipakai UI untuk menjelaskan mengapa hanya deactivate yang
tersedia.

### 8. List kosong, atau filter tidak menemukan apa pun

**Penanganan**: partial `layout/_empty-state.php` membedakan **dua keadaan yang tidak boleh
disamakan** — "belum ada data" (ajakan membuat data pertama) dan "filter tidak menemukan
apa pun" (tawaran menghapus filter).
**Bukti**: dirender dan diperiksa untuk dashboard Sales kosong, dashboard Warehouse kosong,
dan halaman report dengan rentang kosong.

### 9. Session habis di tengah pengisian form

**Penanganan**: `UnauthenticatedException` → redirect ke `/login` untuk route HTML, **401 JSON**
untuk `/api/*`. Tidak ada penyimpanan parsial karena guard berjalan **sebelum** controller
dipanggil — request tidak pernah mencapai Service.
**Bukti**: `StockApiTest::signedOutTheAnswerIs401AsJsonAndNeverAnHtmlLoginPage`,
`ApiErrorEnvelopeTest`.
**Batas jujur**: kehilangan isi form yang sedang diketik saat session habis memang tidak
dipulihkan. FR-029 mempertahankan input pada kegagalan **validasi**, bukan pada session yang
kedaluwarsa.

### 10. Terjadi database error

**Penanganan**: front controller menangkap `Throwable`, mencatat `class`, pesan, file, dan
baris ke **server log**, lalu membalas halaman 500 yang aman — atau
`{"error":{"code":"server_error",...}}` untuk `/api/*`. `display_errors` dimatikan di image.
**Bukti**: `ApiErrorEnvelopeTest::theEnvelopeNeverCarriesAStackTraceOrExceptionDetail` —
memastikan body tidak memuat `Exception`, `#0 `, maupun `.php`.

### 11. Upload executable yang diganti ekstensinya menjadi gambar, atau melebihi batas ukuran

**Penanganan**: tipe ditentukan dari **isi file** lewat `finfo`, bukan dari nama atau header
client. Nama simpan acak `bin2hex(random_bytes(16))`, disimpan **di luar document root**,
disajikan lewat controller dengan `Content-Type` eksplisit.
**Bukti**: `rejectsAnExecutable`, `rejectsADisallowedTypeEvenWhenItLooksLikeAnImage`,
`rejectsSvgWhichCanCarryScript`, `rejectsAnOversizedFile`, `acceptsAFileExactlyAtTheLimit`,
`refusesAStoredNameThatTriesToEscapeTheUploadDirectory`.

### 12. Export untuk rentang tanggal tanpa pergerakan

**Penanganan**: header CSV selalu ditulis lebih dulu, baru baris. Rentang kosong menghasilkan
file berisi header tanpa baris data — bukan file nol byte dan bukan error.
**Bukti**: `ReportServiceTest::anEmptyRangeYieldsNoRowsRatherThanAnError`; halaman report
menampilkan empty state yang tetap menawarkan export.

### 13. Rentang export di luar batas

**Penanganan**: dibatasi 366 hari (research R-008). Rentang tidak sah pada endpoint CSV
**dikembalikan ke form** dengan nilai yang tadi diisi, bukan dibalas halaman error — user
perlu melihat pesannya di samping input yang salah (FR-029).
**Bukti**: `aRangeLongerThan366DaysIsRejected`, `aRangeOfExactly366DaysIsAccepted`,
`anEndDateBeforeTheStartDateIsRejected`, `anImpossibleCalendarDateIsRejected`.

---

## Temuan sweep terhadap `app/Controller/`

| Aspek | Hasil |
| --- | --- |
| Setiap controller memvalidasi lewat Service, bukan sendiri | ✓ |
| Tidak ada `echo` variabel mentah di controller | ✓ — seluruh output lewat `View::render()` |
| `ValidationException` ditangkap dan dirender ulang bersama input sebelumnya | ✓ pada seluruh form (`ProductController`, `SalesOrderController`, `PurchaseOrderController`, `UserController`, `CategoryController`, `WarehouseController`, `SupplierController`, `CustomerController`, `ProfileController`, `StockAdjustmentController`); `ReportController` mengembalikan rentang tanggal yang tidak sah ke form beserta pesannya |
| Status HTTP pada kegagalan validasi | ✓ 422, bukan 200 |
| CSRF pada setiap method non-GET | ✓ ditegakkan front controller, bukan per controller |
| Tidak ada detail exception yang sampai ke user | ✓ |

**Satu hal yang diperbaiki saat sweep**: `Request::expectsJson()` memakai
`str_starts_with($path, '/api/')`, sehingga prefix telanjang `/api` — dan `/api/` yang
dinormalisasi menjadi `/api` — akan dibalas **halaman error HTML**, tepat perilaku yang
dilarang contract untuk surface API. Pemeriksaannya kini berhenti pada batas segmen, dengan
test yang memastikan `/apixyz` dan `/api-docs` tidak ikut terhitung sebagai path API.
