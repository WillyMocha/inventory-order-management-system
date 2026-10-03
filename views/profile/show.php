<?php

declare(strict_types=1);

/**
 * Profil milik user yang sedang login (002-user-profile-page).
 *
 * Nama, email, role, dan status hanya ditampilkan - tidak ada input untuk
 * field tersebut, karena perubahannya tetap wewenang Admin (FR-011).
 *
 * @var Csrf $csrf
 * @var User $user
 * @var array<string, string> $errors
 * @var bool $rateLimited
 */

use App\Entity\Enum\Role;
use App\Entity\User;
use App\Support\Csrf;
use App\Support\View;

// Warna dan ikon role sama dengan stat tile pada halaman Users.
$roleTone = [
    Role::Admin->value => 'info',
    Role::Sales->value => 'success',
    Role::WarehouseStaff->value => 'warning',
];

$roleIcon = [
    Role::Admin->value => 'users',
    Role::Sales->value => 'shopping-cart',
    Role::WarehouseStaff->value => 'warehouse',
];

$err = static fn (string $field): ?string => $errors[$field] ?? null;
?>
<div class="page-header">
    <div>
        <h1 class="page-title">My profile</h1>
        <p class="page-subtitle">Your account details.</p>
    </div>
</div>

<div class="stat-grid">
    <div class="stat">
        <div class="stat-chip">
            <svg class="icon" aria-hidden="true"><use href="/assets/icons/lucide-sprite.svg#users"></use></svg>
        </div>
        <div class="stat-body">
            <div class="stat-label">Name</div>
            <div class="stat-value stat-value--text"><?= View::e($user->name) ?></div>
        </div>
    </div>

    <div class="stat">
        <div class="stat-chip stat-chip--info">
            <svg class="icon" aria-hidden="true"><use href="/assets/icons/lucide-sprite.svg#inbox"></use></svg>
        </div>
        <div class="stat-body">
            <div class="stat-label">Email</div>
            <div class="stat-value stat-value--text"><?= View::e($user->email) ?></div>
        </div>
    </div>

    <div class="stat">
        <div class="stat-chip stat-chip--<?= View::e($roleTone[$user->role->value]) ?>">
            <svg class="icon" aria-hidden="true">
                <use href="/assets/icons/lucide-sprite.svg#<?= View::e($roleIcon[$user->role->value]) ?>"></use>
            </svg>
        </div>
        <div class="stat-body">
            <div class="stat-label">Role</div>
            <div class="stat-value stat-value--text"><?= View::e($user->role->label()) ?></div>
        </div>
    </div>

    <div class="stat">
        <div class="stat-chip stat-chip--<?= $user->isActive ? 'success' : 'danger' ?>">
            <svg class="icon" aria-hidden="true">
                <use href="/assets/icons/lucide-sprite.svg#<?= $user->isActive ? 'check-circle' : 'x-circle' ?>"></use>
            </svg>
        </div>
        <div class="stat-body">
            <div class="stat-label">Status</div>
            <div class="stat-value stat-value--text">
                <span class="badge <?= $user->isActive ? 'badge--fulfilled' : 'badge--cancelled' ?>">
                    <?= $user->isActive ? 'Active' : 'Inactive' ?>
                </span>
            </div>
        </div>
    </div>
</div>

<?php if ($rateLimited) : ?>
    <div class="alert alert--error" role="alert">
        <svg class="icon" aria-hidden="true"><use href="/assets/icons/lucide-sprite.svg#clock"></use></svg>
        <div>Too many incorrect attempts. Try again later.</div>
    </div>
<?php endif; ?>

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
    <div class="card-header">
        <h2 class="card-title">Change password</h2>
    </div>
    <div class="card-body">
        <?php /* Input password tidak pernah diberi atribut value (NFR-001). */ ?>
        <form method="post" action="/profile/password" class="stack" novalidate>
            <?= $csrf->field() ?>
            <div class="form-grid">
                <div class="field">
                    <label class="field-label field-required" for="current_password">Current password</label>
                    <input class="input" type="password" id="current_password" name="current_password"
                           autocomplete="current-password"
                           <?= $err('current_password') !== null ? 'aria-invalid="true" aria-describedby="current_password-error"' : '' ?> required>
                    <?php if ($err('current_password') !== null) : ?>
                        <p class="field-error" id="current_password-error"><?= View::e($err('current_password')) ?></p>
                    <?php endif; ?>
                </div>

                <div class="field">
                    <label class="field-label field-required" for="new_password">New password</label>
                    <input class="input" type="password" id="new_password" name="new_password"
                           autocomplete="new-password"
                           <?= $err('new_password') !== null ? 'aria-invalid="true" aria-describedby="new_password-error"' : 'aria-describedby="new_password-hint"' ?> required>
                    <?php if ($err('new_password') !== null) : ?>
                        <p class="field-error" id="new_password-error"><?= View::e($err('new_password')) ?></p>
                    <?php else : ?>
                        <p class="field-hint" id="new_password-hint">At least <?= User::MIN_PASSWORD_LENGTH ?> characters.</p>
                    <?php endif; ?>
                </div>

                <div class="field">
                    <label class="field-label field-required" for="new_password_confirmation">Confirm new password</label>
                    <input class="input" type="password" id="new_password_confirmation" name="new_password_confirmation"
                           autocomplete="new-password"
                           <?= $err('new_password_confirmation') !== null ? 'aria-invalid="true" aria-describedby="new_password_confirmation-error"' : '' ?> required>
                    <?php if ($err('new_password_confirmation') !== null) : ?>
                        <p class="field-error" id="new_password_confirmation-error"><?= View::e($err('new_password_confirmation')) ?></p>
                    <?php endif; ?>
                </div>
            </div>

            <div class="form-actions">
                <button type="submit" class="btn btn--primary">Change password</button>
            </div>
        </form>
    </div>
</div>
