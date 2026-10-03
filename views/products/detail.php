<?php

declare(strict_types=1);

/**
 * Detail product dengan rincian stock per warehouse (WH-01, FR-011).
 *
 * Menampilkan TOTAL dan rincian per warehouse - keduanya diminta requirement,
 * bukan salah satunya.
 *
 * @var Csrf $csrf
 * @var Product $product
 * @var array{product: Product, warehouses: list<array{warehouseId: int, warehouseName: string, quantity: int}>, total: int, isLowStock: bool} $breakdown
 * @var Category $category
 * @var bool $referenced
 * @var bool $canManage
 */

use App\Entity\Category;
use App\Entity\Product;
use App\Support\Csrf;
use App\Support\Money;
use App\Support\View;
?>
<div class="page-header">
    <div>
        <h1 class="page-title"><?= View::e($product->name) ?></h1>
        <p class="page-subtitle">
            <span class="tabular"><?= View::e($product->sku) ?></span>
            · <?= View::e($category->name) ?>
            <?php if (!$product->isActive) : ?>
                · <span class="badge badge--cancelled">Inactive</span>
            <?php endif; ?>
        </p>
    </div>
    <div class="row">
        <a class="btn btn--ghost" href="/products">Back to products</a>
        <?php if ($canManage) : ?>
            <a class="btn" href="/products/<?= (int) $product->id ?>/edit">Edit</a>
            <form method="post" action="/products/<?= (int) $product->id ?>/toggle-active"
                  data-confirm="<?= $product->isActive
                      ? 'Deactivate this product? It stays on existing orders and its history is kept, but it cannot be added to new ones.'
                      : 'Reactivate this product?' ?>">
                <?= $csrf->field() ?>
                <button type="submit" class="btn">
                    <?= $product->isActive ? 'Deactivate' : 'Activate' ?>
                </button>
            </form>
        <?php endif; ?>
    </div>
</div>

<div class="stat-grid">
    <div class="stat">
        <div class="stat-chip stat-chip--<?= $breakdown['isLowStock'] ? 'warning' : 'success' ?>">
            <svg class="icon" aria-hidden="true">
                <use href="/assets/icons/lucide-sprite.svg#<?= $breakdown['isLowStock'] ? 'alert-triangle' : 'package' ?>"></use>
            </svg>
        </div>
        <div class="stat-body">
            <div class="stat-label">Total stock</div>
            <div class="stat-value tabular"><?= (int) $breakdown['total'] ?> <?= View::e($product->unit) ?></div>
            <div class="stat-delta stat-delta--<?= $breakdown['isLowStock'] ? 'down' : 'up' ?>">
                <svg class="icon" aria-hidden="true" style="width:14px;height:14px">
                    <use href="/assets/icons/lucide-sprite.svg#<?= $breakdown['isLowStock'] ? 'trending-down' : 'check-circle' ?>"></use>
                </svg>
                <span>
                    <?= $breakdown['isLowStock']
                        ? 'at or below reorder point of ' . (int) $product->reorderPoint
                        : 'above reorder point of ' . (int) $product->reorderPoint ?>
                </span>
            </div>
        </div>
    </div>

    <div class="stat">
        <div class="stat-chip">
            <svg class="icon" aria-hidden="true"><use href="/assets/icons/lucide-sprite.svg#banknote"></use></svg>
        </div>
        <div class="stat-body">
            <div class="stat-label">Selling price</div>
            <div class="stat-value tabular"><?= View::e(Money::format($product->sellingPrice)) ?></div>
            <div class="stat-delta stat-delta--flat">
                <span>bought at <?= View::e(Money::format($product->purchasePrice)) ?></span>
            </div>
        </div>
    </div>

    <div class="stat">
        <div class="stat-chip stat-chip--info">
            <svg class="icon" aria-hidden="true"><use href="/assets/icons/lucide-sprite.svg#warehouse"></use></svg>
        </div>
        <div class="stat-body">
            <div class="stat-label">Stocked in</div>
            <div class="stat-value tabular"><?= count($breakdown['warehouses']) ?></div>
            <div class="stat-delta stat-delta--flat">
                <span><?= count($breakdown['warehouses']) === 1 ? 'warehouse' : 'warehouses' ?></span>
            </div>
        </div>
    </div>
</div>

<div class="card">
    <div class="card-header">
        <h2 class="card-title">Stock by warehouse</h2>
        <span class="cell-secondary">Total across all warehouses: <strong class="tabular"><?= (int) $breakdown['total'] ?></strong></span>
    </div>

    <?php if ($breakdown['warehouses'] === []) : ?>
        <div class="card-body">
            <p class="muted">This product is not stocked in any active warehouse yet.</p>
        </div>
    <?php else : ?>
        <div class="table-wrap">
            <table class="table">
                <thead>
                    <tr>
                        <th scope="col">Warehouse</th>
                        <th scope="col" class="numeric">Quantity</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($breakdown['warehouses'] as $row) : ?>
                        <tr>
                            <td>
                                <span class="row-entity">
                                    <svg class="icon" aria-hidden="true">
                                        <use href="/assets/icons/lucide-sprite.svg#warehouse"></use>
                                    </svg>
                                    <span class="cell-primary"><?= View::e($row['warehouseName']) ?></span>
                                </span>
                            </td>
                            <td class="numeric"><?= (int) $row['quantity'] ?> <?= View::e($product->unit) ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>

<div class="card" style="margin-top: var(--space-6)">
    <div class="card-header">
        <h2 class="card-title">Details</h2>
    </div>
    <div class="card-body">
        <div class="form-grid">
            <div>
                <div class="stat-label">Unit</div>
                <p><?= View::e($product->unit) ?></p>
            </div>
            <div>
                <div class="stat-label">Reorder point</div>
                <p class="tabular"><?= (int) $product->reorderPoint ?></p>
            </div>
            <div>
                <div class="stat-label">Purchase price</div>
                <p class="tabular"><?= View::e(Money::format($product->purchasePrice)) ?></p>
            </div>
            <div>
                <div class="stat-label">Image</div>
                <?php if ($product->hasImage()) : ?>
                    <img src="/products/<?= (int) $product->id ?>/image"
                         alt="<?= View::e($product->name) ?>"
                         style="max-width: 200px; border-radius: var(--radius-card); margin-top: var(--space-2)">
                <?php else : ?>
                    <p class="muted">No image uploaded.</p>
                <?php endif; ?>
            </div>
        </div>

        <?php if ($referenced) : ?>
            <div class="alert alert--info" style="margin-top: var(--space-6); margin-bottom: 0">
                <svg class="icon" aria-hidden="true"><use href="/assets/icons/lucide-sprite.svg#inbox"></use></svg>
                <span>
                    This product already appears on one or more orders, so it can only be deactivated,
                    never removed. Its history stays intact either way.
                </span>
            </div>
        <?php endif; ?>
    </div>
</div>
