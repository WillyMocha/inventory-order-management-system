<?php

declare(strict_types=1);

/**
 * Empty state yang informatif — ikon, judul, teks bantuan, dan satu aksi.
 *
 * Dipakai untuk DUA keadaan berbeda yang tidak boleh disamakan:
 *   1. belum ada data sama sekali  -> ajak membuat data pertama
 *   2. filter tidak menemukan apa pun -> tawarkan menghapus filter
 *
 * @var string $icon
 * @var string $heading
 * @var string $text
 * @var string|null $actionLabel
 * @var string|null $actionHref
 */

use App\Support\View;

$hasAction = isset($actionLabel, $actionHref) && $actionLabel !== '' && $actionHref !== '';
?>
<div class="empty-state">
    <div class="empty-state-icon">
        <svg class="icon icon--lg" aria-hidden="true"><use href="/assets/icons/lucide-sprite.svg#<?= View::e($icon) ?>"></use></svg>
    </div>
    <p class="empty-state-title"><?= View::e($heading) ?></p>
    <p class="empty-state-text"><?= View::e($text) ?></p>
    <?php if ($hasAction) : ?>
        <a class="btn btn--primary" href="<?= View::e($actionHref) ?>">
            <?= View::e($actionLabel) ?>
        </a>
    <?php endif; ?>
</div>
