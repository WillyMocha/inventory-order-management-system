<?php

declare(strict_types=1);

/**
 * Dashboard Sales (DASH-01, FR-026, §1.2).
 *
 * Sales melihat ringkasan ORDER MILIKNYA SENDIRI. Pembatasannya sudah terjadi
 * di query — halaman ini memang tidak pernah menerima order milik orang lain,
 * jadi tidak ada apa pun di sini yang perlu disembunyikan.
 *
 * Tidak ada tombol approve di halaman ini, dan itu bukan alasan aturannya
 * aman: segregation of duties ditegakkan SalesOrderService di server
 * (FR-018).
 *
 * @var View $view
 * @var string $userName
 * @var string $roleLabel
 * @var array{
 *     ordersByStatus: array<string, int>,
 *     draft: int,
 *     pendingApproval: int,
 *     approved: int,
 *     fulfilled: int,
 *     cancelled: int,
 *     total: int
 * } $figures
 */

use App\Entity\Enum\SalesOrderStatus;
use App\Support\View;

$labels = [];
foreach (SalesOrderStatus::cases() as $status) {
    $labels[$status->value] = $status->label();
}

$badges = [
    SalesOrderStatus::Draft->value => 'badge--draft',
    SalesOrderStatus::PendingApproval->value => 'badge--pending',
    SalesOrderStatus::Approved->value => 'badge--approved',
    SalesOrderStatus::Fulfilled->value => 'badge--fulfilled',
    SalesOrderStatus::Cancelled->value => 'badge--cancelled',
];

$draft = (int) $figures['draft'];
$pending = (int) $figures['pendingApproval'];
$fulfilled = (int) $figures['fulfilled'];
?>
<div class="page-header">
    <div>
        <h1 class="page-title">Dashboard</h1>
        <p class="page-subtitle">
            <?= View::e($userName) ?> · <?= View::e($roleLabel) ?> · your own orders only
        </p>
    </div>
    <a class="btn btn--primary" href="/sales-orders/create">
        <svg class="icon" aria-hidden="true"><use href="/assets/icons/lucide-sprite.svg#plus"></use></svg>
        <span>Create sales order</span>
    </a>
</div>

<div class="stat-grid">
    <div class="stat stat--feature">
        <div class="stat-chip stat-chip--info">
            <svg class="icon" aria-hidden="true"><use href="/assets/icons/lucide-sprite.svg#file-text"></use></svg>
        </div>
        <div class="stat-body">
            <div class="stat-label">Draft orders</div>
            <div class="stat-value tabular"><?= $draft ?></div>
            <div class="stat-delta stat-delta--flat">
                <svg class="icon icon--sm" aria-hidden="true">
                    <use href="/assets/icons/lucide-sprite.svg#clipboard-list"></use>
                </svg>
                <span><?= $draft > 0 ? 'still yours to edit' : 'nothing in progress' ?></span>
            </div>
        </div>
    </div>

    <div class="stat">
        <div class="stat-chip stat-chip--warning">
            <svg class="icon" aria-hidden="true"><use href="/assets/icons/lucide-sprite.svg#clock"></use></svg>
        </div>
        <div class="stat-body">
            <div class="stat-label">Awaiting approval</div>
            <div class="stat-value tabular"><?= $pending ?></div>
            <div class="stat-delta stat-delta--flat">
                <svg class="icon icon--sm" aria-hidden="true">
                    <use href="/assets/icons/lucide-sprite.svg#users"></use>
                </svg>
                <span><?= $pending > 0 ? 'with an admin for a decision' : 'nothing submitted' ?></span>
            </div>
        </div>
    </div>

    <div class="stat">
        <div class="stat-chip stat-chip--success">
            <svg class="icon" aria-hidden="true">
                <use href="/assets/icons/lucide-sprite.svg#check-circle"></use>
            </svg>
        </div>
        <div class="stat-body">
            <div class="stat-label">Fulfilled</div>
            <div class="stat-value tabular"><?= $fulfilled ?></div>
            <div class="stat-delta stat-delta--<?= $fulfilled > 0 ? 'up' : 'flat' ?>">
                <svg class="icon icon--sm" aria-hidden="true">
                    <use href="/assets/icons/lucide-sprite.svg#<?=
                        $fulfilled > 0 ? 'trending-up' : 'package'
                    ?>"></use>
                </svg>
                <span><?= $fulfilled > 0 ? 'shipped from the warehouse' : 'none shipped yet' ?></span>
            </div>
        </div>
    </div>
</div>

<div class="card">
    <div class="card-header">
        <h2 class="card-title">Your orders by status</h2>
        <a class="btn btn--ghost btn--sm" href="/sales-orders">
            <span>View all</span>
        </a>
    </div>
    <div class="card-body">
        <?php if ((int) $figures['total'] === 0) : ?>
            <?= $view->renderPartial('layout/_empty-state', [
                'icon'        => 'shopping-cart',
                'heading'     => 'You have not created an order yet',
                'text'        => 'Your drafts, submissions and fulfilled orders will be summarised here. '
                    . 'Start by drafting an order for a customer.',
                'actionLabel' => 'Create sales order',
                'actionHref'  => '/sales-orders/create',
            ]) ?>
        <?php else : ?>
            <?= $view->renderPartial('dashboard/_status-tally', [
                'tally'    => $figures['ordersByStatus'],
                'labels'   => $labels,
                'badges'   => $badges,
                'linkBase' => '/sales-orders',
            ]) ?>
        <?php endif; ?>
    </div>
</div>

<div class="card">
    <div class="card-header">
        <h2 class="card-title">Export your orders</h2>
    </div>
    <div class="card-body">
        <p class="muted">
            The report screen exports the orders you created for any date range you choose,
            as a CSV file. Orders created by other users are never included.
        </p>
        <div class="form-actions">
            <a class="btn" href="/reports">
                <svg class="icon" aria-hidden="true">
                    <use href="/assets/icons/lucide-sprite.svg#file-text"></use>
                </svg>
                <span>Open reports</span>
            </a>
        </div>
    </div>
</div>
