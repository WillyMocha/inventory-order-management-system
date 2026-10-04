# Research: Edit Draft Orders

**Feature**: `004-edit-draft-orders` · **Date**: 2026-10-04 · **Spec**: [spec.md](./spec.md)

No `NEEDS CLARIFICATION` remained in the spec (Q1–Q3 resolved 2026-10-04). The decisions below settle
how the feature fits the existing code. Each was made against what the repository already does.

---

## R-001 — Where the edit rules live

**Decision**: In the two existing services. `SalesOrderService` gains `update()` and `canEdit()`;
`PurchaseOrderService` gains `update()` and `canEdit()`. The acting user is passed as an argument.

**Rationale**: Constitution Principle I/V and CLAUDE.md: business rules and authorization belong in the
Service, enforced on the server, testable without a session. `canEdit()` is the single source for both
the save-time check and the detail page's Edit button, so the button can never offer what the save
refuses.

**Alternatives rejected**:
- A new `OrderEditService` — a layer that solves no problem (C-003); the rules depend on each service's
  existing helpers (`requireVisibleOrder()`, `validate()`).
- Computing the button flag inline in the controller, as `canSubmit`/`canDecide` do today — that
  duplicates the rule in an untested layer. The new rule is narrower than "who may create" (Q2/Q3), which
  is exactly where a duplicate would drift.

---

## R-002 — Permission rules (from Clarifications Q2/Q3)

**Decision**:

| Order | Role | Rule | Refusal |
| --- | --- | --- | --- |
| Sales Order | Sales | creator only | other's order → **404** (`requireVisibleOrder()`, unchanged) |
| Sales Order | Admin | creator only | other's order → **403** `ForbiddenException` |
| Sales Order | Warehouse Staff | never | 403 at the route guard **and** in the service |
| Purchase Order | Admin | any | — |
| Purchase Order | Warehouse Staff | creator only | other's order → **403** `ForbiddenException` |
| Purchase Order | Sales | never | 403 at the route guard **and** in the service |

**Rationale**: 404 for Sales follows the existing rule that a Sales user must not learn another Sales
user's order exists. Admin and Warehouse Staff can already *view* those orders, so 403 leaks nothing and
is the honest answer (constitution V: unauthorized → 403). The service checks the role as well as the
route (defence in depth), matching how `requireApprovableOrder()` re-checks Admin.

**Alternatives rejected**: 404 for Admin/Warehouse Staff — misleading, since the detail page they just
viewed exists.

---

## R-003 — Status check at save time (FR-005)

**Decision**: A compare-and-set header update: `UPDATE … SET … WHERE id = :id AND status = 'Draft'`. If it
affects no row, the save is refused with `DomainException("Only a draft order can be edited.")` and the
transaction rolls back. The status is also checked before validation, for a clear early message.

**Rationale**: Mirrors the existing `updateStatus($id, $expected, $status)` compare-and-set used by submit
and cancel. A check-then-write without the condition would let a submit that lands between them be
overwritten.

**Alternatives rejected**: `SELECT … FOR UPDATE` then a plain update — two statements where one suffices;
the conditional UPDATE already takes the row lock.

---

## R-004 — Saving header and lines atomically (FR-011)

**Decision**: Inject the existing `TransactionRunner` into both order services (as `StockService` already
receives it). Inside one transaction: (1) the conditional header update (R-003); (2) replace the lines —
delete the order's lines, insert the validated ones. Two new repository methods per order type:
`updateDraft(Order): bool` and `replaceItems(int $orderId, list<Item>): void`.

**Rationale**: Transaction ownership in the Service is the project's pattern (`StockService`, ADR-002). The
header update comes first, so a second concurrent edit blocks on the header row lock until the first
commits — the two edits never interleave their lines (spec edge case "last save wins, as a whole").

**Alternatives rejected**:
- Diff the lines (update changed, insert new, delete removed) — more code and more queries for no user
  benefit: a Draft has no receipts, no ledger rows, and nothing references its line ids.
- A transaction opened inside the repository — splits transaction ownership across layers, unlike every
  other multi-statement write in the project.
- Reusing the unused `id !== null` branch of `save()` — it updates the header only and has no status
  condition; changing its meaning would surprise the fixtures that call `save()` for inserts.

---

