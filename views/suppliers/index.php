<?php

declare(strict_types=1);

/**
 * Daftar supplier. Supplier dan Customer adalah entity terpisah; tampilannya
 * berbagi partial karena bentuk field-nya identik.
 *
 * @var View $view
 * @var list<Supplier> $suppliers
 */

use App\Entity\Supplier;
use App\Support\View;

echo $view->renderPartial('layout/_party-list', [
    'view' => $view,
    'csrf' => $csrf,
    'parties' => $suppliers,
    'paginator' => $paginator,
    'basePath' => $basePath,
    'entityLabel' => 'supplier',
    'entityLabelPlural' => 'suppliers',
    'icon' => 'clipboard-list',
    'blurb' => 'Companies you buy from. Every purchase order names one.',
    'filters' => $filters,
    'hasFilters' => $hasFilters,
    'total' => $total,
    'activeCount' => $activeCount,
    'canManage' => true,
]);
