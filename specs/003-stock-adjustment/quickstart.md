# Quickstart: Stock Adjustment

Manual verification on the running stack. Demo accounts use `Password123!`.

## 0. Start

```bash
docker compose up -d                 # the entrypoint applies 004_ledger_note.sql automatically
docker compose exec app php database/migrate.php   # prints nothing new if already applied
```

Pick a product with stock, e.g. `SKU-000001` in "Gudang Pusat Jakarta", and note its quantity (call it Q).

## 1. Record a decrease (US1, FR-001 … FR-005, FR-008)

1. Sign in as `warehouse1@ioms.test`, open the product, click **Adjust stock**.
2. Warehouse "Gudang Pusat Jakarta" shows system quantity Q. Enter counted `Q − 3` and reason
   "3 units water-damaged". Click **Record adjustment**.
3. You return to the product with "Stock adjusted in Gudang Pusat Jakarta: Q → Q−3 (−3)." The
   warehouse row shows Q−3, and **Stock adjustments** lists the row with your name and reason.

## 2. Increase, and a warehouse never stocked (US1-2, US1-3)

1. As `admin@ioms.test`, adjust the same product in "Gudang Surabaya" (or any active warehouse where it
   shows 0) to 5 with a reason. The row becomes 5 and a `+5` adjustment is listed.

## 3. Refusals (FR-002 … FR-004, SC-004)

| Try | Expected |
| --- | --- |
| Counted = current quantity | 422, "The count matches the system quantity — nothing to adjust." |
| Empty reason, or counted `-1` / `2.5` / empty | 422 with field messages; values kept |
| Open the form, then in another tab issue goods for an order of this product and warehouse, then submit the first form | 422, "The stock in this warehouse changed to N…"; system quantity refreshed |
| As `sales1@ioms.test`, open `/products/1/adjust-stock` or POST to it | 403 |

After each refusal the product's quantity and the adjustment list are unchanged.

## 4. Trace (US2, FR-009, FR-010, NFR-001)

1. Reports → export the stock movement CSV for today. The adjustments appear with movement type
   `Adjustment`, reference type `Manual`, signed quantity, and the reason in the last column.
2. Record one adjustment with the reason `=1+1`. In the CSV that cell reads `'=1+1`.

## 5. Invariant (SC-002)

```bash
docker compose exec app composer test:integration   # LedgerReconciliationTest, StockAdjustment*Test
```

## 6. Restore demo data

Record opposite adjustments with reason "Restore demo quantity", or run `composer db:reset`.

## Automated checks

```bash
docker compose exec app composer check
```
