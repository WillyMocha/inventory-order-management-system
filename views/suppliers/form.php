<?php

declare(strict_types=1);

/**
 * Form supplier - membungkus partial bersama.
 *
 * @var View $view
 */

use App\Support\View;

echo $view->renderPartial('layout/_party-form', [
    'csrf' => $csrf,
    'party' => $party,
    'old' => $old,
    'errors' => $errors,
    'kind' => $kind,
    'basePath' => $basePath,
]);
