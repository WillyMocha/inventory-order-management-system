<?php

declare(strict_types=1);

/**
 * Form create/edit user.
 *
 * Kegagalan validasi ditampilkan DUA kali: ringkasan di atas form dan pesan
 * pada masing-masing field. Nilai yang sudah diisi dipertahankan; password
 * tidak pernah dikembalikan ke form (FR-029).
 *
 * @var Csrf $csrf
 * @var User|null $user
 * @var array<string, mixed> $old
 * @var array<string, string> $errors
 * @var list<Role> $roles
 */

use App\Entity\Enum\Role;
use App\Entity\User;
use App\Support\Csrf;
use App\Support\View;

$isEdit = $user instanceof User;
$action = $isEdit ? '/users/' . (int) $user->id : '/users';

/** Nilai yang dikirim ulang > nilai tersimpan > kosong. */
$value = static function (string $field, ?string $stored) use ($old): string {
    $submitted = $old[$field] ?? null;

    return is_string($submitted) ? $submitted : ($stored ?? '');
};

$err = static fn (string $field): ?string => $errors[$field] ?? null;
?>
<div class="page-header">
    <div>
        <h1 class="page-title"><?= $isEdit ? 'Edit user' : 'Create user' ?></h1>
        <p class="page-subtitle">
            <?= $isEdit
                ? 'Change the name, email, or role. Password is changed separately.'
                : 'The new account can sign in immediately.' ?>
        </p>
    </div>
    <a class="btn btn--ghost" href="/users">Back to users</a>
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
                           value="<?= View::e($value('name', $isEdit ? $user->name : null)) ?>"
                           <?= $err('name') !== null ? 'aria-invalid="true"' : '' ?> required>
                    <?php if ($err('name') !== null) : ?>
                        <p class="field-error"><?= View::e($err('name')) ?></p>
                    <?php endif; ?>
                </div>

                <div class="field">
                    <label class="field-label field-required" for="email">Email</label>
                    <input class="input" type="email" id="email" name="email"
                           value="<?= View::e($value('email', $isEdit ? $user->email : null)) ?>"
                           <?= $err('email') !== null ? 'aria-invalid="true"' : '' ?> required>
                    <?php if ($err('email') !== null) : ?>
                        <p class="field-error"><?= View::e($err('email')) ?></p>
                    <?php else : ?>
                        <p class="field-hint">Must be unique across all accounts.</p>
                    <?php endif; ?>
                </div>

                <div class="field">
                    <label class="field-label field-required" for="role">Role</label>
                    <select class="select" id="role" name="role"
                            <?= $err('role') !== null ? 'aria-invalid="true"' : '' ?> required>
                        <option value="">Select a role</option>
                        <?php
                        $selectedRole = $value('role', $isEdit ? $user->role->value : null);
                        foreach ($roles as $role) : ?>
                            <option value="<?= View::e($role->value) ?>"
                                <?= $selectedRole === $role->value ? 'selected' : '' ?>>
                                <?= View::e($role->label()) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <?php if ($err('role') !== null) : ?>
                        <p class="field-error"><?= View::e($err('role')) ?></p>
                    <?php endif; ?>
                </div>

                <?php if (!$isEdit) : ?>
                    <div class="field">
                        <label class="field-label field-required" for="password">Password</label>
                        <input class="input" type="password" id="password" name="password"
                               autocomplete="new-password"
                               <?= $err('password') !== null ? 'aria-invalid="true"' : '' ?> required>
                        <?php if ($err('password') !== null) : ?>
                            <p class="field-error"><?= View::e($err('password')) ?></p>
                        <?php else : ?>
                            <p class="field-hint">At least 8 characters.</p>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
            </div>

            <div class="form-actions">
                <button type="submit" class="btn btn--primary">
                    <?= $isEdit ? 'Save changes' : 'Create user' ?>
                </button>
                <a class="btn btn--ghost" href="/users">Cancel</a>
            </div>
        </form>
    </div>
</div>

<?php if ($isEdit) : ?>
    <div class="card" style="margin-top: var(--space-6)">
        <div class="card-header">
            <h2 class="card-title">Change password</h2>
        </div>
        <div class="card-body">
            <p class="field-hint" style="margin-bottom: var(--space-4)">
                For your security, confirm your own password before setting a new one for this user.
            </p>
            <form method="post" action="/users/<?= (int) $user->id ?>/password" class="stack" novalidate>
                <?= $csrf->field() ?>
                <div class="form-grid">
                    <div class="field">
                        <label class="field-label field-required" for="current_password">Your password</label>
                        <input class="input" type="password" id="current_password" name="current_password"
                               autocomplete="current-password"
                               <?= $err('current_password') !== null ? 'aria-invalid="true"' : '' ?> required>
                        <?php if ($err('current_password') !== null) : ?>
                            <p class="field-error"><?= View::e($err('current_password')) ?></p>
                        <?php endif; ?>
                    </div>
                    <div class="field">
                        <label class="field-label field-required" for="new_password">New password for this user</label>
                        <input class="input" type="password" id="new_password" name="new_password"
                               autocomplete="new-password" required>
                    </div>
                </div>
                <div class="form-actions">
                    <button type="submit" class="btn">Update password</button>
                </div>
            </form>
        </div>
    </div>
<?php endif; ?>
