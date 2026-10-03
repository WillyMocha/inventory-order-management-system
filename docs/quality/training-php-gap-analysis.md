# Gap Analysis — Training Programmer PHP & Laravel V2

Pemetaan project ini terhadap modul *Intermediate Programmer — PHP & Laravel V2*
(PT Neuronworks Indonesia, Edisi 2.0 / Agustus 2026).

- **Tanggal pemeriksaan**: 2026-09-25
- **Metode**: membaca source code, schema, konfigurasi Docker, dan dokumen di `docs/`.
  Test suite **tidak** dijalankan dalam pemeriksaan ini.
- Nomor baris mengacu pada kondisi kode saat pemeriksaan dan bisa bergeser setelah
  perubahan berikutnya.

## Konteks

Modul ditulis untuk **Laravel**, sedangkan project ini **PHP 8.4 native tanpa framework**
(dilarang oleh constitution dan spec C-003). Karena itu:

- Topik yang spesifik Laravel (Eloquent, facade, Debugbar/Telescope/Ray, `Route::view`)
  tidak dapat diterapkan secara harfiah.
- Yang dinilai adalah **prinsip di baliknya**, dan sebagian besar sudah diterapkan dalam
  versi native.
- Topik yang tidak dibutuhkan oleh spec sengaja tidak ditambahkan agar tidak menjadi
  over-engineering.

Legenda status:

| Simbol | Arti |
| --- | --- |
| ✅ | Diterapkan |
| ⚠️ | Sebagian / versi native |
| ❌ | Belum diterapkan |
| N/A | Tidak relevan untuk scope atau arsitektur project |

## Ringkasan per bab

| Bab | Topik | Status | Yang kurang |
| --- | --- | --- | --- |
| 1 | Values, operators, runtime config | ✅ | Belum ada inventaris extension (`php -m`) sebagai evidence |
| 2 | Function contracts, scope, callables | ✅ | — |
| 3 | Strings, patterns, formatting | ✅ | Heredoc/nowdoc tidak dipakai (tidak dibutuhkan) |
| 4 | Arrays & data transformation | ⚠️ | Generator tidak ada; peak memory tidak diukur |
| 5 | Object contracts & lifecycle | ⚠️ | Belum ada decision record interface vs abstract class |
| 6 | Secure PHP boundaries | ✅ | `disable_functions` belum diset; log poisoning belum ditangani |
| 7 | Routing, controllers, API | ⚠️ | JWT dan Guzzle: N/A |
| 8 | Migration & query quality | ⚠️ | Tanpa rollback per migrasi; belum ada bukti EXPLAIN / jumlah query |
| 9 | Middleware, authorization, file pipeline | ⚠️ | Data report dimuat penuh ke array sebelum di-stream |
| 10 | Errors, observability, testing | ⚠️ | Log belum structured, tanpa correlation ID |
| 11 | SOLID & design patterns | ✅ | Strategy/Factory tidak dipakai (belum ada variasi) |
| 12 | Refactoring & resource optimization | ⚠️ | Belum ada baseline dan metrik before–after |

## Detail per bab — lokasi penerapan di kode

### Bab 1 — PHP values, operators, runtime configuration

