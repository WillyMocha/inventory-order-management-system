<!--
SYNC IMPACT REPORT
==================
Version change: 1.0.0 → 1.1.0
Rationale: MINOR. A "Language conventions" block was added to the Development Workflow &
Quality Gates section, materially expanding guidance without removing or redefining any
principle. No principle text changed.

Amendment 1.1.0 (2026-09-10) — Language conventions:
  - UI text MUST be English; documentation and code comments MUST be Indonesian;
    technical terms stay English inside Indonesian prose; code identifiers stay English.
  - Origin: stated by the project owner while reviewing spec assumption A-011 of
    specs/001-inventory-order-management/spec.md. That spec was updated in the same
    change (A-011 resolved, recorded as constraint C-007).

--- Initial ratification 1.0.0 (2026-09-10) ---
The live constitution was still the verbatim Rudis scaffold with all
[ALL_CAPS_IDENTIFIER] tokens unfilled, so 1.0.0 was a fresh fill rather than an
amendment. The first ratified version of a governance document is 1.0.0.

Principles defined in 1.0.0 (all new):
  - I.   Clean Architecture Through Layered Boundaries (NON-NEGOTIABLE)
  - II.  PHP Strict Mode & Explicit Typing (NON-NEGOTIABLE)
  - III. Every Use Case Has a Unit Test (NON-NEGOTIABLE)
  - IV.  Transactional Integrity & Concurrency Safety
  - V.   Server-Side Authorization & Segregation of Duties
  - VI.  Design Evidence & Refactoring Discipline
  - VII. Reproducible Environment (Docker-First)

Sections added:
  - Technology Constraints (fills the SECTION_2 scaffold slot)
  - Development Workflow & Quality Gates (fills the SECTION_3 scaffold slot)
  - Governance

Sections removed: none (scaffold principle slots 1-5 expanded to 7 principles)

Templates / dependent artifacts:
  ✅ .rudis/templates/plan-template.md — the "Constitution Check" gate resolves against
     this file at plan time; its placeholder is generic and remains correct. The
     "Complexity Tracking" table is the required home for any Principle I /
     over-engineering deviation.
  ✅ .rudis/templates/spec-template.md — no mandatory section is added or removed by
     this constitution; the existing UI/UX & Screens section already covers UI needs.
  ✅ .rudis/templates/tasks-template.md — existing testing and quality task categories
     already cover the unit, integration, and static-analysis task types required by
     Principles II, III, and the Quality Gates section.
  ✅ CLAUDE.md — agent guidance is consistent (discovery-first, smallest viable change,
     explicit out-of-scope) and is reinforced by Principle VI and Governance.
  ⚠ README.md — does not exist yet. It MUST be created during implementation and MUST
     carry the single test command, the Docker procedure, demo accounts, and known
     limitations required by the Development Workflow & Quality Gates section.

Deferred items / TODOs: none. RATIFICATION_DATE is set to the date of this fill.

Note on scope tension: the source Project Brief (§9 FAQ item 4) states that full
four-ring Clean Architecture is NOT required and that unjustified extra layers are
scored negatively. Principle I therefore realizes the "clean architecture" mandate
through the brief's pragmatic Controller/Service/Repository split with dependency
inversion at the repository boundary, rather than through additional rings.
-->

# Inventory & Order Management System Constitution

## Core Principles

### I. Clean Architecture Through Layered Boundaries (NON-NEGOTIABLE)

Business logic MUST NOT depend on infrastructure. Concretely:

- Three layers, one direction: Controller (HTTP, routing, request/response) → Service
  (business rules, use cases) → Repository (data access). Dependencies point inward
  only; a Repository MUST NEVER reference a Service or Controller.
- Every Repository consumed by a Service MUST be depended upon through an interface,
  never a concrete class. At least one Repository interface MUST have two working
  implementations: a real PDO/MySQL one, and an in-memory fake used by unit tests.
- Services receive every collaborator via constructor injection. A `new PDO(...)`, a
  `$_SESSION` read, a `$_POST` read, or any superglobal access inside a Service or an
  Entity is a violation.
- Entities hold domain state and domain rules only — no SQL, no HTTP, no rendering.

