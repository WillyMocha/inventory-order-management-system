<?php

declare(strict_types=1);

/**
 * Kartu grafik stock movement 30 hari terakhir (spec 005-stock-movement-chart).
 *
 * Dipakai dashboard Admin dan Warehouse Staff saja; Sales tidak pernah
 * menerima figure ini dari DashboardService (FR-011). Grafiknya SVG inline
 * buatan sendiri — tanpa library dan tanpa JavaScript (FR-012):
 *   - batang In di kiri, Out di kanan, sehingga keduanya tetap dapat
 *     dibedakan tanpa mengandalkan warna (NFR-002);
 *   - angka persis setiap hari muncul lewat <title> saat hover, dan lewat
 *     label .chart-tip saat hari yang memiliki pergerakan difokus keyboard;
 *     hari kosong sengaja bukan tab stop (FR-008, analisis A1);
 *   - seluruh 30 hari juga tersedia sebagai tabel untuk screen reader.
 * Geometri sumbu dan tinggi batang dihitung BarChartScale; template ini hanya
 * mencetak angka.
 *
 * @var View $view
 * @var array{
 *     start: string,
 *     end: string,
 *     days: list<array{date: string, in: int, out: int}>,
 *     totalIn: int,
 *     totalOut: int,
 *     net: int
 * } $movement
 */

use App\Support\BarChartScale;
use App\Support\View;

// Sistem koordinat tetap; SVG diskalakan CSS mengikuti lebar kartu (NFR-003).
$width = 720;
$plotLeft = 44;
$plotRight = 712;
$plotTop = 14;
$plotHeight = 176;
$baseline = $plotTop + $plotHeight;
$height = $baseline + 26;
$labelEvery = 5;

$days = $movement['days'];
$dayCount = count($days);
$slotWidth = ($plotRight - $plotLeft) / max(1, $dayCount);
$barWidth = $slotWidth * 0.36;

$maxValue = 0;
foreach ($days as $day) {
    $maxValue = max($maxValue, $day['in'], $day['out']);
}
$scale = new BarChartScale($maxValue, $plotHeight);

$isEmpty = $movement['totalIn'] + $movement['totalOut'] === 0;
$net = (int) $movement['net'];
$netText = match (true) {
    $net > 0 => '+' . $net,
    $net < 0 => '−' . abs($net),
    default => '0',
};
$netClass = match (true) {
    $net > 0 => 'movement-net--up',
    $net < 0 => 'movement-net--down',
    default => '',
};
$reportHref = '/reports?' . http_build_query(['start_date' => $movement['start'], 'end_date' => $movement['end']]);
$summaryLabel = sprintf(
    'Stock movement, last 30 days: %d units in, %d units out',
    $movement['totalIn'],
    $movement['totalOut'],
);

$fmt = static fn (float $value): string => number_format($value, 1, '.', '');
$dayTitle = static fn (array $day): string => (new DateTimeImmutable($day['date']))->format('j M Y')
    . ' — In ' . $day['in'] . ' · Out ' . $day['out'];
