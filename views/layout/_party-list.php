<?php

declare(strict_types=1);

/**
 * Daftar Supplier atau Customer.
 *
 * Kedua entity dijaga TERPISAH di database dan di Service (data-model.md).
 * Yang dibagikan hanyalah tampilannya, karena bentuk field-nya memang sama -
 * ini menghindari duplikasi ~150 baris markup yang identik.
 *
 * @var View $view
 * @var Csrf $csrf
 * @var list<object{id: int|null, name: string, contact: string, address: string, isActive: bool}> $parties
 * @var Paginator $paginator
 * @var string $basePath
 * @var string $entityLabel   "supplier" / "customer"
 * @var string $entityLabelPlural
 * @var string $icon
 * @var string $blurb
 * @var array<string, string> $filters
 * @var bool $hasFilters
 * @var int $total
 * @var int $activeCount
 * @var bool $canManage
 */

use App\Support\Csrf;
use App\Support\Paginator;
use App\Support\View;

$inactive = $total - $activeCount;
?>
<div class="page-header">
    <div>
        <h1 class="page-title"><?= View::e(ucfirst($entityLabelPlural)) ?></h1>
        <p class="page-subtitle"><?= View::e($blurb) ?></p>
    </div>
    <?php if ($canManage) : ?>
        <a class="btn btn--primary" href="<?= View::e($basePath) ?>/create">
            <svg class="icon" aria-hidden="true"><use href="/assets/icons/lucide-sprite.svg#plus"></use></svg>
            <span>Create <?= View::e($entityLabel) ?></span>
        </a>
    <?php endif; ?>
</div>

<div class="stat-grid">
    <div class="stat">
        <div class="stat-chip stat-chip--info">
            <svg class="icon" aria-hidden="true">
                <use href="/assets/icons/lucide-sprite.svg#<?= View::e($icon) ?>"></use>
            </svg>
        </div>
        <div class="stat-body">
            <div class="stat-label">Total <?= View::e($entityLabelPlural) ?></div>
            <div class="stat-value tabular"><?= (int) $total ?></div>
            <div class="stat-delta stat-delta--<?= $inactive > 0 ? 'down' : 'up' ?>">
                <svg class="icon" aria-hidden="true" style="width:14px;height:14px">
                    <use href="/assets/icons/lucide-sprite.svg#<?= $inactive > 0 ? 'alert-triangle' : 'check-circle' ?>"></use>
                </svg>
                <span><?= $inactive > 0 ? $inactive . ' inactive' : 'all active' ?></span>
            </div>
        </div>
    </div>

    <div class="stat">
        <div class="stat-chip stat-chip--success">
            <svg class="icon" aria-hidden="true"><use href="/assets/icons/lucide-sprite.svg#check-circle"></use></svg>
        </div>
        <div class="stat-body">
            <div class="stat-label">Available for new orders</div>
            <div class="stat-value tabular"><?= (int) $activeCount ?></div>
            <div class="stat-delta stat-delta--flat">
                <span>currently active</span>
            </div>
        </div>
    </div>
</div>

<div class="card">
    <form class="toolbar" method="get" action="<?= View::e($basePath) ?>">
        <div class="field">
            <label class="field-label" for="search">Search</label>
            <input class="input" type="search" id="search" name="search"
                   value="<?= View::e($filters['search'] ?? '') ?>" placeholder="Name or contact">
        </div>
        <div class="field">
            <label class="field-label" for="status">Status</label>
            <select class="select" id="status" name="status">
                <option value="">Any status</option>
                <option value="active" <?= ($filters['status'] ?? '') === 'active' ? 'selected' : '' ?>>Active</option>
                <option value="inactive" <?= ($filters['status'] ?? '') === 'inactive' ? 'selected' : '' ?>>Inactive</option>
            </select>
        </div>
        <button type="submit" class="btn">
            <svg class="icon" aria-hidden="true"><use href="/assets/icons/lucide-sprite.svg#search"></use></svg>
            <span>Apply</span>
        </button>
        <?php if ($hasFilters) : ?>
            <a class="btn btn--ghost" href="<?= View::e($basePath) ?>">
                <svg class="icon" aria-hidden="true"><use href="/assets/icons/lucide-sprite.svg#filter-x"></use></svg>
                <span>Clear</span>
            </a>
        <?php endif; ?>
    </form>

    <?php if ($parties === [] && $hasFilters) : ?>
        <?= $view->renderPartial('layout/_empty-state', [
            'icon' => 'filter-x',
            'heading' => 'No ' . $entityLabelPlural . ' match these filters',
            'text' => 'Try a different search term or status, or clear the filters to see everyone.',
            'actionLabel' => 'Clear filters',
            'actionHref' => $basePath,
        ]) ?>
    <?php elseif ($parties === []) : ?>
        <?= $view->renderPartial('layout/_empty-state', [
            'icon' => $icon,
            'heading' => 'No ' . $entityLabelPlural . ' yet',
            'text' => $blurb,
            'actionLabel' => $canManage ? 'Create ' . $entityLabel : '',
            'actionHref' => $canManage ? $basePath . '/create' : '',
        ]) ?>
    <?php else : ?>
        <div class="table-wrap">
            <table class="table">
                <thead>
                    <tr>
                        <th scope="col">Name</th>
                        <th scope="col">Contact</th>
                        <th scope="col">Address</th>
                        <th scope="col">Status</th>
                        <?php if ($canManage) : ?>
                            <th scope="col">Actions</th>
                        <?php endif; ?>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($parties as $party) : ?>
                        <tr>
                            <td>
                                <span class="row-entity">
                                    <svg class="icon" aria-hidden="true">
                                        <use href="/assets/icons/lucide-sprite.svg#<?= View::e($icon) ?>"></use>
                                    </svg>
                                    <span class="cell-primary"><?= View::e($party->name) ?></span>
                                </span>
                            </td>
                            <td class="tabular"><?= View::e($party->contact) ?></td>
                            <td><?= View::e($party->address) ?></td>
                            <td>
                                <span class="badge <?= $party->isActive ? 'badge--fulfilled' : 'badge--cancelled' ?>">
                                    <?= $party->isActive ? 'Active' : 'Inactive' ?>
                                </span>
                            </td>
                            <?php if ($canManage) : ?>
                                <td>
                                    <div class="row">
                                        <a class="btn btn--sm" href="<?= View::e($basePath) ?>/<?= (int) $party->id ?>/edit">Edit</a>
                                        <form method="post" action="<?= View::e($basePath) ?>/<?= (int) $party->id ?>/toggle-active"
                                              data-confirm="<?= $party->isActive
                                                  ? 'Deactivate this ' . View::e($entityLabel) . '? Existing orders keep their history, but it cannot be chosen on new ones.'
                                                  : 'Reactivate this ' . View::e($entityLabel) . '?' ?>">
                                            <?= $csrf->field() ?>
                                            <button type="submit" class="btn btn--sm">
                                                <?= $party->isActive ? 'Deactivate' : 'Activate' ?>
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

        <?= $view->renderPartial('layout/_pagination', [
            'paginator' => $paginator,
            'basePath' => $basePath,
        ]) ?>
    <?php endif; ?>
</div>
