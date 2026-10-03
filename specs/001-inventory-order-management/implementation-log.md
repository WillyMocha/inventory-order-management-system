# Implementation Log

## Session 2026-09-10 — Phase 1 & Phase 2

**Checkpoint HEAD**: `54fd119cac3bb0b75ac05740e4ca568458dad845`
**Tasks targeted**: T001–T044 (Phase 1 Setup, Phase 2 Foundational) — scoped by the user to
"phase 1 dan phase 2 saja"
**Working tree at start**: uncommitted doc changes already present
(`.rudis/memory/constitution.md`, `CLAUDE.md`, `specs/`). Left untouched; no git write
command was run this session.
**Result**: all 44 tasks complete, every verification gate green.

---

### Phase 1 — Setup (T001–T009)

| Task | Files | Note |
| --- | --- | --- |
| T001 | directory tree | Includes `storage/uploads/` and `tests/Unit/Support` added by the `/rudis.analyze` remediation |
| T002 | `composer.json` | `"php": "~8.4.0"` + platform pin; PHPUnit 11, PHPStan 2, PHP_CodeSniffer 3 |
| T003 | `Dockerfile`, `.dockerignore` | `php:8.4-apache`; `mod_rewrite` **and `mod_headers`**; `display_errors=Off` |
| T004 | `compose.yaml`, `database/docker-init/01-create-test-db.sql` | Two services; app gated on db healthcheck |
| T005 | `.env.example` | Example values only |
| T006 | `.gitignore` | Excludes `.env`, `vendor/`, upload contents |
| T007 | `phpunit.xml` | Two suites, `failOnWarning` |
| T008 | `phpstan.neon` | Level 6 |
| T009 | `phpcs.xml` | PSR-12 + `RequireStrictTypes` |

### Phase 2 — Foundational (T010–T044)

| Task | Files | Note |
| --- | --- | --- |
| T010 | `docs/planning/class-diagram-initial.md` | Written **before** any application code (DESIGN-01) |
| T011 | `database/001_schema.sql` | 12 domain + 2 operational tables; every column mapped to its source attribute in a comment |
| T012 | `database/migrate.php` | Ordered SQL runner |
| T013 | `database/002_seed.sql`, `database/generate-seed.py` | Generated so stock is computed **from** the ledger |
| T014 | `config/app.php` | Fails loudly on a missing key |
| T015 | `app/Support/Database.php` | `EMULATE_PREPARES = false`; `transaction()` helper |
| T016–T021 | `app/Entity/**` (17 files) | 5 enums, 12 entities; domain rules only |
| T022 | `app/Repository/*.php` (11 interfaces) | The dependency-inversion boundary |
| T023 | `app/Repository/Mysql/*.php` (11 + base) | All parameterized; `lockForUpdate()` issues `SELECT … FOR UPDATE` |
| T024 | `tests/Unit/Fake/*.php` (11 + FixedClock) | Second implementation of every interface |
| T025–T035 | `app/Support/**` (19 files) | Router, Request/Response, Session, Csrf, Authorization, View, 6 exceptions, Validator, Paginator, Money, Clock |
| T036 | `public/index.php` | PHP 8.4 guard; global CSRF check; every exception → safe response |
| T037 | `public/.htaccess` | Rewrite, dotfile deny, security headers |
| T038 | `config/container.php` | Hand-wired; no DI container |
| T039 | `config/routes.php` | All 68 routes with explicit roles |
| T040 | `public/assets/css/{tokens,app}.css` | Tokens exactly per research R-013 |
| T041 | `public/assets/icons/lucide-sprite.svg` | 20 icons, vendored locally (ISC) |
| T042 | `views/layout/*` | `app.php`, `auth.php`, `_nav`, `_flash`, `_empty-state`, `_pagination` |
| T043 | `views/error/*` | 400, 403, 404, 429, 500 — no technical detail |
| T044 | `tests/bootstrap.php`, `tests/Integration/IntegrationTestCase.php` | Transaction-per-test; `newSeparateConnection()` for T098 |

---

### Verification gates

| Gate | Result |
| --- | --- |
| PHP syntax (all 78 files) | ✅ clean |
| PHPStan level 6 | ✅ **No errors** |
| PHP_CodeSniffer PSR-12 | ✅ **No errors** (78 files) |
| `docker compose up --build` from clean | ✅ both services up, db healthcheck gated app start |
| PHP version (spec C-001) | ✅ **8.4.25** |
| Migration from empty (`--fresh`) | ✅ 14 tables dropped, both files applied |
| Source conformance vs `data-model.md` | ✅ 14 tables, 5 enums exact, 11 CHECK constraints |
| NFR-002 ledger ↔ stock reconciliation | ✅ **0 mismatches** across all 60 stock rows |
| Seed vs NFR-011 | ✅ 5 users, 2 warehouses, 30 products, 15+15 parties, 32 orders, all statuses, 7 low-stock |
| Segregation of duties in seed | ✅ no row where `approved_by = created_by` |
| HTTP: route renders through layout | ✅ `/_health` 200 |
| HTTP: unauthenticated HTML | ✅ 302 → `/login` |
| HTTP: unauthenticated `/api/*` | ✅ **401 JSON**, matching `contracts/openapi.yaml` envelope |
| HTTP: unknown route | ✅ safe 404 |
| Upload dir not web-reachable (R-006) | ✅ 404 on all three probe paths |
| Dotfiles denied | ✅ `/.env` → 403 |
| Security headers | ✅ `nosniff`, `DENY`, `same-origin` |
| `composer audit` | ✅ no advisories |
| Abandoned packages | ✅ 0 of 29 |
| PHP 8.4 EOL | ✅ active support to 2026-12-31, security to 2028-12-31 |
| PHPUnit both suites | ✅ run clean |

---

### Errors found and fixed during the gate

1. **`Database::transaction()` — PHPStan `if.alwaysFalse`.** Re-calling `inTransaction()` in the
   catch block was narrowed to always-false after the early-return guard. Fixed by tracking a
   local `$committed` flag and using `finally`, not by suppressing the finding.
2. **`migrate.php` — "There is no active transaction".** MySQL implicit-commits on DDL, so the
   transaction was already closed by the time `commit()` ran, and the schema file failed. Fixed
   by guarding both `commit()` and `rollBack()` with `inTransaction()`. Verified end-to-end with
   `--fresh` from an empty database.
3. **Security headers were inert.** `public/.htaccess` declared `X-Content-Type-Options`,
   `X-Frame-Options` and `Referrer-Policy`, but the image only enabled `mod_rewrite`, so
   `<IfModule mod_headers.c>` never matched and no header was sent. Fixed by
   `a2enmod rewrite headers`; verified by response inspection after rebuild.
4. **PSR-12 file-docblock order.** Five files placed the docblock after `declare(strict_types=1);`.
   Moved above it. Constitution Principle II still holds — a docblock is not a statement.
5. **Seed had 0 low-stock products and only 32 of 60 stock rows.** First generator pass hand-picked
   receipt quantities and happened to leave every product above its reorder point. Restructured to
   compute opening receipts backwards from a declared target, and to emit a row for every
   (product, warehouse) pair. Now 7 low-stock products and all 60 rows.

**UNRESOLVED**: none.

---

### Additions beyond the literal task list

Both are small, were needed to satisfy a gate, and are flagged here for review:

- **`app/Controller/HealthController.php` + `views/health/index.php`.** The Phase 2 checkpoint
  requires "a stub route renders through the layout", and no controller exists until Phase 3.
  This is the smallest thing that makes the checkpoint verifiable; it also serves as a container
  healthcheck target. It deliberately leaks nothing — no version, no database status.
- **`tests/Integration/HarnessSmokeTest.php`.** Two assertions proving T044's harness actually
  works: that integration tests hit the separate `ioms_test` database and are wrapped in a
  transaction, and that `newSeparateConnection()` yields an independent connection — the exact
  capability T098's concurrency test depends on. Delete it if you'd rather Phase 3 own it.
- **`database/generate-seed.py`.** The seed is generated rather than hand-written so that
  ledger/stock consistency is structural rather than a thing to re-verify by eye. Kept in the
  repo so the seed can be regenerated; the SQL header points at it.

### Assumptions recorded

- **`database/docker-init/01-create-test-db.sql`** creates `ioms_test` on first volume init.
  `compose.yaml` was not specified to that level of detail in T004; this was the simplest way to
  give the integration suite its own database without a manual step.
- **`.env` was not created** — a deny rule blocked copying `.env.example` to `.env`. Not needed:
  `compose.yaml` defaults every variable. A developer still runs `cp .env.example .env` per the
  quickstart.
- **PHPUnit 11.5 kept** although 12.x exists (and PHP_CodeSniffer 3.13 although 4.x exists). Both
  are current, supported, and carry no advisory; plan.md specified the 11.x line. Informational
  only — not a gate failure.

### Rollback

Checkpoint: `54fd119cac3bb0b75ac05740e4ca568458dad845` (unchanged — nothing was committed).

```bash
git status                              # everything from this run is untracked/unstaged
git diff 54fd119 -- CLAUDE.md .rudis/   # the two previously-modified tracked files
git checkout 54fd119 -- <path>          # revert one tracked file
```

All new code is untracked, so removing it is a matter of deleting the new directories
(`app/`, `config/`, `database/`, `public/`, `views/`, `tests/`, `storage/`, `docs/`) and the
root config files. `git reset --hard 54fd119` would **not** remove untracked files and would
discard the constitution and CLAUDE.md edits — use `git clean -nd` first to preview.

---

## Session 2026-09-10 (cont.) — Phase 3: US1 Authentication

**Checkpoint HEAD**: `54fd119cac3bb0b75ac05740e4ca568458dad845` (unchanged; nothing committed)
**Tasks targeted**: T045–T052 — scoped by the user to "phase 3"
**Result**: all 8 tasks complete, every gate green. 27 tests passing (14 unit + 13 integration).

### TDD order

Tests were written first and confirmed **failing** (`Class "App\Service\AuthService" not found`)
before any implementation existed.

| Task | Files | Note |
| --- | --- | --- |
| T045 | `tests/Unit/Service/AuthServiceTest.php` | Valid / wrong password / unknown email / deactivated; all failure causes indistinguishable |
| T046 | `tests/Unit/Service/AuthServiceTest.php` | Rate limit: 6th refused, per (email, IP), unknown emails throttled identically, success clears the count |
| T047 | `tests/Integration/AuthFlowTest.php` | AuthService against real MySQL + `login_attempt`; guard deny-by-default; logout; 404-not-403 ownership |
| T048 | `app/Service/AuthService.php` | `password_verify`, dummy-hash timing defence, `password_needs_rehash`, rate limit |
| T049 | `app/Controller/AuthController.php` | showLogin / login / logout |
| T050 | `views/auth/login.php` | Uniform error, email retained, password never returned |
| T051 | `public/index.php`, `config/container.php` | Controllers resolved from the container instead of `new $class($view)` |
| T052 | `views/dashboard/index.php`, `app/Controller/DashboardController.php` | Role-dispatching shell, three distinct region sets |

### Verification gates

| Gate | Result |
| --- | --- |
| PHPStan level 6 | ✅ No errors |
| PHP_CodeSniffer PSR-12 | ✅ No errors |
| Unit suite | ✅ 14 tests, 47 assertions |
| Integration suite (real MySQL) | ✅ 13 tests, 21 assertions |
| HTTP: `GET /login` | ✅ 200, form renders |
| HTTP: wrong password / unknown email / deactivated | ✅ all 401 with the **identical** message |
| HTTP: session regeneration on sign-in | ✅ id changes (`3be1c94d…` → `4c713ac1…`) |
| HTTP: sign-out then protected route | ✅ 302 → `/login` |
| HTTP: CSRF missing / forged | ✅ 403 both |
| HTTP: rate limit | ✅ attempts 1–5 → 401, attempt 6 → 429 |
| Role dispatch | ✅ Admin / Sales / Warehouse each get distinct nav + dashboard regions |
| Visual Pass A (element checklist) | ✅ stat row, tinted icon chips, shaped skeletons, full empty state, single accent area, tokens only |
| Visual Pass B (rendered) | ✅ **rendered in real Chrome** at 1280 / 768 / 360 px; no horizontal overflow at any width |
| Security §14 self-review | ✅ no BLOCKER/MAJOR introduced |
| New dependencies | ✅ none added to the project |

### Errors found and fixed during the gate

1. **My own test was wrong.** `makeUser()` hashed at bcrypt cost 4, so the
   "current hash is left alone" case would have wrongly detected a needed rehash. Changed to hash
   once at `PASSWORD_DEFAULT` and cache it for the class (also keeps the suite fast).
2. **PHPStan `nullsafe.neverNull`** — `$user?->passwordHash ?? DUMMY_HASH` flagged as an unnecessary
   nullsafe. Replaced with an explicit `$user !== null ? … : …`, which reads better anyway.
3. **PHPStan `staticMethod.alreadyNarrowedType`** — `assertIsString(password_hash(...))` is always
   true in PHP 8. Assertion removed.
4. **Integration tests had no schema.** `ioms_test` was created empty by the Docker init script but
   nothing ever applied the schema to it. Added `--schema-only` to `migrate.php` and a
   `composer db:test` script. Seed is deliberately excluded so tests never depend on demo data
   (FIRST: Independent).
5. **Sign-in button used the `log-out` icon** — an arrow leaving a box, semantically backwards on a
   sign-in button. Added a proper `log-in` symbol to the sprite.
6. **Nav wrapped raggedly at 1280px** (Pass B) — "Reports" dropped to a second row while space sat
   unused on the right. Changed `.nav-links` to `nowrap` + horizontal scroll, trimmed link padding,
   pinned brand and user block with `flex: none`.
