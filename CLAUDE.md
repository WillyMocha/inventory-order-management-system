# CLAUDE.md

Project context for AI agents (Claude Code reads this automatically).

> **The governing document is [`.rudis/memory/constitution.md`](.rudis/memory/constitution.md)
> (v1.0.0).** This file is day-to-day runtime guidance only. Where the two conflict, the
> constitution wins — see its Governance section. Principles I, II, and III are
> NON-NEGOTIABLE: no exceptions, no temporary waivers.

## Overview

Inventory & Order Management System — a web app for product/master-data management,
multi-warehouse stock, Purchase Orders with goods receipt, Sales Orders with an approval
gate and goods issue, a full Stock Ledger, role-based dashboards, and CSV reports. Stock
integrity under concurrent operations is a core requirement, not a nice-to-have.

Three roles: `Admin`, `Sales`, `WarehouseStaff`.

Full functional spec: `docs/inventory-order-management-spec.md`.

## Architecture

Strictly layered, dependencies pointing inward only (Constitution I):

```text
Controller → Service (use case) → Repository Interface ← Repository Implementation → PDO/MySQL
```

```text
app/
├── Controller/          # parse input, delegate to ONE use case, render. No SQL, no rules.
├── Service/             # use cases: business rules + transaction orchestration
├── Repository/
│   ├── Contract/        # interfaces — services depend on these only
│   ├── MySQL/           # production implementations (PDO)
│   └── InMemory/        # fakes for unit tests
├── Entity/
├── Exception/           # domain exceptions
└── Validation/
public/                  # index.php front controller, css/, js/, uploads/
views/ config/ database/ scripts/ tests/{Unit,Integration}/ docs/
```

Concrete repository bindings are wired **only** at the composition root. Every use case
must be constructible with `Repository/InMemory/` fakes and no database.

**Key flow**: Login → Master Data → PO → Goods Receipt → Stock → SO → Approval →
Goods Issue → Stock Ledger → Dashboard/Report.

**State machines** (enforced in services, not controllers):

- PO: `Draft → Ordered → PartiallyReceived → Received`; cancellable before receipt.
- SO: `Draft → PendingApproval → Approved → Fulfilled`; **not** cancellable after
  `Fulfilled`.

Route sketch is in spec §61.

## Conventions

- **PHP 8.4.x only.** Not 8.3 or older, not 8.5 or newer. Keep `composer.json`, the
  Dockerfile, CI, and docs pinned consistently.
- **`declare(strict_types=1);` at the top of every PHP file.** Explicit types on all
  parameters, return types, and properties. `mixed` needs an inline justification.
- Native PHP + OOP + Composer PSR-4 autoload + PDO. **No** Laravel/Symfony/CodeIgniter/
  Slim, **no** ORM, **no** DI-container library, **no** CRUD generator.
- Frontend: HTML5, custom CSS, vanilla JS, Fetch API. **No** React/Vue/Angular/jQuery/
  Bootstrap/Tailwind/CSS framework/admin template.
- PSR-12 formatting (enforced by PHP_CodeSniffer).
- Soft-deactivate (`is_active = false`) records referenced by transactions — never hard
  delete.
- Adding any dependency outside this list requires an ADR **and** a constitution
  amendment.

## Build, run, test

```bash
cp .env.example .env
docker compose up --build          # app/web + mysql containers
```

Database init and demo accounts: follow README (per spec §59). No absolute machine-
specific paths — a clean clone must come up with the commands above.

```bash
vendor/bin/phpunit                            # all tests — must be fully green
vendor/bin/phpunit --testsuite Unit           # unit only
vendor/bin/phpunit --testsuite Integration    # integration only
vendor/bin/phpstan analyse app --level=6      # must report 0 errors (constitution floor is 5)
vendor/bin/phpcs --standard=PSR12 app         # PSR-12
php scripts/check-low-stock.php               # scheduled job
```

Store static-analysis evidence in `docs/quality/` and test evidence in `docs/testing/`.

### Merge gates (Constitution: Development Workflow & Quality Gates)

A change is mergeable only when all ten hold:

