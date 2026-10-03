# Quickstart: User Profile Page

**Feature**: [spec.md](./spec.md) · **Date**: 2026-10-03

How to verify the feature end to end once implemented. Every demo account uses the password
`Password123!`.

## 0. Start

```bash
docker compose up -d                       # migrations run automatically; no new migration here
docker compose exec app composer check     # all gates must stay green
```

## 1. View own profile (US1, FR-001…FR-004)

1. Sign in as `sales1@ioms.test`. The sidebar shows **My profile**, and the name block at the
   bottom is a link.
2. Open it. The page shows the name, `sales1@ioms.test`, role **Sales**, and status **Active**.
3. Repeat as `warehouse1@ioms.test` and `admin@ioms.test`. Each sees only their own account.
4. Try `http://localhost:8080/profile?id=1` as Sales. It still shows the Sales user (the id is
   ignored).
5. Sign out and open `/profile`. You are redirected to `/login`.

## 2. Change own password (US2, FR-005…FR-007)

1. As `sales1@ioms.test`, submit current `Password123!` and the new password `NewSecret123!`
   twice. A confirmation appears and you stay signed in.
2. Sign out. Sign in with `Password123!` → refused. Sign in with `NewSecret123!` → succeeds.
3. Restore it by changing it back to `Password123!` from the profile (otherwise the demo account
   stays changed).

## 3. Rejections (FR-006, NFR-004)

| Input | Expected |
| --- | --- |
| Wrong current password | "Your current password is incorrect." Nothing changes |
| New password `short` | Length error on the new password field |
| Confirmation differs | Error on the confirmation field |
| New = current | "Choose a password different from your current one." |

In every case the password fields come back empty and the stored password is unchanged.

## 4. Attempt limit (FR-008)

1. As `warehouse2@ioms.test`, submit a wrong current password 5 times.
2. The 6th submission, even with the correct password, shows "Too many incorrect attempts.
   Try again later." (HTTP 429).
3. Sign out and try to sign in as `warehouse2@ioms.test` from the same browser. It is also
   refused: the counter is shared with sign-in (research R-001).
4. To reset quickly: `docker compose exec app composer db:reset`, or wait 15 minutes.

## 5. Mobile and accessibility (NFR-002)

- Open `/profile` at 360px. No horizontal scroll; the form is fully usable.
- Tab through the page. Every field and button shows the focus ring.

## Automated checks

```bash
docker compose exec app composer test:unit          # AuthServiceTest: changeOwnPassword rules
docker compose exec app composer test:integration   # ProfileFlowTest: guard, CSRF, ownership, shared limit
```
