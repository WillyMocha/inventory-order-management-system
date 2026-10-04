---
description: "Task list for the Stock Movement Chart on the Dashboard feature"
---

# Tasks: Stock Movement Chart on the Dashboard

**Input**: Design documents from `specs/005-stock-movement-chart/`
**Prerequisites**: plan.md, spec.md, research.md, data-model.md, contracts/dashboard-stock-movement.md, quickstart.md

**Tests**: Included. Constitution Principle III (NON-NEGOTIABLE) requires a unit test for every public Service method
that computes a business figure; research R-009 asks for a real-MySQL proof that chart and CSV agree. Test tasks
come **before** their implementation and must fail first.

**Organization**: Grouped by user story. Every phase is a **Bolt** that ends at a checkpoint: stop, run the checks,
and propose a commit before starting the next (commit only when the owner asks).

**Conventions for every task** (CLAUDE.md, constitution v1.2.0):
- `declare(strict_types=1);` in every PHP file; every parameter, return, and property typed; array shapes documented
  for PHPStan level 6 (`array{start: string, end: string, days: list<array{date: string, in: int, out: int}>, totalIn:
  int, totalOut: int, net: int}`).
- Comments and docs in Indonesian; UI text in English; identifiers in English; this folder in English.
- Read only: nothing in this feature writes `stock_ledger` or `product_stock` (NFR-004).
- Output escaped with `View::e()`; numbers cast to `int` before printing; all SQL prepared
  (`ATTR_EMULATE_PREPARES = false`, so no repeated named placeholder in one statement).
- No library, no JavaScript file (FR-012). Colours only from `public/assets/css/tokens.css`.
- Keep every touched production file ≤ 300 lines and ≤ 20 methods (SonarQube baseline).

## Format: `[ID] [P?] [Story] [FR-###?] Description`

