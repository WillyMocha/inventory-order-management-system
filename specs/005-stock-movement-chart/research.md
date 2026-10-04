# Research: Stock Movement Chart on the Dashboard

**Feature**: `005-stock-movement-chart` · **Date**: 2026-10-04 · **Spec**: [spec.md](./spec.md)

No `NEEDS CLARIFICATION` remained in the spec (Q1–Q3 resolved 2026-10-04: 30 days, adjustments by sign, units). The
decisions below fit the feature into the existing code. Each was made against what the repository already does.

---

## R-001 — Where the figures are computed

**Decision**: In `DashboardService`. It gains two constructor dependencies — `StockLedgerRepositoryInterface` and
`ClockInterface` — and one private method, `stockMovement()`, whose result is added to `adminFigures()` and
`warehouseFigures()` under the key `stockMovement`. `salesFigures()` is unchanged and never calls it.

**Rationale**: `DashboardService` is already the single place every dashboard figure comes from, and its class rule
("no hand-written numbers; every figure is an aggregation") is exactly FR-003. Role scoping is already decided in
`forRole()` per role, not hidden in a view — so Sales simply never receives the key (FR-011), and a unit test can
assert that. The clock is injected the same way `ReportService` receives it, which keeps "today" deterministic in
unit tests (`FixedClock`).

**Alternatives rejected**:
- A new `StockMovementChartService` — a layer that solves no problem (C-003); it would duplicate the role dispatch.
- Reusing `ReportService::stockMovements()` and summing in PHP — reads every ledger row with four joins only to
  count them; violates NFR-001 (one summary query).

---

## R-002 — One aggregation query, same date rule as the report

**Decision**: New repository method
`StockLedgerRepositoryInterface::dailyMovementTotals(string $startDate, string $endDate): array`, returning only
days that have movement, keyed by `Y-m-d`, each `array{in: int, out: int}`. MySQL:

```sql
SELECT DATE(created_at) AS day,
       SUM(CASE WHEN quantity > 0 THEN quantity ELSE 0 END)  AS units_in,
       SUM(CASE WHEN quantity < 0 THEN -quantity ELSE 0 END) AS units_out
  FROM stock_ledger
 WHERE created_at >= :start_date AND created_at < (:end_date + INTERVAL 1 DAY)
 GROUP BY DATE(created_at)
```

**Rationale**:
- The `WHERE` predicate is **identical** to `movementsBetween()` used by the stock movement CSV. Same rows in, same
  day boundaries — which is what makes FR-013 / SC-003 (chart = CSV per day) hold by construction rather than by
  luck.
- Splitting by the **sign of the quantity**, not by movement type, implements Clarification Q2 directly: a positive
  Adjustment is "in", a negative one is "out", Receipts are always positive and Issues always negative. In − out
  over the period therefore equals the net stock change (FR-004).
- No joins: product, warehouse and user names are not needed. `003_date_indexes.sql` already indexes
  `stock_ledger.created_at`, so the range scan is cheap. One query per dashboard load (NFR-001).
- No migration and no new table: the summary is derived, never stored (spec Key Entities).

**Alternatives rejected**: `GROUP BY movement_type` — would need a mapping step and still could not split
Adjustments by sign; a stored daily summary table — denormalised data that could drift from the append-only ledger,
and a schema change for a bonus feature.

---

## R-003 — Filling empty days and the totals

**Decision**: `DashboardService::stockMovement()` builds the 30-day window from the clock (today and the 29 days
before it — same arithmetic as `ReportService::defaultRange()`), asks the repository once, then zero-fills every day
of the window in date order. It returns:

```text
array{
    start: string,               // Y-m-d, first day of the window
    end: string,                 // Y-m-d, today
    days: list<array{date: string, in: int, out: int}>,   // exactly 30 entries, oldest first
    totalIn: int,
    totalOut: int,
    net: int                     // totalIn - totalOut
}
```

**Rationale**: FR-006 (days without movement stay on the axis) and FR-007 (totals equal the sum of the bars) are
both properties of this array, so they are unit-tested on the service, not in the view. Zero-filling mirrors the
existing `zeroFilled()` for status tallies ("a status without rows is 0, not missing").

**Constant**: `DashboardService::MOVEMENT_DAYS = 30` (Clarification Q1). It is a separate constant from
`ReportService::DEFAULT_RANGE_DAYS` even though both are 30 today: one is a chart window, the other a form default,
and changing one must not silently change the other.

---

## R-004 — Drawing the chart: server-side inline SVG

**Decision**: The chart is an inline `<svg>` written by a view partial, `views/dashboard/_movement-chart.php`,
included by `admin.php` and `warehouse.php`. Geometry (bar heights, the y-axis scale and gridline values, which days
get an x-axis label) is computed by a small pure presentation helper, `App\Support\BarChartScale`, so it can be
unit-tested; the partial only loops and prints numbers through `View::e()`.

**Rationale**:
- FR-012 / SC-007 and the constitution forbid a charting library and any JS framework; inline SVG needs neither, no
  network request, and no JavaScript at all.
- Keeping arithmetic out of the template matches the project's existing split: `Money` and `Paginator` are small
  `App\Support` helpers that views call; business rules stay in services. The scale helper contains no business
  rule — only "how tall is a bar of N when the largest is M".
- One partial for two dashboards guarantees Admin and Warehouse Staff see the same chart (A-004).

