# Module: Authentication (`auth`)

**Back to**: [00-overview.md](../00-overview.md) · **Last Updated**: 2026-10-03

## Summary

Login dengan email dan password, session, logout, pembatasan percobaan gagal, validasi ulang
akun pada setiap request, dan profil sendiri (lihat profil, ganti password sendiri). Guard per
route tinggal di [platform](platform.md).

## Capabilities

- **AUTH-CAP-001** — Login dengan email dan password yang valid; diarahkan ke dashboard sesuai role
- **AUTH-CAP-002** — Menolak kredensial salah dengan pesan generik (tidak membocorkan bagian yang salah)
- **AUTH-CAP-003** — Menolak user nonaktif
- **AUTH-CAP-004** — Membatasi percobaan login gagal (rate limit)
- **AUTH-CAP-005** — Logout dan mengakhiri session
- **AUTH-CAP-006** — Melihat profil sendiri (nama, email, role, status; read-only) — semua role (brief §1.2, spec 002)
- **AUTH-CAP-007** — Mengganti password sendiri dengan password saat ini; batas percobaan berbagi counter dengan login (spec 002)
- **AUTH-CAP-008** — Mengakhiri session akun yang dinonaktifkan, terhapus, atau diganti role-nya pada request berikutnya (spec 002 FR-012)

## Key Entities & Rules

- **AUTH-ENT-001 LoginAttempt** — email, ip_address (biner `inet_pton`), attempted_at, succeeded.
  Dicatat walau email tidak terdaftar (`login_attempt`)
- Rule: kegagalan berulang per email/IP dalam satu jendela waktu memicu `RateLimitException`
  (batas di `config/app.php`)
- Rule: session id diregenerasi setelah login (`Session::login()` → `session_regenerate_id(true)`)
- Rule: password diverifikasi dengan `password_verify`; rehash bila algoritma berubah
- Rule: panjang minimum password satu konstanta, `User::MIN_PASSWORD_LENGTH` (8), dipakai Admin
  maupun ganti password sendiri
- Rule: ganti password sendiri menuntut password saat ini; password baru tidak boleh sama dengan
  yang lama; hanya tebakan password saat ini yang salah yang dihitung sebagai kegagalan
- Rule: setiap request terautentikasi memeriksa ulang bahwa user di session masih ada, aktif, dan
  role-nya sama (`AuthService::activeSessionUser`)

## API Surface

**Exposes**

| Kind | Name / Route | Role | Entry file |
| ---- | ------------ | ---- | ---------- |
| HTTP | `GET /login` | publik | [`app/Controller/AuthController.php`](../../../app/Controller/AuthController.php) |
| HTTP | `POST /login` | publik (CSRF) | `AuthController::login` |
| HTTP | `POST /logout` | semua role | `AuthController::logout` |
| HTTP | `GET /profile` | semua role | [`app/Controller/ProfileController.php`](../../../app/Controller/ProfileController.php) |
| HTTP | `POST /profile/password` | semua role (CSRF) | `ProfileController::changePassword` |
| PHP | `AuthService::attempt(email, password, ip): ?User` | — | [`app/Service/AuthService.php`](../../../app/Service/AuthService.php) |
| PHP | `AuthService::verifyPasswordFor(User, password): bool` | step-up re-auth (dipakai `users`) | idem |
| PHP | `AuthService::changeOwnPassword(User, current, new, confirmation, ip): void` | — | idem |
| PHP | `AuthService::activeSessionUser(userId, Role): ?User` | dipanggil `public/index.php` | idem |

**Consumes**

- `users` — `UserRepositoryInterface::findByEmail`, `findById`, `updatePasswordHash`;
  `UserService::requireUser` (profil)
- `platform` — `Session`, `Csrf`, `View`

## Data Flow

- Login: form → CSRF check → rate-limit check → `findByEmail` → `password_verify` → catat attempt →
  `Session::login` (regenerasi id) → redirect `/dashboard`
- Ganti password sendiri: form → CSRF → rate-limit check → aturan format → `password_verify`
  (salah → catat kegagalan) → tolak bila sama dengan yang lama → `updatePasswordHash` → hapus
  kegagalan → `Session::regenerate` → redirect `/profile`
- Setiap request terautentikasi: guard role → `activeSessionUser` → bila `null`: `logout` →
  redirect `/login` atau JSON 401

## Screens / Pages

| Screen | Route | Entry file | Related capability |
| ------ | ----- | ---------- | ------------------ |
| Sign in | `/login` | `views/auth/login.php` (layout `views/layout/auth.php`) | AUTH-CAP-001 |
| My profile | `/profile` | `views/profile/show.php` | AUTH-CAP-006, AUTH-CAP-007 |

## Dependencies

- **Other modules**: users, platform
- **External**: —

## Test Coverage

- `AuthService` — unit (`tests/Unit/Service/AuthServiceTest.php`), termasuk
  `changeOwnPassword` dan `activeSessionUser`
- Profil sendiri — integration (`tests/Integration/ProfileFlowTest.php`)
- Validasi ulang session — integration (`tests/Integration/SessionRevalidationTest.php`)
- Alur session dan guard — integration (`tests/Integration/AuthFlowTest.php`)
- `LoginAttempt` repository — integration (`RepositoryCoverageTest`)

## Known Gaps / Risks

- Tidak ada password reset (di luar scope); Admin yang mengatur ulang password lewat `users`.

## Change Log

- **2026-10-03**: 002-user-profile-page diimplementasikan — AUTH-CAP-006 terpenuhi, ditambah
  AUTH-CAP-007 (ganti password sendiri) dan AUTH-CAP-008 (validasi ulang session); celah profil
  dihapus dari Known Gaps.
- **2026-10-03**: Initial version generated from codebase survey.
