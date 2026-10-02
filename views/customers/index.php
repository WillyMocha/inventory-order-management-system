<?php

declare(strict_types=1);

/**
 * Daftar customer.
 *
 * Sales boleh membaca halaman ini (dibutuhkan saat membuat Sales Order),
 * tetapi hanya Admin yang melihat aksi tulisnya - dan server tetap menolak
 * aksi tulis dari role lain.
 *
 * @var View $view
 * @var list<Customer> $customers
 * @var bool $canManage
 */

use App\Entity\Customer;
use App\Support\View;

echo $view->renderPartial('layout/_party-list', [
    'view' => $view,
    'csrf' => $csrf,
    'parties' => $customers,
    'paginator' => $paginator,
    'basePath' => $basePath,
    'entityLabel' => 'customer',
    'entityLabelPlural' => 'customers',
    'icon' => 'users',
    'blurb' => 'Companies you sell to. Every sales order names one.',
    'filters' => $filters,
    'hasFilters' => $hasFilters,
    'total' => $total,
    'activeCount' => $activeCount,
    'canManage' => $canManage,
]);
