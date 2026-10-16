# Release Notes

## v1.7.1.1 — 2026-10-16

**Align parent status with parent completion percentage.**
Closes [issue-026](docs/issues/issue-026.md): the parent row
in `tasks` now derives its `status` from its own computed
`completion_percentage` rather than from the sub-task
`status` mix. A new pure helper
`deriveParentStatusFromCompletion(?string $currentStatus, float $percentage): string`
lives next to the existing `deriveParentStatus()` in
`includes/functions.php` and is used by
`syncProjectTaskCompletions()`. The strict `all-completed`
rule from [v1.7.1](#v171--2026-10-16) /
[issue-025](docs/issues/issue-025.md) is preserved at the
leaf level and at the user-driven resume path in
`ajax/parent_hold.php` (which still calls
`deriveParentStatus()`). The two helpers are siblings, not
replacements.

### Rule

| Current `status` | `completion_percentage` | Derived `status`   |
| ---------------- | ----------------------- | ------------------ |
| `on_hold`        | any                     | `on_hold` (sticky) |
| any other        | `>= 99.5`               | `completed`        |
| any other        | `> 0` and `< 99.5`      | `in_progress`      |
| any other        | `0`                     | `not_started`      |

The 99.5 threshold matches the `round(x, 2)` granularity
`syncProjectTaskCompletions()` uses when persisting the
parent `completion_percentage`, so a parent stored as
`100.00` (after rounding) always classifies as `completed`.

### Why a new helper

The old `deriveParentStatus()` consumed the sub-task
`status` mix and answered the question "should the parent
be promoted because the children look complete?" The new
helper consumes the parent `completion_percentage` (the
same value being written to the row) and answers the
question "what status should this row carry so that
`status` and `completion_percentage` agree?" Using the
same source of truth for both fields prevents the
UAT2 regression where a parent at 1.6% progress was
classified as `not_started`.

### Files changed

- `includes/functions.php` — new helper
  `deriveParentStatusFromCompletion()` with full docblock
  referencing issue-025 and issue-026; the call site in
  `syncProjectTaskCompletions()` now passes
  `(float)$storedCompletion` to the new helper instead
  of `$subtaskStatuses` to the old one. The
  `$setCompletionDate` block, the `WHERE … AND
  (completion_percentage <> ? OR status <> ?)` guard,
  and the rest of the function body are unchanged.
- `database/task_parent_status_derived_test.php` — new
  layer-1b block with the 12 spec fixtures for
  `deriveParentStatusFromCompletion()` plus explicit
  boundary stress for `99.49` and `99.50` and an
  exhaustive `current × percentage` matrix; live-DB
  sub-test 2a extended to assert
  `completion_percentage === 0.0`; live-DB sub-test 2g
  added for the UAT2 regression (parent with 3
  sub-tasks, one at 5% and `not_started`, two at 0%
  and `not_started` → parent `in_progress` with
  percentage `> 0`); live-DB fixtures 2b and 2d
  updated to give the promoted child a non-zero
  `completion_percentage` so the parent percentage is
  also non-zero under the new contract; new layer-4
  source-grep check that the call site in
  `syncProjectTaskCompletions()` is exactly
  `deriveParentStatusFromCompletion($currentStatus, (float)$storedCompletion)`.
- `ajax/parent_hold.php`, `task_detail.php`,
  `task_edit.php`, `tasks.php`, `project_detail.php`,
  `client/dashboard.php` — **unchanged**. They all
  read `tasks.status` after the rewrite; none derive
  parent status themselves. The `resume` branch in
  `ajax/parent_hold.php` keeps calling
  `deriveParentStatus()` so the strict `all-completed`
  rule from issue-025 still applies to the user-driven
  promotion path.

### Upgrade notes

- Pure function-body + 1-line call-site change. No
  database migration. No JS / CSS / translation change.
  Pull the latest code and reload the page; no
  restart required. A parent whose `status` is now
  re-classified by the helper (e.g. `in_progress` →
  `not_started` because the recomputed percentage
  rounded to `0.00`) is updated on the next user
  interaction that triggers
  `syncProjectTaskCompletions()` (any sub-task edit,
  hold, resume, complete, or progress update). No
  back-fill script is required.
- The pre-existing 5 regression tests are unchanged
  in number and still pass; the parent-status test
  grows by 16 assertions (12 spec fixtures, 2
  boundary, 2 from the matrix stress) and 1 new
  live-DB sub-test (2g).

---

## v1.7.1 — 2026-10-16

**Parent-task status rule tightened.** Closes
[issue-025](docs/issues/issue-025.md): the
`deriveParentStatus()` helper in `includes/functions.php` now
requires **every** sub-task to be `completed` before the parent
promotes to `completed`. The previous "any sub-task completed"
rule caused the project overview to mark a parent as done while
a third of the work was still untouched (e.g. sub-tasks
`['completed', 'not_started']` previously classified the parent
as `completed`; it is now `not_started`).

No DB / schema / AJAX / JS / CSS / translation changes. The fix
is a single PHP function body and a corresponding update to the
regression-test fixtures in
`database/task_parent_status_derived_test.php`. A parent that
was previously classified as `completed` under the loose rule
and still has at least one `not_started` sub-task is
re-classified to `not_started` automatically on the next
`syncProjectTaskCompletions()` call (any sub-task edit, hold,
resume, complete, or progress update). No back-fill script is
required.

### Behavioural diff

| Sub-task statuses                        | v1.7.0 (loose) | **v1.7.1 (strict, this release)** |
| ---------------------------------------- | -------------- | --------------------------------- |
| `['completed', 'completed']`             | `completed`    | `completed` (unchanged)           |
| `['completed', 'not_started']`           | `completed`    | **`not_started`** (fixed)         |
| `['completed', 'on_hold']`               | `completed`    | **`not_started`** (fixed)         |
| `['completed', 'completed', 'not_started']` | `completed` | **`not_started`** (fixed)         |
| `['in_progress', 'not_started']`         | `in_progress`  | `in_progress` (unchanged)         |
| `['in_progress', 'completed']`           | `in_progress`  | `in_progress` (unchanged)         |
| `['not_started', 'not_started']`         | `not_started`  | `not_started` (unchanged)         |
| `[]` (no sub-tasks)                      | `not_started`  | `not_started` (unchanged)         |
| current = `on_hold`, any children         | `on_hold`      | `on_hold` (sticky, unchanged)     |

### Files changed

- `includes/functions.php` — `deriveParentStatus()` body
  changed from the loose "any child completed" rule to the
  strict "all children completed" rule. The `on_hold`
  stickiness, the `in_progress` rule, the empty-children
  rule, and the valid-enum guarantee are all unchanged.
  Docblock updated to reference both issue-012 and
  issue-025.
- `database/task_parent_status_derived_test.php` — four
  pure-function fixtures updated to codify the strict rule
  (including the two new "mixed" cases
  `['completed', 'on_hold']` and
  `['completed', 'completed', 'not_started']`); the 2f
  live-DB assertion updated to expect `'not_started'`
  instead of `'completed'` when one child remains
  `not_started`. The exhaustive 4×4 matrix block and the
  source-grep block are unchanged.

### Upgrade notes

- Pure function-body change. No database migration. No JS /
  CSS / translation change. Pull the latest code and reload
  the page; no restart required. A parent that was previously
  classified as `completed` under the loose rule and still
  has at least one `not_started` sub-task is automatically
  re-classified to `not_started` on the next user interaction
  that triggers `syncProjectTaskCompletions()` (any sub-task
  edit, hold, resume, complete, or progress update).

---

## v1.7.0 — 2026-10-16

**Security + bugfix bundle.** Closes the v1.6.0 security audit
([issue-015](docs/issues/issue-015.md)) and the password-reset
redirect loop ([issue-024](docs/issues/issue-024.md)). No new
features. Pull, rebuild the `app` container
(`docker compose up -d --build app`), reload — no DB rebuild
required. Two new additive migrations ship and apply on first
DB access (idempotent guards).

### Security hardening (issue-015 umbrella, 016–023)

A full read-through audit of every PHP source found thirteen
distinct findings, triaged into the seven split-out issues
below. The umbrella [issue-015](docs/issues/issue-015.md) is the
authoritative index; the split-out files own the per-finding
fix.

#### CSRF on every mutating internal endpoint — [issue-016](docs/issues/issue-016.md) (High)

`contact_edit.php`, `task_edit.php`, and `profile.php` now
require a CSRF token on every POST, mirroring the pattern that
was already in `project_edit.php` / `project_delete.php`. The
token is rendered via `getCsrfToken()` in
`includes/functions.php` and validated with
`validateCsrfToken()` at the very top of the POST branch,
before any other input is read. Both the create and change-password
forms in `profile.php` carry the hidden input.

In the same commit, three adjacent defects ride along:

- **Raw PDO error messages no longer echo to the user** in
  `contact_edit.php`, `task_edit.php`, or any of the four
  `ajax/*.php` endpoints. The verbose message still lands in
  `error_log` for the developer; the user sees a generic
  "Failed to save. Please try again." string.
- **`EmailService.php` HTML- and plaintext-escapes `$name`** in
  both the welcome and the password-reset email templates
  (HTML: `htmlspecialchars`; plaintext: control-character strip
  + truncation). No more reflected content in user-controlled
  email fields.

The intentional no-CSRF endpoints (`login.php`, `register.php`,
`forgot_password.php`) are not changed; a CSRF on
`register.php` would be indistinguishable from the victim
registering themselves.

#### `install.php` hardening + `_smtp_test.php` web-root removal — [issue-021](docs/issues/issue-021.md) (High on first run)

- The `db_name` field is now strictly validated against
  `/^[A-Za-z0-9_]+$/` before any DB or file work.
- `config.php` is written via `var_export()` instead of
  `addslashes()`, eliminating the fragile single-quoted-PHP-
  string-literal path that was safe only by accident.
- `APP_URL` is derived from `$_SERVER['SERVER_NAME']` (set by
  Nginx from `server_name`, not user-controllable) instead of
  the attacker-controllable `HTTP_HOST` header.
- The wizard has a CSRF token at every step, a two-stage gate
  (`config.php` exists **or** the `users` table is present →
  redirect to `index.php`), and a `session_start()` at the
  top of the file so the token persists across the three
  install steps.
- `_smtp_test.php` is moved out of the web root to
  `docker/_smtp_test.php`. The Nginx deny regex entry for the
  file is no longer needed and is removed. Invoke via
  `docker exec -it pmp-app php
  /var/www/html/docker/_smtp_test.php` from the CLI only.

#### `task_edit.php` IDOR re-validation — [issue-022](docs/issues/issue-022.md) (Medium)

The POST handler in `task_edit.php` now re-validates
`responsible_person_id`, `contact_person_id`, and `project_id`
against the current user's project set instead of trusting the
posted value's existence alone. The dropdown rendering matches
the new policy so the UI never offers a value the server will
reject. Closes the "any user / any contact" assignment hole.

#### Password-reset brute-force protection — [issue-018](docs/issues/issue-018.md) (Medium)

- New `password_reset_attempts` table (additive migration
  `20261015_add_password_reset_attempts.sql`).
- New helpers in `includes/functions.php`:
  `recordPasswordResetAttempt()` and
  `isPasswordResetRateLimited()`. Thresholds: 5 requests per
  15 min per (IP, action) tuple, with a hard lockout on
  overflow. The "success" record clears the per-(IP, action)
  counter immediately.
- `reset_password.php` rotates the *consumed* token on every
  successful POST via the existing
  `invalidateAllPasswordResetTokensForUser` helper (so a
  bookmarked URL stops working the moment a new password is
  set), and exposes the same generic "Invalid or expired reset
  link" copy for rate-limited, invalid, and expired tokens
  (indistinguishable to a network-position attacker).
- `forgot_password.php` and `reset_password.php` both call the
  new helpers; `PASSWORD_RESET_REQUEST_LIMIT`,
  `PASSWORD_RESET_REQUEST_WINDOW`,
  `PASSWORD_RESET_CONSUME_LIMIT`, and
  `PASSWORD_RESET_CONSUME_WINDOW` are tunable via `config.php`.
- The `consume` logic is extracted into a pure helper
  (`consumePasswordResetToken()`) for testability.

#### Login + registration brute-force / enumeration — [issue-019](docs/issues/issue-019.md) (Medium)

- New `login_attempts` table (additive migration
  `20261015_add_login_attempts.sql`).
- New helpers: `recordLoginAttempt()` and
  `isLoginRateLimited()`. Thresholds: 5 failures per 15 min
  per (IP, username) tuple; 30 failures per 15 min per IP
  alone. A successful login clears the per-(IP, username)
  counter.
- `register.php` no longer echoes the "Username or email
  already exists" string on the duplicate branch. It renders
  the **same** success card as a fresh registration, with no
  email sent and no DB row written. The two response bodies
  are byte-identical (modulo the CSRF token), so account
  enumeration via the registration form is closed.
- Tunable via `config.php`: `LOGIN_FAILURE_LIMIT`,
  `LOGIN_FAILURE_WINDOW`, `LOGIN_IP_LIMIT`, `LOGIN_IP_WINDOW`.

#### Response security headers + session hardening — [issue-020](docs/issues/issue-020.md) (Medium)

- New `includes/security_headers.php` helper, required from
  `includes/header.php` (internal app) and from
  `client/dashboard.php` + `index.php` (public portal). It
  sends `Content-Security-Policy`, `X-Frame-Options: DENY`,
  `X-Content-Type-Options: nosniff`, `Referrer-Policy:
  strict-origin-when-cross-origin`, and a minimal
  `Permissions-Policy` on every response. The internal-app CSP
  permits `'unsafe-inline'` for `script-src` and `style-src`
  pending the v2.0 nonce migration; the public portal's CSP
  omits `'unsafe-inline'` for `script-src`.
- `startSession()` in `includes/functions.php` now pins
  `session.cookie_*` via `session_set_cookie_params()` with
  `HttpOnly=true` and `SameSite=Lax`. `Secure` is set
  dynamically from `$_SERVER['HTTPS']`.
- `session_regenerate_id(true)` is called on every successful
  `login.php` POST and on every successful
  `reset_password.php` POST, closing the session-fixation
  vector.
- `docker/nginx.conf` adds the three simple headers
  (`X-Frame-Options`, `X-Content-Type-Options`,
  `Referrer-Policy`) at the server level so static-asset
  responses are covered too.

#### Client share-code brute-force protection — [issue-023](docs/issues/issue-023.md) (Medium)

- New `client_share_code_attempts` table (additive migration
  `20261016_add_client_share_code_attempts.sql`).
- New `share_code_rotated_at TIMESTAMP NULL` column on
  `projects` (additive migration
  `20261016_add_projects_share_code_rotated_at.sql`).
  Backfill populates `NOW()` for every existing row with a
  non-null `share_code`.
- New helpers: `recordShareCodeAttempt()` and
  `isShareCodeRateLimited()`. Thresholds: 20 attempts per
  5 min per IP; 5 distinct failed codes per 5 min per IP; a
  success clears the per-IP counter.
- `client/dashboard.php` applies the limit at the top of the
  page (429 + "Too many requests" copy on overflow).
- `index.php` "Find by Code" form records the attempt and
  applies the same limit before redirecting to
  `client/dashboard.php?code=…`.
- `project_edit.php` now shows the
  `share_code_rotated_at` value and a "Rotate code" button
  that regenerates the share code and bumps the rotated-at
  timestamp. The previous URL returns 404 on the next visit;
  a confirmation dialog warns the manager before the
  rotation. The CSRF token from issue-016 covers the action.
- An "open count (last 30 days)" badge on the same page
  surfaces how many successful reads the code has had, with
  no PII (no IP, no UA) leaked to the manager.

### Bug fix — password-reset redirect loop (issue-024)

[issue-024](docs/issues/issue-024.md) ships in the same bundle
as the security work because the regression-test fixture
(`database/password_reset_attempts_test.php` §8) directly
encodes the broken behavior as a negative assertion. Shipping
the two together means the test net is self-consistent.

**The fix:** the `rotatePasswordResetToken()` + 302 pattern
that was added in v1.8.0 to defend against URL leakage is
removed. The pattern was tautological — the rotation function
always returns a fresh token, so the redirect always fired,
and the next request rotated again, etc. The user experienced
this as either `ERR_TOO_MANY_REDIRECTS` (Chrome / Firefox) or
a cached "Invalid Reset Link" page (Safari).

The correct defense against URL leakage is **bulk
invalidation on the successful POST**, which the existing
`invalidateAllPasswordResetTokensForUser` helper already
provides. The token in the URL is now stable for the entire
form session; the user submits a new password → all live
tokens for the user are deleted; a bookmarked URL no longer
works.

The rate-limit check on the GET stays in place. It reuses the
same "Invalid or expired reset link" string so a network-
position attacker cannot distinguish "rate limited" from
"bad token" from "expired token".

`database/reset_password_redirect_loop_test.php` is new: 12
sections, 37 assertions, including a "would have caught the
v1.8.0 bug" assertion that 11 of the 37 fail if the old
rotation code is restored.

### Files changed

**New files**

- `includes/security_headers.php` — `sendSecurityHeaders()`
  helper.
- `database/migrations/20261015_add_password_reset_attempts.sql`
- `database/migrations/20261015_add_login_attempts.sql`
- `database/migrations/20261016_add_client_share_code_attempts.sql`
- `database/migrations/20261016_add_projects_share_code_rotated_at.sql`
- `database/security_issue016_test.php` — CSRF + raw-PDO +
  email-HTML regression.
- `database/password_reset_attempts_test.php` — issue-018
  rate-limit + token-rotation regression.
- `database/login_register_protection_test.php` — issue-019
  rate-limit + enumeration regression.
- `database/security_headers_test.php` — issue-020 source-grep
  + live-curl regression.
- `database/install_wizard_test.php` — issue-021 strict-name
  regex + `var_export` + `_smtp_test.php` relocation regression.
- `database/task_edit_idor_test.php` — issue-022 IDOR
  regression.
- `database/client_share_code_protection_test.php` — issue-023
  rate-limit + `share_code_rotated_at` backfill regression.
- `database/reset_password_redirect_loop_test.php` — issue-024
  redirect-loop regression (12 sections, 37 assertions).
- `docker/_smtp_test.php` — moved out of the web root; the
  original `_smtp_test.php` is deleted.

**Modified files**

- `contact_edit.php`, `task_edit.php`, `profile.php` —
  CSRF gate + hidden input + generic DB-error copy
  (issue-016).
- `ajax/complete_task.php`, `ajax/update_task_status.php`,
  `ajax/update_task_progress.php`,
  `ajax/get_project_contacts.php` — generic error copy
  (issue-016).
- `includes/EmailService.php` — `htmlspecialchars` /
  control-char-strip on `$name` in both templates + AltBody
  (issue-016).
- `install.php` — strict `db_name` regex, `var_export` config
  writer, `SERVER_NAME`-based URL, CSRF, two-stage gate
  (issue-021).
- `reset_password.php` — remove rotate-on-render + 302;
  rate-limit check stays; bulk-invalidation on success stays
  (issue-018 + issue-024).
- `forgot_password.php` — rate-limit gate (issue-018).
- `login.php` — rate-limit gate + `session_regenerate_id(true)`
  (issue-019 + issue-020).
- `register.php` — enumeration fix: "already exists" branch
  renders the same success card with no DB write / no email
  (issue-019).
- `includes/functions.php` — `startSession()` cookie flags;
  `recordPasswordResetAttempt` / `isPasswordResetRateLimited`;
  `consumePasswordResetToken`; `recordLoginAttempt` /
  `isLoginRateLimited`; `recordShareCodeAttempt` /
  `isShareCodeRateLimited`.
- `client/dashboard.php` — rate-limit gate at the top.
- `index.php` — rate-limit gate on the "Find by Code" POST.
- `project_edit.php` — `share_code_rotated_at` display +
  "Rotate code" action.
- `includes/header.php` — `require_once security_headers.php`
  and call `sendSecurityHeaders()`.
- `docker/nginx.conf` — three complement headers
  (`X-Frame-Options`, `X-Content-Type-Options`,
  `Referrer-Policy`).
- `config.php` — twelve new constants
  (`PASSWORD_RESET_REQUEST_LIMIT`,
  `PASSWORD_RESET_REQUEST_WINDOW`,
  `PASSWORD_RESET_CONSUME_LIMIT`,
  `PASSWORD_RESET_CONSUME_WINDOW`, `LOGIN_FAILURE_LIMIT`,
  `LOGIN_FAILURE_WINDOW`, `LOGIN_IP_LIMIT`,
  `LOGIN_IP_WINDOW`, `SHARE_CODE_IP_LIMIT`,
  `SHARE_CODE_IP_WINDOW`, `SHARE_CODE_DISTINCT_LIMIT`).
- `database/schema.sql` — add the four new tables / columns
  for fresh installs.

**Removed**

- `_smtp_test.php` — relocated to `docker/_smtp_test.php`.

### Upgrade notes

- **Pull and rebuild the `app` container** (`docker compose
  up -d --build app`). The two new migrations are
  idempotent (guarded via `information_schema`) and apply
  on the first `Database` connect — no manual SQL to run.
- **No DB rebuild required.** Existing rows are preserved;
  the `share_code_rotated_at` backfill is part of the
  migration.
- **Existing sessions are unaffected.** The session-cookie
  flag change in `startSession()` applies on the next
  session start; no one is logged out by the upgrade.
- **No app restart required** beyond the `app` container
  rebuild, which is the standard PHP-FPM reload.
- **HTTPS deployments only** get the `Secure` cookie flag
  automatically (derived from `$_SERVER['HTTPS']`). HTTP
  local-dev is unchanged.

### Reference

- [docs/issues/issue-015.md](docs/issues/issue-015.md) — the
  audit + triage.
- [docs/issues/issue-016.md](docs/issues/issue-016.md) — CSRF
  + raw PDO + email HTML.
- [docs/issues/issue-018.md](docs/issues/issue-018.md) —
  password-reset rate limiting.
- [docs/issues/issue-019.md](docs/issues/issue-019.md) — login
  + registration rate limiting / enumeration.
- [docs/issues/issue-020.md](docs/issues/issue-020.md) — CSP +
  cookie flags + session hardening.
- [docs/issues/issue-021.md](docs/issues/issue-021.md) —
  `install.php` + `_smtp_test.php`.
- [docs/issues/issue-022.md](docs/issues/issue-022.md) —
  `task_edit.php` IDOR.
- [docs/issues/issue-023.md](docs/issues/issue-023.md) —
  client share-code brute-force.
- [docs/issues/issue-024.md](docs/issues/issue-024.md) —
  password-reset redirect loop.

---

## v1.5.1 — 2026-10-13

UI layout fixes for the v1.5.0 `task_edit.php` / `task_detail.php`
surface, tracked as `docs/issues/issue-013.md` (revised 2026-10-12).
No DB / schema / JS / translation changes. Two CSS bugs fixed,
plus three earlier structural items confirmed already in place:

**Bugs fixed (revised scope, 2026-10-12)**

- **`task_detail.php` "Put on Hold" / "Resume" button falls onto
  its own row.** `.page-actions` had no rule in
  `assets/css/style.css`, so it defaulted to `display: block` and
  the Hold / Resume `<form class="inline-form">` (which was
  correctly nested inside `<div class="page-actions">`) wrapped
  below the other action buttons. Added a `.page-actions` rule
  (`display: flex; flex-wrap: wrap; align-items: center; gap: 10px;
  justify-content: flex-end`) so all action controls sit on one
  row. Also added a `.page-header` rule (flex row with
  `justify-content: space-between`) so the page title and the
  action row lay out correctly on the same baseline.
- **`task_edit.php` "Use sum of sub-task hours" checkbox and
  its text label render on separate lines.** `.checkbox-label`
  had no rule in `assets/css/style.css`, so the default block-flow
  put the input and the `<span>` on two separate lines. Added a
  `.checkbox-label` rule (`display: flex; align-items: center;
  gap: 8px; cursor: pointer;`) and pinned the touch target to
  `18px × 18px` on `.checkbox-label input[type="checkbox"]` for
  WCAG 2.5.5 (target size, sufficient).
- **Mobile breakpoint extended.** The existing
  `@media (max-width: 768px)` block now also collapses
  `.page-header` to a column (`flex-direction: column;
  align-items: stretch;`) and stretches the action buttons to
  full width, so the action row remains reachable with a thumb
  on a phone.

**Background (already in place from the original 2026-10-12 spec,
unchanged in this revised scope)**

- `task_edit.php` two-column `.form-grid` layout defined in
  `assets/css/style.css`, with the Dates section marked
  `class="form-section full-row"` so it spans both columns.
- `task_edit.php` top-level `$effective_inherit = false`
  initialisation silences the "Undefined variable" PHP warning
  on a brand-new task.
- `task_detail.php` Hold / Resume `<form class="inline-form">`
  is rendered inside the `<div class="page-actions">` row
  (the structural position was already correct; the v1.5.1 fix
  is the new `.page-actions` flex rule above).

### Regression coverage

- `database/task_layout_issue013_test.php` — a structural
  regression test that source-greps the new CSS rules in
  `assets/css/style.css` (`.form-grid`, `.form-section`,
  `.estimate-input`, `.page-header`, `.page-actions`,
  `.checkbox-label` + 18x18 touch target, plus the
  `@media (max-width: 768px)` mobile collapse for
  `.page-header` and `.form-grid`), asserts that the
  `$effective_inherit` initialisation appears before the form
  HTML in `task_edit.php`, asserts the
  `class="form-section full-row"` is present on the Dates
  section, asserts the Hold / Resume form is positioned inside
  `<div class="page-actions">` in `task_detail.php`, and lints
  both modified PHP files. Includes a self-check that the test
  catches a regression by re-introducing the bug (the
  `.checkbox-label` rules are stripped, the test fails, the
  rules are restored, the test passes again).

### Files changed

- `assets/css/style.css` — added `.form-grid`,
  `.form-grid .form-section`, `.form-grid .form-section.full-row`,
  `.form-grid .form-section h3`, `.estimate-input` rules
  (original scope) plus `.page-header`, `.page-header .page-title`,
  `.page-header .page-actions`, `.page-actions`,
  `.checkbox-label`, `.checkbox-label input[type="checkbox"]`,
  `.checkbox-label > span` rules and a matching mobile
  breakpoint (revised scope).
- `task_edit.php` — top-level `$effective_inherit = false`
  initialisation, re-derivation inside the editing branch,
  `class="form-section full-row"` on the Dates section,
  `<label class="checkbox-label" for="inherit_subtask_hours">`
  on the inherit-mode checkbox.
- `database/task_layout_issue013_test.php` — new regression
  test, extended for the revised scope.

### Upgrade notes

- Pure markup / CSS change. No database migration. No JS file
  change. Pull the latest code and reload the page; no restart
  required.

---

## v1.5.0 — 2026-10-12

Parent-task model upgrade. Adds an **inherit mode** for a parent
task's effort so the user no longer has to keep the parent in sync
with the sum of its sub-tasks' estimates by hand, and makes a
parent's **status** derive from its sub-tasks so the project
rollup, the task list, and the client portal all agree on what a
parent is doing. The two changes share the same parent surface
(`task_edit.php` / `task_detail.php`) and are released together so
the validation rules and the display wiring land in a single
coordinated change. One small additive migration. No breaking API
changes.

### v1.5.0 — Tester follow-ups (added 2026-10-12)

Two tester rounds (issue-010 and issue-011) returned with bug
reports against the v1.4.1 / v1.5.0 surface. All reported bugs are
fixed in this release; the fixes do not change the public
behaviour of the inherited mode, but they do tighten the
display contract so the user always sees a meaningful label
rather than an empty cell.

- **Issue-011 tester-note-001 bug 1 — "Not estimated (no sub-task
  estimates)" copy on all four display surfaces.** When a parent
  is in inherit mode and **all** of its sub-task estimates are
  `NULL` / `0` / negative, all four display surfaces now render
  the literal "Not estimated (no sub-task estimates)" label in
  place of the previously hidden / mismatched copy:
  - `task_detail.php` (parent block): the previous yellow
    warning banner that conflated "no estimate" with
    "completion will use equal-average fallback" has been
    removed; the same span is now reused with the spec
    literal.
  - `tasks.php` and `project_detail.php`: the previously
    hidden `Total Time` row now renders the spec literal in
    the same `<span class="task-estimate">` element so the
    row's column alignment is preserved.
  - `client/dashboard.php` (read-only public surface): the
    spec literal is rendered via the new translation key
    `dash.not_estimated_parent`, defined in both EN and ZH
    in `client/lang.php`. EN value matches the internal-app
    literal verbatim; ZH value: "暂未估算(子任务均未填写工时)".
  - Regression test extension: `database/task_inherit_subtask_hours_test.php`
    now source-greps all four surfaces for the spec literal /
    the `t('dash.not_estimated_parent')` call, source-greps
    `client/lang.php` for the new key in both EN and ZH
    dictionaries, and live-renders the unauthenticated client
    dashboard to confirm the translated string reaches the
    served HTML.
