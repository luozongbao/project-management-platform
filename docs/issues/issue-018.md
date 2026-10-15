# Password-Reset Brute-Force Protection

**Status:** Proposed
**Type:** Security / Abuse Prevention
**Priority:** Medium
**Affects:** `forgot_password.php`, `reset_password.php`, `database/migrations/`, `includes/functions.php`, new `database/password_reset_attempts_test.php`

## Problem

The password-reset flow today has three failure modes an
attacker can exploit:

1. **No rate limit on `forgot_password.php`.** A logged-out
   attacker can POST an email address as many times as they
   want. The page returns the same success message
   regardless of whether the email is registered (good —
   no enumeration), but the *real* registered user still
   gets a fresh reset email for each request. A sustained
   attack floods the victim's inbox (annoyance) and burns
   SMTP quota.
2. **No rate limit on `reset_password.php`.** The reset
   page accepts any 64-char hex token via `?token=…`. A
   brute-force attack against the 128-bit token is
   infeasible in absolute terms (2^128 search space), but
   *side channels* are not:
   - Timing: the DB query
     `SELECT … WHERE token = ? AND expires_at > NOW()`
     returns the row in constant time, but the *form
     rendering* differs — a valid token shows the
     "Account: <name> (<email>)" line, an invalid token
     shows the "Invalid or expired reset link" error
     block. The two responses are not the same byte
     length, so a network-position attacker can
     distinguish them on first byte. This is not
     exploitable in a useful way (the token is still
     unguessable), but it is a hardening gap.
   - **Token reuse.** The `reset_password.php` flow
     `DELETE`s the token only on the *successful* POST
     (`reset_password.php:58`). A user who opens the link
     in two browsers and submits from one has the link
     invalidated in the second. Fine. But a user who
     opens the link, *does not* submit, leaves the
     browser open, and is then phished later, can have
     their token used by the phisher. The token is valid
     until expiry regardless of use.
3. **Token never rotates on use.** The same token is
   presented on every form render; if the form is
   re-rendered (e.g. validation failure), the token is
   still in the URL bar and in browser history. A
   shoulder-surfing attacker who sees the URL gets
   read-write access to that account for the next hour.

### Why this is Medium and not High

- Brute-forcing the 128-bit token is infeasible.
- The reset email itself does not leak the password or
  the username (only a "you can reset" link).
- A successful attack requires the attacker to be able
  to intercept the email (which they could anyway if
  they have email access — see the
  cross-cutting note below).

So the impact is *bounded* — the attacker must already
be in a position to read the user's email. The
defence-in-depth is still worth doing.

## Goals

- Rate-limit `forgot_password.php` and `reset_password.php`
  per client IP, with exponential backoff and a hard
  lockout after N failures.
- Rotate the token on every successful
  `reset_password.php` POST — already done today — and
  *also* on every form render while a valid token is
  present (defence in depth against browser-history
  leakage).
- Invalidate all live tokens for a user the moment
  their password is changed (today only the consumed
  token is removed; the others stay valid until
  expiry).
- Add a `password_reset_attempts` table to record
  failed attempts and enforce the rate limit server-side
  (not just in PHP memory — a multi-process PHP-FPM
  pool would otherwise lose track of attempts).

## Non-goals

- No 2FA / WebAuthn. Out of scope; the spec is
  password-reset only.
- No change to the email-template copy.
- No change to the `password_reset_tokens` schema other
  than adding the `attempts` table.
- No change to `login.php` (account-takeover via
  credential stuffing is a separate issue —
  [issue-019](issue-019.md)).

## Proposed changes

### 1. New `password_reset_attempts` table

```sql
-- database/migrations/20261015_add_password_reset_attempts.sql

CREATE TABLE IF NOT EXISTS password_reset_attempts (
    id INT PRIMARY KEY AUTO_INCREMENT,
    ip_address VARCHAR(45) NOT NULL,        -- IPv6-safe
    action ENUM('request', 'consume') NOT NULL,
    user_id INT NULL,                       -- NULL for unknown emails
    success TINYINT(1) NOT NULL DEFAULT 0,
    attempted_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_pra_ip_action_time (ip_address, action, attempted_at),
    INDEX idx_pra_user_time (user_id, attempted_at),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

Idempotent: the `IF NOT EXISTS` guard.

### 2. Rate-limit helper

Add to `includes/functions.php`:

```php
function recordPasswordResetAttempt(
    string $ip,
    string $action,            // 'request' | 'consume'
    ?int $userId,
    bool $success
): void;

