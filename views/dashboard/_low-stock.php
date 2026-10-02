<?php

declare(strict_types=1);

/**
 * Product yang berada pada atau di bawah reorder point (spec A-008).
 *
 * Dipakai dashboard Admin dan Warehouse Staff. Keduanya membaca daftar yang
 * sama sehingga tidak mungkin memberi angka yang berbeda kepada dua orang
 * yang melihat stock yang sama.
 *
 * @var list<array{product: Product, totalQuantity: int}> $rows
 */

use App\Entity\Product;
use App\Support\View;

?>
<?php if ($rows === []) : ?>
    <div class="card-body">
        <div class="empty-state">
            <div class="empty-state-icon">
                <svg class="icon icon--lg" aria-hidden="true">
                    <use href="/assets/icons/lucide-sprite.svg#check-circle"></use>
                </svg>
            </div>
            <p class="empty-state-title">Every product is above its reorder point</p>
            <p class="empty-state-text">
                Nothing needs restocking right now. Products appear here as soon as their
                total quantity across all warehouses reaches the reorder point.
            </p>
            <a class="btn btn--primary" href="/products?stock=low">
                <svg class="icon" aria-hidden="true">
                    <use href="/assets/icons/lucide-sprite.svg#package"></use>
                </svg>
                <span>Review the catalog</span>
            </a>
        </div>
    </div>
<?php else : ?>
    <div class="table-wrap">
        <table class="table">
            <thead>
                <tr>
                    <th scope="col">Product</th>
                    <th scope="col">SKU</th>
                    <th scope="col" class="numeric">In stock</th>
                    <th scope="col" class="numeric">Reorder point</th>
                    <th scope="col">Status</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($rows as $row) : ?>
                    <?php $product = $row['product']; ?>
                    <tr>
                        <td>
                            <a class="row-entity" href="/products/<?= (int) $product->id ?>">
                                <svg class="icon" aria-hidden="true">
                                    <use href="/assets/icons/lucide-sprite.svg#package"></use>
                                </svg>
                                <span class="cell-primary"><?= View::e($product->name) ?></span>
                            </a>
                        </td>
                        <td class="tabular"><?= View::e($product->sku) ?></td>
                        <td class="numeric tabular"><?= (int) $row['totalQuantity'] ?></td>
                        <td class="numeric tabular"><?= (int) $product->reorderPoint ?></td>
                        <td><span class="badge badge--low-stock">Low stock</span></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
<?php endif; ?>
