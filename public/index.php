<?php

declare(strict_types=1);

/**
 * Front controller — satu-satunya entry point aplikasi.
 *
 * Tanggung jawab: memastikan versi PHP, bootstrap konfigurasi dan container,
 * memulai session, memeriksa authorization, mendispatch route, lalu
 * menerjemahkan setiap exception menjadi respons yang aman.
 *
 * Stack trace TIDAK PERNAH sampai ke user (ERR-01, constitution Principle V).
 */

use App\Support\Authorization;
use App\Support\Csrf;
use App\Support\Exception\DomainException;
use App\Support\Exception\ForbiddenException;
use App\Support\Exception\NotFoundException;
use App\Support\Exception\RateLimitException;
use App\Support\Exception\UnauthenticatedException;
use App\Support\Exception\ValidationException;
use App\Support\Request;
use App\Support\Response;
use App\Support\Router;
use App\Support\Session;
use App\Support\View;

// -----------------------------------------------------------------------
// Version guard.
//
// Spec C-001 menetapkan PHP 8.4 tepat - tidak boleh di bawah maupun di atas.
// Gagal cepat dengan pesan jelas jauh lebih baik daripada perilaku aneh yang
// membingungkan di kemudian hari.
// -----------------------------------------------------------------------
if (PHP_MAJOR_VERSION !== 8 || PHP_MINOR_VERSION !== 4) {
    http_response_code(500);
    header('Content-Type: text/plain; charset=UTF-8');
    echo 'Configuration error: this application requires PHP 8.4.x exactly. Detected ' . PHP_VERSION . '.';
    exit(1);
}

require_once dirname(__DIR__) . '/vendor/autoload.php';

/** @var array<string, mixed> $config */
$config = require dirname(__DIR__) . '/config/app.php';

/** @var callable(array<string, mixed>): array<string, mixed> $buildContainer */
$buildContainer = require dirname(__DIR__) . '/config/container.php';
$container = $buildContainer($config);

/** @var Session $session */
$session = $container['session'];
/** @var Router $router */
$router = $container['router'];
/** @var Authorization $authorization */
$authorization = $container['authorization'];
/** @var Csrf $csrf */
$csrf = $container['csrf'];
/** @var View $view */
$view = $container['view'];

$session->start();

$request = Request::fromGlobals();

/**
 * Merender halaman error yang aman, atau JSON bila request menuju /api/*.
 */
$errorResponse = static function (
    Request $request,
    View $view,
    int $status,
    string $code,
    string $message,
): Response {
    if ($request->expectsJson()) {
        return Response::jsonError($code, $message, $status);
    }

    return Response::html(
        $view->render('error/' . $status, ['title' => 'Error ' . $status], 'layout/auth'),
        $status,
    );
};

try {
    $route = $router->match($request->method(), $request->path());

    // Deny by default: guard membaca daftar role dari route table.
    $authorization->authorizeRoute($route['roles']);

    // CSRF wajib pada setiap method non-GET (security standard §2).
    if ($request->method() !== 'GET' && $request->method() !== 'HEAD') {
        $submitted = $request->input(Csrf::fieldName());

        if (!$csrf->isValid($submitted)) {
            throw new ForbiddenException('Invalid or expired form token. Please try again.');
        }
    }

    // Controller dibangun oleh container lewat constructor injection manual.
    // Front controller tidak pernah merakit dependency sendiri - seluruh
    // object graph ada di config/container.php (ARCH-01).
    /** @var array<string, callable(): object> $controllers */
    $controllers = $container['controllers'];
    $controllerKey = $route['controller'];

    if (!isset($controllers[$controllerKey])) {
        // Route sudah terdaftar tetapi controller-nya belum dibuat (masih ada
        // phase implementasi yang tersisa). Dicatat ke server log; user
        // menerima 404 yang aman.
        error_log('Route terdaftar tanpa controller: ' . $controllerKey);
        throw new NotFoundException();
    }

    $controller = $controllers[$controllerKey]();
    $action = $route['action'];

    if (!method_exists($controller, $action)) {
        error_log('Controller tanpa action: ' . $controllerKey . '::' . $action);
        throw new NotFoundException();
    }

    /** @var Response $response */
    $response = $controller->{$action}($request->withRouteParams($route['params']));
    $response->send();
} catch (UnauthenticatedException) {
    // Route HTML dialihkan ke login; /api/* menerima 401 JSON (API-01).
    if ($request->expectsJson()) {
        Response::jsonError('unauthorized', 'Authentication required.', 401)->send();
    } else {
        Response::redirect('/login')->send();
    }
} catch (ForbiddenException $e) {
    $errorResponse($request, $view, 403, 'forbidden', $e->getMessage())->send();
} catch (NotFoundException) {
    $errorResponse($request, $view, 404, 'not_found', 'Resource not found.')->send();
} catch (RateLimitException $e) {
    if ($request->expectsJson()) {
        Response::jsonError('rate_limited', $e->getMessage(), 429)->send();
    } else {
        Response::html(
            $view->render('error/429', ['title' => 'Too Many Requests'], 'layout/auth'),
            429,
        )->send();
    }
} catch (ValidationException | DomainException $e) {
    // Kegagalan aturan bisnis yang lolos sampai ke sini berarti controller
    // tidak menanganinya. Dicatat, lalu dibalas sebagai bad request.
    error_log('Unhandled domain/validation error: ' . $e->getMessage());
    $errorResponse($request, $view, 400, 'invalid_request', 'The request could not be processed.')->send();
} catch (Throwable $e) {
    // Jaring pengaman terakhir. Detail HANYA ke server log - tidak pernah ke
    // user (ERR-01, security standard §11).
    error_log(sprintf(
        'Unhandled %s: %s in %s:%d',
        $e::class,
        $e->getMessage(),
        $e->getFile(),
        $e->getLine(),
    ));

    $errorResponse($request, $view, 500, 'server_error', 'Something went wrong. Please try again.')->send();
}
