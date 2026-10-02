# Hasil test suite

Bukti untuk T145 dan SC-006. Dijalankan **2026-09-14** di dalam Docker terhadap PHP 8.4.25
dan MySQL 8.0.46.

## Ringkasan

| Suite | Perintah | Hasil |
| --- | --- | --- |
| Unit | `composer test:unit` | **OK — 363 test, 917 assertion** |
| Integration | `composer test:integration` | **OK — 82 test, 318 assertion** |
| Gabungan | `composer test` | **OK — 445 test, 1235 assertion** |

**Nol test yang di-skip, incomplete, atau risky.** `phpunit.xml` menyetel `failOnWarning`,
`failOnRisky`, dan `failOnNotice` ke `true`, sehingga test yang diam-diam tidak menguji apa
pun akan menggagalkan suite — bukan lewat begitu saja.

Tidak ada satu pun test yang di-skip untuk menutupi coverage yang belum ada. Pemetaan per use
case ada di [`use-case-coverage.md`](./use-case-coverage.md).

## Pemisahan kedua suite

| | Unit | Integration |
| --- | --- | --- |
| Database | tidak ada — seluruhnya `InMemory*Repository` | MySQL 8 sungguhan |
| Session, network, filesystem | tidak ada | session di-set langsung untuk menguji guard |
| Waktu | `FixedClock` — deterministik | `SystemClock` |
| Kecepatan | ± 10 detik | ± 42 detik |

Pemisahan ini yang membuat aturan bisnis dapat diuji tanpa infrastruktur: acting user
di-**pass sebagai argument** ke Service, tidak pernah dibaca dari session, sehingga aturan
seperti segregation of duties dapat diuji tanpa session sama sekali.

## Yang hanya dapat dibuktikan integration test

| Jaminan | Test |
| --- | --- |
| Oversell tidak dapat direproduksi (ARCH-02, SC-003) | `ConcurrentGoodsIssueTest` — dua connection MySQL yang benar-benar terpisah; connection kedua terbukti MENUNGGU lewat lock wait timeout, bukan lewat `sleep` |
| `SUM(stock_ledger.quantity) = product_stock.quantity` (NFR-002, SC-004) | `LedgerReconciliationTest` |
| Ledger bersifat append-only | `LedgerReconciliationTest::noLedgerRowIsEverUpdatedOrDeleted` |
| `adjust()` menerapkan delta negatif, dan menolak hasil negatif | `StockAdjustmentTest` |
| Rollback membatalkan stock DAN ledger sekaligus | `GoodsReceiptTest::aFailureMidOperationLeavesNEITHERStockNorLedgerChanged` |
| Sales tidak dapat approve lewat jalur apa pun (FR-018, SC-005) | `ApprovalAuthorizationTest` |
| Resource di luar scope menghasilkan 404, bukan 403 | `ApprovalAuthorizationTest` |
| Dashboard dan CSV export sepakat (FR-027) | `DashboardReportConsistencyTest` |
| Kontrak JSON: 200 / **401 JSON, bukan halaman login** / 404 (FR-028) | `StockApiTest` |

## Catatan penting — suite ini pernah tidak pernah dijalankan

Sampai 2026-09-14, integration suite **belum pernah dieksekusi sekalipun**: schema database
test belum dibangun (`composer db:test`). Saat akhirnya dijalankan, hasilnya **67 error dari
74 test**.

Yang terungkap bukan sekadar masalah harness, melainkan **satu bug production yang berat**:
`MysqlProductStockRepository::adjust()` memakai `INSERT ... ON DUPLICATE KEY UPDATE` dengan
delta sebagai nilai kandidat insert. MySQL memeriksa CHECK `quantity >= 0` terhadap baris
kandidat itu lebih dulu, sehingga **setiap goods issue gagal** — di test maupun di aplikasi
sungguhan. Rinciannya ada di `implementation-log.md` dan `docs/quality/refactor-log.md`.

Pelajarannya dicatat di `docs/quality/tech-debt.md`: test yang tidak dijalankan tidak
memberikan jaminan apa pun.

## Mengulang hasil ini

```bash
docker compose up -d
docker compose exec app composer db:test        # sekali, membangun schema database test
docker compose exec app composer test
```

Integration test dijalankan dua kali berturut-turut untuk memastikan sifat repeatable-nya —
tidak ada sisa fixture yang membuat run kedua berbeda.
