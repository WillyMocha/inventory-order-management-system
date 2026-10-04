# Implementation Log: Stock Movement Chart on the Dashboard

## Session 2026-10-04

- **Checkpoint HEAD**: `31c1074` (working tree clean apart from the untracked `SOP-Penggunaan-IOMS.pdf`, which is
  never committed, and this spec folder)
- **Tasks targeted**: T001–T026
- **Planned files** (plan.md): `app/Repository/StockLedgerRepositoryInterface.php`,
  `app/Repository/Mysql/MysqlStockLedgerRepository.php`, `app/Service/DashboardService.php`,
  `app/Support/BarChartScale.php` (new), `config/container.php`, `views/dashboard/_movement-chart.php` (new),
  `views/dashboard/admin.php`, `views/dashboard/warehouse.php`, `public/assets/css/app.css`,
  `tests/Unit/Fake/InMemoryStockLedgerRepository.php`, `tests/Unit/Service/DashboardServiceTest.php`,
  `tests/Unit/Support/BarChartScaleTest.php` (new), `tests/Integration/DashboardReportConsistencyTest.php`,
  `tests/Integration/DashboardChartRenderTest.php` (new), plus docs in Bolt 6
- **Checklists**: `checklists/requirements.md` 16/16 — PASS
- **Requirement coverage precondition**: FR-001 … FR-013 each cited by ≥ 1 task; SC-001 … SC-007 achievable — PASS

### Progress

- T001 — baseline `composer check` green: unit 459 tests / 1307 assertions, integration 254 / 914, PHPStan 0,
  PHPCS 0.
- T002 — `tests/Integration/DashboardReportConsistencyTest.php`: 4 tests on two fixed past days (all movement types,
  ±Adjustment, gap day, deactivated product) + helper `insertLedgerAt()` (explicit `created_at`; non-Manual rows
  carry `reference_id = 1` to satisfy `ck_ledger_reference_id`). Red first: method missing.
- T003 — `app/Repository/StockLedgerRepositoryInterface.php`: `dailyMovementTotals()`.
- T004 — `app/Repository/Mysql/MysqlStockLedgerRepository.php`: one `GROUP BY DATE(created_at)` query, no join,
  same range predicate as `movementsBetween()`.
- T005 — `tests/Unit/Fake/InMemoryStockLedgerRepository.php`: same rule; plus `dailyTotalsCalls()` counter (C1).
  Also `tests/Integration/FailOnSecondAppendLedger.php` (a third interface implementation, not in plan.md) delegates
  the new method — found by the first gate run.
- Bolt 1 gate: unit 459, integration 258, PHPStan 0, PHPCS 0.
- T006 — **deviation (file)**: the stock movement tests live in a new file
  `tests/Unit/Service/DashboardStockMovementTest.php` (10 tests) instead of a section of `DashboardServiceTest.php`,
  which only had its `setUp()` constructor updated — keeps both files short. Red first.
- T007 — `app/Service/DashboardService.php`: ledger + clock dependencies, `MOVEMENT_DAYS = 30`, private
  `stockMovement()`, `@phpstan-type StockMovement`; figure added to Admin and Warehouse Staff only.
- T008 — `config/container.php`, `tests/Integration/DashboardReportConsistencyTest.php` constructor.
- Bolt 2 gate: unit 469, integration 258, green (after shortening three test lines and converting one file to LF).
- T009/T010 — `tests/Unit/Support/BarChartScaleTest.php` (22 cases), `app/Support/BarChartScale.php`.
  **Deviation (design detail)**: gridlines are 4 **or** 5 steps chosen from the axis maximum (10 → 5, 20 → 4,
  50 → 5; below 10, one per unit) instead of always 4, so every axis label is a whole number (with 4 steps an axis of
  10 would label 2.5). data-model.md and research R-005 updated.
