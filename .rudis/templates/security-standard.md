<!--
Canonical secure-coding standard shipped by Rudis. Copied verbatim to
.rudis/templates/security-standard.md on `rudis init`. Referenced by
`/rudis.implement` (context load + phase verification gate), `/rudis.test`
(§5.5 Security), `/rudis.plan` (API contract generation), and
`/rudis.checklist` (security.md). Edit this file to change the standard for
all future/updated projects — do not hand-maintain a second copy elsewhere.

Basis: OWASP Top 10:2025, CWE, CVSS v4.0 severity model, NIST SP 800-63B rev.4,
RFC 9106 (Argon2). This is a condensed, actionable subset — not a replacement
for the full OWASP Cheat Sheet Series when a section needs more depth.
-->

# Secure Coding Standard

## 0. Contract

Every command that generates or edits code **must**:

1. Apply the ✅ DO patterns below for the category the task touches; treat every ❌ DON'T as a hard block, not a style nit.
2. Never write a ❌ DON'T pattern even if the user explicitly asks, unless the user confirms it and the context is local-only/non-production. Re-confirm if ambiguous.
3. Always validate input, encode output, and handle errors safely — these are defaults, not opt-in.
4. Never hardcode secrets (keys, passwords, tokens, connection strings) under any circumstance.
5. For every new endpoint, this order is fixed: **authentication → authorization → input validation → business logic → output encoding → audit log.**
6. When "fast to ship" conflicts with "secure," choose secure. Never disable a security control (CSRF, strict CORS, TLS verification, parameterized queries) to ease development unless the user explicitly confirms it and the context is local-only.
7. Treat any task touching PII, credentials, financial, or medical data as one severity tier higher than it would otherwise be.

## 1. Severity mapping

Aligned to the BLOCKER/CRITICAL/MAJOR/MINOR/INFO scale used by `/rudis.test` and the phase verification gate in `/rudis.implement`:

| This standard | Gate severity | Handling |
|---|---|---|
| Critical / High (CVSS 7.0–10.0) | **BLOCKER** | Must not reach main/production. Fix before the phase is marked complete. |
| Medium (CVSS 4.0–6.9) | **MAJOR** | Fix in the current pass; may defer only with an explicit user waiver logged in implementation-log.md. |
| Low (CVSS 0.1–3.9) | **MINOR** | Fix if trivial, otherwise log and continue. |
| Informational | **INFO** | Note it, no action required. |

## 2. Access control (CWE-284, 285, 639, 862, 863, 918)

### ❌ DON'T

- Trust client-supplied `userId`/`tenantId`/role from body, query, or params to decide authority.
- Rely on hiding a URL or on frontend routing as the only access control.
- Fetch a user-supplied URL without an allowlist (SSRF).
- Build file paths from raw user input (`path.join(base, req.body.filename)`).
- Accept a JWT without verifying signature, expiry, issuer, and audience.
- Set `Access-Control-Allow-Origin: *` on a credentialed endpoint.

### ✅ DO

- Deny by default; every route has an explicit authorization check.
- Take `userId`/`tenantId` from the verified session/JWT, never from the request payload.
- Scope every query with the tenant/owner in the `WHERE` clause — application-layer-only scoping is not enough.
- SSRF-safe fetch: allowlist host, resolve DNS and reject RFC 1918 / loopback / link-local ranges, disable redirect-follow, timeout < 5s.
- Path handling: `path.resolve(base, input)` then verify the result `startsWith(base)`.
- Return 404 (not 403) for a resource outside the caller's scope — don't leak existence.
- Rate-limit sensitive endpoints (login, reset, export) per user/IP.

## 3. Security misconfiguration (CWE-16, 611, 614, 1004, 489)

**❌ DON'T:** default credentials; stack traces in production responses; directory listing; debug/diagnostic endpoints reachable publicly; public storage buckets without justification; XML parsers with external entity resolution enabled; missing `HSTS`/`CSP`/`X-Content-Type-Options`; TLS < 1.2 or weak ciphers (RC4/DES/MD5).

**✅ DO:** separate config + secrets per environment, secrets in a vault (never in Git); mandatory security headers (`helmet` or framework equivalent); XML parsers with DTD/external entities disabled; TLS ≥ 1.2 (prefer 1.3); non-root containers, read-only rootfs where feasible; remove sample apps/default accounts before go-live.

## 4. Supply chain (CWE-1104, 937, 829, 494) — new/updated dependencies

