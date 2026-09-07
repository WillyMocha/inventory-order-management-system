<!--
SYNC IMPACT REPORT
Version change: [CONSTITUTION_VERSION] (unfilled scaffold) → 1.0.0
Bump rationale: initial ratification. First concrete fill of the Rudis scaffold; all
Core Principles and Governance rules newly defined.

Modified principles (scaffold token → ratified title):
- [PRINCIPLE_1_NAME] → I. Clean Architecture & Dependency Inversion (NON-NEGOTIABLE)
- [PRINCIPLE_2_NAME] → II. PHP Strict Mode & Static Analysis (NON-NEGOTIABLE)
- [PRINCIPLE_3_NAME] → III. Use-Case Unit Tests (NON-NEGOTIABLE)
- [PRINCIPLE_4_NAME] → IV. Backend-Enforced Authorization & Segregation of Duties
- [PRINCIPLE_5_NAME] → V. Transactional Stock Integrity
- (added) → VI. Security & Decision Auditability

Added sections:
- Technology Stack Constraints (was [SECTION_2_NAME])
- Development Workflow & Quality Gates (was [SECTION_3_NAME])

Removed sections: none.

Templates / artifacts:
- ✅ .rudis/memory/constitution.md — this file
- ✅ .rudis/templates/plan-template.md — Constitution Check gate enumerated
- ✅ .rudis/templates/tasks-template.md — use-case unit tests changed from OPTIONAL to
  REQUIRED per Principle III
- ✅ .rudis/templates/spec-template.md — reviewed; its only constitution reference
  (frontend FE-011) is profile-scoped, no change required
- ✅ .rudis/templates/checklist-template.md, agent-file-template.md — reviewed, no
  constitution-dependent content
- ✅ CLAUDE.md — all sections filled as runtime guidance deriving from this constitution,
  with an explicit defer-on-conflict note pointing here
- ⚠ README.md — does not exist yet; must reference this constitution when created
  (project spec §59 requires a README)

Deferred TODOs: none.
-->

# Inventory & Order Management System Constitution

## Core Principles

### I. Clean Architecture & Dependency Inversion (NON-NEGOTIABLE)

The codebase MUST be organized in strictly layered rings, with dependencies pointing
inward only:

```text
Controller → Service (use case) → Repository Interface ← Repository Implementation → PDO/MySQL
```

- Entities and use cases MUST NOT reference HTTP, session, PDO, or view concerns.
- Services MUST depend on repository *interfaces* (`app/Repository/Contract/`), never on
  a concrete implementation. Concrete bindings are wired only at the composition root.
- Controllers MUST contain no SQL, business rules, stock arithmetic, or transaction
  orchestration; they parse input, delegate to one use case, and render a response.
- Repositories MUST contain no business rules; they translate between persistence and
  entities.
- Every use case MUST be constructible with in-memory repository fakes and no database.

*Rationale*: dependency inversion is what makes use cases testable in isolation
(Principle III) and lets persistence change without touching business rules.

### II. PHP Strict Mode & Static Analysis (NON-NEGOTIABLE)

- Every PHP file MUST begin with `declare(strict_types=1);`.
- All parameters, return types, and class properties MUST carry explicit type
  declarations. Use of `mixed` requires an inline justification comment.
- PHPStan MUST run at level 5 or higher over `app/` with **zero** errors before merge.
- PSR-12 MUST be enforced via PHP_CodeSniffer.
- Any silenced diagnostic (`@phpstan-ignore`, error-suppression, a narrowing var
  annotation) MUST state why on the adjacent line.

*Rationale*: strict typing turns whole classes of coercion bugs — silent int/string
mixing in stock quantities and money — into hard failures at the boundary.

### III. Use-Case Unit Tests (NON-NEGOTIABLE)

- **Every use case MUST have at least one unit test.** A service class or public service
  method expressing a business operation is a use case; merging one without a unit test
  is a constitution violation, not a backlog item.
- Use-case tests MUST run against in-memory repository fakes — no database, no network,
  no filesystem, no `sleep()`.
- Each use case MUST have tests for both its success path and its rule-violation paths
  (rejected state transitions, insufficient stock, unauthorized actor).
- Tests MUST satisfy FIRST: Fast, Independent, Repeatable, Self-validating, Timely.
  Test-order dependence and shared mutable fixtures are prohibited.
- Integration tests are additionally REQUIRED for goods receipt, goods issue, and
  oversell protection, because those assert real transactional behavior.
- Project floor: at least 6 unit tests spanning at least 3 distinct business-logic areas,
  and at least 3 integration tests. This is a floor, never a target.

*Rationale*: use cases hold the rules that money and stock depend on, and they are the
cheapest layer to test — so there is no defensible reason to leave one uncovered.

### IV. Backend-Enforced Authorization & Segregation of Duties

- Authorization MUST be checked server-side on every protected action. Hiding a UI
  control is presentation, NEVER authorization.
- The role matrix (Admin / Sales / WarehouseStaff) MUST be enforced in code, and every
  role check MUST be covered by a test asserting the denial path.
- Segregation of duties is absolute: a Sales user MUST NOT approve any Sales Order,
  including one they created themselves.
- Deactivated users (`is_active = false`) MUST NOT authenticate or hold a valid session.

*Rationale*: the approval boundary is the system's only control against self-dealing; if
it is enforced anywhere but the backend, it is not enforced.

### V. Transactional Stock Integrity

- Every stock mutation MUST occur inside a database transaction and MUST write its
  Stock Ledger entry in that same transaction.
- Read-then-write stock paths MUST acquire a row lock (`SELECT ... FOR UPDATE`) before
  computing the new quantity.
