<?php

declare(strict_types=1);

/**
 * Koreksi stock dari hasil hitung fisik (spec 003).
 *
 * Quantity sistem untuk warehouse terpilih ditampilkan dan ikut dikirim
 * sebagai hidden field. Bila stock berubah selama penghitungan, Service
 * menolak koreksi dan halaman ini dirender ulang dengan quantity terbaru
 * (research R-003). Tidak ada preview selisih di sisi browser: konfirmasi
 * setelah koreksi sudah menyebut perubahan persisnya (research R-009).
 *
 * @var Csrf $csrf
 * @var View $view
 * @var Product $product
 * @var list<Warehouse> $warehouses
 * @var Warehouse|null $selected
 * @var int $systemQuantity
 * @var array<string, mixed> $old
 * @var array<string, string> $errors
 */

use App\Entity\Product;
use App\Entity\Warehouse;
use App\Support\Csrf;
use App\Support\View;

$err = static fn (string $field): ?string => $errors[$field] ?? null;

/** Nilai yang dikirim ulang, atau kosong. */
$value = static function (string $field) use ($old): string {
    $submitted = $old[$field] ?? null;

    return is_string($submitted) ? $submitted : '';
};

$productUrl = '/products/' . (int) $product->id;
$formUrl = $productUrl . '/adjust-stock';
?>
<div class="page-header">
    <div>
        <h1 class="page-title">Adjust stock</h1>
        <p class="page-subtitle">
            <?= View::e($product->name) ?> · <span class="tabular"><?= View::e($product->sku) ?></span>
        </p>
    </div>
    <a class="btn btn--ghost" href="<?= View::e($productUrl) ?>">Back to product</a>
</div>

<?php if ($errors !== []) : ?>
    <div class="alert alert--error" role="alert">
        <svg class="icon" aria-hidden="true"><use href="/assets/icons/lucide-sprite.svg#alert-triangle"></use></svg>
        <div>
            <strong>The adjustment was not recorded:</strong>
            <ul>
                <?php foreach ($errors as $message) : ?>
                    <li><?= View::e($message) ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
    </div>
<?php endif; ?>

<?php if ($selected === null) : ?>
    <div class="card">
        <?= $view->renderPartial('layout/_empty-state', [
            'icon' => 'warehouse',
            'heading' => 'No active warehouse',
            'text' => 'Stock can only be adjusted in an active warehouse. Ask an Admin to activate one.',
            'actionLabel' => 'Back to product',
            'actionHref' => $productUrl,
        ]) ?>
    </div>
<?php else : ?>
    <div class="stat-grid">
        <div class="stat">
            <div class="stat-chip">
                <svg class="icon" aria-hidden="true"><use href="/assets/icons/lucide-sprite.svg#warehouse"></use></svg>
            </div>
            <div class="stat-body">
                <div class="stat-label">Warehouse</div>
                <form method="get" action="<?= View::e($formUrl) ?>" class="inline-picker">
                    <label class="visually-hidden" for="select_warehouse">Warehouse</label>
                    <select class="select" id="select_warehouse" name="warehouse_id">
                        <?php foreach ($warehouses as $warehouse) : ?>
                            <option value="<?= (int) $warehouse->id ?>"
                                <?= $warehouse->id === $selected->id ? 'selected' : '' ?>>
                                <?= View::e($warehouse->name) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <button type="submit" class="btn btn--sm">Show</button>
                </form>
            </div>
        </div>

        <div class="stat">
            <div class="stat-chip stat-chip--info">
                <svg class="icon" aria-hidden="true"><use href="/assets/icons/lucide-sprite.svg#package"></use></svg>
            </div>
            <div class="stat-body">
                <div class="stat-label">System quantity</div>
                <div class="stat-value tabular"><?= $systemQuantity ?> <?= View::e($product->unit) ?></div>
                <div class="stat-delta stat-delta--flat">
                    <span>in <?= View::e($selected->name) ?> right now</span>
                </div>
            </div>
        </div>
    </div>

    <div class="card">
        <div class="card-header">
            <h2 class="card-title">Record a count</h2>
        </div>
        <div class="card-body">
            <form method="post" action="<?= View::e($formUrl) ?>" class="stack" novalidate>
                <?= $csrf->field() ?>
                <input type="hidden" name="warehouse_id" value="<?= (int) $selected->id ?>">
                <input type="hidden" name="expected_quantity" value="<?= $systemQuantity ?>">

                <div class="form-grid">
                    <div class="field">
                        <label class="field-label field-required" for="counted_quantity">Counted quantity</label>
                        <input class="input" type="number" id="counted_quantity" name="counted_quantity"
                               min="0" step="1" inputmode="numeric"
                               value="<?= View::e($value('counted_quantity')) ?>"
                               <?= $err('counted_quantity') !== null
                                   ? 'aria-invalid="true" aria-describedby="counted_quantity-error"'
                                   : 'aria-describedby="counted_quantity-hint"' ?> required>
                        <?php if ($err('counted_quantity') !== null) : ?>
                            <p class="field-error" id="counted_quantity-error"><?= View::e($err('counted_quantity')) ?></p>
                        <?php else : ?>
                            <p class="field-hint" id="counted_quantity-hint">What you physically counted in this warehouse.</p>
                        <?php endif; ?>
                    </div>

                    <div class="field">
                        <label class="field-label field-required" for="note">Reason</label>
                        <textarea class="textarea" id="note" name="note" rows="3" maxlength="255"
                                  <?= $err('note') !== null
                                      ? 'aria-invalid="true" aria-describedby="note-error"'
                                      : 'aria-describedby="note-hint"' ?> required><?= View::e($value('note')) ?></textarea>
                        <?php if ($err('note') !== null) : ?>
                            <p class="field-error" id="note-error"><?= View::e($err('note')) ?></p>
                        <?php else : ?>
                            <p class="field-hint" id="note-hint">Why the count differs, e.g. damaged, lost, found.</p>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="form-actions">
                    <button type="submit" class="btn btn--primary">Record adjustment</button>
                    <a class="btn btn--ghost" href="<?= View::e($productUrl) ?>">Cancel</a>
                </div>
            </form>
        </div>
    </div>
<?php endif; ?>