7. **Horizontal page overflow at 360px** (Pass B) — measured `documentElement` at **485px wide inside
   a 360px window**; the nowrap nav row was widening the whole document, clipping every card.
   Fixed with `max-width: 100%` on `.nav-links` (base + mobile). Re-measured: 360/768/1280 all show
   `scrollWidth === clientWidth`.

**UNRESOLVED**: none.

### Notes on gate methodology

- **Pass B was genuinely rendered**, not assumed. `chrome --headless --screenshot` cannot go below
  ~485 CSS px on Windows, and an iframe harness was blocked by our own `X-Frame-Options: DENY`
  (the header working correctly). `puppeteer-core` was installed **into the scratchpad, not the
  project**, to drive the already-installed Chrome with real device emulation. `composer.json`,
  `composer.lock` and the repo are unchanged by it; no `node_modules` exists in the project.
- **Session regeneration** is asserted over real HTTP rather than in a unit test: PHP CLI has no
  active session, so `session_regenerate_id()` cannot run there. The integration test covers the
  guard's decisions; the regeneration itself is verified by observing the cookie change.

### Deliberate behaviours worth review

- **Empty email or password returns 422**, while wrong credentials return 401. The user-visible
  message is identical in both cases, and the 422 path triggers before any user lookup — so it
  cannot reveal whether an email exists. FR-002 is satisfied; flagged only because the status codes
  differ.
- **`AuthService::DUMMY_HASH`** is a public constant bcrypt hash used only when no user matches, so
  the timing cost of a failed lookup matches a real one. It is not a credential: the `$user === null`
  branch fails closed regardless of the verify result.
- **`AuthService::verifyPasswordFor()`** exists but has no caller yet. It is the step-up re-auth
  primitive that T056 (Admin password change) needs.

### Files added this phase

```
app/Service/AuthService.php
app/Controller/AuthController.php
app/Controller/DashboardController.php
views/auth/login.php
views/dashboard/index.php
tests/Unit/Service/AuthServiceTest.php
tests/Integration/AuthFlowTest.php
```

### Files modified this phase

```
public/index.php                      controllers resolved from container
config/container.php                  AuthService + controller factories
public/assets/css/app.css             nav overflow fixes (Pass B)
public/assets/icons/lucide-sprite.svg added log-in symbol
database/migrate.php                  --schema-only flag
composer.json                         db:test script
```

---

## Session 2026-09-10 (cont.) — Phase 4: US2 Master Data

**Checkpoint HEAD**: `54fd119cac3bb0b75ac05740e4ca568458dad845` (unchanged; nothing committed)
**Tasks targeted**: T053–T073 (3 Bolts) — scoped by the user to "phase 4"
**Result**: all 21 tasks complete, every gate green. 93 tests passing (75 unit + 18 integration).

### Bolt 1 — User management (T053–T057)

| Task | Files | Note |
| --- | --- | --- |
| T053 | `tests/Unit/Service/UserServiceTest.php` | 17 tests: duplicate email, 3-role restriction, self-deactivation refused, deactivated user cannot sign in |
| T054 | `tests/Integration/UserAccessTest.php` | Reads the real route table; asserts every `/users*` route allows **only** Admin, as data and as behavior |
| T055 | `app/Service/UserService.php` | Create/update/toggle/changePassword; hashes on create; email unique excluding self |
| T056 | `app/Controller/UserController.php` | Admin-only; **step-up re-auth** on password change |
| T057 | `views/users/{index,form}.php` | Stat row, both empty states, per-field + summary errors |

### Bolt 2 — Products, categories, warehouses (T058–T068)

| Task | Files | Note |
| --- | --- | --- |
| T058–T060 | `tests/Unit/Service/ProductServiceTest.php` | 20 tests: duplicate SKU, negative prices/reorder rejected, zero accepted, stock breakdown, low-stock boundary |
| T061 | `tests/Unit/Service/ProductImageServiceTest.php` | 11 tests: content-based type check, size limits, unguessable names, path traversal refused |
| T062 | `app/Service/ProductService.php` | No delete operation at all — see decision below |
| T063 | `app/Service/ProductImageService.php` | `finfo` + `getimagesize` double check, random name, outside document root |
| T064 | `app/Service/MasterDataService.php` | Category + Warehouse |
| T065 | `app/Controller/ProductController.php` | Includes `image()` serving from outside the web root |
| T066 | `app/Controller/{Category,Warehouse}Controller.php` | Per-action roles from the route table |
| T067–T068 | `views/products/*`, `views/categories/*`, `views/warehouses/*` | |

### Bolt 3 — Suppliers and customers (T069–T073)

| Task | Files | Note |
| --- | --- | --- |
| T069 | `tests/Unit/Service/PartyServiceTest.php` | 11 tests, including two that lock Supplier/Customer separation |
| T070 | `app/Service/PartyService.php` | Both entities, kept distinct per data-model.md |
| T071 | `app/Controller/{Supplier,Customer}Controller.php` | Sales may read customers; only Admin writes |
| T072 | `views/{suppliers,customers}/*` + `views/layout/_party-{list,form}.php` | Shared partials avoid ~200 duplicated lines |
| T073 | all master-data forms | Per-field + summary errors, values retained, nothing saved on failure |

### Verification gates

| Gate | Result |
| --- | --- |
| PHPStan level 6 | PASS — no errors |
| PHP_CodeSniffer PSR-12 | PASS — no errors |
| Unit suite | PASS — 75 tests |
| Integration suite (real MySQL) | PASS — 18 tests |
| Admin reaches all 14 master-data pages | PASS — all 200 |
| **FR-006 role scoping, called directly** | PASS — Sales 403 on users/suppliers/categories/warehouses, 200 on customers/products; Warehouse 403 on users/customers, 200 on warehouses/products |
| FR-029 validation round-trip | PASS — 422, **product count unchanged (30 to 30)**, 3 per-field errors, summary block, entered values retained |
| Duplicate email on user create | PASS — 422 with field-level message |
| Admin self-deactivation | PASS — refused, flash shown, `is_active` still 1 |
| NFR-002 ledger vs stock | PASS — still 0 mismatches after this phase's writes |
| Visual Pass A | PASS — stat rows, tinted chips, real delta indicators, both empty states, entity icons, single accent area, tokens only |
| Visual Pass B (rendered in Chrome) | PASS — products, product detail, categories, warehouses, suppliers, customers, users at 1280 and 360 px; no overflow anywhere |
| Security section 14 self-review | PASS — no BLOCKER/MAJOR |
| New dependencies | None added |

### Errors found and fixed during the gate

1. **My own view rendered the wrong column.** `views/products/index.php` printed `unit` under a
   "Category" heading. Fixed by passing a `categoryNames` map from the controller (one query, not
   one per row) and giving Unit its own column.
2. **Five PHPStan findings, all in my tests** — two tautological `assertTrue(true, ...)` markers,
   a missing `@param` value type (twice), and `method_exists()` calls PHPStan folded to a constant
   false. The last one was interesting: PHPStan *proving* the delete method is absent is a good
   result, but it makes the runtime test vacuous, so it was rewritten to use `ReflectionClass`
   against both `ProductService` and `ProductRepositoryInterface`, checking five destructive verb
   names. That version genuinely runs and survives refactoring.
3. **One PSR-12 long line** in the reflection test — wrapped.
4. **Underlined entity links in dense tables** (Pass B) — every product name carried a permanent
   underline, making rows read as noisy. Now underlined on hover only, with the accent color.
5. **Weak stat delta on the users page** (Pass A polish bar) — the tile's third line was just the
   word "accounts", which carries no information. Now shows the active/inactive split with a
   matching icon and tone.

**UNRESOLVED**: none.

### Design decision taken during implementation

**Product, Supplier and Customer have no delete operation anywhere.** The T059 task text says a
referenced product "cannot be deleted, only deactivated", which could be read as a guarded delete.
Re-reading section 1.3 — "Produk, supplier, dan customer dinonaktifkan, bukan dihapus permanen" —
the rule is unconditional, and `contracts/http-routes.md` defines no delete endpoint for any of
them. So the capability is absent by construction rather than present-but-guarded: no `delete()`
on the services, none on the repository interfaces, no route. `ProductServiceTest` and
`PartyServiceTest` lock that absence in, so adding one later fails immediately.
`isReferencedByOrder()` exists purely so the UI can explain *why* only deactivation is offered.

### Note on shared party views

`views/layout/_party-list.php` and `_party-form.php` are shared by suppliers and customers because
their field shapes are identical; `views/{suppliers,customers}/{index,form}.php` are thin wrappers.
This shares **presentation only** — the entities, repositories, tables and service methods stay
fully separate per `data-model.md`, and
`PartyServiceTest::theTwoPartiesAreBackedByDistinctRepositoryContracts()` asserts that.

### Files added this phase

```
app/Service/UserService.php
app/Service/ProductService.php
app/Service/ProductImageService.php
app/Service/MasterDataService.php
app/Service/PartyService.php
app/Controller/UserController.php
app/Controller/ProductController.php
app/Controller/CategoryController.php
app/Controller/WarehouseController.php
app/Controller/SupplierController.php
app/Controller/CustomerController.php
views/users/{index,form}.php
views/products/{index,detail,form}.php
views/categories/{index,form}.php
views/warehouses/{index,form}.php
views/suppliers/{index,form}.php
views/customers/{index,form}.php
views/layout/_party-list.php
views/layout/_party-form.php
public/assets/js/confirm.js
tests/Unit/Service/UserServiceTest.php
tests/Unit/Service/ProductServiceTest.php
tests/Unit/Service/ProductImageServiceTest.php
tests/Unit/Service/PartyServiceTest.php
tests/Integration/UserAccessTest.php
```

### Files modified this phase

```
config/container.php          6 services + 6 controllers wired
public/assets/css/app.css     .row-entity, alert lists, hover-only table links
public/assets/js/main.js      imports confirm.js
```

---

## Session — 2026-09-11 · Phase 6 (US4: Sell with approval and controlled stock issue)

- **Checkpoint HEAD**: `54fd119` (`Initial commit from Rudis template`)
- **Tasks targeted**: T086–T104 (all 19)
- **Working tree at start**: dirty, 22 entries — Phases 1–5 work is present but **uncommitted**
- **No git writes performed.** All changes left unstaged for review.

### Phase ordering note

The user asked for Phase 6 while Phase 5 (T074–T085) is still open. `tasks.md` sanctions this
explicitly and in three places: Phase Dependencies ("US4 … **Independent of US3** — a Sales
Order can be issued against seeded stock without any PO"), Recommended order ("**US4 next,
before US3** — it is the hardest and the most heavily graded"), and MVP scope (US4 included,
US3 excluded). The Gantt and critical path agree. Phase 5 remains open.

Consequence recorded: `StockService` currently exposes `issueGoods()` only. `receiveGoods()`
(T083) joins it in Phase 5; the class and its unit-test file were created here.

### Tasks completed

| Task | Files | Note |
| --- | --- | --- |
| T086, T087 | `tests/Unit/Service/SalesOrderServiceTest.php` | 41 tests. Written first, confirmed red (`Class "SalesOrderService" not found`) before implementing |
| T088 | `app/Service/SalesOrderService.php` | create/submit/cancel, transition guards, `SO-YYYYMMDD-####` numbering |
| T089 | `app/Controller/SalesOrderController.php` | Sales scoping via `scopeFor()` → criteria → SQL `WHERE` |
| T090 | `views/sales-orders/{index,detail,form}.php` | |
| T091 | `tests/Unit/Service/SalesOrderServiceTest.php` | Approval rule — 11 tests incl. Admin refused on own order |
| T092, T093 | `tests/Integration/ApprovalAuthorizationTest.php` | 11 tests. **Not executed — no database available this session** |
| T094 | `app/Service/SalesOrderService.php` | `approve()` / `reject()`: Admin **and** `approvedBy !== createdBy` |
| T095 | `app/Controller/SalesOrderController.php` | Admin-only per route table; CSRF via the front-controller guard |
| T096, T097 | `tests/Unit/Service/StockServiceTest.php` | 19 tests |
| T098 | `tests/Integration/ConcurrentGoodsIssueTest.php` | Two real connections, lock proven by lock-wait timeout. **Not executed** |
| T099 | `tests/Integration/LedgerReconciliationTest.php` | Invariant checked for *every* (product, warehouse) pair. **Not executed** |
| T100 | `tests/Integration/GoodsIssueTest.php` | **Not executed** |
| T101 | `app/Service/StockService.php` | Transaction + `FOR UPDATE`, two-phase (verify all, then write), numeric lock ordering |
| T102 | `app/Controller/SalesOrderController.php` | `issueForm()` / `issue()`, Admin + Warehouse Staff |
| T103 | `views/sales-orders/issue.php` | Requested beside available; refusal re-renders with quantities and cause |
| T104 | `public/assets/js/confirm.js` | Already created in Phase 4 — **verified**, not recreated. All 6 POST forms carry `data-confirm` |

### Assumptions and decisions recorded

1. **`TransactionRunner` interface added** (`app/Support/TransactionRunner.php`). Needed so
   `StockService` can run a real transaction in production yet be unit-tested without a
   database. `Database` implements it directly — one interface, no wrapper class. Justified in
   `plan.md` Complexity Tracking. Callback narrowed from `callable(PDO)` to `callable()`, which
   also closes the only route by which a Service could have touched PDO.

2. **Two-phase issue.** All lines are locked and verified *before* any write. A refusal on the
   last line therefore cannot leave the first line changed even if rollback were to fail.

3. **Repeated lines are summed per (product, warehouse) before verification.** Two lines of the
   same product on one order (6 + 5 against 10 in stock) would each pass if checked
   independently — that is an oversell path, and it is closed. Covered by
   `repeatedLinesForTheSameProductAreLockedOnceAndSummed`.