**❌ DON'T:** install without checking the lockfile; use a `latest` tag for a production image; `curl | bash` in a build pipeline; disable integrity checks (`--no-integrity`, careless `--trusted-host`); **rely on the model's own training-data recollection to decide whether a package is current, deprecated, or discontinued** — a knowledge cutoff means a package deprecated or abandoned after that date still reads as "healthy." Deprecation status is a live fact; look it up, don't remember it.

**✅ DO — before adding or upgrading any dependency:**

1. **Name it explicitly** (library, version, purpose).
2. **Deprecation / discontinuation — verify live, never from memory (BLOCKER on a positive hit).** Run the ecosystem's own status query and read the actual output; do not skip it because the package "is well known." A package that is deprecated, yanked, archived, or targets an EOL runtime must not be adopted or left in place — replace it with the maintained successor (name the successor in the summary) or get an explicit user waiver logged in implementation-log.md.
   - **npm/pnpm/yarn**: `npm view <pkg> deprecated` (non-empty string = deprecated); `npm view <pkg> time.modified dist-tags` (stale/last-publish); check the registry `repository` for an archived GitHub repo. `npm outdated` and `npm audit --omit=dev` for the installed tree.
   - **Python**: `pip index versions <pkg>` / PyPI JSON (`info.yanked`, last release date); `pip-audit` for advisories; check the project repo for an archived/"unmaintained" marker.
   - **Go**: `go list -m -u all` (updates), `govulncheck ./...` (advisories + deprecated stdlib/module use).
   - **Any ecosystem, offline-safe fallback**: `osv-scanner` against the lockfile — flags known-vulnerable AND withdrawn packages without needing per-registry calls.
   - **Runtime/framework EOL**: cross-check the targeted runtime and major framework version against endoflife.date (Node, Python, .NET, PHP, Java, the framework itself) — an out-of-support runtime is a supply-chain finding even when every package is current.
   - If a network/tool lookup genuinely can't run in this environment, say so explicitly, mark the dependency **UNVERIFIED** in the summary, and treat it as a MAJOR finding to resolve before release — never silently downgrade it to "assumed fine."
3. **Freshness**: last commit < 12 months old (< 6 months for crypto/auth/parser/network libraries); last release < 18 months old. Two or more red flags (from freshness + step 2) → do not adopt without justification.
4. **Security**: cross-reference name+version across available advisory sources (NVD, GitHub Advisory, OSV.dev, Snyk) before trusting a single source clean.
5. **Legitimacy**: exact name match (typosquat check — `lodash` vs `lodahs`), publisher matches expected upstream, no suspicious `postinstall`/`preinstall` lifecycle script.
6. **License**: state it if not MIT/Apache-2.0/BSD; GPL/AGPL/SSPL/BUSL need explicit user confirmation before adoption.
7. **Prefer** what's already a project dependency or the language stdlib before adding a new one — every dependency is new attack surface.
8. **If replacing** what the user asked for (deprecation/CVE/license/better alternative), explain why — the user may have a reason to keep the original.
9. Commit the updated lockfile; mention it in the summary.

**Inherited dependencies (scaffold / boilerplate / profile).** The checks above apply not only to packages *you* add but to what a scaffold, boilerplate, or RUDIS profile brings in. On the first implementation pass of a new project — before building on top of it — run step 2's deprecation/EOL query across the existing lockfile and report any deprecated/discontinued/EOL dependency as a finding. A pinned-but-obsolete transitive dependency is the most common reason generated code passes local review yet fails an external security gate; do not assume inherited dependencies were vetted upstream.

## 5. Cryptographic failures (CWE-259, 327, 329, 330, 331, 338, 916)

**❌ DON'T:** MD5/SHA-1 for passwords or signatures; DES/3DES/RC4/ECB mode; `Math.random()`/`rand()` for tokens or secrets; hardcoded keys in source; reused IV/nonce with the same key; roll-your-own crypto; bcrypt cost < 10 or PBKDF2 < 600,000 iterations; plaintext or reversibly-encrypted passwords.

**✅ DO:**

- Passwords: Argon2id (`memoryCost ≥ 19 MiB, timeCost ≥ 2, parallelism = 1`) or bcrypt cost ≥ 12.
- Symmetric encryption: AES-256-GCM or ChaCha20-Poly1305 (authenticated encryption only), random 96-bit nonce per encryption.
- Asymmetric: RSA ≥ 3072-bit, or ECDSA/EdDSA (P-256/P-384/Ed25519).
- General hashing (non-password): SHA-256/384/512 or SHA-3.
- Randomness: `crypto.randomBytes()` / `secrets` module / `SecureRandom` / `crypto/rand` — never a non-CSPRNG.
- Keys: rotate periodically, store in a vault/KMS/HSM, never in Git.

