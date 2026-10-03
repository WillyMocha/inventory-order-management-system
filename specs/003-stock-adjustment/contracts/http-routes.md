# HTTP Routes: Stock Adjustment

**Feature**: `003-stock-adjustment` · **Date**: 2026-10-03

Server-rendered HTML routes, added to `config/routes.php`. The global rules of
[`../../001-inventory-order-management/contracts/http-routes.md`](../../001-inventory-order-management/contracts/http-routes.md)
apply (deny by default, identity from the session, CSRF on non-GET, per-request account re-check).
No JSON endpoint is added.

Role abbreviations: **A** = Admin, **S** = Sales, **W** = WarehouseStaff.

## Routes

| Method | Path | Auth | Role | Authorization / notes |
| --- | --- | --- | --- | --- |
| GET | `/products/{id}/adjust-stock` | required | **A W** | S → 403. Unknown product → 404. Query `warehouse_id` selects the warehouse (default: first active warehouse by name); an inactive or unknown `warehouse_id` falls back to the default. Shows the system quantity for that warehouse |
| POST | `/products/{id}/adjust-stock` | required | **A W** | S → 403. CSRF (invalid → 403). Body below. Success → 302 `/products/{id}` with flash. Validation, zero difference, or stale quantity → 422 with the form re-rendered and the system quantity re-read |
| GET | `/products/{id}` (existing) | required | A S W | For A and W only: the "Adjust stock" button and the "Stock adjustments" card (10 most recent). S sees neither, and the history query is not run for S |
| GET | `/reports/stock-movement.csv` (existing) | required | A W (unchanged) | Adds a final `Reason` column; empty for Receipt and Issue; formula-neutralised |

## `POST /products/{id}/adjust-stock` body

| Field | Type | Required | Rule |
| --- | --- | --- | --- |
| `csrf_token` | string | yes | valid session token |
| `warehouse_id` | string (whole number) | yes | existing, active warehouse |
| `counted_quantity` | string (whole number) | yes | whole number ≥ 0; ≠ current system quantity |
| `expected_quantity` | string (whole number) | yes (hidden) | whole number ≥ 0; must equal the current system quantity under lock |

Fields arrive as submitted strings and are validated in `StockService` (research R-004); `2.5`, `abc`, or an
empty value is a field error, never silently truncated.
| `note` | string | yes | 1–255 characters after trim |

## Responses

| Case | Status | What the user sees |
| --- | --- | --- |
| Recorded | 302 → `/products/{id}` | Flash success: "Stock adjusted in {warehouse}: {before} → {after} ({±delta})." |
| Field validation failed | 422 | Summary alert + field errors; entered values kept |
| Zero difference | 422 | Field error on Counted quantity: "The count matches the system quantity — nothing to adjust." |
| Stale quantity | 422 | Alert: "The stock in this warehouse changed to N while you were counting. Check your count and submit again."; system quantity refreshed |
| Sales user | 403 | Standard error page (guard) |
| Not signed in | 302 → `/login` | — |
| Unknown product | 404 | Standard error page |

## Authorization matrix addition

| Capability | Admin | Sales | Warehouse Staff |
| --- | --- | --- | --- |
| Record a stock adjustment | ✅ | ❌ 403 | ✅ |
| See recent adjustments on product detail | ✅ | ❌ not shown | ✅ |
