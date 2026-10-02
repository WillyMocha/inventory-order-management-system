# Phase 0 Research: Inventory & Order Management System

**Feature**: `001-inventory-order-management`
**Date**: 2026-09-10
**Input**: [spec.md](./spec.md) · [constitution v1.1.0](../../.rudis/memory/constitution.md) ·
[resource model digest](./inputs/project-brief-resource-model.md)

Every decision below is recorded as **Decision / Rationale / Alternatives considered**. The
governing constraint throughout is spec C-003: build no more than the brief describes.
Unjustified layers, patterns and dependencies are scored negatively, so "the simpler thing
that satisfies the requirement" wins every tie.

---

## R-001: Runtime and container image

**Decision**: PHP **8.4** exactly, via the official `php:8.4-apache` image, pinned to a
specific patch digest in the Dockerfile. Apache with `mod_rewrite` fronts a single front
controller. Two Compose services only: `app` and `db`.

**Rationale**: The user pinned 8.4 as a hard boundary (spec C-001), so the image tag carries
the version rather than a floating `8` or `latest`. `php:8.4-apache` puts the web server and
runtime in one container, which is the smallest arrangement that satisfies "app service +
MySQL service" — an `nginx` + `php-fpm` split would add a third service and a second config
surface to maintain for no requirement the brief states. A runtime guard in the front
controller fails fast with a clear message if the interpreter is not 8.4.x, so a wrong
version is caught immediately rather than as puzzling behavior later.

**Alternatives considered**:
- *nginx + php-fpm*: the conventional production split, rejected as an extra service and
  config file with no demonstrated benefit at this scope (C-003).
- *`php:8.4-cli` with the built-in server*: rejected — the built-in server is explicitly not
  for anything but development and would make the Docker demonstration unconvincing.
- *Floating `php:8` tag*: rejected — it would silently drift to 8.5 and violate C-001.

---

## R-002: Concurrency control for goods issue (ARCH-02)

**Decision**: **Pessimistic row locking with `SELECT ... FOR UPDATE`** inside an explicit
transaction. The goods-issue service opens a transaction, locks the `product_stock` row for
the (product, warehouse) pair, re-reads the quantity under that lock, verifies sufficiency,
inserts the `stock_ledger` row, updates `product_stock`, and commits. A second concurrent
request blocks at the `FOR UPDATE` until the first commits, then reads the true remaining
quantity and is refused if it no longer suffices.

Lock ordering: when an order has several lines, rows are locked in ascending `product_id`,
then ascending `warehouse_id` order, so two multi-line issues can never deadlock by taking
the same two rows in opposite orders.

**Rationale**: Chosen by the project owner from three options. It requires **no additional
column**, so the schema keeps mirroring the brief's data model exactly (no deviation to
justify). It is the mechanism whose failure scenario is easiest to narrate in a technical
defense: "request two waits here, and by the time it reads, the stock is gone." The read →
verify → write-ledger → write-stock sequence is naturally serialized, which matters because
the ledger row and the stock update must agree.

**Alternatives considered**:
- *Conditional `UPDATE ... WHERE quantity >= :qty`*: correct and marginally faster, rejected
  because the "zero affected rows means someone else took it" reasoning is subtler to defend,
  and ordering the ledger insert around it is more delicate.
- *Optimistic version column with retry*: rejected on two grounds — it adds a `version`
  column absent from the brief's model (an unnecessary source deviation) and retry logic the
  brief never asks for, which reads as over-engineering under C-003.

**Isolation level**: MySQL 8 default `REPEATABLE READ` is kept. `FOR UPDATE` takes the same
lock regardless, and changing the global isolation level would be an unexplained deviation.

**How it is proved** (TEST-02, NFR-001): an integration test seeds one unit of stock, opens
two real database connections, drives the first through the lock and holds it, confirms the
second blocks, then commits the first and confirms the second is refused with stock at zero
and never negative. No thread simulation is needed, which the brief explicitly permits.

---

## R-003: Layering, routing and dependency inversion

**Decision**: A single front controller at `public/index.php` dispatching through a flat
route table to Controller → Service → Repository. Services receive their collaborators by
constructor injection, wired by hand in a single `config/container.php` factory file — a
plain function returning built objects, not a container library. Repository interfaces live
beside their consumers in `app/Repository`, with `Mysql*Repository` and `InMemory*Repository`
implementations.

