---
description: Discover existing tests, generate missing unit tests, run SonarQube-style code quality compliance checks, detect/report functional bugs, and persist a timestamped report under docs/test-reports/.
---

## User Input

```text
$ARGUMENTS
```

You **MUST** consider the user input before proceeding (if not empty).
If the user specifies a target (file, folder, module), scope all work to that target only.

## Goal

Ensure the project has adequate unit test coverage, passes SonarQube-style code quality checks, and surfaces
functional bugs. This command:

1. Discovers existing test infrastructure and frameworks
2. Identifies source files without corresponding tests
3. Generates missing unit tests following project conventions
4. Performs SonarQube-style static analysis (code smells, duplication, complexity, maintainability)
5. Scans for functional bugs (logic errors, not just style) and tries to prove each one with a failing test
6. Produces a structured report with findings, a dedicated bug report when bugs exist, and recommendations
7. Persists that report as its own timestamped file under `docs/test-reports/`, so runs can be compared over time

## Operating Constraints

- **Generate tests (and the report file) only** — do NOT modify production/source code without explicit user approval (see Remediation, step 6a). Writing test files and the persisted report under `docs/test-reports/` is not covered by that approval gate — those are this command's normal output, not production code.
- **Follow existing patterns** — match the project's test framework, naming conventions, file structure, and assertion style
- **No fabricated results** — only report issues actually found in the code
- **Minimal mocking** — prefer real implementations; mock only external dependencies (APIs, databases, filesystem)
- **No over-testing** — skip generated code, vendor files, config files, migration files, and type-only files
- **Compress noisy tool output, never lossy content** — when capturing build/test-run output, dedupe repeated pass/skip lines (e.g. "142 passed" instead of listing each) and collapse verbose framework banners. Never summarize, truncate, or dedupe a failing test's assertion message or stack trace, and never skim a source file instead of reading it fully in Step 4 or Step 5 — those are the basis for real decisions and must stay verbatim.

## Execution Steps

### 1. Initialize Context

Run `.rudis/scripts/powershell/check-prerequisites.ps1 -Json` from repo root and parse JSON for FEATURE_DIR and AVAILABLE_DOCS list. All paths must be absolute.

### 2. Discovery Phase

Detect test infrastructure.

**Test Frameworks** — look for configuration and dependencies:

