# Implementation Log: Edit Draft Orders

## Session 2026-10-04

- **Checkpoint HEAD**: `2beb8eb` (working tree: untracked `specs/004-edit-draft-orders/` and the local
  `SOP-Penggunaan-IOMS.pdf` only)
- **Tasks targeted**: T001–T033 (all phases)
- **Checklists**: `checklists/requirements.md` 16/16 — PASS
- **FR coverage precondition**: FR-001 … FR-017 each cited by ≥ 1 task; SC-001 … SC-007 achievable
  (verified by `/rudis.analyze`, all findings applied)
- **Pre-existing note**: `SalesOrderService.php` (405 lines), `SalesOrderController.php` (479) and
  `PurchaseOrderController.php` (470) already exceed the 300-line file guideline of the SonarQube standard;
  this feature adds short methods only and does not split those classes (out of scope; candidate for a
  later refactor).

| Task | Files | Note |
| --- | --- | --- |
| T001 | `tests/Integration/RepositoryCoverageTest.php` | Baseline: unit 413 OK, integration 170 OK, PHPStan 0 errors. PHPCS had **1 warning** (line 133, 124 chars) left by the previous SKU change (commit `2beb8eb`), which made `composer check` exit 1. Line wrapped; PHPCS exit 0. Baseline now green |
| T002 | `tests/Integration/EditDraftOrderTest.php` (new) | 10 tests (SO + PO: header-only CAS, unchanged-row CAS, refused when not Draft, line replacement, rollback of header + lines, no stock touched). Red first: 10 × "undefined method". Literals → constants; `failInsideTransaction()` rewritten so PHPStan sees no unreachable code |
| T003 | `app/Repository/SalesOrderRepositoryInterface.php`, `app/Repository/Mysql/MysqlSalesOrderRepository.php` | `updateDraft()` (CAS `status = 'Draft'`, re-read when `rowCount()` is 0) and `replaceItems()`; the line INSERT loop of `save()` extracted to private `insertItems()` (shared, no behaviour change) |
| T004 | `app/Repository/PurchaseOrderRepositoryInterface.php`, `app/Repository/Mysql/MysqlPurchaseOrderRepository.php` | Same as T003 for Purchase Orders. T002 green (10/10) |
| T005 | `tests/Unit/Fake/InMemorySalesOrderRepository.php` | `updateDraft()`, `replaceItems()` (line ids from 10 000), test hook `failNextUpdateDraft()` |
| T006 | `tests/Unit/Fake/InMemoryPurchaseOrderRepository.php` | Same as T005 |
| T007 | `app/Service/SalesOrderService.php`, `app/Service/PurchaseOrderService.php`, `config/container.php`, `tests/Unit/Service/{SalesOrderServiceTest,PurchaseOrderServiceTest}.php`, `tests/Integration/{ApprovalAuthorizationTest,ConcurrentGoodsIssueTest}.php` | `TransactionRunner $transactions` as last constructor parameter; all five construction sites updated |
| T008 | `tests/Integration/RepositoryCoverageTest.php` | Class docblock points to `EditDraftOrderTest` for the four new MySQL methods (TD-2b stays true) |

**Gate Bolt 1**: unit 413 OK, integration 180 OK (+10), PHPCS exit 0. PHPStan: 2 expected errors
"`$transactions` is never read" in both order services — consumed by `update()` in T011/T018; not a defect.
| T009 | `tests/Unit/Service/SalesOrderServiceEditTest.php` (new) | 18 tests: creator edits (Sales and Admin), price re-snapshot, payload cannot set number/status/creator/approver/price, 404 for another Sales, 403 for Admin/second Admin/Warehouse Staff, 3 non-Draft statuses, 5 invalid payloads (same keys as create, no partial save), `canEdit()`, `assertMayEdit()` status-independent. Red first (11 errors, 7 failures) |
| T010 | same file | Save-time race via `failNextUpdateDraft()`: `DomainException`, header and lines unchanged |
| T011 | `app/Service/SalesOrderService.php` | `update()`, `canEdit()`, `assertMayEdit()` + private `mayEdit()`, `onlyDraftEditable()`. 59/59 SalesOrderService tests green. **Sonar S1448**: class now has 24 methods (> 20; was 19) — recorded, not split (C-003; candidate refactor, see TD-10 note) |

