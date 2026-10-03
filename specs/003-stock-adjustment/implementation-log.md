# Implementation Log: Stock Adjustment

## Session 2026-10-03 — /rudis.implement

- **Checkpoint HEAD**: `40665e9b191a9458ba8ecac14a6c86c12390c3b5` (branch `fix/correct-business-flow`)
- **Working tree at start**: only `specs/003-stock-adjustment/` untracked (this feature's artifacts).
- **Tasks targeted**: T001–T034
- **Checklists**: `checklists/requirements.md` 16/16 ✓ PASS
- **Requirement coverage gate**: FR-001 … FR-012 each cited by ≥1 task; SC-001 … SC-007 reachable
  (tasks.md "Requirement Coverage", re-validated by `/rudis.analyze`) ✓
- **No git writes**: nothing is committed, tagged, or pushed by this run.

### Task log

- T001 — baseline `composer check`: unit 389 OK, integration 153 OK, PHPStan and PHPCS clean.
- T002 — `tests/Integration/StockAdjustmentFlowTest.php` (new): 4 schema tests bound to MySQL error 3819
  (CHECK violation) so an "unknown column" error cannot pass them; red first (1054 Unknown column 'note').
- T003 — `database/004_ledger_note.sql` (new): `note VARCHAR(255) NULL` + `ck_ledger_note_adjustment` +
  `ck_ledger_adjustment_manual`; applied to the dev DB (`migrate.php`, seed rows pass both CHECKs) and the
  test DB (`composer db:test`); T002 green.
- T004 — `app/Entity/StockLedger.php`: `?string $note = null` (last param) + `adjustment()` factory.
- T005 — `MysqlStockLedgerRepository`: `note` in SELECT, `append()`, `hydrate()`, `movementsBetween()`;
  `InMemoryStockLedgerRepository` stores `note` and returns it from `movementsBetween()`.
- T006 — `ProductStockRepositoryInterface::ensureRow()`; MySQL implementation extracted from `adjust()`
  (which now calls it); fake implementation.
- T007 — `StockService` gains `WarehouseRepositoryInterface $warehouses`; wired in `config/container.php`
  and the 7 construction sites (`StockServiceTest` ×2, `ConcurrentGoodsIssueTest`, `GoodsIssueTest`,
  `GoodsReceiptTest`, `LedgerReconciliationTest`, `RepositoryCoverageTest`).
- T008 — `RepositoryCoverageTest::ensureRowCreatesAZeroRowOnlyWhenTheStockRowIsMissing`.
- Bolt 1 gate — unit 389 OK, integration 158 OK, PHPCS OK; PHPStan 1 expected error
  (`StockService::$warehouses` only written) that T010 resolves — no commit point proposed until then.
- T009 — `tests/Unit/Service/StockServiceAdjustmentTest.php` (new, 14 tests incl. trimmed-length case):
  red first (method missing). Each refusal asserts stock, the never-stocked row, and the ledger unchanged.
- T010 — `StockService::adjustStock(int, array, User)` + private `validateAdjustment()` and
  `adjustWithinTransaction()`, `NOTE_MAX_LENGTH = 255`; imports `ForbiddenException`, `ValidationException`,
  `Validator`. Self-review fix: the trimmed reason is now validated (`['note' => trim(...)] + $input`;
  `$input + [...]` would have kept the untrimmed value) — covered by `theLengthLimitAppliesToTheTrimmedReason`.
- T011 — `StockAdjustmentFlowTest`: +3 MySQL tests (ledger + stock together, invariant, every refusal
  leaves both unchanged, low-stock filter / `availableFor` / `ProductService::availableQuantity` follow).
- T012 — `tests/Integration/ConcurrentStockAdjustmentTest.php` (new, 3 tests, two real connections):
  (a1) B waits ≥ timeout while A holds the row; (a2) after A commits a goods issue, B's stale count is
  refused with key `stock`; (b) never-stocked pair: B waits on A's `ensureRow` row and fails with a lock
  wait (not deadlock 1213), then succeeds after A rolls back. Invariant checked in every scenario.
  Note: in (b) connection A performs the Service's first two steps (`ensureRow` + `lockForUpdate`) through
  the repository directly, because a single-threaded test cannot pause inside `adjustStock()`.
- Bolt 2 gate — unit 403 OK, integration 164 OK, PHPStan 0, PHPCS 0 (one >120-char line split; no
  `phpcs:ignore` used, consistent with the project's zero-suppression policy).
- T013 — `config/routes.php`: `GET|POST /products/{id}/adjust-stock` ($adminWarehouse);
  `StockAdjustmentFlowTest`: route roles exactly [Admin, WarehouseStaff] for both methods; guard refuses Sales.
- T014 — `app/Controller/StockAdjustmentController.php` (new): `create()`, `store()`; raw body passed to the
  Service; 422 re-render re-reads the system quantity; flash "Stock adjusted in {wh}: {before} → {after} ({±delta})."
- T015 — `config/container.php`: controller factory + `use`.
- T016 — `views/stock-adjustments/form.php` (new): header, warehouse picker (GET, no JS) + "System quantity"
  stat tile, form card (counted quantity, reason, hidden warehouse/expected), summary alert, field errors with
  `aria-describedby`, `_empty-state` partial when no active warehouse. Pass B fix: new `.inline-picker` in
  `app.css` keeps the select and "Show" on one row (they wrapped because `.select` is 100% wide).
- T017 — `ProductController::show()` passes `canAdjust` (Admin|WarehouseStaff); `views/products/detail.php`
  shows "Adjust stock" in the header when `canAdjust`.
- T018 — HTTP (curl): WS 28 → 25 → 302 + flash "Stock adjusted in Gudang Pusat Jakarta: 28 → 25 (-3).";
  zero difference 422; stale (expected 28 vs 25) 422 "changed to 25 while…"; `2.5` 422 "whole number";
  no CSRF 403; Sales GET 403, POST 403; Sales detail has no button, WS detail has it; unknown product 404;
  Admin never-stocked pair (product 31, Gudang Surabaya) 0 → 5. Restored: product 31/WH2 back to 0,
  product 1/WH1 back to 28; `availableFor` = ledger sum for both. The ledger keeps the 5 verification
  adjustments (append-only by design), each with a reason marking it as verification.
  SC-001: the scripted flow is sub-second; the screen needs two fields and one click — manual timing in T033.
- Pass B (Bolt 3) — form at 1366/768/360 and product detail at 1366/768/360: status 200, overflow 0; one
  layout issue fixed (picker wrap). Bolt 3 gate: unit 403, integration 166, PHPStan 0, PHPCS 0.
- T019 — `StockServiceAdjustmentTest`: +3 tests for `recentAdjustments()` (newest first with running
  balance across a receipt and an issue, other products excluded; limit 10; empty). Red first.
- T020 — `ReportServiceTest`: +3 tests (header ends with `Reason`; Adjustment row carries `-3`, `Manual`,
  reason; Receipt reason empty; `=1+1` → `'=1+1`). Red first.
- T021 — `StockLedgerRepositoryInterface::recentAdjustmentsForProduct()`; MySQL: window function
  `SUM(quantity) OVER (PARTITION BY warehouse_id ORDER BY created_at, id)` over the product's whole history,
  then filter Adjustment, `LIMIT :limit`; in-memory fake computes the same running sum; the integration
  decorator `tests/Integration/FailOnSecondAppendLedger.php` (a third implementer not listed in tasks.md)
  delegates the new method — found by the fatal error in the integration run.
- T022 — `StockService::recentAdjustments()` + `RECENT_ADJUSTMENTS = 10`.
- T023 — `ProductController` gets `StockService` (constructor + `config/container.php`); `show()` computes
  `$canAdjust` once and runs the history query only for Admin/WS; `views/products/detail.php` "Stock adjustments"
  card (table, `_empty-state` partial with CTA) for `canAdjust` only; `app.css` `.table td.cell-wrap`,
  `.qty-change--up/--down` (`--success-text` / `--danger-text`).
- T024 — `ReportService::STOCK_MOVEMENT_FIELDS` + `note`, `STOCK_MOVEMENT_HEADER` + `Reason` (last column).
  `DashboardReportConsistencyTest` iterates the constants, so it needed no change.
- T025 — `StockAdjustmentFlowTest` +2 MySQL tests (running balance from the whole ledger; export carries the
  reason only for adjustments); `RepositoryCoverageTest::recentAdjustmentsAreReadFromMySql`.
- Incident — a stale scratchpad script from spec 002 (`t025.py`) ran by name collision and re-inserted the
  002 sections into `specs/001-…/contracts/http-routes.md` (17 duplicated lines). Detected via the file-change
  note, inspected with `git diff` (only the duplication), restored with `git checkout -- <that file>`;
  later scripts use an `s3_` prefix.
- Bolt 4 gate — unit 409, integration 169, PHPStan 0 (one array{} narrowing fixed with `assertCount(0, …)`),
  PHPCS 0 (array shapes written multi-line; long test lines split). HTTP: WS sees the card, Sales does not;
  CSV header ends with `Reason` and adjustment rows carry their reason. Pass B: product detail at 1366/768/360,
  overflow 0; on 360px the history table scrolls inside `.table-wrap` like every other table.
- T026 — `MovementType` docblock (Adjustment now produced by `adjustStock`); spec 001 A-006 marked superseded
  → spec 003; spec 001 `data-model.md` (`note` row + pairing CHECK note); `docs/planning/erd.md` (`note`).
- T027 — ADR-002 addendum (same row lock, ensure-row before lock and why gap locks are not enough, stale
  check, no order lock → no cycle, proof test) + references.
- T028 — class diagram: `StockService` methods and 7th dependency, interface methods, `StockAdjustmentController`
  and `ProductController --> StockService` in diagram 3, new "Koreksi stock" subsection; 4 Mermaid blocks render.
- T029 — refactor-log R-8 (Extract Method `ensureRow()`).
- T030 — spec 001 route contract: two routes, CSV `Reason` note, matrix row.
- T031 — BRD `stock.md` (CAP-005 implemented, CAP-006 added, rules, API surface, flow, tests, gap removed,
  change log), `00-overview.md` (gap removed, Q2 fulfilled, change log); README Stock row, Admin and WS roles.
  `docs/brd/modules/product.md` was not changed: it does not list the detail page contents.
- T032 — `use-case-coverage.md` (StockService 5/8, new 003 section, 2 integration rows), `test-results.md`
  (2 integration rows; counts updated in T034). No new tech debt was taken; TD-7 note extended (T033).
- T033 — evidence harness extended (`22-stock-adjustment-form-*`, `23-stock-adjustment-error-*` via a
  zero-difference submit that writes nothing; contrast for the form, its 422 state, and product detail; keyboard
  for the form). Result: 42 captures, overflow 0, clipped 0; 688 text elements in 14 measurements, 0 failing
  (min 4.55:1); 5 keyboard pages, 0 stops without indicator. One pre-existing defect surfaced and fixed:
  `stat-delta--up` 3.3:1 → `--success-text` (A7). Docs: `responsive-accessibility.md`, `run.json`,
  `a11y-audit.json`, TD-7 note. SC-001: one navigation, two fields, one click; the scripted HTTP flow takes
  well under a second — recorded as a manual observation, not a measured human timing.
- T034 — `composer check` exit 0 (unit 409 / 1116, integration 169 / 625, PHPStan 0, PHPCS 0); reports
  regenerated; `test-results.md` counts updated (combined 578 / 1741). No `UPDATE`/`DELETE` on
  `stock_ledger` anywhere in `app/` or `database/*.php`; `docker compose logs app` contains no adjustment
  reason; no `error_log` in `StockAdjustmentController`/`StockService`. Quickstart §1–§4 walked over HTTP
  (T018, Bolt 4 HTTP checks); §4.2 (`=1+1` in the CSV) is proven by `ReportServiceTest` instead of a live
  adjustment, to avoid adding more permanent ledger rows to the demo data.

### Deviations and out-of-scope fixes (recorded for review)

- A5 — `.inline-picker` (new CSS class) for the warehouse select + button; found by Pass B.
- A6 — `FailOnSecondAppendLedger` (integration test decorator) also implements the ledger interface and
  needed the new method; tasks.md did not list it.
- A7 — `.stat-delta--up/--down` now use `--success-text`/`--danger-text` (pre-existing 3.3:1 failure, every
  page with a "good" stat delta). Same approach as the badge and alert fixes.
- Demo data — the dev ledger now holds 5 extra Adjustment rows from T018 verification (net quantities restored;
  reasons say "verification"). They can only be removed with `composer db:reset`.