4. **404 beats 403 where the two rules collide.** T091 wants a Sales user refused approval;
   T093/NFR-003 wants out-of-scope resources to return 404 so existence is not leaked. For
   *another* Sales user's order, ownership scoping runs first and yields `NotFoundException` —
   a refusal that also does not leak. `ForbiddenException` is reserved for an order the caller
   can legitimately see but may not approve. Both paths asserted; the refusal message for a
   foreign order is byte-identical to that for a missing one.

5. **Selling price is snapshotted from the catalog**, never taken from the request payload.
   A later catalog price change does not alter an existing order.

6. **Money arithmetic uses integer rupiah, not `bcmath`.** The Dockerfile installs only
   `pdo_mysql`, so `bcmul`/`bcadd` would work on the host and fatal in the container. Matches
   the existing whole-rupiah convention in `Support\Money` (spec A-011). Caught before commit.

7. **Numeric lock ordering.** An initial `sort($keys, SORT_STRING)` ordered product 30 before
   product 9. Deadlock was still avoided (any consistent total order does that) but it
   contradicted R-002's documented "ascending `product_id`". Corrected to `usort` on the
   integer pair; `lockOrderIsNumericNotLexicographic` covers it.

8. **`SalesOrderFixtures` trait** shares fixture setup across the three stock integration
   tests so their starting state is identical.

### Verification gate

| Check | Result |
| --- | --- |
| `php -l` on every new/changed file | pass (one parse error found and fixed in `GoodsIssueTest`) |
| PHPStan level 6 | **pass, 0 errors** (8 found and fixed: 4 `return.void`, 4 `nullsafe.neverNull`) |
| PHP_CodeSniffer PSR-12 | **pass, 0 errors** (6 CRLF violations from scripted edits, normalized to LF) |
| Unit suite | **pass — 135 tests, 290 assertions** (was 75; +60 this phase) |
| `declare(strict_types=1)` on all 16 new files | pass |
| Output escaping in new views | pass — every dynamic value via `View::e()` or an int cast; remaining raw echoes are literal strings from ternaries/`match` and integer counters |
| View smoke render, 9 states, `E_ALL` | **pass** — index (populated / empty / filtered), detail (×2), form (blank / with errors), issue (covered / short) |
| Integration suite | **NOT RUN — no database reachable this session.** Docker daemon down; Laragon MySQL not running; `config/app.php` resolves to `127.0.0.1:3306`, db `ioms`. 4 new integration files (T092, T093, T098, T099, T100) are unexecuted |
| Visual Pass B (rendered in a browser) | **NOT PERFORMED** — the app cannot boot without a database. Pass A element checklist verified statically; HTML correctness verified by the smoke render above |

### Outstanding

- **Integration tests unverified.** Run `docker compose up -d` then
  `docker compose exec app composer test:integration`. Until then T092/T093/T098/T099/T100 are
  written but unproven — including the oversell-prevention proof, which is the graded core.
- Phase 5 (T074–T085) still open. `StockService::receiveGoods()` and the PO controller/views
  are its remaining work.

### Files added this phase

```
app/Service/SalesOrderService.php
app/Service/StockService.php
app/Controller/SalesOrderController.php
app/Support/TransactionRunner.php
views/sales-orders/index.php
views/sales-orders/detail.php
views/sales-orders/form.php
views/sales-orders/issue.php
public/assets/js/order-lines.js
tests/Unit/Service/SalesOrderServiceTest.php
tests/Unit/Service/StockServiceTest.php
tests/Unit/Fake/ImmediateTransactionRunner.php
tests/Integration/ApprovalAuthorizationTest.php
tests/Integration/GoodsIssueTest.php
tests/Integration/ConcurrentGoodsIssueTest.php
tests/Integration/LedgerReconciliationTest.php
tests/Integration/SalesOrderFixtures.php
```

### Files modified this phase

```
app/Support/Database.php       implements TransactionRunner; callback narrowed to callable()
config/container.php           SalesOrderService + StockService + SalesOrderController wired
public/assets/css/app.css      .stat-value--text for name-valued stat tiles
public/assets/js/main.js       imports order-lines.js
specs/.../plan.md              Complexity Tracking: TransactionRunner justification
specs/.../tasks.md             T086-T104 marked [X]
```

---

## Session — 2026-09-11 · Phase 5 (US3: Purchase from a supplier and receive goods)

- **Checkpoint HEAD**: `54fd119` (`Initial commit from Rudis template`) — unchanged
- **Tasks targeted**: T074–T085 (all 12)
- **Working tree at start**: dirty — Phases 1–4 and 6 present but **uncommitted**
- **No git writes performed.** All changes left unstaged for review.

With this phase, **T001–T104 are all complete**. Phases 7–11 (T105–T151) remain open.

### Tasks completed

| Task | Files | Note |
| --- | --- | --- |
| T074, T075 | `tests/Unit/Service/PurchaseOrderServiceTest.php` | 27 tests. Written first, confirmed red before implementing |
| T076 | `app/Service/PurchaseOrderService.php` | create/submit/cancel, transition guards, `PO-YYYYMMDD-####` numbering |
| T077 | `app/Controller/PurchaseOrderController.php` | Per-action roles: index/create/store/show/submit Admin+Warehouse, **cancel Admin-only** |
| T078 | `views/purchase-orders/{index,detail,form}.php` | Detail shows ordered / received / outstanding per line |
| T079, T080, T081 | `tests/Unit/Service/StockServiceTest.php` | +22 receipt tests (partial, over-receipt refusal, effects) |
| T082 | `tests/Integration/GoodsReceiptTest.php`, `tests/Integration/FailOnSecondAppendLedger.php` | 7 tests incl. forced mid-operation failure. **Not executed — no database** |
| T083 | `app/Service/StockService.php` | `receiveGoods()` — one transaction, two-phase, ledger + stock + PO status |
| T084 | `app/Controller/PurchaseOrderController.php` | `receiveForm()` / `receive()`, CSRF via front-controller guard |
| T085 | `views/purchase-orders/receive.php` | Outstanding shown beside each input; entered values retained on refusal |

### Assumptions and decisions recorded

1. **`StockService` constructor gained `PurchaseOrderRepositoryInterface`** (2nd parameter).
   Receipt needs PO lines and `addReceivedQuantity()`. Four call sites updated:
   `StockServiceTest`, `GoodsIssueTest`, `ConcurrentGoodsIssueTest`,
   `LedgerReconciliationTest`, plus `config/container.php`.

2. **Receipt is keyed by `purchase_order_item.id`, not product id.** The repository contract
   (`addReceivedQuantity(int $itemId, ...)`) already dictated this, and it is the correct key:
   two lines could reference the same product with separate outstanding amounts.
   `planReceipt()` rejects an item id that does not belong to the order being received —
   without that check, another order's line could be used to raise stock here. Covered by
   `refusesAnItemThatBelongsToADifferentOrder`.

3. **Receipt may be partial; issue may not.** A supplier can deliver in stages, so a blank line
   means "not arrived yet" and is skipped rather than treated as zero. A *single* call is still
   all-or-nothing: one line over its outstanding refuses the whole receipt. Spec A-005 bounds
   the quantity, never the staging.

4. **Same two-phase shape as goods issue.** All lines verified before any write, so a refusal
   on the last line cannot leave the first one changed.

5. **Stock rows are locked on receipt too**, in the same ascending `product_id` order as issue.
   Receipt only adds, so a lock is not needed to prevent oversell — it is taken to stop a
   receipt and an issue touching the same rows in opposite orders from deadlocking (R-002).
   The residual race (two concurrent receipts of the same line both passing phase 1) is caught
   by the `received_quantity <= quantity` CHECK constraint, which rolls the transaction back.
   Recorded here rather than silently relied upon.

6. **`statusAfterReceipt()` computes from the in-memory entity plus the planned receipt**, not
   by re-reading the order mid-transaction — a re-read is not guaranteed to observe uncommitted
   writes across every repository implementation.

7. **T099 rewired to the real receipt path.** `LedgerReconciliationTest::recordReceipt()` had
   written `Receipt` ledger rows by hand, because `receiveGoods()` did not exist when Phase 6
   was built. It now drives a real Ordered PO through `StockService::receiveGoods()`, so the
   invariant is tested against application behaviour rather than test-authored data. Its
   docblock note about the Phase 5 gap was removed. This is the one place this phase changed
   Phase 6 work, and it strictly strengthens it.

8. **`PurchaseOrderService` receives no stock repository at all.** Submitting or cancelling a PO
   structurally cannot move stock — the constructor makes it impossible, not merely
   discouraged. `noStockMovesWhenAnOrderIsMerelySubmitted` asserts the intent.

9. **Unit cost snapshotted from the catalog `purchase_price`**, never from the request payload —
   symmetric with the Sales Order selling-price rule.

10. **Integer rupiah, not `bcmath`**, consistent with the Phase 6 decision and `Support\Money`
    (bcmath is absent from the Docker image, which installs only `pdo_mysql`).

11. **`order-lines.js` reused unchanged** for the PO form. Its table markup was deliberately
    made identical to the Sales Order form so one module serves both — no second copy.

12. **`FailOnSecondAppendLedger` split into its own file** to satisfy PSR-1 "one class per
    file", which PHP_CodeSniffer flagged.

### Verification gate

| Check | Result |
| --- | --- |
| `php -l` on every new/changed file | pass |
| PHPStan level 6 | **pass, 0 errors** (1 `nullsafe.neverNull` found and fixed by extracting a local) |
| PHP_CodeSniffer PSR-12 | **pass, 0 errors** (1 PSR-1 one-class-per-file error and 1 long line fixed) |
| Unit suite | **pass — 184 tests, 427 assertions** (was 135; **+49** this phase) |
| `declare(strict_types=1)` on all 9 new files | pass |
| Line endings (LF) across all changed files | pass |
| Output escaping in new views | pass — every dynamic value via `View::e()` or int cast |
| Confirmations on state-changing forms | pass — 4/4 POST forms carry `data-confirm` |
| PO view smoke render, 9 states, `E_ALL` | **pass** — index (populated / empty / filtered), detail (partial / draft), form (blank / errors), receive (fresh / refused) |
| T085 retained-value requirement | **pass** — asserted `value="9"` survives a refused receipt |
| Sales-order views re-rendered (regression) | **pass** — all 9 states still render after the `StockService` signature change |
| Source conformance | pass — no schema or entity change this phase; `purchase_order_item.received_quantity` is the deviation already recorded in `data-model.md` / `plan.md` |
| Integration suite | **NOT RUN — no database reachable this session.** Docker daemon down; Laragon MySQL not running; `config/app.php` resolves to `127.0.0.1:3306`, db `ioms` |
| Visual Pass B (rendered in a browser) | **NOT PERFORMED** — the app cannot boot without a database. Pass A verified statically; HTML correctness via the smoke render above |

### Outstanding

- **Integration tests unverified — now 6 files, 40 tests.** T082 (this phase) plus T092/T093/
  T098/T099/T100 from Phase 6. Run `docker compose up -d` then
  `docker compose exec app composer test:integration`. This still includes the
  oversell-prevention proof and the ledger-reconciliation invariant, both graded.
- Phases 7–11 (T105–T151) open: search/pagination, dashboards and CSV, JSON API, low-stock
  script, then the polish and evidence package.

### Files added this phase

```
app/Service/PurchaseOrderService.php
app/Controller/PurchaseOrderController.php
views/purchase-orders/index.php
views/purchase-orders/detail.php
views/purchase-orders/form.php
views/purchase-orders/receive.php
tests/Unit/Service/PurchaseOrderServiceTest.php
tests/Integration/GoodsReceiptTest.php
tests/Integration/FailOnSecondAppendLedger.php
```

### Files modified this phase

```
app/Service/StockService.php                     receiveGoods() + PO repository dependency
config/container.php                             PurchaseOrderService + PurchaseOrderController wired
tests/Unit/Service/StockServiceTest.php          +22 receipt tests, PO fake wired
tests/Integration/SalesOrderFixtures.php         supplier fixture, orderedPurchaseOrder(), purchaseItemIds()
tests/Integration/LedgerReconciliationTest.php   receipts now go through the real receiveGoods()
tests/Integration/GoodsIssueTest.php             StockService signature
tests/Integration/ConcurrentGoodsIssueTest.php   StockService signature
specs/.../tasks.md                               T074-T085 marked [X]
```

---

## Session — 2026-09-11 · Phase 7 (US5: Find records across large lists)

- **Checkpoint HEAD**: `54fd119` — unchanged
- **Tasks targeted**: T105–T112 (all 8)
- **Working tree**: dirty — Phases 1–7 present but **uncommitted**
- **No git writes performed.**

**T001–T112 are now complete.** Phases 8–11 (T113–T151) remain.

### What was already in place before this phase

Discovery first, per the project's working agreement. Three of the eight tasks were largely
satisfied by earlier phases, and this is recorded so the phase is not credited with work it did
not do:

| Task | Pre-existing | Remaining work done this phase |
| --- | --- | --- |
| T107 | `Paginator` complete since T033, filters already preserved in page links | Tests only — 20 of them. **Not a red-first cycle**; these characterize existing behaviour |
| T108 | Product search (name/SKU), category filter, low-stock filter — all present, all bound parameters | Sort was **dead code** — see finding 1 below |
| T109 | PO/SO order-number + party search, status filter, sort allowlist, both directions — all present | Tests only |
| T112 | Both distinct empty states present in all six index views | Verified only — no change needed |

T112 needs an explicit correction: my first pass grepped the six index files and reported
suppliers/customers as having **zero** empty states. That was wrong. Both delegate to
`views/layout/_party-list.php`, which carries both states. All six views were already compliant.

### Tasks completed

