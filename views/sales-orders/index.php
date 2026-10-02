<?php

declare(strict_types=1);

/**
 * Daftar Sales Order (SO-01, FR-023).
 *
 * Sales hanya melihat order miliknya sendiri — pembatasannya sudah dilakukan
 * di WHERE clause query, view ini hanya menjelaskannya kepada user (§1.2).
 *
 * @var View $view
 * @var list<SalesOrder> $orders
 * @var Paginator $paginator
 * @var string $basePath
 * @var array<string, string> $filters
 * @var bool $hasFilters
 * @var list<SalesOrderStatus> $statuses
 * @var array<int, string> $customerNames
 * @var array{total: int, pendingApproval: int, awaitingIssue: int} $summary
 * @var bool $canCreate
 * @var bool $scopedToSelf
 */

use App\Entity\Enum\SalesOrderStatus;
use App\Entity\SalesOrder;
use App\Support\Paginator;
use App\Support\View;

/** Badge per status — kelasnya sudah ada di app.css. */
$badgeClass = static fn (SalesOrderStatus $status): string => match ($status) {
    SalesOrderStatus::Draft => 'badge--draft',
    SalesOrderStatus::PendingApproval => 'badge--pending',
    SalesOrderStatus::Approved => 'badge--approved',
    SalesOrderStatus::Fulfilled => 'badge--fulfilled',
    SalesOrderStatus::Cancelled => 'badge--cancelled',
};
?>
<div class="page-header">
    <div>
        <h1 class="page-title">Sales orders</h1>
        <p class="page-subtitle">
            <?php if ($scopedToSelf) : ?>
                The orders you created. Approval is handled by an Admin.
            <?php else : ?>
                Draft, approve and issue orders to customers.
            <?php endif; ?>
        </p>
    </div>
    <?php if ($canCreate) : ?>
        <a class="btn btn--primary" href="/sales-orders/create">
            <svg class="icon" aria-hidden="true"><use href="/assets/icons/lucide-sprite.svg#plus"></use></svg>
            <span>Create sales order</span>
        </a>
    <?php endif; ?>
</div>

<div class="stat-grid">
    <div class="stat">
        <div class="stat-chip">
            <svg class="icon" aria-hidden="true"><use href="/assets/icons/lucide-sprite.svg#shopping-cart"></use></svg>
        </div>
        <div class="stat-body">
            <div class="stat-label"><?= $scopedToSelf ? 'Your orders' : 'Total orders' ?></div>
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
            <div class="stat-label">Awaiting approval</div>
            <div class="stat-value tabular"><?= (int) $summary['pendingApproval'] ?></div>
            <div class="stat-delta stat-delta--<?= $summary['pendingApproval'] > 0 ? 'down' : 'up' ?>">
                <svg class="icon" aria-hidden="true" style="width:14px;height:14px">
                    <use href="/assets/icons/lucide-sprite.svg#<?= $summary['pendingApproval'] > 0 ? 'trending-down' : 'check-circle' ?>"></use>
                </svg>
                <span><?= $summary['pendingApproval'] > 0 ? 'needs an Admin decision' : 'nothing waiting' ?></span>
            </div>
        </div>
    </div>

    <div class="stat">
        <div class="stat-chip stat-chip--info">
            <svg class="icon" aria-hidden="true"><use href="/assets/icons/lucide-sprite.svg#truck"></use></svg>
        </div>
        <div class="stat-body">
            <div class="stat-label">Approved, awaiting issue</div>
            <div class="stat-value tabular"><?= (int) $summary['awaitingIssue'] ?></div>
            <div class="stat-delta stat-delta--flat">
                <span>ready for the warehouse</span>
            </div>
        </div>
    </div>
</div>

<div class="card">
    <form class="toolbar" method="get" action="/sales-orders">
        <div class="field">
            <label class="field-label" for="search">Search</label>
            <input class="input" type="search" id="search" name="search"
                   value="<?= View::e($filters['search'] ?? '') ?>" placeholder="Order number or customer">
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
            <a class="btn btn--ghost" href="/sales-orders">
                <svg class="icon" aria-hidden="true"><use href="/assets/icons/lucide-sprite.svg#filter-x"></use></svg>
                <span>Clear</span>
            </a>
        <?php endif; ?>
    </form>

    <?php if ($orders === [] && $hasFilters) : ?>
        <?= $view->renderPartial('layout/_empty-state', [
            'icon' => 'filter-x',
            'heading' => 'No sales orders match these filters',
            'text' => 'Try a different order number, customer or status, or clear the filters to see every order.',
            'actionLabel' => 'Clear filters',
            'actionHref' => '/sales-orders',
        ]) ?>
    <?php elseif ($orders === []) : ?>
        <?= $view->renderPartial('layout/_empty-state', [
            'icon' => 'shopping-cart',
            'heading' => 'No sales orders yet',
            'text' => 'A sales order records what a customer bought. It starts as a draft, an Admin approves it, and the warehouse then issues the goods.',
            'actionLabel' => $canCreate ? 'Create sales order' : '',
            'actionHref' => $canCreate ? '/sales-orders/create' : '',
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
                        <th scope="col">Customer</th>
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
                                <a class="row-entity" href="/sales-orders/<?= (int) $order->id ?>">
                                    <svg class="icon" aria-hidden="true">
                                        <use href="/assets/icons/lucide-sprite.svg#shopping-cart"></use>
                                    </svg>
                                    <span class="cell-primary tabular"><?= View::e($order->orderNumber) ?></span>
                                </a>
                            </td>
                            <td><?= View::e($customerNames[$order->customerId] ?? '—') ?></td>
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
