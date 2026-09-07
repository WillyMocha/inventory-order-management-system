<?php

/**
 * Single front controller. Every request that is not a real file on disk reaches this
 * file (Apache `FallbackResource /index.php`).
 *
 * Boot order: autoload -> environment -> failure handling -> security headers -> dispatch.
 *
 * Dispatch is not wired yet. The router arrives in T023 and the composition root that
 * builds controllers in T034; until then this file boots the application and reports that
 * it booted. It does not pretend to route.
 */

declare(strict_types=1);

const IOMS_ROOT = __DIR__ . '/..';

$autoload = IOMS_ROOT . '/vendor/autoload.php';

if (!is_file($autoload)) {
    // Before dependencies are installed there is nothing to log with and nothing to
    // render with, so this one case answers in plain text.
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    echo "Dependencies are not installed. Run: docker compose exec app composer install\n";

    return;
}

require_once $autoload;
require_once IOMS_ROOT . '/config/env.php';

ioms_load_env(IOMS_ROOT . '/.env');

/**
 * Never show a user the inside of a failure.
 *
 * FR-067 and NFR-006 forbid disclosing the exception class, stack trace, SQL, filesystem
 * paths or credentials. Diagnostics go to the server log (stderr, which Apache forwards
 * to the container's output); the user gets a fixed string.
 *
 * T033 replaces this with the full exception-to-HTTP mapping and the rendered error
 * pages. The rule it enforces is the same, and it applies from the first request.
 */
ini_set('display_errors', '0');
ini_set('log_errors', '1');
error_reporting(E_ALL);

set_exception_handler(static function (Throwable $exception): void {
    error_log(sprintf(
        '[ioms] uncaught %s: %s at %s:%d',
        $exception::class,
        $exception->getMessage(),
        $exception->getFile(),
        $exception->getLine()
    ));

    if (!headers_sent()) {
        http_response_code(500);
        header('Content-Type: text/plain; charset=utf-8');
    }

    echo "An unexpected error occurred.\n";
});

set_error_handler(static function (int $severity, string $message, string $file, int $line): bool {
    // Promote every notice and warning to an exception so nothing is silently tolerated.
    throw new ErrorException($message, 0, $severity, $file, $line);
});

/*
 * Baseline response headers are set by Apache in docker/apache-hardening.conf, not here.
 *
 * That is deliberate: Apache applies them to every response including static assets that
 * never reach PHP, whereas setting them here would cover only PHP responses and produced
 * duplicate headers on the ones it did cover. One source of truth.
 *
 * A Content-Security-Policy is not set yet either: it belongs with the views and the
 * JavaScript they load (T037-T040, T130), so it is written when there is markup to
 * constrain rather than guessed at now.
 */

// ---------------------------------------------------------------------------
// Dispatch — replaced in Phase 2 by:
//     $container = require IOMS_ROOT . '/config/container.php';
//     (new Router(require IOMS_ROOT . '/config/routes.php'))->dispatch($container);
// ---------------------------------------------------------------------------

http_response_code(503);
header('Content-Type: text/plain; charset=utf-8');
header('Retry-After: 3600');

printf(
    "Inventory & Order Management System\n"
    . "Bootstrap OK — PHP %s, autoloader and environment loaded.\n\n"
    . "No routes are registered yet. Routing is implemented in T023 (router),\n"
    . "T024 (route table) and T034 (composition root).\n",
    PHP_VERSION
);
