<?php

declare(strict_types=1);

/**
 * Form create/edit Supplier atau Customer.
 *
 * Dibagikan karena bentuk field kedua entity memang identik. Datanya tetap
 * terpisah sepenuhnya - lihat catatan pada PartyService.
 *
 * @var Csrf $csrf
 * @var object{id: int|null, name: string, contact: string, address: string}|null $party
 * @var array<string, mixed> $old
 * @var array<string, string> $errors
 * @var string $kind      "supplier" / "customer"
 * @var string $basePath
 */

use App\Support\Csrf;
use App\Support\View;

$isEdit = $party !== null;
$action = $isEdit ? $basePath . '/' . (int) $party->id : $basePath;

$value = static function (string $field, ?string $stored) use ($old): string {
    $submitted = $old[$field] ?? null;

    return is_scalar($submitted) ? (string) $submitted : ($stored ?? '');
};

$err = static fn (string $field): ?string => $errors[$field] ?? null;
?>
<div class="page-header">
    <div>
        <h1 class="page-title"><?= $isEdit ? 'Edit ' : 'Create ' ?><?= View::e($kind) ?></h1>
    </div>
    <a class="btn btn--ghost" href="<?= View::e($basePath) ?>">Back</a>
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
                           value="<?= View::e($value('name', $isEdit ? $party->name : null)) ?>"
                           <?= $err('name') !== null ? 'aria-invalid="true"' : '' ?> required>
                    <?php if ($err('name') !== null) : ?>
                        <p class="field-error"><?= View::e($err('name')) ?></p>
                    <?php endif; ?>
                </div>

                <div class="field">
                    <label class="field-label field-required" for="contact">Contact</label>
                    <input class="input" type="text" id="contact" name="contact"
                           value="<?= View::e($value('contact', $isEdit ? $party->contact : null)) ?>"
                           placeholder="Phone or email"
                           <?= $err('contact') !== null ? 'aria-invalid="true"' : '' ?> required>
                    <?php if ($err('contact') !== null) : ?>
                        <p class="field-error"><?= View::e($err('contact')) ?></p>
                    <?php endif; ?>
                </div>

                <div class="field">
                    <label class="field-label field-required" for="address">Address</label>
                    <textarea class="textarea" id="address" name="address" rows="3"
                              <?= $err('address') !== null ? 'aria-invalid="true"' : '' ?> required><?= View::e($value('address', $isEdit ? $party->address : null)) ?></textarea>
                    <?php if ($err('address') !== null) : ?>
                        <p class="field-error"><?= View::e($err('address')) ?></p>
                    <?php endif; ?>
                </div>
            </div>

            <div class="form-actions">
                <button type="submit" class="btn btn--primary">
                    <?= $isEdit ? 'Save changes' : 'Create ' . View::e($kind) ?>
                </button>
                <a class="btn btn--ghost" href="<?= View::e($basePath) ?>">Cancel</a>
            </div>
        </form>
    </div>
</div>
