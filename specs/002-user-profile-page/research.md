# Research: User Profile Page

**Feature**: [spec.md](./spec.md) · **Plan**: [plan.md](./plan.md) · **Date**: 2026-10-03

Every decision below resolves an open point from the Technical Context. No item remains
`NEEDS CLARIFICATION`.

---

## R-001 — No new table for the password-change attempt limit

**Decision**: Reuse the existing `login_attempt` table and `LoginAttemptRepositoryInterface`
(`record`, `countRecentFailures`, `clearFailures`) for FR-008. A wrong current password is recorded
as a failed attempt for `(account email, client IP)`. Before checking the password, the limit is
enforced with the same values as sign-in (`config/app.php` → `security.login_max_attempts` = 5,
`security.login_window_minutes` = 15, already injected into `AuthService`). No
schema change and no migration.

**Rationale**:
- User constraint for this plan: *no new table without a strong reason*. None exists. The
  information needed — "how many recent failed proofs of this account's password from this client"
  — is exactly what `login_attempt` already stores.
- Both forms test the **same secret**. Counting them together is the stronger control: an
  attacker cannot get 5 guesses on the sign-in form plus another 5 on the profile form.
- Assumption A-003 asked for the sign-in limit values; reusing the mechanism guarantees they can
  never drift apart.

**Alternatives considered**:
- *New `password_change_attempt` table*: rejected. It duplicates `login_attempt` column for column,
  adds a migration, a repository pair, and a fake, and gives a weaker control (separate budgets).
- *Counter in the PHP session*: rejected. Services must not read the session (constitution I),
  and the counter would reset on sign-out and sign-in, which is exactly the move an attacker
  with a stolen session would make.
- *Key by email only, across all IPs* (literal "per user"): rejected. A stranger guessing the
  sign-in password from anywhere could then lock the owner out of their own profile form. FR-008
  was refined to "per account and client address" to match.

**Consequence accepted**: 5 wrong current-password entries also block sign-in for that account
from that address for 15 minutes, and vice versa. A successful password change clears the counter
(`clearFailures`), just like a successful sign-in.

## R-002 — Where the use case lives: `AuthService`, not a new `ProfileService`

**Decision**: Add `AuthService::changeOwnPassword(User $actor, string $currentPassword, string
$newPassword, string $confirmation, string $ipAddress): void`. Viewing the profile needs no new
service method: the controller loads the signed-in user with the existing
`UserService::requireUser(int)`.

**Rationale**: `AuthService` already owns everything this rule needs: `UserRepositoryInterface`,
`LoginAttemptRepositoryInterface`, and the max-attempts and window values (constructor-injected
from `config/app.php`). It is also the class responsible for proving a user knows their password. A
new service would receive the identical four constructor arguments for one method, which is the kind
of layer spec C-003 counts against.

**Alternatives considered**: *`ProfileService`*, rejected for the duplication reason above.
*Extending `UserService::changePassword`*, rejected because that method serves the Admin's
step-up flow, sets any user's password, and has no attempt limit. Merging the two would blur two
different authorization rules.

## R-003 — Shared password policy moves to the `User` entity

**Decision**: Move the minimum length (8) from `UserService::MIN_PASSWORD_LENGTH` (private) to a
public constant on the entity, `User::MIN_PASSWORD_LENGTH`. Both `UserService` and `AuthService`
read it from there.

**Rationale**: Assumption A-002 requires both flows to apply the same rule. A second literal `8`
would be Duplicate Code that drifts. A domain rule about a User belongs on the entity (constitution:
entities hold domain rules). This is a small Boy Scout refactor of existing code and will be logged
in `docs/quality/refactor-log.md`.

## R-004 — Validation rules and their order (FR-006, NFR-004)

**Decision**: `changeOwnPassword` evaluates, in this order:
1. **Attempt limit.** If ≥ 5 recent failures for `(email, ip)`, throw `RateLimitException`. This is
   checked before verifying the password, so a locked-out client learns nothing.
2. **Input rules**, collected into a single `ValidationException` (field-level messages):
   - `current_password` required
   - `new_password` required, ≥ `User::MIN_PASSWORD_LENGTH`
   - `new_password_confirmation` must equal `new_password`
3. **Current password.** If `password_verify` fails, record a failed attempt and throw
   `ValidationException(['current_password' => 'Your current password is incorrect.'])`.
4. **Reuse.** If the new password equals the current one (`password_verify(new, hash)`), throw
   `ValidationException(['new_password' => 'Choose a password different from your current one.'])`.
5. **Success.** Store `password_hash(new, PASSWORD_DEFAULT)` and clear failures for `(email, ip)`.

