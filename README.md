# Inventory & Order Management System

> **Build status: Phase 1 (project setup) complete.** The container, tooling and quality
> gates are in place; no application behaviour is implemented yet. Sections marked
> _(pending)_ are filled as the phases that deliver them complete — see
> `specs/001-inventory-order-system/tasks.md` for the plan and T160 for this file's
> completion.

## 1. Project overview

A web application for managing products and master data, stock across multiple
warehouses, Purchase Orders with goods receipt, Sales Orders with an approval gate and
goods issue, a permanent Stock Ledger, role-based dashboards, and CSV reports.

Its central guarantee: **recorded stock and its movement history can never disagree, and
stock can never be oversold** — including when two fulfilments compete for the same units
at the same moment.

Governing documents:

- `.rudis/memory/constitution.md` — the non-negotiable rules (v1.0.0)
- `specs/001-inventory-order-system/spec.md` — what is built and why
- `specs/001-inventory-order-system/plan.md` — how it is built
- `docs/inventory-order-management-spec.md` — the original project brief

## 2. Features

_(pending — filled as user stories land; see `tasks.md` phases 3–12)_

Planned, in delivery order: role-based access · master data and multi-warehouse stock ·
Purchase Order and Goods Receipt · Sales Order with Admin approval and oversell-proof
Goods Issue · search/filter/sort/pagination · three role dashboards · CSV exports ·
availability JSON API · low-stock CLI check · Admin stock adjustment.

## 3. Technology stack

| Layer | Choice | Note |
| ----- | ------ | ---- |
| Language | **PHP 8.4.x only** | Hard version lock. Not 8.3 or older, not 8.5 or newer. |
| Backend | Native PHP, OOP, PDO | **No framework, no ORM, no DI container, no CRUD generator.** |
| Frontend | HTML5, hand-authored CSS, vanilla JS | **No React/Vue/Angular/jQuery, no CSS framework, no admin template.** |
| Database | MySQL 8+ (InnoDB) | InnoDB is required for the row locking the oversell guarantee depends on. |
| Runtime | Docker + Docker Compose | `app` + `mysql` containers. |
| Testing | PHPUnit 13 | Unit (no database) and Integration (real MySQL). |
| Static analysis | PHPStan level 6 | Constitution floor is 5; this project runs at 6. |
| Style | PHP_CodeSniffer, PSR-12 | Plus `strict_types` in every file. |

Runtime Composer dependencies: **none.** Composer provides PSR-4 autoloading and the
three dev tools above, nothing more.

## 4. Architecture overview

Strictly layered, dependencies pointing inward only:

```text
Controller → Service (use case) → Repository Interface ← Repository Implementation → PDO/MySQL
```

- Controllers parse input, call **one** use case, and render. No SQL, no business rules.
- Services hold business rules, state transitions and transaction orchestration.
- Services depend on repository **interfaces** only; concrete bindings are wired at the
  composition root (`config/container.php`).
- `app/Repository/InMemory/` exists so every use case can be unit-tested with no
  database. That is not test scaffolding — it is the reason the boundary exists.

Decisions are recorded in `docs/architecture/` (ADR-001 … ADR-006) _(pending — T153)_.

## 5. Requirements

- Docker Engine 24+ with the Compose plugin (`docker compose`)
- Nothing else. No local PHP, MySQL or Composer is needed.

## 6. Installation

```bash
git clone <repo-url>
cd inventory-order-management-system
cp .env.example .env      # then replace every CHANGE_ME value
```

`.env` is git-ignored and must stay that way — no credential belongs in this repository.

## 7. Docker startup

```bash
docker compose up --build
```

The application is then at **http://localhost:8080** (change `APP_PORT` in `.env` to use
a different port). The `app` container waits for the database's healthcheck, so a first
run cannot race into a connection error.

## 8. Database initialisation

_(pending — the schema and seed files arrive in T013 and T043)_

```bash
docker compose exec app composer install
docker compose exec -T mysql mysql -uroot -p"$MYSQL_ROOT_PASSWORD" ioms < database/schema.sql
docker compose exec -T mysql mysql -uroot -p"$MYSQL_ROOT_PASSWORD" ioms < database/seed.sql
```

## 9. Demo accounts

_(pending — seeded by T043)_

## 10. Run unit tests

```bash
docker compose exec app vendor/bin/phpunit --testsuite Unit
```

No database, no network, no filesystem, no `sleep()` — unit tests run against in-memory
repository fakes.

## 11. Run integration tests

```bash
docker compose exec app vendor/bin/phpunit --testsuite Integration
```

These use the real MySQL container and will drop and recreate `TEST_DB_DATABASE`. Point
that at a throwaway database.

## 12. Run static analysis

```bash
docker compose exec app vendor/bin/phpstan analyse app --level=6   # must report 0 errors
docker compose exec app vendor/bin/phpcs                           # PSR-12
```

Or everything that runs without a database: `composer check`.

## 13. Run the scheduled script

_(pending — T133)_

```bash
docker compose exec app php scripts/check-low-stock.php
```

## 14. Known limitations

- **No MFA for Admin accounts.** Recommended by the project's security standard for
  high-privilege accounts; excluded by scope (brief §66). Recorded as accepted risk.
- **No breached-password check.** Requires an external service, which an offline
  container does not have.
- **Sign-in rate-limit counters live in the application database**, so they are not
  shared across multiple app containers. Fine at the single-node scope that ships.
- **CSV export streams rows** but a very wide date range is still a long request.

These and any others are tracked in `docs/quality/tech-debt.md` _(pending — T150)_.

## 15. Project layout

```text
app/          Controller · Service · Repository{Contract,MySQL,InMemory} · Entity · Authorization
public/       index.php front controller, css/, js/, img/, uploads/
views/        plain PHP templates
config/       env.php · database.php · container.php · routes.php
database/     schema.sql · seed.sql
scripts/      check-low-stock.php
tests/        Unit/ (no DB) · Integration/ (real MySQL)
docs/         planning · architecture (ADRs) · quality · testing
specs/        the specification, plan and task list this project is built from
```

## AI usage

This project was developed with AI assistance. Every use is disclosed in
`ai-usage-log.md`, per the brief's DISCLOSE / REVIEW / VERIFY / TEST obligations.
