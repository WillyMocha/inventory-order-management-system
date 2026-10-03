<?php

declare(strict_types=1);

/**
 * Daftar category.
 *
 * Category tidak memiliki status aktif pada model sumber (§1.3), jadi tidak
 * ada aksi deactivate di sini.
 *
 * @var View $view
 * @var list<Category> $categories
 */

use App\Entity\Category;
use App\Support\View;
?>
<div class="page-header">
    <div>
        <h1 class="page-title">Categories</h1>
        <p class="page-subtitle">Used to group products in the catalog and in filters.</p>
    </div>
    <a class="btn btn--primary" href="/categories/create">
        <svg class="icon" aria-hidden="true"><use href="/assets/icons/lucide-sprite.svg#plus"></use></svg>
        <span>Create category</span>
    </a>
</div>

<div class="stat-grid">
    <div class="stat">
        <div class="stat-chip stat-chip--info">
            <svg class="icon" aria-hidden="true"><use href="/assets/icons/lucide-sprite.svg#clipboard-list"></use></svg>
        </div>
        <div class="stat-body">
            <div class="stat-label">Categories</div>
            <div class="stat-value tabular"><?= count($categories) ?></div>
            <div class="stat-delta stat-delta--flat">
                <span>available to products</span>
            </div>
        </div>
    </div>
</div>

<div class="card">
    <?php if ($categories === []) : ?>
        <?= $view->renderPartial('layout/_empty-state', [
            'icon' => 'clipboard-list',
            'heading' => 'No categories yet',
            'text' => 'Every product belongs to a category. Create the first one before adding products.',
            'actionLabel' => 'Create category',
            'actionHref' => '/categories/create',
        ]) ?>
    <?php else : ?>
        <div class="table-wrap">
            <table class="table">
                <thead>
                    <tr>
                        <th scope="col">Name</th>
                        <th scope="col">Description</th>
                        <th scope="col">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($categories as $category) : ?>
                        <tr>
                            <td>
                                <span class="row-entity">
                                    <svg class="icon" aria-hidden="true">
                                        <use href="/assets/icons/lucide-sprite.svg#clipboard-list"></use>
                                    </svg>
                                    <span class="cell-primary"><?= View::e($category->name) ?></span>
                                </span>
                            </td>
                            <td><?= View::e($category->description ?? '—') ?></td>
                            <td><a class="btn btn--sm" href="/categories/<?= (int) $category->id ?>/edit">Edit</a></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>
