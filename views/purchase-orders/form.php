<?php

declare(strict_types=1);

/**
 * Form create Purchase Order (PO-01, FR-012, FR-029).
 *
 * Line dinamis memakai order-lines.js, modul yang sama dengan form Sales
 * Order — struktur tabelnya sengaja identik supaya satu modul melayani
 * keduanya. Tanpa JavaScript tetap ada tiga baris kosong dari server.
 *
 * @var Csrf $csrf
 * @var array<string, mixed> $old
 * @var array<string, string> $errors
 * @var list<Supplier> $suppliers
 * @var list<Warehouse> $warehouses
 * @var list<Product> $products
 * @var string $today
 */

use App\Entity\Product;
use App\Entity\Supplier;
use App\Entity\Warehouse;
use App\Support\Csrf;
use App\Support\Money;
use App\Support\View;

$value = static function (string $field, string $fallback = '') use ($old): string {
    $submitted = $old[$field] ?? null;

    return is_scalar($submitted) ? (string) $submitted : $fallback;
};

$err = static fn (string $field): ?string => $errors[$field] ?? null;

$submittedItems = is_array($old['items'] ?? null) ? array_values($old['items']) : [];
$rowCount = max(count($submittedItems), 3);
?>
<div class="page-header">
    <div>
        <h1 class="page-title">Create purchase order</h1>
        <p class="page-subtitle">
            The order starts as a draft. No stock moves until goods are received against it.
        </p>
    </div>
    <a class="btn btn--ghost" href="/purchase-orders">Cancel</a>
</div>

<?php if ($errors !== []) : ?>
    <div class="alert alert--error" role="alert">
        <svg class="icon" aria-hidden="true"><use href="/assets/icons/lucide-sprite.svg#alert-triangle"></use></svg>
        <div>
            <strong>Please correct the following:</strong>
            <ul>
                <?php foreach ($errors as $message) : ?>
                    <li><?= View::e($message) ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
    </div>
<?php endif; ?>

<form method="post" action="/purchase-orders" id="purchase-order-form"
      data-confirm="Create this purchase order as a draft?">
    <?= $csrf->field() ?>

    <div class="card">
        <div class="card-header">
            <h2 class="card-title">Order details</h2>
        </div>
        <div class="card-body">
            <div class="form-grid">
                <div class="field">
                    <label class="field-label field-required" for="supplier_id">Supplier</label>
                    <select class="select" id="supplier_id" name="supplier_id" required>
                        <option value="">Select a supplier</option>
                        <?php foreach ($suppliers as $supplier) : ?>
                            <option value="<?= (int) $supplier->id ?>"
                                <?= $value('supplier_id') === (string) $supplier->id ? 'selected' : '' ?>>
                                <?= View::e($supplier->name) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <?php if ($err('supplier_id') !== null) : ?>
                        <p class="field-error"><?= View::e((string) $err('supplier_id')) ?></p>
                    <?php endif; ?>
                </div>

                <div class="field">
                    <label class="field-label field-required" for="warehouse_id">Destination warehouse</label>
                    <select class="select" id="warehouse_id" name="warehouse_id" required>
                        <option value="">Select a warehouse</option>
                        <?php foreach ($warehouses as $warehouse) : ?>
                            <option value="<?= (int) $warehouse->id ?>"
                                <?= $value('warehouse_id') === (string) $warehouse->id ? 'selected' : '' ?>>
                                <?= View::e($warehouse->name) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <p class="field-hint">Received goods are added to this warehouse.</p>
                    <?php if ($err('warehouse_id') !== null) : ?>
                        <p class="field-error"><?= View::e((string) $err('warehouse_id')) ?></p>
                    <?php endif; ?>
                </div>

                <div class="field">
                    <label class="field-label" for="order_date">Order date</label>
                    <input class="input" type="date" id="order_date" name="order_date"
                           value="<?= View::e($value('order_date', $today)) ?>">
                    <?php if ($err('order_date') !== null) : ?>
                        <p class="field-error"><?= View::e((string) $err('order_date')) ?></p>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <div class="card">
        <div class="card-header">
            <h2 class="card-title">Order lines</h2>
            <button type="button" class="btn btn--sm" id="add-line">
                <svg class="icon" aria-hidden="true"><use href="/assets/icons/lucide-sprite.svg#plus"></use></svg>
                <span>Add line</span>
            </button>
        </div>

        <?php if ($err('items') !== null) : ?>
            <div class="card-body">
                <p class="field-error"><?= View::e((string) $err('items')) ?></p>
            </div>
        <?php endif; ?>

        <div class="table-wrap">
            <table class="table" id="line-table">
                <thead>
                    <tr>
                        <th scope="col">Product</th>
                        <th scope="col" class="numeric">Quantity</th>
                        <th scope="col"><span class="visually-hidden">Remove</span></th>
                    </tr>
                </thead>
                <tbody>
                    <?php for ($index = 0; $index < $rowCount; $index++) :
                        $item = $submittedItems[$index] ?? [];
                        $selectedProduct = is_array($item) ? (string) ($item['product_id'] ?? '') : '';
                        $quantity = is_array($item) ? (string) ($item['quantity'] ?? '') : '';
                        ?>
                        <tr class="line-row">
                            <td>
                                <label class="visually-hidden" for="items-<?= $index ?>-product">Product</label>
                                <select class="select" id="items-<?= $index ?>-product"
                                        name="items[<?= $index ?>][product_id]">
                                    <option value="">Select a product</option>
                                    <?php foreach ($products as $product) : ?>
                                        <option value="<?= (int) $product->id ?>"
                                            <?= $selectedProduct === (string) $product->id ? 'selected' : '' ?>>
                                            <?= View::e($product->name) ?>
                                            (<?= View::e($product->sku) ?>)
                                            — <?= View::e(Money::format($product->purchasePrice)) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <?php if ($err('items.' . $index . '.product_id') !== null) : ?>
                                    <p class="field-error">
                                        <?= View::e((string) $err('items.' . $index . '.product_id')) ?>
                                    </p>
                                <?php endif; ?>
                            </td>
                            <td class="numeric">
                                <label class="visually-hidden" for="items-<?= $index ?>-quantity">Quantity</label>
                                <input class="input tabular" type="number" min="1" step="1"
                                       id="items-<?= $index ?>-quantity"
                                       name="items[<?= $index ?>][quantity]"
                                       value="<?= View::e($quantity) ?>">
                                <?php if ($err('items.' . $index . '.quantity') !== null) : ?>
                                    <p class="field-error">
                                        <?= View::e((string) $err('items.' . $index . '.quantity')) ?>
                                    </p>
                                <?php endif; ?>
                            </td>
                            <td>
                                <button type="button" class="btn btn--sm btn--ghost remove-line">Remove</button>
                            </td>
                        </tr>
                    <?php endfor; ?>
                </tbody>
            </table>
        </div>

        <div class="card-body">
            <p class="field-hint">
                Unit cost is taken from the catalog purchase price when the order is created, so a
                later price change does not alter this order.
            </p>
        </div>
    </div>

    <div class="form-actions">
        <button type="submit" class="btn btn--primary">Create draft order</button>
        <a class="btn btn--ghost" href="/purchase-orders">Cancel</a>
    </div>
</form>
