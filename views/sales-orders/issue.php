<?php

declare(strict_types=1);

/**
 * Form goods issue (SO-01, FR-019).
 *
 * Setiap line menampilkan quantity yang diminta BERSAMA stock yang tersedia
 * saat ini, sehingga penolakan tidak datang sebagai kejutan.
 *
 * Angka "available" di sini hanyalah panduan — bisa sudah berubah saat form
 * dikirim. Keputusan yang mengikat selalu pembacaan di bawah lock di dalam
 * StockService::issueGoods() (ARCH-02).
 *
 * @var Csrf $csrf
 * @var SalesOrder $order
 * @var list<array{product: Product, quantity: int, available: int}> $lines
 * @var Warehouse $warehouse
 * @var Customer $customer
 * @var string|null $error
 */

use App\Entity\Customer;
use App\Entity\Product;
use App\Entity\SalesOrder;
use App\Entity\Warehouse;
use App\Support\Csrf;
use App\Support\View;

$orderId = (int) $order->id;

$shortfall = 0;
foreach ($lines as $line) {
    if ($line['available'] < $line['quantity']) {
        $shortfall++;
    }
}
?>
<div class="page-header">
    <div>
        <h1 class="page-title">Issue goods</h1>
        <p class="page-subtitle">
            <span class="tabular"><?= View::e($order->orderNumber) ?></span>
            · <?= View::e($customer->name) ?>
            · from <?= View::e($warehouse->name) ?>
        </p>
    </div>
    <a class="btn btn--ghost" href="/sales-orders/<?= $orderId ?>">Back to order</a>
</div>

<?php if ($error !== null) : ?>
    <div class="alert alert--error" role="alert">
        <svg class="icon" aria-hidden="true"><use href="/assets/icons/lucide-sprite.svg#alert-triangle"></use></svg>
        <div>
            <strong>The goods were not issued.</strong>
            <?= View::e($error) ?>
            Nothing was changed — stock and the ledger are exactly as they were.
        </div>
    </div>
<?php endif; ?>

<div class="stat-grid">
    <div class="stat">
        <div class="stat-chip stat-chip--info">
            <svg class="icon" aria-hidden="true"><use href="/assets/icons/lucide-sprite.svg#clipboard-list"></use></svg>
        </div>
        <div class="stat-body">
            <div class="stat-label">Lines to issue</div>
            <div class="stat-value tabular"><?= count($lines) ?></div>
            <div class="stat-delta stat-delta--flat">
                <span>issued together, all or nothing</span>
            </div>
        </div>
    </div>

    <div class="stat">
        <div class="stat-chip stat-chip--<?= $shortfall > 0 ? 'danger' : 'success' ?>">
            <svg class="icon" aria-hidden="true">
                <use href="/assets/icons/lucide-sprite.svg#<?= $shortfall > 0 ? 'x-circle' : 'check-circle' ?>"></use>
            </svg>
        </div>
        <div class="stat-body">
            <div class="stat-label">Lines short on stock</div>
            <div class="stat-value tabular"><?= $shortfall ?></div>
            <div class="stat-delta stat-delta--<?= $shortfall > 0 ? 'down' : 'up' ?>">
                <svg class="icon" aria-hidden="true" style="width:14px;height:14px">
                    <use href="/assets/icons/lucide-sprite.svg#<?= $shortfall > 0 ? 'trending-down' : 'check-circle' ?>"></use>
                </svg>
                <span><?= $shortfall > 0 ? 'the order cannot be issued yet' : 'every line can be covered' ?></span>
            </div>
        </div>
    </div>
</div>

<div class="card">
    <div class="card-header">
        <h2 class="card-title">Requested against available stock</h2>
    </div>

    <div class="table-wrap">
        <table class="table">
            <thead>
                <tr>
                    <th scope="col">Product</th>
                    <th scope="col">SKU</th>
                    <th scope="col" class="numeric">Requested</th>
                    <th scope="col" class="numeric">Available now</th>
                    <th scope="col">Status</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($lines as $line) :
                    $isShort = $line['available'] < $line['quantity'];
                    ?>
                    <tr>
                        <td>
                            <span class="row-entity">
                                <svg class="icon" aria-hidden="true">
                                    <use href="/assets/icons/lucide-sprite.svg#package"></use>
                                </svg>
                                <span class="cell-primary"><?= View::e($line['product']->name) ?></span>
                            </span>
                        </td>
                        <td class="tabular"><?= View::e($line['product']->sku) ?></td>
                        <td class="numeric tabular"><?= (int) $line['quantity'] ?></td>
                        <td class="numeric tabular"><?= (int) $line['available'] ?></td>
                        <td>
                            <?php if ($isShort) : ?>
                                <span class="badge badge--cancelled">
                                    Short by <?= (int) ($line['quantity'] - $line['available']) ?>
                                </span>
                            <?php else : ?>
                                <span class="badge badge--approved">Can be issued</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <div class="card-body">
        <p class="field-hint">
            Issuing reduces stock in <?= View::e($warehouse->name) ?> and writes one ledger entry
            per line. The ledger can never be edited or deleted afterwards. If any single line is
            short at the moment of issue, the whole order is refused and nothing changes.
        </p>

        <form method="post" action="/sales-orders/<?= $orderId ?>/issue"
              data-confirm="Issue the goods for this order? Stock in <?= View::e($warehouse->name) ?> will be reduced and the order will become Fulfilled. This cannot be undone.">
            <?= $csrf->field() ?>
            <div class="form-actions">
                <button type="submit" class="btn btn--primary">
                    <svg class="icon" aria-hidden="true"><use href="/assets/icons/lucide-sprite.svg#truck"></use></svg>
                    <span>Issue goods and reduce stock</span>
                </button>
                <a class="btn btn--ghost" href="/sales-orders/<?= $orderId ?>">Cancel</a>
            </div>
        </form>
    </div>
</div>