## 6. Injection (CWE-79, 89, 77/78, 90, 91, 943, 1336)

**❌ DON'T:** string-concatenate or template-literal user input into SQL; `exec`/`child_process.exec` with unescaped user input; `innerHTML`/`dangerouslySetInnerHTML` with unsanitized content; compile a template engine from user input (SSTI); pass a raw Mongo query object from `req.body` (NoSQL operator injection, e.g. `{"$ne": null}`).

**✅ DO:**

- SQL: parameterized queries or an ORM/query builder. Dynamic identifiers (sort column, table name) go through an allowlist, never interpolation.
- Commands: `execFile`/`spawn` with an argument array, `shell: false` — never a shell string built from input.
- HTML output: rely on the framework's default escaping (React `{}` binding); if raw HTML is required, sanitize with `DOMPurify` first; add CSP as defense-in-depth.
- NoSQL: cast inputs to the expected primitive type (`String(...)`) or validate with a schema (Zod/Joi/Yup) before the query touches the driver.
- **Prompt injection (LLM apps/agents):** delimit user input from the system prompt (`<user_input>...</user_input>`); never give the model direct access to a destructive tool without an allowlist + human confirmation; validate/schema-check model output before forwarding it to another system; sanitize retrieved documents in RAG pipelines.

## 7. Insecure design (CWE-209, 256, 501, 522) — flag at plan/design time, not just at code review

**❌ DON'T:** password reset via guessable security questions; "email not found" vs "wrong password" (account enumeration); unlimited coupon/OTP attempts with no rate limit; sensitive actions (email/password/2FA change) with no re-authentication step; bulk endpoints with no batch-size limit.

**✅ DO:** think through "what happens if an attacker gains this access?" for every new feature before coding it; secure defaults (new features off until reviewed); uniform auth error messages; rate limit + step-up re-auth for sensitive actions; enforce business rules server-side always, never only in the UI.

## 8. Authentication failures (CWE-287, 297, 384, 521, 613, 620)

**❌ DON'T:** password policy capped at "min 6 chars, no breach check"; session ID in the URL; no session rotation after login; JWT `algorithm: none` or algorithm-confusion accepted; 2FA optional for admin/high-privilege accounts; login endpoint with no rate limit; reusable or >15-minute password-reset tokens.

**✅ DO:**

- Password policy per NIST 800-63B: min 8 (15+ recommended), no mandatory composition rules, check against a breached-password list, no forced periodic rotation.
- MFA: TOTP baseline, WebAuthn/passkey where phishing-resistance matters; required for admin/financial/third-party-data accounts.
- Sessions: regenerate ID after login/privilege change; `HttpOnly; Secure; SameSite=Lax` (or `Strict`); absolute + idle timeout.
- JWT: explicit algorithm whitelist (`{ algorithms: ['RS256'] }`), verify `iss`/`aud`/`exp`/`nbf`; short-lived access token + revocable refresh token stored server-side.
- Password reset: single-use, TTL ≤ 15 min, sent only to the registered address, uniform response regardless of whether the account exists.

## 9. Software/data integrity (CWE-345, 353, 426, 494, 502, 565, 830)

**❌ DON'T:** deserialize untrusted data with a code-executing format (`pickle.loads`, `yaml.load` without `SafeLoader`, Java `ObjectInputStream`, PHP `unserialize`); trust a hidden field or cookie for business logic with no MAC/signature; client-side-only integrity checks.

**✅ DO:** JSON only across trust boundaries; if deserialization of a richer format is unavoidable, whitelist the classes and signature-verify the payload first; HMAC-sign any state/token round-tripped through the client; SRI hashes on CDN-loaded scripts/CSS.

## 10. Logging & alerting (CWE-117, 223, 532, 778)

**❌ DON'T:** log passwords, tokens, secrets, session IDs, or full card/SSN numbers; concatenate raw user input into a log line (``logger.info(`user searched: ${q}`)`` — log injection); silently swallow an error (`catch(e) {}`).

**✅ DO:** structured (JSON) logs with `timestamp, level, traceId, userId, action, resource, outcome`; auto-redact sensitive fields at the logger config level; always log auth success/failure, authz failure, privilege changes, and rate-limit hits; pass user input as a field, not string-interpolated into the message.

## 11. Exceptional conditions (CWE-390, 391, 460, 703, 754, 755)

**❌ DON'T:** empty `catch`; return the raw stack trace/error to the client; fail-open ("catch, log, return success anyway"); infinite retries with no backoff/cap; leave a multi-step write half-applied on failure.

