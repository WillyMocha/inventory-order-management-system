<?php

declare(strict_types=1);

/**
 * Dashboard Warehouse Staff (DASH-01, FR-026, §1.2).
 *
 * Isinya adalah antrean kerja: apa yang menunggu diterima, apa yang menunggu
 * dikeluarkan, dan product apa yang menipis. Daftar di bawah tiap angka hanya
 * menampilkan beberapa baris teratas; angkanya sendiri dihitung terpisah
 * lewat COUNT, jadi daftar yang pendek tidak pernah mengecilkan angkanya.
 *
 * @var View $view
 * @var string $userName
 * @var string $roleLabel
 * @var array{
 *     receiptQueueCount: int,
 *     issueQueueCount: int,
 *     lowStockCount: int,
 *     awaitingReceipt: list<PurchaseOrder>,
 *     awaitingIssue: list<SalesOrder>,
 *     lowStock: list<array{product: Product, totalQuantity: int}>
 * } $figures
 */

use App\Entity\Enum\PurchaseOrderStatus;
use App\Entity\Product;
use App\Entity\PurchaseOrder;
use App\Entity\SalesOrder;
use App\Support\View;

$receipts = (int) $figures['receiptQueueCount'];
$issues = (int) $figures['issueQueueCount'];
$lowStock = (int) $figures['lowStockCount'];
?>
<div class="page-header">
    <div>
        <h1 class="page-title">Dashboard</h1>
        <p class="page-subtitle">
            <?= View::e($userName) ?> · <?= View::e($roleLabel) ?> · what is waiting in the warehouse
        </p>
    </div>
    <a class="btn btn--primary" href="/reports">
        <svg class="icon" aria-hidden="true"><use href="/assets/icons/lucide-sprite.svg#file-text"></use></svg>
        <span>Open reports</span>
    </a>
</div>

<div class="stat-grid">
    <div class="stat stat--feature">
        <div class="stat-chip stat-chip--info">
            <svg class="icon" aria-hidden="true"><use href="/assets/icons/lucide-sprite.svg#truck"></use></svg>
        </div>
        <div class="stat-body">
            <div class="stat-label">Awaiting goods receipt</div>
            <div class="stat-value tabular"><?= $receipts ?></div>
            <div class="stat-delta stat-delta--flat">
                <svg class="icon icon--sm" aria-hidden="true">
                    <use href="/assets/icons/lucide-sprite.svg#inbox"></use>
                </svg>
                <span><?= $receipts > 0 ? 'purchase orders to receive' : 'nothing on its way' ?></span>
            </div>
        </div>
    </div>

    <div class="stat">
        <div class="stat-chip stat-chip--warning">
            <svg class="icon" aria-hidden="true"><use href="/assets/icons/lucide-sprite.svg#package"></use></svg>
        </div>
        <div class="stat-body">
            <div class="stat-label">Awaiting goods issue</div>
            <div class="stat-value tabular"><?= $issues ?></div>
            <div class="stat-delta stat-delta--flat">
                <svg class="icon icon--sm" aria-hidden="true">
                    <use href="/assets/icons/lucide-sprite.svg#shopping-cart"></use>
                </svg>
                <span><?= $issues > 0 ? 'approved orders to ship' : 'nothing to ship' ?></span>
            </div>
        </div>
    </div>

    <div class="stat">
        <div class="stat-chip stat-chip--danger">
            <svg class="icon" aria-hidden="true">
                <use href="/assets/icons/lucide-sprite.svg#alert-triangle"></use>
            </svg>
        </div>
        <div class="stat-body">
            <div class="stat-label">Low stock products</div>
            <div class="stat-value tabular"><?= $lowStock ?></div>
            <div class="stat-delta stat-delta--<?= $lowStock > 0 ? 'down' : 'up' ?>">
                <svg class="icon icon--sm" aria-hidden="true">
                    <use href="/assets/icons/lucide-sprite.svg#<?=
                        $lowStock > 0 ? 'trending-down' : 'check-circle'
                    ?>"></use>
                </svg>
                <span><?= $lowStock > 0 ? 'at or below reorder point' : 'all above reorder point' ?></span>
            </div>
        </div>
    </div>