- JavaScript/TypeScript: Jest, Vitest, Mocha, Playwright, Cypress (check package.json, vitest.config.*, jest.config.*, tsconfig.json)
- Python: pytest, unittest (check pyproject.toml, setup.cfg, conftest.py, tox.ini)
- Java/Kotlin: JUnit, TestNG (check pom.xml, build.gradle)
- Go: built-in testing (check *_test.go)
- Rust: built-in testing (check #[cfg(test)] blocks)
- .NET: xUnit, NUnit, MSTest (check *.csproj)

**Test Locations** — find where tests live:

- `tests/`, `test/`, `__tests__/`, `spec/`
- Co-located: `*.test.*`, `*.spec.*` alongside source
- `src/**/__tests__/`

**Test Run Command** — identify how to run tests:

- npm/yarn/pnpm scripts (test, test:unit, test:coverage)
- pytest / python -m pytest
- go test ./...
- cargo test
- dotnet test

Record:

- Framework name and version
- Test directory structure
- Naming convention (e.g., `*.test.ts`, `*_test.go`, `test_*.py`)
- Number of existing test files
- Available test .rudis/scripts/commands

### 3. Coverage Gap Analysis

Identify source files WITHOUT corresponding test files:

1. List all source files (excluding vendor, node_modules, dist, build, generated, migrations, types-only)
2. For each source file, check if a matching test file exists based on the project's naming convention
3. Classify untested files by priority:
   - **CRITICAL** — business logic, services, controllers, handlers, core domain
   - **HIGH** — utilities, helpers, data transformations, validators
   - **MEDIUM** — components (UI), hooks, middleware
   - **LOW** — constants, config, types, interfaces, models (data-only)

Output a gap summary table:

| Priority | Source File | Expected Test File | Status |
|----------|------------|--------------------|--------|
| CRITICAL | src/services/order.ts | src/services/order.test.ts | MISSING |
| HIGH | src/utils/format.ts | src/utils/format.test.ts | MISSING |

### 4. Unit Test Generation

For each missing test (ordered by priority, CRITICAL first):

1. Read the source file completely
2. Identify all exported functions, classes, methods
3. For each testable unit, generate tests covering:
   - **Happy path** — expected input produces expected output
   - **Edge cases** — empty input, null/undefined, boundary values, zero, negative numbers
   - **Error paths** — invalid input, thrown exceptions, error returns
   - **Type boundaries** — type coercion, optional parameters, default values

**Test file template** (adapt to project framework):

```text
// Follow the project's existing import style
// Group tests by function/method using describe blocks
// Use descriptive test names: "should [expected behavior] when [condition]"
// Arrange-Act-Assert pattern
// Minimal setup — no unnecessary mocking
```

Rules:

- One test file per source file
- Match the project's existing test structure and style exactly
- Use the same assertion library as existing tests
- Import from relative paths matching existing patterns
- Do NOT test private/internal functions unless the project convention does so
- Do NOT add dependencies not already in the project

### 5. SonarQube Compliance Analysis

Analyze all source files for the following quality dimensions. The thresholds in
§5.1–§5.4 are the canonical values from `.rudis/templates/sonarqube-standard.md` (§2) —
read that file as the source of truth (and to catch any project-specific profile
layered on top); the numbers restated below must stay aligned with it.

#### 5.0 Run the real scanner when the project is wired for SonarQube

The static checks in §5.1–§5.6 approximate the gate; the **only** way to prove
code will pass the project's actual SonarQube gate is to run its scanner against
its own quality profile. Before the static pass, detect whether this project is
already wired for SonarQube and, if so, prefer the real scan:

1. **Detect config** — look for `sonar-project.properties`, a `sonar.*` block in
   `pom.xml`/`build.gradle`, a `sonar` script in `package.json`, or a Sonar stage
   in `.gitlab-ci.yml`/`.github/workflows/*`. Record the `sonar.host.url` and
   project key if present.
2. **Detect a runner** — `sonar-scanner` on PATH, `mvn`/`gradle` with the Sonar
   plugin, or `npx sonar-scanner`.
3. **If both exist**: tell the user you can run the real analysis and ask ONCE
   for confirmation (the scan uploads to their server and needs a token — never
   hardcode or echo it; rely on the environment/CI variable the project already
   uses, e.g. `SONAR_TOKEN`). On yes, run it with the quality-gate wait enabled
   (`-Dsonar.qualitygate.wait=true` for scanner/Maven, or
   `sonar.qualitygate.wait=true`) so the command returns the **actual** gate
   PASS/FAIL, and record that result verbatim in the report's Quality Gate
   section. A real FAIL lists the exact failing conditions — treat each as a
   finding and route production-code fixes through Step 6a.
4. **If config or runner is missing, or the user declines**: state clearly in the
   report that the gate result is a **static approximation, not a verified scan**,
   then proceed with §5.1–§5.6. Do not claim the code "passes SonarQube" on the
   strength of the static pass alone — only a real scan (or the project's CI)
   proves that.

#### 5.1 Code Smells

- Functions/methods exceeding 40 lines
- Files exceeding 300 lines
- Deeply nested conditionals (> 3 levels)
- Functions with > 4 parameters
- Unused variables and imports
- Dead code (unreachable branches)
- Magic numbers and strings (should be constants)
- Console.log / print statements left in production code

#### 5.2 Duplication

- Repeated code blocks (> 6 similar lines across files)
- Copy-paste patterns in adjacent functions
- Duplicated logic that could be extracted to shared utilities

#### 5.3 Complexity

- Cyclomatic complexity > 10 per function
- Cognitive complexity > 15 per function
- Deeply nested callbacks or promise chains
- Complex boolean expressions (> 3 conditions)

#### 5.4 Maintainability

- Missing or misleading function/variable names
- Inconsistent naming conventions within the codebase
- Long parameter lists without destructuring or options objects
- Tight coupling between modules (circular or excessive imports)
- God objects / god functions (doing too many things)

#### 5.5 Security

Scan against `.rudis/templates/security-standard.md` — its ❌ DON'T patterns per category (access control, misconfiguration, supply chain, crypto, injection, insecure design, auth, integrity, logging, exception handling) and its §14 self-review checklist. At minimum, flag:

- Hardcoded credentials, tokens, or secrets
- SQL string concatenation or template literals (injection risk); unparameterized dynamic identifiers (sort/table names) with no allowlist
- Unsanitized user input in templates or `innerHTML`/`dangerouslySetInnerHTML` (XSS risk)
- Use of eval() or equivalent dynamic execution; shell string built from user input (command injection)
- Insecure randomness (`Math.random()`/`rand()`) for tokens, secrets, or salts
- Weak/deprecated crypto (MD5/SHA-1 for passwords, DES/3DES/RC4, ECB mode, bcrypt cost < 10)
- Missing authorization check, or `userId`/`tenantId` taken from client input instead of the verified session
- Silent `catch` that swallows a security-relevant error, or a stack trace leaked to the client
- A dependency added/updated this task that hasn't been checked per the standard's §4 (freshness, known CVEs, typosquatting, license)
- **A deprecated, discontinued, yanked, or archived dependency, or one targeting an EOL runtime.** Detect this by running §4 step 2's tool query against the project's lockfile — `npm view <pkg> deprecated` / `npm outdated`, `pip-audit`, `osv-scanner`, endoflife.date for the runtime — and reading the output; do NOT judge a package "current" from training-data memory (a knowledge cutoff misses anything abandoned after that date). Scan the *whole* lockfile, including inherited scaffold/boilerplate/profile dependencies, not just what this task touched — an obsolete inherited package is the common reason code passes local review yet fails an external gate. A confirmed deprecated/discontinued/EOL dependency is a **BLOCKER** (it will fail an external security/SonarQube gate). If the tool can't run in this session, report the dependencies as UNVERIFIED rather than clean.

#### 5.6 Bug Detection (Functional Correctness)

Distinct from 5.1-5.5 above: those are style/maintainability signals, this is about code that
produces **wrong behavior**. Scan for:

- Off-by-one errors in loops, slices, pagination, or array indexing
- Null/undefined access on a path that can genuinely be null/undefined at runtime
- Inverted or malformed boolean logic (wrong operator, swapped `&&`/`||`, broken De Morgan negation)
- Race conditions / unguarded shared-state mutation across concurrent or async paths
- Unhandled promise rejections, or async errors that are silently dropped
- Resource leaks (unclosed handles, connections, listeners, timers, subscriptions)
- Error swallowing (empty `catch`, or a `catch` that discards the real error/cause)
- Type-coercion bugs (loose equality across mismatched types, unintended truthy/falsy on `0`/`""`/`NaN`)
- Loops/recursion that can run one too many/few times, or never terminate
- Copy-paste bugs (a duplicated block that still references the original's variable/field)

For each candidate, in order:

1. State the concrete failure scenario: what input or state triggers it, and what the wrong
   outcome is (not just "this looks risky").
2. Try to write a minimal reproducing test (Prove-It pattern — it must FAIL against the
   current code).
3. If the test fails as expected -> mark it **CONFIRMED** and hand it to Step 6a's
   remediation flow (Production-code failures bucket), exactly like a bug caught during
   Step 6 verification.
4. If a reproducing test can't be written (missing infra, timing-dependent, needs manual
   verification) -> mark it **SUSPECTED**, state why it couldn't be confirmed, and still
   include it in the Bug Report.

Never label a candidate CONFIRMED without a failing test that proves it — that is the
fabrication line this command must not cross. Static reasoning alone only earns SUSPECTED.

Severity classification (applies to 5.1-5.6, including bugs):

- **BLOCKER** — Critical/High-severity security vulnerability (per `.rudis/templates/security-standard.md` §1), will fail SonarQube quality gate. A Medium/Low-severity security finding is MAJOR/MINOR instead, per that same mapping.
- **CRITICAL** — bug-prone pattern (non-security), high risk of runtime failure
- **MAJOR** — significant maintainability or readability issue
- **MINOR** — style inconsistency or minor improvement opportunity
- **INFO** — suggestion for improvement, not a violation

### 6. Verification

If a test run command was identified in Step 2:

1. Run the existing test suite to establish baseline (capture pass/fail count)
2. After generating new tests, run the full suite again to verify new tests pass
3. If any generated test fails, fix it immediately — never leave broken tests

If no test command is available, skip this step and note it in the report.

### 6a. Remediation (do not leave errors hanging)

Failures fall into two buckets — handle both to completion. This bucket now has two sources:
failures from running the (existing + generated) suite in Step 6, and bugs marked **CONFIRMED**
in Step 5.6 by a reproducing test.

- **Test-side failures** (wrong assertion, bad mock, wrong import in a generated test):
  fix the test yourself immediately and re-run, up to 3 attempts per distinct error.
- **Production-code failures** (the test correctly exposes a real bug): do NOT silently
  skip them. Present a concrete fix per failure (file, line, root cause, exact change)
  and ask ONCE: "Apply these N production fixes? (yes/no)". On "yes", apply them,
  re-run the suite, and iterate (max 3 rounds) until green or genuinely blocked.
  On "no", record them as OPEN BUGS in the report with the proposed fixes attached.

Never end the command with a vague "some errors remain" — every failure is either
fixed, an OPEN BUG with a ready-to-apply fix, or explicitly blocked with a reason. Every
CONFIRMED bug from 5.6 must resolve to Fixed, Open (declined), or Blocked — never left
unmentioned in the report.

### 7. Report

Generate the final report (template below), then persist it and present it:

1. Get the current date/time (e.g. via the shell) — do not guess it.
2. Determine the output path: `docs/test-reports/<YYYY-MM-DD-HHmm>.md`, repo-root relative.
   If `$ARGUMENTS` scoped this run to a specific path, append a slugified version of it, e.g.
   `docs/test-reports/2026-07-20-1430--src-services.md`.
3. Create `docs/test-reports/` if it doesn't exist yet, then write the full report (including
   the header fields below) to that file. Never overwrite a prior report — each run gets its
   own timestamped file, so the directory accumulates a history that can be diffed/compared
   across runs.
4. In the chat response, print a COMPACT summary — the gap/severity table, the bug/finding
   table, and the report's file path — NOT the full report body (it is already persisted to the
   file in step 3). Reprinting the whole report duplicates a large emission; the tables + path
   give full visibility while keeping the run token-thrifty. Print the full body only if the user
   explicitly asks for it.

