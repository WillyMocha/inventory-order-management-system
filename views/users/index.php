<?php

declare(strict_types=1);

/**
 * Daftar user (USR-01).
 *
 * Dua empty state yang berbeda dan tidak boleh disamakan:
 *   - belum ada user sama sekali -> ajak membuat user pertama
 *   - filter tidak menemukan apa pun -> tawarkan menghapus filter
 *
 * @var View $view
 * @var Csrf $csrf
 * @var list<User> $users
 * @var Paginator $paginator
 * @var string $basePath
 * @var array<string, string> $filters
 * @var bool $hasFilters
 * @var list<Role> $roles
 * @var array<string, array{total: int, active: int}> $counts
 */

use App\Entity\Enum\Role;
use App\Entity\User;
use App\Support\Csrf;
use App\Support\Paginator;
use App\Support\View;

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
?>
<div class="page-header">
    <div>
        <h1 class="page-title">Users</h1>
        <p class="page-subtitle">Accounts are created here. There is no public registration.</p>
    </div>
    <a class="btn btn--primary" href="/users/create">
        <svg class="icon" aria-hidden="true"><use href="/assets/icons/lucide-sprite.svg#plus"></use></svg>
        <span>Create user</span>
    </a>
</div>

<div class="stat-grid">
    <?php foreach ($roles as $role) : ?>
        <div class="stat">
            <div class="stat-chip stat-chip--<?= View::e($roleTone[$role->value]) ?>">
                <svg class="icon" aria-hidden="true">
                    <use href="/assets/icons/lucide-sprite.svg#<?= View::e($roleIcon[$role->value]) ?>"></use>
                </svg>
            </div>
            <div class="stat-body">
                <div class="stat-label"><?= View::e($role->label()) ?></div>
                <?php
                $total = $counts[$role->value]['total'] ?? 0;
                $active = $counts[$role->value]['active'] ?? 0;
                $inactive = $total - $active;
                ?>
                <div class="stat-value tabular"><?= $total ?></div>
                <div class="stat-delta stat-delta--<?= $inactive > 0 ? 'down' : 'up' ?>">
                    <svg class="icon" aria-hidden="true" style="width:14px;height:14px">
                        <use href="/assets/icons/lucide-sprite.svg#<?= $inactive > 0 ? 'alert-triangle' : 'check-circle' ?>"></use>
                    </svg>
                    <span>
                        <?= $inactive > 0
                            ? $inactive . ' inactive'
                            : 'all active' ?>
                    </span>
                </div>
            </div>
        </div>
    <?php endforeach; ?>
</div>

<div class="card">
    <form class="toolbar" method="get" action="/users">
        <div class="field">
            <label class="field-label" for="search">Search</label>
            <input class="input" type="search" id="search" name="search"
                   value="<?= View::e($filters['search'] ?? '') ?>" placeholder="Name or email">
        </div>
        <div class="field">
            <label class="field-label" for="role">Role</label>
            <select class="select" id="role" name="role">
                <option value="">All roles</option>
                <?php foreach ($roles as $role) : ?>
                    <option value="<?= View::e($role->value) ?>"
                        <?= ($filters['role'] ?? '') === $role->value ? 'selected' : '' ?>>
                        <?= View::e($role->label()) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <button type="submit" class="btn">
            <svg class="icon" aria-hidden="true"><use href="/assets/icons/lucide-sprite.svg#search"></use></svg>
            <span>Apply</span>
        </button>
        <?php if ($hasFilters) : ?>
            <a class="btn btn--ghost" href="/users">
                <svg class="icon" aria-hidden="true"><use href="/assets/icons/lucide-sprite.svg#filter-x"></use></svg>
                <span>Clear</span>
            </a>
        <?php endif; ?>
    </form>

    <?php if ($users === [] && $hasFilters) : ?>
        <?= $view->renderPartial('layout/_empty-state', [
            'icon' => 'filter-x',
            'heading' => 'No users match these filters',
            'text' => 'Try a different search term, or clear the filters to see every account.',
            'actionLabel' => 'Clear filters',
            'actionHref' => '/users',
        ]) ?>
    <?php elseif ($users === []) : ?>
        <?= $view->renderPartial('layout/_empty-state', [
            'icon' => 'users',
            'heading' => 'No users yet',
            'text' => 'Every account is created by an administrator. Add the first Sales or Warehouse Staff member to get started.',
            'actionLabel' => 'Create user',
            'actionHref' => '/users/create',
        ]) ?>
    <?php else : ?>
        <div class="table-wrap">
            <table class="table">
                <thead>
                    <tr>
                        <th scope="col">Name</th>
                        <th scope="col">Email</th>
                        <th scope="col">Role</th>
                        <th scope="col">Status</th>
                        <th scope="col">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($users as $user) : ?>
                        <tr>
                            <td>
                                <span class="row-entity">
                                    <svg class="icon" aria-hidden="true">
                                        <use href="/assets/icons/lucide-sprite.svg#<?= View::e($roleIcon[$user->role->value]) ?>"></use>
                                    </svg>
                                    <span class="cell-primary"><?= View::e($user->name) ?></span>
                                </span>
                            </td>
                            <td><?= View::e($user->email) ?></td>
                            <td><?= View::e($user->role->label()) ?></td>
                            <td>
                                <span class="badge <?= $user->isActive ? 'badge--fulfilled' : 'badge--cancelled' ?>">
                                    <?= $user->isActive ? 'Active' : 'Inactive' ?>
                                </span>
                            </td>
                            <td>
                                <div class="row">
                                    <a class="btn btn--sm" href="/users/<?= (int) $user->id ?>/edit">Edit</a>
                                    <form method="post" action="/users/<?= (int) $user->id ?>/toggle-active"
                                          data-confirm="<?= $user->isActive
                                              ? 'Deactivate this user? They will no longer be able to sign in. Their history is kept.'
                                              : 'Reactivate this user?' ?>">
                                        <?= $csrf->field() ?>
                                        <button type="submit" class="btn btn--sm">
                                            <?= $user->isActive ? 'Deactivate' : 'Activate' ?>
                                        </button>
                                    </form>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <?= $view->renderPartial('layout/_pagination', [
            'paginator' => $paginator,
            'basePath' => $basePath,
        ]) ?>
    <?php endif; ?>
</div>
