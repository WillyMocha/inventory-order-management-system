# Hasil test suite

Bukti untuk T145 dan SC-006. Dijalankan ulang **2026-10-04** (setelah 004-edit-draft-orders) di dalam Docker terhadap
PHP 8.4.26 dan MySQL 8.0.46.

## Ringkasan

| Suite | Perintah | Hasil |
| --- | --- | --- |
| Unit | `composer test:unit` | **OK — 459 test, 1307 assertion** |
| Integration | `composer test:integration` | **OK — 244 test, 896 assertion** |
| Gabungan | `composer test` | **OK — 703 test, 2203 assertion** |
| Coverage (SonarQube) | `composer test:coverage` | **OK — 703 test**; `coverage/clover.xml`, 2660 dari 3483 statement (76%) |
| JavaScript | `node --test "tests/js/*.test.mjs"` (image `node:22-alpine`) | **OK — 18 test** |
| Seluruh gate | `composer check` | **OK** — schema test, unit, integration, PHPStan 0 error, PHPCS 0 error 0 warning; lulus di Docker, dari `cmd.exe` Windows, dan dari salinan repo bersih |

**Coverage repository MySQL oleh integration suite: 116 dari 116 method** (diukur dengan pcov di
container sekali pakai; lihat `docs/quality/tech-debt.md` TD-2b). Ini bukti bahwa setiap query
SQL di repository pernah benar-benar dieksekusi MySQL, bukan hanya fake in-memory-nya.
Empat method yang ditambahkan 004 (`updateDraft` dan `replaceItems` pada kedua repository order)
dieksekusi `EditDraftOrderTest`; angka pcov di atas belum diukur ulang sejak itu.

**Coverage untuk SonarQube.** `composer test:coverage` menjalankan unit + integration dengan pcov
(terpasang di image, dimatikan secara default) dan menulis `coverage/clover.xml` dengan path relatif
terhadap root project; `sonar-project.properties` membacanya lewat `sonar.php.coverage.reportPaths`.
Sebelum ini SonarQube tidak menerima laporan apa pun, sehingga seluruh *new code* terbaca 0%.
Controller order (`SalesOrderController`, `PurchaseOrderController`, `SalesOrderApprovalController`,
`GoodsIssueController`, `GoodsReceiptController`) kini diuji di lapisan HTTP oleh
`SalesOrderControllerTest`, `PurchaseOrderControllerTest`, `SalesOrderActionControllerTest`, dan
`GoodsReceiptControllerTest` (50 test): status code, redirect beserta flash, dan form 422. Coverage
kelimanya 98–100%.

**Nol test yang di-skip, incomplete, atau risky.** `phpunit.xml` menyetel `failOnWarning`,
`failOnRisky`, dan `failOnNotice` ke `true`, sehingga test yang diam-diam tidak menguji apa
pun akan menggagalkan suite — bukan lewat begitu saja.

Tidak ada satu pun test yang di-skip untuk menutupi coverage yang belum ada. Pemetaan per use
case ada di [`use-case-coverage.md`](./use-case-coverage.md).

## Pemisahan kedua suite

| | Unit | Integration |
| --- | --- | --- |
| Database | tidak ada — seluruhnya `InMemory*Repository` | MySQL 8 sungguhan |
| Session, network, filesystem | tidak ada | session di-set langsung untuk menguji guard; filesystem di direktori sementara (`ProductImageStorageTest`) |
| Waktu | `FixedClock` — deterministik | `SystemClock` |
| Kecepatan | ± 10 detik | ± 60 detik |

Pemisahan ini yang membuat aturan bisnis dapat diuji tanpa infrastruktur: acting user
di-**pass sebagai argument** ke Service, tidak pernah dibaca dari session, sehingga aturan
seperti segregation of duties dapat diuji tanpa session sama sekali.

## Yang hanya dapat dibuktikan integration test