**Rationale**: ARCH-01 requires the three-layer split and dependency inversion at the
repository boundary; the brief's FAQ says explicitly that manual constructor injection is
sufficient and a DI container is not required. A hand-written wiring file makes the object
graph readable in one place, which is exactly what a class-diagram trace during the defense
needs. Constitution Principle I additionally forbids adding a container as unjustified
complexity.

**Alternatives considered**:
- *PHP-DI or Symfony DI*: prohibited by the brief's technology table and by constitution
  C-002; rejected outright.
- *Service locator*: rejected — it hides dependencies and would undermine the very testability
  ARCH-01 is checking for.
- *Attribute-based auto-wiring written by hand*: rejected as reinventing a container.

**Interfaces with two implementations**: every repository gets an interface, since the fake
implementations are what make Principle III's per-use-case unit tests possible without a
database. The brief requires at minimum one such pair; satisfying it for all of them costs
nothing extra and is what the testing principle demands anyway.

---

## R-004: Templating, escaping and the frontend

**Decision**: Plain PHP templates under `views/`, rendered by a small `View` renderer that
extracts data into a scoped include. All output passes through a single `e()` helper wrapping
`htmlspecialchars` with `ENT_QUOTES | ENT_SUBSTITUTE` and UTF-8. Frontend behavior is written
as ES modules in vanilla JavaScript, loaded with `<script type="module">`, no build step.

**Rationale**: The brief prohibits frontend frameworks and requires the participant's own CSS.
A template engine (Twig, Blade) is not prohibited outright but adds a dependency and a
compilation cache for no stated requirement, and would make the "hand-written" claim weaker.
No build step means the Docker demonstration has no `npm install` to fail.

**Alternatives considered**:
- *Twig*: rejected under C-003 — a dependency and a cache directory solving no stated problem.
- *A JS bundler (Vite, esbuild)*: rejected — adds a toolchain, a node dependency, and a build
  step to the Docker flow for a handful of progressive-enhancement scripts.

**Escaping discipline**: templates never echo a raw variable. The static analysis
configuration is set so that a raw `echo $var` in a template is visible in review, and the
`e()` helper is the only sanctioned output path.

---

## R-005: Authentication, session and access control

**Decision**: PHP native sessions with cookie parameters set explicitly —
`HttpOnly`, `SameSite=Lax`, `Secure` driven by an environment flag, and a non-default session
name. `session_regenerate_id(true)` runs immediately after a successful sign-in.
`password_hash()` with the default algorithm and `password_verify()` handle credentials, and
`password_needs_rehash()` is checked on sign-in.

Authorization is **deny by default**: every route entry declares the roles permitted to reach
it, and a central guard refuses anything not explicitly allowed. Ownership and
segregation-of-duties rules are enforced in the Service layer, where the acting user is
passed in as an argument rather than read from a superglobal — which is also what makes those
rules unit-testable per Principle I.

**Rationale**: Directly required by AUTH-01, AUTH-02, USR-01, §4.2 and security standard §2
("deny by default; every route has an explicit authorization check"). Passing the acting user
into the Service rather than letting it reach for `$_SESSION` is the single decision that lets
the segregation-of-duties rule be unit-tested without a session — the highest-value test in
the whole suite (SC-005).

**Alternatives considered**:
- *Reading the current user from a session singleton inside services*: rejected — it violates
  Principle I and would make the approval-rule test require a session.
- *JWT / token auth*: rejected — the application is server-rendered with a cookie session;
  tokens would add a mechanism the brief never asks for.

### Security standard §7 (insecure design) decisions, taken now rather than improvised

