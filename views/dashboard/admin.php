<?php

declare(strict_types=1);

/**
 * Dashboard Admin (DASH-01, FR-026, §1.2).
 *
 * Admin melihat seluruh data: nilai inventori, product di bawah reorder
 * point, dan sebaran order per status. Setiap angka di halaman ini berasal
 * dari query aggregation — tidak ada satu pun yang ditulis di template.
 *
 * @var View $view
 * @var string $userName
 * @var string $roleLabel
 * @var array{
 *     inventoryValue: string,
 *     belowReorderPoint: int,
 *     salesOrdersByStatus: array<string, int>,
 *     purchaseOrdersByStatus: array<string, int>,
 *     pendingApproval: int,
 *     lowStock: list<array{product: Product, totalQuantity: int}>,
 *     stockMovement: array{
 *         start: string,
 *         end: string,
 *         days: list<array{date: string, in: int, out: int}>,
 *         totalIn: int,
 *         totalOut: int,
 *         net: int
 *     }
 * } $figures
 */

use App\Entity\Enum\PurchaseOrderStatus;
use App\Entity\Enum\SalesOrderStatus;
use App\Entity\Product;
use App\Support\Money;
use App\Support\View;

$salesLabels = [];
$salesBadges = [
    SalesOrderStatus::Draft->value => 'badge--draft',
    SalesOrderStatus::PendingApproval->value => 'badge--pending',
    SalesOrderStatus::Approved->value => 'badge--approved',
    SalesOrderStatus::Fulfilled->value => 'badge--fulfilled',
    SalesOrderStatus::Cancelled->value => 'badge--cancelled',
];
foreach (SalesOrderStatus::cases() as $status) {
    $salesLabels[$status->value] = $status->label();
}

$purchaseLabels = [];
$purchaseBadges = [
    PurchaseOrderStatus::Draft->value => 'badge--draft',
    PurchaseOrderStatus::Ordered->value => 'badge--approved',
    PurchaseOrderStatus::PartiallyReceived->value => 'badge--pending',
    PurchaseOrderStatus::Received->value => 'badge--received',
    PurchaseOrderStatus::Cancelled->value => 'badge--cancelled',
];
foreach (PurchaseOrderStatus::cases() as $status) {
    $purchaseLabels[$status->value] = $status->label();
}

$lowStockCount = (int) $figures['belowReorderPoint'];
$pending = (int) $figures['pendingApproval'];
?>
<div class="page-header">
    <div>
        <h1 class="page-title">Dashboard</h1>
        <p class="page-subtitle">
            <?= View::e($userName) ?> · <?= View::e($roleLabel) ?> · every warehouse, every order
        </p>
    </div>
    <a class="btn btn--primary" href="/reports">
        <svg class="icon" aria-hidden="true"><use href="/assets/icons/lucide-sprite.svg#file-text"></use></svg>
        <span>Open reports</span>
    </a>
</div>

<div class="stat-grid">
    <div class="stat stat--feature">
        <div class="stat-chip stat-chip--success">
            <svg class="icon" aria-hidden="true"><use href="/assets/icons/lucide-sprite.svg#banknote"></use></svg>
        </div>
        <div class="stat-body">
            <div class="stat-label">Inventory value</div>
            <div class="stat-value tabular"><?= View::e(Money::format($figures['inventoryValue'])) ?></div>
            <div class="stat-delta stat-delta--flat">
                <svg class="icon icon--sm" aria-hidden="true">
                    <use href="/assets/icons/lucide-sprite.svg#package"></use>
                </svg>
                <span>quantity × purchase price, all warehouses</span>
            </div>
        </div>
    </div>

    <div class="stat">
        <div class="stat-chip stat-chip--warning">
            <svg class="icon" aria-hidden="true">
                <use href="/assets/icons/lucide-sprite.svg#alert-triangle"></use>
            </svg>
        </div>
        <div class="stat-body">
            <div class="stat-label">Below reorder point</div>
            <div class="stat-value tabular"><?= $lowStockCount ?></div>
            <div class="stat-delta stat-delta--<?= $lowStockCount > 0 ? 'down' : 'up' ?>">
                <svg class="icon icon--sm" aria-hidden="true">
                    <use href="/assets/icons/lucide-sprite.svg#<?=
                        $lowStockCount > 0 ? 'trending-down' : 'check-circle'
                    ?>"></use>
                </svg>
                <span><?= $lowStockCount > 0 ? 'needs restocking' : 'all above reorder point' ?></span>
            </div>
        </div>
    </div>

    <div class="stat">
        <div class="stat-chip stat-chip--info">
            <svg class="icon" aria-hidden="true"><use href="/assets/icons/lucide-sprite.svg#clock"></use></svg>
        </div>
        <div class="stat-body">
            <div class="stat-label">Awaiting your approval</div>
            <div class="stat-value tabular"><?= $pending ?></div>
            <div class="stat-delta stat-delta--flat">
                <svg class="icon icon--sm" aria-hidden="true">
                    <use href="/assets/icons/lucide-sprite.svg#shopping-cart"></use>
                </svg>
                <span><?= $pending > 0 ? 'sales orders need a decision' : 'nothing waiting on you' ?></span>
            </div>
        </div>
    </div>
</div>

<div class="card">
    <div class="card-header">
        <h2 class="card-title">Sales orders by status</h2>
        <a class="btn btn--ghost btn--sm" href="/sales-orders">
            <span>View all</span>
        </a>
    </div>
    <div class="card-body">
        <?= $view->renderPartial('dashboard/_status-tally', [
            'tally'    => $figures['salesOrdersByStatus'],
            'labels'   => $salesLabels,
            'badges'   => $salesBadges,
            'linkBase' => '/sales-orders',
        ]) ?>
    </div>
</div>

<div class="card">
    <div class="card-header">
        <h2 class="card-title">Purchase orders by status</h2>
        <a class="btn btn--ghost btn--sm" href="/purchase-orders">
            <span>View all</span>
        </a>
    </div>
    <div class="card-body">
        <?= $view->renderPartial('dashboard/_status-tally', [
            'tally'    => $figures['purchaseOrdersByStatus'],
            'labels'   => $purchaseLabels,
            'badges'   => $purchaseBadges,
            'linkBase' => '/purchase-orders',
        ]) ?>
    </div>
</div>

<?= $view->renderPartial('dashboard/_movement-chart', ['movement' => $figures['stockMovement']]) ?>

<div class="card">
    <div class="card-header">
        <h2 class="card-title">Products needing attention</h2>
        <a class="btn btn--ghost btn--sm" href="/products?stock=low">
            <span>View all low stock</span>
        </a>
    </div>
    <?= $view->renderPartial('dashboard/_low-stock', ['rows' => $figures['lowStock']]) ?>
</div>
