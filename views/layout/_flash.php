<?php

declare(strict_types=1);

/**
 * Pesan sekali-tampil setelah redirect.
 *
 * @var Session $session
 */

use App\Support\Session;
use App\Support\View;

$flash = $session->pullFlash();

if ($flash === null) {
    return;
}

$variant = match ($flash['type']) {
    'success' => 'alert--success',
    'warning' => 'alert--warning',
    'error'   => 'alert--error',
    default   => 'alert--info',
};

$icon = match ($flash['type']) {
    'success' => 'check-circle',
    'warning' => 'alert-triangle',
    'error'   => 'x-circle',
    default   => 'inbox',
};
?>
<div class="alert <?= View::e($variant) ?>" role="status">
    <svg class="icon" aria-hidden="true"><use href="/assets/icons/lucide-sprite.svg#<?= View::e($icon) ?>"></use></svg>
    <span><?= View::e($flash['message']) ?></span>
</div>
