# HTTP Routes: User Profile Page

**Feature**: [../spec.md](../spec.md) · **Date**: 2026-10-03

Additions to the application's route table. They are merged into
[`../../001-inventory-order-management/contracts/http-routes.md`](../../001-inventory-order-management/contracts/http-routes.md)
and `config/routes.php` during implementation. **No JSON endpoint** is added: the profile is an
HTML page, and the spec requires no machine consumer.

Legend: **A** Admin · **S** Sales · **W** Warehouse Staff.

| Method | Path | Auth | Roles | Authorization rule | Notes |
| --- | --- | --- | --- | --- | --- |
| GET | `/profile` | session required | **A S W** | Subject = `Session::userId()`. No id in the path or query, so ownership cannot be overridden (FR-003) | 200 with the profile; unauthenticated → 302 `/login` |
| POST | `/profile/password` | session required + **CSRF** | **A S W** | Subject = `Session::userId()`. Current password re-verified (FR-005) | See responses below |

## `POST /profile/password`

**Form fields** (`application/x-www-form-urlencoded`):

| Field | Required | Rule |
| --- | --- | --- |
| `csrf_token` | yes | Must match the session token |
| `current_password` | yes | Must verify against the stored hash |
| `new_password` | yes | ≥ 8 characters (`User::MIN_PASSWORD_LENGTH`); must differ from the current password; leading/trailing spaces are trimmed on input, as at sign-in |
| `new_password_confirmation` | yes | Must equal `new_password` |

**Responses**:

| Case | Status | Result |
| --- | --- | --- |
| Success | 302 → `/profile` | Password updated, attempt counter cleared, session id regenerated, flash "Your password has been changed." |
| Any rule in FR-006 fails | 422 | Profile page re-rendered with field errors. **Password fields are always empty** |
| Wrong current password | 422 | Field error "Your current password is incorrect." Failed attempt recorded |
| ≥ 5 failures for (email, IP) in 15 min | 429 | Profile page re-rendered with alert "Too many incorrect attempts. Try again later." Password not checked |
| Missing or invalid CSRF token | 403 | Existing front-controller check (`public/index.php`, every non-GET request); nothing changed |
| Not signed in | 302 → `/login` | Existing guard |
| Signed-in user deactivated meanwhile | 302 → `/login` | Existing behaviour on the next request |

## Navigation (all roles)

- New sidebar item **"My profile"** → `/profile` (`activeNav = 'profile'`).
- The name and role block in the sidebar footer links to `/profile`.
