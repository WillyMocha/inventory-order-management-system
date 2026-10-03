---
description: Execute the implementation plan by processing and executing all tasks defined in tasks.md
---

## User Input

```text
$ARGUMENTS
```

You **MUST** consider the user input before proceeding (if not empty).

## Interactive Clarification (ask when blocked or unsure)

When you cannot resolve a decision confidently from tasks.md, plan.md, the code, or project conventions, ask the user instead of guessing or looping:

- **Ask when** the decision is high-impact or hard to reverse (architecture fit, data-model / schema strategy, breaking API changes, security, deleting or overwriting existing files), a required spec/file/input is missing, or the phase self-fix loop (step 8) has been exhausted on a blocking error.
- **How**: pause and ask ONE specific question at a time. State your recommended option with a one-line reason, then give 2–4 concrete choices plus a short free-form alternative; the user may accept the recommendation by replying "yes" (same style as `/rudis.clarify`).
- **Proceed without asking** when the choice is low-impact and reversible or has a clear conventional default — pick the sensible default, record the assumption in `implementation-log.md`, and continue. Do not interrogate the user over trivia.
- **Bounded (no wait-loop)**: ask at most twice per decision. If still unresolved, apply your recommended default, log it as an assumption for review, and move on — never block indefinitely.
- **Non-interactive sessions** (CI, `rudis run`, headless): never block on a question — apply the documented default, flag the assumption prominently in the final report, and continue.

## Outline

1. Run `.rudis/scripts/powershell/check-prerequisites.ps1 -Json -RequireTasks -IncludeTasks` from repo root and parse FEATURE_DIR and AVAILABLE_DOCS list. All paths must be absolute. For single quotes in args like "I'm Groot", use escape syntax: e.g 'I'\''m Groot' (or double-quote if possible: "I'm Groot").

   **Path resolution rules (STRICT)**:
   - Resolve the repo root ONCE at the start (`git rev-parse --show-toplevel` or the script output) and derive every file path from it as an absolute path. Never pass relative paths to file tools or scripts.
   - If a file or directory referenced by tasks.md/plan.md cannot be found, do NOT abort the whole run: report the unresolved path with a clear message (expected path, what referenced it, closest existing match), skip only the affected task(s), and continue with the rest.
   - When a task must create a file in a directory that does not exist yet, create the directory first — a missing folder is not an error.

2. **Check checklists status** (if FEATURE_DIR/checklists/ exists):
   - Scan all checklist files in the checklists/ directory
   - For each checklist, count:
     - Total items: All lines matching `- [ ]` or `- [X]` or `- [x]`
     - Completed items: Lines matching `- [X]` or `- [x]`
     - Incomplete items: Lines matching `- [ ]`
   - Create a status table:

     ```text
     | Checklist | Total | Completed | Incomplete | Status |
     |-----------|-------|-----------|------------|--------|
     | ux.md     | 12    | 12        | 0          | ✓ PASS |
     | test.md   | 8     | 5         | 3          | ✗ FAIL |
     | security.md | 6   | 6         | 0          | ✓ PASS |
     ```

   - Calculate overall status:
     - **PASS**: All checklists have 0 incomplete items
     - **FAIL**: One or more checklists have incomplete items

   - **If any checklist is incomplete**:
     - Display the table with incomplete item counts
     - **STOP** and ask: "Some checklists are incomplete. Do you want to proceed with implementation anyway? (yes/no)"
     - Wait for user response before continuing
     - If user says "no" or "wait" or "stop", halt execution
     - If user says "yes" or "proceed" or "continue", proceed to step 3

   - **If all checklists are complete**:
     - Display the table showing all checklists passed
     - Automatically proceed to step 3

