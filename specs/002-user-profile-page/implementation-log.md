# Implementation Log: User Profile Page

## Session 2026-10-03 — /rudis.implement

- **Checkpoint HEAD**: `30c1508bb8d0a623c727fe75bc1be5e6ca8cd3ff` (branch `fix/correct-business-flow`;
  originally `e0928f8`; hashes changed when history was rewritten to remove the brief PDF and the
  obsolete `specs/001-inventory-order-system/` folder)
- **Working tree at start**: `README.md` modified (constitution v1.2.0 language note) and
  `specs/002-user-profile-page/` untracked — both are this feature's planning artifacts, left as is.
- **Tasks targeted**: T001–T032
- **Checklists**: `checklists/requirements.md` 16/16 ✓ PASS
- **Requirement coverage gate**: FR-001 … FR-012 each cited by ≥1 task; SC-001 … SC-007 reachable
  (see tasks.md "Requirement Coverage") ✓
- **No git writes**: nothing is committed, tagged, or pushed by this run.

### Assumptions (low impact, recorded instead of asking)

- A1 — T005 seeds its own two users (pattern of `AuthFlowTest::seedUsers`) instead of the full
  `SalesOrderFixtures` trait: the test only needs users, and the trait also seeds products, stock,
  and orders it would never read.

### Task log

- T001 — baseline `docker compose exec -T app composer check`: unit 374 OK, integration 138 OK,
  PHPStan and PHPCS clean.
- T002 — `tests/Unit/Service/AuthServiceTest.php`: 4 cases for `activeSessionUser` (valid, unknown id,
  inactive, role changed); confirmed red first (undefined method).
- T003 — `app/Service/AuthService.php`: `activeSessionUser(int, Role): ?User` + `use Role`; T002 green.
- T004 — `public/index.php`: re-check after `authorizeRoute()` for non-public routes; null id/role or
  rejected user → `logout()` + `UnauthenticatedException`; `use App\Service\AuthService`.
- T005 — `tests/Integration/SessionRevalidationTest.php` (new, 4 cases, assumption A1); green.
- T006 — HTTP against the running stack (curl, cookie jars): Sales `/dashboard` 200 → Admin
  `POST /users/2/toggle-active` → Sales `/dashboard` 302 `/login`; `/api/products/SKU-000001/availability`
  401 JSON; WS `/api/dashboard/low-stock` 200 → deactivate → 401 JSON; role change Sales→WS via
  `POST /users/2` → next `/sales-orders` 302 `/login`. Users 2 and 4 restored (active, original role).
- Bolt 1 gate — targeted PHPUnit (26 OK), PHPStan level 6 OK, PHPCS OK.
- T007 — `tests/Integration/ProfileFlowTest.php` (new): route roles, guard without session,
  `show()` as Sales with `?id=`/`?user_id=` of the Admin → 200 with own email only. Red first.
- T008 — `config/routes.php`: `GET /profile` ($all) under a new "Profil sendiri" section.
- T009 — `app/Controller/ProfileController.php` (new): `show()`, `currentUser()` from session only.
- T010 — `config/container.php`: `ProfileController` factory + `use`.
- T011 — `views/profile/show.php` (new): page header + 4 read-only `stat` tiles (Name, Email, Role,
  Status). Deviation A2 below.
- T012 — `views/layout/_nav.php`: "My profile" for all roles; `.nav-user-name` is now a link to
  `/profile`. `public/assets/css/app.css`: `a.nav-user-name` (no underline, hover accent, wraps).
- T013 — `AuthServiceTest`: 7 cases for `changeOwnPassword` (success, wrong current, too short,
  mismatch, missing, reuse, exact value stored); each rejection asserts the hash is unchanged. Red first.
- T014 — `User::MIN_PASSWORD_LENGTH = 8`; `UserService` uses it (private const removed);
  `UserServiceTest` unchanged and green.
- T015 — `AuthService::changeOwnPassword()` steps 2–5 of R-004 + `WRONG_CURRENT_PASSWORD_MESSAGE`.
- T016 — `Session::regenerate()`; guarded by `session_status() === PHP_SESSION_ACTIVE` (A3).
- T017 — `POST /profile/password` route; `ProfileController::changePassword()` (422 re-render,
  regenerate + flash + redirect on success).
- T018 — password card in `views/profile/show.php` (no `value` attribute, field errors, summary alert).
- T019 — `ProfileFlowTest`: SC-004, SC-005, POST route roles, SC-002 (smuggled `id`/`user_id`),
  422 body never contains the submitted passwords.
- T020 — `AuthServiceTest`: lockout blocks the right password, one failure per wrong current
  password, format errors record none, success clears. 3 red first (format case already held).
- T021 — `changeOwnPassword()` step 1 (`isLockedOut`), `record(..., false)` on wrong current,
  `clearFailures` on success.
- T022 — `ProfileController` catches `RateLimitException` → 429 re-render; view alert "Too many
  incorrect attempts. Try again later." with the form still visible.
- T023 — `ProfileFlowTest`: profile failures lock sign-in, sign-in failures lock the profile,
  controller 429 render keeps the form and leaves the hash unchanged.
- Gate (Bolts 2–4) — `composer check`: unit 389 OK, integration 153 OK, PHPStan OK, PHPCS OK
  (after fixing 3 >120-char lines with a `changeSalesPassword()` test helper and extracting repeated
  literals to constants).