</div>

<div class="card">
    <div class="card-header">
        <h2 class="card-title">Receipt queue</h2>
        <a class="btn btn--ghost btn--sm" href="/purchase-orders?status=Ordered">
            <span>View all</span>
        </a>
    </div>
    <?php if ($figures['awaitingReceipt'] === []) : ?>
        <div class="card-body">
            <?= $view->renderPartial('layout/_empty-state', [
                'icon'        => 'inbox',
                'heading'     => 'Nothing waiting to be received',
                'text'        => 'Purchase orders appear here once they are ordered from the supplier, '
                    . 'and stay until every line has arrived.',
                'actionLabel' => 'Browse purchase orders',
                'actionHref'  => '/purchase-orders',
            ]) ?>
        </div>
    <?php else : ?>
        <div class="table-wrap">
            <table class="table">
                <thead>
                    <tr>
                        <th scope="col">Order</th>
                        <th scope="col">Order date</th>
                        <th scope="col">Status</th>
                        <th scope="col"></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($figures['awaitingReceipt'] as $order) : ?>
                        <tr>
                            <td>
                                <a class="row-entity" href="/purchase-orders/<?= (int) $order->id ?>">
                                    <svg class="icon" aria-hidden="true">
                                        <use href="/assets/icons/lucide-sprite.svg#truck"></use>
                                    </svg>
                                    <span class="cell-primary"><?= View::e($order->orderNumber) ?></span>
                                </a>
                            </td>
                            <td class="tabular"><?= View::e($order->orderDate) ?></td>
                            <td>
                                <span class="badge badge--<?=
                                    $order->status === PurchaseOrderStatus::Ordered ? 'approved' : 'pending'
                                ?>"><?= View::e($order->status->label()) ?></span>
                            </td>
                            <td class="numeric">
                                <a class="btn btn--sm" href="/purchase-orders/<?= (int) $order->id ?>/receive">
                                    <span>Receive</span>
                                </a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>

<div class="card">
    <div class="card-header">
        <h2 class="card-title">Issue queue</h2>
        <a class="btn btn--ghost btn--sm" href="/sales-orders?status=Approved">
            <span>View all</span>
        </a>
    </div>
    <?php if ($figures['awaitingIssue'] === []) : ?>
        <div class="card-body">
            <?= $view->renderPartial('layout/_empty-state', [
                'icon'        => 'package',
                'heading'     => 'Nothing waiting to be shipped',
                'text'        => 'Sales orders appear here once an admin approves them. '
                    . 'Until then there is nothing to take off the shelf.',
                'actionLabel' => 'Browse sales orders',
                'actionHref'  => '/sales-orders',
            ]) ?>
        </div>
    <?php else : ?>
        <div class="table-wrap">
            <table class="table">
                <thead>
                    <tr>
                        <th scope="col">Order</th>
                        <th scope="col">Order date</th>
                        <th scope="col">Status</th>
                        <th scope="col"></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($figures['awaitingIssue'] as $order) : ?>
                        <tr>
                            <td>
                                <a class="row-entity" href="/sales-orders/<?= (int) $order->id ?>">
                                    <svg class="icon" aria-hidden="true">
                                        <use href="/assets/icons/lucide-sprite.svg#shopping-cart"></use>
                                    </svg>
                                    <span class="cell-primary"><?= View::e($order->orderNumber) ?></span>
                                </a>
                            </td>
                            <td class="tabular"><?= View::e($order->orderDate) ?></td>
                            <td><span class="badge badge--approved"><?= View::e($order->status->label()) ?></span></td>
                            <td class="numeric">
                                <a class="btn btn--sm" href="/sales-orders/<?= (int) $order->id ?>/issue">
                                    <span>Issue goods</span>
                                </a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>

<div class="card">
    <div class="card-header">
        <h2 class="card-title">Products needing attention</h2>
        <a class="btn btn--ghost btn--sm" href="/products?stock=low">
            <span>View all low stock</span>
        </a>
    </div>
    <?= $view->renderPartial('dashboard/_low-stock', ['rows' => $figures['lowStock']]) ?>
</div>