Report template:

---

# Test Report

- **Scope**: [whole repository | the path from $ARGUMENTS]
- **Date**: [YYYY-MM-DD HH:mm]
- **Command**: `/rudis.test [$ARGUMENTS]`

## Test Infrastructure

- Framework: [name + version]
- Test directory: [path]
- Run command: [command]
- Existing test files: [count]

## Coverage Gap Analysis

| Priority | Untested Files | Tests Generated |
|----------|---------------|-----------------|
| CRITICAL | [n] | [n] |
| HIGH | [n] | [n] |
| MEDIUM | [n] | [n] |
| LOW | [n] | [n] |
| **Total** | **[n]** | **[n]** |

## Generated Tests Summary

List each generated test file with:

- File path
- Number of test cases
- Functions/methods covered

## Bug Report

Include this section only if Step 5.6 or Step 6a surfaced at least one CONFIRMED or SUSPECTED
bug. If none were found, write a single line instead: "No bugs found." — do not render an
empty table.

| Status | Severity | File | Description | Evidence | Resolution |
|--------|----------|------|--------------|----------|------------|
| CONFIRMED | CRITICAL | path:line | what goes wrong and when | failing test name | Fixed / Open (declined) / Blocked |
| SUSPECTED | MAJOR | path:line | what goes wrong and when | why it couldn't be reproduced | Needs manual verification |

