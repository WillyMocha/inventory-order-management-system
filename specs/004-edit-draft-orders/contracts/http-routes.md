# HTTP Routes: Edit Draft Orders

**Feature**: `004-edit-draft-orders` · **Date**: 2026-10-04

Server-rendered HTML routes, added to `config/routes.php`. The global rules of
[`../../001-inventory-order-management/contracts/http-routes.md`](../../001-inventory-order-management/contracts/http-routes.md)
apply: deny by default, identity from the session (never from the payload), CSRF on every non-GET
request, per-request account re-check, 404 instead of 403 when the caller may not even know the record
exists. No JSON endpoint is added.

Role abbreviations: **A** = Admin, **S** = Sales, **W** = WarehouseStaff.

## Routes

| Method | Path | Auth | Role (guard) | Authorization in the service / notes |
| --- | --- | --- | --- | --- |
| GET | `/sales-orders/{id}/edit` | required | **A S** | W → 403 (guard). S, other user's order → **404**. A, other user's order → **403**. Not Draft → 302 to `/sales-orders/{id}` with flash error. Otherwise the form pre-filled with the order |
| POST | `/sales-orders/{id}` | required | **A S** | Same rules as GET, re-checked at save time. CSRF (invalid → 403). Body below. Success → 302 `/sales-orders/{id}`, flash "Sales order updated." Validation → 422 re-render. Not Draft at save time → 302 to detail with flash error, nothing changed |
| GET | `/purchase-orders/{id}/edit` | required | **A W** | S → 403 (guard). W, other user's order → **403**. Not Draft → 302 to `/purchase-orders/{id}` with flash error |
| POST | `/purchase-orders/{id}` | required | **A W** | Same rules as GET, re-checked at save time. CSRF. Body below. Success → 302 `/purchase-orders/{id}`, flash "Purchase order updated." Validation → 422. Not Draft at save time → 302 with flash error |
| GET | `/sales-orders/{id}` (existing) | required | A S W (unchanged) | Shows **Edit** only when `SalesOrderService::canEdit()` is true |
| GET | `/purchase-orders/{id}` (existing) | required | A W (unchanged) | Shows **Edit** only when `PurchaseOrderService::canEdit()` is true |

Unknown or non-numeric `{id}` → 404 on all four new routes.

## Check order (GET and POST identical)

Both the edit screen (GET) and the save (POST) apply the checks in this order, so a user always gets the same
answer for the same order:

1. Order exists → otherwise **404**.
2. Visibility — a Sales user and another user's Sales Order → **404** (existing rule).
3. Permission (`assertMayEdit()`, independent of status) → otherwise **403**.
4. Status is Draft → otherwise **302** to the detail page with "Only a draft order can be edited.".
5. (POST only) Validation → otherwise **422**; then the save, whose status condition can still refuse
   (step 4's answer) if the order left Draft in the meantime.

Example: an Admin opening the edit screen of another user's **submitted** Sales Order gets **403** (step 3),
not a redirect.

## `POST /sales-orders/{id}` body

| Field | Type | Required | Rule |
| --- | --- | --- | --- |
| `csrf_token` | string | yes | valid session token |
| `customer_id` | string (whole number) | yes | existing customer |
| `warehouse_id` | string (whole number) | yes | existing warehouse |
| `order_date` | string `Y-m-d` | no | valid date; empty → today |
| `items[n][product_id]` | string (whole number) | yes, per line | existing product |
| `items[n][quantity]` | string (whole number) | yes, per line | ≥ 1 |

At least one line. Blank rows (no product and no quantity) are ignored. Ignored if present: `created_by`,
`status`, `order_number`, `approved_by`, any price — these never come from the payload.

## `POST /purchase-orders/{id}` body

As above with `supplier_id` instead of `customer_id`. Ignored if present: `created_by`, `status`,
`order_number`, `received_quantity`, any price.

## Responses

| Case | Status | What the user sees |
| --- | --- | --- |
| Saved | 302 → detail | Flash success; detail shows new header, lines, total; status still Draft |
| Validation failed | 422 | Edit form, summary alert + field errors, entered values kept |
| Not Draft (on open or at save) | 302 → detail | Flash error "Only a draft order can be edited." |
| Not permitted, record visible to the role | 403 | Standard error page |
| Sales user, another user's Sales Order | 404 | Standard error page |
| Not signed in | 302 → `/login` | — |

## Authorization matrix addition

| Capability | Admin | Sales | Warehouse Staff |
| --- | --- | --- | --- |
| Edit a Draft Sales Order | ✅ own only (others → 403) | ✅ own only (others → 404) | ❌ 403 |
| Edit a Draft Purchase Order | ✅ any | ❌ 403 | ✅ own only (others → 403) |
| Edit any order not in Draft | ❌ refused | ❌ refused | ❌ refused |
