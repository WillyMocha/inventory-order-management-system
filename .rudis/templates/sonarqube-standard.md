<!--
Canonical code-quality standard shipped by Rudis. Copied verbatim to
.rudis/templates/sonarqube-standard.md on `rudis init`. Referenced by
`/rudis.implement` (context load + phase verification gate) and `/rudis.test`
(§5 SonarQube Compliance Analysis — this file is the source of truth for the
thresholds used there). Edit this file to change the standard for all
future/updated projects — do not hand-maintain a second copy elsewhere.

Purpose: make generated code pass a SonarQube quality gate on the FIRST pass,
instead of being cleaned up after the pipeline fails. This encodes the default
"Sonar way" baseline. A project's own SonarQube instance may enforce a stricter
or different quality profile — when that profile is known, layer its rules on
top of this baseline; this file is the floor, not the ceiling.

Security findings are NOT duplicated here — they live in
`templates/security-standard.md` (Sonar "Vulnerability" + "Security Hotspot").
This file covers the Reliability (Bugs) and Maintainability (Code Smells)
dimensions plus the coverage/duplication gate conditions.
-->

# SonarQube Code Quality Standard

## 0. Contract

Every command that generates or edits code **must**:

1. Write code that would pass a default SonarQube quality gate on **new code** — do not rely on a later cleanup pass.
2. Treat each threshold in §2 as a hard limit for any function/file you create or materially change. Exceeding one is a defect to fix now, not a style nit to defer.
3. Never introduce a Reliability bug pattern from §3 or a Maintainability smell from §4. Security lives in `security-standard.md` and is applied in parallel.
4. Never leave debug output, dead code, commented-out code, or `TODO`/`FIXME`-only stubs in generated code — Sonar flags all of these.
5. When a project-specific SonarQube quality profile is available, it overrides this baseline where stricter; otherwise this baseline applies.

## 1. Quality gate model (Sonar way, on New Code)

A default gate fails the build unless the code you added/changed keeps **all** of:

| Condition | Target on new code |
|---|---|
| Reliability rating | **A** (0 new bugs) |
| Security rating | **A** (0 new vulnerabilities) |
| Security hotspots | 100% reviewed |
| Maintainability rating | **A** (technical-debt ratio low) |
| Coverage | **≥ 80%** of new lines |
| Duplicated lines | **< 3%** on new code |

Practical consequence for generation: new logic ships **with unit tests**
(see `/rudis.test`), stays under the §2 limits, and repeats no block big enough
to trip duplication.

## 2. Thresholds (canonical — `/rudis.test` §5 uses these)

| Metric | Limit | Sonar dimension |
|---|---|---|
| Function/method length | ≤ 40 lines | Maintainability |
| File length | ≤ 300 lines | Maintainability |
| Nesting depth (if/for/while/try) | ≤ 3 levels | Maintainability |
| Parameters per function | ≤ 4 (use an options object beyond that) | Maintainability |
| Cyclomatic complexity | ≤ 10 per function | Maintainability |
| Cognitive complexity | ≤ 15 per function | Maintainability |
| Duplicated block | < 6 similar lines repeated | Duplication |
| Boolean expression terms | ≤ 3 conditions (extract a named predicate beyond that) | Maintainability |

These limits are set **at or stricter than** SonarQube's default rule values
(e.g. Sonar defaults allow up to 7 parameters and longer functions; cognitive
complexity 15 matches). Meeting the tighter bar here therefore clears the
default "Sonar way" profile for these rules — a project profile can only make
them stricter, never looser than Sonar's own defaults, so passing this floor is
a safe target. It is still not a *guarantee* the gate passes: coverage,
duplication density, and any custom rules are only proven by an actual scan
(see `/rudis.test` §5.0).

When a limit is genuinely unavoidable (e.g. a required framework signature with
many params), refactor first; only keep it if refactoring would harm clarity,
and note why.

## 3. Reliability — bug patterns to never write (Sonar "Bug")

