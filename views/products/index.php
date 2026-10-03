<?php

declare(strict_types=1);

/**
 * Daftar product (PRD-01, FIND-01).
 *
 * @var View $view
 * @var list<Product> $products
 * @var array<int, int> $totals total stock seluruh warehouse per product
 * @var Paginator $paginator
 * @var string $basePath
 * @var array<string, string> $filters
 * @var bool $hasFilters
 * @var list<Category> $categories
 * @var array<int, string> $categoryNames
 * @var array{total: int, lowStock: int, inventoryValue: string} $summary
 * @var bool $canManage
 */

use App\Entity\Category;
use App\Entity\Product;
use App\Support\Money;
use App\Support\Paginator;
use App\Support\View;
?>
<div class="page-header">
    <div>
        <h1 class="page-title">Products</h1>
        <p class="page-subtitle">Catalog used by every purchase and sales order.</p>
    </div>
    <?php if ($canManage) : ?>
        <a class="btn btn--primary" href="/products/create">
            <svg class="icon" aria-hidden="true"><use href="/assets/icons/lucide-sprite.svg#plus"></use></svg>
            <span>Create product</span>
        </a>
    <?php endif; ?>
</div>

<div class="stat-grid">
    <div class="stat">
        <div class="stat-chip">
            <svg class="icon" aria-hidden="true"><use href="/assets/icons/lucide-sprite.svg#package"></use></svg>
        </div>
        <div class="stat-body">
            <div class="stat-label">Active products</div>
            <div class="stat-value tabular"><?= (int) $summary['total'] ?></div>
            <div class="stat-delta stat-delta--flat">
                <span>in the catalog</span>
            </div>
        </div>
    </div>

    <div class="stat">
        <div class="stat-chip stat-chip--warning">
            <svg class="icon" aria-hidden="true"><use href="/assets/icons/lucide-sprite.svg#alert-triangle"></use></svg>
        </div>
        <div class="stat-body">
            <div class="stat-label">Below reorder point</div>
            <div class="stat-value tabular"><?= (int) $summary['lowStock'] ?></div>
            <div class="stat-delta stat-delta--<?= $summary['lowStock'] > 0 ? 'down' : 'up' ?>">
                <svg class="icon" aria-hidden="true" style="width:14px;height:14px">
                    <use href="/assets/icons/lucide-sprite.svg#<?= $summary['lowStock'] > 0 ? 'trending-down' : 'check-circle' ?>"></use>
                </svg>
                <span><?= $summary['lowStock'] > 0 ? 'needs restocking' : 'all above reorder point' ?></span>
            </div>
        </div>
    </div>

    <div class="stat">
        <div class="stat-chip stat-chip--success">
            <svg class="icon" aria-hidden="true"><use href="/assets/icons/lucide-sprite.svg#banknote"></use></svg>
        </div>
        <div class="stat-body">
            <div class="stat-label">Inventory value</div>
            <div class="stat-value tabular"><?= View::e(Money::format($summary['inventoryValue'])) ?></div>
            <div class="stat-delta stat-delta--flat">
                <span>at purchase price</span>
            </div>
        </div>
    </div>
</div>

