<?php

declare(strict_types=1);

/**
 * Halaman login.
 *
 * Satu pesan error seragam di atas form. TIDAK ada petunjuk per field tentang
 * bagian mana yang keliru - itu akan membocorkan email mana yang terdaftar
 * (FR-002, security standard §7).
 *
 * Email dipertahankan setelah gagal; password tidak pernah.
 *
 * @var Csrf $csrf
 * @var string $email
 * @var string|null $error
 */

use App\Support\Csrf;
use App\Support\View;

$hasError = isset($error) && $error !== null && $error !== '';
?>
<div class="stack">
    <div>
        <h1 class="page-title">Sign in</h1>
        <p class="page-subtitle">Inventory &amp; Order Management System</p>
    </div>

    <?php if ($hasError) : ?>
        <div class="alert alert--error" role="alert">
            <svg class="icon" aria-hidden="true">
                <use href="/assets/icons/lucide-sprite.svg#x-circle"></use>
            </svg>
            <span><?= View::e($error) ?></span>
        </div>
    <?php endif; ?>

    <form method="post" action="/login" class="stack" novalidate>
        <?= $csrf->field() ?>

        <div class="field">
            <label class="field-label field-required" for="email">Email</label>
            <input
                class="input"
                type="email"
                id="email"
                name="email"
                value="<?= View::e($email) ?>"
                autocomplete="username"
                required
                autofocus
            >
        </div>

        <div class="field">
            <label class="field-label field-required" for="password">Password</label>
            <input
                class="input"
                type="password"
                id="password"
                name="password"
                autocomplete="current-password"
                required
            >
        </div>

        <div class="form-actions">
            <button type="submit" class="btn btn--primary" data-submit-label="Signing in…">
                <svg class="icon" aria-hidden="true">
                    <use href="/assets/icons/lucide-sprite.svg#log-in"></use>
                </svg>
                <span>Sign in</span>
            </button>
        </div>
    </form>

    <p class="field-hint">
        Accounts are created by an administrator. There is no public registration.
    </p>
</div>