**Gate Bolt 2**: unit 431 OK; PHPCS exit 0; PHPStan: 1 expected error left (`PurchaseOrderService::$transactions`, T018).
| T012 | `config/routes.php`, `tests/Integration/EditDraftOrderTest.php` | `GET /sales-orders/{id}/edit` and `POST /sales-orders/{id}` with `$adminSales`; route-table test (exactly Admin + Sales) and guard test (Warehouse Staff refused) |
| T013 | `app/Controller/SalesOrderController.php` | `edit()` (same check order as the service: 404 → `assertMayEdit` 403 → not Draft redirect), `update()` (422 re-render / flash on `DomainException` / success flash), `renderForm(…, ?SalesOrder)`, `oldFromOrder()`, `canEdit` in `detailData()`. Boy Scout: the 8 `'/sales-orders/'` redirect literals → `DETAIL_PATH` constant (Sonar S1192). Class was already > 20 methods (Sonar S1448, pre-existing) |
| T014 | `views/sales-orders/form.php` | Edit mode via `$order`: title with order number, subtitle, action, confirmation text, "Save changes", Cancel → detail, price hint for drafts; create mode unchanged |
| T015 | `views/sales-orders/detail.php` | Text-only **Edit** link before Submit when `$canEdit` |
| T016 | — (verification) | Headless Chrome against `localhost:8080`, script `verify-so-edit.mjs` (scratchpad). sales1: SO 15 shows Edit; edit form pre-filled (customer 15, warehouse 1, 2026-10-04, line 20 × 6, 3 rows, action `/sales-orders/15`); edit via UI + confirmation modal → `/sales-orders/15`, flash "Sales order updated.", status Draft, same number, lines "Webcam × 9" + "CCTV × 2"; SO 14 (sales2) edit → **404**; `/999999/edit` and `/abc/edit` → **404**; POST without CSRF → **403**; empty lines → **422** "Add at least one product line to the order.", customer kept. admin: SO 14 no Edit, edit → **403**; SO 10 (PendingApproval, sales1's) no Edit, edit → **403** (not a redirect — check order verified). warehouse1: GET and POST → **403**. Ledger rows 74 → 74, `SUM(product_stock.quantity)` 1127 → 1127. SO 15 restored to customer 15 / warehouse 1 / 2026-10-04 / product 20 × 6 (only `updated_at` differs). **SC-001**: the automated UI flow (open edit → change → save → confirm) took 1.9 s; a human timing was not taken in this headless session — recorded honestly, not claimed |

**Gate Bolt 3**: integration `EditDraftOrderTest` 12/12; PHPCS exit 0; PHPStan 1 expected error (T018). Pass B (rendered): edit form at 1280px matches the create screen (cards, grid, spacing, tokens; available-stock hint pre-filled).
| T017 | `tests/Unit/Service/PurchaseOrderServiceEditTest.php` (new) | 17 tests: creating Warehouse Staff and Admin (on another's draft) edit; creator unchanged; unit cost re-snapshot (payload price ignored); W on another W's / Admin's draft → 403; Sales → 403; unknown → 404; 4 non-Draft statuses; save-time race via `failNextUpdateDraft()`; 4 invalid payloads with no partial save; `canEdit()`; `assertMayEdit()` status-independent. Red first (9 errors, 8 failures) |
| T018 | `app/Service/PurchaseOrderService.php` | `update()`, `canEdit()`, `assertMayEdit()` + private `mayEdit()`, `onlyDraftEditable()`; class docblock updated (ownership now applies to editing). 44/44 PurchaseOrderService tests green; **PHPStan now 0 errors** |
| T019 | `config/routes.php`, `tests/Integration/EditDraftOrderTest.php` | `GET /purchase-orders/{id}/edit`, `POST /purchase-orders/{id}` with `$adminWarehouse`; route-table + guard (Sales refused) tests |
| T020 | `app/Controller/PurchaseOrderController.php` | `edit()`/`update()` in the T013 check order, `renderForm(…, ?PurchaseOrder)`, `oldFromOrder()`, `canEdit` in `detailData()`; Boy Scout: 8 `'/purchase-orders/'` literals → `DETAIL_PATH`. Class already > 20 methods (Sonar S1448, pre-existing) |
| T021 | `views/purchase-orders/form.php` | Edit mode as T014 (unit-cost hint for drafts) |
| T022 | `views/purchase-orders/detail.php` | Text-only **Edit** link before "Submit to supplier" when `$canEdit` |
| T023 | `tests/Integration/EditDraftOrderTest.php`; verification | +4 MySQL service-path tests (SO and PO edited through the real services with catalog prices; submitted SO / Ordered PO refused, lines intact) → 18/18. HTTP (`verify-po-edit.mjs`): admin edits PO 12 (warehouse1's) → flash, Draft, Router × 15, then restored to Router × 10 + AP × 8 (DB identical except `updated_at`); `/999999/edit`, `/abc/edit` → 404; warehouse1 edits own PO 12 (200), PO 13 (admin's) no Edit + GET/POST **403**; warehouse2 on PO 12 **403**; sales1 GET/POST **403**. **Two-tab race**: warehouse1 created PO 18, opened its edit form, a second session submitted it (Ordered), saving the first tab → flash "Only a draft order can be edited.", lines unchanged (Router × 1); PO 18 then cancelled by admin. **Side effect**: `PO-20261004-0003` (id 18) stays in the demo DB as Cancelled — an Ordered PO cannot be removed through the application. Ledger 74 → 74, stock 1127 → 1127 |
| T024 | `views/purchase-orders/detail.php` | PO submit confirmation "…edit its lines." → "…edit it." (header is editable too); SO confirmation already accurate ("You will not be able to edit it afterwards"), unchanged |
| T025 | — (verification) | Edit-link matrix over HTTP: SO — sales1 own Draft ✅ shown; admin on sales2's Draft ✗; admin on sales1's PendingApproval ✗; (warehouse has no SO edit route). PO — admin on warehouse1's Draft ✅; warehouse1 own Draft ✅; warehouse1 on admin's Draft ✗; warehouse2 on warehouse1's Draft ✗; admin on Ordered/Cancelled ✗. Cell "creator on own submitted order" is covered by unit tests (`canEdit()` false for PendingApproval / Ordered), not re-run over HTTP |

**Gate Bolt 4–6**: unit and integration green for the touched suites; PHPStan 0; PHPCS exit 0. Pass B: PO edit form at 1280px matches the create screen.
| T026 | `docs/planning/decisions.md` | D-04 (index row + section): addition beyond the brief, Q1–Q3 rules, where enforced, tests, rollback if rejected |
| T027 | `specs/001-inventory-order-management/contracts/http-routes.md` | Four routes merged into the PO and SO sections, citing spec 004 |
| T028 | `docs/architecture/class-diagram-as-built.md` | New service/repository methods, `TransactionRunner` arrows, section "Edit order Draft (004)" |
| T029 | `docs/brd/modules/{sales-order,purchase-order}.md`, `docs/brd/00-overview.md`, `README.md` | SO-CAP-008 / PO-CAP-006, rules, API rows, screens, tests, change log; stale PO gap (seed order-number format, fixed by `e8682d2`) removed; README features and role table |
| T030 | `docs/testing/{test-scenarios,use-case-coverage}.md` | S-3/S-4 edit rows; coverage SalesOrderService 13/13, PurchaseOrderService 10/10 with a 004 section |
| T031 | `docs/testing/screenshots/{24,25,26}-*-{desktop,mobile}.png` (new), `run.json`, `responsive-accessibility.md`, `public/assets/css/app.css` | 6 captures, overflow 0 / clipped 0 (line table scrolls inside `.table-wrap` at 360px). **Found from the images**: order number in the edit title broke at the hyphen at 360px → `.page-title .tabular { white-space: nowrap; }`, recaptured. Keyboard walk: logical order, every stop in `main` has visible focus. `run.json` now 48 entries |
| T032 | `docs/quality/tech-debt.md` | TD-10 (order validation checks existence, not active status — create and edit) and TD-11 (SalesOrderService 24 methods; both order controllers over the size limits — pre-existing, worsened by 004) |
| T033 | `docs/quality/{phpstan,phpcs}-report.txt`, `docs/testing/test-results.md`, `ai-usage-log.md` | Final gate below; reports regenerated; AI log row #7 and note #5 (five AI claims corrected before they became code) |

## Final gate (2026-10-04)

- `composer check`: unit **448 OK** (1291 assertions), integration **188 OK** (726), PHPStan level 6 **0 errors**,
  PHPCS **exit 0** (153 files). JS `node --test`: 18/18.
- No `stock_ledger` / `product_stock` reference in either order service or order repository (grep).
- `composer.json` / `composer.lock` unchanged (NFR-003).
- Changed files: 38 modified + 10 new (3 test classes, 6 screenshots, this spec folder) — all listed in plan.md
  or in a Polish task; nothing outside scope.

## Requirement verification

| Req | Evidence |
| --- | --- |
| FR-001 | `SalesOrderService::assertMayEdit()`; `SalesOrderServiceEditTest::anAdminCannotEditADraftSomeoneElseCreated`; HTTP admin → SO 14 / SO 10 403 |
| FR-002 | `requireVisibleOrder()` in `update()`/`edit()`; test `anotherSalesUserGetsNotFound`; HTTP sales1 → SO 14 404 |
| FR-003 | Routes `$adminSales` / `$adminWarehouse` + service role checks; `warehouseStaffCanNeverEditASalesOrder`, `salesCanNeverEditAPurchaseOrder`; guard tests in `EditDraftOrderTest`; HTTP 403 both ways |
| FR-004 | `PurchaseOrderService::mayEdit()`; `anAdminEditsAnyDraft…`, `warehouseStaffCannotEditAnotherUsersDraft`; HTTP warehouse1 PO 12 ✓, PO 13 403, warehouse2 403 |
| FR-005 | Status check + `updateDraft()` CAS; data-provider non-Draft tests (3 SO, 4 PO); repo CAS tests; HTTP two-tab race refused |
| FR-006 | `canEdit()`/`assertMayEdit()` on every save; POST-direct 403/404 tests over HTTP |
| FR-007 | `theCreatorReplacesHeaderAndLines`, `theCreatingWarehouseStaffReplacesHeaderAndLines`; HTTP header + line changes |
| FR-008 | Same tests assert number/status/creator/approver unchanged; `fieldsThatNeverComeFromThePayloadAreIgnored` |
| FR-009 | Shared `validate()`; `editUsesTheSameValidationAsCreate` (5 SO, 4 PO); HTTP 422 with values kept. "Active" gap recorded as TD-10 |
| FR-010 | `linePricesAreReReadFromTheCatalogWhenSaving`, `unitCostsAreReReadFromTheCatalogWhenSaving`; MySQL service-path tests |
| FR-011 | `TransactionRunner` + header-then-lines; repo rollback tests (`aFailed…EditRollsBackHeaderAndLinesTogether`) |
| FR-012 | `editingDraftsNeverTouchesStock`; HTTP ledger 74 → 74, stock 1127 → 1127; grep |
| FR-013 | Controllers: flash "Sales order updated." / "Purchase order updated." + redirect; HTTP verified |
| FR-014 | `canEdit` in `detailData()`; Edit links in both detail views; T025 matrix |
| FR-015 | PO confirmation "…edit it."; SO confirmation already states it; modal title/body verified |
| FR-016 | Front-controller CSRF; HTTP POST without token → 403 |
| FR-017 | 35 new unit tests (18 SO + 17 PO) on in-memory fakes; every rule above has a unit test |
| NFR-001 | Screenshots 24–26 desktop + 360px, overflow 0 / clipped 0; keyboard walk; title wrap fixed |
| NFR-002 | All new UI strings English (titles, hints, buttons, flashes, errors) |
| NFR-003 | No dependency added |
| SC-001 | Automated flow 1.9 s; **no human timing taken** (headless session) — not claimed |
| SC-002 … SC-006 | Unit + integration + HTTP results above |
| SC-007 | Same 360px / keyboard checks as the create screens (T031); contrast uses existing tokens only, no new colour |

**Assumptions re-checked**: A-001…A-007 hold. A-004 (prices re-read) and A-005 (last save wins, as a whole —
header row lock before line replacement) are implemented as written.

**UNRESOLVED**: none. **Known side effect**: test purchase order `PO-20261004-0003` (id 18) remains in the local
demo database as Cancelled (created for the two-tab race check; an Ordered PO cannot be deleted through the app).
`composer db:reset` removes it.

---

## Session 2026-10-04 — redesign: tech-debt TD-9, TD-10, TD-11

- **Checkpoint HEAD**: `9fa8dae` (working tree clean except the local SOP PDF)
- **Scope (owner decision)**: fix all three in code. TD-11 split by responsibility; the 300-line file limit is
  recorded as remaining.

| Item | Files | Note |
| --- | --- | --- |
| TD-9 | `database/005_ledger_append_only.sql` (new), `compose.yaml`, `tests/Integration/{IntegrationTestCase,SalesOrderFixtures}.php`, `tests/Integration/LedgerAppendOnlyTest.php` (new) | Triggers refuse every UPDATE and any DELETE without `@ioms_allow_ledger_cleanup = 1`. `compose.yaml`: `--log-bin-trust-function-creators=1` (binary log ON + non-SUPER user → error 1419 otherwise); `db` container recreated once, data kept. Mutation check: with the triggers dropped in the test DB, 4 of 6 tests fail. Applied to the dev DB with `migrate.php` |
| TD-10 | `app/Support/Validator.php` (`activeById()`), `app/Service/{SalesOrderService,PurchaseOrderService}.php`, their unit tests | Inactive customer/supplier, warehouse, product refused on create and edit; 8 new unit tests; spec 004 FR-009/R-005 updated |
| TD-11 | NEW `app/Controller/{GoodsIssueController,GoodsReceiptController,SalesOrderApprovalController}.php`, NEW `app/Service/SalesOrderApprovalService.php`; `SalesOrderService`, both order controllers, `app/Support/Money.php` (`lineTotal()`), `config/{routes,container}.php`, `tests/Unit/Support/MoneyTest.php` (new), approval tests re-pointed | All classes ≤ 20 methods (SalesOrderService 24→19, SalesOrderController 27→20, PurchaseOrderController 26→20). Same URLs, roles, messages. HTTP smoke: issue/receive forms 200, wrong-status redirects with the same messages, approve/reject on a Fulfilled order refused with the same messages, Sales 403; demo data unchanged (ledger count, stock sum, SO statuses compared before/after) |
| Docs | `CLAUDE.md`, `README.md`, `docs/quality/{tech-debt,refactor-log}.md`, `docs/planning/{decisions,erd}.md`, `docs/architecture/{class-diagram-as-built,adr-001-repository-abstraction}.md`, `docs/testing/failure-paths.md`, `docs/brd/modules/{sales-order,purchase-order,stock}.md` | References to moved code and to TD-9 updated; refactor-log R-9…R-11; history documents (`specs/001/tasks.md`, older log entries) intentionally untouched |

**Gate**: `composer check` — unit 459 OK, integration 194 OK, PHPStan 0, PHPCS 0 (no suppression added).

**UNRESOLVED**: none. **Remaining by design**: four order classes still exceed the 300-line file guideline (TD-11
"partly done").
