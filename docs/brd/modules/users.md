# Module: User Management (`users`)

**Back to**: [00-overview.md](../00-overview.md) · **Last Updated**: 2026-10-03

## Summary

Admin mengelola akun Sales, Warehouse Staff, dan Admin lain. Tidak ada registrasi publik.

## Capabilities

- **USERS-CAP-001** — Membuat user dengan role Admin, Sales, atau WarehouseStaff (email unik)
- **USERS-CAP-002** — Mengubah data user
- **USERS-CAP-003** — Mengaktifkan dan menonaktifkan user (Admin tidak dapat menonaktifkan dirinya sendiri)
- **USERS-CAP-004** — Mengganti password user dengan step-up re-auth (Admin memasukkan ulang password miliknya)
- **USERS-CAP-005** — Mencari dan memfilter user (nama/email, role), dengan pagination

## Key Entities & Rules

- **USERS-ENT-001 User** — name, email (unik), password_hash, role, is_active, timestamps (`user`,
  `app/Entity/User.php`)
- **USERS-ENT-002 Role** — enum `Admin | Sales | WarehouseStaff` (`app/Entity/Enum/Role.php`)
- Rule: user tidak pernah dihapus, hanya dinonaktifkan; user nonaktif tidak dapat login

## API Surface

**Exposes** (seluruhnya Admin saja; mutasi memakai CSRF)

| Kind | Name / Route | Role | Entry file |
| ---- | ------------ | ---- | ---------- |
| HTTP | `GET /users`, `GET /users/create`, `GET /users/{id}/edit` | list, form | [`app/Controller/UserController.php`](../../../app/Controller/UserController.php) |
| HTTP | `POST /users`, `POST /users/{id}` | create, update | idem |
| HTTP | `POST /users/{id}/toggle-active` | aktif/nonaktif | idem |
| HTTP | `POST /users/{id}/password` | ganti password (step-up) | idem |

**Consumes**

- `auth` — `AuthService::verifyPasswordFor` untuk step-up re-auth
- `platform` — `Request::queryState`, `Paginator`, `Validator`

## Data Flow

- Ganti password: Admin → form + password miliknya → `verifyPasswordFor` → `UserService::changePassword`
  → `password_hash` → simpan

## Screens / Pages

| Screen | Route | Entry file | Related capability |
| ------ | ----- | ---------- | ------------------ |
| User list | `/users` | `views/users/index.php` | USERS-CAP-005 |
| User form | `/users/create`, `/users/{id}/edit` | `views/users/form.php` | USERS-CAP-001/002/004 |

## Dependencies

- **Other modules**: auth, platform

## Test Coverage

- `UserService` — unit (`tests/Unit/Service/UserServiceTest.php`); `requireUser` hanya lookup tanpa test langsung
- Akses Sales/Warehouse ke route user — integration (`tests/Integration/UserAccessTest.php`)

## Known Gaps / Risks

- —

## Change Log

- **2026-10-03**: Initial version generated from codebase survey.
