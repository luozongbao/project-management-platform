# Security Audit — Findings, Prioritisation, and Triage

**Status:** Open
**Type:** Security / Hardening
**Priority:** High (umbrella) — concrete items split into issue-016..issue-020
**Affects:** every mutating endpoint in the app, the install wizard, the password-reset flow, the AJAX layer, and the response-header policy.

## Scope

A read-through security audit of the PHP sources at the v1.6.0
state (PHP 8.3 procedural, PDO with `EMULATE_PREPARES=false`,
Docker / Nginx / MariaDB 10.11). The audit covers:

- SQL injection
- Cross-site request forgery (CSRF) on every mutating endpoint
- Output escaping / cross-site scripting (XSS)
- Authentication, session handling, password storage
- Authorization / IDOR on per-record endpoints
- Account enumeration on register / forgot-password / login
- Rate limiting and abuse controls
- Response security headers (CSP, X-Frame-Options, etc.)
- Cookie / session flags (`Secure`, `HttpOnly`, `SameSite`)
- File-handling safety (`install.php` writes `config.php`,
  no file upload pipeline otherwise)
- Email / SMTP injection in `EmailService`
- `install.php` write-out of `config.php` with raw interpolation

Out of scope (already covered by other tickets): the public
client portal's read-only path, the schema, the v1.6.0
cleanup items.

## Summary of findings

