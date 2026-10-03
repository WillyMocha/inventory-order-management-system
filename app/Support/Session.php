<?php

declare(strict_types=1);

namespace App\Support;

use App\Entity\Enum\Role;

/**
 * Siklus hidup session.
 *
 * Cookie diberi HttpOnly, SameSite=Lax, dan Secure bila HTTPS aktif. ID
 * di-regenerate setelah login berhasil untuk mencegah session fixation
 * (AUTH-01, research R-005).
 */
final class Session
{
    private const string KEY_USER_ID = 'auth_user_id';
    private const string KEY_USER_ROLE = 'auth_user_role';
    private const string KEY_USER_NAME = 'auth_user_name';
    private const string KEY_FLASH = 'flash';

    public function __construct(
        private readonly string $name,
        private readonly bool $secure,
    ) {
    }

    public function start(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return;
        }

        session_name($this->name);
        session_set_cookie_params([
            'lifetime' => 0,
            'path'     => '/',
            'secure'   => $this->secure,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        session_start();
    }

    /**
     * Dipanggil setelah kredensial terverifikasi. Regenerasi ID adalah
     * pertahanan terhadap session fixation.
     */
    public function login(int $userId, Role $role, string $name): void
    {
        session_regenerate_id(true);

        $_SESSION[self::KEY_USER_ID] = $userId;
        $_SESSION[self::KEY_USER_ROLE] = $role->value;
        $_SESSION[self::KEY_USER_NAME] = $name;
    }

    /**
     * Memperbarui session id tanpa mengubah isinya. Dipanggil setelah
     * perubahan sensitif seperti ganti password sendiri, agar session id lama
     * yang mungkin sudah bocor tidak berlaku lagi (002 FR-007).
     */
    public function regenerate(): void
    {
        // Tanpa session aktif (misalnya integration test di CLI) tidak ada id
        // yang perlu diperbarui.
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_regenerate_id(true);
        }
    }

    /**
     * Menghapus seluruh data session dan mengadaluarsakan cookie, sehingga
     * URL terlindungi tidak dapat dibuka lagi (AUTH-02).
     */
    public function logout(): void
    {
        $_SESSION = [];

        if (ini_get('session.use_cookies') !== false) {
            $params = session_get_cookie_params();
            setcookie(
                session_name() ?: $this->name,
                '',
                [
                    'expires'  => time() - 42000,
                    'path'     => $params['path'],
                    'secure'   => $params['secure'],
                    'httponly' => $params['httponly'],
                    'samesite' => 'Lax',
                ],
            );
        }

        session_destroy();
    }

    public function isAuthenticated(): bool
    {
        return isset($_SESSION[self::KEY_USER_ID]);
    }

    public function userId(): ?int
    {
        $value = $_SESSION[self::KEY_USER_ID] ?? null;

        return is_int($value) ? $value : null;
    }

    public function role(): ?Role
    {
        $value = $_SESSION[self::KEY_USER_ROLE] ?? null;

        return is_string($value) ? Role::tryFrom($value) : null;
    }

    public function userName(): ?string
    {
        $value = $_SESSION[self::KEY_USER_NAME] ?? null;

        return is_string($value) ? $value : null;
    }

    /** @return array<string, mixed> */
    public function all(): array
    {
        /** @var array<string, mixed> $session */
        $session = $_SESSION;

        return $session;
    }

    /**
     * `mixed` dibenarkan: session bag menyimpan nilai apa pun yang dititipkan
     * pemanggil, dan accessor bertipe di atas (userId, role, userName) adalah
     * jalur yang dipakai kode aplikasi.
     */
    public function put(string $key, mixed $value): void
    {
        $_SESSION[$key] = $value;
    }

    /** `mixed` dibenarkan dengan alasan yang sama seperti put(). */
    public function get(string $key): mixed
    {
        return $_SESSION[$key] ?? null;
    }

    public function forget(string $key): void
    {
        unset($_SESSION[$key]);
    }

    /** Pesan sekali-tampil untuk umpan balik setelah redirect. */
    public function flash(string $type, string $message): void
    {
        $_SESSION[self::KEY_FLASH] = ['type' => $type, 'message' => $message];
    }

    /** @return array{type: string, message: string}|null */
    public function pullFlash(): ?array
    {
        $flash = $_SESSION[self::KEY_FLASH] ?? null;
        unset($_SESSION[self::KEY_FLASH]);

        if (!is_array($flash) || !isset($flash['type'], $flash['message'])) {
            return null;
        }

        return [
            'type' => (string) $flash['type'],
            'message' => (string) $flash['message'],
        ];
    }
}