| Concern | Decision |
| --- | --- |
| Account enumeration | One uniform message — "Email or password is incorrect" — for a wrong email, a wrong password, and a deactivated account. The deactivated case is deliberately indistinguishable. |
| Sign-in rate limiting | Throttle per email **and** per IP: after 5 failures within 15 minutes, refuse further attempts for that pair for 15 minutes, counted in a small `login_attempt` table. Applies equally to a nonexistent email so timing does not leak. |
| Export rate limiting | CSV export is capped at one running export per session at a time, and the date range is bounded (see R-008) — export is the one endpoint that can be made expensive cheaply. |
| Re-authentication | An Admin changing another user's password must re-enter their own password. Sensitive-action step-up per §7. |
| Session fixation | ID regenerated on sign-in; session data cleared and cookie expired on sign-out. |
| Out-of-scope resource access | A Sales user requesting another user's order receives **404, not 403**, per security standard §2 — refusing with 403 would confirm the order exists. A role reaching a route it may never use at all still gets 403, since that leaks nothing. |
| CSRF | A per-session token is required on every state-changing form and on non-GET JSON calls, compared with `hash_equals`. Not named in the brief, but omitting it on an app with cookie sessions and approval endpoints would be a design defect; the cost is one helper and one hidden field. |
| Bulk / destructive actions | There are no bulk endpoints. Deactivation is per record and confirmed; nothing is hard-deleted. |
| Error disclosure | A global handler renders a safe page and logs the detail server-side; `display_errors` is off in the image. Required by ERR-01 and §7. |

---

## R-006: File upload (product image)

**Decision**: Accept JPEG, PNG and WebP only, at most 2 MB. The type is decided by
`finfo` inspection of the file's actual content and a re-check that the image is decodable —
never by the client-supplied name or MIME header. The stored filename is
`bin2hex(random_bytes(16))` plus an extension derived from the detected type. Files are
written outside the document root and served through a controller that sets an explicit
`Content-Type` and `Content-Disposition: inline`, so an uploaded file can never be executed
as a script.

**Rationale**: PRD-01 requires type and size validation and an unguessable name. Serving from
outside the web root is what actually neutralizes an upload-to-RCE attempt; a random name
alone only prevents guessing.

**Alternatives considered**:
- *Storing under `public/uploads/` with a random name*: rejected — a misconfigured Apache
  directive would make uploads executable; the random name would not save it.
- *Image re-encoding to strip payloads*: rejected as beyond the stated requirement, and it
  would pull in an image extension dependency (C-003).

---

## R-007: Database access, schema and migrations

**Decision**: PDO with `ERRMODE_EXCEPTION`, `FETCH_ASSOC`, and **`ATTR_EMULATE_PREPARES =
false`** so prepared statements are genuinely server-side. Character set `utf8mb4`. Schema
and seed are plain, ordered SQL files under `database/` (`001_schema.sql`,
`002_seed.sql`), applied by a small PHP runner script that records which files it has
applied.

Money is stored as `DECIMAL(15,2)` and quantities as `INT`. IDR is the only currency
(spec A-011); values are whole rupiah, and the two decimal places exist so that a
`DECIMAL` comparison never surprises — display always renders zero decimals.

**Rationale**: DB-01 requires prepared statements, constraints, indexes and explicit
transactions. Disabling emulation matters: with emulation on, PDO interpolates client-side and
the "prepared statement" defense is weaker than it appears. Plain SQL files keep the schema
readable and reviewable in the defense, and a migration library would be an unjustified
dependency at this scope.

**Alternatives considered**:
- *Phinx or Doctrine Migrations*: rejected under C-003 — a dependency for a schema that is
  written once.
- *`FLOAT`/`DOUBLE` for money*: rejected — binary floating point cannot represent currency
  exactly and would make totals disagree with the ledger.
- *Storing rupiah as integer minor units*: reasonable, rejected for a smaller reason — the
  brief's model says "harga" without units, and `DECIMAL` keeps the SQL reports readable
  without dividing by 100 everywhere.

**Indexes** (DB-01 asks the participant to explain one): `product.sku` unique;
`product_stock (product_id, warehouse_id)` unique — this is the row the goods-issue lock
targets, so the index is what makes the lock a single-row lock rather than a range scan;
`stock_ledger (product_id, warehouse_id, created_at)` for the ledger and report queries;
`sales_order (status, created_at)` and `purchase_order (status, created_at)` for the filtered,
sorted, paginated lists in FIND-01.

---

## R-008: Lists, pagination and reporting