| # | Finding | Severity | Where | New issue |
|---|---|---|---|---|
| 1 | `contact_edit.php` accepts unauthenticated POSTs (no CSRF) and surfaces raw PDO messages | **High** | `contact_edit.php`, `task_edit.php` (echoes `$e->getMessage()`) | [issue-016](issue-016.md) |
| 2 | `profile.php` accepts name/email/username/password changes with no CSRF | **High** | `profile.php` | [issue-017](issue-017.md) |
| 3 | `reset_password.php` has no rate limit / no per-token attempt cap; raw PDO messages logged but not surfaced (good) — but tokens never rotate on use, and a token-guessing attacker has no lockout | **Medium** | `reset_password.php`, `forgot_password.php` | [issue-018](issue-018.md) |
| 4 | `login.php` and `forgot_password.php` have no rate limiting, no lockout, and `forgot_password.php` always returns the same success message (good — no enumeration there), but `register.php` echoes a distinct "Username or email already exists" error → account enumeration | **Medium** | `register.php`, `login.php` | [issue-019](issue-019.md) |
| 5 | No security response headers (CSP, X-Frame-Options, X-Content-Type-Options, Referrer-Policy, Permissions-Policy); no `Secure` / `SameSite` cookie attributes; `startSession()` is called *after* some `echo` in auth pages | **Medium** | `includes/header.php`, `includes/functions.php`, `config.php`, `docker/nginx.conf` | [issue-020](issue-020.md) |
| 6 | `install.php` writes `config.php` with `addslashes()` and `$_SERVER['HTTP_HOST']` interpolation; only protected by a `file_exists('config.php')` check; `CREATE DATABASE … "$db_name"` uses backticks but `$db_name` is not whitelisted | **High** (on first run) | `install.php` | [issue-021](issue-021.md) |
| 7 | `task_edit.php` lets an authenticated user pick any `users.id` as the responsible person (no scoping), and any `contacts.id` from another user's project as the contact person (the contact dropdown is project-scoped on read, but the posted value is not re-validated against ownership) → IDOR | **Medium** | `task_edit.php` | [issue-022](issue-022.md) |
| 8 | `EmailService.php` interpolates `$name` (user-controlled at registration) into the HTML and plaintext bodies without escaping → reflected email content (low impact — the user controls their own name) but the reset URL embeds the *raw* token (no per-token URL signing); also no SMTP envelope hardening | **Low** | `includes/EmailService.php` | folded into [issue-016](issue-016.md) as item B |
| 9 | `client/dashboard.php?code=…` is a brute-forceable 31-bit secret (8 bits/char × 15 chars = 120 bits of entropy, but the read path reveals the project's name, scope, budget, owner name, all tasks/sub-tasks, dates, completion %, and contact names — i.e. a *guessable* code is catastrophic) | **Medium** | `client/dashboard.php`, `index.php` | [issue-023](issue-023.md) |
| 10 | `ajax/complete_task.php` returns the raw exception message (`'Error completing task: ' . $e->getMessage()`); `ajax/update_task_status.php` and `ajax/update_task_progress.php` do the same | **Low** | `ajax/*.php` | folded into [issue-016](issue-016.md) as item C |
| 11 | `dashboard.php` is reachable while logged in but does not call `requireLogin()` to set the CSRF token (intentional — view-only — but `getCurrentUser()['name']` is rendered in the welcome banner and is fed straight from the DB; mitigated by the `e()` wrapper, but worth confirming) | **Informational** | `dashboard.php` | closed, no action |
| 12 | `_smtp_test.php` is a debug script bound to the web root that exercises SMTP with hard-coded credentials; protected only by Nginx regex but a misconfiguration exposes credentials | **Low** | `_smtp_test.php` | tracked under [issue-021](issue-021.md) as item D |
| 13 | `password_reset_tokens` table does not index on `(user_id, expires_at)`; the lookup query in `forgot_password.php` is keyed on `token` only (the unique index covers it), but the cascade delete in `forgot_password.php` and the lookup in `reset_password.php` could be slow at scale | **Informational** | schema, `forgot_password.php` | closed, no action |

## Severity model used here

- **High** — exploitable today with a low-friction attack and a
  meaningful blast radius (data corruption, account takeover,
  data leak).
- **Medium** — exploitable but requires a non-trivial pre-condition
  (network position, prior enumeration, multi-step chain).
- **Low** — defence-in-depth, hardening, or information disclosure
  without a direct attack path.
- **Informational** — observation, no action.

## Triage

The seven split-out issues ([issue-016](issue-016.md) ..
[issue-023](issue-023.md)) are ordered for shipping. Item 1
(CSRF on `contact_edit.php` + raw PDO error echo) is the
biggest single win and is a one-file fix; ship it first.

| Order | Issue | Title | Why this order |
|---|---|---|---|
| 1 | [issue-016](issue-016.md) | CSRF on `contact_edit.php`, `profile.php` (also `task_edit.php`); raw PDO error echo on `contact_edit.php` / `task_edit.php` / `ajax/*`; email-content escaping in `EmailService.php` | Unauthenticated CSRF lets a third-party site write to a logged-in manager's contact book. This is the easiest high-severity fix. |
| 2 | [issue-021](issue-021.md) | `install.php` hardening — config.php writer, `$_SERVER['HTTP_HOST']` injection, `addslashes` for SQL identifiers, exposed `_smtp_test.php` | First-run risk; cheap to fix; users with an already-installed app are unaffected. |
| 3 | [issue-022](issue-022.md) | `task_edit.php` IDOR — responsible person / contact person not re-validated against the current user on POST | Privilege boundary inside an authenticated surface. |
| 4 | [issue-018](issue-018.md) | Password-reset rate limiting, per-token attempt cap, token rotation on use | Account-takeover path. |
| 5 | [issue-019](issue-019.md) | Registration / login rate limiting + registration enumeration fix (echo of "already exists" → return the same success path as a new registration, send the welcome email to a non-existent address) | Brute-force + user enumeration. |
| 6 | [issue-020](issue-020.md) | Response security headers + cookie `Secure` / `SameSite` attributes + `session_regenerate_id()` on login | Defence in depth. |
| 7 | [issue-023](issue-023.md) | Client share-code protection — rate-limit `client/dashboard.php?code=…` and `index.php` POST by IP; consider a per-code read counter with a soft lockout | Information disclosure on guess. |

The first three are the must-fixes for a v1.7.0 release. Items
4..7 are the v1.7.x hardening releases. Items folded into
issue-016 (raw PDO echo + email HTML injection) ride along with
the CSRF fix because they live in the same files.

## Cross-cutting notes (apply across issues)

- **Every mutating endpoint needs CSRF.** A spot audit (run from
  inside the repo: `grep -rEn "validateCsrfToken" *.php ajax/*.php`)
  shows the following endpoints currently have CSRF protection:
  - `project_edit.php` ✅
  - `project_delete.php` ✅
  - `ajax/parent_hold.php` ✅
  - `task_detail.php` (Hold/Resume form) ✅ (via `parent_hold.php`)

  The following do **not**:

  - `contact_edit.php` ❌
  - `task_edit.php` ❌ (it accepts POST and writes to `tasks`)
  - `profile.php` ❌ (update_profile, change_password)
  - `register.php` ❌ (acceptable — anyone can register; but a CSRF
    would force a logged-out victim to create an account under
    someone else's name, low impact)
  - `forgot_password.php` ❌ (acceptable for the same reason)
  - `reset_password.php` ❌ (acceptable — the *token* is the auth;
    but a CSRF here can still consume a token, see issue-018)
  - `login.php` ❌ (acceptable for the same reason)

  Issue-016 covers `contact_edit.php`, `task_edit.php`, and
  `profile.php`. The auth flows are intentionally left without
  CSRF — a CSRF on `register.php` lets an attacker create an
  account with attacker-controlled credentials, which is the
  same as the user registering themselves.

- **Every error path that touches a superglobal should funnel
  through `e()` / `htmlspecialchars()` before `echo`.** Audit
  finds the `<?= e($_POST['x'] ?? $row['x'] ?? '') ?>` pattern
  is consistent — good. The exceptions are
  `contact_edit.php:171` (echoes `$project_name` from a raw
  `fetchOne` without `e()` if validation fails — actually it is
  wrapped; verified) and the `install.php` form which uses
  `htmlspecialchars` directly (acceptable — no helper available
  in that file's bootstrap order). Closed: no action.

- **PDO error messages are now surfaced in the wrapped
  Exception** (v1.2.1 follow-up), which is great for the
  schema-drift case but means the application must explicitly
  choose whether to surface the message to the user. Today
  `contact_edit.php`, `task_edit.php`, and `ajax/*.php` echo
  `$e->getMessage()` directly. Fix in issue-016.

- **Nginx already blocks `config.php`, `composer.json`, etc.**
  (`docker/nginx.conf`). The list is comprehensive; no
  additions needed beyond moving `_smtp_test.php` to a path
  that is not web-reachable (issue-021 §D).

- **There is no file-upload surface in the application** (no
  avatar upload, no attachment). Closed: no action.

- **The public client portal (`client/dashboard.php`) is
  read-only and does not write to the DB.** SQL-injection risk
  in the read path is bounded by the format check
  (`/^[A-Z2-9]{5}-[A-Z2-9]{5}-[A-Z2-9]{5}$/`) and the
  parameterised query. The only realistic risk is brute-force
  guessing of the code itself, which is issue-023.

## Suggested release plan

- **v1.7.0** — issue-016, issue-021, issue-022. CSRF +
  install hardening + IDOR close. Pure security patch; no
  feature work.
- **v1.7.1** — issue-018, issue-019. Brute-force and enumeration.
- **v1.7.2** — issue-020, issue-023. Defence-in-depth.

Alternatively, bundle all seven into a single v1.7.0 if the
reviewer bandwidth is available — they are independent and
small. The audit does **not** recommend deferring any of
issues-016 / -021 / -022 past v1.7.0.

## Acceptance (per item)

See the per-issue file. Each split-out issue lists its own
acceptance criteria and regression-test plan. The umbrella
issue is closed when issues 016, 017 (folded into 016), 018,
019, 020, 021, 022, and 023 are all shipped.

## Out of scope (flagged for future tickets)

- Migrate to a framework with built-in CSRF / CSP middleware
  (e.g. Symfony, Laravel). Out of scope for v1.7.x — the
  procedural pages stay as-is.
- 2FA / WebAuthn for the internal app. Spec gap, not a v1.7
  item.
- Audit logging (who-did-what on the contact / task
  records). Spec gap.
- Replace the static `ENCRYPTION_KEY` constant with a per-install
  random key loaded from a `.env` file. (v2.0; the constant is
  generated by `install.php` already, but never *used* — it is
  reserved for future signed-token use.)
- Migrate the session store to Redis. (v2.0; the file session
  store on a single container is fine for the current install.)
- Move the public client portal behind short-lived signed URLs
  (`?code=…&exp=…&sig=…`) so a leaked code stops working after
  the manager's chosen expiry. Spec gap; issue-023 §"Future
  direction" sketches the design.
