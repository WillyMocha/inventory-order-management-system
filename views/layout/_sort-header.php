<?php

declare(strict_types=1);

/**
 * Header kolom tabel yang dapat diurutkan (FIND-01, FR-024, FR-025).
 *
 * Satu partial dipakai tiga halaman daftar agar pembangunan link sort tidak
 * diduplikasi (SonarQube §4).
 *
 * Dua hal yang dijaga link ini:
 *   1. SELURUH filter aktif tetap terbawa — mengurutkan tidak boleh membuang
 *      hasil pencarian yang sedang tampil;
 *   2. parameter `page` TIDAK dibawa — urutan baru berarti baris pertama
 *      berubah, jadi kembali ke halaman satu (FR-025).
 *
 * @var string $label       teks header
 * @var string $sortKey     key sort, harus ada di allowlist repository
 * @var string $basePath
 * @var array<string, string> $filters  query state aktif, termasuk sort/direction
 * @var bool $numeric       true untuk kolom angka (rata kanan)
 */

use App\Support\View;

$currentSort = $filters['sort'] ?? '';
$currentDirection = strtolower($filters['direction'] ?? '');
$isActive = $currentSort === $sortKey;

// Klik pertama mengurutkan naik; klik berikutnya pada kolom yang sama
// membalik arahnya.
$nextDirection = $isActive && $currentDirection === 'asc' ? 'desc' : 'asc';

$params = $filters;
unset($params['page']);
$params['sort'] = $sortKey;
$params['direction'] = $nextDirection;

$href = $basePath . '?' . http_build_query($params);

if ($isActive) {
    $icon = $currentDirection === 'asc' ? 'arrow-up' : 'arrow-down';
    $ariaSort = $currentDirection === 'asc' ? 'ascending' : 'descending';
} else {
    $icon = 'arrow-up-down';
    $ariaSort = 'none';
}
?>
<th scope="col"<?= ($numeric ?? false) ? ' class="numeric"' : '' ?> aria-sort="<?= View::e($ariaSort) ?>">
    <a class="sort-link<?= $isActive ? ' sort-link--active' : '' ?>" href="<?= View::e($href) ?>">
        <span><?= View::e($label) ?></span>
        <svg class="icon sort-icon" aria-hidden="true">
            <use href="/assets/icons/lucide-sprite.svg#<?= View::e($icon) ?>"></use>
        </svg>
        <span class="visually-hidden">
            <?= $isActive
                ? 'Currently sorted ' . View::e($ariaSort) . '. Activate to reverse the order.'
                : 'Activate to sort by this column.' ?>
        </span>
    </a>
</th>
