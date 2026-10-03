<?php

declare(strict_types=1);

/**
 * Report dan export CSV (REPORT-01, FR-027).
 *
 * Angka "matching records" di halaman ini dihitung dari baris yang PERSIS akan
 * ditulis ke file, bukan dari query terpisah — itulah sebabnya apa yang
 * dilihat user dan apa yang ia unduh tidak bisa berbeda.
 *
 * Rentang yang kosong tetap boleh diekspor: hasilnya file berisi header saja,
 * bukan kegagalan. "Tidak ada data pada periode ini" adalah jawaban yang sah.
 *
 * @var View $view
 * @var array{start: string, end: string} $range      rentang yang dipakai menghitung
 * @var array{start: string, end: string} $requested  nilai yang diisi user
 * @var array<string, string> $errors
 * @var int $orderCount
 * @var array<string, int> $statusTotals
 * @var int $movementCount
 * @var bool $canSeeStockMovement
 * @var int $purchaseOrderCount
 * @var array<string, int> $purchaseOrderTotals
 * @var bool $isScoped
 * @var int $maxRangeDays
 */

use App\Entity\Enum\PurchaseOrderStatus;
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

$poLabels = [];
foreach (PurchaseOrderStatus::cases() as $status) {
    $poLabels[$status->value] = $status->label();
}

// Warna sama dengan dashboard Admin dan daftar Purchase Order.
$poBadges = [
    PurchaseOrderStatus::Draft->value => 'badge--draft',
    PurchaseOrderStatus::Ordered->value => 'badge--approved',
    PurchaseOrderStatus::PartiallyReceived->value => 'badge--pending',
    PurchaseOrderStatus::Received->value => 'badge--received',
    PurchaseOrderStatus::Cancelled->value => 'badge--cancelled',
];

$exportQuery = http_build_query(['start_date' => $range['start'], 'end_date' => $range['end']]);
?>
<div class="page-header">
    <div>
        <h1 class="page-title">Reports</h1>
        <p class="page-subtitle">
            Export order status and stock movement for a date range, as CSV.
            <?= $isScoped ? 'Your export covers the orders you created.' : '' ?>
        </p>
    </div>
</div>

<div class="card">
    <form class="toolbar toolbar--with-notes" method="get" action="/reports">
        <div class="field">
            <label class="field-label" for="start_date">From</label>
            <input class="input" type="date" id="start_date" name="start_date" required
                   value="<?= View::e($requested['start']) ?>">
            <?php if (isset($errors['start_date'])) : ?>
                <p class="field-error"><?= View::e($errors['start_date']) ?></p>
            <?php endif; ?>
        </div>
        <div class="field">
            <label class="field-label" for="end_date">To</label>
            <input class="input" type="date" id="end_date" name="end_date" required
                   value="<?= View::e($requested['end']) ?>">
            <?php if (isset($errors['end_date'])) : ?>
                <p class="field-error"><?= View::e($errors['end_date']) ?></p>
            <?php else : ?>
                <p class="field-hint">At most <?= (int) $maxRangeDays ?> days.</p>
            <?php endif; ?>
        </div>
        <button type="submit" class="btn btn--primary">
            <svg class="icon" aria-hidden="true"><use href="/assets/icons/lucide-sprite.svg#search"></use></svg>
            <span>Apply range</span>
        </button>
    </form>

    <?php if ($errors !== []) : ?>
        <div class="card-body">
            <div class="alert alert--error">
                That date range could not be used, so the figures below cover the default range
                <?= View::e($range['start']) ?> to <?= View::e($range['end']) ?> instead.
            </div>
        </div>
    <?php endif; ?>
</div>

<div class="stat-grid">
    <div class="stat stat--feature">
        <div class="stat-chip stat-chip--info">
            <svg class="icon" aria-hidden="true">
                <use href="/assets/icons/lucide-sprite.svg#shopping-cart"></use>
            </svg>
        </div>
        <div class="stat-body">
            <div class="stat-label">Matching sales orders</div>
            <div class="stat-value tabular"><?= (int) $orderCount ?></div>
            <div class="stat-delta stat-delta--flat">
                <svg class="icon icon--sm" aria-hidden="true">
                    <use href="/assets/icons/lucide-sprite.svg#clock"></use>
                </svg>
                <span><?= View::e($range['start']) ?> → <?= View::e($range['end']) ?></span>
            </div>
        </div>
    </div>

    <?php if ($canSeeStockMovement) : ?>
        <div class="stat">
            <div class="stat-chip stat-chip--success">
                <svg class="icon" aria-hidden="true">
                    <use href="/assets/icons/lucide-sprite.svg#warehouse"></use>
                </svg>
            </div>
            <div class="stat-body">
                <div class="stat-label">Matching stock movements</div>
                <div class="stat-value tabular"><?= (int) $movementCount ?></div>
                <div class="stat-delta stat-delta--flat">
                    <svg class="icon icon--sm" aria-hidden="true">
                        <use href="/assets/icons/lucide-sprite.svg#package"></use>
                    </svg>
                    <span>receipts, issues and adjustments</span>
                </div>
            </div>
        </div>
    <?php endif; ?>

    <div class="stat">
        <div class="stat-chip stat-chip--warning">
            <svg class="icon" aria-hidden="true"><use href="/assets/icons/lucide-sprite.svg#users"></use></svg>
        </div>
        <div class="stat-body">
            <div class="stat-label">Scope</div>
            <div class="stat-value stat-value--text"><?= $isScoped ? 'Your orders' : 'All users' ?></div>
            <div class="stat-delta stat-delta--flat">
                <svg class="icon icon--sm" aria-hidden="true">
                    <use href="/assets/icons/lucide-sprite.svg#file-text"></use>
                </svg>
                <span><?= $isScoped ? 'orders you created' : 'every order in the system' ?></span>
            </div>
        </div>
    </div>