| Task | Files | Note |
| --- | --- | --- |
| T105 | `tests/Unit/Service/ProductSearchTest.php` | 21 tests — partial name/SKU, category, low-stock (incl. boundary), active, and all combinations |
| T106 | `tests/Unit/Service/OrderSearchTest.php`, `tests/Unit/Fake/SortAllowlistProbe.php` | 18 tests — allowlist, 9 injection payloads, direction, plus reflection over the real repositories' `SORTABLE` maps |
| T107 | `tests/Unit/Support/PaginatorTest.php` | 20 tests — page arithmetic, clamping, filter preservation, page window, range labels |
| T108 | `app/Repository/Mysql/MysqlProductRepository.php`, `ProductRepositoryInterface.php`, `tests/Unit/Fake/InMemoryProductRepository.php` | Product sort wired; fake gained matching sort so it cannot pass what production rejects |
| T109 | — | Already implemented; now covered by T106's tests |
| T110 | `app/Controller/{Product,SalesOrder,PurchaseOrder}Controller.php`, `views/layout/_sort-header.php`, 3 index views, `app.css`, `lucide-sprite.svg` | Sort read from query string, validated per-controller, preserved through pagination |
| T111 | `public/assets/js/filters.js`, `public/assets/js/main.js` | Selects auto-submit; sort copied to hidden inputs; `page` dropped; empty fields omitted |
| T112 | — | Already satisfied across all six views (see correction above) |

### Findings and decisions

1. **The product `SORTABLE` allowlist was dead code.** `MysqlProductRepository::search()` called
   `resolveSortColumn(self::SORTABLE, null, 'p.name')` — passing a literal `null`, so the three
   declared sort keys could never be reached and the catalog was permanently name-ASC. Now wired
   to `$criteria['sort']`. This is the substantive implementation finding of the phase.

2. **A regression I introduced and then caught.** Wiring `resolveDirection($criteria['direction']
   ?? null)` flipped the product list to Z→A, because `resolveDirection(null)` returns `DESC`.
   Products sort by name, where A→Z is correct; orders sort by date, where newest-first is
   correct. The default direction therefore differs by repository **deliberately**, and the
   product call now passes `?? 'asc'` with a comment saying why.

3. **Sort defaults differ by controller, matching that.** `ProductController` normalizes an
   unknown direction to `asc`; the two order controllers normalize to `desc`.

4. **Sort is kept out of `countBy()`.** Counting does not care about order, and the interface's
   array shape does not accept sort keys. `index()` builds filter-only criteria for `count()` and
   `$criteria + sortCriteriaFrom()` for `search()`. Passing the sorted array to both would have
   been a PHPStan shape violation.

5. **Sort keys are validated twice, on purpose.** The repository allowlist is the security
   boundary (it is the only place a value reaches SQL unbound). The per-controller `SORT_KEYS`
   const is a separate, non-security check that keeps the query string clean and stops the view's
   active-column indicator lighting up for an unrecognized key.

6. **One shared `_sort-header` partial** rather than link-building logic duplicated across three
   views (SonarQube §4). It guarantees both FR-025 properties in one place: every filter is
   carried, and `page` is dropped because a new ordering changes which rows come first.

7. **Three icons added to the vendored Lucide sprite** (`arrow-up`, `arrow-down`,
   `arrow-up-down`). The sprite had no directional arrows, and reusing `trending-up`/`trending-down`
   for sort direction would have been semantically wrong. Same library, same ISC licence, already
   attributed in `README.md` — no new dependency.

8. **`filters.js` exists for two specific reasons**, both documented in the file: sort lives in the
   query string rather than in the form, so it must be copied into hidden inputs or it vanishes on
   submit; and `page` must be dropped, or filtering while on page 5 of a now-2-page result shows an
   empty page. Text inputs deliberately do **not** auto-submit — reloading on every keystroke
   would be unusable. Progressive enhancement: the toolbar is a working GET form without JS.

### Verification gate

| Check | Result |
| --- | --- |
| `php -l` on every new/changed file | pass |
| PHPStan level 6 | **pass, 0 errors** |
| PHP_CodeSniffer PSR-12 | **pass, 0 errors, 0 warnings** (3 over-length lines I introduced were fixed, not left as warnings) |
| Unit suite | **pass — 243 tests, 617 assertions** (was 184; **+59** this phase) |
| `declare(strict_types=1)` on new files | pass |
| Line endings (LF) | pass |
| Sales-order views re-rendered, incl. new sorted+filtered state | **pass**, 10 states |
| Purchase-order views re-rendered, incl. new sorted+filtered state | **pass**, 10 states |
| `products/index` rendered with sort headers (first time) | **pass** |
| **FR-025 sort links verified in rendered HTML** | **pass** — every sort link carries all three active filters, none carries `page`, the active column's link reverses direction, `aria-sort` marks active/inactive correctly, and pagination links keep the active sort |
| Integration suite | **NOT RUN — no database reachable this session** |
| Visual Pass B (browser) | **NOT PERFORMED** — app cannot boot without a database |

### Outstanding

- **Integration tests still unverified — 6 files, ~40 tests**, unchanged from Phase 5. Run
  `docker compose up -d` then `docker compose exec app composer test:integration`.
- **No sort/filter test against real MySQL.** T105/T106 run against in-memory fakes, so the
  actual `ORDER BY` and `LIKE` SQL has never executed. The fakes mirror the allowlist behaviour
  deliberately, but only a live database proves the generated SQL is valid.
- **NFR-011 seeded-volume check not performed.** US5's Independent Test calls for 30 products and
  25 orders seeded, then paging with filters intact. The seed exists (T013) but has never been
  loaded, so "usable at realistic volume" is unproven.
- Phases 8–11 (T113–T151) open: dashboards + CSV export, JSON API, low-stock script, then polish
  and the evidence package.

### Files added this phase

```
tests/Unit/Service/ProductSearchTest.php
tests/Unit/Service/OrderSearchTest.php
tests/Unit/Support/PaginatorTest.php
tests/Unit/Fake/SortAllowlistProbe.php
views/layout/_sort-header.php
public/assets/js/filters.js
```

### Files modified this phase

```
app/Repository/Mysql/MysqlProductRepository.php   sort wired (was dead code); direction defaults asc
app/Repository/ProductRepositoryInterface.php     criteria shape gains sort/direction
app/Controller/ProductController.php              SORT_KEYS, sortCriteriaFrom(), sort in queryState
app/Controller/SalesOrderController.php           idem
app/Controller/PurchaseOrderController.php        idem
views/products/index.php                          2 sortable headers
views/sales-orders/index.php                      3 sortable headers
views/purchase-orders/index.php                   3 sortable headers
tests/Unit/Fake/InMemoryProductRepository.php     sort() mirroring the production allowlist
public/assets/css/app.css                         .sort-link / .sort-icon
public/assets/icons/lucide-sprite.svg             arrow-up, arrow-down, arrow-up-down
public/assets/js/main.js                          imports filters.js
specs/.../tasks.md                                T105-T112 marked [X]
```

---

## Session 2026-09-14 — Phase 8 (US6: role-scoped dashboards + CSV export)

**HEAD at start**: `54fd119` (working tree carries all prior phases, uncommitted)
**Tasks targeted**: T113–T121
**Checklists**: `requirements.md` 16/16 ✓ PASS

### Tasks completed

| Task | Note |
| --- | --- |
| T113 | `DashboardServiceTest` — 17 tests. Setiap figure dibuktikan BERGERAK saat datanya berubah, bukan sekadar bernilai benar sekali. |
| T114 | `ReportServiceTest` — 27 tests. Rentang 366 hari (batas inklusif: 366 lolos, 367 ditolak), scoping Sales, bentuk baris, CSV formula injection. |
| T115 | `DashboardReportConsistencyTest` — 9 tests terhadap MySQL. **Belum dijalankan** (lihat Unverified). |
| T116 | `DashboardService` — `forRole()` men-dispatch ke tiga kumpulan figure berbeda. Tidak ada angka konstan. |
| T117 | `ReportService` — memanggil method repository yang SAMA dengan dashboard; `statusTotals()` menghitung dari baris yang akan diekspor. |
| T118 | `DashboardController` ditulis ulang: tiga role → tiga view, angka dari service. |
| T119 | `ReportController` — stream `fputcsv` ke `php://output`, rentang dibatasi, Sales ter-scope. |
| T120 | `views/dashboard/{admin,sales,warehouse}.php` + dua partial bersama. |
| T121 | `views/reports/index.php` — jumlah record, dua export, empty state per report. |

### Keputusan yang diambil (asumsi untuk direview)

1. **Rentang default 30 hari** saat `/reports` dibuka tanpa parameter. Spec tidak
   menentukannya; halaman harus langsung terpakai, bukan menampilkan error validasi.
2. **Rentang tidak sah pada endpoint CSV di-redirect kembali ke `/reports`** dengan nilai
   yang tadi diisi, bukan dibalas halaman error 400 — user perlu melihat pesannya di
   samping input yang salah (FR-029).
3. **`views/dashboard/index.php` dihapus.** Shell placeholder Phase 2 digantikan tiga view
   per role; tidak ada lagi yang mereferensikannya.
4. **Fake in-memory dinaikkan ke bentuk baris produksi.** `ordersBetween()` dan
   `movementsBetween()` pada fake sebelumnya mengembalikan kolom yang lebih sedikit
   daripada query MySQL-nya, sehingga unit test bisa lulus untuk bentuk data yang
   produksinya tidak pernah hasilkan. Keduanya kini mengembalikan nama kolom yang sama
   persis, dan fake ledger menerima timestamp lewat `recordAt()` agar pemfilteran rentang
   dapat diuji deterministik.

### Temuan security yang diperbaiki dalam pass ini

- **CSV formula injection (CWE-1236) — MAJOR, fixed.** Nama product, customer dan user
  ikut tertulis ke file export. Sel yang diawali `=`, `+`, `-`, `@`, tab atau CR akan
  dieksekusi sebagai rumus saat file dibuka di spreadsheet. `ReportService::cell()` kini
  mengawali sel semacam itu dengan kutip tunggal. Angka dikecualikan secara eksplisit:
  quantity Issue bernilai negatif dan nilai uang harus tetap dapat dijumlahkan kembali —
  ada unit test untuk kedua sisinya.
- Tidak ada dependency baru pada phase ini.

### Verification gate

| Gate | Hasil |
| --- | --- |
| `phpunit --testsuite Unit` | **OK — 287 tests, 734 assertions** |
| `phpstan analyse` (level 6) | **OK — no errors** |
| `phpcs` (PSR-12 + strict_types) | **OK — 127 files, 0 error** |
| Visual Pass A (element checklist) | **Lulus** — stat row dengan chip + angka + delta di keempat halaman; empty state ikon + judul + teks + CTA; ikon konsisten dengan halaman sebelumnya; satu anchor gradient per halaman; seluruh warna dari token, tidak ada hex baru. |
| Visual Pass B (render di browser) | **TIDAK DIVERIFIKASI** — lihat di bawah. |

**Render check (pengganti parsial Pass B).** Docker daemon mati dan MySQL tidak berjalan,
sehingga aplikasi tidak dapat disajikan. Sebagai gantinya seluruh template dieksekusi
langsung lewat `View` dengan data stub, tujuh keadaan halaman, semuanya menghasilkan HTML
tanpa error: dashboard admin/sales/warehouse (terisi), sales & warehouse (kosong), reports
(terisi, Admin) dan reports (kosong + error validasi, Sales). Tidak ada `<?php` yang bocor
ke output, escaping lewat `View::e()` terpakai di seluruh nilai dinamis.

**Yang tetap belum diverifikasi**: tampilan sesungguhnya di browser — spacing, keselarasan
grid, harmoni warna, dan perilaku responsive pada viewport sempit. Ini tidak dapat
dijalankan pada sesi ini (tidak ada browser tooling; MCP Playwright gagal terhubung) dan
**tidak boleh dianggap lulus**.

### Unverified (dibawa dari phase sebelumnya, bertambah)

- **Integration test belum pernah dijalankan — kini 7 file.** `DashboardReportConsistencyTest`
  menyusul enam file sebelumnya. Jalankan `docker compose up -d` lalu
  `docker compose exec app composer test:integration`.
- Query `ordersBetween()` dan `movementsBetween()` pada MySQL sudah ada sejak Foundational
  tetapi **SQL-nya belum pernah dieksekusi**. Unit test berjalan terhadap fake yang kini
  mencerminkan bentuk barisnya; hanya database sungguhan yang membuktikan SQL-nya sah.

### Files added this phase

```
app/Service/DashboardService.php
app/Service/ReportService.php
app/Controller/ReportController.php
views/dashboard/admin.php
views/dashboard/sales.php
views/dashboard/warehouse.php
views/dashboard/_status-tally.php
views/dashboard/_low-stock.php
views/reports/index.php
tests/Unit/Service/DashboardServiceTest.php
tests/Unit/Service/ReportServiceTest.php
tests/Integration/DashboardReportConsistencyTest.php
```

### Files modified this phase

```
app/Controller/DashboardController.php        ditulis ulang: dispatch tiga view, figure dari service
config/container.php                          DashboardService, ReportService, ReportController
public/assets/css/app.css                     .stat--feature, .icon--sm, .tally*
tests/Unit/Fake/InMemorySalesOrderRepository.php    ordersBetween() bentuk baris produksi + name maps
tests/Unit/Fake/InMemoryStockLedgerRepository.php   movementsBetween() bentuk baris produksi + recordAt()
specs/.../tasks.md                            T113-T121 ditandai [X]
```

### Files removed this phase

```
views/dashboard/index.php                     shell placeholder Phase 2, digantikan tiga view per role
```

---

## Session 2026-09-14 — Phase 9 (US7: JSON API)

**HEAD at start**: `54fd119` (working tree carries all prior phases, uncommitted)
**Tasks targeted**: T122–T127
**Checklists**: `requirements.md` 16/16 ✓ PASS