- **Null/undefined dereference** on a path that can be null at runtime — guard, use optional chaining, or narrow the type first.
- **Ignored return value** of a function whose result carries success/failure (e.g. a write that returns a status).
- **Resource leak** — every opened handle, connection, stream, timer, listener, or subscription is closed/cleared on all paths (use `finally`, `using`, context managers, `defer`).
- **Unconditional / dead branch** — a condition that is always true or always false, or code after an unconditional return/throw.
- **Identical branches** — `if`/`else` (or ternary arms, or `switch` cases) with the same body.
- **Loose equality across types** — see per-language notes; a `0`/`""`/`NaN`/`null` coercion bug is a classic Sonar bug.
- **Swallowed exception** — an empty `catch`, or one that discards the original error/cause. Log with context or rethrow.
- **Off-by-one / non-terminating loop** — verify bounds on slices, pagination, and indexing.
- **`await` in a loop** where the calls are independent — batch with `Promise.all`/equivalent (also a performance smell).

## 4. Maintainability — code smells to avoid (Sonar "Code Smell")

- No **debug/print** left in code: `console.log`/`console.debug` (JS/TS), `var_dump`/`print_r`/`error_log` for debugging (PHP), `print` (Python), `System.out.println` (Java).
- No **commented-out code** and no dead/unreachable code — delete it; git keeps history.
- No **magic numbers/strings** in logic — name them as constants/enums.
- No **unused** imports, variables, parameters, or private members.
- **Names** are meaningful and follow the language convention (§5); no single-letter names except trivial loop indices.
- No **duplicated literal** used 3+ times — extract a constant.
- **Cognitive load**: prefer early returns (guard clauses) over deep nesting; extract a helper when a function does more than one thing.
- No **empty** blocks (empty function, empty `catch`, empty `if`) without an explanatory comment on why it is intentional.
- No `TODO`/`FIXME` as the entire implementation — either implement it or track it as a real task, not a leftover marker in shipped code.

## 5. Security hotspots

Do **not** re-derive security rules here — apply `templates/security-standard.md`
in full. The Sonar dimensions it maps to:

- **Vulnerability** — its ❌ DON'T patterns (injection, hardcoded secrets, weak crypto, missing authz, XSS). A Critical/High one is a **BLOCKER**.
- **Security Hotspot** — Sonar flags security-sensitive APIs (dynamic SQL, `exec`/`eval`, weak randomness, permissive CORS, cookie flags, deserialization) for mandatory review. Generated code must not introduce an unreviewed hotspot: either avoid the sensitive API or make its safe usage obvious (parameterized query, allowlist, CSPRNG, `HttpOnly`/`Secure`/`SameSite` cookies).

## 6. Language quick reference

### PHP

- **DO**: `declare(strict_types=1)`; type-hint params and returns; `===`/`!==` (never `==` on mixed types); PDO **prepared statements** (never string-concat SQL); early returns; `final` where inheritance isn't intended.
- **DON'T**: `@` error suppression; `eval()`, `extract()`, `create_function()`; leftover `var_dump`/`print_r`; `global`; unbounded `switch` without `default`; deep `if` nesting where a guard clause fits.

### JavaScript / TypeScript / React

- **DO**: `const`/`let` (never `var`); `===`/`!==`; `async/await` with `try/catch`; TS explicit types on public APIs; React list `key`s that are stable ids (not the array index); complete `useEffect` dependency arrays; sanitize before any `dangerouslySetInnerHTML`.
- **DON'T**: `console.log` in shipped code; TS `any` (use `unknown` + narrowing); mutating React state directly (spread/immutable update instead); nested ternaries; unused props/vars; `==`/`!=`; leaving a Promise unhandled (no floating promises).

### Python

- **DO**: type hints; context managers (`with`) for files/connections; `is`/`is not` for `None`; specific exception types; f-strings; small pure functions.
- **DON'T**: bare `except:`; mutable default arguments (`def f(x=[])`); leftover `print` debugging; `eval`/`exec`; wildcard `from x import *`; comparing to `None` with `==`.

## 7. Self-review checklist (before marking a task done)

- [ ] Every function I added/changed is within the §2 limits (length, params, complexity, nesting).
- [ ] No debug output, dead code, or commented-out code remains.
- [ ] No magic numbers/strings; constants named.
- [ ] No unused imports/vars; no duplicated block ≥ 6 lines.
- [ ] No §3 reliability pattern (null deref, leak, swallowed error, dead branch).
- [ ] New logic has unit tests aiming at ≥ 80% coverage of the new lines.
- [ ] `security-standard.md` applied for any security-sensitive code (hotspots reviewed).
- [ ] Language conventions from §6 followed for every file touched.