</div>

<div class="card">
    <div class="card-header">
        <h2 class="card-title">Order status report</h2>
        <a class="btn btn--primary btn--sm" href="/reports/orders.csv?<?= View::e($exportQuery) ?>">
            <svg class="icon" aria-hidden="true"><use href="/assets/icons/lucide-sprite.svg#file-text"></use></svg>
            <span>Export CSV</span>
        </a>
    </div>
    <div class="card-body">
        <?php if ($orderCount === 0) : ?>
            <?= $view->renderPartial('layout/_empty-state', [
                'icon'        => 'inbox',
                'heading'     => 'No orders in this date range',
                'text'        => 'Nothing was ordered between these two dates. You can still export — '
                    . 'the file will contain the column headers and no rows.',
                'actionLabel' => '',
                'actionHref'  => '',
            ]) ?>
        <?php else : ?>
            <p class="muted">
                These are the same records the CSV will contain, counted from the same query.
            </p>
            <?= $view->renderPartial('dashboard/_status-tally', [
                'tally'    => $statusTotals,
                'labels'   => $labels,
                'badges'   => $badges,
                'linkBase' => '/sales-orders',
            ]) ?>
        <?php endif; ?>
    </div>
</div>

<?php if ($canSeeStockMovement) : ?>
    <div class="card">
        <div class="card-header">
            <h2 class="card-title">Stock movement report</h2>
            <a class="btn btn--primary btn--sm"
               href="/reports/stock-movement.csv?<?= View::e($exportQuery) ?>">
                <svg class="icon" aria-hidden="true">
                    <use href="/assets/icons/lucide-sprite.svg#file-text"></use>
                </svg>
                <span>Export CSV</span>
            </a>
        </div>
        <div class="card-body">
            <?php if ($movementCount === 0) : ?>
                <?= $view->renderPartial('layout/_empty-state', [
                    'icon'        => 'warehouse',
                    'heading'     => 'No stock moved in this date range',
                    'text'        => 'No goods were received, issued or adjusted between these two dates. '
                        . 'Exporting produces a headers-only file.',
                    'actionLabel' => '',
                    'actionHref'  => '',
                ]) ?>
            <?php else : ?>
                <p class="muted">
                    <span class="tabular"><?= (int) $movementCount ?></span>
                    ledger entries will be exported, one row per movement, in date order.
                </p>
            <?php endif; ?>
        </div>
    </div>

    <div class="card">
        <div class="card-header">
            <h2 class="card-title">Purchase order status report</h2>
            <a class="btn btn--primary btn--sm"
               href="/reports/purchase-orders.csv?<?= View::e($exportQuery) ?>">
                <svg class="icon" aria-hidden="true">
                    <use href="/assets/icons/lucide-sprite.svg#file-text"></use>
                </svg>
                <span>Export CSV</span>
            </a>
        </div>
        <div class="card-body">
            <?php if ($purchaseOrderCount === 0) : ?>
                <?= $view->renderPartial('layout/_empty-state', [
                    'icon'        => 'truck',
                    'heading'     => 'No purchase orders in this date range',
                    'text'        => 'No purchase orders were placed between these two dates. '
                        . 'Exporting produces a headers-only file.',
                    'actionLabel' => '',
                    'actionHref'  => '',
                ]) ?>
            <?php else : ?>
                <p class="muted">
                    These are the same records the CSV will contain, including ordered and received
                    quantities so partial receipts stay visible.
                </p>
                <?= $view->renderPartial('dashboard/_status-tally', [
                    'tally'    => $purchaseOrderTotals,
                    'labels'   => $poLabels,
                    'badges'   => $poBadges,
                    'linkBase' => '/purchase-orders',
                ]) ?>
            <?php endif; ?>
        </div>
    </div>
<?php endif; ?>
