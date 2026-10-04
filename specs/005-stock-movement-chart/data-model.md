# Data Model: Stock Movement Chart on the Dashboard

**Feature**: `005-stock-movement-chart` · **Date**: 2026-10-04

## Schema changes

**None.** No table, column, index, constraint, or migration is added. The schema stays a mirror of the brief's
resource model ([`../001-inventory-order-management/data-model.md`](../001-inventory-order-management/data-model.md)).
The feature only **reads** `stock_ledger`; it never writes it (NFR-004), and the ledger stays append-only
(`database/005_ledger_append_only.sql`).

The date-range query relies on the existing index on `stock_ledger.created_at` from
`database/003_date_indexes.sql`.

## Conformance check against the source

| Source resource (brief §1.3) | Table | Change |
| --- | --- | --- |
| StockLedger | `stock_ledger` | none — read only: `quantity` (signed) and `created_at` |

Nothing is added, renamed, merged, or dropped.

## Columns read

| Column | Use |
| --- | --- |
| `created_at` | assigns the movement to a calendar day; range filter `created_at >= start AND created_at < end + 1 day` — identical to the stock movement report |
| `quantity` | signed: `> 0` adds to that day's **in**, `< 0` adds its absolute value to that day's **out** |

`movement_type`, `product_id`, `warehouse_id`, `reference_*`, `performed_by` and `note` are not read: the chart is a
total across all products and warehouses, and the in/out split follows the sign (Clarification Q2), so a positive
Adjustment is "in" and a negative one is "out" without looking at the type.

## Derived structures (never stored)

### Daily movement totals — repository result

`StockLedgerRepositoryInterface::dailyMovementTotals(string $startDate, string $endDate)`

```text
array<string, array{in: int, out: int}>   // key: 'Y-m-d'; only days that have at least one movement
```

Rules: `in >= 0`, `out >= 0`. `StockService` never writes a zero-quantity movement (it skips zero receipt lines and
rejects a zero adjustment); a zero row, if one existed, would add nothing to either side.

### Stock movement figure — service result

`DashboardService::adminFigures()['stockMovement']` and `warehouseFigures()['stockMovement']`

```text
array{
    start: string,                                      // 'Y-m-d' = today − 29 days
    end: string,                                        // 'Y-m-d' = today
    days: list<array{date: string, in: int, out: int}>, // exactly 30 entries, oldest first, zero-filled
    totalIn: int,                                       // Σ days[].in
    totalOut: int,                                      // Σ days[].out
    net: int                                            // totalIn − totalOut
}
```

Invariants (unit-tested):

| # | Invariant | Spec |
| --- | --- | --- |
| DM-1 | `count(days) === 30`, consecutive dates, `days[0].date === start`, `days[29].date === end` | FR-001, FR-006 |
| DM-2 | `totalIn === Σ in`, `totalOut === Σ out`, `net === totalIn − totalOut` | FR-007, SC-004 |
| DM-3 | For each day: `in` = Σ positive ledger quantities that day; `out` = Σ |negative| quantities that day | FR-003, FR-004, FR-013 |
| DM-4 | `net` over the window = Σ ledger quantities in the window (net stock change) | FR-004 |
| DM-5 | `salesFigures()` has no `stockMovement` key and does not query the ledger | FR-011, SC-005 |

## Chart geometry — presentation only (never stored)

`App\Support\BarChartScale` turns the largest daily value into an axis maximum and bar heights. It holds no business
rule.

| Input | Output |
| --- | --- |
| `maxValue` (largest daily in or out) | `axisMax`: `maxValue` rounded up to 1, 2 or 5 × 10ⁿ (minimum 1) |
| `axisMax` | gridline values from 0 to `axisMax` in whole-number steps: 4 steps when the leading digit is 2 (20 → 0, 5, 10, 15, 20), otherwise 5 (10 → 0, 2, …, 10; 50 → 0, 10, …, 50); below 10 one step per unit (implementation log, Bolt 3) |
| `value`, `plotHeight` | bar height `value / axisMax × plotHeight`; `0` → 0, any non-zero value → at least 1 |