<div class="card">
    <form class="toolbar" method="get" action="/products">
        <div class="field">
            <label class="field-label" for="search">Search</label>
            <input class="input" type="search" id="search" name="search"
                   value="<?= View::e($filters['search'] ?? '') ?>" placeholder="Name or SKU">
        </div>
        <div class="field">
            <label class="field-label" for="category">Category</label>
            <select class="select" id="category" name="category">
                <option value="">All categories</option>
                <?php foreach ($categories as $category) : ?>
                    <option value="<?= (int) $category->id ?>"
                        <?= ($filters['category'] ?? '') === (string) $category->id ? 'selected' : '' ?>>
                        <?= View::e($category->name) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="field">
            <label class="field-label" for="stock">Stock status</label>
            <select class="select" id="stock" name="stock">
                <option value="">Any stock level</option>
                <option value="low" <?= ($filters['stock'] ?? '') === 'low' ? 'selected' : '' ?>>Low stock</option>
                <option value="normal" <?= ($filters['stock'] ?? '') === 'normal' ? 'selected' : '' ?>>Normal</option>
            </select>
        </div>
        <button type="submit" class="btn">
            <svg class="icon" aria-hidden="true"><use href="/assets/icons/lucide-sprite.svg#search"></use></svg>
            <span>Apply</span>
        </button>
        <?php if ($hasFilters) : ?>
            <a class="btn btn--ghost" href="/products">
                <svg class="icon" aria-hidden="true"><use href="/assets/icons/lucide-sprite.svg#filter-x"></use></svg>
                <span>Clear</span>
            </a>
        <?php endif; ?>
    </form>

    <?php if ($products === [] && $hasFilters) : ?>
        <?= $view->renderPartial('layout/_empty-state', [
            'icon' => 'filter-x',
            'heading' => 'No products match these filters',
            'text' => 'Try a different search term or stock level, or clear the filters to see the whole catalog.',
            'actionLabel' => 'Clear filters',
            'actionHref' => '/products',
        ]) ?>
    <?php elseif ($products === []) : ?>
        <?= $view->renderPartial('layout/_empty-state', [
            'icon' => 'package',
            'heading' => 'No products yet',
            'text' => 'The catalog drives every purchase and sales order. Add your first product to get started.',
            'actionLabel' => $canManage ? 'Create product' : '',
            'actionHref' => $canManage ? '/products/create' : '',
        ]) ?>
    <?php else : ?>
        <div class="table-wrap">
            <table class="table">
                <thead>
                    <tr>
                        <?= $view->renderPartial('layout/_sort-header', [
                            'label' => 'Product',
                            'sortKey' => 'name',
                            'basePath' => $basePath,
                            'filters' => $filters,
                        ]) ?>
                        <?= $view->renderPartial('layout/_sort-header', [
                            'label' => 'SKU',
                            'sortKey' => 'sku',
                            'basePath' => $basePath,
                            'filters' => $filters,
                        ]) ?>
                        <th scope="col">Category</th>
                        <th scope="col">Unit</th>
                        <?= $view->renderPartial('layout/_sort-header', [
                            'label' => 'Selling price',
                            'sortKey' => 'price',
                            'basePath' => $basePath,
                            'filters' => $filters,
                            'numeric' => true,
                        ]) ?>
                        <th scope="col" class="numeric">Stock</th>
                        <th scope="col">Status</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($products as $product) :
                        $total = $totals[(int) $product->id] ?? 0;
                        $isLow = $product->isLowStock($total);
                        ?>
                        <tr>
                            <td>
                                <a class="row-entity" href="/products/<?= (int) $product->id ?>">
                                    <svg class="icon" aria-hidden="true">
                                        <use href="/assets/icons/lucide-sprite.svg#package"></use>
                                    </svg>
                                    <span class="cell-primary"><?= View::e($product->name) ?></span>
                                </a>
                            </td>
                            <td class="tabular"><?= View::e($product->sku) ?></td>
                            <td><?= View::e($categoryNames[$product->categoryId] ?? '—') ?></td>
                            <td><?= View::e($product->unit) ?></td>
                            <td class="numeric"><?= View::e(Money::format($product->sellingPrice)) ?></td>
                            <td class="numeric">
                                <?= $total ?>
                                <span class="cell-secondary">/ <?= (int) $product->reorderPoint ?></span>
                            </td>
                            <td>
                                <?php if (!$product->isActive) : ?>
                                    <span class="badge badge--cancelled">Inactive</span>
                                <?php elseif ($isLow) : ?>
                                    <span class="badge badge--low-stock">Low stock</span>
                                <?php else : ?>
                                    <span class="badge badge--fulfilled">In stock</span>
                                <?php endif; ?>
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