3. Load and analyze the implementation context:
   - **REQUIRED**: Read tasks.md for the complete task list and execution plan
   - **REQUIRED**: Read plan.md for tech stack, architecture, and file structure
   - **REQUIRED**: Honor the plan's **Architecture Type** and **Integration Target**. If plan.md lacks these fields, detect them now: read the agent context file (`CLAUDE.md`/`AGENTS.md`), architecture docs (`docs/architecture*`, `ARCHITECTURE.md`, ADRs), and build config (module federation / micro-frontend setup, monorepo layout). Generated code MUST fit the detected architecture — e.g., a micro-frontend remote wired into the host shell, never a standalone app when the project is federated. If architecture is genuinely undeterminable, STOP and ask the user before generating frontend/backend scaffolding.
   - **REQUIRED for any task touching UI**: Honor the plan's **Existing Design System** field. If plan.md lacks it (older plan, or the plan step was skipped), detect it now the same way: sample existing pages/components for the component library actually in use, the theme/token source, and the icon set. Build every new page/component with that existing library/tokens/icons — never introduce a second, unrelated design system alongside what the repo already has. Only fall back to a domain profile's own reference design system when the field says "None found — greenfield frontend" (a genuinely new frontend with nothing to match yet).
   - **IF EXISTS**: Read data-model.md for entities and relationships — the database schema follows data-model.md, which mirrors the source resource model; it deviates from that shape ONLY where data-model.md explicitly records a user-approved, lossless mapping
   - **IF EXISTS**: Read contracts/ for API specifications and test requirements
   - **IF EXISTS**: Read research.md for technical decisions and constraints
   - **IF EXISTS**: Read quickstart.md for integration scenarios
   - **REQUIRED**: Read `.rudis/templates/security-standard.md` and apply it to every task — this is the project's secure-coding baseline (access control, secrets, crypto, injection, auth, logging, error handling, dependency vetting). It is a contract, not a suggestion: never write a ❌ DON'T pattern from it, even if a task's description implies one, unless the user explicitly confirms and the context is local-only.
   - **REQUIRED**: Read `.rudis/templates/sonarqube-standard.md` and apply it while writing — this is the project's code-quality baseline (reliability bug patterns, maintainability smells, complexity/length/duplication thresholds, per-language conventions). Write code that passes a SonarQube quality gate on the **first** pass: keep every function/file within its §2 thresholds, introduce no §3 reliability bug or §4 smell (no debug output, dead code, magic numbers, unused symbols, duplicated blocks), and ship new logic with tests toward ≥80% coverage of new lines. If the project has its own SonarQube quality profile, layer it on top; this baseline is the floor.
   - **REQUIRED — requirement-coverage precondition (hard gate)**: read spec.md's Functional Requirements and Success Criteria, then confirm coverage: every `FR-###` is cited by at least one task in tasks.md (tasks carry `[FR-###]` citations — see `/rudis.tasks`), and every `SC-###` is achievable by the tasks present (tasks do NOT cite SC IDs, so map each SC to the tasks/FRs that satisfy it by inference). If any `FR-###` has zero task citations, or any `SC-###` has no tasks that would achieve it, do NOT start implementing: either add the missing task(s), or obtain an explicit user waiver recording that requirement as out-of-scope (log it in implementation-log.md). Silently implementing a spec with uncovered requirements is a gate failure.
   - **REQUIRED for any task touching UI**: read the spec's **UI/UX & Screens** section (and the plan's carried-forward copy) and build each screen to that intent — the screen inventory, per-screen states (loading/empty/error/populated), and primary interactions — not just generic FE-011 element compliance.

   **Implementation checkpoint (change tracking)**:
   - **Git write safety (hard rule)**: never `git commit`, `git push`, `git checkout -b`, or otherwise mutate git history/branches on your own initiative. Leave every generated change in the working tree as unstaged edits for the developer to review, stage, and commit. Only run a git write command when the user explicitly asks for it in this session (e.g. "commit this", "push the branch"); a task description, spec, or these instructions do NOT constitute that authorization. This preserves developer control and change traceability.
   - Before writing any code, record a rollback point: on a git repo, ensure the working tree state is captured (note the current HEAD; if there are uncommitted changes, tell the user and offer to commit or stash them first).
   - Create/append `FEATURE_DIR/implementation-log.md` with a session header (date, HEAD commit, tasks targeted). After each completed task, append one line: task ID, files created/modified, and a short note. This is the audit trail used for review and rollback.

