<?php

declare(strict_types=1);

/**
 * Detail Purchase Order (PO-01, FR-023).
 *
 * Tabel line menampilkan ordered, received DAN outstanding sekaligus —
 * ketiganya diminta requirement, bukan salah satunya (FR-014).
 *
 * @var View $view
 * @var Csrf $csrf
 * @var PurchaseOrder $order
 * @var list<array{itemId: int, product: Product, quantity: int, received: int, outstanding: int, unitPrice: string, lineTotal: string}> $lines
 * @var string $orderTotal
 * @var Supplier $supplier
 * @var Warehouse $warehouse
 * @var User $creator
 * @var list<StockLedger> $movements
 * @var bool $canEdit Admin, atau Warehouse Staff pembuatnya; hanya selama Draft (spec 004)
 * @var bool $canSubmit
 * @var bool $canReceive
 * @var bool $canCancel
 */

use App\Entity\Enum\PurchaseOrderStatus;
use App\Entity\Product;
use App\Entity\PurchaseOrder;
use App\Entity\StockLedger;
use App\Entity\Supplier;
use App\Entity\User;
use App\Entity\Warehouse;
use App\Support\Csrf;
use App\Support\Money;
use App\Support\View;

$badgeClass = match ($order->status) {
    PurchaseOrderStatus::Draft => 'badge--draft',
    PurchaseOrderStatus::Ordered => 'badge--approved',
    PurchaseOrderStatus::PartiallyReceived => 'badge--pending',
    PurchaseOrderStatus::Received => 'badge--received',
    PurchaseOrderStatus::Cancelled => 'badge--cancelled',
};

$orderId = (int) $order->id;

$totalOrdered = 0;
$totalReceived = 0;
$productNames = [];

foreach ($lines as $line) {
    $totalOrdered += $line['quantity'];
    $totalReceived += $line['received'];
    $productNames[(int) $line['product']->id] = $line['product']->name;
}

$totalOutstanding = $totalOrdered - $totalReceived;
$receivedPercent = $totalOrdered === 0 ? 0 : (int) round($totalReceived / $totalOrdered * 100);
?>
<div class="page-header">
    <div>
        <h1 class="page-title tabular"><?= View::e($order->orderNumber) ?></h1>
        <p class="page-subtitle">
            <span class="badge <?= $badgeClass ?>"><?= View::e($order->status->label()) ?></span>
            · <?= View::e($supplier->name) ?>
            · <?= View::e($order->orderDate) ?>
        </p>
    </div>
    <div class="row">
        <a class="btn btn--ghost" href="/purchase-orders">Back to purchase orders</a>

        <?php if ($canEdit) : ?>
            <a class="btn" href="/purchase-orders/<?= $orderId ?>/edit">Edit</a>
        <?php endif; ?>

        <?php if ($canSubmit) : ?>
            <form method="post" action="/purchase-orders/<?= $orderId ?>/submit"
                  data-confirm="Submit this order to the supplier? It can then receive goods, and you will no longer be able to edit it.">
                <?= $csrf->field() ?>
                <button type="submit" class="btn btn--primary">Submit to supplier</button>
            </form>
        <?php endif; ?>

        <?php if ($canReceive) : ?>
            <a class="btn btn--primary" href="/purchase-orders/<?= $orderId ?>/receive">
                <svg class="icon" aria-hidden="true"><use href="/assets/icons/lucide-sprite.svg#inbox"></use></svg>
                <span>Receive goods</span>
            </a>
        <?php endif; ?>

        <?php if ($canCancel) : ?>
            <form method="post" action="/purchase-orders/<?= $orderId ?>/cancel"
                  data-confirm="Cancel this purchase order? Stock already received is NOT returned — the ledger is permanent.">
                <?= $csrf->field() ?>
                <button type="submit" class="btn btn--danger">Cancel order</button>
            </form>
        <?php endif; ?>
    </div>
</div>

<?php if ($order->status === PurchaseOrderStatus::PartiallyReceived) : ?>
    <div class="alert alert--warning" role="status">
        <svg class="icon" aria-hidden="true"><use href="/assets/icons/lucide-sprite.svg#clock"></use></svg>
        <div>
            <strong><?= (int) $totalOutstanding ?> unit<?= $totalOutstanding === 1 ? '' : 's' ?> still outstanding.</strong>
            Part of this order has arrived. Receive the remainder when the supplier delivers it.
        </div>
    </div>
