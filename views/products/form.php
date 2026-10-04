<?php

declare(strict_types=1);

/**
 * Form create/edit product (PRD-01).
 *
 * Nilai yang sudah diisi dipertahankan saat validasi gagal, dan kesalahan
 * tampil dua kali: ringkasan di atas plus pesan per field (FR-029).
 *
 * @var Csrf $csrf
 * @var Product|null $product
 * @var string|null $nextSku pratinjau SKU untuk product baru; null saat edit
 * @var array<string, mixed> $old
 * @var array<string, string> $errors
 * @var list<Category> $categories
 */

use App\Entity\Category;
use App\Entity\Product;
use App\Support\Csrf;
use App\Support\View;

$isEdit = $product instanceof Product;
$action = $isEdit ? '/products/' . (int) $product->id : '/products';

$value = static function (string $field, ?string $stored) use ($old): string {
    $submitted = $old[$field] ?? null;

    return is_scalar($submitted) ? (string) $submitted : ($stored ?? '');
};

$err = static fn (string $field): ?string => $errors[$field] ?? null;
?>
<div class="page-header">
    <div>
        <h1 class="page-title"><?= $isEdit ? 'Edit product' : 'Create product' ?></h1>
        <p class="page-subtitle">Stock levels are never edited here — they change only through goods receipt and goods issue.</p>
    </div>
    <a class="btn btn--ghost" href="<?= $isEdit ? '/products/' . (int) $product->id : '/products' ?>">Cancel</a>
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

<div class="card">
    <div class="card-body">
        <form method="post" action="<?= View::e($action) ?>" class="stack" enctype="multipart/form-data" novalidate>
            <?= $csrf->field() ?>

            <div class="form-grid">
                <?php /* SKU dibuat server dan tidak pernah berubah, sehingga field-nya
                         read-only dan sengaja tanpa atribut name: nilainya tidak ikut
                         dikirim, dan server mengabaikan `sku` dari request. */ ?>
                <div class="field">
                    <label class="field-label" for="sku">SKU</label>
                    <input class="input tabular" type="text" id="sku"
                           value="<?= View::e($isEdit ? $product->sku : (string) $nextSku) ?>"
                           aria-describedby="sku-hint" readonly>
                    <p class="field-hint" id="sku-hint">
                        <?= $isEdit
                            ? 'Assigned when the product was created. It never changes.'
                            : 'Assigned automatically when you save. It never changes afterwards.' ?>
                    </p>
                </div>

                <div class="field">
                    <label class="field-label field-required" for="name">Name</label>
                    <input class="input" type="text" id="name" name="name"
                           value="<?= View::e($value('name', $isEdit ? $product->name : null)) ?>"
                           <?= $err('name') !== null ? 'aria-invalid="true"' : '' ?> required>
                    <?php if ($err('name') !== null) : ?>
                        <p class="field-error"><?= View::e($err('name')) ?></p>
                    <?php endif; ?>
                </div>

                <div class="field">
                    <label class="field-label field-required" for="category_id">Category</label>
                    <select class="select" id="category_id" name="category_id"
                            <?= $err('category_id') !== null ? 'aria-invalid="true"' : '' ?> required>
                        <option value="">Select a category</option>
                        <?php
                        $selected = $value('category_id', $isEdit ? (string) $product->categoryId : null);
                        foreach ($categories as $category) : ?>
                            <option value="<?= (int) $category->id ?>"
                                <?= $selected === (string) $category->id ? 'selected' : '' ?>>
                                <?= View::e($category->name) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <?php if ($err('category_id') !== null) : ?>
                        <p class="field-error"><?= View::e($err('category_id')) ?></p>
                    <?php endif; ?>
                </div>

                <div class="field">
                    <label class="field-label field-required" for="unit">Unit</label>
                    <input class="input" type="text" id="unit" name="unit"
                           value="<?= View::e($value('unit', $isEdit ? $product->unit : null)) ?>"
                           placeholder="pcs, box, roll"
                           <?= $err('unit') !== null ? 'aria-invalid="true"' : '' ?> required>
                    <?php if ($err('unit') !== null) : ?>
                        <p class="field-error"><?= View::e($err('unit')) ?></p>
                    <?php endif; ?>
                </div>

                <div class="field">
                    <label class="field-label field-required" for="purchase_price">Purchase price (IDR)</label>
                    <input class="input tabular" type="number" id="purchase_price" name="purchase_price"
                           min="0" step="1"
                           value="<?= View::e($value('purchase_price', $isEdit ? $product->purchasePrice : null)) ?>"
                           <?= $err('purchase_price') !== null ? 'aria-invalid="true"' : '' ?> required>
                    <?php if ($err('purchase_price') !== null) : ?>
                        <p class="field-error"><?= View::e($err('purchase_price')) ?></p>
                    <?php endif; ?>
                </div>

                <div class="field">
                    <label class="field-label field-required" for="selling_price">Selling price (IDR)</label>
                    <input class="input tabular" type="number" id="selling_price" name="selling_price"
                           min="0" step="1"
                           value="<?= View::e($value('selling_price', $isEdit ? $product->sellingPrice : null)) ?>"
                           <?= $err('selling_price') !== null ? 'aria-invalid="true"' : '' ?> required>
                    <?php if ($err('selling_price') !== null) : ?>
                        <p class="field-error"><?= View::e($err('selling_price')) ?></p>
                    <?php endif; ?>
                </div>

                <div class="field">
                    <label class="field-label field-required" for="reorder_point">Reorder point</label>
                    <input class="input tabular" type="number" id="reorder_point" name="reorder_point"
                           min="0" step="1"
                           value="<?= View::e($value('reorder_point', $isEdit ? (string) $product->reorderPoint : '0')) ?>"
                           <?= $err('reorder_point') !== null ? 'aria-invalid="true"' : '' ?> required>
                    <?php if ($err('reorder_point') !== null) : ?>
                        <p class="field-error"><?= View::e($err('reorder_point')) ?></p>
                    <?php else : ?>
                        <p class="field-hint">Flagged as low stock at or below this total.</p>
                    <?php endif; ?>
                </div>

                <div class="field">
                    <label class="field-label" for="image">Image (optional)</label>
                    <input class="input" type="file" id="image" name="image"
                           accept="image/jpeg,image/png,image/webp"
                           <?= $err('image') !== null ? 'aria-invalid="true"' : '' ?>>
                    <?php if ($err('image') !== null) : ?>
                        <p class="field-error"><?= View::e($err('image')) ?></p>
                    <?php else : ?>
                        <p class="field-hint">JPEG, PNG, or WebP. Up to 2 MB.</p>
                    <?php endif; ?>
                </div>
            </div>

            <div class="form-actions">
                <button type="submit" class="btn btn--primary">
                    <?= $isEdit ? 'Save changes' : 'Create product' ?>
                </button>
                <a class="btn btn--ghost" href="<?= $isEdit ? '/products/' . (int) $product->id : '/products' ?>">Cancel</a>
            </div>
        </form>
    </div>
</div>
