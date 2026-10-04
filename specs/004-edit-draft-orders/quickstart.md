# Quickstart: Edit Draft Orders

**Feature**: `004-edit-draft-orders` · **Date**: 2026-10-04

Manual verification against the Docker stack with the demo seed. All accounts use `Password123!`.

```bash
docker compose up -d
docker compose exec app composer test          # unit + integration
docker compose exec app composer analyse       # PHPStan level 6
docker compose exec app composer cs            # PHPCS PSR-12
```

Seed drafts used below (status Draft, creator in brackets):

| Order | Creator |
| --- | --- |
| `SO-…` id 13 | sales1 |
| `SO-…` id 14 | sales2 |
| `SO-…` id 15 | sales1 |
| `PO-…` id 12 | warehouse1 |
| `PO-…` id 13 | admin |

(Verified against the seed on 2026-10-04; check "Created by" / "ordered by" on the detail page if the data
was changed.)

## 1. Sales edits their own draft (US1)

1. Sign in as `sales1@ioms.test`, open Sales order id 13. **Expect** an **Edit** button next to Submit
   and Cancel.
2. Click Edit. **Expect** the form titled "Edit sales order SO-…", pre-filled with the customer,
   warehouse, date, and both lines.
3. Change one quantity, remove the other line, add a new line, click **Save changes**, confirm.
   **Expect** the detail page, flash "Sales order updated.", same order number, status Draft, new lines
   and total.
4. Submit the order for approval. **Expect** success; the Edit button is gone. (Steps 4–5 consume the draft;
   use order 15 to repeat steps 1–3.)
5. Open `/sales-orders/13/edit` directly. **Expect** a redirect to the detail page with "Only a draft order
   can be edited."

## 2. Refusals for Sales Orders

1. As `sales1`, open `/sales-orders/14/edit` (sales2's draft). **Expect** 404.
2. As `admin@ioms.test`, open Sales order 14. **Expect** no Edit button; `/sales-orders/14/edit` → 403.
3. As `warehouse1@ioms.test`, open `/sales-orders/14/edit`. **Expect** 403.
4. As `sales1`, edit a draft and clear every line, then save. **Expect** 422 with "Add at least one product
   line to the order." and the entered header kept.

## 3. Purchase Orders (US2)

1. As `admin@ioms.test`, open Purchase order 12 (created by warehouse1). **Expect** Edit — an Admin may edit
   any draft Purchase Order. Change the destination warehouse and a quantity, save. **Expect** "Purchase
   order updated.", status Draft, new values.
2. As `warehouse1@ioms.test` (the creator), open Purchase order 12. **Expect** Edit; editing works.
3. As `warehouse1@ioms.test`, open Purchase order 13 (created by admin). **Expect** no Edit;
   `/purchase-orders/13/edit` → 403.
4. As `warehouse2@ioms.test`, open `/purchase-orders/12/edit`. **Expect** 403.
5. As `sales1`, open `/purchase-orders/12/edit`. **Expect** 403.

## 4. Saving after the order left Draft

1. As `warehouse1`, open the edit form of Purchase order 12 in one tab.
2. In a second tab, submit that order to the supplier.
3. Back in the first tab, click **Save changes**. **Expect** a redirect to the detail page with "Only a draft
   order can be edited."; the order shows the submitted lines, unchanged.

## 5. Stock is untouched (FR-012)

Before and after any edit, the stock movement report for today has the same number of rows, and the
product detail pages show the same quantities.

## 6. Responsive and keyboard checks (NFR-001)

Open each edit form at 360px width and on desktop: no horizontal page scroll, every field labelled,
reachable and operable by keyboard alone, visible focus.