- **Issue-010 tester-note-001 — three small bugs in the sub-tasks
  toggle.** All three were fixed against the v1.4.1 surface and
  are listed here so the v1.5.0 changelog captures them.
  - **Bug 1 (a11y):** `client/dashboard.php` `applyState()`
    selector was `section.querySelectorAll('ul.task-tree')`,
    which matched the root `<ul class="task-tree root">` and
    flipped its `aria-hidden`, hiding the top-level workstream
    names from screen readers in the default "hidden" state.
    Selector scoped to `ul.task-tree:not(.root)` to mirror the
    CSS rule.
  - **Bug 2 (test coverage):** `client/toggle_subtasks_test.php`
    only asserted the literal `aria-hidden` substring and
    silently accepted the buggy selector. New assertion
    `strpos($with_subs_html, 'ul.task-tree:not(.root)')` locks
    the fix in.
  - **Bug 3 (dead code):** the test's cleanup section
    attempted to restore the old `subtask_functions.sql`
    triggers via `shell_exec('mariadb … < $tmp')`. The
    `pmp-app` container has no `mariadb` client, so the call
    was a silent no-op. The triggers are also no longer used
    by the app (parent completion is now computed in PHP via
    `calculateTaskCompletionMap()`), so the entire restore
    block was removed and replaced with a `SELECT 1` DB
    connectivity sanity check.