Deliberate boundary: this is the pragmatic three-layer realization of clean
architecture, NOT the four-ring form. Adding further layers, a DI container, an ORM,
or a pattern that solves no demonstrated problem is itself a violation of this
principle, and MUST be recorded in Complexity Tracking if proposed.

Rationale: the point of the boundary is that business rules stay testable and
explicable without a database. A layer that does not buy testability or clarity buys
nothing.

### II. PHP Strict Mode & Explicit Typing (NON-NEGOTIABLE)

- Every `.php` file in the project MUST begin with `declare(strict_types=1);` before
  any other statement. No exceptions — tests, scripts, and config loaders included.
- Every method and function MUST declare parameter types and a return type. `mixed` is
  permitted only where a narrower type is genuinely impossible, and each use MUST carry
  a one-line comment stating why.
- Every class property MUST be typed. Nullability MUST be explicit (`?T`), never
  implied by a default of `null`.
- Static analysis MUST run at PHPStan level 5 or higher (or the PHP_CodeSniffer PSR-12
  equivalent) with zero critical errors. Remaining warnings MUST be listed and
  explained in `docs/quality/`; silent suppression is a violation.

Rationale: strict types turn a whole class of silent coercion bugs — a quantity that
was a string, a null that became `0` — into immediate, local failures. In a system
whose core asset is a correct stock number, coercion is not a convenience.

### III. Every Use Case Has a Unit Test (NON-NEGOTIABLE)

- Every use case — every public Service method that executes a business rule — MUST
  have at least one unit test before that use case counts as done. A use case shipped
  without a unit test is an incomplete task, not a completed one.
- Unit tests MUST run against fake or in-memory repositories. They MUST NOT touch a
  real database, a session, the filesystem, the network, or the clock directly.
- Tests MUST assert behavior, not shape. Getter/setter and pure-delegation tests do not
  count toward this principle.
- Tests MUST obey FIRST: Fast, Independent, Repeatable, Self-validating, Timely. No
  `sleep()`, no execution-order dependence, no test that passes only by being skipped.
- Integration tests are a separate, additional obligation: at minimum three, running
  against real MySQL in Docker, covering at least goods receipt increasing stock
  end-to-end, and a second goods issue being refused when stock is already exhausted.
- Unit and integration suites live in `tests/Unit` and `tests/Integration`, and each
  MUST be runnable by a single documented command.

Rationale: coverage counted per use case, rather than as a global percentage, makes the
obligation unambiguous and reviewable — you can point at the method and ask for its test.

### IV. Transactional Integrity & Concurrency Safety

- Every multi-table stock operation (goods receipt, goods issue) MUST run inside one
  explicit transaction: `beginTransaction` / `commit` / `rollBack`. The `ProductStock`
  mutation and the corresponding `StockLedger` write either both land or neither does.
- Stock MUST NEVER be modified outside a Service that also writes the ledger. A direct
  UPDATE of stock from a controller, a script, or hand-run SQL is a violation, and
  `StockLedger` MUST always reconcile with `ProductStock`.
- Two near-simultaneous goods issues for the same product and warehouse MUST NOT
  oversell and MUST NOT produce a lost update. The chosen mechanism (locking strategy,
  conditional update, or equivalent) is a design decision that MUST be recorded in an
  ADR and demonstrated by a controlled, verifiable test scenario.
- All SQL MUST use PDO prepared statements. String concatenation of user input into a
  query is a violation without exception.

Rationale: an inventory system whose numbers cannot be defended under concurrency has
no product, only a UI.

### V. Server-Side Authorization & Segregation of Duties

- Authorization MUST be enforced on the server for every request, JSON API endpoints
  included. Hiding a control in the UI is presentation, never enforcement.
- Segregation of duties MUST be enforced in the authorization layer: a Sales user
  cannot approve a Sales Order, including one they created themselves. The approve
  endpoint remains available to Admin.
- Passwords MUST be stored using PHP's password hashing API. Session IDs MUST be
  regenerated on login. Secrets, credentials, `.env` files, and client/PII data MUST
  NEVER enter the repository or its history.
- User-supplied output MUST be escaped before rendering into HTML. Database exceptions
  and stack traces MUST NEVER reach the user; unauthenticated access redirects to
  login, unauthorized access returns 403, and missing data returns 404.