<?php endif; ?>

<div class="stat-grid">
    <div class="stat">
        <div class="stat-chip stat-chip--info">
            <svg class="icon" aria-hidden="true"><use href="/assets/icons/lucide-sprite.svg#clipboard-list"></use></svg>
        </div>
        <div class="stat-body">
            <div class="stat-label">Ordered</div>
            <div class="stat-value tabular"><?= (int) $totalOrdered ?></div>
            <div class="stat-delta stat-delta--flat">
                <span><?= count($lines) ?> line<?= count($lines) === 1 ? '' : 's' ?></span>
            </div>
        </div>
    </div>

    <div class="stat">
        <div class="stat-chip stat-chip--success">
            <svg class="icon" aria-hidden="true"><use href="/assets/icons/lucide-sprite.svg#inbox"></use></svg>
        </div>
        <div class="stat-body">
            <div class="stat-label">Received</div>
            <div class="stat-value tabular"><?= (int) $totalReceived ?></div>
            <div class="stat-delta stat-delta--<?= $receivedPercent === 100 ? 'up' : 'flat' ?>">
                <svg class="icon" aria-hidden="true" style="width:14px;height:14px">
                    <use href="/assets/icons/lucide-sprite.svg#<?= $receivedPercent === 100 ? 'check-circle' : 'trending-up' ?>"></use>
                </svg>
                <span><?= $receivedPercent ?>% of the order</span>
            </div>
        </div>
    </div>

    <div class="stat">
        <div class="stat-chip stat-chip--<?= $totalOutstanding > 0 ? 'warning' : 'success' ?>">
            <svg class="icon" aria-hidden="true">
                <use href="/assets/icons/lucide-sprite.svg#<?= $totalOutstanding > 0 ? 'clock' : 'check-circle' ?>"></use>
            </svg>
        </div>
        <div class="stat-body">
            <div class="stat-label">Outstanding</div>
            <div class="stat-value tabular"><?= (int) $totalOutstanding ?></div>
            <div class="stat-delta stat-delta--flat">
                <span><?= $totalOutstanding > 0 ? 'still to arrive' : 'fully received' ?></span>
            </div>
        </div>
    </div>

    <div class="stat">
        <div class="stat-chip">
            <svg class="icon" aria-hidden="true"><use href="/assets/icons/lucide-sprite.svg#warehouse"></use></svg>
        </div>
        <div class="stat-body">
            <div class="stat-label">Destination</div>
            <div class="stat-value stat-value--text"><?= View::e($warehouse->name) ?></div>
            <div class="stat-delta stat-delta--flat">
                <span>ordered by <?= View::e($creator->name) ?></span>
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
                    <th scope="col" class="numeric">Ordered</th>
                    <th scope="col" class="numeric">Received</th>
                    <th scope="col" class="numeric">Outstanding</th>
                    <th scope="col" class="numeric">Unit cost</th>
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
                        <td class="numeric tabular"><?= (int) $line['received'] ?></td>
                        <td class="numeric tabular">
                            <?php if ($line['outstanding'] > 0) : ?>
                                <span class="badge badge--pending"><?= (int) $line['outstanding'] ?></span>
                            <?php else : ?>
                                <span class="badge badge--received">0</span>
                            <?php endif; ?>
                        </td>
                        <td class="numeric"><?= View::e(Money::format($line['unitPrice'])) ?></td>
                        <td class="numeric"><?= View::e(Money::format($line['lineTotal'])) ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
            <tfoot>
                <tr>
                    <td colspan="6" class="numeric"><strong>Order total</strong></td>
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
            'heading' => 'Nothing received yet',
            'text' => 'Stock rises only when goods are received against this order. Every receipt is recorded here and can never be edited or deleted.',
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
                                        <use href="/assets/icons/lucide-sprite.svg#inbox"></use>
                                    </svg>
                                    <span class="cell-primary"><?= View::e($movement->movementType->label()) ?></span>
                                </span>
                            </td>
                            <td><?= View::e($productNames[$movement->productId] ?? ('#' . $movement->productId)) ?></td>
                            <td class="numeric tabular">+<?= (int) $movement->quantity ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>