### Highlights

- **Parent effort inherit mode (issue-011)**
  - New nullable column `tasks.inherit_subtask_hours TINYINT(1) NULL`
    (default `NULL`; existing rows are unchanged). The flag is only
    surfaced on the edit form when the task already has at least
    one sub-task — the parent-must-be-created-first invariant from
    the original issue brief is preserved.
  - New helper `resolveTaskEstimateHours(?array $task, array $childTasks)`
    in `includes/functions.php` is the **single source of truth**
    consumed by all four display surfaces:
    - When `inherit_subtask_hours = 1`, the function returns the
      sum of the children's positive `estimated_hours` (zero and
      NULL child estimates are excluded), or `NULL` if no child has
      a positive estimate.
    - When the flag is `0` or `NULL`, the function returns the
      stored `estimated_hours` (with the existing null / zero /
      negative guards) so manual-mode parents behave exactly as
      they did before this release.
  - The task edit form (`task_edit.php`) renders a new checkbox
    **"Use sum of sub-task hours as total task time"** (EN) /
    inline equivalent on the internal app. When ticked, the
    `Total Task Time` numeric input is disabled, an inline script
    toggles the disabled state on click, and a read-only hint
    shows the current live sum. A **server-side defence** forces
    `estimated_hours = NULL` on save whenever the flag is `1`, so
    even a hand-crafted POST cannot leave a stale manual value in
    the row.
  - Validation: editing a parent that has sub-tasks with the flag
    off and an empty / zero / non-positive `Total Task Time` now
    fails with the existing error-pattern:
    *"Tasks with sub-tasks must specify a non-zero total task time,
    or check 'Use sum of sub-task hours'."* — the user is
    steered to either pick a number or tick the box.
  - All four display surfaces now agree on the number:
    - `task_detail.php` (parent block)
    - `tasks.php` (list view, N+1-safe — child estimates are
      loaded in a single follow-up query for parents with the
      flag set, not row-by-row)
    - `project_detail.php` (the project-level task table now
      shows a `Total Time` cell that was previously missing
      entirely)
    - `client/dashboard.php` (read-only client portal, the
      number alone — no inherit-mode indicator on the public
      surface per the issue spec)
  - Inherit-mode rows render a small `fa-link` icon next to the
    number in the internal console so the manager can see at a
    glance that the value is computed, not typed. If a parent
    is in inherit mode but none of its children have positive
    estimates, `task_detail.php` shows an `alert alert-warning`
    banner so the manager is not silently left with a missing
    number.
  - Migration: `database/migrations/20261012_add_task_inherit_subtask_hours.sql`
    (idempotent, guarded by `information_schema.COLUMNS`). Fresh
    installs are covered by the matching `database/schema.sql`
    update. Existing rows are untouched (the column is `NULL`
    by default and `NULL` is treated as "manual mode").
  - Regression test: `database/task_inherit_subtask_hours_test.php`
    asserts the column exists in the right slot of the table,
    exercises `resolveTaskEstimateHours()` against twelve pure-function
    cases (inherit, manual, mixed-child, rounding, zero / null /
    negative children), and runs a live DB round-trip that
    toggles the flag and confirms the stored `estimated_hours`
    is forced `NULL` on save. Source-grep checks confirm all
    four display surfaces call the helper, the form binds the
    field, the migration declares the column with the
    information_schema guard, and `schema.sql` declares the
    column for fresh installs.