function isPasswordResetRateLimited(
    string $ip,
    string $action
): bool;
```

The policy:

- `action = 'request'` — at most 5 attempts in 15 minutes
  per IP, then 1 attempt per hour for the next 24 hours.
  After 24 hours of no requests, the counter resets.
- `action = 'consume'` — at most 10 attempts (any
  outcome) per IP in 15 minutes. A "success" attempt
  clears the counter (a successful reset is the legit
  end of the flow).
- Both policies are evaluated against the *attempt
  history* for the IP. A successful `consume` clears
  the `consume` history for that IP immediately
  (so a user who resets successfully then has their
  browser auto-fill the same URL a second time is not
  locked out — but the URL is also now invalid because
  the token was deleted, so the second POST is a
  "consume failure" that *does* count).

The helper logs to `password_reset_attempts` and
returns `true` from `isPasswordResetRateLimited()` if
the IP is over the threshold. The caller surfaces a
generic "Too many attempts. Please try again later."
to the user without revealing which threshold was
hit.

### 3. `forgot_password.php` — apply the request limit

```php
if (isPasswordResetRateLimited($clientIp, 'request')) {
    $errors[] = 'Too many reset requests. Please try again later.';
    recordPasswordResetAttempt($clientIp, 'request', null, false);
} else {
    // existing flow
    recordPasswordResetAttempt($clientIp, 'request', $user['id'] ?? null, true);
}
```

`$clientIp` is `$_SERVER['REMOTE_ADDR']` — **not** the
`X-Forwarded-For` header (this app is behind Nginx but
not behind a load balancer that sets the header; if a
reverse proxy is added in the future, the
`REMOTE_ADDR` resolution must be revisited).

The success path still renders the same "If an account
with that email exists, we've sent…" message. The
success-path branch is reached even if the user is
unknown (good — no enumeration).

### 4. `reset_password.php` — apply the consume limit + token rotation

On every GET render with a valid token:

- Check the rate limit; if exceeded, render the
  "Invalid or expired reset link" page (do not reveal
  the rate-limit reason).
- **Rotate the token**: `UPDATE password_reset_tokens
  SET token = <new token> WHERE id = ?`. The new token
  is placed in the form's hidden input *and* in the
  URL bar is replaced via a 302 to
  `reset_password.php?token=<new>`. This way the URL
  the user sees in the address bar is the rotated
  one; if they copy-paste the URL, the rotated
  token is what gets shared.

On every successful POST:

- Invalidate **all** live tokens for that user
  (`DELETE FROM password_reset_tokens WHERE user_id = ?`),
  not just the consumed one. This prevents a
  previously-issued link from being used after a
  successful reset.

On every failed POST (password mismatch, weak
password, etc.):

- The token is *not* rotated (the user is still
  mid-flow, may correct and resubmit). The failed
  attempt is recorded.

### 5. Render-time IP rate limit

If the IP is rate-limited, the page renders the same
"Invalid or expired reset link" block — so an attacker
who hits the rate limit cannot distinguish "rate
limited" from "bad token" from "expired token". All
three cases are now indistinguishable to a
network-position attacker.

## Files touched

- `database/migrations/20261015_add_password_reset_attempts.sql`
  (new, idempotent).
- `database/schema.sql` — add the same `CREATE TABLE` so
  a fresh install gets the table.
- `includes/functions.php` — add `recordPasswordResetAttempt()`
  and `isPasswordResetRateLimited()`.
- `forgot_password.php` — call the helpers, log every
  attempt.
- `reset_password.php` — call the helpers, rotate the
  token on render, invalidate all tokens on success.
- `database/password_reset_attempts_test.php` (new) —
  regression test, see below.
- `docs/sprint/v1.6.md` — capture as a v1.7.1 item.

## Risks

- **The IP-based rate limit can lock out a NAT'd
  office.** Many users behind a single corporate
  proxy share one public IP. A realistic scenario: a
  user legitimately requests a reset, the email
  doesn't arrive, they try again, the office admin
  tries from a different machine, the entire office
  is locked out for an hour. **Mitigation:** the
  threshold of 5 requests per 15 minutes is generous
  for a single user; the helper should be tunable
  via constants in `config.php` (`PASSWORD_RESET_REQUEST_LIMIT`,
  `PASSWORD_RESET_REQUEST_WINDOW`). Defaults are 5
  per 15 minutes; admins can lower or raise.
- **The token rotation on render changes the URL in
  the address bar mid-flow.** A user who copy-pastes
  the URL from the address bar to share with
  themselves (e.g. from desktop to phone) gets the
  rotated token, which is fine — but if they
  copy-paste *before* the rotation, the stale URL
  fails on the other device. **Mitigation:** the
  rotation is on *GET render*; the *first* GET
  receives the original token from the email and
  rotates it. The user copy-pastes the rotated
  URL. A second copy-paste is no longer
  needed.
- **The migration is additive** (new table, no
  changes to existing tables), so applying it on a
  live install is safe. A fresh install picks it up
  via `database/schema.sql`.

## Acceptance

- The migration applies cleanly on a fresh install
  and on the live DB (idempotency).
- `forgot_password.php` returns 200 with the
  "If an account with that email exists…" message
  for the 5th request from a single IP within
  15 minutes. The 6th request returns the same
  page with the additional "Too many reset
  requests" alert (and the form is hidden). The
  `password_reset_attempts` table contains 6 rows
  for that IP.
- `reset_password.php` with the same valid token
  POSTed 11 times within 15 minutes shows the
  "Invalid or expired reset link" page on the
  11th. The 10 prior attempts (whether success
  or failure) are recorded. The 11th is **not**
  recorded (we drop the request before any DB
  write to the tokens table).
- A successful reset on `reset_password.php`
  deletes all rows from `password_reset_tokens`
  for that user — verified by re-fetching the user
  and counting rows. Before the fix, only the
  consumed token was deleted; after, all of them
  are.
- A GET render with a valid token issues a 302
  to `reset_password.php?token=<new>`. The new
  token is in the form's hidden input. The old
  token in `password_reset_tokens` is gone
  (rotated to the new one).
- The new constants
  `PASSWORD_RESET_REQUEST_LIMIT` /
  `PASSWORD_RESET_REQUEST_WINDOW` /
  `PASSWORD_RESET_CONSUME_LIMIT` /
  `PASSWORD_RESET_CONSUME_WINDOW` are defined in
  `config.php` (with safe defaults).

## Regression test plan

`database/password_reset_attempts_test.php` is
self-contained. Steps:

1. Apply the migration (idempotent — guarded).
2. Seed a user with a known email + hashed password.
3. Insert a valid `password_reset_tokens` row for the
   user.
4. Call `recordPasswordResetAttempt()` 5 times with
   `success = true` and assert
   `isPasswordResetRateLimited($ip, 'request')` is
   `false` after each.
5. Call it a 6th time with `success = false` and
   assert the helper returns `true`.
6. Insert another `password_reset_tokens` row (a
   fresh token). Boot a minimal `reset_password.php`
   in-process (with a stubbed `requireLogin` /
   `startSession` via `auto_prepend_file` or by
   extracting the consume path into a helper; see
   "Implementation note" below).
7. POST 10 times with the wrong password; assert the
   10th renders the "Invalid or expired reset link"
   page. POST an 11th time and assert the helper
   records nothing (the request is dropped before the
   DB write).
8. Clean up: DELETE the user (cascades to the
   attempts and the tokens). DROP the
   `password_reset_attempts` table on the test
   teardown so re-running the test starts clean.

### Implementation note for the test

`reset_password.php` is a server-rendered page with
form-POST handling. A naïve `php -r 'include
"reset_password.php";'` exits at the `if (isLoggedIn())`
check. The v1.6.0 hardening guide (`docs/sprint/v1.6.md`)
records the same problem. The cleanest approach is to
extract the consume logic into a helper:

```php
// includes/functions.php
function consumePasswordResetToken(string $token, string $newPassword): array {
    // returns ['ok' => bool, 'error' => string, 'user_id' => int|null]
}
```

…and have `reset_password.php` call it. The test
imports the helper directly. No CLI proxy, no
`auto_prepend_file`. This is also a code-quality
win for the page itself.

## Cross-references

- Umbrella: [issue-015](issue-015.md) finding 3.
- Schema: `database/schema.sql` (new table block),
  `database/migrations/` (additive file).
- Test pattern: same shape as
  `database/task_inherit_subtask_hours_test.php`.

## Out of scope (flagged for future tickets)

- Switch the `password_reset_tokens.token` column to
  store only a SHA-256 hash and put the raw token in
  the email. Today the raw token is stored in the DB;
  a DB compromise is a reset-token compromise. v2.0
  hardening.
- Add an admin-visible "recent password reset
  attempts" page so the user can see if someone
  tried to reset their password without success.
  v2.0.
- Email-based 2FA before the reset link is issued.
  v2.0.
- Sign the reset URL with a per-user HMAC
  (`?token=…&exp=…&sig=…`) so a leaked token can be
  invalidated server-side without a DB round-trip.
  v2.0.