- **[P]**: can run in parallel (different files, no dependency on an incomplete task)
- **[Story]**: US1, US2, US3 (spec.md)
- **[FR-###]**: requirement(s) satisfied; every FR-001 … FR-013 appears at least once

---

## Phase 1: Setup

**Purpose**: confirm a green baseline. No new dependency, tooling, or migration is needed.

- [X] T001 Run `docker compose exec app composer check` and record the baseline counts (last full run: 459 unit + 254 integration, all passing) in `specs/005-stock-movement-chart/implementation-log.md` (create it with a session header: date, checkpoint HEAD, planned files from plan.md) before any change; stop if it is not green

---

## Phase 2: Foundational — daily totals from the ledger · Bolt 1

**Purpose**: the one aggregation query every story reads (research R-002). Blocks all user stories.

**Independent test**: on real MySQL, ledger rows of every type on two days produce per-day in/out totals equal to
the stock movement report's positive/negative sums for those days.

### Tests for Bolt 1

- [X] T002 [FR-003] [FR-004] [FR-013] Add a "Stock movement chart" section to `tests/Integration/DashboardReportConsistencyTest.php`: append ledger rows on two different days — a Receipt (+), an Issue (−), a positive Adjustment and a negative Adjustment — and assert that `MysqlStockLedgerRepository::dailyMovementTotals($start, $end)` returns, for each day, `in` = sum of positive and `out` = sum of |negative| quantities, that it equals the same sums computed from `movementsBetween()` for that single day, that a day without movement is absent from the result, and that rows of a **deactivated** product still count (spec edge case; no join to product). The existing helper `appendLedgerRow()` always stamps `NOW()`, so add a private helper that inserts a ledger row with an explicit `created_at` through `$this->pdo` (INSERT is allowed by the append-only triggers; the test transaction rolls it back), and use two fixed past dates (e.g. 2025-01-10 and 2025-01-11) that no other test writes to; must fail (method missing)

### Implementation for Bolt 1

- [X] T003 [FR-003] Declare `dailyMovementTotals(string $startDate, string $endDate): array` with return shape `array<string, array{in: int, out: int}>` (keyed `Y-m-d`, ascending, only days with movement) and an Indonesian docblock stating the range rule is identical to `movementsBetween()` in `app/Repository/StockLedgerRepositoryInterface.php`
- [X] T004 [FR-003] [FR-004] Implement `dailyMovementTotals()` in `app/Repository/Mysql/MysqlStockLedgerRepository.php` as one query: `SELECT DATE(created_at) AS day, SUM(CASE WHEN quantity > 0 THEN quantity ELSE 0 END) AS units_in, SUM(CASE WHEN quantity < 0 THEN -quantity ELSE 0 END) AS units_out FROM stock_ledger WHERE created_at >= :start_date AND created_at < (:end_date + INTERVAL 1 DAY) GROUP BY DATE(created_at) ORDER BY day`; cast results to `int`, key by `day`
- [X] T005 [P] [FR-003] [FR-004] Implement `dailyMovementTotals()` in `tests/Unit/Fake/InMemoryStockLedgerRepository.php` from its entries and `createdAt` timestamps, with the same inclusive date rule as its `movementsBetween()`, split by the sign of `quantity`, ascending by date

**Checkpoint (Bolt 1)**: T002 green; `composer check` green. Propose commit
`feat: add daily stock movement totals to the ledger repository`.

---

## Phase 3: User Story 1 — See daily stock in and out at a glance (Priority: P1) 🎯 MVP

**Goal**: Admin and Warehouse Staff dashboards show 30 days of in/out bars and period totals; Sales does not.

**Independent test**: as `warehouse1`, receive 5 units; reload the dashboard; today's In and the period's Units in
each grow by exactly 5. As `sales1`, the card is absent.

### US1 · Bolt 2: service figure

- [X] T006 [US1] [FR-001] [FR-004] [FR-005] [FR-006] [FR-007] [FR-011] Add a "stock movement" section to `tests/Unit/Service/DashboardServiceTest.php` (update `setUp()` to construct `DashboardService` with an `InMemoryStockLedgerRepository` and a `FixedClock`): (a) `adminFigures()['stockMovement']['days']` has exactly 30 consecutive dates, oldest first, `start` = today − 29 and `end` = today from the clock (DM-1); (b) days without entries are `in = 0, out = 0`; (c) a Receipt of 5 and an Issue of 3 on one day give `in 5 / out 3`; (d) a +4 and a −2 Adjustment count as `in 4 / out 2` (Clarification Q2); (e) `totalIn`, `totalOut`, `net` equal the sums (DM-2) and `net` equals the sum of all ledger quantities in the window (DM-4); (f) entries outside the window are ignored; (g) `warehouseFigures()['stockMovement']` equals the Admin figure; (h) `salesFigures()` has no `stockMovement` key (DM-5); (i) an empty ledger gives 30 zero days and totals 0; (j) the fake ledger counts calls to `dailyMovementTotals()` — exactly **one** per `adminFigures()` / `warehouseFigures()` call (NFR-001) and **zero** for `salesFigures()` (FR-011). Must fail first
- [X] T007 [US1] [FR-001] [FR-003] [FR-004] [FR-005] [FR-006] [FR-007] [FR-011] In `app/Service/DashboardService.php`: add constructor parameters `StockLedgerRepositoryInterface $ledger` and `ClockInterface $clock` (after the existing three), constant `MOVEMENT_DAYS = 30`, and private `stockMovement(): array` that computes the window from the clock (same arithmetic as `ReportService::defaultRange()`), calls `dailyMovementTotals()` **once**, zero-fills 30 days, and returns `start`, `end`, `days`, `totalIn`, `totalOut`, `net`; add it under key `stockMovement` in `adminFigures()` and `warehouseFigures()` only; extend both `@return` shapes and the class docblock (ledger is read, Sales never)
- [X] T008 [US1] Wire the new arguments: pass `$stockLedgerRepository` and `$clock` to `DashboardService` in `config/container.php`, and update its construction in `tests/Integration/DashboardReportConsistencyTest.php` (after T002, same file)

**Checkpoint (Bolt 2)**: unit + integration green.

### US1 · Bolt 3: the chart

- [X] T009 [P] [US1] [FR-002] Create `tests/Unit/Support/BarChartScaleTest.php`: axis maximum rounds up to 1, 2 or 5 × 10ⁿ (e.g. 7 → 10, 12 → 20, 450 → 500, 0 → 1); four gridline steps `axisMax × k / 4`; bar height proportional to `value / axisMax × plotHeight`; `0` → 0; any non-zero value → at least 1; the largest value never exceeds `plotHeight`. Must fail first
- [X] T010 [P] [US1] [FR-002] [FR-012] Create `app/Support/BarChartScale.php` (final, pure, no I/O): constructor `(int $maxValue, int $plotHeight)`, methods `axisMax(): int`, `gridlines(): list<int>` (5 values, 0 … axisMax), `barHeight(int $value): int`; Indonesian docblock stating it holds presentation geometry only, no business rule (plan Complexity Tracking)
- [X] T011 [US1] [FR-002] [FR-005] [FR-006] [FR-007] [FR-009] [FR-012] Create `views/dashboard/_movement-chart.php` (receives `$movement` = the figure, `$view`): card `card-header` with title "Stock movement — last 30 days"; when `totalIn + totalOut === 0` render `layout/_empty-state` (icon `warehouse`, heading "No stock moved in the last 30 days", text "Receipts, issues and adjustments will appear here as they are recorded.", empty action) and no chart; otherwise a summary row (Units in · Units out · Net change with an explicit sign), a legend (In / Out with labels), and an inline `<svg viewBox="…">` drawn with `BarChartScale`: 4 gridlines with values, 30 equal day slots each with the In bar left and the Out bar right, date labels (`j M`) on every 5th slot and on the last slot; every printed value via `View::e()` or `(int)`
- [X] T012 [US1] [FR-001] [FR-011] Render the partial in `views/dashboard/admin.php` (after "Purchase orders by status", before "Products needing attention") and in `views/dashboard/warehouse.php` (after "Issue queue", before "Products needing attention"), passing `$figures['stockMovement']`; extend both `@var` shapes. Do not touch `views/dashboard/sales.php`
- [X] T013 [P] [US1] [FR-002] Add chart styles to `public/assets/css/app.css` near the other dashboard styles: `.movement-chart` (`width: 100%; height: auto; display: block`), bar fills `var(--success)` / `var(--danger)`, gridlines `var(--border-default)`, axis text `var(--text-muted)` at `--text-xs`, summary row reusing existing spacing tokens, net change `--success-text` / `--danger-text`; no horizontal overflow at 360 px (NFR-003)
- [X] T014 [US1] [FR-001] [FR-009] [FR-011] Create `tests/Integration/DashboardChartRenderTest.php` (render through `DashboardController` with the real `View`, session set as in `OrderControllerHarness`/`ProfileFlowTest`, services on `$this->database`): Admin and Warehouse Staff pages contain "Stock movement — last 30 days" and the SVG; a ledger row written today appears in the summary totals; the Sales page contains neither the card title nor `movement-chart`; with no ledger rows in the window the empty-state heading is shown and no `<svg class="movement-chart"` — make the empty window **explicit** rather than relying on the state of `ioms_test`: build that `DashboardService` with a `FixedClock` set to a date long before any fixture (e.g. 2000-01-31), so its window is guaranteed empty

**Checkpoint (Bolt 3)**: `composer check` green; dashboard opened in a browser for Admin, Warehouse Staff and Sales.
Propose commit `feat: show a 30-day stock movement chart on the admin and warehouse dashboards`.

---

## Phase 4: User Story 2 — Read the exact figure for one day (Priority: P2) · Bolt 4

**Goal**: exact figures on hover and keyboard focus, a text alternative, and proof the chart equals the CSV.

**Independent test**: hover, or Tab to, a day with a known receipt; its date and exact in/out are shown; Tab skips
empty days; the screen-reader table lists all 30 rows.

- [X] T015 [US2] [FR-008] Extend `tests/Integration/DashboardChartRenderTest.php`: the `<svg>` has `role="img"` and an `aria-label` containing the period totals; there are 30 day slots, each with a `<title>` of the form "{j M Y} — In {in} · Out {out}", and `tabindex="0"` appears **only** on slots with movement (count equals the number of days with in + out > 0); a `<table class="visually-hidden">` has 30 body rows with Date · In · Out matching the figure
- [X] T016 [US2] [FR-008] In `views/dashboard/_movement-chart.php`: wrap each day slot in `<g class="chart-day">` with `<title>`, adding `tabindex="0"` only when that day's in + out > 0 (analysis A1), add a hidden tip `<text class="chart-tip">` with the same text, set `role="img"` and the `aria-label` summary on the `<svg>`, and render the visually hidden data table after the chart
- [X] T017 [P] [US2] [FR-008] In `public/assets/css/app.css`: visible focus outline for `.chart-day:focus` (using the existing focus-ring token or `--accent`), and `.chart-tip { visibility: hidden }` revealed on `.chart-day:hover` / `.chart-day:focus`; colour is never the only signal (legend text + bar position)
- [X] T018 [US2] [FR-013] Extend the stock movement section of `tests/Integration/DashboardReportConsistencyTest.php`: write ledger rows **today** (via `appendLedgerRow()` / `NOW()`, including a +Adjustment and a −Adjustment through `StockService::adjustStock()` or a direct insert) — the T002 rows sit on fixed past dates outside the 30-day window — then, for the window, `DashboardService::adminFigures()['stockMovement']` daily figures equal the positive/negative sums of `ReportService::stockMovements()` rows grouped by day, and `net` equals the sum of all exported quantities

**Checkpoint (Bolt 4)**: `composer check` green; keyboard-only check in a browser. Propose commit
`feat: make stock movement chart figures readable on hover, focus and screen readers`.

---

## Phase 5: User Story 3 — Go from the chart to the detail (Priority: P3) · Bolt 5

**Goal**: one link from the card to the Reports page with the same range.

**Independent test**: follow the link; Reports opens with From = today − 29 and To = today.

- [X] T019 [US3] [FR-010] Extend `tests/Integration/DashboardChartRenderTest.php`: the card contains a link `href="/reports?start_date={start}&amp;end_date={end}"` with text "View stock movement report", in both the populated and the empty state
- [X] T020 [US3] [FR-010] In `views/dashboard/_movement-chart.php`: add the right-aligned `btn btn--ghost btn--sm` link "View stock movement report" in the card header, built with `http_build_query(['start_date' => …, 'end_date' => …])` and escaped with `View::e()`

**Checkpoint (Bolt 5)**: `composer check` green. Propose commit `feat: link the stock movement chart to the report`.

---

## Phase 6: Polish & Cross-Cutting Concerns · Bolt 6

- [X] T021 Run `docker compose exec app composer check` and `composer test:coverage`; confirm PHPStan 0, PHPCS 0, and that `BarChartScale`, `DashboardService` and the new repository method are covered; record counts in `specs/005-stock-movement-chart/implementation-log.md`
- [X] T022 [P] Update `docs/architecture/class-diagram-as-built.md`: `DashboardService` now depends on `StockLedgerRepositoryInterface` and `ClockInterface`; new `dailyMovementTotals()`; new `App\Support\BarChartScale`
- [X] T023 [P] Update `docs/brd/modules/dashboard.md` (new capability: stock movement chart for Admin and Warehouse Staff, consumes `stock`; screens; test coverage; Change Log 2026-10-04) and the Consumes list of `docs/brd/modules/stock.md` if it lists consumers
- [X] T024 [P] Record the bonus: add the chart and the extra integration tests as **bonus** items in `README.md` (features table or a short "Bonus" note) and update `docs/testing/test-results.md` (counts, new tests) and `docs/testing/use-case-coverage.md` (row for the chart)
- [X] T025 Manual verification per `specs/005-stock-movement-chart/quickstart.md` §1–§6 against the running stack with headless Chrome at 1280 px and 360 px (SC-006: no horizontal page scroll); refresh the dashboard screenshots used by the SOP (scratchpad, not committed) and regenerate `SOP-Penggunaan-IOMS.pdf` as v1.4 with the chart described for Admin and Warehouse Staff
- [X] T026 Final scope check: `git status` shows only the files listed in plan.md plus docs; mark tasks done here; close the session in `implementation-log.md`; propose commit messages (do not commit)

---

## Requirement Coverage

| Requirement | Tasks |
| --- | --- |
| FR-001 | T006, T007, T012, T014 |
| FR-002 | T009, T010, T011, T013 |
| FR-003 | T002, T003, T004, T005, T007 |
| FR-004 | T002, T004, T005, T006, T007 |
| FR-005 | T006, T007, T011 |
| FR-006 | T006, T007, T011 |
| FR-007 | T006, T007, T011 |
| FR-008 | T015, T016, T017 |
| FR-009 | T011, T014 |
| FR-010 | T019, T020 |
| FR-011 | T006, T007, T012, T014 |
| FR-012 | T010, T011 |
| FR-013 | T002, T018 |
| NFR-001 (one query) | T004, T007 |
| NFR-002 (accessibility) | T013, T016, T017 |
| NFR-003 (360 px) | T013, T025 |
| NFR-004 (read only) | T004, T007 (no write path), T021 |
| SC-001 | T011, T012, T025 |
| SC-002 | T014, T025 |
| SC-003 | T002, T018 |
| SC-004 | T006 |
| SC-005 | T006, T014 |
| SC-006 | T013, T025 |
| SC-007 | T010, T021 |
| Edge case "deactivated product or warehouse" | T002 (by design: no join, R-002) |

Analysis remediation (2026-10-04): U1/U2 (UTC day boundary, relative seed dates) in spec A-002, research R-005,
quickstart; A1 (tab stops only on days with movement) in FR-008, R-006, contract, T015/T016; C1 → T006 (j);
C2 → T002; I1 card title in spec; I2 → T018; I3 → T024; D1 FR-013 note; T1 → T014.

---

## Dependencies & Execution Order

- **Phase 1** → **Phase 2 (Bolt 1)** → **Phase 3 (Bolt 2 → Bolt 3)** → **Phase 4 (Bolt 4)** → **Phase 5 (Bolt 5)** →
  **Phase 6 (Bolt 6)**
- US2 and US3 depend on US1's partial (`_movement-chart.php`) and render test.
- Same-file chains: `DashboardReportConsistencyTest.php` (T002 → T008 → T018), `_movement-chart.php`
  (T011 → T016 → T020), `DashboardChartRenderTest.php` (T014 → T015 → T019), `app.css` (T013 → T017),
  `DashboardService.php` (T007), `DashboardServiceTest.php` (T006).

### Parallel Opportunities

- Bolt 1: T005 alongside T003/T004 (different files) once T002 is red.
- Bolt 3: T009/T010 (helper and its test) and T013 (CSS) in parallel with each other; T011 needs T010; T012 needs T011.
- Bolt 6: T022, T023, T024 are independent documentation files; T025 needs the running stack; T026 is last.

## Implementation Strategy

### MVP first

Phases 1–3 deliver the chart for Admin and Warehouse Staff. Stop at the Bolt 3 checkpoint and demo quickstart §1–§2.

### Incremental delivery (one Bolt at a time; commit only when asked)

1. Bolt 1 → `feat: add daily stock movement totals to the ledger repository`
2. Bolt 2–3 → `feat: show a 30-day stock movement chart on the admin and warehouse dashboards`
3. Bolt 4 → `feat: make stock movement chart figures readable on hover, focus and screen readers`
4. Bolt 5 → `feat: link the stock movement chart to the report`
5. Bolt 6 → `docs: stock movement chart evidence, diagrams and bonus note`
