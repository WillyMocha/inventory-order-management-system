---
description: "Task list for the Edit Draft Orders feature"
---

# Tasks: Edit Draft Orders

**Input**: Design documents from `specs/004-edit-draft-orders/`
**Prerequisites**: plan.md, spec.md, research.md, data-model.md, contracts/http-routes.md, quickstart.md

**Tests**: Included. Spec FR-017 and constitution Principle III (NON-NEGOTIABLE) require a unit test for every
public Service method that runs a business rule; Principle IV wants the save-time status race shown by a
controlled test (research R-009). Test tasks come **before** their implementation and must fail first.

**Organization**: Grouped by user story. Every phase is a **Bolt** that ends at a checkpoint: stop, run the
checks, and propose a commit before starting the next (commit only when the owner asks).

**Conventions for every task** (CLAUDE.md, constitution v1.2.0):
- `declare(strict_types=1);` in every PHP file; every parameter, return, and property typed; array shapes
  (`list<SalesOrderItem>` etc.) documented for PHPStan level 6.
- Comments and docs in Indonesian; UI text in English; identifiers in English; this folder in English.
- Services never read `$_SESSION`/superglobals; the acting user is passed in. Editing never touches
  `product_stock` or `stock_ledger`.
- Output escaped with `View::e()`; all SQL prepared (`ATTR_EMULATE_PREPARES = false`, so no repeated named
  placeholder in one statement).
- New constructor parameter `TransactionRunner $transactions` goes **last**, after `ClockInterface $clock`,
  as in `StockService`.

## Format: `[ID] [P?] [Story] [FR-###?] Description`