- T011/T016/T020 — `views/dashboard/_movement-chart.php`: card, summary, legend, SVG, `<title>` per day, focusable
  only when the day has movement, hidden tip, `aria-label`, screen-reader table, report link (populated and empty).
  The render tests T014/T015/T019 were written first and were red (6 of 7).
- T012 — `views/dashboard/admin.php`, `views/dashboard/warehouse.php`: partial included before "Products needing
  attention"; `@var` shapes extended. `views/dashboard/sales.php` untouched.
- T013/T017 — `public/assets/css/app.css`: chart styles from tokens, focus stroke on the slot, tip on hover/focus,
  larger SVG text below 640 px.
- T014/T015/T019 — `tests/Integration/DashboardChartRenderTest.php` (7 tests). One assertion changed from a test-only
  `data-total-in` attribute to the `aria-label` text, so the markup carries no test-only attribute.
- Visual check (Pass B, headless Chrome): 1280 px fine for Admin and Warehouse Staff; Sales has no card. At 360 px the
  page first overflowed by 21 px — cause: `<table class="visually-hidden">` ignores the 1 px width (tables size to
  content). Fixed by wrapping the table in `<div class="visually-hidden">` (contract updated); axis text enlarged on
  small screens (it scales down with the viewBox). Re-check: no overflow at 360 px for any role. Real Tab key from the
  card link lands on the first day with movement; `:focus` matches and the tip is visible.
- T018 — `DashboardReportConsistencyTest::theDashboardChartMatchesTheStockMovementExportForItsWindow` (rows written
  today incl. ±Adjustment; per-day figures and net equal the export).
- T021 — `composer check` green: unit 491 / 1398, integration 266 / 983, PHPStan 0, PHPCS 0.
  `composer test:coverage`: `DashboardService` 72/72, `MysqlStockLedgerRepository` 79/79, `BarChartScale` 24/24
  (an unreachable fallback was made reachable by removing 10 from the nice-step list).
- T022 — `docs/architecture/class-diagram-as-built.md`: `DashboardService` → ledger + clock, `dailyMovementTotals()`,
  section "Grafik stock movement di dashboard".
- T023 — `docs/brd/modules/dashboard.md` (DASH-CAP-005, consumes `stock`, tests, change log), `docs/brd/modules/stock.md`
  (consumed by dashboard).
- T024 — `README.md` (Dashboard row + "Bonus" subsection: chart and extra integration tests),
  `docs/testing/test-results.md` (counts 491/266/757, chart evidence and coverage), `docs/testing/use-case-coverage.md`
  (row for the chart).
- T025 — quickstart §1–§6 checked in headless Chrome against the running stack: card for Admin and Warehouse Staff,
  none for Sales; figures equal the summary; Tab focus and tip; report link; no horizontal scroll at 360 px.
  Refreshed `docs/testing/screenshots/02-admin-dashboard-{desktop,mobile}.png` and
  `04-warehouse-dashboard-{desktop,mobile}.png`; SOP (scratchpad source) A-01 and W-01 describe the chart, screenshots
  retaken, version 1.4; `SOP-Penggunaan-IOMS.pdf` regenerated (untracked, never committed).
- T026 — scope check: changed files = plan.md list + `tests/Integration/FailOnSecondAppendLedger.php` (third interface
  implementation) + `tests/Unit/Service/DashboardStockMovementTest.php` (test file deviation above) + docs and the
  four refreshed screenshots. No route, migration, controller, or JSON API change. Line endings normalised to LF.

### Result

All 26 tasks done. Final gate: unit 491 tests / 1398 assertions, integration 266 / 983, PHPStan 0, PHPCS 0;
`composer test:coverage` 757 tests, new code fully covered. No UNRESOLVED errors.

Rollback: checkpoint `31c1074` — `git checkout 31c1074 -- <path>` per file, and delete the new files
(`app/Support/BarChartScale.php`, `views/dashboard/_movement-chart.php`, the three new test files, this spec folder).
