# Research: Stock Adjustment

**Feature**: `003-stock-adjustment` · **Date**: 2026-10-03 · **Spec**: [spec.md](./spec.md)

No `NEEDS CLARIFICATION` was left in the spec: the owner settled roles, the reason field, and the
counted-quantity input before specification, and accepted assumptions A-004 to A-007 by proceeding
to planning. The decisions below resolve the technical unknowns found while reading the code.

## R-001 — Storing the reason (approved schema deviation)

**Decision**: New migration `database/004_ledger_note.sql`:

```sql
ALTER TABLE `stock_ledger`
    ADD COLUMN `note` VARCHAR(255) NULL AFTER `reference_id`,
    ADD CONSTRAINT `ck_ledger_note_adjustment`
        CHECK ((`movement_type` = 'Adjustment') = (`note` IS NOT NULL)),
    ADD CONSTRAINT `ck_ledger_adjustment_manual`
        CHECK ((`movement_type` = 'Adjustment') = (`reference_type` = 'Manual'));
```

**Rationale**: The brief's StockLedger has no reason attribute; the owner approved one nullable column
(spec A-002). The first CHECK makes "reason required for Adjustment, absent for Receipt/Issue" a
database rule, not only an application convention (the same lesson as tech-debt TD-9). The second
CHECK pins the pairing the spec defines: an Adjustment always references `Manual`, and `Manual` is only
used by Adjustment. The existing `ck_ledger_reference_id` (Manual ⇔ `reference_id IS NULL`) still
applies. Existing rows are all Receipt/Issue with a NULL note, so both CHECKs pass on a live database.

A new file is required because applied migrations are never edited (`003_date_indexes.sql` header).
`database/migrate.php` already applies new `*.sql` files in order, and the container entrypoint runs it
on start.

**Alternatives considered**:
- A separate `stock_adjustment` table holding the reason → a second table for one attribute, plus a
  join on every history read; rejected (owner directive from 002: no new table without a strong reason).
- Encode the reason elsewhere (e.g. a log file) → not traceable from the ledger row; rejected.
- No CHECK, application-only rule → the ledger's integrity rules already live in the database
  (`ck_ledger_quantity_nonzero`, `ck_ledger_reference_id`); rejected for consistency.

## R-002 — Locking when the stock row may not exist yet

**Decision**: Inside the transaction, first **ensure the `product_stock` row exists** (insert quantity 0
if missing, no-op otherwise), then `SELECT … FOR UPDATE` it, then read the current quantity under the
lock. Add `ProductStockRepositoryInterface::ensureRow(int $productId, int $warehouseId): void`, and make
`adjust()` call it instead of repeating the same `INSERT … ON DUPLICATE KEY UPDATE id = id` (Boy Scout).

**Rationale**: Spec acceptance scenario US1-3 corrects a warehouse where the product was never stocked.
With no row, `FOR UPDATE` takes only an InnoDB gap lock. Gap locks do not conflict with each other, so
two concurrent corrections would both read 0, both pass the stale check, and then collide on insert —
a deadlock error surfacing as a 500 instead of a clean "stock changed" refusal. Inserting a zero row
first turns the case into the normal single-row lock used by goods issue (ADR-002). A zero row keeps the
invariant: the ledger sum for a pair with no movements is 0.

Lock order: an adjustment locks exactly one `product_stock` row and no order row, so it cannot form a
cycle with goods issue (order → stock rows in `product_id, warehouse_id` order) or goods receipt.

**Alternatives considered**:
- Lock the product or warehouse row instead → serialises unrelated pairs and departs from ADR-002;
  rejected.
- Catch deadlock and retry → hides the race instead of removing it; rejected.

## R-003 — Stale-count protection (spec FR-004, A-004)

**Decision**: The form carries the system quantity the user was shown as a hidden field
`expected_quantity`. Under the lock, if the current quantity ≠ `expected_quantity`, the Service throws
`ValidationException(['stock' => 'The stock in this warehouse changed to N while you were counting.
Check your count and submit again.'])`. The controller re-renders the form (HTTP 422) with the fresh
system quantity, so the hidden field now carries the new value; resubmitting is a deliberate act.

**Rationale**: "Set to counted" would silently erase a goods issue that happened during the count;
"apply the difference seen" would leave stock at a number nobody counted. Refusing is the only option
that never produces a wrong figure. It reuses the existing exception and form-error rendering — no new
exception class (C-003).

**Alternatives considered**: a version column on `product_stock` (optimistic locking) → schema change
for what a compare under the existing lock already gives; rejected.

## R-004 — Where the rule lives and its signature

**Decision**: `StockService::adjustStock(int $productId, array $input, User $actor): array{before: int,
after: int, delta: int}` — the only path for a correction, inside `TransactionRunner`. `$input` is the raw
request body (`warehouse_id`, `counted_quantity`, `expected_quantity`, `note` as submitted strings), the
same pattern as `PurchaseOrderService::create(array $data)`, so format errors such as `2.5` or an empty
field reach the `Validator` instead of being lost in an `(int)` cast in the controller
(`/rudis.analyze` finding U1). It:

1. refuses actors that are not Admin or Warehouse Staff (`ForbiddenException`) — defence in depth
   behind the route table, and unit-testable (constitution V);
2. validates input (`Validator`): `warehouse_id` required whole number; `counted_quantity` required whole
   number ≥ 0; `expected_quantity` required whole number ≥ 0; `note` required, ≤ 255 characters after
   trimming → one `ValidationException` with field messages; only then casts to `int`;
3. loads the product (`NotFoundException` if missing; inactive products allowed, A-006) and the
   warehouse (must exist and be active, else field error `warehouse_id`);