Rationale: these mirror the real controls of inventory and finance systems — one person
must not be able to run a transaction end-to-end alone.

### VI. Design Evidence & Refactoring Discipline

- A class diagram MUST exist before implementation (`docs/planning/`), and an as-built
  diagram at completion (`docs/architecture/`), the latter distinguishing dependencies
  on interfaces from dependencies on concrete classes. Both MUST match the actual code
  and be traceable class-by-class on demand.
- At least two Architecture Decision Records MUST exist in `docs/architecture/` in
  context / decision / consequences form, covering at minimum the repository
  abstraction choice and the Principle IV concurrency mechanism.
- A refactoring log (`docs/quality/refactor-log.md`) MUST carry at least three entries
  naming the smell, the technique applied, and before/after excerpts, plus one SRP
  audit note. At least one commit MUST be tagged `refactor:` and improve pre-existing
  code rather than the feature in progress.
- A tech-debt register (`docs/quality/tech-debt.md`) MUST record shortcuts honestly,
  each with its ideal fix. Undocumented debt is the violation; documented debt is not.

Rationale: the design is only real if it can be traced to the code and defended out
loud. Documents that drift from the code are worse than no documents.

### VII. Reproducible Environment (Docker-First)

- The application and its database MUST start from a clean checkout with
  `docker compose up --build`, providing at minimum an app/web service and a MySQL 8
  service.
- All configuration MUST come from environment variables, with every key present in
  `.env.example`. No absolute paths, no machine-specific setup, no step that exists
  only in one developer's shell history.
- Schema and seed MUST build the database from empty, including enough demo data to
  exercise pagination and every role: one Admin, two or more Sales, two or more
  Warehouse Staff, two or more warehouses, 30 products with varied reorder points, and
  25 or more combined PO/SO records spanning statuses.

Rationale: if it only runs here, it does not run.

## Technology Constraints

These constraints are absolute. A feature that works by using a prohibited technology
still fails the requirement.

| Area | Required | Permitted | Prohibited |
| --- | --- | --- | --- |
| Frontend | Semantic HTML, hand-written CSS, Vanilla JS | Fetch API, declared icon libraries | React, Vue, Angular, jQuery, CSS frameworks, prebuilt admin templates |
| Backend | PHP 8.2+ native OOP, layered, DIP at the repository boundary | Composer for autoload and dev dependencies | Laravel, CodeIgniter, Symfony, Slim, any ORM, CRUD generators, framework DI containers |
| Database | MySQL 8, relations, constraints, indexes, PDO prepared statements, explicit transactions | Hand-written migrations and seeds | NoSQL as the primary store, query concatenation of user input |
| Environment | Dockerfile, Docker Compose, `.env.example` | Apache or Nginx per design | Setups that run only on one machine |
| Testing | PHPUnit unit + integration suites, static analysis report | Additional test types | Trivial getter/setter tests, tests that pass only by being skipped |

Required layout — folder names MAY differ, but these responsibilities MUST stay
separate: `public/`, `app/Controller`, `app/Service`, `app/Repository`, `app/Entity`,
`views/`, `config/`, `database/`, `tests/Unit`, `tests/Integration`, and
`docs/planning`, `docs/architecture`, `docs/quality`, `docs/testing`.

Explicitly out of scope: microservices, real message queues, cloud deployment, CI/CD,
Kubernetes, real-time notifications, mobile applications, automatic server-side cron,
and automated end-to-end tests. Building these is scope creep, not extra credit.

Bonus work (simulated email notification, master-data audit trail, hand-built chart
dashboards, extra integration tests) MAY be attempted only after every mandatory
requirement is stable. Bonus work NEVER compensates for a missing mandatory requirement.

## Development Workflow & Quality Gates

Work proceeds in vertical slices — a slice reaching from route to database and back,
with its tests — rather than layer by layer. Polish follows working slices.

A change is done only when all of the following hold:

1. `declare(strict_types=1);` is present in every new or modified PHP file (Principle II).
2. Every new or modified use case has a passing unit test (Principle III).
3. The full unit and integration suites pass, and neither suite has skipped tests
   standing in for absent coverage.
4. Static analysis reports zero critical errors; any remaining warnings are explained in
   `docs/quality/`.
5. No secret, credential, `.env`, token, or PII has been added to the repository or its
   history.