**Decision**: Server-side pagination with `LIMIT`/`OFFSET` at ten rows per page, with filters,
sort and page carried in the query string so a filtered page is linkable and survives
navigation. Sort is restricted to an allowlist of column names mapped from safe keys — never
interpolated from user input. CSV export streams with `fputcsv` to `php://output` behind
`Content-Disposition: attachment`, and the export reuses the **same query builder methods** as
the dashboard so the two cannot drift (REPORT-01 requires them to agree). Export date range is
capped at 366 days.

**Rationale**: FIND-01 fixes ten rows per page and requires filters to survive paging; putting
state in the query string is the simplest thing that achieves it and makes the demonstration
trivially reproducible. Streaming avoids building a large string in memory.

**Alternatives considered**:
- *Keyset pagination*: better at scale, rejected — with 30 products and 25 orders it solves a
  problem this system does not have, and page numbers are what FIND-01 describes.
- *Client-side filtering of a full dataset*: rejected — it would not demonstrate pagination and
  scales badly.
- *A separate reporting query*: rejected — duplicated logic is exactly how a report drifts from
  its dashboard.

---

## R-009: JSON API surface

**Decision**: A small JSON surface under `/api/`, sharing the same session authentication and
the same authorization guard as the HTML routes, returning `application/json` with accurate
status codes and a uniform error envelope. Required endpoint: product availability per
warehouse. Two more are included because the frontend genuinely consumes them —
low-stock summary for the dashboard, and available stock for a product/warehouse pair used by
the Sales Order form as the user types.

**Rationale**: API-01 requires at least one JSON endpoint with correct 200/401/404 behavior and
no HTML error pages. The user's plan input permits more "if needed"; these two are needed by
screens the spec already describes, so they are not speculative. The application stays
server-rendered — HTML routes are not duplicated as REST resources, which would be a parallel
API surface with no consumer (C-003).

**Alternatives considered**:
- *A full REST API mirroring every action*: rejected explicitly — it doubles the surface,
  doubles the tests, and no requirement asks for it.
- *A separate token auth for `/api/`*: rejected — same session, same guard, less to explain.

---

## R-010: Testing strategy

**Decision**: PHPUnit 11.x (the current line supporting PHP 8.4), two suites declared in one
`phpunit.xml` — `Unit` and `Integration` — runnable separately or together.

- **Unit**: every Service use case, against `InMemory*Repository` fakes. No database, session,
  network, filesystem or real clock. Time is injected as a `ClockInterface` so date-dependent
  rules are deterministic. Target areas well beyond the brief's minimum of six tests across
  three areas, because Principle III requires one per use case.
- **Integration**: against real MySQL 8 in Docker, each test wrapped in a transaction rolled
  back at teardown so tests stay independent (FIRST). Covers at minimum: goods receipt
  increases stock end-to-end; a second goods issue is refused when the first exhausted stock;
  the two-connection lock scenario from R-002.

**Rationale**: Principle III makes per-use-case unit coverage the definition of done, and
TEST-01/02/03 set the floor. Injecting the clock is the one small abstraction that pays for
itself immediately — without it, any test touching order dates is either fragile or untestable.

**Alternatives considered**:
- *SQLite in memory for integration tests*: rejected — it would not exercise `FOR UPDATE` or
  InnoDB locking, which is the single most important thing to prove.
- *Mockery / Prophecy for repository doubles*: rejected — hand-written in-memory fakes are what
  ARCH-01 asks to see, they are reusable across tests, and they avoid a dependency.
- *A full test-database rebuild per test*: rejected as slow; transaction rollback keeps the
  suite fast (FIRST).

---

## R-011: Static analysis and code style

**Decision**: **PHPStan level 6** plus **PHP_CodeSniffer with the PSR-12 standard**. Both run
via Composer scripts and write their reports into `docs/quality/`. Zero critical errors is the
gate; any remaining warning is explained in writing in the same directory.

**Rationale**: The brief asks for level 5 or above and the constitution repeats it. Level 6
adds missing-iterable-type detection, which on a strict-typed greenfield codebase is
achievable without noise and demonstrably exceeds the floor. Running PSR-12 alongside costs
one dev dependency and settles style arguments mechanically.

**Fallback**: if level 6 produces findings that would need suppression rather than fixes, drop
to level 5 (still compliant) and record the reason in `docs/quality/` — suppressing to hold a
level would violate Principle II.