- **Parent status derived from sub-tasks (issue-012)**
  - `deriveParentStatus(?string $currentStatus, array $childStatuses)`
    in `includes/functions.php` is the **single source of truth**
    for parent → child status rollup, consumed by both the
    server-side `syncProjectTaskCompletions()` extension and the
    new `ajax/parent_hold.php` endpoint:
    - **on_hold is sticky** — once a parent is held, subsequent
      sub-task changes do **not** auto-resume it; the manager
      must explicitly press *Resume* on the task detail page.
    - Otherwise, the derived status is: any child `in_progress`
      → `in_progress`; otherwise any child `completed` →
      `completed`; otherwise `not_started`. (The implementation
      follows the exact spec the issue brief documents; the rule
      is documented and unit-tested.)
  - `syncProjectTaskCompletions()` now also re-derives the
    parent's `status` and aligns `completion_date`:
    - On transition to `completed`, `completion_date` is set to
      `COALESCE(completion_date, NOW())` so a completed parent
      never has a missing completion date.
    - On demotion **from** `completed`, `completion_date` is
      cleared to `NULL` so a re-opened parent does not keep
      stale "completed" history.
    - A `WHERE completion_percentage <> ? OR status <> ?` guard
      keeps `updated_at` honest when the helper is a no-op.
  - `task_detail.php` shows a small **"Auto"** pill (`fa-magic`,
    `.derived-pill`) next to the status badge for any task that
    has sub-tasks — visual cue that the value is derived, not
    typed. The pill is suppressed on the client portal.
  - `task_edit.php` now renders the **status** field as a
    **read-only** badge for any task that has sub-tasks (the
    dropdown is replaced with the current status label and an
    explanation text: *"This task has sub-tasks, so its status
    is derived automatically. To put it on hold, use the 'On
    Hold' action on the task detail page."*). The submitted
    `status` value is **ignored** for parents with sub-tasks
    (server-side defence) so hand-crafted POSTs cannot desync
    the rollup.
  - New endpoint `ajax/parent_hold.php` is the only legitimate
    path that mutates a parent's status while it has children:
    - `action=hold` sets `tasks.status = 'on_hold'`.
    - `action=resume` re-derives the status from the current
      sub-task statuses via `deriveParentStatus(null, …)` and
      updates `completion_date` in the same way
      `syncProjectTaskCompletions()` does.
    - Both paths call `syncProjectTaskCompletions($db, $projectId)`
      at the end so the project's rollup, the task list, and the
      client portal are all updated atomically.
    - CSRF, login, ownership (project owner), and parent-with-
      children constraints are enforced. Errors return
      `400` / `403` / `404` / `405` as appropriate. The
      endpoint detects `X-Requested-With: XMLHttpRequest` and
      returns JSON; otherwise it redirects back to the task
      detail page so the buttons can be plain form submits.
  - `ajax/complete_task.php` now calls `syncProjectTaskCompletions()`
    directly in both branches (sub-task and top-level). The
    helper extension makes the old local `updateParentTaskCompletion()`
    wrapper redundant, so it was removed in the same change to
    keep a single source of truth.
  - Regression test: `database/task_parent_status_derived_test.php`
    exercises `deriveParentStatus()` against twelve pure-function
    cases plus an exhaustive 4×4 current × child matrix, runs a
    live DB round-trip that exercises: (a) all-not-started →
    not_started, (b) one in_progress child → in_progress, (c)
    all children completed → completed with `completion_date`
    set, (d) demotion to in_progress clears `completion_date`,
    (e) on_hold stickiness across `syncProjectTaskCompletions()`,
    (f) resume derivation. Source-grep checks confirm
    `ajax/parent_hold.php` calls the right helpers,
    `task_detail.php` renders the pill and the buttons, and
    `includes/functions.php` exports both new helpers.

## v1.4.1 — 2026-10-12

Client-portal-only feature release. Adds a sub-tasks toggle to the
public client dashboard so a stakeholder can collapse or expand the
nested sub-trees under each top-level workstream without scrolling
through a wall of sub-tasks. No DB changes, no migrations, no new
endpoints. The state is stored per device in `localStorage`.

### Highlights

- **Sub-tasks toggle on the client dashboard (issue-010)**
  - Inline button rendered next to the "Tasks & Progress" heading on
    `client/dashboard.php`. Label flips between **"Show all
    sub-tasks"** (EN) / **"显示所有子任务"** (中文) and **"Show
    top-level only"** (EN) / **"仅显示顶级任务"** (中文); the icon
    flips between `fa-eye` and `fa-eye-slash`. The control is hidden
    entirely on projects that have no sub-tasks — nothing to
    collapse, so the control would be misleading.
  - **Default state is "top-level only"** for first-time visitors and
    for any project that has sub-tasks. The nested sub-trees are
    visually hidden via a scoped CSS rule, not by re-rendering
    server-side; the page HTML is the same on every load and only
    the section's `data-subtasks` attribute + the button's
    `aria-pressed` differ.
  - The user's choice is persisted in `localStorage` under
    `client_dash.subtasks_visible.<share_code>`. Returning visitors
    who previously chose "expanded" see the full tree on first
    paint with no flash of collapsed content (the inline script
    runs at the end of `<body>`, before the browser commits the
    first frame). Each project has its own key, so two projects
    keep two separate preferences.
  - When `localStorage` is unavailable (private mode, sandboxed
    iframe, JS disabled), the page still defaults to "top-level
    only" and the toggle remains visible but its click is a safe
    no-op.
  - Accessibility: the control is a real `<button type="button">`
    with `aria-pressed`, `aria-controls="tasks-progress"`, and an
    `aria-label`; nested `<ul class="task-tree">` carry
    `aria-hidden="true"` while collapsed so screen readers skip
    the subtree. The icon + text combination means there is no
    reliance on colour alone.
  - Purely client-side: no new DB columns, no new SQL, no new
    endpoints. The existing `render_task_tree()` is untouched;
    only the wrapper section's id, the new button, the localised
    labels, the inline script, and one scoped CSS rule
    (`#tasks-progress[data-subtasks="hidden"] ul.task-tree:not(.root)`)
    are added.
  - Share code is interpolated into the inline script via
    `json_encode(..., JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS |
    JSON_HEX_QUOT)` so a future change to the share-code character
    set cannot break the page.

### Localisation

Three new keys added to `client_translations()` in `client/lang.php`
for both English and Simplified Chinese:

| Key                          | EN                       | 中文                   |
| ---------------------------- | ------------------------ | ---------------------- |
| `dash.subtasks_show_all`     | `Show all sub-tasks`     | `显示所有子任务`        |
| `dash.subtasks_show_top`     | `Show top-level only`    | `仅显示顶级任务`        |
| `dash.subtasks_toggle_aria`  | `Toggle sub-tasks visibility` | `切换子任务显示`   |

### Upgrade notes

This release has **no database change** and **no migration**. To
deploy:

1. Pull the latest code. The change is server-rendered HTML + one
   inline `<script>` + one CSS rule, so no rebuild is needed.
2. (Optional) Run the new regression test inside the app container:
   ```bash
   docker compose exec app php client/toggle_subtasks_test.php
   ```
3. Clear any CDN / reverse-proxy cache for `client/dashboard.php`
   and `client/assets/client.css` so visitors see the new control
   on the next page load.

### Out of scope

- Per-task accordion (collapsing individual sub-trees independently).
- An "Expand all / Collapse all" pair of controls. The single toggle
  is sufficient for the use case.
- Persisting the choice across devices or browsers. This is a
  per-device preference, like the existing `client_lang` cookie.
- Surfacing the toggle on the internal console's task pages.
- A server-side query-parameter override (e.g. `?subtasks=1`).

---

## v1.2.1 — 2026-10-06

Bundles the planned **v1.3**, **v1.3.1**, and **v1.4** work-items into a
single **v1.2.1** release (the next patch slot after v1.2.0) because
they all touch the same project + contact surface and the same DB
shape.

- Originally planned as **v1.3**: optional **Project Start Date**
  (issue-008).
- Originally planned as **v1.3.1**: a small patch on top —
  **dashboard "New Project" CTA target** + **CSRF hardening** on the
  project create / edit POST handler (QA findings against the v1.3
  draft, documented in
  `docs/issues/issue-008/tester-note-001.md`).
- Originally planned as **v1.4**: ships the missing `contacts`
  columns (**company**, **position**, **address**) so the contact
  create / edit form stops crashing, and makes the underlying PDO
  error visible in the next schema-drift bug (issue-009).

All three shipped together under the **v1.2.1** tag.

### Highlights

- **Project Start Date (issue-008, originally planned as v1.3)**
  - New optional `projects.start_date DATE` column, distinct from
    `created_at`. The manager decides when work on the project
    actually began; the database row's insertion time is no longer
    mis-reported as the project's start date.
  - The project create / edit form gains a third date field next to
    `Expected Completion Date` and `Actual Completion Date`. The form
    pre-fills from the stored value, parses dates with the existing
    `parseProjectDate` helper, and surfaces a **non-blocking** warning
    if `start_date` is later than `expected_completion_date` or
    `completion_date` (historical imports stay importable).
  - The internal project detail page shows `Started: <date>` in the
    project-meta strip (conditional on the column being non-null).
  - The internal projects list shows `Started: <date>` as a small
    meta line under each project card.
  - The client portal dashboard now renders the user-supplied
    `start_date` in its `Started` row. When the column is `NULL` the
    row renders as `Not set` (matching the `Expected Completion Date`
    fallback) rather than echoing the audit `created_at`.
  - `created_at` continues to be used for sort order on the projects
    list and for any audit context — the two fields have different
    semantics and are no longer conflated.
  - Migration: `database/migrations/20261006_add_project_start_date.sql`
    (idempotent, guarded by `information_schema`). Existing rows stay
    `NULL` and continue to render correctly.
  - Regression test: `database/project_start_date_test.php` asserts
    that, given a `start_date` different from `created_at`, the client
    dashboard and the project detail page both display the
    user-supplied value, not `created_at`. The form's date parser is
    exercised for valid / leap-year / malformed / non-calendar / empty
    inputs.

- **Contact write path no longer crashes — missing `contacts` columns
  (issue-009, originally planned as v1.4)**
  - Adds `contacts.company VARCHAR(255) NULL`,
    `contacts.position VARCHAR(255) NULL`, and
    `contacts.address TEXT NULL`. The contact write path
    (`contact_edit.php`), the read view (`contact_detail.php`), and
    the contacts search (`contacts.php`) have all been referencing
    these columns; without the schema the create / edit form failed on
    every submit with MariaDB error
    `1054 (42S22): Unknown column 'company' in 'INSERT INTO'` and the
    user saw the generic `"Error saving contact: Database execute
    failed"` message. The same drift class was previously fixed for
    `phone` in issue-001 / v1.1.0.
  - Migration:
    `database/migrations/20261006_add_contacts_company_position_address.sql`
    (idempotent, guarded by `information_schema`). Existing rows stay
    `NULL` and continue to render with the existing "Not set" fallbacks.
  - `Database::execute()` and `Database::query()` now prepend the
    underlying PDO error message to the thrown `Exception`, so the
    next schema-drift bug surfaces directly to the form instead of
    being hidden as `"Database execute failed"`.
  - Regression test: `database/contacts_columns_test.php` performs the
    exact failing `INSERT` from the bug report (name `张总`, wechat
    `YADEPAN18`), then `UPDATE`s the new columns and re-reads them; it
    also exercises the search-by-company query and the new
    error-message wrapper, then cleans up after itself.

- **Dashboard "New Project" CTA — bug 2 from `tester-note-001`
  (originally planned as v1.3.1)**
  - `dashboard.php` "Recent Projects" → "+ New Project" button now
    routes to `project_edit.php` (the create form), not to
    `projects.php` (the list). Matches the label and reduces clicks to
    one. The same CTA on `projects.php` already routed correctly.

- **CSRF protection on project create / edit POST — bug 4 from
  `tester-note-001` (originally planned as v1.3.1)**
  - `project_edit.php` now allocates the CSRF token for both create
    and edit (was previously only allocated when editing an existing
    project).
  - The main create / edit form embeds a hidden `csrf_token` input,
    and the POST handler calls `validateCsrfToken()` before
    processing any other field. A cross-site request without a valid
    token is rejected with
    `"Invalid form token. Please reload the page and submit again."`
    and does not mutate `projects` (including `start_date`,
    `expected_completion_date`, `completion_date`, `status`).
  - The inline delete dialog continues to validate its own CSRF token
    via `project_delete.php` (unchanged).
  - The `project_start_date_test.php` regression test was extended to
    assert the dashboard CTA target and the form CSRF inputs.
  - Out of scope for this release: same CSRF hardening should be
    applied to other write handlers (`task_edit.php`,
    `contact_edit.php`, `register.php`, `forgot_password.php`,
    `reset_password.php`, AJAX endpoints). Track under a separate
    ticket.

### Upgrade notes

Apply this from any prior release (including a fresh `v1.2.0` install):

1. Pull the latest code.
2. Re-run the v1.2.1 migrations (both are idempotent):
   ```bash
   docker compose exec db mariadb -u"$DB_USER" -p"$DB_PASSWORD" "$DB_NAME" \
     < database/migrations/20261006_add_project_start_date.sql
   docker compose exec db mariadb -u"$DB_USER" -p"$DB_PASSWORD" "$DB_NAME" \
     < database/migrations/20261006_add_contacts_company_position_address.sql
   ```
3. (Recommended) Run the regression tests inside the app container:
   ```bash
   docker compose exec app php database/project_start_date_test.php
   docker compose exec app php database/contacts_columns_test.php
   docker compose exec app php database/task_completion_test.php
   ```
4. For fresh installs, point `docker-compose.yml` at a clean checkout
   and run `docker compose up -d`. The installer is reachable at
   `http://localhost:${APP_PORT}/install.php`. Fresh databases include
   the new columns via the updated `database/schema.sql`.

### Refusals / non-changes

The QA report on issue-008 also flagged a third bullet about
`fa-flag` on the dashboard "Recent Projects" item. v1.2.1 does **not**
change that icon because `docs/issues/issue-008.md:102` explicitly
specifies `fa-flag` for that exact entry. Reviewed: a later
"themed icons" pass is the right place to revisit the entire icon set
together.

---

## v1.2.0 — 2026-10-05

This release hardens the deployment, consolidates the public entry point,
makes project deletion require an explicit Project Code, and introduces
task effort estimates with effort-weighted completion rollups.

### Highlights

- **Deployment security — Nginx deny rules (issue-003)**
  - `docker/nginx.conf` now explicitly blocks HTTP access to dotfiles,
    configuration files (`config.php`, `config.template.php`,
    `.htaccess`), the diagnostic `_smtp_test.php`, and the
    `docs/`, `database/`, `vendor/`, and `includes/` directories.
  - The intentionally public entry points (`install.php`, the
    authentication pages, the project / task / contact CRUD pages, AJAX
    endpoints, `index.php`, and the `client/` portal) and the static
    asset paths continue to work.
  - Defense in depth: secrets and non-public source files are still
    kept outside the document root where the deployment allows.
- **Public entry point at `/` (issue-004)**
  - The domain root now hosts the share-code form. Clients and project
    managers share a single landing page with a clearly visible "Owner
    sign in" link that opens the existing `/login.php` flow.
  - New `buildClientShareUrl()` output targets the root; existing
    `/client/` URLs are preserved as 302 redirects to `/` (see v1.1.0).
  - Invalid / unknown / malformed codes show a useful error and never
    confirm whether a project exists.
- **Project-Code-gated deletion (issue-005)**
  - The delete action now opens an accessible confirmation dialog that
    shows the project's Project Code (`projects.share_code`) and refuses
    to submit until the manager types that exact value.
  - Deletion is submitted as `POST` with the application's standard
    CSRF token. The server re-checks session authentication, project
    ownership, the CSRF token, and the typed Project Code before
    deleting. A missing Project Code, a mismatched value, or a
    `GET` request is non-destructive.
  - Projects without a Project Code cannot be deleted through this flow;
    the user is told the database ID is not an acceptable substitute.
- **Task time estimates (issue-006)**
  - Optional per-task and per-subtask total-time estimate, entered in
    **hours** or **mandays** (1 manday = 8 hours). Stored canonically
    in `tasks.estimated_hours` (`DECIMAL(12,4)`, max `99,999,999.9999`).
  - Empty means "not estimated"; zero is rejected as not a usable
    weight. Existing tasks and subtasks remain unestimated; their
    completion values are not touched by the migration.
  - Migration: `database/migrations/20261005_add_task_estimated_hours.sql`
    (idempotent, guarded by `information_schema`).
- **Effort-weighted completion (issue-007)**
  - When every sibling in a group has a positive estimate, completion
    is reported as the effort-weighted average
    `Σ(estimated_hours × completion%) / Σ(estimated_hours)`.
  - If any sibling is unestimated or has a zero estimate, the group
    falls back to the existing equal-average so legacy and
    partially-estimated work is not silently dropped.
  - Applies to:
    - Parent-task completion derived from subtasks (computed in the
      application when rendering task and dashboard pages).
    - Project completion derived from top-level tasks, via the
      updated `project_stats` view.
    - The client portal's read-only project dashboard.
  - The same server-side formula is used everywhere; no client-side
    computation is introduced.
  - Migration: `database/migrations/20261005_weight_project_completion.sql`
    (idempotent `CREATE OR REPLACE VIEW`).

### Bug fixes / behavior changes

- The auto-subtask trigger (`database/subtask_functions.sql`) is no
  longer needed for the displayed completion rollup: v1.2.0 computes
  parent-task completion from the subtask tree in the application, so
  the recursive trigger MariaDB ≥ 10.5 rejects is replaced. The file is
  retained as reference.
- Existing projects / tasks are unaffected: tasks without an estimate
  continue to use the equal-average rule, and completion values already
  stored are not changed.

### Improvements

- Project delete dialog is keyboard accessible and works at narrow /
  mobile viewport sizes.
- Task and subtask create / edit forms show the time estimate and unit
  selector; task list and task / subtask detail views show the saved
  estimate in the user-selected unit.
- `formatMoney()` continues to handle JPY (no decimals) correctly, now
  alongside the new `formatEstimatedTime()` helper used for task
  estimates.
- The Nginx deny rules are documented in the README under
  *Web access boundaries*, and the README's quick-start now lists a
  smoke test that confirms each private file returns `404`.

### Known gaps (not fixed in v1.2.0)

- Internal app pages remain English-only. The client portal is fully
  i18n (EN / 简体中文); extending the catalog to the internal console
  is tracked for a future release.
- The Dockerfile still pulls base images from `docker.m.daocloud.io`
  mirrors because `registry-1.docker.io` is not reachable from some
  networks. Adjust the `image:` lines in `docker-compose.yml` if you
  have direct Docker Hub access.

> The first "Known gaps" bullet — missing `contacts.company`,
> `position`, `address` columns — was the top-of-stack item in v1.2.0
> and is **resolved in v1.2.1** (see issue-009 below).

### Upgrade notes

1. Pull the latest code.
2. Re-run the v1.2.0 migrations (both are idempotent):
   ```bash
   docker compose exec db mariadb -u"$DB_USER" -p"$DB_PASSWORD" "$DB_NAME" \
     < database/migrations/20261005_add_task_estimated_hours.sql
   docker compose exec db mariadb -u"$DB_USER" -p"$DB_PASSWORD" "$DB_NAME" \
     < database/migrations/20261005_weight_project_completion.sql
   ```
3. Reload Nginx so the new deny rules take effect:
   ```bash
   docker compose exec web nginx -s reload
   ```
4. (Recommended) Smoke test that the protected paths return `404`:
   ```sh
   for path in .env .env.example .htaccess config.php config.template.php \
     _smtp_test.php docs/requirements.md database/schema.sql \
     includes/Database.php vendor/autoload.php; do
       code=$(curl -sS -o /dev/null -w '%{http_code}' "http://localhost/$path")
       test "$code" = 404 || { echo "$path returned $code" >&2; exit 1; }
   done
   ```
5. For fresh installs, point `docker-compose.yml` at a clean checkout
   and run `docker compose up -d`; the installer is reachable at
   `http://localhost:${APP_PORT}/install.php`.

---

## v1.1.1 — 2026-10-04

This patch release fixes the profile page layout and brings it in line with
the rest of the internal console.

### Improvements

- Restored the logged-in navigation on the profile page.
- Applied the shared page container, heading, and responsive spacing styles
  used by the other internal pages.

No database migration is required.

## v1.1.0 — 2026-10-03

This release introduces a public client portal with shareable read-only project
views, full English / Simplified Chinese localization of that portal, the
canonical Docker compose stack, and a number of bug fixes.

### Highlights

- **Client share portal** (entry at `/`, dashboard at `/client/dashboard.php`)
  - New public, unauthenticated entry point. Anyone holding a project's
    `share_code` (a 5-5-5 alphanumeric code such as `SZ9GM-K2S3C-AWU6P`) can
    see a read-only snapshot of the project.
  - Landing page at `/` (was `/client/`) with auto-formatting input —
    uppercase and dashes inserted automatically as the user types.
    `/client/` 302-redirects to `/` while preserving the query string.
  - Read-only dashboard (`client/dashboard.php`) showing:
    - Project info: name, description, status, manager, start / expected /
      completion dates.
    - Overall completion (average of top-level task percentages).
    - Project scope (one bullet per line).
    - Budget in the project's chosen currency (THB, USD, CNY, JPY, SGD, EUR).
    - Recursive task tree with status, due date, completion %, and responsible
      person for every task and subtask.
  - Share codes are generated with `generateShareCode()` (excludes
    confusable `0/O/1/I/L`) and rendered into the share URL by
    `buildClientShareUrl()`.
- **Localization (ENG / 中文) for the client portal**
  - `client/lang.php` — translation catalog and helpers (`t()`, `t_status()`,
    `client_current_lang()`, `client_set_lang_cookie()`,
    `client_lang_url()`, `client_other_langs()`).
  - Locale resolution order: `?lang=` query parameter → `client_lang`
    cookie → `Accept-Language` header → `en` default.
  - Choice persists in a 1-year, root-path cookie.
  - A language switcher is rendered on both the landing page (centered, at
    the bottom of the card) and the dashboard (inline in the header).
- **Docker-first installation**
  - `docker-compose.yml`, `Dockerfile`, and `docker/entrypoint.sh` for a
    reproducible three-container stack: `pmp-app` (php-fpm 8.3),
    `pmp-web` (nginx), `pmp-db` (MariaDB 10.11).
  - `config.template.php` and `.env.example` document all configuration
    knobs (`APP_NAME`, `APP_PORT`, `APP_URL`, `DB_*`).
- **Database migrations** (`database/migrations/`)
  - New `20261003_add_contacts_phone.sql` adds the `phone` column to the
    `contacts` table. Idempotent — safe to re-run.
  - `database/schema.sql` updated so fresh installs include the same
    columns and the v1.1 client-share columns (`projects.scope`,
    `projects.budget_amount`, `projects.currency`, `projects.share_code`,
    `projects.expected_completion_date`).

### Bug fixes

- **`contacts.phone` column missing — task create / view crashed (issue-001)**
  - `task_detail.php` and `task_edit.php` referenced `c.phone` in joins
    against `contacts`, but the column had never been added to the schema.
  - Fixed by the new `20261003_add_contacts_phone.sql` migration.
  - Symptom: `Fatal error: Uncaught Exception: Database query failed … on
    dashboard click → Tasks → a task → create / view`.
- **Contacts page direct access (issue-002)**
  - `Dashboard → Contacts` link now resolves cleanly via the column added
    above.
  - Search query remains sensitive to other missing columns
    (`company`, `position`, `address`) — see *Known gaps* below.

### Improvements

- `client/index.php` and `client/dashboard.php` use `e()` (the project's
  shared HTML-escape helper) on every translated string and dynamic value.
- `includes/functions.php` gained `generateShareCode()` and
  `buildClientShareUrl()` helpers used by the new portal.
- `formatMoney()` now handles JPY (no decimals) correctly.

### Known gaps (not fixed in v1.1.0, scheduled for v1.2)

- `contacts` table is still missing `company`, `position`, `address` columns
  referenced by `contact_edit.php` (write path) and `contacts.php?search=…`
  (search path). Direct visits to `/contacts.php` work; search and create
  fail until those columns are added. *(Not resolved in v1.2.0 — still
  tracked for a follow-up release.)*
- The auto-generated subtask trigger (defined in
  `database/subtask_functions.sql`) is rejected by MariaDB ≥ 10.5 because
  stored functions may not be recursive. Subtasks therefore do not
  auto-roll-up completion percentages; managers must update subtasks
  manually. A migration to replace the trigger with application-level
  rollups is tracked for v1.2. *(Resolved in v1.2.0 — application-level
  rollups are used everywhere; the trigger file is kept for reference
  only.)*
- The internal app (`/`, `/projects.php`, `/tasks.php`, …) is **English
  only** in this release. v1.2 will extend the `lang.php` catalog to the
  internal surface as well. *(Not resolved in v1.2.0; the client portal
  remains the only fully localized surface.)*

### Upgrade notes

- Pull the latest code, then re-run any database migrations:
  ```bash
  docker compose exec db mariadb -udbuser -p"$DB_PASSWORD" dbname \
    < database/migrations/20261003_add_contacts_phone.sql
  ```
  (The script is idempotent — re-running it on an already-migrated
  database is a no-op.)
- For fresh installs, just point `docker-compose.yml` at a clean checkout
  and run `docker compose up -d`. The installer is reachable at
  `http://localhost:${APP_PORT}/install.php`.

---

## v1.0.0 — Initial release

- Internal project, task, contact, and user management.
- Login / logout / password reset flows.
- Email service via PHPMailer (SMTP).