4. transaction: `ensureRow` → `lockForUpdate` → stale check (R-003) → zero-difference check
   (`ValidationException(['counted_quantity' => 'The count matches the system quantity — nothing to
   adjust.'])`) → `ledger->append(StockLedger::adjustment(...))` → `stocks->adjust(delta)`.

`StockService` gains one dependency, `WarehouseRepositoryInterface`, for step 3.

**Rationale**: Constitution IV and CLAUDE.md make `StockService` the single place stock changes. The
counted quantity is ≥ 0, so the result is never negative; the existing `quantity >= 0` CHECK stays the
last safety net. Returning before/after/delta lets the controller build the confirmation ("Warehouse A:
12 → 9 (−3)") without a second read.

**Alternatives considered**:
- A new `StockAdjustmentService` → a second class able to change stock, exactly what the SRP audit note
  in `refactor-log.md` rejected for issue/receipt; rejected.
- Warehouse check in the controller via `MasterDataService` → a business rule outside the Service;
  rejected.

## R-005 — `StockLedger` entity

**Decision**: Add `public readonly ?string $note = null` as the last constructor parameter and a factory
`StockLedger::adjustment(int $productId, int $warehouseId, int $delta, string $note, int $performedBy)`
that sets `MovementType::Adjustment`, `ReferenceType::Manual`, `referenceId = null`, and keeps the sign
of `$delta`. `MysqlStockLedgerRepository::append()` writes `note`; `hydrate()` reads it.

**Rationale**: Mirrors the existing `receipt()` / `issue()` factories. The default keeps every existing
call site unchanged.

## R-006 — Showing recent corrections (FR-009)

**Decision**: `StockLedgerRepositoryInterface::recentAdjustmentsForProduct(int $productId, int $limit):
list<array{createdAt: string, warehouseName: string, quantity: int, balanceAfter: int, performedByName:
string, note: string}>`. MySQL computes `balanceAfter` with
`SUM(quantity) OVER (PARTITION BY warehouse_id ORDER BY created_at, id)` over the product's full
history, then keeps only Adjustment rows, newest first, `LIMIT 10`. Exposed as
`StockService::recentAdjustments(int $productId)`; `ProductController` receives `StockService` to
render the card, and calls it **only** for Admin and Warehouse Staff (spec FR-009, A-009) — Sales neither
sees the card nor triggers the query.

**Rationale**: "Resulting quantity" is not stored; deriving it from the ledger is exact by the
invariant. MySQL 8.0 supports window functions. A read-model array follows `movementsBetween()`.

**Alternatives considered**: store `balance_after` on each row → a second schema change and a value
that duplicates the invariant; rejected.

## R-007 — CSV export (FR-010, NFR-001)

**Decision**: `movementsBetween()` also selects `sl.note`; `ReportService::STOCK_MOVEMENT_FIELDS` gains
`note` and `STOCK_MOVEMENT_HEADER` gains `Reason`, as the last column. Cells already pass through
`cell()` → `neutralizeFormula()` (CWE-1236), so a reason starting with `=`, `+`, `-`, `@` is prefixed
with `'` in the file.

**Rationale**: Appending a column at the end keeps existing column positions for anyone reading the
file by index. No new escaping code is needed.

## R-008 — Controller and routes

**Decision**: New `StockAdjustmentController` with `create()` and `store()`, mirroring the order
controllers (constructor: `View`, `StockService`, `ProductService`, `MasterDataService`, `UserService`, `Session`,
`Csrf`). Routes, roles `$adminWarehouse`:

- `GET /products/{id}/adjust-stock?warehouse_id=` — form; warehouse defaults to the first active one
  by name; shows that warehouse's system quantity.
- `POST /products/{id}/adjust-stock` — record.

Changing the warehouse is a plain GET form (no JavaScript needed).

**Rationale**: `ProductController` is Admin-oriented (`canManage` = Admin) and already has six
dependencies; a dedicated controller keeps it unchanged except for the history card. The URL nests
under the product because a correction is always for one product.

**Alternatives considered**: `/stock-adjustments/create?product_id=` → a top-level resource with no
list page; rejected.

## R-009 — UI and the difference preview

**Decision**: Reuse the design system: `page-header`, a product summary `stat-grid`, a `card` with
`form-grid`, `field-label field-required`, `field-error`, `alert--error`, `btn--primary`. On product
detail: an "Adjust stock" button in the header for Admin and Warehouse Staff, and a "Stock adjustments"
card under "Stock by warehouse" with `+`/`−` change coloured via existing tokens and an empty state.
**No live JavaScript preview** of the difference: the spec lists it as an optional progressive
enhancement, and the server-side confirmation already states the exact change.

**Rationale**: C-003 — one more JS module adds tests and maintenance for a value the user sees in the
confirmation a second later.

## R-010 — Security standard §2 / §7

| Item | Decision |
| --- | --- |
| Authentication | Session required on both routes; the FR-012 (002) per-request account re-check applies |
| Authorization | Route roles Admin + Warehouse Staff; Sales → 403 at the guard; `StockService` also refuses (R-004 step 1). Adjustment history on product detail rendered for Admin + Warehouse Staff only (A-009) |
| CSRF | Existing front-controller check on POST → 403 |
| Input | Whole number ≥ 0, note 1–255 after trim, warehouse active; all server-side, prepared statements |
| Output | Reason rendered with `View::e()`; CSV neutralised (R-007) |
| Integrity | One transaction, row lock, stale check, DB CHECKs (R-001), append-only ledger (no update/delete path added) |
| Rate limit | Not needed: authenticated staff action with no secret to guess |
| Logging | No reason text or quantities written to `error_log` |