- **[P]**: can run in parallel (different files, no dependency on an incomplete task)
- **[Story]**: US1, US2, US3 (spec.md)
- **[FR-###]**: requirement(s) satisfied; every FR-001 … FR-017 appears at least once

---

## Phase 1: Setup

**Purpose**: confirm a green baseline. No new dependency, tooling, or migration is needed.

- [X] T001 Run `docker compose exec app composer check` and record the baseline counts (last full run: 582 tests, all passing) in `specs/004-edit-draft-orders/implementation-log.md` (create it with a session header: date, checkpoint HEAD, planned files from plan.md) before any change; stop if it is not green

**Checkpoint**: baseline green.

---

## Phase 2: Foundational — repositories and transaction wiring · Bolt 1

**Purpose**: what both order types need: the compare-and-set header update, line replacement, and a
`TransactionRunner` in both order services, wired through every construction site.

**Independent test**: against MySQL, `updateDraft()` changes a Draft and refuses any other status;
`replaceItems()` swaps the lines; a failure after `updateDraft()` inside a transaction rolls both back. The
full suite stays green.

### Tests for Bolt 1

- [X] T002 [FR-005] [FR-008] [FR-011] [FR-012] Write failing integration tests in `tests/Integration/EditDraftOrderTest.php` (extends `IntegrationTestCase`, uses `SalesOrderFixtures` for customer/supplier/warehouse/products/users; Indonesian class docblock naming spec 004 and research R-003/R-004/R-009). For **each** of `MysqlSalesOrderRepository` and `MysqlPurchaseOrderRepository`: (a) `updateDraft()` on a Draft returns true and writes customer/supplier, warehouse, order date, while `order_number`, `status`, `created_by` (and `approved_by` for SO) are unchanged; (b) `updateDraft()` on a non-Draft order (submit it first via `updateStatus`) returns false and changes nothing; (c) `replaceItems()` leaves exactly the given lines (old line ids gone; PO lines have `received_quantity = 0`); (d) inside `$this->database->transaction(...)`, `updateDraft()` then `replaceItems()` then a thrown exception → header **and** lines are back to their original values (FR-011); (e) `stock_ledger` row count and `product_stock` quantities are identical before and after (a)–(d) (FR-012). Run with `composer db:test` + `composer test:integration`; they must fail because the methods do not exist yet

### Implementation for Bolt 1

- [X] T003 [P] [FR-005] [FR-011] Add to `app/Repository/SalesOrderRepositoryInterface.php`, with Indonesian docblocks taken from data-model.md "Repository additions": `public function updateDraft(SalesOrder $order): bool;` (header only, only while stored status is Draft) and `/** @param list<SalesOrderItem> $items */ public function replaceItems(int $orderId, array $items): void;` (must run inside the caller's transaction). Implement both in `app/Repository/Mysql/MysqlSalesOrderRepository.php`: `UPDATE sales_order SET customer_id = :customer_id, warehouse_id = :warehouse_id, order_date = :order_date, updated_at = NOW() WHERE id = :id AND status = :draft` returning `rowCount() === 1` (pass `SalesOrderStatus::Draft->value`; PDO's MySQL `rowCount()` counts *changed* rows, so an edit that changes nothing within the same second as the previous one reports 0 — do not switch the whole connection to `PDO::MYSQL_ATTR_FOUND_ROWS`; instead, if `rowCount()` is 0, return `$this->fetchInt('SELECT COUNT(*) FROM sales_order WHERE id = :id AND status = :draft', …) > 0`, which runs inside the same transaction and row lock), and `replaceItems` = `DELETE FROM sales_order_item WHERE sales_order_id = :order_id` + one prepared `INSERT` per item (same columns as `save()`). Leave the existing `save()` untouched (research R-004)
- [X] T004 [P] [FR-005] [FR-011] Same as T003 for `app/Repository/PurchaseOrderRepositoryInterface.php` and `app/Repository/Mysql/MysqlPurchaseOrderRepository.php` (`supplier_id`; `PurchaseOrderStatus::Draft`; insert `received_quantity` from the item, which is 0). With T003 and T004 done, T002 must now pass
- [X] T005 [P] [FR-005] Implement `updateDraft()` and `replaceItems()` in `tests/Unit/Fake/InMemorySalesOrderRepository.php`: compare-and-set on `status === SalesOrderStatus::Draft` (return false otherwise, change nothing), rebuild the stored `SalesOrder` with the new customer/warehouse/date and the **stored** number/creator/approver/status/items; `replaceItems` rebuilds the stored order with the given items (assign ids from a counter, `salesOrderId` = order id). The fake is `final`, so the save-time race in T010 is simulated with a test hook on the fake itself: `public function failNextUpdateDraft(): void` (Indonesian docblock: test-only, models an order that left Draft between read and save) — the next `updateDraft()` call returns false and changes nothing
- [X] T006 [P] [FR-005] Same as T005 in `tests/Unit/Fake/InMemoryPurchaseOrderRepository.php`, including `failNextUpdateDraft()` (keep `addReceivedQuantity()` working on the replaced items)
- [X] T007 [FR-011] Add `private readonly TransactionRunner $transactions` as the **last** constructor parameter of `app/Service/SalesOrderService.php` and `app/Service/PurchaseOrderService.php` (`use App\Support\TransactionRunner;`, Indonesian comment: why — header and lines of an edit commit together, research R-004) and pass it at **all five** construction sites: `config/container.php` (`$database`, as for `StockService`), `tests/Unit/Service/SalesOrderServiceTest.php` and `tests/Unit/Service/PurchaseOrderServiceTest.php` (`new ImmediateTransactionRunner()`), `tests/Integration/ApprovalAuthorizationTest.php` and `tests/Integration/ConcurrentGoodsIssueTest.php` (`$this->database` / `$database`); no behaviour change — the full suite stays green
- [X] T008 Add `updateDraft` and `replaceItems` of both MySQL repositories to the "every MySQL repository method executed" guarantee: either reference `EditDraftOrderTest` from the class docblock of `tests/Integration/RepositoryCoverageTest.php`, or add a minimal case there, so tech-debt TD-2b stays true

**Checkpoint (Bolt 1)**: `composer check` green. Commit suggestion: `feat: add draft-only order update and line replacement to order repositories`.

---

## Phase 3: User Story 1 — Edit a draft Sales Order (Priority: P1) 🎯 MVP

**Goal**: the creator of a Draft Sales Order (Sales or Admin) changes its header and lines; nobody else can.

**Independent test**: as `sales1`, edit draft SO 15 (change a quantity, remove a line, add a line), save; same
number, still Draft, new lines and total, then submit works (quickstart §1–§2).

### US1 · Bolt 2: service rule

#### Tests for Bolt 2

- [X] T009 [P] [US1] [FR-001] [FR-002] [FR-003] [FR-005] [FR-006] [FR-007] [FR-008] [FR-009] [FR-010] [FR-012] [FR-017] Write failing unit tests in `tests/Unit/Service/SalesOrderServiceEditTest.php` (setup copied from `SalesOrderServiceTest`: in-memory customer/warehouse/product fakes, `FixedClock`, `ImmediateTransactionRunner`; Admin A1, Admin A2, Sales S1, Sales S2, Warehouse W1; a Draft created by S1 and a Draft created by A1). `update(int $id, array $data, User $actor): void`: S1 edits own Draft → customer, warehouse, date, and lines replaced; `orderNumber`, `status` (Draft), `createdBy`, `approvedBy` (null) unchanged (FR-007, FR-008); line prices are the **current** catalog `sellingPrice`, also after changing the product's price in the fake between create and edit (FR-010, A-004); A1 edits own Draft → ok; S2 on S1's order → `NotFoundException` (FR-002); A2 on S1's order → `ForbiddenException` (FR-001); W1 → `ForbiddenException` (FR-003); order submitted (PendingApproval), Approved, Cancelled → `DomainException` "Only a draft order can be edited." (FR-005); no lines / quantity 0 / unknown product / unknown customer → `ValidationException` with the same keys as `create()` (FR-009); a payload carrying `created_by`, `status`, `order_number`, or prices has no effect; on **every** refusal the stored order (header and lines) is unchanged. `canEdit(SalesOrder $order, User $actor): bool`: true only for Draft + creator; false for every other row of research R-002 and every non-Draft status (FR-006). `assertMayEdit()`: throws `ForbiddenException` for Warehouse Staff and for a non-creator **regardless of status** (so an Admin opening another user's submitted order gets 403, not a redirect)
- [X] T010 [P] [US1] [FR-005] [FR-011] Add one unit test to `tests/Unit/Service/SalesOrderServiceEditTest.php` for the save-time race: call `$this->orders->failNextUpdateDraft()` (T005) before `update()` on a valid Draft and valid payload, and assert `update()` throws `DomainException` "Only a draft order can be edited." and that the stored header and lines are unchanged (so `replaceItems()` did not run)

