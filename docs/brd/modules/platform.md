# Module: Platform (`platform`)

**Back to**: [00-overview.md](../00-overview.md) · **Last Updated**: 2026-10-03

## Summary

Plumbing tanpa business rule: front controller, router, guard authorization, session, CSRF,
koneksi DB dan transaction, view, migration, wiring dependency, dan runtime Docker.

## Capabilities

- **PLAT-CAP-001** — Merutekan request ke controller dan menegakkan role per route (deny by default; 401/403/404)
- **PLAT-CAP-002** — Menjalankan transaction, dengan SAVEPOINT untuk transaction bersarang
- **PLAT-CAP-003** — Merender view dengan escaping `View::e()` dan halaman error 400/403/404/429/500 tanpa stack trace
- **PLAT-CAP-004** — Menerapkan migration dan seed secara idempotent (otomatis saat container start, atau manual)
- **PLAT-CAP-005** — Membentuk state filter dan sort dari query string untuk pagination (FIND-01)
- **PLAT-CAP-006** — Menghasilkan ulang seed demo dari riwayat ledger (dev tool)

## Key Entities & Rules

- **PLAT-ENT-001 Route** — method, path, controller, action, roles (`config/routes.php`); `null` = publik, `[]` = tak seorang pun
- **PLAT-ENT-002 SchemaMigration** — filename (PK), applied_at (`schema_migration`)
- Rule: `ATTR_EMULATE_PREPARES = false`; `MYSQL_ATTR_FOUND_ROWS` aktif agar compare-and-set menghitung baris yang cocok
- Rule: request `/api/*` selalu dibalas JSON, termasuk saat error

## API Surface

**Exposes**

| Kind | Name / Route | Role | Entry file |
| ---- | ------------ | ---- | ---------- |
| HTTP | front controller | semua request | [`public/index.php`](../../../public/index.php) |
| HTTP | `GET /_health` | publik | `app/Controller/HealthController.php` |
| PHP | `Router`, `Authorization`, `Session`, `Csrf`, `Request`, `Response`, `View`, `Paginator`, `Validator`, `Money` | — | `app/Support/` |
| PHP | `Database` (`TransactionRunner`), `ClockInterface`/`SystemClock` | — | `app/Support/Database.php` |
| CLI | `composer db:migrate` / `db:reset` / `db:test` / `check` | dev/operator | [`composer.json`](../../../composer.json), [`database/migrate.php`](../../../database/migrate.php) |
| CLI | `php database/generate-seed.php` | dev | [`database/generate-seed.php`](../../../database/generate-seed.php) |
| Container | entrypoint `ioms-entrypoint` → migrate → `apache2-foreground` | Docker | [`docker/entrypoint.sh`](../../../docker/entrypoint.sh), [`Dockerfile`](../../../Dockerfile), [`compose.yaml`](../../../compose.yaml) |

**Consumes**

- MySQL 8 (PDO), filesystem `storage/uploads`, environment variable (`config/app.php` membaca `.env` sebagai fallback)

## Data Flow

- Request: `public/index.php` → `Router::match` → `Authorization::authorizeRoute` → controller dari `config/container.php` → `Response` (HTML/JSON/CSV/redirect)
- Start container: healthcheck MySQL (TCP) → `ioms-entrypoint` → `migrate.php` (hanya file baru) → Apache

## Dependencies

- **Other modules**: — (dipakai seluruh modul)
- **External**: Apache 2.4 (`php:8.4-apache`), MySQL 8.0, Docker Compose

## Test Coverage

- `Request::queryState/sortCriteria` — unit (`RequestTest`); `Paginator` — unit (`PaginatorTest`); envelope error API — unit (`ApiErrorEnvelopeTest`)
- Guard dan session — integration (`AuthFlowTest`, `ApprovalAuthorizationTest`, `UserAccessTest`)
- `Database::transaction` + SAVEPOINT — integration (`NestedTransactionTest`)
- Repository SQL 116/116 method — integration (`RepositoryCoverageTest` dkk.)
- Migration otomatis dan prosedur Docker — diverifikasi manual dari salinan bersih (`implementation-log.md`), tanpa test otomatis
- `generate-seed.php` — diverifikasi output identik byte demi byte dan pengaman exit 1; tanpa test otomatis

## Known Gaps / Risks

- Tidak ada CI; gate dijalankan manual lewat `composer check` (di luar scope brief §4.3, TD-2).
- Sort key tak dikenal ikut terbawa di link pagination (known-bugs KB-3, harmless).

## Change Log

- **2026-10-03**: Initial version generated from codebase survey.
