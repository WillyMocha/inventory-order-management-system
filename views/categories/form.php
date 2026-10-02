<?php

declare(strict_types=1);

/**
 * Form create/edit category.
 *
 * @var Csrf $csrf
 * @var Category|null $category
 * @var array<string, mixed> $old
 * @var array<string, string> $errors
 */

use App\Entity\Category;
use App\Support\Csrf;
use App\Support\View;

$isEdit = $category instanceof Category;
$action = $isEdit ? '/categories/' . (int) $category->id : '/categories';

$value = static function (string $field, ?string $stored) use ($old): string {
    $submitted = $old[$field] ?? null;

    return is_scalar($submitted) ? (string) $submitted : ($stored ?? '');
};

$err = static fn (string $field): ?string => $errors[$field] ?? null;
?>
<div class="page-header">
    <div>
        <h1 class="page-title"><?= $isEdit ? 'Edit category' : 'Create category' ?></h1>
    </div>
    <a class="btn btn--ghost" href="/categories">Back to categories</a>
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
        <form method="post" action="<?= View::e($action) ?>" class="stack" novalidate>
            <?= $csrf->field() ?>

            <div class="form-grid">
                <div class="field">
                    <label class="field-label field-required" for="name">Name</label>
                    <input class="input" type="text" id="name" name="name"
                           value="<?= View::e($value('name', $isEdit ? $category->name : null)) ?>"
                           <?= $err('name') !== null ? 'aria-invalid="true"' : '' ?> required>
                    <?php if ($err('name') !== null) : ?>
                        <p class="field-error"><?= View::e($err('name')) ?></p>
                    <?php endif; ?>
                </div>

                <div class="field">
                    <label class="field-label" for="description">Description</label>
                    <textarea class="textarea" id="description" name="description" rows="3"><?= View::e($value('description', $isEdit ? $category->description : null)) ?></textarea>
                </div>
            </div>

            <div class="form-actions">
                <button type="submit" class="btn btn--primary"><?= $isEdit ? 'Save changes' : 'Create category' ?></button>
                <a class="btn btn--ghost" href="/categories">Cancel</a>
            </div>
        </form>
    </div>
</div>