**Alternatives rejected**:
- `<canvas>` + hand-written JS — needs JavaScript to show anything, gives screen readers nothing, and cannot be
  checked by a server-side test.
- All geometry inside the partial — untestable arithmetic in a template, the pattern the codebase avoids.
- CSS-only bars (`div` heights) — workable, but axes, gridlines and labels are clumsier than SVG and the brief names
  "SVG/canvas".

---

## R-005 — Scale and axis

**Decision**:
- **Y scale**: the axis maximum is the largest single daily figure (in or out) rounded **up** to a "nice" value
  (1, 2 or 5 × 10ⁿ), with 4 or 5 gridline steps chosen so every label is a whole number (20 → steps of 5;
  10 and 50 → five steps). A bar's height is `value / axisMax × plotHeight`; a non-zero
  value gets at least 1 px so it never disappears.
- **X axis**: 30 day slots of equal width; each slot holds two bars side by side — **In on the left, Out on the
  right** — so the series are distinguishable by position as well as colour (NFR-002). A date label (`d M`) is printed
  every 5th day and on the last day, to stay legible at 360 px.
- **Fixed coordinate system**: `viewBox` of fixed width and height; the SVG is styled `width: 100%; height: auto`,
  so it scales down to phone width without horizontal page scroll (NFR-003, SC-006).

**Day boundary**: a UTC calendar day — PHP runs in UTC and MySQL's `NOW()` uses the container's `SYSTEM` zone,
also UTC — identical to the report (spec A-002; analysis finding U1).

**Rationale**: a nice-rounded maximum gives readable gridline numbers; the 1 px minimum handles the edge case of one
very large day dwarfing the others (spec Edge Cases), while the exact numbers remain available (R-006).

---

## R-006 — Exact figures on hover and focus, and a text alternative

**Decision**:
- Each day slot is an SVG `<g>` with a `<title>` ("2 Oct 2026 — In 12 · Out 5"). The browser shows `<title>` as a
  tooltip on hover with no JavaScript. Only slots **with movement** get `tabindex="0"`: without JavaScript there is
  no roving tabindex, and 30 tab stops — most of them empty days — would make the dashboard tedious to cross by
  keyboard (analysis finding A1). Zero days stay readable in the hidden table below.
- For keyboard focus, the same text is rendered as a hidden label inside the chart that CSS reveals on
  `:hover` / `:focus` of its slot (`g:focus .chart-tip { visibility: visible }`), plus a visible focus outline.
- The `<svg>` has `role="img"` and an `aria-label` summarising the period ("Stock movement, last 30 days: 512 units in,
  296 out"). The full daily figures are also rendered as a table inside a `<div class="visually-hidden">` — the
  existing utility class, put on a wrapper because a `<table>` ignores its 1 px width and widened the page at 360 px —
  so screen-reader users can read every day (FR-008, US2 scenario 2).

**Rationale**: meets FR-008 without JavaScript; reuses the existing `.visually-hidden` utility; colour is never the
only signal (legend text + bar position).

---

## R-007 — States and placement

**Decision**:
- **Populated**: card "Stock movement — last 30 days" with a right-aligned `btn--ghost` link **View stock movement
  report** → `/reports?start_date={start}&end_date={end}`; body: a summary row (Units in · Units out · Net change),
  the legend, the chart.
- **Empty** (`totalIn + totalOut === 0`): the existing `layout/_empty-state` partial — icon `warehouse`, heading "No
  stock moved in the last 30 days", text "Receipts, issues and adjustments will appear here as they are recorded."
  — the chart is not drawn (FR-009).
- **Placement**: Admin — after "Purchase orders by status", before "Products needing attention". Warehouse Staff —
  after "Issue queue", before "Products needing attention" (spec Primary Interactions).
- Loading: not applicable (server-rendered). Error: the dashboard's existing safe error page; no partial chart.

**Rationale**: the card header + ghost link + `_empty-state` is exactly how the existing dashboard cards are built.
The report link uses query parameters the Reports page already accepts and validates; for both roles that see the
chart, the Reports page shows the stock movement export (route `stock-movement.csv` is Admin + Warehouse Staff).

---

## R-008 — Colours

**Decision**: In = `--success` (green), Out = `--danger` (red) from `tokens.css`, gridlines `--border-default`,
axis text `--text-muted`. Net change in the summary row uses `--success-text` when positive, `--danger-text` when
negative, neutral when zero, and always carries a sign (`+12`, `−5`).

**Rationale**: these are the app's existing status hues (spec Design Reference); the sign makes the net readable
without colour.

---

## R-009 — Proof that chart and CSV agree (FR-013, SC-003)

**Decision**: One integration test against MySQL, in `DashboardReportConsistencyTest` (which already proves the
dashboard and the exports agree): write ledger rows of all three types — including a positive and a negative
Adjustment — on two days, then assert for each day that `dailyMovementTotals()` equals the positive and negative
sums of `movementsBetween()` for that day, and that the dashboard's `stockMovement` totals equal them too.

**Rationale**: the property the spec cares about is "same numbers as the report"; testing it across the real SQL of
both queries is what catches a drift in either predicate. The in-memory fake gets the same method, computed from its
entries with the same date rule as its `movementsBetween()`.

---

## R-010 — What stays untouched

No route, controller logic, authorization rule, migration, JSON API, report or ledger write changes. The
`DashboardController` already passes `figures` to the view; the new key flows through unchanged. Only
`config/container.php` changes (two extra constructor arguments), plus the two dashboard views, one new partial,
CSS, and the helper.