**✅ DO:** fail secure/closed — an authorization check that throws denies access; log with a `traceId`, respond to the caller with a generic message + reference ID; catch specific error types, let the rest bubble to one centralized handler; wrap multi-step writes in a transaction; retry external calls with backoff + jitter + a cap.

## 12. Other scanner-flagged categories

- **Hardcoded secrets** — `process.env.API_KEY` + `.env` in `.gitignore` + vault in production, never a literal in source.
- **Open redirect (CWE-601)** — validate the redirect target is a relative, same-origin path (`startsWith('/')` and not `'//'`) before `res.redirect(...)`.
- **CSRF (CWE-352)** — `SameSite` cookies + a per-session CSRF token (or framework built-in) for state-changing requests.
- **ReDoS (CWE-1333)** — avoid nested-quantifier regexes (`(a+)+`, `(.*)*`); cap input length before matching; prefer a deterministic parser for complex grammars.
- **File upload (CWE-434/23/400)** — validate MIME type + magic bytes (not just extension); rename to a UUID + allowlisted extension; enforce size limits; store off the webroot; never execute an uploaded file.
- **Race conditions (CWE-362/367)** — use DB transactions/row locks (`SELECT ... FOR UPDATE`) or optimistic locking (`version` column) for read-modify-write sequences; idempotency keys for retryable writes.
- **Sensitive data over-exposure** — return an explicit DTO/response schema, never the raw ORM entity (`res.json(user)` leaks `passwordHash`, `mfaSecret`, etc.).

## 13. Language quick reference

- **JS/TS/Node**: `helmet` (headers), `zod`/`joi`/`yup` (validation), `argon2` (passwords), `jsonwebtoken` with an explicit `algorithms` list, `dompurify` (HTML sanitization), Prisma/Drizzle/TypeORM (parameterized by default). Avoid `eval`, `new Function()`, `child_process.exec` with user input.
- **Python**: `argon2-cffi`/`passlib[bcrypt]`, SQLAlchemy/psycopg parameterized (never an f-string into SQL), `yaml.safe_load`, `defusedxml`. Avoid `pickle`, `eval`, `exec`, `subprocess.Popen(shell=True)`.
- **Java/Kotlin**: `BCryptPasswordEncoder(12)` or `argon2-jvm`, JPA/Hibernate or `PreparedStatement`, `disallow-doctype-decl` on the XML parser, Spring `@PreAuthorize`/`@Secured`. Avoid `Runtime.exec` with string concat, `SnakeYAML` without `SafeConstructor`.
- **Go**: `golang.org/x/crypto/bcrypt` or `argon2`, `database/sql` prepared statements or `sqlx`/`sqlc`, `crypto/rand` (never `math/rand`), `html/template` for HTML output.

## 14. Self-review gate (run before marking a phase/task complete)

If any item is "no" without a logged, user-confirmed waiver — fix it before proceeding (see §1 for severity → handling).

**Input** — external inputs validated by schema; string fields length-capped; enumerable fields (sort/filter/role) allowlisted.
**Auth** — every endpoint has explicit authentication + authorization; `userId`/`tenantId` comes from the server session, never the client.
**Data access** — all queries parameterized/ORM; tenant/owner scoping in the `WHERE` clause; response uses an explicit DTO, no internal-field leakage.
**Crypto & secrets** — no hardcoded secret; Argon2id/bcrypt≥12 for passwords; AES-GCM/ChaCha20-Poly1305 for symmetric encryption; CSPRNG for randomness.
**Injection** — no `eval`/`exec`/shell-string with user input; HTML output escaped/sanitized; templates never compiled from user input.
**Error & logging** — no silent `catch`; generic error response, no leaked stack trace; structured logs with sensitive fields redacted; security events logged.
**Headers & response** — CSP/HSTS/`X-Content-Type-Options` present; CORS scoped (no `*` with credentials); cookies `HttpOnly; Secure; SameSite`.
**Dependencies** — new AND inherited packages checked per §4; deprecation/discontinuation/EOL verified by a live tool query (`npm view … deprecated`, `pip-audit`, `osv-scanner`, endoflife.date), never from model memory; no deprecated/discontinued/EOL library without a logged waiver; lockfile updated.
**File & path** — uploads validated (MIME + magic bytes + size + rename); paths resolved then checked `startsWith(base)`.

## 15. Further reading

OWASP Top 10:2025 (owasp.org/Top10/2025), OWASP Cheat Sheet Series (cheatsheetseries.owasp.org), OWASP ASVS, NIST SP 800-63B rev.4, RFC 9106 (Argon2), CWE (cwe.mitre.org). Consult these when a category above needs more depth than this condensed version provides.
