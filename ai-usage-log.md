# AI Usage Log

Required by the project brief §58. Four obligations apply to every entry:

| Obligation | Meaning |
| ---------- | ------- |
| **DISCLOSE** | Every use of AI is recorded here, including ones that produced nothing usable. |
| **REVIEW** | A human read the output before it was kept. |
| **VERIFY** | The output was checked against the brief, the constitution, or a live tool query — not against the model's recollection. |
| **TEST** | Anything that runs has a test, or the reason it does not is stated. |

## Entry format

```markdown
## Entry NNN

Date:
YYYY-MM-DD

Tool:
<tool and model>

Purpose:
<what was being attempted>

Prompt summary:
<what was asked, not the verbatim prompt>

Output used:
<what was kept>

Output rejected:
<what was discarded, and why — this field is the useful one>

Verification:
<how it was checked>
```

---

## Entry 001

Date:
2026-09-07

Tool:
Claude Code (Opus 5), driven through the Rudis spec-driven workflow

Purpose:
Produce the project's governing documents before any code: constitution, feature
specification, implementation plan, data model, API contracts, and task breakdown.

Prompt summary:
Asked for a constitution enforcing clean architecture, PHP strict mode and a mandatory
unit test per use case; then to derive a specification from the project brief
(`docs/inventory-order-management-spec.md`), then a plan, then a task list.

Output used:
`.rudis/memory/constitution.md` (v1.0.0, 6 principles), and under
`specs/001-inventory-order-system/`: `spec.md` (67 functional requirements, 14
non-functional, 15 success criteria, 10 user stories), `plan.md`, `research.md` (9
recorded decisions), `data-model.md` (14 tables), `contracts/` (route table + OpenAPI),
`tasks.md` (164 tasks).

Output rejected:
- An initial mapping that added a `reason` column to `StockLedger` for stock adjustments.
  Rejected because it invented an attribute the brief's §23 does not define. Replaced with
  a separate `stock_adjustments` document table, which keeps `stock_ledgers` exactly
  conformant to the brief and gives the brief's mandatory `reference_id` a real referent.
- A claim that the design had "27 use-case methods". The actual count in the plan's own
  service tree is 40. Corrected — the figure drives how much testing is owed.
- Two ambiguities the AI initially resolved on its own were escalated to the project owner
  instead, because the brief is genuinely silent and no default was defensible: whether
  partial goods issue is permitted, and whether stock adjustment is in scope. Both were
  decided by a human (all-or-nothing issue; Admin-only adjustment) and are recorded in
  `spec.md` as A-010 and A-011.

Verification:
Cross-checked every requirement against the brief section by section. Ran a mechanical
consistency pass (`/rudis.analyze`) that verified: all 67 functional requirements cited by
at least one task, all 14 non-functional requirements mapped, task IDs contiguous with no
duplicates, no file-path collisions among parallel tasks, no forward task references, and
every `FR-###` cross-reference across all seven artifacts resolving to a real requirement.
That pass found 13 defects — including 17 stale requirement references and a table the
tasks created but the data model never documented — which were then fixed.

---

## Entry 002

Date:
2026-09-07

Tool:
Claude Code (Opus 5)

Purpose:
Implement Phase 1 (project setup, T001–T012): directory tree, Composer manifest, Docker
image and compose file, ignore files, the three quality-gate configs, the environment
loader and PDO factory, the front-controller skeleton, README and this log.

Prompt summary:
Asked to execute Phase 1 of `tasks.md` only.

Output used:
All twelve Phase 1 tasks, plus `.dockerignore` (required once a Dockerfile exists) and
`config/env.php` (the brief's own backlog item PROJ-005, which the task list had folded
into T009's description without giving it a file).

Output rejected:
- The task list's pinned dependency versions (`phpunit ^11`, `php_codesniffer ^3`). A live
  Packagist query showed current majors are PHPUnit 13 and CodeSniffer 4, and that
  PHPUnit 13.3.2 requires PHP >= 8.4.1 — which fits and reinforces the 8.4 version lock.
  Upgraded to `^13.3` and `^4.0`.
- A first draft of `public/index.php` that conditionally dispatched if `config/routes.php`
  existed. Rejected as dead-code-by-design: it would have looked like working routing
  while doing nothing. Replaced with an explicit `503` that states which tasks implement
  routing.

Verification:
Dependency status checked by querying Packagist's API directly — not from recollection —
confirming none of the three dev dependencies is abandoned. PHP 8.4's support window
checked against endoflife.date: active support to 2026-12-31, security to 2028-12-31, so
not end-of-life. Configuration files verified by actually running `composer install`,
`phpunit`, `phpstan` and `phpcs` rather than by inspection.

Test:
Phase 1 creates no business logic, so it carries no unit tests — its verification is that
the four tools execute successfully against the configuration. The first behavioural tests
arrive with the Policy object in T027.
