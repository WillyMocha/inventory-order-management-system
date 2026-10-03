<?php

/**
 * Bootstrap PHPUnit.
 *
 * APP_ENV=testing membuat config/app.php memilih DB_DATABASE_TEST, sehingga
 * integration test tidak pernah menyentuh data demo.
 */

declare(strict_types=1);

require_once dirname(__DIR__) . '/vendor/autoload.php';

putenv('APP_ENV=testing');
$_ENV['APP_ENV'] = 'testing';