## R-005 — Validation and prices (FR-009, FR-010, A-004)

**Decision**: `update()` calls the **same private `validate()`** that `create()` uses. It already
re-reads each product and snapshots the catalog price (`sellingPrice` / `purchasePrice`), so A-004 holds
with no extra code.

**Consequence for the spec**: `validate()` checks that customer/supplier, warehouse, and products
**exist**; it does not check that they are active. Active-only choice is enforced by the form, which lists
only active records — the same as Create today. Spec FR-009 and the "deactivated after the draft was
created" edge case are worded to match this (updated 2026-10-04). Making the server reject inactive
records would change Create too, so it is out of scope here and is noted as a follow-up candidate.

**Update 2026-10-04 (tech-debt TD-10)**: the follow-up was done. `validate()` now rejects inactive
customers/suppliers, warehouses, and products through `Validator::activeById()`; because create and edit
still share `validate()`, both became stricter together — the "one rule set" decision above holds.

**Alternatives rejected**: A second, stricter validator for edit only — two rule sets for the same order
would diverge, and a draft could then be created in a state it can no longer be saved in.

---

## R-006 — Routes

**Decision**: Mirror the product edit routes (`GET /products/{id}/edit`, `POST /products/{id}`):

| Method | Path | Roles (guard) |
| --- | --- | --- |
| GET | `/sales-orders/{id}/edit` | A S |
| POST | `/sales-orders/{id}` | A S |
| GET | `/purchase-orders/{id}/edit` | A W |
| POST | `/purchase-orders/{id}` | A W |

**Rationale**: Same shape as existing edit routes; the guard roles equal the create roles, and the service
narrows them (R-002). No conflict with `/sales-orders/{id}/submit` etc.

---

## R-007 — Edit screen: reuse the create form

**Decision**: `views/sales-orders/form.php` and `views/purchase-orders/form.php` take an optional `$order`.
When present: title "Edit sales order SO-…", action `POST /sales-orders/{id}`, primary button "Save
changes", confirmation "Save changes to this draft order?", Cancel back to the order. The controller
pre-fills the existing `$old` array from the order (header fields and `items`), so the form's existing
re-fill logic shows the current lines with no new view code. Row count stays `max(lines, 3)`; Add line /
Remove use the existing `order-lines.js`.

**Rationale**: Mimics `views/products/form.php` (`$isEdit`). One form per order type keeps create and edit
visually identical (spec: "same layout as the create screen").

**Consequence**: a line whose product, or a header whose customer/supplier/warehouse, is now inactive
appears unselected (the lists hold active records only); saving then asks the user to choose — the
documented edge case.

---

## R-008 — Controller error handling

**Decision**:

| Exception from service | GET edit | POST update |
| --- | --- | --- |
| `NotFoundException` | 404 page | 404 page |
| `ForbiddenException` | 403 page | 403 page |
| `DomainException` (not Draft / changed meanwhile) | flash error, redirect to detail | flash error, redirect to detail |
| `ValidationException` | — | 422, form re-rendered with entered values and errors |

**Rationale**: Identical to the existing `receiveForm()` (redirect with flash when the state is wrong) and
`store()` (422 re-render) patterns.

---

## R-009 — Concurrency evidence

**Decision**: One integration test against MySQL proves the compare-and-set: a draft that was submitted
between loading and saving is refused, and its lines are unchanged. No two-connection test is added.

**Rationale**: Editing a Draft never touches stock (FR-012), so ADR-002's oversell risk does not apply. The
only race is "edit vs. state change", which the conditional update settles inside one statement; a
single-connection test shows the refusal and the rollback of the line replacement.

**Alternatives rejected**: A two-connection test as in `ConcurrentStockAdjustmentTest` — it proves row
locking, which this feature relies on but does not introduce.

---

## R-010 — Recording the brief deviation

**Decision**: Add **D-04** to `docs/planning/decisions.md`: editing a Draft is an owner-requested addition
beyond the brief, with the Q1–Q3 rules and the "if rejected" rollback (remove four routes; services keep
working). Also amend `specs/001-…/contracts/http-routes.md` and its authorization matrix.

**Rationale**: `decisions.md` already records every interpretation beyond the brief (D-01…D-03), with the
change needed if the trainer disagrees. This keeps the addition visible rather than silent.
