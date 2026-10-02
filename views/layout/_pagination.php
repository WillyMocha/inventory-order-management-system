<?php

declare(strict_types=1);

/**
 * Kontrol pagination.
 *
 * Seluruh filter yang sedang aktif ikut terbawa pada setiap link, sehingga
 * berpindah halaman tidak menghilangkan filter dan URL-nya tetap dapat
 * dibagikan (FR-025).
 *
 * @var Paginator $paginator
 * @var string $basePath
 */

use App\Support\Paginator;
use App\Support\View;

if ($paginator->totalItems() === 0) {
    return;
}
?>
<div class="pagination">
    <p class="pagination-info">
        Showing <?= $paginator->firstItemNumber() ?>–<?= $paginator->lastItemNumber() ?>
        of <?= $paginator->totalItems() ?>
    </p>

    <?php if ($paginator->totalPages() > 1) : ?>
        <ul class="pagination-links">
            <?php if ($paginator->hasPrevious()) : ?>
                <li>
                    <a
                        class="pagination-link"
                        href="<?= View::e($paginator->urlForPage($basePath, $paginator->currentPage() - 1)) ?>"
                        rel="prev"
                    >Previous</a>
                </li>
            <?php endif; ?>

            <?php foreach ($paginator->pageWindow() as $page) : ?>
                <li>
                    <a
                        class="pagination-link"
                        href="<?= View::e($paginator->urlForPage($basePath, $page)) ?>"
                        <?= $page === $paginator->currentPage() ? 'aria-current="page"' : '' ?>
                    ><?= $page ?></a>
                </li>
            <?php endforeach; ?>

            <?php if ($paginator->hasNext()) : ?>
                <li>
                    <a
                        class="pagination-link"
                        href="<?= View::e($paginator->urlForPage($basePath, $paginator->currentPage() + 1)) ?>"
                        rel="next"
                    >Next</a>
                </li>
            <?php endif; ?>
        </ul>
    <?php endif; ?>
</div>