#### Implementation for Bolt 2

- [X] T011 [US1] [FR-001] [FR-002] [FR-003] [FR-005] [FR-006] [FR-007] [FR-008] [FR-009] [FR-010] [FR-011] [FR-012] In `app/Service/SalesOrderService.php` add `public function canEdit(SalesOrder $order, User $actingUser): bool` (Draft and `isCreatedBy(actor)`; Indonesian docblock: Q3 — only the creator, so an Admin can never change an order and then approve it; also used by the controller for the Edit button) and `public function update(int $id, array $data, User $actingUser): void` in this order: `requireVisibleOrder()` (Sales on another's → 404) → `public function assertMayEdit(SalesOrder $order, User $actingUser): void` (new; Warehouse Staff → `ForbiddenException`; not creator → `ForbiddenException('You can only edit a draft order you created.')`; independent of status; reused by the controller in T013 so GET and POST check in the same order; `canEdit()` = may edit **and** Draft) → status ≠ Draft → `DomainException('Only a draft order can be edited.')` → `$items = $this->validate($data)` (the same private method `create()` uses, research R-005) → `$this->transactions->transaction(...)`: `updateDraft(new SalesOrder(id, stored number, customer, stored createdBy, stored approvedBy, warehouse, stored status, resolveOrderDate($data), []))`; if false → throw the same `DomainException` (rolls back); else `replaceItems($id, $items)`. Make T009 and T010 pass

**Checkpoint (Bolt 2)**: unit tests green; `composer analyse` clean.

### US1 · Bolt 3: HTTP and screen

- [X] T012 [US1] [FR-001] [FR-002] [FR-003] [FR-016] Register in `config/routes.php` in the Sales Order section, with an Indonesian comment (spec 004, owner decision D-04, service narrows the roles): `$router->add('GET', '/sales-orders/{id}/edit', 'SalesOrderController', 'edit', $adminSales);` and `$router->add('POST', '/sales-orders/{id}', 'SalesOrderController', 'update', $adminSales);`; add a route-table test to `tests/Integration/EditDraftOrderTest.php` asserting both allow exactly `[Role::Admin, Role::Sales]` and `Authorization::authorizeRoute` refuses Warehouse Staff with `ForbiddenException` (mirror `StockAdjustmentFlowTest`)
- [X] T013 [US1] [FR-001] [FR-002] [FR-005] [FR-009] [FR-013] In `app/Controller/SalesOrderController.php`: `edit()` checks in **the same order as `update()`** (contracts/http-routes.md "Check order"): `requireVisibleOrder()` (Sales on another's → 404) → `$this->salesOrders->assertMayEdit()` from T011 (→ 403) → order not Draft → flash error "Only a draft order can be edited." and redirect to `/sales-orders/{id}` (pattern of `PurchaseOrderController::receiveForm()`); then render the form with `$old` pre-filled from the order (`customer_id`, `warehouse_id`, `order_date`, `items` = list of `['product_id' => (string), 'quantity' => (string)]`). `update()` = `update($id, $this->payloadFrom($request), actor)`; `ValidationException` → 422 re-render with the entered payload and errors; `DomainException` → flash error + redirect to detail; success → flash "Sales order updated." + redirect `/sales-orders/{id}`. Extend `renderForm(array $old = [], array $errors = [], ?SalesOrder $order = null)` to pass `order` and title "Edit sales order {number}" when present. In `detailData()` add `'canEdit' => $this->salesOrders->canEdit($order, $actingUser)`. CSRF is enforced by the front controller (FR-016) — comment only
- [X] T014 [P] [US1] [FR-013] [NFR-001] [NFR-002] Edit mode in `views/sales-orders/form.php` via optional `@var SalesOrder|null $order` (`$isEdit = $order instanceof SalesOrder`, like `views/products/form.php`): title "Edit sales order {number}" and subtitle "Changes keep the order as a draft. Prices are re-read from the catalog when you save."; `action` `/sales-orders/{id}`; `data-confirm` "Save changes to this draft order?"; primary button "Save changes"; Cancel (header and footer) → `/sales-orders/{id}`; create mode unchanged. Row count stays `max(count($submittedItems), 3)`; everything via `View::e()`
- [X] T015 [P] [US1] [FR-014] In `views/sales-orders/detail.php` add `@var bool $canEdit` and, when true, `<a class="btn" href="/sales-orders/{id}/edit">Edit</a>` in the action row before Submit (text-only, like the product detail Edit button)
- [X] T016 [US1] [FR-001] [FR-002] [FR-003] [FR-005] [FR-013] [FR-016] Verify over HTTP against the running stack (quickstart §1–§2, curl with cookie jars or the browser harness): sales1 edits SO 15 → 302 + flash, new lines/total, status Draft; sales1 `GET /sales-orders/14/edit` → 404; admin `GET /sales-orders/14/edit` → 403 and no Edit button on SO 14; warehouse1 → 403; POST without CSRF → 403; empty lines → 422 with values kept; `GET /sales-orders/999999/edit` and `GET /sales-orders/abc/edit` → 404; stock movement row count unchanged; time one edit from the detail page to the confirmation (SC-001, < 1 minute, recorded as a manual observation). Record commands and results in `implementation-log.md`; restore the demo draft afterwards (edit it back) and note it

**Checkpoint (Bolt 3)**: `composer check` green; quickstart §1–§2 behave as written. MVP delivered. Commit
suggestion: `feat: let the creator edit a draft sales order`.

---

## Phase 4: User Story 2 — Edit a draft Purchase Order (Priority: P2)

**Goal**: an Admin edits any Draft Purchase Order; Warehouse Staff edit only the drafts they created.

**Independent test**: as warehouse1, edit draft PO 12 (warehouse and a quantity), save; same number, still
Draft (quickstart §3–§4).

### US2 · Bolt 4: service rule

- [X] T017 [P] [US2] [FR-003] [FR-004] [FR-005] [FR-006] [FR-007] [FR-008] [FR-009] [FR-010] [FR-012] [FR-017] Write failing unit tests in `tests/Unit/Service/PurchaseOrderServiceEditTest.php` (setup copied from `PurchaseOrderServiceTest` + `ImmediateTransactionRunner`; Admin A1, Warehouse W1, Warehouse W2, Sales S1; a Draft created by W1 and one by A1): A1 edits W1's Draft → ok (FR-004); W1 edits own → ok; W2 on W1's → `ForbiddenException`; W1 on A1's → `ForbiddenException` (FR-004); S1 → `ForbiddenException` (FR-003); Ordered / PartiallyReceived / Received / Cancelled → `DomainException` (FR-005); header and lines replaced, `received_quantity` 0, price = current catalog `purchasePrice` (FR-010); number/status/creator unchanged (FR-008); validation keys as `create()` (FR-009); every refusal leaves the order unchanged; the save-time race via `failNextUpdateDraft()` (T006) as in T010; `canEdit()` true only for Draft + (Admin, or Warehouse Staff creator) (FR-006); `assertMayEdit()` refuses Sales and a non-creating Warehouse Staff regardless of status
- [X] T018 [US2] [FR-003] [FR-004] [FR-005] [FR-006] [FR-007] [FR-008] [FR-009] [FR-010] [FR-011] [FR-012] In `app/Service/PurchaseOrderService.php` add `canEdit(PurchaseOrder $order, User $actingUser): bool` (Draft and (`isAdmin()` or (`isWarehouseStaff()` and `createdBy === actor id`)); Indonesian docblock citing Q2 and D-02) and `update(int $id, array $data, User $actingUser): void`: `requireOrder()` → `assertMayEdit(PurchaseOrder, User)` (public; Sales → `ForbiddenException`; Warehouse Staff not the creator → `ForbiddenException('You can only edit a draft purchase order you created.')`) → not Draft → `DomainException('Only a draft order can be edited.')` → `validate($data)` → transaction: `updateDraft()` (false → same `DomainException`) then `replaceItems()`. Update the class docblock sentence "Tidak ada scoping kepemilikan di sini" — ownership now applies to editing. Make T017 pass

**Checkpoint (Bolt 4)**: unit tests green.

### US2 · Bolt 5: HTTP and screen

- [X] T019 [US2] [FR-003] [FR-004] [FR-016] Register `GET /purchase-orders/{id}/edit` (`edit`) and `POST /purchase-orders/{id}` (`update`) with `$adminWarehouse` in `config/routes.php` (Indonesian comment); route-table test in `tests/Integration/EditDraftOrderTest.php`: exactly `[Role::Admin, Role::WarehouseStaff]`, Sales refused
- [X] T020 [US2] [FR-004] [FR-005] [FR-009] [FR-013] In `app/Controller/PurchaseOrderController.php` add `edit()` and `update()` exactly as T013, with the same check order (`requireOrder()` → `assertMayEdit()` → not Draft → redirect with flash; flash "Purchase order updated.", redirect `/purchase-orders/{id}`), extend `renderForm()` with `?PurchaseOrder $order` (title "Edit purchase order {number}"), and add `'canEdit' => $this->purchaseOrders->canEdit($order, $actingUser)` to `detailData()`
- [X] T021 [P] [US2] [FR-013] [NFR-001] [NFR-002] Edit mode in `views/purchase-orders/form.php` as T014 (subtitle "Changes keep the order as a draft. Unit costs are re-read from the catalog when you save.", action `/purchase-orders/{id}`, Cancel → detail)
- [X] T022 [P] [US2] [FR-014] In `views/purchase-orders/detail.php` add the Edit link when `$canEdit` (before "Submit to supplier")
- [X] T023 [US2] [FR-004] [FR-005] [FR-011] [FR-016] Extend `tests/Integration/EditDraftOrderTest.php` with the end-to-end service path on MySQL for both order types: `SalesOrderService::update()` and `PurchaseOrderService::update()` change header and lines; an order submitted between `findById` and the save (call `updateStatus` on the repository after loading, then `update()`) is refused and its lines are unchanged (research R-009); then verify over HTTP (quickstart §3–§4): admin edits PO 12; warehouse1 edits PO 12; warehouse1 on PO 13 → 403 and no Edit; warehouse2 on PO 12 → 403; sales1 → 403; `GET /purchase-orders/999999/edit` and `/purchase-orders/abc/edit` → 404; two-tab "submitted while editing" → flash error, lines unchanged. Record in `implementation-log.md`; restore demo data

**Checkpoint (Bolt 5)**: `composer check` green; quickstart §3–§4 behave as written. Commit suggestion:
`feat: let admins and the creating warehouse staff edit a draft purchase order`.

---

## Phase 5: User Story 3 — Honest confirmations and visible rules (Priority: P3) · Bolt 6

**Goal**: the submit confirmations and the Edit buttons tell users exactly when an order can be changed.

**Independent test**: open a Draft and a submitted order as each role; Edit appears only on the Draft for
permitted users; both submit confirmations say editing ends at submit (quickstart §1 step 4, §3).

- [X] T024 [P] [US3] [FR-015] Review the two submit confirmations: `views/purchase-orders/detail.php` ("…you will no longer be able to edit its lines." → "…you will no longer be able to edit it.", since the header is editable too) and `views/sales-orders/detail.php` ("You will not be able to edit it afterwards" — already accurate, keep); confirm both read correctly in the modal (title = first question, body = rest — `confirm.js` `splitMessage()`)
- [X] T025 [US3] [FR-006] [FR-014] Verify the visibility matrix over HTTP for every role × {Draft SO own, Draft SO other, submitted SO, Draft PO own, Draft PO other, Ordered PO}: the Edit link is present exactly where `canEdit()` is true (contracts/http-routes.md matrix); record the table in `implementation-log.md`

**Checkpoint (Bolt 6)**: matrix matches the contract.

---

## Phase 6: Polish & Cross-Cutting Concerns · Bolt 7

- [X] T026 [P] Add **D-04** to `docs/planning/decisions.md` (row in the index table + section in the D-01 style, Indonesian): "Edit order Draft — tambahan di luar brief atas permintaan owner (2026-10-04)"; Yang ambigu / Tafsiran (Q1–Q3 rules), Ditegakkan di (`SalesOrderService::update/canEdit`, `PurchaseOrderService::update/canEdit`, four routes), Diuji oleh (test classes), Bila tafsiran ditolak (remove the four routes and the Edit links; services may stay)
- [X] T027 [P] Merge the four routes and the matrix rows from `specs/004-edit-draft-orders/contracts/http-routes.md` into `specs/001-inventory-order-management/contracts/http-routes.md` (Sales Order and Purchase Order sections), each row citing spec 004
- [X] T028 [P] Update `docs/architecture/class-diagram-as-built.md`: `update()`/`canEdit()` on both order services, `SalesOrderService ..> TransactionRunner`, `PurchaseOrderService ..> TransactionRunner`, `updateDraft()`/`replaceItems()` on both repository interfaces, `edit()`/`update()` on both controllers; render-check every Mermaid block
- [X] T029 [P] Update `docs/brd/modules/sales-order.md` and `docs/brd/modules/purchase-order.md` (capability "edit Draft", screens, API surface rows, data flow, tests, prepended Change Log entry) and `docs/brd/00-overview.md` if it lists capabilities; update `README.md` (order flow and role responsibilities mention editing drafts and who may)
- [X] T030 [P] Update `docs/testing/test-scenarios.md` (S-3/S-4 rows: edit own draft, refused cases, not-Draft refusal, save-time race), `docs/testing/use-case-coverage.md` (four new service methods → test classes), `docs/testing/test-results.md` (new counts)
- [X] T031 [NFR-001] [NFR-002] Take UI evidence with the screenshot harness used for `docs/testing/screenshots/` (desktop 1366×768, mobile 360×740 touch, full height, overflow/clipped measured): new `24-sales-order-edit-desktop.png` / `24-sales-order-edit-mobile.png`, `25-purchase-order-edit-desktop.png` / `25-purchase-order-edit-mobile.png`, and `26-sales-order-edit-error-desktop.png` / `26-sales-order-edit-error-mobile.png` (422 after saving with no lines); keyboard walk through one edit form; add the rows to `docs/testing/responsive-accessibility.md` and `screenshots/run.json`; restore demo data afterwards
- [X] T032 [P] [FR-009] Record **TD-10** in `docs/quality/tech-debt.md` (index row + section in the TD-9 style, Indonesian; constitution VI): server-side validation of orders checks that customer/supplier, warehouse, and product **exist** but not that they are **active** — only the form's option lists enforce "active", for Create and now also Edit (research R-005); a crafted request can reference an inactive record. Ideal fix: an `existsActive` check in both order services' `validate()` for create and edit, with unit tests; status **Terbuka**, dated 2026-10-04
- [X] T033 [FR-012] [FR-017] [NFR-003] Final gate: `composer check` green; regenerate `docs/quality/phpstan-report.txt` and `docs/quality/phpcs-report.txt`; walk the full `quickstart.md` on the running stack; `grep` that no edit code path writes `stock_ledger` or `product_stock`; confirm `composer.json` gained no dependency (NFR-003); confirm every FR in the coverage table below has a passing test or a recorded HTTP verification; update `ai-usage-log.md` (constitution, Git and process) for feature 004 — the summary table row(s) it falls under, plus any AI output that was rejected or corrected (e.g. spec FR-009 aligned to the real create rule during planning; quickstart seed creators corrected after a query); finish `implementation-log.md` with results and UNRESOLVED (if any)

**Checkpoint**: everything green and documented. The local `SOP-Penggunaan-IOMS.pdf` (not in git) can then
gain an "Edit" step in A-06 and S-02 on request.

---

## Requirement Coverage

| Requirement | Tasks |
| --- | --- |
| FR-001 SO: creator only; Admin on others refused | T009, T011, T012, T013, T016 |
| FR-002 Sales on another's SO → 404 | T009, T011, T012, T013, T016 |
| FR-003 W never edits SO; S never edits PO | T009, T011, T012, T016, T017, T018, T019 |
| FR-004 PO: Admin any, W own only | T017, T018, T019, T020, T023 |
| FR-005 Draft only, checked at save time | T002, T003, T004, T005, T006, T009, T010, T011, T013, T016, T017, T018, T020, T023 |
| FR-006 rules enforced server-side on every save | T009, T011, T017, T018, T025 |
| FR-007 editable fields | T009, T011, T017, T018 |
| FR-008 number/status/creator/approver unchanged | T002, T009, T011, T017, T018 |
| FR-009 same validation as create | T009, T011, T013, T017, T018, T020, T032 (known gap recorded as TD-10) |
| FR-010 prices as on create | T009, T011, T017, T018 |
| FR-011 header + lines atomic | T002, T003, T004, T007, T010, T011, T018, T023 |
| FR-012 no stock change | T002, T009, T011, T017, T018, T033 |
| FR-013 redirect + confirmation | T013, T014, T016, T020, T021 |
| FR-014 Edit shown only when allowed | T015, T022, T025 |
| FR-015 honest submit confirmations | T024 |
| FR-016 CSRF | T012, T013, T016, T019, T023 |
| FR-017 unit tests per rule | T009, T010, T017, T033 |
| NFR-001 360px / keyboard / contrast | T014, T021, T031 |
| NFR-002 English UI | T014, T021, T031 |
| NFR-003 no new dependency | T033 (no task adds one) |
| SC-001 | T016 (manual timing) |
| SC-002 / SC-006 | T009, T010, T017, T023 |
| SC-003 | T009, T016 |
| SC-004 | T009, T016, T017, T023 |
| SC-005 | T002, T009, T017 |
| SC-007 | T031 |

---

## Dependencies & Execution Order

- **Phase 1** → **Phase 2 (Bolt 1)** → **Phase 3 (Bolt 2 → Bolt 3)** → **Phase 4 (Bolt 4 → Bolt 5)** →
  **Phase 5 (Bolt 6)** → **Phase 6 (Bolt 7)**
- US2 does not depend on US1's code; it depends only on Phase 2. It is ordered after US1 by priority and
  because both touch `config/routes.php` and `tests/Integration/EditDraftOrderTest.php`.
- US3 depends on US1 and US2 (it checks their Edit links and confirmations).
- Same-file chains: `EditDraftOrderTest.php` (T002 → T012 → T019 → T023), `config/routes.php`
  (T012 → T019), `SalesOrderService.php` (T007 → T011), `PurchaseOrderService.php` (T007 → T018),
  `SalesOrderController.php` (T013), `PurchaseOrderController.php` (T020),
  `views/purchase-orders/detail.php` (T022 → T024), `views/sales-orders/detail.php` (T015 → T024),
  `config/container.php` (T007), `SalesOrderServiceEditTest.php` (T009 → T010),
  `InMemorySalesOrderRepository.php` (T005, incl. `failNextUpdateDraft()` used by T010),
  `InMemoryPurchaseOrderRepository.php` (T006, used by T017), `SalesOrderService.php` → `SalesOrderController.php`
  (T011 defines `assertMayEdit()`, T013 uses it), `PurchaseOrderService.php` → `PurchaseOrderController.php`
  (T018 → T020).

### Parallel Opportunities

- Bolt 1: T003, T004, T005, T006 (four different files) after T002 is written and red; T007 after all four.
- Bolt 2: T009 and T010 are the same file — write them together, then T011.
- Bolt 3: T014 and T015 (two views) once T013 fixes the variables passed to them.
- Bolt 4/5: T017 can start as soon as Phase 2 is done, in parallel with US1's Bolt 3; T021 and T022 together.
- Bolt 7: T026–T030 and T032 are independent documentation files; T031 needs the running stack; T033 is last.

## Implementation Strategy

### MVP first

Phases 1–3 deliver editing for Sales Orders, the most frequent case. Stop at the Bolt 3 checkpoint and demo
quickstart §1–§2.

### Incremental delivery (one Bolt at a time; commit only when asked)

1. Bolt 1 → `feat: add draft-only order update and line replacement to order repositories`
2. Bolt 2–3 → `feat: let the creator edit a draft sales order`
3. Bolt 4–5 → `feat: let admins and the creating warehouse staff edit a draft purchase order`
4. Bolt 6 → `fix: make submit confirmations match the edit rules`
5. Bolt 7 → `docs: edit draft orders decision, contracts, diagrams and evidence`