### Tasks completed

| Task | Note |
| --- | --- |
| T122 | `StockApiTest` — tiga keadaan (200 / 401 JSON / 404) plus `Content-Type` pada setiap respons. **Belum dijalankan** (lihat Unverified). |
| T123 | `StockApiTest` — Sales ditolak dari `/api/dashboard/low-stock`, Admin dan Warehouse Staff diterima; route table diperiksa pada datanya, bukan hanya perilakunya. |
| T124 | `StockApiController` — `availability(sku)` dan `available(productId, warehouseId)`, bentuk persis openapi.yaml. |
| T125 | `DashboardApiController` — `lowStock()` dengan `limit`, default 20, dijepit pada 100. |
| T126 | Envelope JSON sudah ada sejak Phase 2; celah prefix telanjang `/api` ditutup. |
| T127 | `stock-lookup.js` + kolom Available pada form Sales Order. |

Tambahan di luar daftar task, karena keduanya dibutuhkan task-nya sendiri:

- `ProductService::stockBreakdownBySku()` dan `ProductService::availableQuantity()` —
  controller tidak boleh menyentuh repository langsung (ARCH-01).
- `Response::header()` — accessor kecil agar contract "selalu JSON" benar-benar dapat
  diuji, termasuk pada respons error.

### Keputusan yang diambil (asumsi untuk direview)

1. **`count` pada low-stock adalah TOTAL, bukan jumlah baris yang terkirim.** Contract tidak
   menegaskannya. Field `count` hanya berguna bila dapat berbeda dari panjang array-nya;
   kalau disamakan, memperpendek daftar akan mengecilkan angka di dashboard. Ada unit test
   yang mengunci perilaku ini.
2. **`limit` di luar rentang dijepit, bukan ditolak.** Parameter opsional tanpa keputusan
   bisnis di belakangnya; memaksa pemanggil menebak batas tidak membuat siapa pun lebih aman,
   sedangkan limit tak terbatas membuat endpoint murah ini mudah dijadikan mahal.
3. **SKU di luar bentuk yang didokumentasikan dijawab 404, bukan 400.** Bagi pemanggil tidak
   ada gunanya membedakan "SKU tidak berbentuk benar" dari "SKU tidak ada", dan menyamakan
   keduanya tidak membocorkan apa pun.
4. **Pasangan product/warehouse yang sah tanpa baris stock bernilai 0, bukan 404.** Product
   dan warehouse-nya ada; stocknya saja yang kosong. Warehouse yang memang tidak ada tetap 404.
5. **Controller API tidak menerima `Session`.** Authentication dan authorization selesai di
   route table dan guard sebelum request sampai ke controller, sehingga bentuk responsnya
   dapat di-unit-test penuh tanpa session.

### Deviasi tercatat — `/api/dashboard/low-stock` tanpa consumer frontend

`contracts/openapi.yaml` menyebut endpoint ini "dikonsumsi oleh dashboard Admin dan Warehouse
Staff", tetapi Phase 8 merender low stock di server dan tidak ada task — di Phase 9 maupun
Phase 11 — yang memasang JS ke endpoint tersebut.

**Keputusan (dikonfirmasi user, 2026-09-14): dibiarkan tanpa consumer, dicatat sebagai
deviasi yang disengaja.** Menambah JS untuk mengambil ulang data yang sudah dirender PHP
berarti menduplikasi logika render ke dalam dua bahasa tanpa requirement yang menuntutnya —
persis yang dinilai negatif oleh spec C-003. Endpoint-nya tetap diimplementasikan, sesuai
contract, dan teruji. FR-028 sendiri dipenuhi oleh endpoint `availability` yang benar-benar
dipanggil form Sales Order.

Hal yang sama berlaku untuk `/api/products/{sku}/availability`: contract tidak menyebut
consumer JS untuk endpoint ini — ia adalah endpoint demonstrasi yang diwajibkan API-01.

### Verification gate

| Gate | Hasil |
| --- | --- |
| `phpunit --testsuite Unit` | **OK — 324 tests, 855 assertions** (37 baru) |
| `phpstan analyse` (level 6) | **OK — no errors** |
| `phpcs` (PSR-12 + strict_types) | **OK — 133 files, 0 error** |
| `node --check` pada JS baru | **OK** |
| Contract field-parity | **Lulus** — lihat di bawah. |
| Security conformance | **Lulus** — lihat di bawah. |
| Operation coverage BE→FE | **1 deviasi tercatat** — lihat bagian di atas. |
| Visual Pass B (render di browser) | **TIDAK DIVERIFIKASI** — lihat di bawah. |

**Field-parity terhadap `contracts/openapi.yaml`**, diperiksa field demi field dan dikunci
unit test — tidak ada field yang hilang, tidak ada pula field tambahan:

| Schema | Field | Status |
| --- | --- | --- |
| `ProductAvailability` | sku, productName, unit, reorderPoint, totalQuantity, lowStock, warehouses | 7/7 |
| `WarehouseStock` | warehouseId, warehouseName, quantity | 3/3 |
| `AvailableQuantity` | productId, warehouseId, availableQuantity | 3/3 |
| `LowStockSummary` | count, products | 2/2 |
| `LowStockProduct` | productId, sku, productName, totalQuantity, reorderPoint | 5/5 |
| `Error` | error.code, error.message (6 kode enum) | 2/2 |

Tipe ikut diuji terpisah: PDO mengembalikan kolom numerik sebagai string, dan meneruskannya
apa adanya akan mematahkan consumer JSON-nya. Ada test yang menuntut `int` dan `bool`
sungguhan pada setiap field numerik dan boolean.

**Security conformance (§2–§12).**

- Access control: `/api/dashboard/low-stock` dibatasi di route table (Admin + Warehouse
  Staff); ada test yang memeriksa route table-nya, bukan hanya perilaku guard-nya.
- Injection: SKU dan id selalu bound parameter; SKU divalidasi terhadap pola contract
  sebelum dipakai.
- Error disclosure: envelope generic, ada test eksplisit bahwa body tidak pernah memuat
  `Exception`, jejak stack, maupun nama file.
- CSRF: seluruh endpoint bersifat GET, sesuai catatan contract. Tidak ada endpoint JSON
  non-GET yang ditambahkan.
- Tidak ada dependency baru pada phase ini.

**Celah yang ditutup pada pass ini (MINOR):** `Request::expectsJson()` sebelumnya memakai
`str_starts_with($path, '/api/')`, sehingga prefix telanjang `/api` — dan `/api/` yang
dinormalisasi menjadi `/api` — akan dibalas halaman error HTML, tepat perilaku yang dilarang
contract untuk surface API. Pemeriksaannya kini berhenti pada batas segmen, dengan test yang
memastikan `/apixyz` dan `/api-docs` TIDAK ikut terhitung sebagai path API.

**Render check.** Docker daemon mati dan MySQL tidak berjalan, jadi aplikasi tetap tidak dapat
disajikan. Form Sales Order yang berubah (kolom Available) belum pernah dilihat di browser,
dan `stock-lookup.js` belum pernah benar-benar dijalankan terhadap endpoint-nya — hanya
diperiksa sintaksisnya dengan `node --check`. **Ini tidak boleh dianggap lulus.**

### Unverified (bertambah dari phase sebelumnya)

- **Integration test belum pernah dijalankan — kini 8 file.** `StockApiTest` menyusul tujuh
  sebelumnya. Jalankan `docker compose up -d` lalu
  `docker compose exec app composer test:integration`.
- **`stock-lookup.js` belum pernah dieksekusi di browser.** Alur fetch, pembatalan request
  yang saling menyusul, dan perilaku debounce-nya belum terbukti secara empiris.

### Files added this phase

```
app/Controller/Api/StockApiController.php
app/Controller/Api/DashboardApiController.php
public/assets/js/stock-lookup.js
tests/Unit/Controller/StockApiControllerTest.php
tests/Unit/Controller/DashboardApiControllerTest.php
tests/Unit/Support/ApiErrorEnvelopeTest.php
tests/Integration/StockApiTest.php
```

### Files modified this phase

```
app/Service/ProductService.php        stockBreakdownBySku(), availableQuantity()
app/Support/Request.php               expectsJson() berhenti pada batas segmen; const API_PREFIX
app/Support/Response.php              header() accessor
config/container.php                  dua controller API terdaftar
views/sales-orders/form.php           kolom Available per line
public/assets/js/main.js              import + init stock-lookup
public/assets/js/order-lines.js       baris hasil clone mereset stock hint
public/assets/css/app.css             .stock-hint / --ok / --low
specs/.../tasks.md                    T122-T127 ditandai [X]
```

---

## Session 2026-09-14 — Phase 10 (US8: low-stock check di luar request cycle)

**HEAD at start**: `54fd119` (working tree carries all prior phases, uncommitted)
**Tasks targeted**: T128–T130
**Checklists**: `requirements.md` 16/16 ✓ PASS

**Docker naik di tengah sesi ini.** Container `ioms_app` (:8080) dan `ioms_db` (:3307)
berjalan, sehingga untuk PERTAMA KALINYA integration test dan aplikasi sungguhan dapat
dijalankan. Sebagian besar catatan di bawah adalah hasil verifikasi itu.

### Tasks completed

| Task | Note |
| --- | --- |
| T128 | 7 unit test baru pada `ProductServiceTest` — kedua sisi batas reorder point, stock nol, product nonaktif, limit, dan katalog sehat. |
| T129 | `scripts/check-low-stock.php` — CLI mandiri, mem-boot `config/container.php` yang sama, memakai `ProductService` yang sama dengan dashboard. |
| T130 | `docs/testing/low-stock-job.md` (prosedur verifikasi) dan `README.md`. |

`README.md` sengaja ditulis secukupnya: **T146 pada Phase 11 yang memilikinya** dan akan
melengkapinya. Yang wajib ada sekarang menurut T130 — invocation JOB-01 — sudah ada.

### Keputusan yang diambil (asumsi untuk direview)

1. **Exit code 0 walaupun ada product menipis.** Menemukan stock menipis adalah HASIL, bukan
   kegagalan. Exit non-zero akan membuat setiap pemanggil otomatis membaca laporan yang sehat
   sebagai error. Exit 1 disediakan khusus untuk gagal dijalankan.
2. **Default `--limit=100`, nilai tidak masuk akal kembali ke default.** `ProductService::lowStock()`
   menuntut limit; ini laporan, bukan perintah berbahaya, sehingga argumen buruk tidak
   dijadikan error.
3. **Jumlah total dihitung terpisah dari daftarnya.** Daftar yang dipotong `--limit` tidak
   pernah mengecilkan angka yang dilaporkan — konsisten dengan prinsip yang sama pada
   dashboard (Phase 8) dan endpoint low-stock (Phase 9).

### Verification gate

| Gate | Hasil |
| --- | --- |
| `composer test:unit` (di Docker) | **OK — 330 tests, 863 assertions** (6 baru) |
| `composer analyse` (PHPStan 6) | **OK — no errors** |
| `composer cs` (PSR-12) | **OK — 134 files, 0 error** |
| Script dijalankan sungguhan | **OK** — lihat di bawah |
| Aplikasi diverifikasi lewat HTTP | **OK** — lihat di bawah |
| `composer test:integration` | **13 gagal**, seluruhnya di file Phase 4/5 — lihat di bawah |

### Verifikasi sungguhan (baru mungkin dilakukan sesi ini)

**Kesepakatan script ↔ dashboard ↔ API — inti R-012, kini terbukti empiris.**
Terhadap data seed yang sama, tiga permukaan yang berbeda melaporkan angka yang sama persis:

| Permukaan | Angka |
| --- | --- |
| `php scripts/check-low-stock.php` | `9 products need restocking.` |
| Dashboard Admin — "Below reorder point" | `9` |
| Dashboard Warehouse Staff — "Low stock products" | `9` |
| `GET /api/dashboard/low-stock` | `{"count":9,...}` |

**Batas inklusif A-008 terbukti pada data nyata.** `SKU-000018` memiliki total 4 dengan
reorder point 4, dan muncul sebagai low stock di script maupun di
`GET /api/products/SKU-000018/availability` (`"lowStock":true`).

**Daftar terpotong tidak mengecilkan angka.** `--limit=2` menampilkan 2 baris namun tetap
melaporkan `9 products need restocking.` Dashboard menampilkan 5 baris teratas dengan angka
tetap 9.

**Phase 8 dan Phase 9 ikut terverifikasi terhadap aplikasi yang berjalan:**

- Tiga dashboard per role mengembalikan 200 dengan angka yang berbeda-beda — Admin
  (nilai inventori `Rp 636.015.000`, below reorder 9, pending approval 3), Sales (hanya
  ordernya sendiri: 2 draft, 1 pending, 4 fulfilled), Warehouse (receipt 4, issue 2,
  low stock 9).
- Halaman `/reports` mengembalikan 200 dengan 14 order dan 12 stock movement yang cocok.
- Export CSV menghasilkan header yang benar, approver kosong tertulis sebagai sel kosong
  (`,,`) bukan kata "null", dan nilai uang sebagai angka polos (`10550000`) — persis yang
  dikunci unit test.
- Endpoint JSON: 200 dengan tipe integer/boolean sungguhan; **401 JSON tanpa cookie**, bukan
  halaman login HTML; 404 JSON untuk SKU tidak dikenal; **403 JSON untuk Sales** pada
  low-stock. Perbaikan Phase 9 atas prefix telanjang terbukti: `/api/` menghasilkan 404 JSON.
- Kolom Available pada form Sales Order terender, dan `stock-lookup.js` disajikan sebagai
  `text/javascript`.

