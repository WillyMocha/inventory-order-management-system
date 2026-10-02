<?php

declare(strict_types=1);

/**
 * Layout terpusat tanpa navigasi — dipakai halaman login dan halaman error.
 *
 * @var string $content
 * @var string|null $title
 */

use App\Support\View;

$pageTitle = isset($title) && is_string($title) ? $title : 'Inventory & Order Management';
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
<div class="centered-shell">
    <div class="centered-card">
        <?= $content ?>
    </div>
</div>
</body>
</html>
