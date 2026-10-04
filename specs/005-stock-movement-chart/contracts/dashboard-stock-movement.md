# Contract: Dashboard stock movement card

**Feature**: `005-stock-movement-chart` · **Date**: 2026-10-04

## Routes

**No new route and no change to an existing one.** The card is part of the existing dashboard page.

| Method | Path | Auth | Role | Change |
| --- | --- | --- | --- | --- |
| GET | `/dashboard` | required (session) | A S W | Admin and Warehouse Staff views gain the stock movement card; the Sales view is unchanged |
| GET | `/reports?start_date=&end_date=` | required | A S W | none — target of the card's link; only Admin and Warehouse Staff ever see the link |

The JSON API (`contracts/openapi.yaml` of spec 001) is unchanged.

## Authorization

| Role | Sees the card | Ledger queried for them |
| --- | --- | --- |
| Admin | yes | yes |
| Warehouse Staff | yes | yes |
| Sales | **no** | **no** — `DashboardService::salesFigures()` never calls the ledger repository (FR-011) |

Enforced on the server by role dispatch in `DashboardService::forRole()` (each role gets its own figure set and its
own template). The Sales template has no reference to `stockMovement`; hiding is not the control, not computing is.

## Repository contract

```php
/**
 * Units in and out per calendar day, for days that have movement.
 * Range rule identical to movementsBetween(): created_at >= start AND created_at < end + 1 day.
 *
 * @return array<string, array{in: int, out: int}>  keyed by 'Y-m-d', ascending
 */
public function dailyMovementTotals(string $startDate, string $endDate): array;
```

Implemented by `MysqlStockLedgerRepository` (one `GROUP BY DATE(created_at)` query) and
`Tests\Unit\Fake\InMemoryStockLedgerRepository` (same rule over its entries).

## View-model contract (`$figures['stockMovement']`)

| Key | Type | Meaning |
| --- | --- | --- |
| `start` | `string` `Y-m-d` | first day of the window (today − 29) |
| `end` | `string` `Y-m-d` | today |
| `days` | `list<array{date: string, in: int, out: int}>` | 30 entries, oldest first, zero-filled |
| `totalIn` | `int` | Σ in |
| `totalOut` | `int` | Σ out |
| `net` | `int` | totalIn − totalOut |

## Rendered output (card)

| Element | Content |
| --- | --- |
| Card title | `Stock movement — last 30 days` |
| Header link | `View stock movement report` → `/reports?start_date={start}&end_date={end}` (`btn btn--ghost btn--sm`) |
| Summary row | `Units in` {totalIn} · `Units out` {totalOut} · `Net change` {±net} |
| Legend | `In` (success colour) · `Out` (danger colour) |
| Chart | `<svg role="img" aria-label="Stock movement, last 30 days: {totalIn} units in, {totalOut} units out">`; 30 day slots, each with `<title>` `{j M Y} — In {in} · Out {out}`; only slots with movement have `tabindex="0"` |
| Text alternative | `<div class="visually-hidden"><table>` with a caption and columns Date · In · Out, 30 rows (the wrapper carries the class: a `<table>` ignores its 1 px width) |
| Empty state (totalIn + totalOut = 0) | `_empty-state`: icon `warehouse`, heading `No stock moved in the last 30 days`, text `Receipts, issues and adjustments will appear here as they are recorded.`; no chart, link kept |

All dynamic text passes through `View::e()`; numbers are cast to `int` before printing.