- **CONFIRMED** — proven by a reproducing test that fails against current code (Step 5.6 or
  Step 6). Resolution must match what actually happened in Step 6a: Fixed, Open (user declined
  the fix, proposed patch attached below), or Blocked (with reason).
- **SUSPECTED** — identified by reasoning/static analysis but not yet proven by a failing
  test. Never presented as fact — flag it for manual review, and include the reproducing test
  you attempted (and why it fell short) so a human can finish the proof.

For every Open or Blocked CONFIRMED bug, attach the proposed fix (file, line, root cause, exact
change) directly under its row so it's ready to apply later.

## SonarQube Compliance

| Severity | Count | Category |
|----------|-------|----------|
| BLOCKER | [n] | [breakdown] |
| CRITICAL | [n] | [breakdown] |
| MAJOR | [n] | [breakdown] |
| MINOR | [n] | [breakdown] |
| INFO | [n] | [breakdown] |

### Findings Detail

For each finding (grouped by severity):

- **File**: path:line
- **Rule**: category (e.g., code-smell / duplication / complexity / security)
- **Severity**: BLOCKER / CRITICAL / MAJOR / MINOR / INFO
- **Description**: what was found
- **Recommendation**: how to fix it

## Quality Gate

- **Verification method**: [Real SonarQube scan (`sonar.qualitygate.wait`) | Static approximation — no scan run]
- **Real gate result** (only if §5.0 ran a scan): [PASS | FAIL — list failing conditions]
- [ ] No BLOCKER issues
- [ ] No CRITICAL issues
- [ ] No CONFIRMED bugs left unresolved (each is Fixed, Open with a proposed fix, or Blocked)
- [ ] Unit test coverage: [estimated % based on files with tests / total testable files]
- [ ] All generated tests pass

> If the method above is "Static approximation", this section reflects the
> static checks only — it is NOT proof the project's SonarQube gate passes.

## Recommendations

Prioritized list of next actions to improve quality.

---

## Rules

- Never modify production/source code without the explicit approval described in step 6a — default is test files only
- Never fabricate findings — report only what exists in the code
- Never mark a bug CONFIRMED without a reproducing test that actually fails against current code — unconfirmed suspicions are reported as SUSPECTED, never upgraded to CONFIRMED on reasoning alone
- Never mark a quality gate as passed without evidence
- If test generation cannot proceed (no framework detected, no source files), stop and explain why
- Always verify generated tests compile and pass before reporting success
- Always persist the report to `docs/test-reports/<timestamp>.md` as its own new file — never overwrite a previous run's report, and never skip writing it even if no bugs/findings turned up
