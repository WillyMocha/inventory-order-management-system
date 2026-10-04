# Data Model: Stock Adjustment

**Feature**: `003-stock-adjustment` · **Date**: 2026-10-03
**Source model**: [`../001-inventory-order-management/inputs/project-brief-resource-model.md`](../001-inventory-order-management/inputs/project-brief-resource-model.md)
(StockLedger, ProductStock) and the base schema in
[`../001-inventory-order-management/data-model.md`](../001-inventory-order-management/data-model.md).

**One schema change**: column `stock_ledger.note` plus two CHECK constraints, in a new migration
`database/004_ledger_note.sql`. No new table.

## Data Design Decisions

| # | Decision | Source says | Deviation? | Approved by | Mapping |
| --- | --- | --- | --- | --- | --- |
| D-1 | Add `stock_ledger.note VARCHAR(255) NULL` | StockLedger attributes: product, warehouse, movement type, quantity, reference (PO/SO id), performed by, timestamp — **no reason** | **Yes — attribute added** | Owner, 2026-10-03 (spec A-002) | Lossless: every source attribute keeps its column; `note` is extra and NULL for Receipt/Issue |
| D-2 | `CHECK ((movement_type = 'Adjustment') = (note IS NOT NULL))` | — | No (constraint only) | — | Reason required exactly for Adjustment |
| D-3 | `CHECK ((movement_type = 'Adjustment') = (reference_type = 'Manual'))` | Movement types Receipt/Issue/Adjustment; reference PO/SO id | No (constraint only) | — | Adjustment has no order reference; Manual is used only by Adjustment |
| D-4 | First use of `movement_type = 'Adjustment'` and `reference_type = 'Manual'` | Enumeration includes Adjustment | No — both values already in `001_schema.sql` | — | — |

## Entities touched

### stock_ledger (source: StockLedger) — changed

| Column | Type | Null | Source attribute | Change |
| --- | --- | --- | --- | --- |
| id | BIGINT UNSIGNED PK | no | (operational) | — |
| product_id | BIGINT UNSIGNED FK → product | no | Produk | — |
| warehouse_id | BIGINT UNSIGNED FK → warehouse | no | gudang | — |
| movement_type | ENUM('Receipt','Issue','Adjustment') | no | tipe pergerakan | — |
| quantity | INT, CHECK ≠ 0 | no | quantity | signed: + receipt / − issue / ± adjustment |
| reference_type | ENUM('PurchaseOrder','SalesOrder','Manual') | no | referensi | — |
| reference_id | BIGINT UNSIGNED | yes (only when Manual) | referensi (PO/SO id) | — |
| **note** | **VARCHAR(255)** | **yes** | — (D-1) | **new** |
| performed_by | BIGINT UNSIGNED FK → user | no | dilakukan oleh | — |
| created_at | DATETIME | no | timestamp | — |

Constraints after this feature: `ck_ledger_quantity_nonzero`, `ck_ledger_reference_id` (existing),
`ck_ledger_note_adjustment` (D-2), `ck_ledger_adjustment_manual` (D-3).

Append-only: no UPDATE or DELETE path is added (FR-007).

### product_stock (source: ProductStock) — unchanged schema

| Rule | Where enforced |
| --- | --- |
| `quantity >= 0` | existing CHECK; adjustment result = counted quantity ≥ 0 |
| One row per (product, warehouse) | existing UNIQUE; adjustment may create the row with 0 before locking (research R-002) |
| `quantity = SUM(stock_ledger.quantity)` for the pair | `StockService` writes both in one transaction; `LedgerReconciliationTest` |

## Validation rules (from spec FR-002 … FR-004)

| Field | Rule | Message |
| --- | --- | --- |
| `warehouse_id` | required; whole number; existing and active warehouse | "Warehouse is required." / "Choose an active warehouse." |
| `counted_quantity` | required; whole number ≥ 0 | "Counted quantity is required." / "Counted quantity must be a whole number." / "Counted quantity must be 0 or greater." |
| `counted_quantity` | ≠ current system quantity | "The count matches the system quantity — nothing to adjust." |
| `note` | required; 1–255 characters after trim | "Reason is required." / "Reason must not exceed 255 characters." |
| `expected_quantity` (hidden) | required whole number ≥ 0; must equal the quantity under lock | key `stock`: "The stock in this warehouse changed to N while you were counting. Check your count and submit again." |

## Read model: recent adjustments (FR-009)

`StockLedgerRepositoryInterface::recentAdjustmentsForProduct(int $productId, int $limit)` returns rows of
`{createdAt, warehouseName, quantity, balanceAfter, performedByName, note}`; `balanceAfter` is the running
ledger sum for that warehouse up to and including the row (research R-006).

## Conformance checklist (against the source model)

- [x] Every StockLedger source attribute still maps to the same column, same type and cardinality.
- [x] Movement-type and reference enumerations unchanged (Receipt/Issue/Adjustment; PO/SO/Manual).
- [x] ProductStock unchanged.
- [x] The only added attribute (`note`) is recorded as an approved deviation (D-1) and is lossless.
- [x] No resource merged, renamed, or simplified.