6. Documentation affected by the change — class diagram, ADR, refactor log, tech-debt
   register, README — is updated in the same change, not later.

Language conventions:

- Every string a user sees — labels, buttons, headings, validation messages, error text,
  empty states, exported report headers — MUST be in English.
- Documentation and code comments MUST be in Indonesian. This covers `README.md`, ADRs,
  the refactoring log, the SRP audit note, the tech-debt register, the critique, test
  notes, planning documents, `ai-usage-log.md`, and every comment and docblock in code.
- Technical terms MUST stay in English inside Indonesian prose, untranslated, so that
  meaning stays unambiguous — for example Controller, Service, Repository, Entity,
  interface, dependency injection, constructor injection, transaction, race condition,
  goods receipt, goods issue, reorder point, StockLedger, unit test, integration test,
  static analysis, prepared statement.
- Code identifiers — class, method, property, variable, table, and column names — MUST be
  in English, since they are technical terms by nature. Commit messages MUST be in English.

Rationale: the product is read by English-speaking users while the team reasons, reviews,
and defends the design in Indonesian. Translating technical vocabulary is precisely where
that split would start producing ambiguity, so the vocabulary stays English.

Git and process:

- Commits are incremental and describe real changes. At least one commit MUST be tagged
  `refactor:` and improve pre-existing code.
- External snippets, packages, and assets MUST be attributed.
- AI assistance MUST be disclosed, reviewed, verified against requirements or
  documentation, and behavior-tested; each use is logged in `ai-usage-log.md` with the
  tool, purpose, sanitized prompt summary, output used or rejected, and verification
  evidence. Proprietary source, client data, and credentials MUST NEVER be sent to
  public AI services. Responsibility for every design decision remains with the author,
  who MUST be able to explain it unaided.
- `README.md` MUST document features, install steps, demo accounts, the Docker
  procedure, the single command for each test suite, and known limitations, and MUST be
  verified from a clean directory before any release tag.

Any of the following is a hard failure regardless of other progress: the app or database
not starting via Docker; a broken login/PO/SO/stock core flow; use of a prohibited
framework, ORM, or DI container; absent or wholly failing tests; plaintext passwords,
committed live secrets, raw query concatenation, or frontend-only authorization; stock
changed outside the service/ledger path; non-transactional goods issue/receipt that
permits reproducible oversell; a class diagram that does not match the code; or
concealed material use of AI or external sources.

## Governance

This constitution supersedes all other practices, conventions, and preferences in this
repository. Where this document and a habit, a tutorial, or an AI suggestion conflict,
this document wins.

Amendment procedure:

1. Amendments MUST be proposed as an explicit change to this file, stating the principle
   affected, the rationale, and the migration path for code that the change would render
   non-compliant.
2. The Sync Impact Report comment at the top of this file MUST be updated in the same
   change, and dependent artifacts (`.rudis/templates/*`, `CLAUDE.md`, `README.md`) MUST
   be reviewed and either updated or explicitly marked pending.
3. Amendments take effect on merge. Existing code that becomes non-compliant MUST either
   be brought into compliance in the same change, or recorded in
   `docs/quality/tech-debt.md` with a named remediation.

Versioning policy — semantic versioning applies to this document:

- MAJOR: a principle is removed or redefined in a backward-incompatible way, or
  governance itself changes incompatibly.
- MINOR: a principle or section is added, or existing guidance is materially expanded.
- PATCH: clarification, wording, or typo fixes that do not change what is required.

Compliance review:

- Every change is reviewed against the Development Workflow & Quality Gates checklist
  above. A reviewer MUST NOT approve a change that fails a gate without a recorded
  Complexity Tracking justification.
- Complexity MUST be justified: any added layer, pattern, or dependency beyond what
  Principle I and the Technology Constraints describe requires an entry in the plan's
  Complexity Tracking table, naming the concrete problem it solves and why the simpler
  option was insufficient. Unjustified complexity is treated as a defect of equal weight
  to disorganized code.
- `CLAUDE.md` carries runtime development guidance for AI agents working in this
  repository and MUST remain consistent with this constitution.

**Version**: 1.1.0 | **Ratified**: 2026-09-10 | **Last Amended**: 2026-09-10