1. `declare(strict_types=1);` in every touched PHP file
2. PHPStan level 6 → 0 errors (constitution floor is 5)
3. PSR-12 clean
4. Every use case added/modified has passing unit tests — success **and** failure paths
5. `vendor/bin/phpunit` green; no unexplained skips
6. No layer violation (no SQL in controllers, no rules in repositories, no concrete
   repository referenced by a service)
7. Every new/changed protected action has a server-side authz check **plus a denial test**
8. Stock-touching changes are transactional, row-locked, and ledger-writing
9. ADR written for architecturally significant decisions
10. Evidence stored under `docs/quality/` and `docs/testing/`

## Risky / sensitive areas

- **Stock mutation (highest risk).** Every mutation runs inside a transaction and writes
  its Stock Ledger entry in that *same* transaction. Read-then-write paths must take a
  row lock (`SELECT ... FOR UPDATE`) before computing the new quantity. Stock must never
  go negative — insufficient stock rolls the whole operation back with a domain
  exception, never a partial fulfillment. Keep transaction scope minimal: no HTTP, file
  I/O, or user interaction inside one. Concurrency protection must be proven by an
  automated test. See `docs/architecture/adr-002-stock-concurrency.md`.
- **Authorization / segregation of duties.** Checked server-side on *every* protected
  action. Hiding a UI button is presentation, never authorization. A `Sales` user must
  never approve a Sales Order — **including one they created themselves**. Deactivated
  users (`is_active = false`) can neither log in nor hold a valid session.
- **Auth & sessions.** `password_hash()` / `password_verify()`; regenerate the session ID
  on login, destroy on logout; failed-login messages must not reveal whether the email or
  the password was wrong.
- **SQL & output.** PDO prepared statements only — string-interpolated SQL is prohibited.
  Escape all dynamic output with `htmlspecialchars($value, ENT_QUOTES, 'UTF-8')`.
- **Uploads.** Validate MIME type and size; store under a generated, non-predictable
  filename (never the original name).
- **Secrets.** `.env` is git-ignored. Never commit a live credential.

## How agents should work here

- Discovery-first: read and confirm understanding before changing code.
- Keep changes in scope; state what is OUT OF SCOPE; verify end-to-end.
- Prefer the smallest viable change; ask for approval on the diff.
- **When you add or change a use case, write its unit test in the same change.** A
  service method without a unit test is a constitution violation, not a backlog item
  (Constitution III). Tests use `Repository/InMemory/` fakes — no DB, no network, no
  filesystem, no `sleep()`, no test-order dependence.
- Goods receipt, goods issue, and oversell protection additionally require integration
  tests.
- Justify complexity: if you rejected a simpler design, say why in the plan's Complexity
  Tracking table or in an ADR under `docs/architecture/`.

## Active Technologies

Feature `001-inventory-order-system` (see `specs/001-inventory-order-system/plan.md`):

- PHP 8.4.x, native, zero runtime dependencies (Composer for PSR-4 autoload + dev tools only)
- MySQL 8 via PDO, InnoDB (row locking), prepared statements, explicit transactions
- Hand-authored HTML/CSS + vanilla JS (progressive enhancement; works with JS off)
- Docker Compose: `app` + `mysql`
- Dev: PHPUnit, PHPStan level 6, PHP_CodeSniffer PSR-12

Details are in the plan; the constraints that govern them are in the constitution.

## Recent Changes

- **001-inventory-order-system** — spec, plan, research, data model, and contracts written.
  Design decisions worth knowing before touching code:
  - `TransactionManagerInterface` keeps transaction orchestration out of PDO's reach so all
    40 use-case methods stay unit-testable with in-memory fakes (ADR-002).
  - One `Authorization\Policy` object holds every access rule; the router refuses to boot if
    a route lacks an authorization annotation, so a forgotten one fails closed (ADR-003).
  - Goods issue is **all-or-nothing** — no `issued_quantity` on sales order lines.
  - Stock adjustment is Admin-only with a mandatory reason, recorded as its own
    `stock_adjustments` document so `stock_ledgers` stays exactly as the source spec defines it.
  - Multi-line stock operations lock rows in ascending `(product_id, warehouse_id)` order to
    make deadlock impossible.