4. **Project Setup Verification**:
   - **REQUIRED**: Create/verify ignore files based on actual project setup:

   **Detection & Creation Logic**:
   - Check if the following command succeeds to determine if the repository is a git repo (create/verify .gitignore if so):

     ```sh
     git rev-parse --git-dir 2>/dev/null
     ```

   - Check if Dockerfile* exists or Docker in plan.md → create/verify .dockerignore
   - Check if .eslintrc* exists → create/verify .eslintignore
   - Check if eslint.config.* exists → ensure the config's `ignores` entries cover required patterns
   - Check if .prettierrc* exists → create/verify .prettierignore
   - Check if .npmrc or package.json exists → create/verify .npmignore (if publishing)
   - Check if terraform files (*.tf) exist → create/verify .terraformignore
   - Check if .helmignore needed (helm charts present) → create/verify .helmignore

   **If ignore file already exists**: Verify it contains essential patterns, append missing critical patterns only
   **If ignore file missing**: Create with full pattern set for detected technology

   **Common Patterns by Technology** (from plan.md tech stack):
   - **Node.js/JavaScript/TypeScript**: `node_modules/`, `dist/`, `build/`, `*.log`, `.env*`
   - **Python**: `__pycache__/`, `*.pyc`, `.venv/`, `venv/`, `dist/`, `*.egg-info/`
   - **Java**: `target/`, `*.class`, `*.jar`, `.gradle/`, `build/`
   - **C#/.NET**: `bin/`, `obj/`, `*.user`, `*.suo`, `packages/`
   - **Go**: `*.exe`, `*.test`, `vendor/`, `*.out`
   - **Ruby**: `.bundle/`, `log/`, `tmp/`, `*.gem`, `vendor/bundle/`
   - **PHP**: `vendor/`, `*.log`, `*.cache`, `*.env`
   - **Rust**: `target/`, `debug/`, `release/`, `*.rs.bk`, `*.rlib`, `*.prof*`, `.idea/`, `*.log`, `.env*`
   - **Kotlin**: `build/`, `out/`, `.gradle/`, `.idea/`, `*.class`, `*.jar`, `*.iml`, `*.log`, `.env*`
   - **C++**: `build/`, `bin/`, `obj/`, `out/`, `*.o`, `*.so`, `*.a`, `*.exe`, `*.dll`, `.idea/`, `*.log`, `.env*`
   - **C**: `build/`, `bin/`, `obj/`, `out/`, `*.o`, `*.a`, `*.so`, `*.exe`, `Makefile`, `config.log`, `.idea/`, `*.log`, `.env*`
   - **Swift**: `.build/`, `DerivedData/`, `*.swiftpm/`, `Packages/`
   - **R**: `.Rproj.user/`, `.Rhistory`, `.RData`, `.Ruserdata`, `*.Rproj`, `packrat/`, `renv/`
   - **Universal**: `.DS_Store`, `Thumbs.db`, `*.tmp`, `*.swp`, `.vscode/`, `.idea/`

   **Tool-Specific Patterns**:
   - **Docker**: `node_modules/`, `.git/`, `Dockerfile*`, `.dockerignore`, `*.log*`, `.env*`, `coverage/`
   - **ESLint**: `node_modules/`, `dist/`, `build/`, `coverage/`, `*.min.js`
   - **Prettier**: `node_modules/`, `dist/`, `build/`, `coverage/`, `package-lock.json`, `yarn.lock`, `pnpm-lock.yaml`
   - **Terraform**: `.terraform/`, `*.tfstate*`, `*.tfvars`, `.terraform.lock.hcl`
   - **Kubernetes/k8s**: `*.secret.yaml`, `secrets/`, `.kube/`, `kubeconfig*`, `*.key`, `*.crt`

5. Parse tasks.md structure and extract:
   - **Task phases**: Setup, Tests, Core, Integration, Polish
   - **Task dependencies**: Sequential vs parallel execution rules
   - **Task details**: ID, description, file paths, parallel markers [P]
   - **Execution flow**: Order and dependency requirements

6. Execute implementation following the task plan:
   - **Phase-by-phase execution**: Complete each phase before moving to the next
   - **Respect dependencies**: Run sequential tasks in order, parallel tasks [P] can run together  
   - **Follow TDD approach**: Execute test tasks before their corresponding implementation tasks
   - **File-based coordination**: Tasks affecting the same files must run sequentially
   - **Validation checkpoints**: Verify each phase completion before proceeding

