<?php

declare(strict_types=1);

/**
 * Navigasi utama.
 *
 * Menu ditampilkan sesuai role — tetapi ini murni presentasi. Penegakan yang
 * sesungguhnya ada pada route table dan Authorization guard di server;
 * menyembunyikan link BUKAN kontrol akses (§1.2, security standard §2).
 *
 * @var Session $session
 * @var string $activeNav
 */

use App\Entity\Enum\Role;
use App\Support\Csrf;
use App\Support\Session;
use App\Support\View;

$role = $session->role();

if ($role === null) {
    return;
}

$isAdmin = $role === Role::Admin;
$isSales = $role === Role::Sales;
$isWarehouse = $role === Role::WarehouseStaff;

/** @var list<array{key: string, href: string, label: string, icon: string}> $items */
$items = [
    ['key' => 'dashboard', 'href' => '/dashboard', 'label' => 'Dashboard', 'icon' => 'layout-dashboard'],
    ['key' => 'products', 'href' => '/products', 'label' => 'Products', 'icon' => 'package'],
];

// Purchase Order tidak tersedia untuk Sales (§1.2).
if ($isAdmin || $isWarehouse) {
    $items[] = ['key' => 'purchase-orders', 'href' => '/purchase-orders', 'label' => 'Purchase Orders', 'icon' => 'truck'];
}

$items[] = ['key' => 'sales-orders', 'href' => '/sales-orders', 'label' => 'Sales Orders', 'icon' => 'shopping-cart'];

if ($isAdmin || $isSales) {
    $items[] = ['key' => 'customers', 'href' => '/customers', 'label' => 'Customers', 'icon' => 'users'];
}

if ($isAdmin) {
    $items[] = ['key' => 'suppliers', 'href' => '/suppliers', 'label' => 'Suppliers', 'icon' => 'clipboard-list'];
    $items[] = ['key' => 'users', 'href' => '/users', 'label' => 'Users', 'icon' => 'users'];
}

$items[] = ['key' => 'reports', 'href' => '/reports', 'label' => 'Reports', 'icon' => 'file-text'];

// Profil sendiri tersedia untuk seluruh role (002-user-profile-page).
$items[] = ['key' => 'profile', 'href' => '/profile', 'label' => 'My profile', 'icon' => 'users'];

/** @var Csrf $csrf */
$csrf = $csrf ?? null;
?>
<nav class="nav" aria-label="Main">
    <div class="nav-brand-row">
        <a class="nav-brand" href="/dashboard">IOMS</a>

        <?php /* Hanya tampil pada layar sempit, menutup drawer. */ ?>
        <label class="nav-close" for="nav-toggle">
            <svg class="icon" aria-hidden="true"><use href="/assets/icons/lucide-sprite.svg#x-circle"></use></svg>
            <span class="visually-hidden">Close navigation</span>
        </label>
    </div>

    <ul class="nav-links">
        <?php foreach ($items as $item) : ?>
            <li>
                <a
                    class="nav-link"
                    href="<?= View::e($item['href']) ?>"
                    <?= $activeNav === $item['key'] ? 'aria-current="page"' : '' ?>
                >
                    <svg class="icon" aria-hidden="true"><use href="/assets/icons/lucide-sprite.svg#<?= View::e($item['icon']) ?>"></use></svg>
                    <span><?= View::e($item['label']) ?></span>
                </a>
            </li>
        <?php endforeach; ?>
    </ul>

    <div class="nav-user">
        <a class="nav-user-name" href="/profile" title="My profile">
            <?= View::e($session->userName() ?? '') ?>
            <span class="muted">· <?= View::e($role->label()) ?></span>
        </a>
        <form method="post" action="/logout">
            <?= $csrf instanceof Csrf ? $csrf->field() : '' ?>
            <button type="submit" class="btn btn--ghost btn--sm">
                <svg class="icon" aria-hidden="true"><use href="/assets/icons/lucide-sprite.svg#log-out"></use></svg>
                <span>Sign out</span>
            </button>
        </form>
    </div>
</nav>
