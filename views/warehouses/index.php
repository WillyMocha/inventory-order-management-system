<?php

declare(strict_types=1);

/**
 * Daftar warehouse (WH-01).
 *
 * Warehouse Staff boleh membaca halaman ini; hanya Admin yang melihat aksi
 * tulisnya. Server tetap menolak aksi tulis dari role lain, terlepas dari apa
 * yang ditampilkan di sini.
 *
 * @var View $view
 * @var Csrf $csrf
 * @var list<Warehouse> $warehouses
 * @var bool $canManage
 */

use App\Entity\Warehouse;
use App\Support\Csrf;
use App\Support\View;

$active = count(array_filter($warehouses, static fn (Warehouse $w): bool => $w->isActive));
$inactive = count($warehouses) - $active;
?>
<div class="page-header">
    <div>
        <h1 class="page-title">Warehouses</h1>
        <p class="page-subtitle">Stock is held per warehouse, and every order names the one it moves through.</p>
    </div>
    <?php if ($canManage) : ?>
        <a class="btn btn--primary" href="/warehouses/create">
            <svg class="icon" aria-hidden="true"><use href="/assets/icons/lucide-sprite.svg#plus"></use></svg>
            <span>Create warehouse</span>
        </a>
    <?php endif; ?>
</div>

<div class="stat-grid">
    <div class="stat">
        <div class="stat-chip stat-chip--info">
            <svg class="icon" aria-hidden="true"><use href="/assets/icons/lucide-sprite.svg#warehouse"></use></svg>
        </div>
        <div class="stat-body">
            <div class="stat-label">Warehouses</div>
            <div class="stat-value tabular"><?= count($warehouses) ?></div>
            <div class="stat-delta stat-delta--<?= $inactive > 0 ? 'down' : 'up' ?>">
                <svg class="icon" aria-hidden="true" style="width:14px;height:14px">
                    <use href="/assets/icons/lucide-sprite.svg#<?= $inactive > 0 ? 'alert-triangle' : 'check-circle' ?>"></use>
                </svg>
                <span><?= $inactive > 0 ? $inactive . ' inactive' : 'all active' ?></span>
            </div>
        </div>
    </div>
</div>

<div class="card">
    <?php if ($warehouses === []) : ?>
        <?= $view->renderPartial('layout/_empty-state', [
            'icon' => 'warehouse',
            'heading' => 'No warehouses yet',
            'text' => 'Stock cannot be received or issued until at least one warehouse exists.',
            'actionLabel' => $canManage ? 'Create warehouse' : '',
            'actionHref' => $canManage ? '/warehouses/create' : '',
        ]) ?>
    <?php else : ?>
        <div class="table-wrap">
            <table class="table">
                <thead>
                    <tr>
                        <th scope="col">Name</th>
                        <th scope="col">Location</th>
                        <th scope="col">Status</th>
                        <?php if ($canManage) : ?>
                            <th scope="col">Actions</th>
                        <?php endif; ?>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($warehouses as $warehouse) : ?>
                        <tr>
                            <td>
                                <span class="row-entity">
                                    <svg class="icon" aria-hidden="true">
                                        <use href="/assets/icons/lucide-sprite.svg#warehouse"></use>
                                    </svg>
                                    <span class="cell-primary"><?= View::e($warehouse->name) ?></span>
                                </span>
                            </td>
                            <td><?= View::e($warehouse->location) ?></td>
                            <td>
                                <span class="badge <?= $warehouse->isActive ? 'badge--fulfilled' : 'badge--cancelled' ?>">
                                    <?= $warehouse->isActive ? 'Active' : 'Inactive' ?>
                                </span>
                            </td>
                            <?php if ($canManage) : ?>
                                <td>
                                    <div class="row">
                                        <a class="btn btn--sm" href="/warehouses/<?= (int) $warehouse->id ?>/edit">Edit</a>
                                        <form method="post" action="/warehouses/<?= (int) $warehouse->id ?>/toggle-active"
                                              data-confirm="<?= $warehouse->isActive
                                                  ? 'Deactivate this warehouse? Existing stock and history are kept, but it cannot be chosen on new orders.'
                                                  : 'Reactivate this warehouse?' ?>">
                                            <?= $csrf->field() ?>
                                            <button type="submit" class="btn btn--sm">
                                                <?= $warehouse->isActive ? 'Deactivate' : 'Activate' ?>
                                            </button>
                                        </form>
                                    </div>
                                </td>
                            <?php endif; ?>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>
