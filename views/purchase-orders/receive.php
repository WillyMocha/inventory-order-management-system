<?php

declare(strict_types=1);

/**
 * Form goods receipt (PO-01, FR-014).
 *
 * Setiap line menampilkan outstanding TEPAT DI SAMPING input-nya, dan nilai
 * yang sudah diisi dipertahankan saat penerimaan ditolak — keduanya diminta
 * T085 secara eksplisit.
 *
 * Receipt boleh sebagian: line yang dibiarkan kosong berarti belum datang,
 * bukan nol yang disengaja.
 *
 * @var Csrf $csrf
 * @var PurchaseOrder $order
 * @var list<array{itemId: int, product: Product, quantity: int, received: int, outstanding: int, submitted: int|null}> $lines
 * @var Supplier $supplier
 * @var Warehouse $warehouse
 * @var string|null $error
 */

use App\Entity\Product;
use App\Entity\PurchaseOrder;
use App\Entity\Supplier;
use App\Entity\Warehouse;
use App\Support\Csrf;
use App\Support\View;

$orderId = (int) $order->id;

$totalOutstanding = 0;
$openLines = 0;

foreach ($lines as $line) {
    $totalOutstanding += $line['outstanding'];

    if ($line['outstanding'] > 0) {
        $openLines++;
    }
}
?>
<div class="page-header">
    <div>
        <h1 class="page-title">Receive goods</h1>
        <p class="page-subtitle">
            <span class="tabular"><?= View::e($order->orderNumber) ?></span>
            · <?= View::e($supplier->name) ?>
            · into <?= View::e($warehouse->name) ?>
        </p>
    </div>
    <a class="btn btn--ghost" href="/purchase-orders/<?= $orderId ?>">Back to order</a>
</div>

<?php if ($error !== null) : ?>
    <div class="alert alert--error" role="alert">
        <svg class="icon" aria-hidden="true"><use href="/assets/icons/lucide-sprite.svg#alert-triangle"></use></svg>
        <div>
            <strong>The goods were not received.</strong>
            <?= View::e($error) ?>
            Nothing was changed — stock and the ledger are exactly as they were.
        </div>
    </div>
<?php endif; ?>

<div class="stat-grid">
    <div class="stat">
        <div class="stat-chip stat-chip--warning">
            <svg class="icon" aria-hidden="true"><use href="/assets/icons/lucide-sprite.svg#clock"></use></svg>
        </div>
        <div class="stat-body">
            <div class="stat-label">Still outstanding</div>
            <div class="stat-value tabular"><?= (int) $totalOutstanding ?></div>
            <div class="stat-delta stat-delta--flat">
                <span>across <?= $openLines ?> open line<?= $openLines === 1 ? '' : 's' ?></span>
            </div>
        </div>
    </div>

    <div class="stat">
        <div class="stat-chip stat-chip--info">
            <svg class="icon" aria-hidden="true"><use href="/assets/icons/lucide-sprite.svg#inbox"></use></svg>
        </div>
        <div class="stat-body">
            <div class="stat-label">Partial delivery</div>
            <div class="stat-value stat-value--text">Allowed</div>
            <div class="stat-delta stat-delta--flat">
                <span>leave a line blank if it has not arrived</span>
            </div>
        </div>
    </div>
</div>

<form method="post" action="/purchase-orders/<?= $orderId ?>/receive"
      data-confirm="Record this receipt? Stock in <?= View::e($warehouse->name) ?> will increase and a ledger entry will be written for each line. This cannot be undone.">
    <?= $csrf->field() ?>

    <div class="card">
        <div class="card-header">
            <h2 class="card-title">Quantities arriving now</h2>
        </div>

        <div class="table-wrap">
            <table class="table">
                <thead>
                    <tr>
                        <th scope="col">Product</th>
                        <th scope="col">SKU</th>
                        <th scope="col" class="numeric">Ordered</th>
                        <th scope="col" class="numeric">Already received</th>
                        <th scope="col" class="numeric">Outstanding</th>
                        <th scope="col" class="numeric">Receiving now</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($lines as $line) :
                        $isClosed = $line['outstanding'] === 0;
                        $inputId = 'received-' . $line['itemId'];
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
                            <td class="numeric tabular"><?= (int) $line['received'] ?></td>
                            <td class="numeric tabular">
                                <?php if ($isClosed) : ?>
                                    <span class="badge badge--received">Complete</span>
                                <?php else : ?>
                                    <span class="badge badge--pending"><?= (int) $line['outstanding'] ?></span>
                                <?php endif; ?>
                            </td>
                            <td class="numeric">
                                <label class="visually-hidden" for="<?= View::e($inputId) ?>">
                                    Quantity receiving now for <?= View::e($line['product']->name) ?>
                                </label>
                                <?php if ($isClosed) : ?>
                                    <span class="muted">—</span>
                                <?php else : ?>
                                    <input class="input tabular" type="number"
                                           id="<?= View::e($inputId) ?>"
                                           name="received[<?= (int) $line['itemId'] ?>]"
                                           min="0" step="1"
                                           max="<?= (int) $line['outstanding'] ?>"
                                           value="<?= $line['submitted'] === null ? '' : (int) $line['submitted'] ?>"
                                           placeholder="0">
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <div class="card-body">
            <p class="field-hint">
                Receiving raises stock in <?= View::e($warehouse->name) ?> and writes one ledger entry
                per line. The ledger can never be edited or deleted afterwards. A quantity above the
                outstanding amount is refused, and if any line is refused the whole receipt is
                refused — nothing changes.
            </p>

            <div class="form-actions">
                <button type="submit" class="btn btn--primary">
                    <svg class="icon" aria-hidden="true"><use href="/assets/icons/lucide-sprite.svg#inbox"></use></svg>
                    <span>Record receipt and raise stock</span>
                </button>
                <a class="btn btn--ghost" href="/purchase-orders/<?= $orderId ?>">Cancel</a>
            </div>
        </div>
    </div>
</form>
