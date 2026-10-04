# Quickstart: Stock Movement Chart on the Dashboard

**Feature**: `005-stock-movement-chart` · **Date**: 2026-10-04

Manual verification against the Docker stack with the demo seed. All accounts use `Password123!`.

```bash
docker compose up -d
docker compose exec app composer check        # schema test, unit, integration, PHPStan, PHPCS
```

The seed writes its ledger rows **relative to the moment the database was first seeded**
(`DATE_SUB(NOW(), INTERVAL n DAY)`), so on a fresh install the last 30 days always contain several receipt and
issue days; movements from your own testing also show. Days are UTC calendar days (spec A-002): an entry made at
06:00 WIB belongs to the previous day, in the chart and in the CSV alike.

## 1. The chart is on the Admin and Warehouse Staff dashboards (US1)

1. Sign in as `admin@ioms.test`, open **Dashboard**. **Expect** a card "Stock movement — last 30 days" between
   "Purchase orders by status" and "Products needing attention", with a summary row (Units in · Units out · Net
   change), a legend (In / Out) and 30 day slots.
2. Sign in as `warehouse1@ioms.test`. **Expect** the same card with the same figures, before "Products needing
   attention".
3. Sign in as `sales1@ioms.test`. **Expect** no stock movement card (FR-011).

## 2. Figures follow the ledger (US1 scenario 3, SC-002)

1. Note today's In and Out (hover today's slot) and the totals.
2. As `warehouse1`, receive 5 units on an Ordered purchase order (or add +5 with **Adjust stock**).
3. Reload the dashboard. **Expect** today's In and the period's Units in each grown by exactly 5; Net change grown by
   5.
4. Record a goods issue (or an adjustment of −3). **Expect** today's Out grown by exactly that quantity.

## 3. Exact figures and accessibility (US2)

1. Hover a day with movement. **Expect** a tooltip "d Mon yyyy — In N · Out M".
2. Press **Tab** into the chart. **Expect** focus to move only between days that have movement, each with a visible
   outline and its figures shown; empty days are skipped.
3. With a screen reader (or the browser's accessibility tree), **expect** a summary label on the chart and a table
   Date · In · Out with 30 rows.

## 4. Chart equals the report (US2 scenario 3, SC-003)

1. Pick any day that has a bar; note its date, In and Out from the tooltip.
2. Click **View stock movement report**, set From and To to that same day, export the stock movement CSV.
3. **Expect** the sum of positive Quantity = the chart's In, the sum of negative Quantity (absolute) = the chart's Out.

## 5. Report link (US3)

1. Click **View stock movement report**. **Expect** the Reports page with From = today − 29 days and To = today.

## 6. Empty state and phone width

1. Empty state is covered by the unit test (no ledger rows in the window → empty state, no chart).
2. Narrow the browser to 360 px. **Expect** the chart scaled down inside its card and no horizontal page scroll.

## Automated evidence

| Test | Proves |
| --- | --- |
| `DashboardServiceTest` (stock movement section) | 30 zero-filled days, totals, adjustments by sign, window from the clock, Sales gets no figure |
| `BarChartScaleTest` | nice axis maximum, gridlines, bar heights, 1 px minimum |
| `DashboardReportConsistencyTest` (stock movement) | `dailyMovementTotals()` on real MySQL: adjustments split by sign; per day, chart in/out = CSV positive/negative sums |
| `DashboardChartRenderTest` (integration) | card rendered for Admin and Warehouse Staff, absent for Sales; empty state when no movement |
