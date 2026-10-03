<?php

declare(strict_types=1);

/**
 * Layout utama aplikasi — dipakai seluruh halaman setelah login.
 *
 * Navigasi berupa SIDEBAR pada layar lebar, dan drawer yang dapat dibuka-tutup
 * pada layar sempit.
 *
 * Drawer-nya digerakkan CHECKBOX, bukan JavaScript. Itu disengaja: brief
 * menetapkan aplikasi tetap berfungsi penuh tanpa JavaScript, dan drawer yang
 * hanya dapat dibuka lewat JS akan menghilangkan SELURUH navigasi begitu JS
 * mati. `nav-drawer.js` hanya menambahkan aria-expanded dan tombol Escape di
 * atas mekanisme yang sudah bekerja sendiri.
 *
 * Urutan elemen di bawah ini penting: checkbox harus mendahului `.nav` dan
 * `.nav-scrim` agar selector `:checked ~` dapat menjangkau keduanya.
 *
 * Seluruh string UI berbahasa Inggris (spec C-007); komentar berbahasa
 * Indonesia. Setiap variabel keluar lewat View::e().
 *
 * @var string $content HTML halaman yang sudah dirender
 * @var View $view
 * @var Session $session
 * @var string|null $title
 * @var string|null $activeNav
 */

use App\Support\Session;
use App\Support\View;

$pageTitle = isset($title) && is_string($title) ? $title : 'Inventory & Order Management';
$current = isset($activeNav) && is_string($activeNav) ? $activeNav : '';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= View::e($pageTitle) ?> · IOMS</title>
    <link rel="stylesheet" href="/assets/css/tokens.css">
    <link rel="stylesheet" href="/assets/css/app.css">
</head>
<body>
<div class="app-shell">
    <input class="nav-toggle visually-hidden" type="checkbox" id="nav-toggle" aria-expanded="false">

    <?= $view->renderPartial('layout/_nav', ['session' => $session, 'activeNav' => $current]) ?>

    <label class="nav-scrim" for="nav-toggle" aria-hidden="true"></label>

    <main class="page">
        <div class="topbar">
            <label class="topbar-toggle" for="nav-toggle">
                <svg class="icon" aria-hidden="true"><use href="/assets/icons/lucide-sprite.svg#menu"></use></svg>
                <span class="visually-hidden">Open navigation</span>
            </label>
            <a class="topbar-brand" href="/dashboard">IOMS</a>
        </div>

        <div class="container">
            <?= $view->renderPartial('layout/_flash', ['session' => $session]) ?>
            <?= $content ?>
        </div>
    </main>
</div>
<script type="module" src="/assets/js/main.js"></script>
</body>
</html>
