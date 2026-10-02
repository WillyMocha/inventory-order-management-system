<?php

declare(strict_types=1);

namespace App\Controller;

use App\Service\AuthService;
use App\Support\Csrf;
use App\Support\Exception\RateLimitException;
use App\Support\Request;
use App\Support\Response;
use App\Support\Session;
use App\Support\View;

/**
 * Login dan logout (AUTH-01, AUTH-02).
 *
 * Controller hanya mengurus HTTP: membaca request, memanggil Service, memilih
 * respons. Aturan kredensialnya sendiri ada di AuthService.
 */
final class AuthController
{
    public function __construct(
        private readonly View $view,
        private readonly AuthService $authService,
        private readonly Session $session,
        private readonly Csrf $csrf,
    ) {
    }

    public function showLogin(Request $request): Response
    {
        // Sudah login: tidak perlu melihat form login lagi.
        if ($this->session->isAuthenticated()) {
            return Response::redirect('/dashboard');
        }

        return Response::html($this->renderLoginForm());
    }

    /**
     * Memproses percobaan login.
     *
     * Kegagalan apa pun - email tak terdaftar, password salah, akun
     * dinonaktifkan - menghasilkan respons yang identik (FR-002).
     */
    public function login(Request $request): Response
    {
        $email = $request->input('email');
        $password = $request->input('password');

        // Validasi bentuk pun memakai pesan yang sama, agar field kosong tidak
        // menghasilkan jalur respons yang berbeda.
        if ($email === '' || $password === '') {
            return Response::html(
                $this->renderLoginForm($email, AuthService::FAILURE_MESSAGE),
                422,
            );
        }

        try {
            $user = $this->authService->attempt($email, $password, $request->ipAddress());
        } catch (RateLimitException $e) {
            return Response::html($this->renderLoginForm($email, $e->getMessage()), 429);
        }

        if ($user === null) {
            // Email dipertahankan agar user tidak perlu mengetik ulang;
            // password tidak pernah dikembalikan ke form.
            return Response::html(
                $this->renderLoginForm($email, AuthService::FAILURE_MESSAGE),
                401,
            );
        }

        // Session::login() melakukan session_regenerate_id(true) - pertahanan
        // terhadap session fixation (AUTH-01).
        $this->session->login($user->id ?? 0, $user->role, $user->name);

        return Response::redirect('/dashboard');
    }

    /**
     * Mengakhiri session. Setelah ini, URL terlindungi tidak dapat dibuka lagi
     * tanpa login ulang (AUTH-02).
     */
    public function logout(Request $request): Response
    {
        $this->session->logout();

        return Response::redirect('/login');
    }

    private function renderLoginForm(string $email = '', ?string $error = null): string
    {
        return $this->view->render(
            'auth/login',
            [
                'title' => 'Sign in',
                'email' => $email,
                'error' => $error,
                'csrf'  => $this->csrf,
            ],
            'layout/auth',
        );
    }
}