**Pass B (penilaian visual di browser) tetap TIDAK DIVERIFIKASI.** Halaman diambil lewat HTTP
dan isinya diperiksa, tetapi tidak ada tooling browser pada sesi ini (MCP Playwright gagal
terhubung), sehingga spacing, keselarasan grid, harmoni warna dan perilaku responsive belum
pernah benar-benar dilihat. Mengambil HTML bukan melihatnya terender.

### Integration suite — dijalankan untuk pertama kalinya

Schema database test belum pernah dibangun; `composer db:test` menyelesaikannya.

Hasil pertama: **67 error dari 74 test**. Dua defect nyata pada fixture bersama ditemukan dan
diperbaiki, keduanya pre-existing sejak Phase 4/5 dan tidak pernah terlihat karena suite-nya
memang belum pernah dijalankan:

1. **`SalesOrderFixtures::setStock()` — placeholder ganda.** `:quantity` dipakai dua kali
   (di `VALUES` dan di `ON DUPLICATE KEY UPDATE`). Dengan `ATTR_EMULATE_PREPARES = false`
   PDO meneruskan statement apa adanya ke MySQL, dan satu nama placeholder hanya boleh muncul
   sekali → `SQLSTATE[HY093] Invalid parameter number`. Diperbaiki dengan `:new_quantity`.
2. **`SalesOrderFixtures::setStock()` — `updated_at` tidak diisi.** Kolomnya `NOT NULL` tanpa
   default; production code (`MysqlProductStockRepository::adjust()`) mengisinya dengan
   `NOW()`, fixture-nya tidak → `SQLSTATE[HY000] 1364`. Diperbaiki.

Setelah kedua perbaikan itu: **67 → 13 kegagalan.** Seluruh test Phase 8 dan Phase 9 —
`DashboardReportConsistencyTest` (9) dan `StockApiTest` (11) — **lulus**, demikian pula
`ApprovalAuthorizationTest`, `AuthFlowTest`, `UserAccessTest` dan `HarnessSmokeTest`.

### UNRESOLVED — 13 kegagalan integration di file Phase 4/5

Tidak ada yang menyentuh kode Phase 8/9/10. Dibiarkan **belum diperbaiki** dan diserahkan
sebagai keputusan scope; T145 pada Phase 11 memang memiliki "confirm both suites pass".

```
ConcurrentGoodsIssueTest::noUpdateIsLostWhenBothConnectionsIssueDifferentUnits
ConcurrentGoodsIssueTest::theLedgerStillReconcilesAfterTheContention
ConcurrentGoodsIssueTest::twoConcurrentIssuesForOneUnitNeverOversell
GoodsIssueTest::anApprovedOrderIsIssuedAndStockFalls
GoodsIssueTest::oneIssueLedgerRowIsWrittenPerLine
GoodsIssueTest::stockAndLedgerAgreeAfterTheIssue
GoodsIssueTest::theSameOrderCannotBeIssuedTwice
GoodsReceiptTest::aFailureMidOperationLeavesNEITHERStockNorLedgerChanged
GoodsReceiptTest::theOrderCanStillBeReceivedNormallyAfterAFailedAttempt
LedgerReconciliationTest::ledgerAndStockAgreeAfterAMixedSequenceOfReceiptsAndIssues
LedgerReconciliationTest::ledgerAndStockStillAgreeWhenSomeIssuesWereRefused
LedgerReconciliationTest::noLedgerRowIsEverUpdatedOrDeleted
LedgerReconciliationTest::theInvariantHoldsAcrossTwoWarehousesIndependently
```

**Diagnosis (sebagian terbukti, sebagian belum).**

`Database::transaction()` memperlakukan panggilan bersarang sebagai passthrough — hanya
transaction terluar yang commit. `IntegrationTestCase` membungkus SETIAP test dalam
transaction lalu me-rollback-nya saat teardown. Akibatnya, di dalam integration test,
transaction milik `StockService` **tidak pernah benar-benar ada**: tidak ada commit, dan yang
lebih penting **tidak ada rollback**. Itu menjelaskan
`aFailureMidOperationLeavesNEITHERStockNorLedgerChanged` (stock 15, diharapkan 10 — penulisan
parsial tidak ter-rollback) dan pelanggaran `ck_product_stock_quantity` berikutnya, yang
beroperasi di atas state yang sudah telanjur rusak.

`IntegrationTestCase` sendiri sudah mengantisipasi hal ini: "Test yang perlu menguji perilaku
transaction itu sendiri … harus mematikan pembungkus ini dengan meng-override
`wrapsInTransaction()`." `ConcurrentGoodsIssueTest` melakukannya; `GoodsReceiptTest`,
`GoodsIssueTest` dan `LedgerReconciliationTest` **tidak**, padahal ketiganya menguji persis
perilaku itu.

**Yang belum terjelaskan**: `ConcurrentGoodsIssueTest` SUDAH mematikan pembungkusnya namun 3
test-nya tetap gagal. Penyebabnya belum diselidiki dan **tidak boleh ditebak** — area ini
(ARCH-02, pencegahan oversell) adalah yang paling kritikal di seluruh project.

**Catatan penting**: diagnosis di atas menunjuk pada harness test, BUKAN pada bukti bahwa
production code-nya cacat — di produksi tidak ada transaction terluar, sehingga
`Database::transaction()` benar-benar begin/commit/rollback. Namun hal itu **belum
dibuktikan**, dan sampai suite-nya hijau, pencegahan oversell ARCH-02 berstatus
**belum terverifikasi**.

### Files added this phase

```
scripts/check-low-stock.php
docs/testing/low-stock-job.md
README.md
```

### Files modified this phase

```
tests/Unit/Service/ProductServiceTest.php   7 test low-stock untuk JOB-01
tests/Integration/SalesOrderFixtures.php    setStock(): placeholder ganda + updated_at
specs/.../tasks.md                          T128-T130 ditandai [X]
```

---

## Session 2026-09-14 — Perbaikan agar ARCH-02 dapat diverifikasi

**Permintaan**: "perbaiki dulu, agar ARCH-02 bisa di verifikasi"
**Titik awal**: 13 integration test gagal (lihat catatan Phase 10)
**Hasil**: **412 test hijau** (unit + integration), ARCH-02 terbukti.

### BUG PRODUCTION — goods issue tidak pernah bisa berhasil

Diagnosis awal pada Phase 10 menduga seluruh kegagalan berasal dari harness test.
**Dugaan itu salah.** Penyebab utama — 10 dari 13 kegagalan — adalah cacat pada production
code, dan dampaknya berat.

`MysqlProductStockRepository::adjust()` memakai satu statement:

```sql
INSERT INTO product_stock (product_id, warehouse_id, quantity, updated_at)
     VALUES (:product_id, :warehouse_id, :delta, NOW())
ON DUPLICATE KEY UPDATE quantity = quantity + VALUES(quantity), updated_at = NOW()
```

MySQL memeriksa CHECK `quantity >= 0` terhadap **baris kandidat INSERT** lebih dulu, SEBELUM
jatuh ke cabang `ON DUPLICATE KEY UPDATE`. Goods issue mengirim delta negatif, sehingga
constraint menolaknya **walaupun nilai akhirnya tidak pernah negatif**.

Konsekuensinya: **setiap goods issue gagal dengan PDOException** — di test maupun di aplikasi
sungguhan. Fitur inti FR-020 tidak berfungsi sama sekali.

Dibuktikan terisolasi dengan SQL langsung sebelum satu baris kode pun diubah: baris ada
dengan quantity 10, `adjust(..., -4)` → `ERROR 3819 ck_product_stock_quantity is violated`.

**Perbaikan** — dua langkah, keduanya tetap di dalam transaction milik StockService:

```sql
INSERT INTO product_stock (...) VALUES (:product_id, :warehouse_id, 0, NOW())
ON DUPLICATE KEY UPDATE id = id;                    -- pastikan baris ada, kandidat 0 lolos CHECK

UPDATE product_stock SET quantity = quantity + :delta, updated_at = NOW()
 WHERE product_id = :product_id AND warehouse_id = :warehouse_id;
```

`ON DUPLICATE KEY UPDATE id = id` dipilih ketimbang `INSERT IGNORE`: IGNORE menurunkan
pelanggaran foreign key menjadi warning, sehingga product atau warehouse yang tidak sah akan
lolos diam-diam.

Susunan ini **mengembalikan CHECK ke peran yang dimaksudkan**: jaring pengaman terakhir atas
HASIL perubahan, bukan penolak setiap delta negatif. Issue yang melebihi stock tetap ditolak
database — diverifikasi terpisah.

### Dua cacat test yang tersisa

**`GoodsIssueTest::stockAndLedgerAgreeAfterTheIssue`** — menyiapkan stock lewat `setStock()`
yang menulis langsung ke `product_stock` tanpa baris ledger, lalu menuntut
`SUM(stock_ledger) = product_stock`. Invariant itu mustahil terpenuhi sejak awal. Docblock
`setStock()` sendiri sudah menyatakan syaratnya. Stock awal kini dibentuk lewat **goods
receipt yang sesungguhnya**, sehingga ledger dan stock berangkat konsisten.

**`GoodsReceiptTest`** (2 test) — menguji ROLLBACK, tetapi `Database::transaction()`
memperlakukan panggilan bersarang sebagai passthrough dan `IntegrationTestCase` membungkus
setiap test dalam transaction. Akibatnya transaction Service tidak pernah terbentuk dan
rollback-nya tidak pernah terjadi, sehingga penulisan parsial justru bertahan (stock 15,
seharusnya kembali 10). `wrapsInTransaction()` kini dimatikan pada class itu, sama seperti
`ConcurrentGoodsIssueTest`.

Helper pembersihan fixture dipindahkan dari milik pribadi `ConcurrentGoodsIssueTest` ke trait
`SalesOrderFixtures` — tempatnya memang di sana, karena ia membersihkan persis apa yang
di-seed trait itu. Sekalian dilengkapi: versi lamanya **tidak menghapus** `purchase_order`,
`purchase_order_item`, dan `supplier`, yang justru dibuat `GoodsReceiptTest`.

### Regression test

`tests/Integration/StockAdjustmentTest.php` — 8 test yang menguji `adjust()` LANGSUNG,
terpisah dari StockService, agar kegagalan serupa di kemudian hari menunjuk tepat ke lapisan
yang salah.

Test ini **dibuktikan benar-benar menangkap bug-nya**: SQL lama dikembalikan sementara →
4 error; perbaikan dipasang lagi → 8/8 lulus. Regression test yang tidak gagal pada kode lama
tidak ada gunanya.

### ARCH-02 — kini terverifikasi

```
Concurrent Goods Issue
 ✔ The second connection blocks while the first holds the lock
 ✔ Two concurrent issues for one unit never oversell
 ✔ No update is lost when both connections issue different units
 ✔ The ledger still reconciles after the contention

Ledger Reconciliation
 ✔ Ledger and stock agree after a mixed sequence of receipts and issues
 ✔ Ledger and stock still agree when some issues were refused
 ✔ A refused multi line issue leaves no partial ledger row
 ✔ The invariant holds across two warehouses independently
 ✔ No ledger row is ever updated or deleted
```

Oversell tidak dapat direproduksi, lost update tidak terjadi, dan invariant
`SUM(stock_ledger.quantity) = product_stock.quantity` bertahan — termasuk setelah issue yang
ditolak.

### Verifikasi end-to-end di aplikasi yang berjalan

Goods issue sungguhan lewat HTTP sebagai Warehouse Staff pada `SO-2026-0008`:

| | Sebelum | Sesudah |
| --- | --- | --- |
| Product 3 on hand | 20 | **16** (issue 4) |
| Product 4 on hand | 18 | **15** (issue 3) |
| Baris ledger untuk order | 0 | **2**, keduanya negatif |
| Status order | Approved | **Fulfilled** |

Sebelum perbaikan, request ini berakhir sebagai PDOException dan halaman error 500.

> **Catatan**: verifikasi ini MENGUBAH data pada database development (`ioms`) —
> `SO-2026-0008` kini Fulfilled dan stock dua product berkurang. Kembalikan dengan
> `docker compose exec app composer db:reset` bila data seed awal dibutuhkan utuh.

### Verification gate

| Gate | Hasil |
| --- | --- |
| `composer test` (unit + integration) | **OK — 412 tests, 1181 assertions** |
| Integration dijalankan dua kali berturut-turut | **OK** — repeatable, tidak ada sisa fixture |
| `composer analyse` (PHPStan 6) | **OK — no errors** |
| `composer cs` (PSR-12) | **OK — 0 error** |
| Goods issue lewat HTTP | **OK** |

### Files modified

```
app/Repository/Mysql/MysqlProductStockRepository.php  adjust(): perbaikan bug production
tests/Integration/SalesOrderFixtures.php              cleanUpSalesOrderFixtures() dipindah + dilengkapi
tests/Integration/ConcurrentGoodsIssueTest.php        memakai helper trait, bukan salinan pribadi
tests/Integration/GoodsReceiptTest.php                wrapsInTransaction() dimatikan + cleanup manual
tests/Integration/GoodsIssueTest.php                  invariant test menyiapkan stock lewat ledger
```

### Files added

```
tests/Integration/StockAdjustmentTest.php             8 regression test untuk adjust()
```

### Catatan untuk Phase 11

- **T145** ("confirm both suites pass in full") kini sudah terpenuhi lebih awal — 412 test
  hijau. Tetap perlu dicatat hasilnya ke `docs/testing/test-results.md`.
- **T137** (`docs/quality/refactor-log.md`) memiliki bahan nyata dari sesi ini: satu bug
  production berat, sebab teknisnya, dan perbaikan dua langkahnya.
- **T139** (`docs/quality/tech-debt.md`): `Database::transaction()` yang mem-passthrough
  panggilan bersarang membuat rollback tidak dapat diuji tanpa mematikan pembungkus
  IntegrationTestCase. Berfungsi, tetapi mudah menjebak penulis test berikutnya.

---