| Jaminan | Test |
| --- | --- |
| Oversell tidak dapat direproduksi (ARCH-02, SC-003) | `ConcurrentGoodsIssueTest` — dua connection MySQL yang benar-benar terpisah; connection kedua terbukti MENUNGGU lewat lock wait timeout, bukan lewat `sleep` |
| Satu Sales Order tidak dapat di-issue dua kali; cancel tidak menimpa order yang sudah Fulfilled; receipt kedua tidak merencanakan dari outstanding basi | `ConcurrentGoodsIssueTest` — connection kedua memegang snapshot REPEATABLE READ yang basi, keputusan harus diambil dari pembacaan di bawah lock |
| `SUM(stock_ledger.quantity) = product_stock.quantity` (NFR-002, SC-004) | `LedgerReconciliationTest` |
| Ledger bersifat append-only | `LedgerReconciliationTest::noLedgerRowIsEverUpdatedOrDeleted` |
| `adjust()` menerapkan delta negatif, dan menolak hasil negatif | `StockAdjustmentTest` |
| Rollback membatalkan stock DAN ledger sekaligus | `GoodsReceiptTest::aFailureMidOperationLeavesNEITHERStockNorLedgerChanged` |
| Sales tidak dapat approve lewat jalur apa pun (FR-018, SC-005) | `ApprovalAuthorizationTest` |
| Resource di luar scope menghasilkan 404, bukan 403 | `ApprovalAuthorizationTest` |
| Dashboard dan CSV export sepakat (FR-027) | `DashboardReportConsistencyTest` |
| Kontrak JSON: 200 / **401 JSON, bukan halaman login** / 404 (FR-028) | `StockApiTest` |
| Rollback nested transaction lewat SAVEPOINT, termasuk di bawah pembungkus transaction harness | `NestedTransactionTest`, `GoodsReceiptTest` |
| Seluruh method repository MySQL benar-benar dieksekusi (116/116) | `RepositoryCoverageTest`, `RepositorySearchTest`, `RepositorySortPagingTest` |
| Jalur filesystem upload: baca, hapus, dan penolakan file palsu | `ProductImageStorageTest` |
| Akun yang dinonaktifkan atau diganti role-nya kehilangan session pada request berikutnya (002 FR-012, SC-007) | `SessionRevalidationTest` |
| Profil sendiri: `id` yang diselipkan ke request diabaikan; password lama berhenti berlaku; penolakan tidak mengubah hash; tebakan di profil dan di login berbagi satu counter (002 SC-002, SC-004, SC-005, FR-008) | `ProfileFlowTest` |
| Koreksi stock: MySQL menolak Adjustment tanpa alasan dan alasan pada Receipt; stock = SUM(ledger) setelah setiap koreksi; setiap penolakan tidak mengubah apa pun (003 SC-002, SC-004) | `StockAdjustmentFlowTest` |
| Koreksi stock dengan dua connection: B menunggu lock; quantity yang berubah karena goods issue ditolak; pasangan yang belum pernah distok tidak deadlock (003 SC-005) | `ConcurrentStockAdjustmentTest` |

## Catatan penting — suite ini pernah tidak pernah dijalankan

Sampai 2026-09-14, integration suite **belum pernah dieksekusi sekalipun**: schema database
test belum dibangun (`composer db:test`). Saat akhirnya dijalankan, hasilnya **67 error dari
74 test**.

Yang terungkap bukan sekadar masalah harness, melainkan **satu bug production yang berat**:
`MysqlProductStockRepository::adjust()` memakai `INSERT ... ON DUPLICATE KEY UPDATE` dengan
delta sebagai nilai kandidat insert. MySQL memeriksa CHECK `quantity >= 0` terhadap baris
kandidat itu lebih dulu, sehingga **setiap goods issue gagal** — di test maupun di aplikasi
sungguhan. Rinciannya ada di `specs/001-inventory-order-management/implementation-log.md` dan
`docs/quality/refactor-log.md` R-1.

Pelajarannya dicatat di `docs/quality/tech-debt.md`: test yang tidak dijalankan tidak
memberikan jaminan apa pun.

## Mengulang hasil ini

```bash
docker compose up -d
docker compose exec app composer check          # membangun schema test, lalu seluruh gate
```

Atau per langkah: `composer db:test` (sekali), lalu `composer test`.

Integration test dijalankan dua kali berturut-turut untuk memastikan sifat repeatable-nya —
tidak ada sisa fixture yang membuat run kedua berbeda.
