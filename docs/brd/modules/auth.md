# Module: Authentication (`auth`)

**Back to**: [00-overview.md](../00-overview.md) · **Last Updated**: 2026-10-03

## Summary

Login dengan email dan password, session, logout, dan pembatasan percobaan gagal. Guard per
route tinggal di [platform](platform.md).

## Capabilities

- **AUTH-CAP-001** — Login dengan email dan password yang valid; diarahkan ke dashboard sesuai role
- **AUTH-CAP-002** — Menolak kredensial salah dengan pesan generik (tidak membocorkan bagian yang salah)
- **AUTH-CAP-003** — Menolak user nonaktif
- **AUTH-CAP-004** — Membatasi percobaan login gagal (rate limit)
- **AUTH-CAP-005** — Logout dan mengakhiri session
- **AUTH-CAP-006** — Melihat profil sendiri — **BELUM ADA** (requirement brief §1.2; diputuskan 2026-10-03 sebagai celah yang perlu dibuat)

## Key Entities & Rules

- **AUTH-ENT-001 LoginAttempt** — email, ip_address (biner `inet_pton`), attempted_at, succeeded.
  Dicatat walau email tidak terdaftar (`login_attempt`)
- Rule: kegagalan berulang per email/IP dalam satu jendela waktu memicu `RateLimitException`
  (batas di `config/app.php`)
- Rule: session id diregenerasi setelah login (`Session::login()` → `session_regenerate_id(true)`)
- Rule: password diverifikasi dengan `password_verify`; rehash bila algoritma berubah

## API Surface

**Exposes**

| Kind | Name / Route | Role | Entry file |
| ---- | ------------ | ---- | ---------- |
| HTTP | `GET /login` | publik | [`app/Controller/AuthController.php`](../../../app/Controller/AuthController.php) |
| HTTP | `POST /login` | publik (CSRF) | `AuthController::login` |
| HTTP | `POST /logout` | semua role | `AuthController::logout` |
| PHP | `AuthService::attempt(email, password, ip): ?User` | — | [`app/Service/AuthService.php`](../../../app/Service/AuthService.php) |
| PHP | `AuthService::verifyPasswordFor(User, password): bool` | step-up re-auth (dipakai `users`) | idem |

**Consumes**

- `users` — `UserRepositoryInterface::findByEmail`, `updatePasswordHash`
- `platform` — `Session`, `Csrf`, `View`

## Data Flow

- Login: form → CSRF check → rate-limit check → `findByEmail` → `password_verify` → catat attempt →
  `Session::login` (regenerasi id) → redirect `/dashboard`

## Screens / Pages

| Screen | Route | Entry file | Related capability |
| ------ | ----- | ---------- | ------------------ |
| Sign in | `/login` | `views/auth/login.php` (layout `views/layout/auth.php`) | AUTH-CAP-001 |

## Dependencies

- **Other modules**: users, platform
- **External**: —

## Test Coverage

- `AuthService` — unit (`tests/Unit/Service/AuthServiceTest.php`)
- Alur session dan guard — integration (`tests/Integration/AuthFlowTest.php`)
- `LoginAttempt` repository — integration (`RepositoryCoverageTest`)

## Known Gaps / Risks

- **Halaman profil sendiri belum dibuat** (Q1, terkonfirmasi celah). Brief §1.2 memberi ketiga role akses "profil sendiri"; aplikasi hanya menampilkan nama dan role di navigasi. Kandidat: `GET /profile` (semua role) untuk melihat nama, email, dan role, plus ganti password sendiri dengan verifikasi password lama.
- Tidak ada password reset (di luar scope); Admin yang mengatur ulang password lewat `users`.

## Change Log

- **2026-10-03**: Initial version generated from codebase survey.