## Session 2026-09-14 — Phase 11 (Polish, Evidence & Cross-Cutting Concerns)

**HEAD at start**: `54fd119` · **Tasks targeted**: T131–T151
**Checklists**: `requirements.md` 16/16 ✓ PASS

### Koreksi ledger task lebih dulu

Pola `sed` pada Phase 10 (`T1[23][089]`) secara tidak sengaja ikut mencocokkan **T138 dan
T139**, sehingga keduanya tertandai selesai padahal belum dikerjakan. Dikembalikan menjadi
`[ ]` di awal phase ini; `docs/quality/` yang masih kosong menjadi buktinya.

### Selesai — 17 task

| Task | Hasil |
| --- | --- |
| T131 | `public/assets/js/validation.js` — aturan dibaca dari atribut HTML yang sudah dirender server, bukan dari daftar aturan kedua di JS |
| T132 | `docs/testing/failure-paths.md` — 13 Edge Case spec ditelusuri ke kode dan test-nya |
| T134 | `docs/architecture/class-diagram-as-built.md` — membedakan dependency pada interface (`..>`) dari class konkret (`-->`) |
| T135 | `docs/architecture/adr-001-repository-abstraction.md` |
| T136 | `docs/architecture/adr-002-concurrency-control.md` |
| T137 | `docs/quality/refactor-log.md` — 4 entri + catatan audit SRP |
| T139 | `docs/quality/tech-debt.md` — 7 item |
| T140 | `docs/quality/critique.md` |
| T141 | `docs/quality/phpstan-report.txt` — level 6, **no errors** |
| T142 | `docs/quality/phpcs-report.txt` — PSR-12, **0 error** |
| T143 | 179 file PHP, **nol** tanpa `declare(strict_types=1)`; 6 `mixed` diberi komentar pembenar |
| T144 | `docs/testing/use-case-coverage.md` + **33 unit test baru** |
| T145 | `docs/testing/test-results.md` — 445 test, 1266 assertion |
| T146 | `README.md` diperluas — fitur, kebutuhan, akun demo, perintah per suite, atribusi Lucide, keterbatasan |
| T147 | `ai-usage-log.md` |
| T148 | `docs/planning/user-stories-and-scope.md` — story, scope, ERD, backlog |
| T151 | `docs/quality/secret-scan.md` |

### T144 menemukan kekurangan yang nyata

Audit bukan sekadar mencatat keadaan:

- **`MasterDataService` tidak punya file test sama sekali** — 11 method public, termasuk
  aturan validasi dan keunikan nama Category. Ditambahkan 21 test.
- **`AuthService::verifyPasswordFor` tidak teruji**, padahal ia adalah step-up re-auth sebelum
  aksi sensitif (security standard §7). Ditambahkan 4 test.
- **Sisi Customer pada `PartyService` tertinggal dari sisi Supplier** — `updateCustomer`,
  `countCustomers`, `activeCustomers`, `countSuppliers`. Ditambahkan 3 test.
- `ProductService::stockBreakdownBySku` dan `availableQuantity` hanya teruji tidak langsung
  lewat controller API. Ditambahkan 5 test.

### Perbaikan lain dalam pass ini

- **T143**: enam `mixed` pada signature tidak punya komentar pembenar seperti dituntut
  constitution Principle II. Ditambahkan pada `MasterDataService::optionalText`,
  `Session::put`/`get`, `Validator::raw`, `View::share`, `ReportService::cell`.
- **T133**: tiga aturan responsive ditambahkan ke blok `max-width: 640px` —
  `.toolbar .field`, `.toolbar .btn`, dan `.tally-item` melebar penuh, karena tanpa itu lebar
  intrinsiknya dapat mendorong halaman melewati 360px.
- **Dua finding PHPStan diperbaiki, bukan ditekan**: `nullsafe.neverNull` pada
  `ProductService::availableQuantity()`, dan `function.impossibleType` pada test yang memakai
  `method_exists()` — diganti reflection yang justru lebih bermakna (menangkap `delete*`
  apa pun yang kelak ditambahkan, bukan dua nama tertentu).

### Verification gate

| Gate | Hasil |
| --- | --- |
| `composer test` | **OK — 445 test, 1266 assertion** |
| `composer analyse` (PHPStan 6) | **OK — no errors** |
| `composer cs` (PSR-12) | **OK — 0 error** |
| `node --check` pada JS baru | **OK** |
| `declare(strict_types=1)` | **179/179 file** |
| Secret scan | Bersih; satu temuan INFO (`.env.example`) |

### BELUM SELESAI — 4 task, dengan alasannya

**T138 — commit bertag `refactor:`. TIDAK DAPAT DIKERJAKAN AGENT.**
Instruksi `/rudis.implement` melarang keras menjalankan perintah tulis git atas inisiatif
sendiri: "never `git commit` … on your own initiative. Only run a git write command when the
user explicitly asks for it in this session; a task description, spec, or these instructions
do NOT constitute that authorization."

Bahan untuk commit-nya sudah tersedia dan memang memperbaiki kode yang sudah ada, bukan fitur
yang sedang dikerjakan — seluruhnya tercatat di `docs/quality/refactor-log.md` R-1…R-4.
**Hanya user yang dapat menjalankan commit-nya.**

**T150 — menjalankan quickstart dari clone bersih. TERBLOKIR oleh T138.**
Seluruh pekerjaan masih berupa perubahan yang belum di-commit; history repository hanya berisi
satu commit template (`54fd119`). `git clone` ke direktori kosong karenanya hanya akan
menghasilkan template, **tanpa satu pun kode yang dibuat**. T150 baru bermakna setelah
pekerjaan di-commit.

Tambahan: stack yang sedang berjalan memakai port 8080 dan 3307, sehingga clone bersih perlu
menghentikannya dulu.

**T149 — screenshot. BELUM DIAMBIL.**
`docs/testing/test-scenarios.md` sudah ditulis lengkap beserta daftar berkas screenshot yang
diwajibkan UI-01, tetapi **screenshot-nya belum dibuat** — sesi ini tidak punya tooling
browser. Ini pekerjaan manual manusia.

**T133 — bagian terender BELUM DILAKUKAN.**
Pemeriksaan statis selesai dan tercatat di `docs/testing/responsive-accessibility.md` (label
pada seluruh input, focus-visible, tabel dibungkus `overflow-x`, reflow pada 640px, tidak ada
lebar tetap > 360px, seluruh warna dari token). Yang **belum** dilakukan: membuka halaman di
browser pada 360px dan desktop, menelusuri form dengan keyboard, dan mengukur rasio kontras.
Task sengaja dibiarkan terbuka, bukan ditandai selesai.

### Files added this phase

```
public/assets/js/validation.js
docs/architecture/class-diagram-as-built.md
docs/architecture/adr-001-repository-abstraction.md
docs/architecture/adr-002-concurrency-control.md
docs/quality/refactor-log.md
docs/quality/tech-debt.md
docs/quality/critique.md
docs/quality/phpstan-report.txt
docs/quality/phpcs-report.txt
docs/quality/secret-scan.md
docs/testing/failure-paths.md
docs/testing/test-results.md
docs/testing/use-case-coverage.md
docs/testing/test-scenarios.md
docs/testing/responsive-accessibility.md
docs/planning/user-stories-and-scope.md
ai-usage-log.md
tests/Unit/Service/MasterDataServiceTest.php
```

### Files modified this phase

```
README.md                                  diperluas ke cakupan penuh T146
app/Service/MasterDataService.php          komentar pembenar `mixed`
app/Service/ReportService.php              komentar pembenar `mixed`
app/Support/Session.php                    komentar pembenar `mixed`
app/Support/Validator.php                  komentar pembenar `mixed`
app/Support/View.php                       komentar pembenar `mixed`
public/assets/js/main.js                   import + init validation.js
public/assets/css/app.css                  3 aturan responsive pada blok 640px
tests/Unit/Service/AuthServiceTest.php      4 test step-up re-auth
tests/Unit/Service/PartyServiceTest.php     3 test sisi Customer
tests/Unit/Service/ProductServiceTest.php   5 test lookup API
specs/.../tasks.md                          T138/T139 dikoreksi; 17 task ditandai [X]
```

---

## Session 2026-09-14 — Redesign: perbaikan fitur search

**HEAD checkpoint**: `54fd119` · **Dilaporkan user**: filter dropdown berfungsi, search 500.

### Root cause

`SQLSTATE[HY093]: Invalid parameter number`. Setiap klausa search memakai **satu nama
placeholder DUA KALI** dalam satu statement sementara nilainya diikat sekali:

```php
$clauses[] = '(p.name LIKE :search OR p.sku LIKE :search)';
$params['search'] = '%' . $criteria['search'] . '%';
```

`ATTR_EMULATE_PREPARES = false` membuat PDO meneruskan statement apa adanya ke MySQL, yang
tidak mengenal placeholder bernama berulang. Filter dropdown selamat karena masing-masing
mengikat tepat satu placeholder sekali.

**Enam fitur search mati seluruhnya**, bukan hanya yang dilaporkan: product, sales order,
purchase order, customer, supplier, user. Diverifikasi 500 pada keenamnya sebelum perbaikan.

### Mengapa tidak ada test yang menangkapnya

`InMemory*Repository::search()` mengimplementasikan pencarian dengan `str_contains` di PHP —
SQL-nya tidak pernah dieksekusi. Persis risiko yang dicatat ADR-001, dan bug berbentuk sama
dengan yang ditemukan pada Phase 10 (fixture `setStock()`).

### Perubahan

| File | Perubahan |
| --- | --- |
| `MysqlProductRepository.php` | `:search` → `:search_name` / `:search_sku` |
| `MysqlSalesOrderRepository.php` | → `:search_number` / `:search_customer` |
| `MysqlPurchaseOrderRepository.php` | → `:search_number` / `:search_supplier` |
| `MysqlCustomerRepository.php` | → `:search_name` / `:search_contact` |
| `MysqlSupplierRepository.php` | → `:search_name` / `:search_contact` |
| `MysqlUserRepository.php` | → `:search_name` / `:search_email` |

Masing-masing disertai komentar yang menjelaskan sebabnya, agar pola yang sama tidak ditulis
ulang. Keduanya diikat ke nilai yang sama.

**Ditambahkan**: `tests/Integration/RepositorySearchTest.php` — 16 test yang menjalankan query
sungguhan untuk keenam repository, menguji **kedua sisi** setiap klausa OR, kesepakatan
`search()` dengan `countBy()` (pagination), kombinasi search + filter dropdown, dan bahwa
input bergaya SQL diperlakukan sebagai teks.

**Diverifikasi benar-benar menangkap bug-nya**: satu klausa dikembalikan sementara → 7 error;
perbaikan dipasang lagi → 16/16 lulus.

**Tidak disentuh**: Service, Controller, view, allowlist sort, fake in-memory, logika filter
dropdown. Tidak ada perubahan schema maupun contract.

### Verifikasi

| Gate | Hasil |
| --- | --- |
| Keenam halaman lewat HTTP | **200** (sebelumnya 500) |
| Pencocokan kedua sisi OR | Product by SKU ✓, user by email ✓, sales order by customer name ✓ |
| `composer test` | **OK — 461 test, 1294 assertion** |
| `composer analyse` (PHPStan 6) | **OK — no errors** |
| `composer cs` (PSR-12) | **OK — 0 error** |
| Scope | Hanya 6 repository + 1 file test baru |

Dicatat juga sebagai `docs/quality/tech-debt.md` TD-2b.

### Lanjutan — pemeriksaan sort dan pagination

Diminta user setelah perbaikan search: memastikan sort dan pagination tidak ikut bermasalah.

**Hasil: keduanya BERFUNGSI BENAR, tidak ada error.** Diperiksa lewat HTTP pada seluruh
halaman list — setiap sort key × kedua arah, halaman 1–4, halaman di luar jangkauan, nilai
`page` tidak wajar (`0`, `-1`, `abc`), serta kombinasi sort + search + filter + page.
Tidak ada satu pun respons non-200.

Diverifikasi bukan hanya status code, melainkan perilakunya: sort benar-benar membalik urutan,
halaman kedua benar-benar melanjutkan halaman pertama, sort key di luar allowlist jatuh ke
default alih-alih masuk ke SQL, dan `LIMIT`/`OFFSET` terikat sebagai integer sungguhan (kedua
parameter bertipe `int` dengan `strict_types`, sehingga tidak pernah menjadi string).

**Mengapa sort selamat sementara search tidak**: sort tidak pernah memakai placeholder bernama
sama sekali. Nama kolomnya dipetakan allowlist `SORTABLE`, dan arahnya dinormalisasi menjadi
literal `ASC`/`DESC` — tidak ada teks user yang masuk ke SQL.

**Celah yang ditutup.** Sort hanya teruji di lapisan unit terhadap fake in-memory yang
mengurutkan dengan `usort` di PHP, sehingga `ORDER BY` yang sesungguhnya belum pernah
dieksekusi test — blind spot yang sama persis dengan yang menyembunyikan bug search, dan sudah
tercatat pada catatan Phase 7 tanpa pernah ditindaklanjuti.

Ditambahkan `tests/Integration/RepositorySortPagingTest.php` — 13 test terhadap SQL sungguhan:
ketiga sort key × dua arah, urutan alfabet dan urutan numerik untuk kolom DECIMAL, batas
halaman, halaman kedua yang tidak mengulang maupun melewatkan baris, `countBy()` yang tidak
ikut terpotong `LIMIT`, sort key dan direction bermuatan SQL, serta sort digabung dengan
search, filter, dan paging.

**Diverifikasi benar-benar menangkap regresi**: allowlist `resolveSortColumn()` dilumpuhkan
sementara → 3 error; dikembalikan → 13/13 lulus.