?>
<div class="card">
    <div class="card-header">
        <h2 class="card-title">Stock movement — last 30 days</h2>
        <a class="btn btn--ghost btn--sm" href="<?= View::e($reportHref) ?>">
            <span>View stock movement report</span>
        </a>
    </div>
    <div class="card-body">
        <?php if ($isEmpty) : ?>
            <?= $view->renderPartial('layout/_empty-state', [
                'icon'        => 'warehouse',
                'heading'     => 'No stock moved in the last 30 days',
                'text'        => 'Receipts, issues and adjustments will appear here as they are recorded.',
                'actionLabel' => '',
                'actionHref'  => '',
            ]) ?>
        <?php else : ?>
            <dl class="movement-summary">
                <div>
                    <dt>Units in</dt>
                    <dd class="tabular"><?= (int) $movement['totalIn'] ?></dd>
                </div>
                <div>
                    <dt>Units out</dt>
                    <dd class="tabular"><?= (int) $movement['totalOut'] ?></dd>
                </div>
                <div>
                    <dt>Net change</dt>
                    <dd class="tabular movement-net <?= View::e($netClass) ?>"><?= View::e($netText) ?></dd>
                </div>
            </dl>

            <ul class="chart-legend" aria-hidden="true">
                <li><span class="chart-swatch chart-swatch--in"></span>In</li>
                <li><span class="chart-swatch chart-swatch--out"></span>Out</li>
            </ul>

            <svg class="movement-chart" viewBox="0 0 <?= $width ?> <?= $height ?>"
                 role="img" aria-label="<?= View::e($summaryLabel) ?>">
                <?php foreach ($scale->gridlines() as $value) :
                    $y = $baseline - $scale->barHeight($value); ?>
                    <line class="chart-grid" x1="<?= $plotLeft ?>" x2="<?= $plotRight ?>"
                          y1="<?= $y ?>" y2="<?= $y ?>"></line>
                    <text class="chart-axis" x="<?= $plotLeft - 6 ?>" y="<?= $y + 4 ?>"
                          text-anchor="end"><?= (int) $value ?></text>
                <?php endforeach; ?>

                <?php foreach ($days as $index => $day) :
                    $slotX = $plotLeft + $index * $slotWidth;
                    $center = $slotX + $slotWidth / 2;
                    $inHeight = $scale->barHeight($day['in']);
                    $outHeight = $scale->barHeight($day['out']);
                    $hasMovement = $day['in'] + $day['out'] > 0;
                    $tipX = min(max($center, $plotLeft + 70), $plotRight - 70);
                    $isLabelled = $index % $labelEvery === 0 || $index === $dayCount - 1; ?>
                    <g class="chart-day"<?= $hasMovement ? ' tabindex="0"' : '' ?>>
                        <title><?= View::e($dayTitle($day)) ?></title>
                        <rect class="chart-hit" x="<?= $fmt($slotX) ?>" y="<?= $plotTop ?>"
                              width="<?= $fmt($slotWidth) ?>" height="<?= $plotHeight ?>"></rect>
                        <?php if ($inHeight > 0) : ?>
                            <rect class="chart-bar chart-bar--in" x="<?= $fmt($center - $barWidth) ?>"
                                  y="<?= $baseline - $inHeight ?>" width="<?= $fmt($barWidth) ?>"
                                  height="<?= $inHeight ?>"></rect>
                        <?php endif; ?>
                        <?php if ($outHeight > 0) : ?>
                            <rect class="chart-bar chart-bar--out" x="<?= $fmt($center) ?>"
                                  y="<?= $baseline - $outHeight ?>" width="<?= $fmt($barWidth) ?>"
                                  height="<?= $outHeight ?>"></rect>
                        <?php endif; ?>
                        <?php if ($isLabelled) : ?>
                            <text class="chart-axis" x="<?= $fmt($center) ?>" y="<?= $baseline + 18 ?>"
                                  text-anchor="middle"><?= View::e(
                                      (new DateTimeImmutable($day['date']))->format('j M'),
                                  ) ?></text>
                        <?php endif; ?>
                        <?php if ($hasMovement) : ?>
                            <text class="chart-tip" x="<?= $fmt($tipX) ?>" y="<?= $plotTop - 2 ?>"
                                  text-anchor="middle"><?= View::e($dayTitle($day)) ?></text>
                        <?php endif; ?>
                    </g>
                <?php endforeach; ?>

                <line class="chart-baseline" x1="<?= $plotLeft ?>" x2="<?= $plotRight ?>"
                      y1="<?= $baseline ?>" y2="<?= $baseline ?>"></line>
            </svg>

            <?php /* Dibungkus div: <table> tidak menghormati width 1px dari
                     .visually-hidden dan melebarkan halaman di 360px. */ ?>
            <div class="visually-hidden">
                <table>
                    <caption><?= View::e($summaryLabel) ?></caption>
                    <tr><th scope="col">Date</th><th scope="col">In</th><th scope="col">Out</th></tr>
                    <?php foreach ($days as $day) : ?>
                        <tr>
                            <td><?= View::e((new DateTimeImmutable($day['date']))->format('j M Y')) ?></td>
                            <td><?= (int) $day['in'] ?></td>
                            <td><?= (int) $day['out'] ?></td>
                        </tr>
                    <?php endforeach; ?>
                </table>
            </div>
        <?php endif; ?>
    </div>
</div>
