<?php

declare(strict_types=1);

/**
 * Sebaran order per status sebagai deret badge beserta angkanya.
 *
 * Dipakai ketiga dashboard agar warna status di Admin, Sales dan Warehouse
 * berarti hal yang sama persis. Kalau tiap view menyusun deretnya sendiri,
 * satu status cepat atau lambat akan berbeda warna di salah satu halaman.
 *
 * Status bernilai nol TETAP ditampilkan — "belum ada" adalah informasi, dan
 * kolom yang hilang membuat pembaca mengira angkanya belum sempat dimuat.
 *
 * @var array<string, int> $tally      status => jumlah
 * @var array<string, string> $labels  status => label yang tampil
 * @var array<string, string> $badges  status => kelas badge
 * @var string $linkBase               awalan URL filter status, '' bila tidak ada
 */

use App\Support\View;

$total = array_sum($tally);
?>
<div class="tally">
    <?php foreach ($tally as $status => $count) : ?>
        <?php
        $label = $labels[$status] ?? $status;
        $badge = $badges[$status] ?? 'badge--draft';
        $href = $linkBase === '' ? '' : $linkBase . '?status=' . rawurlencode($status);
        ?>
        <?php if ($href !== '') : ?>
            <a class="tally-item" href="<?= View::e($href) ?>">
        <?php else : ?>
            <div class="tally-item">
        <?php endif; ?>
            <span class="badge <?= View::e($badge) ?>"><?= View::e($label) ?></span>
            <span class="tally-count tabular"><?= (int) $count ?></span>
        <?php if ($href !== '') : ?>
            </a>
        <?php else : ?>
            </div>
        <?php endif; ?>
    <?php endforeach; ?>
</div>
<p class="tally-total muted">
    <?= $total === 1 ? '1 order in total' : (int) $total . ' orders in total' ?>
</p>