**Rationale**: Format errors (step 2) are cheap and need no secret, so they do not count as guesses.
Only a real wrong-password proof (step 3) is recorded. The reuse check needs the current password to
be proven first, otherwise it would act as a password oracle. Password values arrive already trimmed by `Request::input()`
(`app/Support/Request.php`), the same path used by sign-in and by the Admin's password form. The
Service does not trim again and does not try to preserve spaces: diverging from sign-in would let a
user set a password they can never sign in with.

## R-005 — Session renewal after a change (FR-007)

**Decision**: Add `Session::regenerate(): void`, which calls `session_regenerate_id(true)`. The
controller calls it after a successful change. The user stays signed in.

**Rationale**: `Session::login()` already does this, but calling `login()` again to get the side
effect would hide intent. A one-line plumbing method in `app/Support` is the smallest honest option.
The Service never touches the session (constitution I).

## R-006 — Security standard §2 / §7 checklist for the new endpoints

| Item | Decision |
| --- | --- |
| Authentication | Session required on both routes; unauthenticated → redirect to `/login` (existing guard) |
| Authorization | Roles: Admin, Sales, Warehouse Staff (`$all`). **Ownership is implicit**: no id in the route; the subject is always `Session::userId()` (FR-003) |
| CSRF | `POST /profile/password` is covered by the existing front-controller check for every non-GET request (`Csrf::isValid`), refusing with 403 — no per-controller code needed |
| Re-auth on sensitive change | The current password *is* the re-auth (FR-005) |
| Rate limit | R-001: 5 / 15 min per (email, IP), shared with sign-in |
| Uniform errors | Wrong current password → one fixed message (NFR-004). Locked out → generic "Too many incorrect attempts. Try again later." Neither reveals the stored password or the attempt count |
| Secret handling | Password fields are never re-rendered with their values, never logged, never flashed |
| Response codes | Validation failure → the controller catches `ValidationException` and re-renders the page with 422 (same convention as `UserController`). Rate limit → the controller catches `RateLimitException` and re-renders with an alert and 429, so the user stays on their profile instead of the global 429 page |

## R-007 — UI placement within the existing design system

**Decision**:
- Add a **"My profile"** entry to the sidebar for every role (icon `users` from the existing Lucide
  sprite).
- Make the name and role block in `.nav-user` a link to `/profile`.
- The page reuses existing patterns: a `page-header`, a summary `card` with role and status badges
  (as on user and detail pages), and a "Change password" `card` with `form-grid` fields,
  `field-required` labels, and `field-error` messages (as in `views/users/form.php`). No new CSS
  component is expected; a rule may be added only if the 360px check shows a need.

**Rationale**: Spec "Existing UI to match" names the user form and the detail pages. One extra
nav item for all roles changes every page's sidebar, so the UI-01 screenshots must be retaken
after implementation (see plan, follow-ups).

## R-008 — Re-check the session user on every authenticated request (FR-012)

**Found during planning**: the guard (`Authorization::authorizeRoute`) trusts the session alone.
An account that an Admin deactivates, or whose role an Admin changes, keeps working — with its
old permissions — until the session ends. This is a pre-existing, application-wide gap. The product
owner chose to fix it as part of this feature (2026-10-03).

**Decision**:
- Business rule: `AuthService::activeSessionUser(int $userId, Role $sessionRole): ?User` returns the
  user only if it exists, `canSignIn()` (active), and its role equals the role stored in the
  session. Otherwise it returns `null`. Unit-tested with `InMemoryUserRepository`.
- Wiring: in `public/index.php`, right after `authorizeRoute()` and **only for non-public routes**,
  call it. On `null`: `Session::logout()` (also clears the cookie), then throw the existing
  `UnauthenticatedException`. The existing handler already redirects HTML requests to `/login` and
  answers `/api/*` with 401 JSON.
- Cost: one primary-key lookup per authenticated request, on a table of a handful of rows.

**Rationale**: The rule belongs in a Service (constitution I), not in `Authorization`, which is
`app/Support` plumbing and depends only on `Session`. Keeping it out of `Authorization` also avoids
making plumbing depend on a repository. The front controller already composes guard, CSRF, and
controller, so it is the natural, single place to call it, with no change to any controller.

**Alternatives considered**:
- *Check inside `ProfileController` only*: rejected by the product owner (inconsistent: every
  other page would still serve a deactivated user).
- *Refresh the session role silently instead of signing out*: rejected. A role change is a security
  event. Forcing a fresh sign-in is simpler to reason about and also regenerates the session id.
- *Put the check in `Authorization`*: rejected (plumbing depending on a repository; breaks the
  existing unit tests' assumptions about `Authorization`).