| Gate | Hasil |
| --- | --- |
| `composer test` | **OK — 474 test, 1319 assertion** |
| `composer analyse` (PHPStan 6) | **OK — no errors** |
| `composer cs` (PSR-12) | **OK — 0 error, 0 warning** |

Tidak ada kode produksi yang diubah pada langkah ini — sort dan pagination memang sudah benar.

---

## Session 2026-09-14 — Redesign: jarak antar card

**HEAD checkpoint**: `54fd119` · **Dilaporkan user**: bila satu halaman memuat lebih dari satu
card, jarak antar card-nya tidak ada.

### Root cause

`.card` sama sekali tidak punya margin. Blok tingkat halaman lain sudah punya —
`.page-header` dan `.stat-grid` memakai `margin-bottom: var(--space-6)` — sehingga deretan
stat terlihat berjarak, tetapi card yang bertumpuk menempel rapat.

Wrapper halamannya (`<main class="page"><div class="container">`) bukan container flex/grid
ber-gap, jadi tidak ada yang memberi jarak itu dari luar.

Terdampak **8 halaman**: dashboard admin/sales/warehouse, reports, detail sales order dan
purchase order, serta form sales order dan purchase order.

### Perubahan

Satu file, satu aturan: `public/assets/css/app.css`.

```css
.card            { margin-bottom: var(--space-6); }   /* 24px, sama dengan .stat-grid */
.card:last-child { margin-bottom: 0; }
```

Nilainya sengaja disamakan dengan `.page-header` dan `.stat-grid` agar ritme vertikal seluruh
halaman tetap satu skala, bukan memperkenalkan angka jarak baru.

### Yang diperiksa sebelum mengubah

- **Tidak ada card yang berada di dalam container ber-gap.** Pola yang dipakai selalu
  `.card > form.stack`, tidak pernah sebaliknya — diperiksa pada 36 card di seluruh `views/`.
  Karena itu margin ini tidak mungkin bertumpuk dengan `gap` milik `.stack`.
- **Halaman login tidak terdampak**: memakai kelas `.centered-card` yang terpisah.
- **Card yang masih diikuti elemen lain tetap berjarak.** Pada form order, card kedua diikuti
  `.form-actions`, sehingga ia bukan `:last-child` dan margin-nya tetap berlaku — diverifikasi
  pada HTML yang benar-benar dirender.
- **Card terakhir pada dashboard memang `:last-child`**, jaraknya ditutup
  `padding-block` bawah milik `.page`.

**Tidak disentuh**: seluruh file view, `.centered-card`, `.stack`, `.stat-grid`, dan token.

### Verifikasi

| Gate | Hasil |
| --- | --- |
| Aturan tersaji lewat HTTP | ✓ |
| Kelima halaman bercard tumpuk | **200** |
| Kurung kurawal CSS seimbang | 142 / 142 |
| Hex hardcoded baru | 0 — seluruh nilai dari token |
| `composer test` | **OK — 474 test, 1319 assertion** |
| Scope | Hanya `public/assets/css/app.css` |

**BELUM DIVERIFIKASI**: hasil visualnya belum dilihat di browser — 24px belum dinilai secara
visual, dan perilakunya pada 360px belum diperiksa. Termasuk bagian T133/T149 yang masih
terbuka.

---

## Session 2026-09-14 — Redesign: toolbar halaman report

**HEAD checkpoint**: `54fd119` · **Dilaporkan user**: posisi komponen di dalam toolbar halaman
report berantakan.

### Root cause

`.toolbar` memakai `align-items: flex-end`, sehingga yang disejajarkan adalah **sisi bawah**
tiap item. Itu bekerja selama seluruh item setinggi sama.

Pada halaman report tingginya TIDAK sama:

| Item | Isi |
| --- | --- |
| Field "From" | label + input |
| Field "To" | label + input + **`<p class="field-hint">`** |
| Tombol | tidak keduanya |

Karena field "To" memuat satu baris pesan di bawah input, sisi bawahnya adalah pesan itu —
bukan input. Akibatnya input "From" tersejajarkan dengan PESAN milik "To", kedua input date
jadi bergeser vertikal, dan tombolnya ikut turun sejajar pesan.

Toolbar lain (product, sales order, purchase order) selamat karena field-nya tidak pernah
memuat hint maupun error — seluruh itemnya setinggi sama.

Keadaan error lebih buruk lagi: field "From" ikut memperoleh pesan, sehingga tinggi barisnya
bergeser sekali lagi.

### Perubahan

| File | Perubahan |
| --- | --- |
| `public/assets/css/app.css` | Modifier `.toolbar--with-notes`: pesan dikeluarkan dari perhitungan tinggi baris |
| `views/reports/index.php` | Menambahkan class `toolbar--with-notes` pada form toolbar |

Pesan digantung tepat di bawah field-nya sendiri (`position: absolute`), sehingga seluruh item
toolbar kembali setinggi input dan sejajar. Ruangnya disediakan `padding-bottom: var(--space-8)`
pada toolbar — 32px, sementara pesan 12px/±18px yang digantung 4px di bawah field hanya
memakai ±22px, menyisakan ±10px kelegaan sebelum border bawah toolbar.

Berlaku **hanya dari 641px ke atas**. Di bawah itu field sudah melebar penuh dan menumpuk
(blok `max-width: 640px`), jadi tidak ada kesejajaran antar kolom yang perlu dijaga dan pesan
lebih baik mengalir normal.

Pesan error tetap berada tepat di bawah input yang salah — konvensi `.field-error` yang dipakai
seluruh form lain tidak diubah (FR-029).

**Tidak disentuh**: aturan `.toolbar` bersama, serta toolbar product, sales order, dan purchase
order.

### Verifikasi

| Gate | Hasil |
| --- | --- |
| Aturan tersaji lewat HTTP | ✓ |
| Keadaan normal (hint saja) | **200**, satu pesan pada field "To" |
| Keadaan error (kedua field berpesan) | **200**, error pada "From" + hint pada "To" |
| Kurung kurawal CSS seimbang | 146 / 146 |
| Hex hardcoded baru | 0 |
| `composer test` | **OK — 474 test, 1319 assertion** |
| Scope | Hanya 2 file: `app.css` dan `views/reports/index.php` |

**BELUM DIVERIFIKASI**: hasil visualnya belum dilihat di browser. Kesejajarannya benar secara
model layout dan ruang untuk pesan sudah dihitung, tetapi belum ada yang benar-benar melihatnya
— termasuk perilaku pada 360px. Bagian dari T133/T149 yang masih terbuka.

---

## Session 2026-09-14 — Redesign: navbar atas menjadi sidebar

**HEAD checkpoint**: `54fd119` · **Permintaan user**: pindahkan navigasi dari atas halaman
menjadi sidebar. **Keputusan user**: layar sempit memakai off-canvas drawer dengan tombol
hamburger.

### Bentuk akhir

| Lebar layar | Navigasi |
| --- | --- |
| ≥ 641px | Sidebar permanen 240px di kiri, sticky setinggi viewport |
| ≤ 640px | Drawer yang menggeser masuk dari kiri, dibuka tombol pada bar atas |

**Drawer-nya digerakkan CHECKBOX, bukan JavaScript.** Ini keputusan yang mengikat: brief
menetapkan aplikasi tetap berfungsi penuh tanpa JavaScript, dan drawer yang hanya dapat dibuka
lewat JS akan menghilangkan SELURUH navigasi begitu JS mati. `nav-drawer.js` hanya menambahkan
`aria-expanded` dan tombol Escape di atas mekanisme yang sudah bekerja sendiri — bila modul itu
gagal dimuat, drawer tetap berfungsi penuh.

### Perubahan

| File | Perubahan |
| --- | --- |
| `views/layout/app.php` | Shell: checkbox toggle, scrim, dan bar atas mobile; urutan elemen disusun agar selector `:checked ~` menjangkau `.nav` dan `.nav-scrim` |
| `views/layout/_nav.php` | Markup disusun vertikal — brand di atas, link di tengah, blok user di dasar. **Logika role tidak disentuh** |
| `public/assets/css/app.css` | `.app-shell` menjadi grid dua kolom; `.nav` menjadi kolom penuh; aturan drawer pada blok `max-width: 640px` |
| `public/assets/css/tokens.css` | Token baru `--sidebar-width: 240px` dan `--scrim` |
| `public/assets/icons/lucide-sprite.svg` | Menambah icon `menu` (Lucide, ISC) |
| `public/assets/js/nav-drawer.js` | **Baru** — penyempurnaan `aria-expanded` + Escape |
| `public/assets/js/main.js` | Memasang `initNavDrawer()` |

`.nav-inner` dihapus karena tidak lagi dipakai — diperiksa tidak ada rujukan tersisa.

### Dua hal yang mudah terlewat, dan sudah ditangani

**`min-width: 0` pada `.page`.** Grid item defaultnya `min-width: auto`, sehingga tabel lebar
akan MELEBARKAN kolomnya alih-alih menggulir di dalam `.table-wrap` — seluruh halaman ikut
bergeser mendatar. Tanpa baris ini, sidebar justru akan merusak setiap halaman bertabel.

**Checkbox disembunyikan penuh pada layar lebar.** Sidebar permanen tidak membutuhkannya, dan
membiarkannya fokusable akan membuat pengguna keyboard singgah pada kontrol tak terlihat yang
tidak melakukan apa pun. Pada layar sempit ia dihidupkan kembali dan tetap fokusable.

Transisi drawer dimatikan pada `prefers-reduced-motion: reduce`.

### Verifikasi

| Gate | Hasil |
| --- | --- |
| Seluruh halaman utama | **200** |
| Urutan elemen shell | `#nav-toggle` → `.nav` → `.nav-scrim` → `.page` ✓ selector sibling terjangkau |
| Menu per role **tidak berubah** | Admin 8 link, Sales 5, Warehouse 5 — persis seperti sebelumnya |
| `aria-current="page"` | ✓ masih ditandai |
| Form sign-out + CSRF | ✓ utuh |
| Halaman login | **200** — memakai layout `auth.php` yang terpisah, tidak terdampak |
| Kurung kurawal CSS | 159 / 159 |
| Hex hardcoded baru | 0 — `--scrim` ditambahkan sebagai token |
| `node --check` JS baru | ✓ |
| `composer test` | **OK — 474 test, 1319 assertion** |

### BELUM DIVERIFIKASI — dan untuk perubahan sebesar ini itu penting

Ini perubahan layout yang menyentuh shell SETIAP halaman, tetapi **hasil visualnya belum
dilihat di browser sama sekali**. Yang sudah dipastikan hanyalah struktur, urutan elemen,
keutuhan menu per role, dan semua halaman tetap 200.

Yang benar-benar perlu dilihat manusia:
- proporsi sidebar 240px terhadap konten, dan tabel lebar di dalam kolom yang menyempit
- drawer pada 360px: apakah menggeser mulus, scrim menutup, dan tombol tutup terjangkau
- navigasi keyboard: Tab ke tombol hamburger, Enter membuka, Escape menutup
- perilaku dengan JavaScript dimatikan — drawer harus tetap dapat dibuka

Ini memperbesar utang yang sudah tercatat pada T133/T149 dan `tech-debt.md` TD-7.

---

## Redesign 2026-10-03 — validasi `docs/quality/critique.md` (DESIGN-04)

**Checkpoint (rollback point)**: `31d5feb` — working tree bersih.
**Target**: klaim di `docs/quality/critique.md` divalidasi terhadap kode; yang salah diperbaiki.

**Temuan validasi**
- Dokumen berisi kritik desain sendiri, padahal DESIGN-04 meminta kritik atas cuplikan kode
  dari assessor. Bagian wajib itu belum ada → kerangka disiapkan (cuplikan belum diterima).
- 1.2 menyebut duplikasi `queryState()` di tiga controller; kenyataannya enam (ambang
  "salinan keempat" milik dokumen sendiri sudah terlewati). `sortCriteriaFrom()` di tiga.
- ISP: `ProductRepositoryInterface` 13 method (bukan 14); `ReportService` tidak memakainya;
  `DashboardService` memakai 3 (bukan 2).
- Bagian 4 usang: SAVEPOINT dan test filesystem sudah dikerjakan (tech-debt TD-1, TD-3).

**File yang direncanakan**
- `app/Support/Request.php` — `queryState()` dan `sortCriteria()`
- `app/Controller/{Customer,Supplier,User,Product,PurchaseOrder,SalesOrder}Controller.php` — hapus salinan private
- `tests/Unit/Support/RequestTest.php` — baru
- `docs/quality/critique.md`, `docs/quality/refactor-log.md` (R-6)

**File yang diubah**
- `app/Support/Request.php` — `queryState(list<string>)`, `sortCriteria(list<string>, 'asc'|'desc')`
- `app/Controller/{Customer,Supplier,User,Product,PurchaseOrder,SalesOrder}Controller.php` — konstanta `FILTER_KEYS`; 6 `queryState()` dan 3 `sortCriteriaFrom()` private dihapus
- `tests/Unit/Support/RequestTest.php` — baru, 7 test
- `docs/quality/critique.md` — Bagian A (kerangka DESIGN-04, menunggu cuplikan assessor); Bagian B dikoreksi
- `docs/quality/refactor-log.md` — R-6

**Verifikasi**
- `composer check`: OK — unit 374 test / 972 assertion, integration 138 / 482, PHPStan 0 error, PHPCS 0 error 0 warning
- End-to-end (HTTP, akun Admin): link halaman 2 tetap membawa filter dan sort; `direction=asc` urut naik; arah tidak sah jatuh ke default `desc`
- Class diagram as-built tidak memuat member yang berubah — tidak ada drift
- Di luar scope: tidak ada perubahan pada Service, Repository, view, route, maupun schema

**UNRESOLVED**: tidak ada. Bagian A `critique.md` menunggu cuplikan dari assessor (bukan cacat).