**Alternatives considered**:
- *Psalm*: comparable, rejected — the brief names PHPStan, and naming the tool it names avoids
  an explanation nobody needs.
- *Level 9 / max*: rejected — it would demand defensive annotations disproportionate to the
  scope and invite exactly the over-engineering C-003 warns about.

---

## R-012: Scheduled low-stock script (JOB-01)

**Decision**: `scripts/check-low-stock.php`, a standalone CLI entry point that boots the same
wiring file, resolves the same `ProductService` the dashboard uses, and prints a summary of
products at or below reorder point. Run manually with `docker compose exec`. No cron
installed in the image.

**Rationale**: JOB-01 requires separation from the web request cycle and explicitly does not
require real scheduling; §4.3 puts automatic scheduling out of scope. Reusing the same service
is the point — it proves the business logic is not tied to HTTP.

**Alternatives considered**:
- *A cron entry in the container*: rejected — out of scope per §4.3 and it complicates the
  image.
- *A duplicate query inside the script*: rejected — it would let the script and the dashboard
  disagree.

---

## R-013: Frontend design system (greenfield, nothing to inherit)

**Decision**: No existing frontend and no domain profile supplies one, so a minimal but real
token system is established up front in `public/assets/css/tokens.css` and used by every page.

| Token group | Values |
| --- | --- |
| Accent | `--accent: #2563eb` (blue 600), hover `#1d4ed8`, subtle wash `#eff6ff` |
| Neutrals (blue-biased, not pure grey) | `#f8fafc · #f1f5f9 · #e2e8f0 · #cbd5e1 · #94a3b8 · #64748b · #475569 · #334155 · #1e293b · #0f172a` |
| Status (kept distinct from accent) | success `#16a34a`, warning `#d97706`, danger `#dc2626`, info `#0891b2`, each with a tinted background for badges |
| Spacing scale | `4 · 8 · 12 · 16 · 24 · 32 · 48 · 64` px |
| Radius | `4px` controls, `8px` cards, `9999px` badges |
| Elevation | one soft level: `0 1px 3px rgb(15 23 42 / 0.08), 0 1px 2px rgb(15 23 42 / 0.04)` |
| Type scale | `12 · 14 · 16 · 20 · 24 · 32` px; system font stack; tabular numerals for every money and quantity column |
| Icons | Lucide, vendored locally as an SVG sprite and cited in the README |

Status colors map to domain meaning consistently: Draft neutral, PendingApproval warning,
Approved info, Fulfilled/Received success, Cancelled danger, low stock warning.

**Rationale**: The plan command requires a real design system rather than framework defaults
when there is nothing to inherit, and the brief scores UI usability (UI-01). Hue-biased
neutrals and tabular numerals are what keep a dense inventory table from looking like an
unstyled document. Tokens in one file also mean the whole palette can be defended as a
deliberate choice rather than accumulated inline styles.

**Icon licensing**: Lucide is ISC-licensed; vendoring the sprite locally keeps the Docker
environment offline-capable (no CDN), and the brief permits a cited icon library.

**Alternatives considered**:
- *A CSS framework (Bootstrap, Tailwind)*: prohibited by the brief and C-002.
- *An icon font*: rejected — heavier, worse at arbitrary sizes, and needs a CDN or a font file
  for glyphs an SVG sprite handles better.
- *Pure default styling*: rejected — the plan command warns this is exactly where generated
  UIs come out flat, and UI-01 is graded.

---

## R-014: Language conventions in code

**Decision**: Per spec C-007 and constitution v1.1.0 — UI strings in English, comments and
documentation in Indonesian, technical terms left untranslated in English, identifiers and
commit messages in English.

**Rationale**: Recorded here so it is unambiguous during implementation. Concretely: a docblock
reads `/** Memproses goods issue untuk Sales Order yang sudah Approved. */` — Indonesian prose,
English technical vocabulary, and the method still named `processGoodsIssue()`.

---

## Resolved NEEDS CLARIFICATION

None outstanding. The spec carried zero clarification markers, all twelve of its assumptions
were confirmed by the project owner on 2026-09-10, and the one genuinely open design decision
(R-002, the concurrency mechanism) was decided by the project owner during this planning
session.