| Topik silabus | Lokasi | Keterangan |
| --- | --- | --- |
| 1. Casting, float & integer | [Validator.php:96](../../app/Support/Validator.php#L96) | Integer divalidasi (`is_numeric` + round-trip `(string)(int)`) **sebelum** di-cast |
| | [Request.php:109](../../app/Support/Request.php#L109), [Request.php:123](../../app/Support/Request.php#L123) | `ctype_digit` / `is_numeric` sebelum `(int)` |
| 2. `isset` vs `empty` | [StockService.php:230-232](../../app/Service/StockService.php#L230-L232) | `??` bersemantik `isset` dipakai untuk stock yang null; `empty()` sengaja tidak dipakai di seluruh `app/` sehingga nilai `'0'` tidak hilang |
| 3. Ternary | [Request.php:49](../../app/Support/Request.php#L49), [SalesOrderService.php:196](../../app/Service/SalesOrderService.php#L196) | Dua cabang pendek yang tetap terbaca |
| 4. Null coalescing | [Request.php:45-53](../../app/Support/Request.php#L45-L53), [View.php:87](../../app/Support/View.php#L87) | Fallback untuk `$_SERVER` yang tidak ada dan value null |
| 5. Bitwise | [View.php:87](../../app/Support/View.php#L87) | Hanya sebagai flag `ENT_QUOTES \| ENT_SUBSTITUTE`; tidak ada bitmask domain |
| 6. php.ini | [Dockerfile:24-34](../../Dockerfile#L24-L34) | `php.ini-production` + `display_errors=Off`, `log_errors=On`, `expose_php=Off`, batas upload |
| | [config/app.php:68](../../config/app.php#L68) | Nilai environment di-parse eksplisit dengan `FILTER_VALIDATE_BOOLEAN` |
| 7. Extension / runtime check | [public/index.php:32-39](../../public/index.php#L32-L39) | Runtime guard: aplikasi menolak jalan jika bukan PHP 8.4.x |

### Bab 2 — Function contracts, scope, callables

| Topik silabus | Lokasi | Keterangan |
| --- | --- | --- |
| 8. Nullable type | [CategoryRepositoryInterface.php:11](../../app/Repository/CategoryRepositoryInterface.php#L11), [View.php:85](../../app/Support/View.php#L85) | `?Category`, `?string` |
| 9. Overloading (pola alternatif) | [Money.php:22](../../app/Support/Money.php#L22), [Money.php:32](../../app/Support/Money.php#L32) | Dua method bernama (`format` / `formatPlain`) alih-alih satu method dengan flag |
| | [Validator.php:35](../../app/Support/Validator.php#L35) | Named constructor `Validator::make()` |
| 10. By value vs reference | Seluruh `app/` | Tidak ada parameter `&$`; mutation dilakukan lewat return value |
| 11. Return type | Seluruh `app/` | Setiap method punya return type, termasuk `never` di [Authorization.php:91](../../app/Support/Authorization.php#L91) |
| 12. Variable scope | [StockService.php:53](../../app/Service/StockService.php#L53), [AuthService.php:42](../../app/Service/AuthService.php#L42) | Dependency lewat constructor injection; tanpa `global` atau superglobal di Service |
| | [StockService.php:93](../../app/Service/StockService.php#L93) | Closure menangkap variabel secara eksplisit lewat `use` |
| 13. Callables | [Database.php:78-81](../../app/Support/Database.php#L78-L81) | `callable(): T` dengan PHPDoc generic |
| | [SalesOrderController.php:219-221](../../app/Controller/SalesOrderController.php#L219-L221) | `callable(int, User): void` sebagai action transisi status |
| | [Validator.php:175](../../app/Support/Validator.php#L175) | Callable sebagai rule `existsById` |

### Bab 3 — Strings, patterns, output formatting

| Topik silabus | Lokasi | Keterangan |
| --- | --- | --- |
| 14. Interpolation array/object | [MysqlProductRepository.php:89](../../app/Repository/Mysql/MysqlProductRepository.php#L89) | Konkatenasi hanya untuk nilai dari allowlist, bukan input |
| 15. Control characters | [ReportService.php:286](../../app/Service/ReportService.php#L286) | Sel CSV yang diawali tab / CR dinetralkan |
| 16. Heredoc & nowdoc | — | Tidak dipakai (lihat bagian *Sengaja tidak diterapkan*) |
| 17. Matching & extracting | [StockApiController.php:103](../../app/Controller/Api/StockApiController.php#L103) | Regex ber-anchor dengan batas panjang `{1,64}` |
| | [ProductImageService.php:95](../../app/Service/ProductImageService.php#L95) | Nama file tersimpan divalidasi ketat sebelum jadi path |
| | [Router.php:45](../../app/Support/Router.php#L45), [Router.php:92](../../app/Support/Router.php#L92) | Pattern route dikompilasi lalu di-match |
| 18. Searching strings | [Validator.php:66](../../app/Support/Validator.php#L66), [Validator.php:77](../../app/Support/Validator.php#L77) | `mb_strlen` agar panjang dihitung per karakter UTF-8 |
| | [check-low-stock.php:75-79](../../scripts/check-low-stock.php#L75-L79) | `mb_substr` untuk memotong kolom output CLI |
| 19. Formatting strings & numbers | [Money.php:22-26](../../app/Support/Money.php#L22-L26) | Format `Rp 1.250.000` hanya di presentation; domain tetap string desimal |

### Bab 4 — Arrays dan data transformation

| Topik silabus | Lokasi | Keterangan |
| --- | --- | --- |
| 20. Membuat array | [DashboardService.php:160](../../app/Service/DashboardService.php#L160) | `array_map` dari `SalesOrderStatus::cases()` |
| 21. Array operators | [Authorization.php:58](../../app/Support/Authorization.php#L58) | `array_values` untuk list berindeks |
| 22. Filling arrays | [StockService.php:433](../../app/Service/StockService.php#L433) | Akumulasi quantity per pasangan `product:warehouse` |
| 23. Combining & splitting | [MysqlSalesOrderRepository.php:219](../../app/Repository/Mysql/MysqlSalesOrderRepository.php#L219) | Row DB di-map menjadi entity item |
| 24. Calculating | [ReportService.php:210](../../app/Service/ReportService.php#L210) | `statusTotals` menjumlah per status |
| 25. Sorting | [StockService.php:199-201](../../app/Service/StockService.php#L199-L201) | `usort` multi-key `[product, warehouse] <=> ...` yang deterministik, menentukan urutan lock anti-deadlock |
| | [StockService.php:373](../../app/Service/StockService.php#L373) | Comparator mengembalikan `int`, bukan boolean |
| Memory posture | — ❌ | Belum ada generator dan pengukuran peak memory |

### Bab 5 — Object contracts dan lifecycle

| Topik silabus | Lokasi | Keterangan |
| --- | --- | --- |
| 26. Copying & serializing | [Request.php:92-94](../../app/Support/Request.php#L92-L94) | Pola *wither*: `clone` lalu ubah salinannya, object asli tetap immutable |
| | — | `serialize`/`unserialize` tidak dipakai sama sekali |
| 27. Overriding | [MysqlRepository.php:20](../../app/Repository/Mysql/MysqlRepository.php#L20) | Subclass memakai helper protected tanpa mengubah contract |
| 28. Interface & abstract class | [ProductStockRepositoryInterface.php:16](../../app/Repository/ProductStockRepositoryInterface.php#L16), [ClockInterface.php:13](../../app/Support/ClockInterface.php#L13), [TransactionRunner.php:22](../../app/Support/TransactionRunner.php#L22) | Interface = capability, punya implementasi MySQL dan fake |
| | [MysqlRepository.php:34-57](../../app/Repository/Mysql/MysqlRepository.php#L34-L57) | Abstract class = shared implementation (`run`, `fetchOne`, `fetchAll`) |
| | [PurchaseOrderStatus.php:32-38](../../app/Entity/Enum/PurchaseOrderStatus.php#L32-L38) | Backed enum dengan invariant transisi status |
| 29. Magic methods | — | Tidak dipakai; method dan property typed eksplisit sesuai anjuran modul |

### Bab 6 — Secure PHP boundaries

| Topik silabus | Lokasi | Keterangan |
| --- | --- | --- |
| 30. Disabling functions | — ❌ | `disable_functions` belum diset di `99-app.ini` |
| 31. XSS & CSRF | [View.php:85-87](../../app/Support/View.php#L85-L87) | Helper `e()` dengan `ENT_QUOTES \| ENT_SUBSTITUTE`, UTF-8 |
| | [Csrf.php:25-47](../../app/Support/Csrf.php#L25-L47) | Token `random_bytes(32)`, dibandingkan dengan `hash_equals` |
| | [Session.php:39-52](../../app/Support/Session.php#L39-L52) | Cookie HttpOnly, SameSite=Lax, Secure; `session_regenerate_id` saat login |
| | [Response.php:85](../../app/Support/Response.php#L85) | Header `X-Content-Type-Options: nosniff` |
| 32. Filtering input | [Validator.php:40-217](../../app/Support/Validator.php#L40-L217) | Rule `required`, `email`, `maxLength`, `integerMin`, `decimalMin`, `enum`, `date`, `existsById` |
| 33. Escaping output | [View.php:85](../../app/Support/View.php#L85) + seluruh `views/` | Semua output template lewat `View::e()` |
| | [ReportService.php:270-286](../../app/Service/ReportService.php#L270-L286) | Escaping konteks CSV: netralisasi formula injection (CWE-1236) |
| 34. Log poisoning | — ❌ | [index.php:151](../../public/index.php#L151) dan [index.php:156](../../public/index.php#L156) menulis `$e->getMessage()` tanpa membuang CR/LF |
| 35. File upload security | [ProductImageService.php:125](../../app/Service/ProductImageService.php#L125) | `is_uploaded_file` |
| | [ProductImageService.php:182-184](../../app/Service/ProductImageService.php#L182-L184) | MIME dari **isi** file lewat `finfo` |
| | [ProductImageService.php:135](../../app/Service/ProductImageService.php#L135) | `getimagesize` sebagai lapisan kedua |
| | [ProductImageService.php:47](../../app/Service/ProductImageService.php#L47) | Validasi tipe dan ukuran |
| | [ProductImageService.php:82](../../app/Service/ProductImageService.php#L82) | Nama file acak `bin2hex(random_bytes(16))` |
| | [ProductController.php:159-174](../../app/Controller/ProductController.php#L159-L174) | File disajikan lewat controller, bukan dari docroot |
| 36. Database security | [Database.php:49-52](../../app/Support/Database.php#L49-L52) | `ERRMODE_EXCEPTION`, `ATTR_EMULATE_PREPARES = false` |
| | [MysqlRepository.php:34-37](../../app/Repository/Mysql/MysqlRepository.php#L34-L37) | Semua query lewat `prepare` + `execute` |
| | [MysqlRepository.php:96](../../app/Repository/Mysql/MysqlRepository.php#L96), [MysqlProductRepository.php:23](../../app/Repository/Mysql/MysqlProductRepository.php#L23) | Kolom `ORDER BY` dari allowlist, bukan dari input |
| 37. Password & secret | [AuthService.php:74-75](../../app/Service/AuthService.php#L74-L75) | `password_verify`, dengan dummy hash agar waktu respons tidak membocorkan email terdaftar |
| | [AuthService.php:102-108](../../app/Service/AuthService.php#L102-L108) | `password_needs_rehash` |
| | [AuthService.php:59-67](../../app/Service/AuthService.php#L59-L67), [AuthService.php:91-93](../../app/Service/AuthService.php#L91-L93) | Rate limit login per email + IP |
| | [.gitignore](../../.gitignore), [.env.example](../../.env.example), [secret-scan.md](secret-scan.md) | `.env` tidak di-commit; hasil secret scan |

Test pendukung: [ProductImageServiceTest.php:54-83](../../tests/Unit/Service/ProductImageServiceTest.php#L54-L83)
(tipe palsu, SVG, executable, file terlalu besar).

### Bab 7 — Routing, controllers, dan API

| Topik silabus | Lokasi | Keterangan |
| --- | --- | --- |
| 38. Callback / view statis | [HealthController.php](../../app/Controller/HealthController.php) | Padanan native untuk halaman sederhana |
| 39. Single controller method | [config/routes.php](../../config/routes.php) | Setiap route menunjuk ke satu `Controller::action` |
| 40. Parameter, group, naming | [Router.php:40](../../app/Support/Router.php#L40), [Router.php:78](../../app/Support/Router.php#L78) | Route parameter di pattern; role per route di [routes.php:27-29](../../config/routes.php#L27-L29) |
| 41. API routes & controllers | [StockApiController.php](../../app/Controller/Api/StockApiController.php), [DashboardApiController.php](../../app/Controller/Api/DashboardApiController.php), [openapi.yaml](../../specs/001-inventory-order-management/contracts/openapi.yaml) | Controller tipis, logika di Service |
| 42. API auth JWT | N/A | Auth berbasis session (aplikasi server-rendered) |
| 43. Error handling & status | [Response.php:33-44](../../app/Support/Response.php#L33-L44) | `json()` dan `jsonError()` dengan envelope konsisten |
| | [index.php:128-153](../../public/index.php#L128-L153) | Pemetaan exception → 401 / 403 / 404 / 429 / 400 / 500 |
| | [CategoryController.php:51](../../app/Controller/CategoryController.php#L51), [AuthController.php:64](../../app/Controller/AuthController.php#L64) | 422 untuk validasi, 429 untuk rate limit |
| 44. Guzzle HTTP client | N/A | Tidak ada integrasi eksternal di spec |

Test pendukung: [ApiErrorEnvelopeTest.php](../../tests/Unit/Support/ApiErrorEnvelopeTest.php),
[StockApiTest.php](../../tests/Integration/StockApiTest.php).

### Bab 8 — Migrations dan query quality

| Topik silabus | Lokasi | Keterangan |
| --- | --- | --- |
| 45. Create table | [001_schema.sql](../../database/001_schema.sql) | Schema lengkap dengan FK `ON DELETE RESTRICT` |
| 46. Alter table | — ❌ | Belum ada migrasi alter; perubahan dilakukan lewat `--fresh` |
| 47. Index | [001_schema.sql:30](../../database/001_schema.sql#L30), [001_schema.sql:80](../../database/001_schema.sql#L80), [001_schema.sql:105](../../database/001_schema.sql#L105), [001_schema.sql:165](../../database/001_schema.sql#L165), [001_schema.sql:226](../../database/001_schema.sql#L226) | Composite index sesuai filter + sort (`is_active, name`; `status, order_date`); unique `product_id, warehouse_id` |
| 48. Seeder & factory | [002_seed.sql](../../database/002_seed.sql), [generate-seed.py](../../database/generate-seed.py), [SalesOrderFixtures.php](../../tests/Integration/SalesOrderFixtures.php) | Seed demo dan fixture test yang deterministik |
| | [migrate.php:114-129](../../database/migrate.php#L114-L129) | Setiap file SQL diterapkan dalam transaction, rollback bila gagal |
| 49. Query scopes | [SalesOrderService.php:196](../../app/Service/SalesOrderService.php#L196) | Filter `createdBy` untuk Sales dimasukkan ke query, bukan difilter di PHP |
| 50. Relations | [MysqlSalesOrderRepository.php:219](../../app/Repository/Mysql/MysqlSalesOrderRepository.php#L219), [MysqlPurchaseOrderRepository.php:180](../../app/Repository/Mysql/MysqlPurchaseOrderRepository.php#L180) | Header + item order dimuat dari repository |
| 51. Soft delete | [MysqlCustomerRepository.php:38](../../app/Repository/Mysql/MysqlCustomerRepository.php#L38) | Flag `is_active` sebagai pengganti soft delete |
| Query evidence (N+1, EXPLAIN) | — ❌ | Belum ada dokumen bukti |

Test pendukung: [RepositorySortPagingTest.php](../../tests/Integration/RepositorySortPagingTest.php),
[RepositorySearchTest.php](../../tests/Integration/RepositorySearchTest.php).

### Bab 9 — Middleware, authorization, dan file pipelines

| Topik silabus | Lokasi | Keterangan |
| --- | --- | --- |
| 52. Custom middleware | [Authorization.php:33-52](../../app/Support/Authorization.php#L33-L52), dipanggil dari [public/index.php](../../public/index.php) | Guard deny-by-default sebelum controller (padanan middleware) |
| 53. Roles / policies | [Authorization.php:91-107](../../app/Support/Authorization.php#L91-L107) | Resource di luar scope → **404**, bukan 403 |
| | [SalesOrderService.php:229-245](../../app/Service/SalesOrderService.php#L229-L245) | Segregation of duties: hanya Admin, dan approver ≠ pembuat |
| | [SalesOrderService.php:174-179](../../app/Service/SalesOrderService.php#L174-L179) | Ownership check (padanan Policy) |
| 54. Email verification | N/A | Akun dibuat oleh Admin |
| 55. Upload / download | [ProductImageService.php:111-157](../../app/Service/ProductImageService.php#L111-L157) | Storage di luar docroot, download lewat controller |
| 56. Big dataset | [ReportController.php:182-200](../../app/Controller/ReportController.php#L182-L200), [Response.php:60](../../app/Support/Response.php#L60) | ⚠️ CSV di-stream ke `php://output`, tapi `$rows` dimuat penuh lebih dulu di [ReportController.php:105](../../app/Controller/ReportController.php#L105) |

Test pendukung: [SalesOrderServiceTest.php:337-361](../../tests/Unit/Service/SalesOrderServiceTest.php#L337-L361),
[ApprovalAuthorizationTest.php](../../tests/Integration/ApprovalAuthorizationTest.php),
[UserAccessTest.php](../../tests/Integration/UserAccessTest.php).

### Bab 10 — Errors, observability, automated testing

| Topik silabus | Lokasi | Keterangan |
| --- | --- | --- |
| 57. Log files | [Database.php:59](../../app/Support/Database.php#L59), [index.php:151-156](../../public/index.php#L151-L156) | ⚠️ `error_log` ke stderr; belum structured dan tanpa correlation ID |
| 58. try-catch & exceptions | [app/Support/Exception/](../../app/Support/Exception/) | Taxonomy: Domain, Forbidden, NotFound, RateLimit, Unauthenticated, Validation |
| | [ProductController.php:109](../../app/Controller/ProductController.php#L109) | Catch hanya untuk menerjemahkan ke 422, bukan menelan error |
| | [index.php:153](../../public/index.php#L153) | Jaring pengaman `Throwable`: detail ke log, pesan generik ke user |
| 59. Local debugging tools | N/A | Debugbar/Telescope/Ray khusus Laravel |
| 60. Custom error pages | [views/error/](../../views/error/) | Halaman 400/403/404/429/500 tanpa stack trace |
| 61. Unit testing | [tests/Unit/Service/](../../tests/Unit/Service/), [tests/Unit/Fake/](../../tests/Unit/Fake/) | Unit test tanpa DB/session memakai in-memory repository dan [FixedClock.php](../../tests/Unit/Fake/FixedClock.php) |
| 62. Assertions | [ConcurrentGoodsIssueTest.php:88-196](../../tests/Integration/ConcurrentGoodsIssueTest.php#L88-L196) | Lock benar-benar menahan koneksi kedua; tidak oversell |
| | [LedgerReconciliationTest.php:59-140](../../tests/Integration/LedgerReconciliationTest.php#L59-L140) | Invariant `SUM(ledger) = stock`; ledger append-only |
| 63. Testing database & CRUD | [IntegrationTestCase.php](../../tests/Integration/IntegrationTestCase.php), [01-create-test-db.sh](../../database/docker-init/01-create-test-db.sh), [phpunit.xml](../../phpunit.xml) | Database test terpisah; suite Unit dan Integration dipisah |

Laporan: [test-results.md](../testing/test-results.md), [failure-paths.md](../testing/failure-paths.md).

### Bab 11 — SOLID dan design patterns

| Topik silabus | Lokasi | Keterangan |
| --- | --- | --- |
| 64. SOLID | [app/Controller/](../../app/Controller/) → [app/Service/](../../app/Service/) → [app/Repository/](../../app/Repository/) | SRP per layer; dependency inversion di boundary repository |
| | [ClockInterface.php](../../app/Support/ClockInterface.php), [TransactionRunner.php](../../app/Support/TransactionRunner.php) | Waktu dan transaction di-abstraksi agar Service bisa di-unit-test |
| 65. Factory, Strategy, Repository | [ProductStockRepositoryInterface.php](../../app/Repository/ProductStockRepositoryInterface.php) + [MysqlProductStockRepository.php:46-59](../../app/Repository/Mysql/MysqlProductStockRepository.php#L46-L59) | Repository dengan nilai nyata (`lockForUpdate`), bukan passthrough CRUD |
| | [config/container.php](../../config/container.php) | Wiring manual (padanan factory), tanpa DI container |
| | [adr-001-repository-abstraction.md](../architecture/adr-001-repository-abstraction.md), [adr-002-concurrency-control.md](../architecture/adr-002-concurrency-control.md) | Decision record |
| | — | Strategy tidak dipakai: belum ada variasi algoritma |

### Bab 12 — Refactoring dan resource optimization

| Topik silabus | Lokasi | Keterangan |
| --- | --- | --- |
| 66. Refactoring decision | [refactor-log.md](refactor-log.md), [tech-debt.md](tech-debt.md), [critique.md](critique.md) | Keputusan refactor dan utang teknis tercatat |
| | [phpstan.neon](../../phpstan.neon), [phpcs.xml](../../phpcs.xml) | Static analysis level 6 dan PSR-12 sebagai pengaman refactor |
| 67. Resource & memory optimization | — ❌ | Belum ada baseline waktu, peak memory, dan jumlah query |

## Pemetaan kriteria penilaian

Bagian ini menjawab enam kriteria penilaian tambahan. Setiap kriteria merujuk ke kode dan
dokumen yang sudah ada.

### K1 — Struktur, alur, dan implementasi teknis PHP

**Struktur folder**

| Folder | Tanggung jawab |
| --- | --- |
| [public/index.php](../../public/index.php) | Satu-satunya entry point HTTP (front controller) |
| [config/](../../config/) | Konfigurasi, route table, dan wiring object ([container.php](../../config/container.php)) |
| [app/Controller/](../../app/Controller/) | HTTP saja: baca request, panggil Service, pilih view |
| [app/Service/](../../app/Service/) | Seluruh business rule |
| [app/Repository/](../../app/Repository/) | Interface + implementasi MySQL |
| [app/Entity/](../../app/Entity/) | Domain state dan enum status |
| [app/Support/](../../app/Support/) | Plumbing: Router, View, Session, Csrf, Authorization, Validator |
| [views/](../../views/) | Template PHP, output lewat `View::e()` |
| [database/](../../database/) | Schema, seed, dan runner migrasi |
| [tests/](../../tests/) | Suite Unit dan Integration |

**Alur satu request** (lihat [index.php](../../public/index.php)):

1. Runtime guard PHP 8.4 — [index.php:32-39](../../public/index.php#L32-L39)
2. Autoload, config, dan container — [index.php:43-61](../../public/index.php#L43-L61)
3. Router mencocokkan method + path — [index.php:88](../../public/index.php#L88)
4. Authorization guard memeriksa role — [index.php:91](../../public/index.php#L91)
5. Controller dari container menjalankan action — [index.php:102-106](../../public/index.php#L102-L106)
6. Exception dipetakan ke status HTTP — [index.php:128-153](../../public/index.php#L128-L153)

Alur bisnis paling kritikal, **goods issue**, dijelaskan di
[adr-002-concurrency-control.md](../architecture/adr-002-concurrency-control.md). Ada dua
fase: semua baris dikunci dan diverifikasi dulu
([StockService.php:215-233](../../app/Service/StockService.php#L215-L233)), baru kemudian
stock dan ledger ditulis dalam transaction yang sama.

Implementasi teknis: PHP 8.4 dengan `declare(strict_types=1)` di setiap file, PSR-4
autoload ([composer.json](../../composer.json)), PHPStan level 6 **tanpa error**
([phpstan-report.txt](phpstan-report.txt)), dan PSR-12 **136/136 file lolos**
([phpcs-report.txt](phpcs-report.txt)).

### K2 — PHP Native dengan pendekatan OOP

| Konsep OOP | Penerapan |
| --- | --- |
| Encapsulation | Entity dan Service `final` dengan property `private readonly`, misalnya [AuthService.php:42-44](../../app/Service/AuthService.php#L42-L44) |
| Abstraction | 13 interface: 11 repository + [ClockInterface](../../app/Support/ClockInterface.php) + [TransactionRunner](../../app/Support/TransactionRunner.php) |
| Inheritance | [MysqlRepository](../../app/Repository/Mysql/MysqlRepository.php#L20) sebagai abstract base; dipakai hanya untuk shared helper, bukan hierarki dalam |
| Polymorphism | `Mysql*Repository` dan `InMemory*Repository` saling menggantikan di balik interface yang sama |
| Enum | Status dan role sebagai backed enum dengan method domain, misalnya [PurchaseOrderStatus.php:32-38](../../app/Entity/Enum/PurchaseOrderStatus.php#L32-L38) |
| Dependency injection | Constructor injection manual di [container.php](../../config/container.php), tanpa DI container |
| Exception sebagai object | Hierarki exception di [app/Support/Exception/](../../app/Support/Exception/) |

Kelas diagram: [class-diagram-as-built.md](../architecture/class-diagram-as-built.md).

### K3 — Integrasi PHP dengan database dan komponen lain

| Komponen | Penerapan |
| --- | --- |
| MySQL via PDO | [Database.php:49-52](../../app/Support/Database.php#L49-L52): exception mode, native prepared statement |
| Transaction | [Database.php:81](../../app/Support/Database.php#L81) dipanggil Service lewat interface [TransactionRunner](../../app/Support/TransactionRunner.php) |
| Row locking | `SELECT ... FOR UPDATE` di [MysqlProductStockRepository.php:46-59](../../app/Repository/Mysql/MysqlProductStockRepository.php#L46-L59) |
| Ledger append-only | [MysqlStockLedgerRepository.php:25-28](../../app/Repository/Mysql/MysqlStockLedgerRepository.php#L25-L28) hanya `INSERT` |
| Constraint di DB | FK, unique, dan CHECK di [001_schema.sql](../../database/001_schema.sql) sebagai lapisan pertahanan kedua |
| Session | [Session.php](../../app/Support/Session.php) |
| Filesystem | Upload image di [ProductImageService.php](../../app/Service/ProductImageService.php) |
| JSON API | [app/Controller/Api/](../../app/Controller/Api/) + [openapi.yaml](../../specs/001-inventory-order-management/contracts/openapi.yaml) |
| CSV export | [ReportController.php:182-200](../../app/Controller/ReportController.php#L182-L200) |
| CLI job | [check-low-stock.php](../../scripts/check-low-stock.php) memakai container dan Service yang sama dengan web |
| Docker | [Dockerfile](../../Dockerfile), [compose.yaml](../../compose.yaml): PHP 8.4 + Apache + MySQL 8, database test terpisah |

### K4 — Validasi, keamanan, hak akses, dan penanganan error

| Aspek | Penerapan |
| --- | --- |
| Validasi | [Validator.php](../../app/Support/Validator.php) dipanggil di Service; error dikembalikan sebagai 422 dengan pesan per field |
| Validasi aturan bisnis | Mis. stock tidak cukup → `DomainException` di [StockService.php:234](../../app/Service/StockService.php#L234) |
| Keamanan | Lihat [Bab 6](#bab-6--secure-php-boundaries): XSS, CSRF, SQL injection, upload, password, rate limit, CSV injection |
| Hak akses (role) | Deny by default di [Authorization.php:33-52](../../app/Support/Authorization.php#L33-L52); role per route di [routes.php](../../config/routes.php) dan [http-routes.md](../../specs/001-inventory-order-management/contracts/http-routes.md) |
| Hak akses (data) | Sales hanya melihat order miliknya; di luar scope → 404 ([Authorization.php:91-107](../../app/Support/Authorization.php#L91-L107)) |
| Segregation of duties | [SalesOrderService.php:229-245](../../app/Service/SalesOrderService.php#L229-L245): hanya Admin, dan tidak untuk order buatannya sendiri |
| Penanganan error | Exception dipetakan ke 400/401/403/404/422/429/500 di [index.php:128-153](../../public/index.php#L128-L153); detail hanya ke log, user melihat halaman [views/error/](../../views/error/) |
| Bukti jalur gagal | [failure-paths.md](../testing/failure-paths.md) |

### K5 — Unit test dan hasil pengujian

Hasil terakhir yang tercatat di [test-results.md](../testing/test-results.md) (dijalankan
2026-09-14 di Docker, PHP 8.4.25, MySQL 8.0.46):

| Suite | Hasil |
| --- | --- |
| Unit | **OK — 363 test, 917 assertion** |
| Integration | **OK — 82 test, 318 assertion** |
| Gabungan | **OK — 445 test, 1235 assertion**; nol skip, incomplete, atau risky |

Hasil ini tidak dijalankan ulang saat gap analysis ini dibuat.

- **Unit test** tanpa database, session, atau network: in-memory repository di
  [tests/Unit/Fake/](../../tests/Unit/Fake/) dan waktu tetap lewat
  [FixedClock.php](../../tests/Unit/Fake/FixedClock.php).
- **Integration test** terhadap MySQL 8 sungguhan, termasuk dua koneksi paralel untuk
  membuktikan oversell tidak terjadi
  ([ConcurrentGoodsIssueTest.php](../../tests/Integration/ConcurrentGoodsIssueTest.php)).
- **Pemetaan use case ke test**: [use-case-coverage.md](../testing/use-case-coverage.md).
  Method yang belum punya test langsung: `ProductImageService::store/read/delete`,
  `StockService::availableFor` dan dua method baca movement, serta beberapa method baca di
  `ProductService` dan `UserService`.
- `phpunit.xml` menyetel `failOnWarning`, `failOnRisky`, dan `failOnNotice`, sehingga test
  yang tidak menguji apa pun menggagalkan suite.

Cara mengulang:

```bash
docker compose up -d
docker compose exec app composer db:test
docker compose exec app composer test
```

### K6 — Alasan pemilihan struktur dan bagian yang masih bisa diperbaiki

**Alasan pemilihan** (lengkap di [research.md](../../specs/001-inventory-order-management/research.md)
dan ADR):

| Keputusan | Alasan | Alternatif yang ditolak |
| --- | --- | --- |
| Controller → Service → Repository | Business rule terkumpul di satu layer yang bisa di-unit-test tanpa HTTP dan DB | Logika di controller (sulit diuji tanpa HTTP) |
| Repository dengan interface | Service bisa diuji dengan fake in-memory; lock `FOR UPDATE` tersembunyi di balik contract yang jelas ([ADR-001](../architecture/adr-001-repository-abstraction.md)) | Query PDO langsung di Service |
| Acting user sebagai argument | Aturan approval bisa diuji tanpa session | Membaca `$_SESSION` di Service |
| Pessimistic lock `FOR UPDATE` | Mencegah oversell pada goods issue bersamaan ([ADR-002](../architecture/adr-002-concurrency-control.md)) | Optimistic locking (butuh retry di UI); cek stock tanpa lock (race condition) |
| DI manual tanpa container | Object graph kecil dan eksplisit; tidak ada dependency tambahan | DI container (dilarang constitution, over-engineering) |
| 404 untuk resource di luar scope | Keberadaan record tidak bocor | 403 (membocorkan bahwa record ada) |
| PHP native tanpa framework | Diwajibkan oleh brief dan constitution | Laravel / Symfony |

**Bagian yang masih bisa diperbaiki atau disederhanakan** (dicatat jujur di
[critique.md](critique.md) dan [tech-debt.md](tech-debt.md)):

| Area | Masalah | Arah perbaikan |
| --- | --- | --- |
| `StockService` | `issueWithinTransaction()` dan `receiveWithinTransaction()` memakai pola dua fase yang sama | Dibiarkan sengaja agar alur kritikal tetap terbaca; ditinjau bila muncul alur stock ketiga |
| Controller order | `queryState()` dan `sortCriteriaFrom()` terduplikasi di tiga controller | Tarik ke satu helper kecil begitu ada controller keempat |
| `ProductRepositoryInterface` | 14 method; konsumen hanya butuh sebagian (Interface Segregation lemah) | Pecah hanya bila ada implementasi kedua yang nyata |
| Fake in-memory | 11 repository ditulis dua kali; fake tidak menjalankan SQL dan sudah dua kali menyembunyikan bug | Tambah integration test untuk setiap query non-trivial |
| Nested transaction | Transaction Service tidak terbentuk di dalam integration test yang dibungkus transaction | SAVEPOINT, atau gagal keras |
| CI | Suite belum otomatis dijalankan; integration suite pernah tidak dijalankan selama enam phase | Jalankan kedua suite di CI setiap perubahan |
| Upload | Jalur filesystem `ProductImageService` belum teruji | Integration test dengan direktori sementara |
| Logging | Belum structured dan rawan log poisoning | Lihat celah nomor 1 di bawah |
| `Validator` | Memakai `mixed` di titik masuk | Dibenarkan karena memang pintu masuk data tak bertipe; titik terlemah dari sisi tipe |

## Celah yang direkomendasikan untuk ditutup

Diurutkan dari dampak terbesar. Semua perubahan kecil dan tidak menambah layer baru
(sesuai C-003).

1. **Log poisoning dan structured log** (Bab 6.3, Bab 10). Buat satu helper log yang
   membuang CR/LF dari pesan dan menulis JSON dengan `request_id` per request. Titik yang
   diganti: [index.php:151](../../public/index.php#L151), [index.php:156](../../public/index.php#L156),
   [Database.php:59](../../app/Support/Database.php#L59).
2. **Bukti query** (Bab 8.3). Simpan output `EXPLAIN` untuk query list, report, dan
   dashboard utama, serta jumlah query per halaman, di `docs/quality/`.
3. **Memory pada report** (Bab 4.3, 12.2). Ubah `ReportService::stockMovements()` dan
   `salesOrders()` agar mengembalikan generator, lalu catat `memory_get_peak_usage()`
   sebelum dan sesudah perubahan.
4. **Rollback migration** (Bab 8.1). Minimal dokumentasikan strategi rollback; idealnya
   sediakan skrip `down` per migrasi di [migrate.php](../../database/migrate.php).
5. **`disable_functions`** (topik 30). Tambahkan `exec`, `shell_exec`, `system`,
   `passthru`, dan `proc_open` ke `99-app.ini` di [Dockerfile:25-34](../../Dockerfile#L25-L34).

## Sengaja tidak diterapkan

Topik berikut tidak ditambahkan karena tidak menyelesaikan masalah nyata di spec.
Menambahkannya akan dinilai sebagai over-engineering (C-003). Alasan ini disiapkan untuk
technical defense.

| Topik | Alasan |
| --- | --- |
| Heredoc/nowdoc, bitmask domain | Tidak ada kebutuhan domain; SQL sudah memakai prepared statement biasa |
| Serialization, magic method | Tidak ada state yang perlu di-serialize; modul sendiri menganjurkan method typed eksplisit |
| Strategy | Belum ada variasi algoritma yang terbukti |
| JWT | Aplikasi server-rendered dengan auth berbasis session |
| Guzzle / HTTP client | Tidak ada integrasi dengan service eksternal |
| Email verification | Akun dibuat oleh Admin, bukan registrasi mandiri |
| Import big dataset | Tidak ada di requirement |
| Eloquent, Debugbar, Telescope, Ray | Framework dan ORM dilarang oleh constitution |