- Stock MUST NOT go negative. Insufficient stock MUST roll back the whole operation and
  surface a domain exception — never a partial fulfillment.
- Transaction scope MUST stay minimal: no HTTP calls, file I/O, or user interaction
  inside a transaction.
- Concurrency protection MUST be demonstrated by an automated test, not asserted in prose.

*Rationale*: concurrent goods issue is this system's specified failure mode; row locking
plus an atomically written ledger is the only design that survives it.

### VI. Security & Decision Auditability

- Passwords MUST use `password_hash()` / `password_verify()`. Session IDs MUST be
  regenerated on login and destroyed on logout. Failed-login messages MUST NOT reveal
  whether the email or the password was wrong.
- All SQL MUST use PDO prepared statements. String-interpolated SQL is prohibited.
- All dynamic output MUST be escaped with `htmlspecialchars($value, ENT_QUOTES, 'UTF-8')`.
- Uploads MUST validate MIME type and size, and MUST be stored under a generated,
  non-predictable filename.
- `.env` MUST be git-ignored; no live credential may ever be committed.
- Records referenced by transactions MUST be soft-deactivated (`is_active = false`),
  never hard-deleted.
- Each architecturally significant decision MUST be recorded as an ADR under
  `docs/architecture/` in Context → Decision → Consequences form.

*Rationale*: these are the baseline controls for a system holding credentials and
inventory value; ADRs keep the reasoning reviewable after the authors have moved on.

## Technology Stack Constraints

- **PHP 8.4.x only.** Not 8.3 or older, not 8.5 or newer. `composer.json`, the Docker
  image, CI commands, and setup docs MUST all pin 8.4.x consistently.
- **Backend**: native PHP with OOP, Composer PSR-4 autoload, PDO. Frameworks (Laravel,
  Symfony, CodeIgniter, Slim), ORMs, DI-container libraries, and CRUD generators are
  PROHIBITED.
- **Frontend**: HTML5, custom CSS, vanilla JavaScript, Fetch API. React, Vue, Angular,
  jQuery, Bootstrap, Tailwind, any CSS framework, and admin templates are PROHIBITED.
- **Database**: MySQL 8+ with foreign keys, constraints, indexes, and transactions.
- **Runtime**: Docker + Docker Compose, with at minimum an app/web container and a mysql
  container. A clean `docker compose up` MUST bring up a working system.
- **Tooling**: PHPUnit, PHPStan (level 5 or higher), PHP_CodeSniffer (PSR-12).

Adding any dependency outside this list requires an ADR and an amendment to this section.

## Development Workflow & Quality Gates

Discovery-first: read and confirm understanding before changing code. Keep changes in
scope, state what is OUT OF SCOPE, and prefer the smallest viable change.

A change is mergeable only when ALL of the following hold:

1. `declare(strict_types=1);` present in every touched PHP file (Principle II).
2. `vendor/bin/phpstan analyse app --level=5` reports 0 errors (Principle II).
3. The PSR-12 check passes (Principle II).
4. Every use case added or modified has passing unit tests covering success **and**
   failure paths (Principle III).
5. `vendor/bin/phpunit` is fully green; no skipped test without a written reason.
6. No layer violation: no SQL in controllers, no business rules in repositories, no
   concrete repository referenced by a service (Principle I).
7. Every new or changed protected action has a server-side authorization check plus a
   denial test (Principle IV).
8. Any stock-touching change is transactional, row-locked, and ledger-writing
   (Principle V).
9. Architecturally significant decisions have an ADR (Principle VI).
10. Static-analysis and test evidence is stored under `docs/quality/` and
    `docs/testing/`.

Complexity MUST be justified. If a simpler design was rejected, record why in the plan's
Complexity Tracking table or in an ADR.

## Governance

This constitution supersedes all other development practices, conventions, and habits in
this repository. Where a template, tool default, or prior code pattern conflicts with it,
the constitution wins and the conflicting artifact MUST be corrected.

**Amendment procedure**

1. Propose the change with an explicit rationale and its migration impact on existing code.
2. Obtain project-owner approval before the amendment is written.
3. Apply the amendment to `.rudis/memory/constitution.md` only — never to
   `.rudis/templates/constitution-template.md`, which is the shared scaffold.
4. Propagate the change through `.rudis/templates/plan-template.md`, `spec-template.md`,
   `tasks-template.md`, `CLAUDE.md`, and `README.md`, recording the result in the Sync
   Impact Report at the top of this file.
5. Record a migration plan for any amendment that invalidates existing code.

**Versioning policy** (semantic):

- **MAJOR** — a principle is removed or redefined in a backward-incompatible way, or a
  governance rule changes such that previously compliant code becomes non-compliant.
- **MINOR** — a new principle or section is added, or existing guidance is materially
  expanded.
- **PATCH** — clarifications, wording, typos, and other non-semantic refinements.

**Compliance review**

- Every pull request MUST verify the ten quality gates above; a PR that cannot cite them
  is not reviewable.
- Principles marked NON-NEGOTIABLE admit no exception and no temporary waiver.
- Other deviations require an ADR recording the trade-off and, where the deviation is
  knowingly carried, an entry in the Tech Debt Register.
- Compliance is re-verified at each Rudis phase gate: `/rudis.plan` (Constitution Check
  before Phase 0 and again after Phase 1 design), `/rudis.tasks`, and `/rudis.implement`.
- `CLAUDE.md` carries runtime, day-to-day agent guidance; it MUST NOT contradict this
  document and MUST defer to it on conflict.

**Version**: 1.0.0 | **Ratified**: 2026-09-07 | **Last Amended**: 2026-09-07