7. Implementation execution rules:
   - **Setup first**: Initialize project structure, dependencies, configuration
   - **Tests before code**: If you need to write tests for contracts, entities, and integration scenarios
   - **Core development**: Implement models, services, CLI commands, endpoints
   - **Integration work**: Database connections, middleware, logging, external services
   - **Polish and validation**: Unit tests, performance optimization, documentation
   - **Mimicry Principle (match existing patterns, don't reinvent)**: in a codebase that already has code, do NOT write a task's implementation from a blank-slate description — first find the nearest existing example of the same kind of thing (an existing controller, service, model, migration, test, error handler, logger call, auth guard) and **mimic its structure, naming, imports, and conventions**. New code should look like it was written by whoever wrote the surrounding code: same error-handling shape, same logging style, same validation approach, same file/module layout. This produces code that fits the project (and passes the field-parity/architecture gates below) far more reliably than inventing a fresh style. Deviate from an existing pattern only when the task explicitly requires it, and say so when you do. On a genuinely greenfield project with no example to copy, follow plan.md and the constitution instead.

8. **Phase verification gate** (run after EVERY phase, before moving on — this prevents error chains):
   - Run the project's cheapest available checks in this order: type check / compile (e.g., `tsc --noEmit`, `mvn compile`), then lint, then build, then the tests relevant to the phase.
   - **Keep gate output compact** (token thrift): this gate runs after every phase, so do NOT paste full build/lint/test logs into the run. Collapse passing/framework noise to counts; keep verbatim only the failing messages, assertions, and stack traces needed to diagnose and fix (same output-compression rule as `/rudis.test`).
   - **Self-fix loop**: if a check fails, fix the error and re-run — up to 3 attempts per distinct error. Actually fix errors; never just report a recommendation and move on. If an error survives 3 attempts, record it in implementation-log.md as UNRESOLVED with your best diagnosis and continue only if later phases don't depend on it; otherwise stop and report.
   - **Source-conformance check** (the "matches the PDF" gate — run whenever a phase touches an entity, migration, DTO, contract, or FE type): compare the generated artifacts back to the source resource model via the digest under `FEATURE_DIR/inputs/` and `data-model.md`'s conformance checklist. Verify: every resource → table, every sub-resource → child table, every attribute → column (exact name/type/cardinality), every enum/state type present with exactly the source's values kept per resource — **nothing missing, renamed, invented, or simplified**. Any merge/flatten must be a deviation explicitly recorded in `data-model.md` and backed by a lossless mapper; an **unrecorded deviation is a gate failure**. This is cheap (compare against the text digest, not the raw PDF) and catches drift early, before it compounds into a rebuild.
   - **BE/FE/DB field-parity check** (run whenever a phase touches an entity, DTO, mapper, contract, or FE type/form): build ONE field inventory per resource from `contracts/` — the TMF wire contract is the source of truth for names, types, casing, and required/optional — then reconcile all three layers against it:
     - **API DTO ↔ contract**: every field name, type, casing (`camelCase`, `@type`/`@baseType`/`@schemaLocation`), and required/optional flag matches the contract exactly. A single mismatch (e.g. `unitCost` vs `costItemAmount`) causes cascading 400 errors — catch it here, not at runtime.
     - **DB ↔ contract via mapper**: by default the storage model in `data-model.md` mirrors the source, so it maps to the contract directly. Where `data-model.md` records an approved deviation (merged tables, flattened value objects, child tables), verify a mapper reassembles the full wire contract **losslessly** — every contract field produced, every column / child row consumed, nothing dropped, no orphan column. If a deviation merges sub-types with different lifecycles (e.g. `ActualCostItem` vs `ProjectedCostItem` states), verify state transitions are validated **per sub-type**, not against one combined enum.
     - **FE ↔ DTO**: `types.ts`, form fields, and the FormMapper use the same field names/types as the DTO; enumerations (state lifecycles) match, and per-subtype enums are not conflated.
     - On any mismatch, apply the self-fix loop above. A field present in one layer but unaccounted for in another is a **gate failure**, not a warning.
   - **BE→FE operation-coverage check** (run whenever a phase touches a controller, backend service method, or FE service/page — separate from the field-parity check above, which only verifies shared fields match, not that every operation exists on both sides): build ONE operation inventory per resource from the backend implementation (every exposed endpoint/controller method — CRUD plus custom actions like approve/cancel/export) and reconcile the frontend against it:
     - **FE service layer**: every backend operation has a corresponding function in the FE service file (`service/*.ts`), using the same route, method, and parameters as the backend controller.
     - **FE UI trigger**: every FE service function is actually invoked from a page/component (button, menu item, form submit, row action) — a service function nobody calls is as much a gap as a missing one.
     - An operation intentionally not exposed in the UI (e.g. an internal/system-only endpoint) is not a gap **only if** `data-model.md` or the spec records it as out-of-scope for FE; otherwise treat it as missing and implement it.
     - On any mismatch, apply the self-fix loop above. A backend operation with no FE service function, or an FE service function with no UI trigger, is a **gate failure**, not a warning.
   - **Visual composition check** (run whenever a phase adds or edits a page component. A domain profile, if active, defines FE-011 in its frontend constitution and may exceed this; **when no profile is active, this checklist IS the standard** — apply it against the project's own or greenfield design tokens, so a no-profile project still comes out designed, not basic). This is two passes, not one — the checklist alone is not sufficient:
     - **Pass A — element checklist**: for every list/overview/dashboard-landing page touched in this phase, confirm it is not a bare, flat composition — specifically: a stat/KPI summary row is present above the main table/grid; the empty state is icon/illustration + heading + helper text + primary CTA (never bare "No data" text); the loading state is a shaped skeleton, not a bare spinner, whenever the final layout is known; icons for the same status/entity/nav concept match what earlier pages in this feature already used; and the page has at least one tint/gradient/accent visual anchor instead of being built entirely from flat white/plain surfaces. Also confirm it uses the **Existing Design System** detected in Step 3, not a second/unrelated component library.
       **Beyond "not bare" — the polish bar** (what separates a page that looks *designed* from a merely functional one; enforce it especially when no profile is guiding the look, since that is where output defaults to basic): each KPI/stat tile carries a tinted icon chip + numeral + label + a trend/delta indicator or a small inline sparkline — a plain label+number tile is a fail; status/severity renders as a colored semantic chip (status dot + label) with a leading entity icon per row, never plain text; exactly one area per page spends the brand accent (header, featured stat, or primary CTA) with the rest kept neutral; grouped content sits on cards with deliberate soft elevation and a consistent radius, not flat borderless boxes; spacing follows one scale and columns of figures use tabular numerals. Every color resolves to a design token — never a hardcoded hex.
     - **Pass B — actually look at it rendered**: a checklist can pass while the page still looks bad (cramped spacing, clashing colors, misaligned grid, awkward proportions) — code-only review cannot catch that. Start the project's dev server and open each page touched in this phase in a browser (screenshot it), for at least one representative viewport. If the frontend is a Module-Federation remote that can't render standalone, render it through its host shell (or a Storybook/harness that provides `common_remote`) rather than expecting the remote's own dev server to show the page — a remote that 404s or renders blank on its own is a rendering-setup gap to fix, not a reason to skip Pass B or to mark it passed. Judge it holistically: spacing/whitespace rhythm, visual hierarchy, alignment/grid consistency, color harmony and contrast, and overall polish relative to sibling pages already in the app — not just "does it have a stat card". Then run this concrete **"offside" checklist** on each screenshot — any single failure is a gate failure:
       1. **No overflow/clipping**: no content spills outside its container, no unintended horizontal scrollbar, no text truncated or overlapping.
       2. **Alignment**: elements sit on a consistent grid — no control that's off-center or a few pixels out of line with its row/column, no ragged card edges.
       3. **Spacing scale**: gaps/padding follow one consistent scale (design-system spacing tokens) — nothing cramped, nothing with a randomly huge gap.
       4. **Color from tokens**: every color traces to a design token — no clashing/off-palette hue, no stray hardcoded color; status/entity colors match sibling pages.
       5. **Responsive**: re-check at one narrower viewport (e.g. tablet width) — the layout reflows without breaking, clipping, or overlapping.
       If something reads as visually rough even though Pass A passed, that is still a **gate failure**; fix it via the self-fix loop before moving on.
     - **Non-interactive/headless sessions** (CI, `rudis run`, no browser tooling available): the rendered Pass B cannot run — say so explicitly in the phase report ("visual rendering not verified — no browser available in this session") instead of silently skipping it or implying it was checked, and never claim Pass B passed without having actually rendered the page. BUT still enforce what can be checked statically: if the active profile ships a static FE-011 linter (e.g. its `validation/fe011-lint.*`), run it over the pages touched this phase and treat any ERROR finding as a gate failure (fix via the self-fix loop). This keeps visual-composition and design-token rules enforced even when no human is watching.
     - Treat a page that's functionally correct but visually bare or rough (default library styling, no summary context, plain-text empty state, or a rendered result that just looks off) as a **gate failure**, not a warning — apply the self-fix loop above to add the missing element(s)/fix the composition before marking the phase complete.
   - **Security conformance check** (run whenever a phase touches an endpoint, auth, data access, crypto, file handling, external HTTP calls, or a new/updated dependency — run `.rudis/templates/security-standard.md` §14's self-review gate against the code just written): walk each pattern flagged in the standard's category sections (§2–§12) and confirm none was introduced; walk §14's checklist and confirm every applicable "yes." Map any finding to severity per §1 (BLOCKER — Critical/High severity: e.g. hardcoded secret, missing authz check, unparameterized query, weak crypto, silent catch on a security-relevant path — blocks the phase; MAJOR — Medium severity: gets fixed in the same pass or an explicit user waiver logged in implementation-log.md; MINOR/INFO — Low/informational: logged and may proceed). A new dependency added this phase must pass §4's checks before it's used — treat an unvetted dependency as a gate failure. **Deprecation/discontinuation/EOL is verified by actually running §4 step 2's tool query (`npm view <pkg> deprecated`, `pip-audit`, `osv-scanner` on the lockfile, endoflife.date for the runtime) and reading its output — never from the model's own recollection, which a knowledge cutoff makes unreliable for packages abandoned after that date; a positive hit (deprecated / yanked / archived / EOL) is a BLOCKER unless a user waiver is logged.** On the project's **first** implementation phase, run that same query once across the *inherited* lockfile (scaffold/boilerplate/profile dependencies), not only over packages added this phase — an obsolete pinned dependency shipped by the boilerplate is the usual reason code passes this local gate yet fails an external security scan. If a tool/network lookup can't run in this session, mark the affected dependencies UNVERIFIED in the phase report and treat as MAJOR — do not claim they were checked. Apply the self-fix loop above to any BLOCKER/MAJOR finding before marking the phase complete.
   - Never declare a phase complete while its verification gate fails.

9. Progress tracking and error handling:
   - Report progress after each completed task
   - Halt execution if any non-parallel task fails — but a *path resolution* failure follows the rules in step 1 (skip and continue, don't abort the run)
   - For parallel tasks [P], continue with successful tasks, report failed ones
   - Provide clear error messages with context for debugging
   - Suggest next steps if implementation cannot proceed
   - **IMPORTANT** For completed tasks, make sure to mark the task off as [X] in the tasks file.

10. Completion validation:
    - Verify all required tasks are completed
    - **Verify per requirement, with evidence**: iterate every `FR-###` and `SC-###` from spec.md and confirm the implementation satisfies it, citing the concrete file / endpoint / test that fulfills each — not a blanket "matches the spec". List any FR/SC that is unmet or only partially met as an explicit gap. Re-confirm the spec's Assumptions still hold, and flag any that were invalidated during implementation.
    - Validate that tests pass and coverage meets requirements
    - Confirm the implementation follows the technical plan (including Architecture Type/Integration Target) and the constitution
    - Report final status with summary of completed work, including:
      - Changed-files summary (from implementation-log.md) so the user can see exactly what this run touched
      - Any UNRESOLVED errors with diagnosis
      - Rollback instructions: the checkpoint HEAD recorded in step 3 and the command to revert (e.g., `git diff <checkpoint>..HEAD`, `git checkout <checkpoint> -- <path>` for a single file, or `git reset --hard <checkpoint>` for everything — warn that reset discards all changes)

Note: This command assumes a complete task breakdown exists in tasks.md. If tasks are incomplete or missing, suggest running `/rudis.tasks` first to regenerate the task list.