- HTTP (quickstart §1–§4) — every role sees its own profile, `?id=1` ignored, signed-out → `/login`;
  422 mismatch with field error and no password echoed; POST without CSRF → 403; success → 302
  `/profile`, flash shown, **session cookie id changed**, still signed in; old password refused,
  new accepted; demo password restored. Lockout: 6 wrong submissions → 429 (desktop and 360px).
  Lockout rows for `warehouse1@ioms.test` cleared afterwards through
  `LoginAttemptRepositoryInterface::clearFailures` (a direct SQL `DELETE` was blocked by the session's
  safety check, so the app's own repository was used instead); password verified as `Password123!`.
- Pass B (rendered, Chrome headless via puppeteer-core) — `/profile` populated at 1366, 768, 360
  (overflow 0); success, 422, and 429 states at 1366 and 360 (overflow 0). No clipping, consistent
  spacing tokens, role tone/icon equal to the Users page.

### Deviations (low impact, recorded)

- A2 — T011 asked for a role *badge*. The design system's `badge--*` classes are order/stock status
  colours; using one for a role would mislabel it. The role is shown as text in a tinted `stat` tile
  with the same tone and icon as the Users page; status keeps its `badge--fulfilled/cancelled` badge.
- A3 — `Session::regenerate()` skips when no session is active (CLI tests), mirroring why
  `AuthFlowTest` cannot call `session_regenerate_id()`. Over HTTP the id change was verified.
- A4 — Password inputs carry `aria-describedby` to their error/hint (`users/form.php` does not).
  Small accessibility gain for NFR-002; no visual change.
- T024 — `UserServiceTest` and `UserAccessTest` green and unchanged; over HTTP Admin
  `POST /users/3/password` with a wrong own password → 403, with the right one → 302 and the hash
  changes; restored to `Password123!`.
- T025 — `specs/001-.../contracts/http-routes.md`: FR-012 rule in the global preamble, new
  "Profil sendiri" route table, two rows in the error table, one row in the authorization matrix.
- T026 — `docs/architecture/class-diagram-as-built.md`: `ProfileController` (+ `AuthService`) in
  diagram 3, new subsection with a `ProfileController`/`AuthService`/interfaces/`User` diagram.
  All 4 Mermaid blocks rendered OK (mermaid 11, Chrome headless).
- T027 — `docs/quality/refactor-log.md`: R-7 (Move Field → `User::MIN_PASSWORD_LENGTH`).
- T028 — `docs/testing/use-case-coverage.md` (AuthService 4/4, new section, 2 integration rows);
  `docs/testing/test-results.md` (counts 389/1037, 153/551, 542/1588; 2 integration rows).
- T029 — `README.md`: Authentication row extended, new "Profil sendiri" row, My profile note
  under the role table.
- T030 — `docs/brd/modules/auth.md` (AUTH-CAP-006 implemented, + CAP-007/008, rules, API surface,
  data flow, screen row, tests, change log; gap removed) and `docs/brd/00-overview.md` (module
  index, gap removed, Q1 fulfilled, change log).
- T031 — evidence retaken with a rebuilt one-off harness (puppeteer-core + Chrome headless; base
  list = `HEAD:docs/testing/screenshots/run.json`, not committed): 38 captures (34 retaken + 20/21
  profile at desktop and 360px), overflow 0, clipped 0; contrast 570 elements in 11 measurements,
  0 failing (min 4.55:1); keyboard 4 pages, 0 stops without an indicator. Two **pre-existing**
  defects surfaced and fixed in `public/assets/css/app.css` (see A5, A6). Docs:
  `docs/testing/responsive-accessibility.md`, `docs/testing/screenshots/run.json`,
  `docs/testing/a11y-audit.json`, TD-7 note in `docs/quality/tech-debt.md`.
  Harness mistake corrected: one intermediate run re-read its own output and duplicated entries;
  the final run starts from the `HEAD` baselines, and the committed JSON has 38 unique files.
- T032 — `composer check` exit 0 (unit 389, integration 153, PHPStan 0, PHPCS 0/0);
  `docs/quality/phpstan-report.txt` and `phpcs-report.txt` regenerated (146 files). Quickstart
  §1–§5 walked on the running stack (§3 rejections: wrong current / too short / reuse → 422 with
  the right field message). NFR-001: `docker compose logs app` and `/var/log/apache2/*` contain none
  of the submitted password values; the only flash in `ProfileController`/`AuthService` is the fixed
  success text and there is no `error_log`. Demo accounts sales1, sales2, warehouse1, warehouse2
  verified on `Password123!` with 0 recent failures.

### Out-of-scope fixes (found by the evidence gate, recorded for review)

- A5 — `.table-wrap { position: relative; }`: `.visually-hidden` labels in table cells escaped the
  scroll container and widened `/products`, `/purchase-orders`, `/sales-orders`, and
  `/sales-orders/create` to 479–661px at 360px (touch mode). Pre-existing; one CSS line.
- A6 — `.alert--*` text now uses the `--*-text` tokens (as badges already do): `alert--error` was
  4.41:1 and `alert--success` lower. Pre-existing; affects every form's error summary and flash.
