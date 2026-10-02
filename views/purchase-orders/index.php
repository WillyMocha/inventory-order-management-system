<?php

declare(strict_types=1);

/**
 * Daftar Purchase Order (PO-01, FR-023).
 *
 * @var View $view
 * @var list<PurchaseOrder> $orders
 * @var Paginator $paginator
 * @var string $basePath
 * @var array<string, string> $filters
 * @var bool $hasFilters
 * @var list<PurchaseOrderStatus> $statuses
 * @var array<int, string> $supplierNames
 * @var array{total: int, awaitingReceipt: int, draft: int} $summary
 */

use App\Entity\Enum\PurchaseOrderStatus;
use App\Entity\PurchaseOrder;
use App\Support\Paginator;
use App\Support\View;

/**
 * Badge per status. PartiallyReceived memakai kelas pending karena keduanya
 * berarti "masih berjalan" — konsisten dengan warna status di halaman lain.
 */
$badgeClass = static fn (PurchaseOrderStatus $status): string => match ($status) {
    PurchaseOrderStatus::Draft => 'badge--draft',
    PurchaseOrderStatus::Ordered => 'badge--approved',
    PurchaseOrderStatus::PartiallyReceived => 'badge--pending',
    PurchaseOrderStatus::Received => 'badge--received',
    PurchaseOrderStatus::Cancelled => 'badge--cancelled',
};
?>
<div class="page-header">
    <div>
        <h1 class="page-title">Purchase orders</h1>
        <p class="page-subtitle">Order stock from suppliers and record what actually arrives.</p>
    </div>
    <a class="btn btn--primary" href="/purchase-orders/create">
        <svg class="icon" aria-hidden="true"><use href="/assets/icons/lucide-sprite.svg#plus"></use></svg>
        <span>Create purchase order</span>
    </a>
</div>

<div class="stat-grid">
    <div class="stat">
        <div class="stat-chip">
            <svg class="icon" aria-hidden="true"><use href="/assets/icons/lucide-sprite.svg#truck"></use></svg>
        </div>
        <div class="stat-body">
            <div class="stat-label">Total orders</div>
            <div class="stat-value tabular"><?= (int) $summary['total'] ?></div>
            <div class="stat-delta stat-delta--flat">
                <span>across every status</span>
            </div>
        </div>
    </div>

    <div class="stat">
        <div class="stat-chip stat-chip--warning">
            <svg class="icon" aria-hidden="true"><use href="/assets/icons/lucide-sprite.svg#clock"></use></svg>
        </div>
        <div class="stat-body">
            <div class="stat-label">Awaiting receipt</div>
            <div class="stat-value tabular"><?= (int) $summary['awaitingReceipt'] ?></div>
            <div class="stat-delta stat-delta--<?= $summary['awaitingReceipt'] > 0 ? 'down' : 'up' ?>">
                <svg class="icon" aria-hidden="true" style="width:14px;height:14px">
                    <use href="/assets/icons/lucide-sprite.svg#<?= $summary['awaitingReceipt'] > 0 ? 'trending-down' : 'check-circle' ?>"></use>
                </svg>
                <span><?= $summary['awaitingReceipt'] > 0 ? 'goods still to arrive' : 'nothing outstanding' ?></span>
            </div>
        </div>
    </div>

    <div class="stat">
        <div class="stat-chip stat-chip--info">
            <svg class="icon" aria-hidden="true"><use href="/assets/icons/lucide-sprite.svg#file-text"></use></svg>
        </div>
        <div class="stat-body">
            <div class="stat-label">Still in draft</div>
            <div class="stat-value tabular"><?= (int) $summary['draft'] ?></div>
            <div class="stat-delta stat-delta--flat">
                <span>not yet sent to a supplier</span>
            </div>
        </div>
    </div>
</div>

<div class="card">
    <form class="toolbar" method="get" action="/purchase-orders">
        <div class="field">
            <label class="field-label" for="search">Search</label>
            <input class="input" type="search" id="search" name="search"
                   value="<?= View::e($filters['search'] ?? '') ?>" placeholder="Order number or supplier">
        </div>
        <div class="field">
            <label class="field-label" for="status">Status</label>
            <select class="select" id="status" name="status">
                <option value="">Any status</option>
                <?php foreach ($statuses as $status) : ?>
                    <option value="<?= View::e($status->value) ?>"
                        <?= ($filters['status'] ?? '') === $status->value ? 'selected' : '' ?>>
                        <?= View::e($status->label()) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <button type="submit" class="btn">
            <svg class="icon" aria-hidden="true"><use href="/assets/icons/lucide-sprite.svg#search"></use></svg>
            <span>Apply</span>
        </button>
        <?php if ($hasFilters) : ?>
            <a class="btn btn--ghost" href="/purchase-orders">
                <svg class="icon" aria-hidden="true"><use href="/assets/icons/lucide-sprite.svg#filter-x"></use></svg>
                <span>Clear</span>
            </a>
        <?php endif; ?>
    </form>

    <?php if ($orders === [] && $hasFilters) : ?>
        <?= $view->renderPartial('layout/_empty-state', [
            'icon' => 'filter-x',
            'heading' => 'No purchase orders match these filters',
            'text' => 'Try a different order number, supplier or status, or clear the filters to see every order.',
            'actionLabel' => 'Clear filters',
            'actionHref' => '/purchase-orders',
        ]) ?>
    <?php elseif ($orders === []) : ?>
        <?= $view->renderPartial('layout/_empty-state', [
            'icon' => 'truck',
            'heading' => 'No purchase orders yet',
            'text' => 'A purchase order records what you ordered from a supplier. Stock rises only when the goods are actually received against it.',
            'actionLabel' => 'Create purchase order',
            'actionHref' => '/purchase-orders/create',
        ]) ?>
    <?php else : ?>
        <div class="table-wrap">
            <table class="table">
                <thead>
                    <tr>
                        <?= $view->renderPartial('layout/_sort-header', [
                            'label' => 'Order',
                            'sortKey' => 'number',
                            'basePath' => $basePath,
                            'filters' => $filters,
                        ]) ?>
                        <th scope="col">Supplier</th>
                        <?= $view->renderPartial('layout/_sort-header', [
                            'label' => 'Order date',
                            'sortKey' => 'date',
                            'basePath' => $basePath,
                            'filters' => $filters,
                        ]) ?>
                        <?= $view->renderPartial('layout/_sort-header', [
                            'label' => 'Status',
                            'sortKey' => 'status',
                            'basePath' => $basePath,
                            'filters' => $filters,
                        ]) ?>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($orders as $order) : ?>
                        <tr>
                            <td>
                                <a class="row-entity" href="/purchase-orders/<?= (int) $order->id ?>">
                                    <svg class="icon" aria-hidden="true">
                                        <use href="/assets/icons/lucide-sprite.svg#truck"></use>
                                    </svg>
                                    <span class="cell-primary tabular"><?= View::e($order->orderNumber) ?></span>
                                </a>
                            </td>
                            <td><?= View::e($supplierNames[$order->supplierId] ?? '—') ?></td>
                            <td class="tabular"><?= View::e($order->orderDate) ?></td>
                            <td>
                                <span class="badge <?= $badgeClass($order->status) ?>">
                                    <?= View::e($order->status->label()) ?>
                                </span>
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
