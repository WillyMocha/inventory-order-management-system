# Data Model: User Profile Page

**Feature**: [spec.md](./spec.md) · **Date**: 2026-10-03

## Summary

**No schema change.** No new table, no new column, no migration. The feature reads and updates
existing data only, per the planning constraint "no new table without a strong reason"
(research R-001).

Source of the model: the spec's **Key Entities**. There is no external resource-model document
for this feature. The authoritative schema remains
[`../001-inventory-order-management/data-model.md`](../001-inventory-order-management/data-model.md)
and `database/001_schema.sql`.

## Entities touched

### User — existing table `user`

| Column | Read | Written | By this feature |
| --- | --- | --- | --- |
| `id` | ✓ | — | Identity is taken from the session, never from the request (FR-003) |
| `name` | ✓ | — | Shown; not editable (FR-011) |
| `email` | ✓ | — | Shown; not editable (FR-011); also the key for the attempt limit |
| `role` | ✓ | — | Shown as a badge; not editable |
| `is_active` | ✓ | — | Shown as a status badge; not editable (A-006) |
| `password_hash` | ✓ | ✓ | Verified, then replaced by `password_hash(new, PASSWORD_DEFAULT)` on success |
| `updated_at` | — | ✓ | Set by the existing `updatePasswordHash` statement |

- Validation (domain rule, R-003): new password length ≥ `User::MIN_PASSWORD_LENGTH` (8). The new
  password must differ from the current one. The current password must verify against
  `password_hash`.
- State lifecycle: unchanged. `is_active` is owned by the Admin (user management).

### LoginAttempt — existing table `login_attempt` (reused, R-001)

| Column | Use by this feature |
| --- | --- |
| `email` | The signed-in user's email |
| `ip_address` | Client IP (binary, as today) |
| `attempted_at` | Time of the failed current-password check |
| `succeeded` | Always `0` for recorded profile failures; a success clears the failures via `clearFailures` |

- Rule: ≥ 5 failures for `(email, ip)` within 15 minutes ⇒ both sign-in and profile password
  change are refused until the window passes (shared counter, by design).
- Existing indexes `ix_login_attempt_email_time` and `ix_login_attempt_ip_time` already serve the
  count query. No index change.

## Data Design Decisions

| Decision | Choice | Why |
| --- | --- | --- |
| Storage for the FR-008 counter | Reuse `login_attempt` | Same information, same secret, stronger shared limit; a new table would duplicate it column for column (R-001) |
| Password policy location | `User::MIN_PASSWORD_LENGTH` constant | One rule for Admin-set and self-set passwords (R-003) |
| Profile-editable fields | None besides the password | FR-011 (decided 2026-10-03) |

## Conformance check

- Every entity in the spec's Key Entities maps to an existing table. The "password-change attempt"
  maps to `login_attempt` rows.
- No attribute is invented, renamed, or dropped. No migration file is added (`database/` stays at
  `001`–`003`).
