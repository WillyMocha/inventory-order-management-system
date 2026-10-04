<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\User;
use App\Service\AuthService;
use App\Service\UserService;
use App\Support\Csrf;
use App\Support\Exception\RateLimitException;
use App\Support\Exception\UnauthenticatedException;
use App\Support\Exception\ValidationException;
use App\Support\Request;
use App\Support\Response;
use App\Support\Session;
use App\Support\View;

/**
 * Profil milik user yang sedang login (002-user-profile-page) - seluruh role.
 *
 * Identitas pemilik profil HANYA diambil dari session. Controller ini tidak
 * pernah membaca id user dari request, sehingga tidak ada jalan untuk membuka
 * profil orang lain (FR-003). Seluruh field profil read-only (FR-011).
 */
final class ProfileController
{
    public function __construct(
        private readonly View $view,
        private readonly UserService $userService,
        private readonly AuthService $authService,
        private readonly Session $session,
        private readonly Csrf $csrf,
    ) {
    }

    public function show(Request $request): Response
    {
        return Response::html($this->renderProfile($this->currentUser()));
    }

    /**
     * Ganti password sendiri (FR-005). CSRF sudah diperiksa front controller
     * untuk setiap request non-GET (FR-009), sehingga tidak diulang di sini.
     * Nilai password tidak pernah dikembalikan ke form maupun dicatat
     * (NFR-001).
     */
    public function changePassword(Request $request): Response
    {
        $actor = $this->currentUser();

        try {
            $this->authService->changeOwnPassword(
                $actor,
                $request->input('current_password'),
                $request->input('new_password'),
                $request->input('new_password_confirmation'),
                $request->ipAddress(),
            );
        } catch (ValidationException $e) {
            return Response::html($this->renderProfile($actor, $e->errors()), 422);
        } catch (RateLimitException) {
            // User tetap di halaman profilnya dengan pesan umum, bukan halaman
            // 429 global; jumlah percobaan tidak disebutkan (FR-008).
            return Response::html($this->renderProfile($actor, [], true), 429);
        }

        // Session id lama tidak berlaku lagi; user tetap login (FR-007).
        $this->session->regenerate();
        $this->session->flash('success', 'Your password has been changed.');

        return Response::redirect('/profile');
    }

    /**
     * @param array<string, string> $errors
     */
    private function renderProfile(User $user, array $errors = [], bool $rateLimited = false): string
    {
        return $this->view->render('profile/show', [
            'title'       => 'My profile',
            'activeNav'   => 'profile',
            'user'        => $user,
            'errors'      => $errors,
            'rateLimited' => $rateLimited,
            'csrf'        => $this->csrf,
        ]);
    }

    private function currentUser(): User
    {
        $id = $this->session->userId();

        if ($id === null) {
            throw new UnauthenticatedException();
        }

        return $this->userService->requireUser($id);
    }
}
