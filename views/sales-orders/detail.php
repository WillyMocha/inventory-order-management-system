<?php

declare(strict_types=1);

/**
 * Detail Sales Order (SO-01, FR-023).
 *
 * Menampilkan pembuat DAN penyetuju secara eksplisit — keduanya adalah bukti
 * segregation of duties yang dapat dibaca langsung dari layar (§1.2).
 *
 * Aksi yang dirender hanyalah yang relevan untuk status dan role saat ini.
 * Perlu ditegaskan: ini BUKAN kontrol akses. Yang menolak adalah
 * SalesOrderService di server; menyembunyikan tombol hanya mencegah user
 * ditawari aksi yang pasti gagal.
 *
 * @var Csrf $csrf
 * @var SalesOrder $order
 * @var list<array{product: Product, quantity: int, unitPrice: string, lineTotal: string}> $lines
 * @var string $orderTotal
 * @var Customer $customer
 * @var Warehouse $warehouse
 * @var User $creator
 * @var User|null $approver
 * @var list<StockLedger> $movements
 * @var bool $canEdit hanya pembuat order, hanya selama Draft (spec 004)
 * @var bool $canSubmit
 * @var bool $canDecide
 * @var bool $ownOrderAwaitingApproval
 * @var bool $canIssue
 * @var bool $canCancel
 */

use App\Entity\Customer;
use App\Entity\Enum\SalesOrderStatus;
use App\Entity\Product;
use App\Entity\SalesOrder;
use App\Entity\StockLedger;
use App\Entity\User;
use App\Entity\Warehouse;
use App\Support\Csrf;
use App\Support\Money;
use App\Support\View;

$badgeClass = match ($order->status) {
    SalesOrderStatus::Draft => 'badge--draft',
    SalesOrderStatus::PendingApproval => 'badge--pending',
    SalesOrderStatus::Approved => 'badge--approved',
    SalesOrderStatus::Fulfilled => 'badge--fulfilled',
    SalesOrderStatus::Cancelled => 'badge--cancelled',
};

$orderId = (int) $order->id;

// Nama product untuk riwayat pergerakan, diambil dari line yang sudah dimuat
// controller — tidak perlu query tambahan per baris ledger.
$productNames = [];
foreach ($lines as $line) {
    $productNames[(int) $line['product']->id] = $line['product']->name;
}
?>
<div class="page-header">
    <div>
        <h1 class="page-title tabular"><?= View::e($order->orderNumber) ?></h1>
        <p class="page-subtitle">
            <span class="badge <?= $badgeClass ?>"><?= View::e($order->status->label()) ?></span>
            · <?= View::e($customer->name) ?>
            · <?= View::e($order->orderDate) ?>
        </p>
    </div>
    <div class="row">
        <a class="btn btn--ghost" href="/sales-orders">Back to sales orders</a>

        <?php if ($canEdit) : ?>
            <a class="btn" href="/sales-orders/<?= $orderId ?>/edit">Edit</a>
        <?php endif; ?>

        <?php if ($canSubmit) : ?>
            <form method="post" action="/sales-orders/<?= $orderId ?>/submit"
                  data-confirm="Submit this order for approval? You will not be able to edit it afterwards, and an Admin other than you must approve it.">
                <?= $csrf->field() ?>
                <button type="submit" class="btn btn--primary">Submit for approval</button>
            </form>
        <?php endif; ?>

        <?php if ($canDecide) : ?>
            <form method="post" action="/sales-orders/<?= $orderId ?>/approve"
                  data-confirm="Approve this order? The warehouse will then be able to issue the goods and reduce stock.">
                <?= $csrf->field() ?>
                <button type="submit" class="btn btn--primary">Approve</button>
            </form>
            <form method="post" action="/sales-orders/<?= $orderId ?>/reject"
                  data-confirm="Reject this order? It will be cancelled and no goods will be issued.">
                <?= $csrf->field() ?>
                <button type="submit" class="btn btn--danger">Reject</button>
            </form>
        <?php endif; ?>

        <?php if ($canIssue) : ?>
            <a class="btn btn--primary" href="/sales-orders/<?= $orderId ?>/issue">
                <svg class="icon" aria-hidden="true"><use href="/assets/icons/lucide-sprite.svg#truck"></use></svg>
                <span>Issue goods</span>
            </a>
        <?php endif; ?>

        <?php if ($canCancel) : ?>
            <form method="post" action="/sales-orders/<?= $orderId ?>/cancel"
                  data-confirm="Cancel this order? This cannot be undone, and any stock already issued is not returned automatically.">
                <?= $csrf->field() ?>
                <button type="submit" class="btn">Cancel order</button>
            </form>
        <?php endif; ?>
    </div>
</div>

<?php if ($ownOrderAwaitingApproval) : ?>
    <div class="alert alert--info" role="status">
        <svg class="icon" aria-hidden="true"><use href="/assets/icons/lucide-sprite.svg#clock"></use></svg>
        <div>
            <strong>Waiting for someone else to approve.</strong>
            You created this order, so you cannot approve it yourself — approval must come from
            an Admin who did not create it.
        </div>
    </div>
<?php endif; ?>

