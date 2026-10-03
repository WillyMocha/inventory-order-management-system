<?php

declare(strict_types=1);

namespace App\Controller;

use App\Support\Request;
use App\Support\Response;
use App\Support\View;

/**
 * Health check.
 *
 * Dipakai memverifikasi checkpoint Phase 2 — bahwa container boot dan sebuah
 * route benar-benar dirender lewat layout — sekaligus menjadi target
 * healthcheck container.
 *
 * Sengaja tidak membocorkan apa pun: tanpa versi, tanpa status database,
 * tanpa detail environment (security standard §3).
 */
final class HealthController
{
    public function __construct(private readonly View $view)
    {
    }

    public function index(Request $request): Response
    {
        return Response::html(
            $this->view->render('health/index', ['title' => 'Health'], 'layout/auth'),
        );
    }
}