<div class="stat-grid">
    <div class="stat">
        <div class="stat-chip stat-chip--info">
            <svg class="icon" aria-hidden="true"><use href="/assets/icons/lucide-sprite.svg#users"></use></svg>
        </div>
        <div class="stat-body">
            <div class="stat-label">Created by</div>
            <div class="stat-value stat-value--text"><?= View::e($creator->name) ?></div>
            <div class="stat-delta stat-delta--flat">
                <span><?= View::e($creator->role->label()) ?></span>
            </div>
        </div>
    </div>

    <div class="stat">
        <div class="stat-chip stat-chip--<?= $approver instanceof User ? 'success' : 'warning' ?>">
            <svg class="icon" aria-hidden="true">
                <use href="/assets/icons/lucide-sprite.svg#<?= $approver instanceof User ? 'check-circle' : 'clock' ?>"></use>
            </svg>
        </div>
        <div class="stat-body">
            <div class="stat-label">Approved by</div>
            <div class="stat-value stat-value--text">
                <?= $approver instanceof User ? View::e($approver->name) : 'Not yet approved' ?>
            </div>
            <div class="stat-delta stat-delta--flat">
                <span>
                    <?php if ($approver instanceof User) : ?>
                        <?= View::e($approver->role->label()) ?> — never the creator
                    <?php else : ?>
                        awaiting an Admin decision
                    <?php endif; ?>
                </span>
            </div>
        </div>
    </div>

    <div class="stat">
        <div class="stat-chip stat-chip--success">
            <svg class="icon" aria-hidden="true"><use href="/assets/icons/lucide-sprite.svg#banknote"></use></svg>
        </div>
        <div class="stat-body">
            <div class="stat-label">Order total</div>
            <div class="stat-value tabular"><?= View::e(Money::format($orderTotal)) ?></div>
            <div class="stat-delta stat-delta--flat">
                <span><?= count($lines) ?> line<?= count($lines) === 1 ? '' : 's' ?></span>
            </div>
        </div>
    </div>

    <div class="stat">
        <div class="stat-chip">
            <svg class="icon" aria-hidden="true"><use href="/assets/icons/lucide-sprite.svg#warehouse"></use></svg>
        </div>
        <div class="stat-body">
            <div class="stat-label">Source warehouse</div>
            <div class="stat-value stat-value--text"><?= View::e($warehouse->name) ?></div>
            <div class="stat-delta stat-delta--flat">
                <span><?= View::e($warehouse->location) ?></span>
            </div>
        </div>
    </div>
</div>

<div class="card">
    <div class="card-header">
        <h2 class="card-title">Order lines</h2>
    </div>
    <div class="table-wrap">
        <table class="table">
            <thead>
                <tr>
                    <th scope="col">Product</th>
                    <th scope="col">SKU</th>
                    <th scope="col" class="numeric">Quantity</th>
                    <th scope="col" class="numeric">Unit price</th>
                    <th scope="col" class="numeric">Line total</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($lines as $line) : ?>
                    <tr>
                        <td>
                            <a class="row-entity" href="/products/<?= (int) $line['product']->id ?>">
                                <svg class="icon" aria-hidden="true">
                                    <use href="/assets/icons/lucide-sprite.svg#package"></use>
                                </svg>
                                <span class="cell-primary"><?= View::e($line['product']->name) ?></span>
                            </a>
                        </td>
                        <td class="tabular"><?= View::e($line['product']->sku) ?></td>
                        <td class="numeric tabular"><?= (int) $line['quantity'] ?></td>
                        <td class="numeric"><?= View::e(Money::format($line['unitPrice'])) ?></td>
                        <td class="numeric"><?= View::e(Money::format($line['lineTotal'])) ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
            <tfoot>
                <tr>
                    <td colspan="4" class="numeric"><strong>Order total</strong></td>
                    <td class="numeric"><strong><?= View::e(Money::format($orderTotal)) ?></strong></td>
                </tr>
            </tfoot>
        </table>
    </div>
</div>

<div class="card">
    <div class="card-header">
        <h2 class="card-title">Stock movement history</h2>
    </div>

    <?php if ($movements === []) : ?>
        <?= $view->renderPartial('layout/_empty-state', [
            'icon' => 'inbox',
            'heading' => 'No stock has moved yet',
            'text' => 'Stock leaves the warehouse only when an approved order is issued. Every movement is recorded here and can never be edited or deleted.',
            'actionLabel' => '',
            'actionHref' => '',
        ]) ?>
    <?php else : ?>
        <div class="table-wrap">
            <table class="table">
                <thead>
                    <tr>
                        <th scope="col">Movement</th>
                        <th scope="col">Product</th>
                        <th scope="col" class="numeric">Quantity</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($movements as $movement) : ?>
                        <tr>
                            <td>
                                <span class="row-entity">
                                    <svg class="icon" aria-hidden="true">
                                        <use href="/assets/icons/lucide-sprite.svg#truck"></use>
                                    </svg>
                                    <span class="cell-primary"><?= View::e($movement->movementType->label()) ?></span>
                                </span>
                            </td>
                            <td><?= View::e($productNames[$movement->productId] ?? ('#' . $movement->productId)) ?></td>
                            <td class="numeric tabular"><?= (int) $movement->quantity ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>
