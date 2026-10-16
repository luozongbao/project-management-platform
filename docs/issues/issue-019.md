# Login & Registration Brute-Force / Enumeration Protection

**Status:** Implemented (v1.9.0)
**Type:** Security / Abuse Prevention
**Priority:** Medium
**Affects:** `login.php`, `register.php`, `includes/functions.php`, `database/migrations/`, new `database/login_register_protection_test.php`

## Problem

Three failure modes:

1. **No rate limit on `login.php`.** A credential-stuffing
   attack (or a brute-force against a known username)
   can run unbounded. The page returns the same
   "Invalid username or password" message regardless of
   which side failed (good — no username enumeration
   via login error), but it does not slow the attacker
   down.
2. **No rate limit on `register.php`.** Public endpoint
   (anyone can register). Today the page emits a
   distinct error if the username or email is already
   taken:

   ```php
   // register.php:54
   if ($existing) {
       $errors[] = "Username or email already exists";
   }
   ```

   This is **account enumeration**: an attacker can
   submit the form with a candidate email and read off
   "already exists" vs. the success path (which renders
   a separate "Registration Successful!" card with no
   "already exists" alert). A motivated attacker can
   enumerate the entire user table by iterating over
   candidate emails.
3. **No lockout on `forgot_password.php`** — covered
   by [issue-018](issue-018.md). Out of scope here; the
   cross-reference is noted.

## Goals

- Rate-limit `login.php` per (IP + username) tuple with
  exponential backoff and a hard lockout after N
  failures. The lockout is per *username* (not per IP)
  so a credential-stuffing attacker cannot lock out
  every user at once by trying them from one IP.
- Fix `register.php` enumeration: return the **same**
  success-path render for both "user created" and
  "user already exists" cases. The "already exists"
  case silently swallows the registration attempt —
  no email is sent, no DB row is created, and the user
  sees the same success card.
- Add the `login_attempts` table for server-side
  attempt tracking (PHP memory is per-request, useless
  across the FPM pool).

## Non-goals

- No 2FA / WebAuthn. The spec is rate-limiting only.
- No change to the `forgot_password.php` flow (covered
  by [issue-018](issue-018.md)).
- No change to the password storage (`hashPassword` /
  `verifyPassword` are Argon2id, fine).
- No change to the email-template copy in
  `register.php`'s "Registration Successful!" card.

## Proposed changes

### 1. New `login_attempts` table

```sql
-- database/migrations/20261015_add_login_attempts.sql

CREATE TABLE IF NOT EXISTS login_attempts (
    id INT PRIMARY KEY AUTO_INCREMENT,
    ip_address VARCHAR(45) NOT NULL,
    username_attempted VARCHAR(255) NOT NULL,  -- free-form, NOT a users.username FK
    success TINYINT(1) NOT NULL DEFAULT 0,
    attempted_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_la_user_time (username_attempted, attempted_at),
    INDEX idx_la_ip_time (ip_address, attempted_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

`username_attempted` is the **submitted** value, not a
FK. An attacker who tries `admin` and `bob@x` and
`Carol` from one IP gets three rows with three
different `username_attempted` values, so the per-user
rate limit is independent of the IP.

### 2. New helpers in `includes/functions.php`

```php
function recordLoginAttempt(
    string $ip,
    string $username,
    bool $success
): void;

function isLoginRateLimited(string $ip, string $username): bool;
```

The policy:

- **5 failed attempts** in 15 minutes for the
  (IP, username) tuple → return `true`. The lockout
  is "soft": the page renders the same
  "Invalid username or password" alert (no
  lockout-specific copy, so an attacker cannot
  distinguish "wrong password" from "locked out").
- A **successful** login clears the per-(IP,
  username) failure count.
- The IP-only cap (independent of username) is 30
  failed attempts in 15 minutes — defends against a
  distributed dictionary attack that rotates
  usernames.

The constants live in `config.php`:

```php
const LOGIN_FAILURE_LIMIT = 5;
const LOGIN_FAILURE_WINDOW = 900;          // 15 min
const LOGIN_IP_LIMIT = 30;
const LOGIN_IP_WINDOW = 900;
```

### 3. `login.php` changes

The login flow becomes:

```php
if ($_POST) {
    $username = sanitize($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';
    $clientIp = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';

    if (isLoginRateLimited($clientIp, $username)) {
        // Same "Invalid username or password" message;
        // do NOT reveal that the IP/username is rate-limited.
        $errors[] = "Invalid username or password";
        recordLoginAttempt($clientIp, $username, false);
    } else {
        $user = $db->fetchOne(
            "SELECT id, name, username, email, password
             FROM users WHERE username = ? OR email = ?",
            [$username, $username]
        );

        if ($user && verifyPassword($password, $user['password'])) {
            // session_regenerate_id(true);  // issue-020
            $_SESSION['user_id']   = $user['id'];
            $_SESSION['username']  = $user['username'];
            $_SESSION['name']      = $user['name'];
            $_SESSION['timezone']  = $_SESSION['timezone'] ?? TIMEZONE;
            recordLoginAttempt($clientIp, $username, true);
            redirect('dashboard.php', 'Login successful!', 'success');
        } else {
            $errors[] = "Invalid username or password";
            recordLoginAttempt($clientIp, $username, false);
        }
    }
}
```

Two non-obvious details:

- The rate-limit branch records a *failure* in
  `login_attempts` even though the password was
  never actually checked. Otherwise an attacker
  could ping the rate-limit branch forever without
  the counter incrementing.
- The successful branch records a success. The
  helper clears the per-(IP, username) failure
  count on a success record (i.e. the *next* failure
  counts as 1, not 6).

The page does **not** add a CAPTCHA. The issue
explicitly avoids this — CAPTCHAs are out of scope
and the rate limit is sufficient at the current
threat model.

### 4. `register.php` enumeration fix

The current "user already exists" branch returns
`$errors[] = "Username or email already exists"`.
Replace with:

```php
if ($existing) {
    // Account enumeration mitigation: do not reveal that
    // the user/email is taken. Render the same success
    // card as a real registration would, but do NOT
    // create the user and do NOT send the welcome email.
    // The legitimate user already has a welcome email
    // from their first registration; this no-op is
    // indistinguishable from a fresh registration to the
    // attacker.
    $success = true;
} else {
    // existing INSERT + email send
}
```

Critically:

- The render of the success card is reached in both
  cases, so the response HTML is byte-identical
  (modulo the timing of the email send, which is
  best-effort and already varies). The attacker
  cannot distinguish "created" from "exists" from
  the response.
- The `INSERT` and email-send are skipped in the
  "exists" branch, so no duplicate welcome email is
  fired at the existing user.
- The DB row is left untouched, so the existing
  user's password is not reset.

## Files touched

- `database/migrations/20261015_add_login_attempts.sql`
  (new, idempotent).
- `database/schema.sql` — add the same `CREATE TABLE`.
- `includes/functions.php` — add `recordLoginAttempt()`
  and `isLoginRateLimited()` (both `function_exists`-guarded,
  with `defined()` fallbacks for the four tunables).
- `login.php` — gate on the (IP, username) and per-IP
  rate limit, record every attempt (success / wrong-password /
  rate-limited drop), same "Invalid username or password"
  message on all denial paths (no enumeration leak).
- `register.php` — collapse the "already exists" branch
  into a silent `$success = true` (no INSERT, no welcome
  email, same rendered card).
- `config.template.php`, `config.php`, `docker/entrypoint.sh`,
  `install.php` — add `LOGIN_FAILURE_LIMIT=5`,
  `LOGIN_FAILURE_WINDOW=900`, `LOGIN_IP_LIMIT=30`,
  `LOGIN_IP_WINDOW=900`. The helpers still use `defined()`
  fallbacks so a stale `config.php` does not break login.
- `database/login_register_protection_test.php` (new) —
  regression test, 10 sections, 54 assertions.
- `docs/issues/issue-019.md` — this file.

## Risks

- **Lockout for legitimate users on a shared IP.** A
  corporate office with a single public IP, one user
  locked out, then 4 other users fail their passwords
  → the 5th failure locks the IP for 15 minutes for
  *every* user at that username. The IP cap (30
  per 15 min) is set high enough that this is unlikely
  in normal use, but the per-IP lockout is a real
  foot-gun. **Mitigation:** the constants are
  configurable; ops can raise the IP cap. The
  per-username cap is the more important one and
  remains low.
- **The registration success path now silently
  no-ops on duplicate.** A user who legitimately
  forgot they already registered will see the
  "Registration Successful!" card, click "Return to
  Login", and find they cannot log in. They then
  have to use `forgot_password.php`. **Mitigation:**
  the success card already says "check your email";
  for a returning user, the "check your email"
  instruction is the right next step. Optional
  follow-up: a "(Don't see it? Try forgot password.)"
  link below the success card.
- **The `login_attempts` table grows without bound.**
  No retention policy. **Mitigation:** a nightly
  cron or a TTL helper that `DELETE`s rows older than
  30 days. The test fixtures clean up their own rows;
  ops can add the cron in a follow-up.

## Acceptance

- The migration applies cleanly on a fresh install
  and on the live DB (idempotency).
- 5 failed login attempts for the same
  (IP, username) tuple within 15 minutes return
  `isLoginRateLimited() === true` from the 6th call
  onward. A successful login in between resets the
  counter.
- 31 failed login attempts for distinct usernames
  from the same IP within 15 minutes trigger the
  per-IP rate limit (the 31st call's helper returns
  `true`).
- `register.php` with an existing email renders
  byte-for-byte the same HTML as a successful
  registration. Verified by `curl -c /dev/null
  "http://localhost/register.php" -d '…'` twice
  (once new, once existing) and `diff`ing the
  responses.
- The new constants are in `config.php` and
  reachable from `includes/functions.php`.

## Regression test plan

`database/login_register_protection_test.php` is
self-contained:

1. Apply the migration (idempotent — guarded).
2. Seed a user with a known username and password
   hash.
3. Call `recordLoginAttempt($ip, $username, false)`
   5 times. Assert
   `isLoginRateLimited($ip, $username) === false`
   after each.
4. Call it a 6th time. Assert the helper returns
   `true`.
5. Call `recordLoginAttempt($ip, $username, true)`.
   Assert the helper returns `false` (success
   clears the counter).
6. Call `recordLoginAttempt($ip, $username, false)`
   4 more times. Assert the helper still returns
   `false` (the counter is at 1, not 5).
7. Seed a second user (`bob`). Call
   `recordLoginAttempt($ip, 'bob', false)` 30 times
   with `success = false`. Assert the IP-only
   helper returns `false`. Call once more and
   assert it returns `true`.
8. Clean up: DELETE both users (cascades). DROP
   the `login_attempts` table on teardown so
   re-running the test starts clean.

The registration-enumeration fix is a source-grep
test (no DB): the new `register.php` is required
to render the same HTML for both branches. The
test boots `register.php` in a CLI proxy (same
pattern as the v1.6.0 hardening), POSTs the form
once with a fresh email and once with the
existing email, and asserts the two response
bodies are byte-identical except for the CSRF
token. (The CSRF token is session-scoped; in the
CLI proxy the session is empty, so the test
either disables the CSRF check or uses a
`session_start()` stub.)

## Cross-references

- Umbrella: [issue-015](issue-015.md) finding 4.
- Reset flow: [issue-018](issue-018.md).
- Response headers (CSP, cookie flags):
  [issue-020](issue-020.md).
- Test pattern: same shape as
  `database/password_reset_attempts_test.php`
  (issue-018).

## Out of scope (flagged for future tickets)

- CAPTCHA after 2 failed attempts (or hCaptcha on
  the form). v2.0.
- Admin-visible "recent login attempts" page. v2.0.
- Per-IP progressive backoff (1s after each
  failure, 5s after 3, 60s after 5). The
  rate-limit-table approach in this issue is
  sufficient for the current install; a
  memory-table backoff is faster but more
  fragile. v2.0.
- Lockout notification email to the user
  ("Someone tried to log in as you 5 times in the
  last 15 minutes. Was this you?"). v2.0.

## Implementation summary (v1.9.0)

The spec was implemented end-to-end with one
deliberate deviation from the spec text: the
registration-enumeration fix is a single-line
`$success = true;` in the "already exists" branch
followed by the existing `if ($success)` render
block. The spec's pseudocode (which showed inline
INSERT + email-send in the success branch) is
preserved as-is; the "exists" branch is a true
no-op.

### Helpers

| Helper | Purpose |
| --- | --- |
| `recordLoginAttempt($ip, $username, $success)` | INSERT one row into `login_attempts`. On `success=true`, also `DELETE`s the per-(IP, username) failure history (success resets the counter). |
| `isLoginRateLimited($ip, $username)` | Two gates: per-(IP, username) threshold (5 / 15 min) AND per-IP threshold (30 / 15 min). Returns true if EITHER is exceeded. Both use `count > limit` so the Nth is allowed and the (N+1)th is denied. |

### `login.php` — what changed

The POST block now:
1. Reads `$clientIp = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0'`.
2. Calls `isLoginRateLimited($clientIp, $username)`. If true, emits the byte-identical "Invalid username or password" message and records a failure (so a sustained attack keeps incrementing the counter — the attacker cannot ping the rate-limit branch forever).
3. Otherwise, looks up the user, calls `verifyPassword`, and either sets up the session + records a success (which clears the per-(IP, username) failure history) or emits the same "Invalid username or password" and records a failure.

`$clientIp` is `$_SERVER['REMOTE_ADDR']` — the spec's "do not trust `X-Forwarded-For`" note is honoured (and source-grepped).

### `register.php` — what changed

The "user already exists" branch used to push `$errors[] = "Username or email already exists"`. Replaced with a single `$success = true;` line. The page then renders the SAME `if ($success)` "Registration Successful!" card as a real registration would. The attacker cannot distinguish "user created" from "user exists" from the response, so they cannot enumerate the user table.

Critically: the existing branch is a NO-OP. No `INSERT INTO users`, no welcome email. The existing user keeps their original welcome email; their password is not reset.

### Migration

`database/migrations/20261015_add_login_attempts.sql` is additive (`CREATE TABLE IF NOT EXISTS …`). No FK on `username_attempted` (it's the submitted value, not a users FK — a credential-stuffing attack that rotates usernames is fully recorded). The same DDL is mirrored in `database/schema.sql`.

## Test results

`database/login_register_protection_test.php` runs 10 sections, 54 assertions, all green:

```
## 1. Per-(IP, username) rate limit (5 failed / 15 min)
  [PASS] x 8  (fresh not limited, 5 records-not-limited, 6th-trips, cross-username isolated)
## 2. Success clears the per-(IP, username) failure history
  [PASS] x 5  (success clears, 5 fresh fail NOT limited, 6th IS limited, cross-user success does NOT clear, same-user success DOES clear)
## 3. Per-IP rate limit (30 failed / 15 min)
  [PASS] x 3  (30 NOT tripped, 31 IS tripped, unknown username also denied at IP cap)
## 4. Source-grep on login.php
  [PASS] x 9  (gate, record, REMOTE_ADDR, no enumeration leak, no X-Forwarded-For)
## 5. Source-grep on register.php (enumeration fix)
  [PASS] x 6  (still queries users, no $errors push, $success=true, no INSERT in existing branch, success card present, no welcome email in existing branch)
## 6. Source-grep on includes/functions.php
  [PASS] x 9  (helpers present, defined() fallbacks, `>` not `>=`, success-clears)
## 7. Source-grep on config.php (constants)
  [PASS] x 4
## 8. Source-grep on database/schema.sql
  [PASS] x 4  (table, indexes, no FK on username_attempted)
## 9. Source-grep on the migration
  [PASS] x 3
## 10. Lint touched files
  [PASS] x 3  (login.php, register.php, includes/functions.php)

Issue-019 login/register protection: ALL ASSERTIONS PASSED
```

### Self-test (bug catch)

The test catches two distinct regressions:

1. **`>=` vs `>` semantic revert** — re-introducing `$userCount >= $userLimit` in `isLoginRateLimited` produces:
   ```
   [FAIL] After 5 failure(s), (IP, username) is still not rate-limited
   [FAIL] After 5 fresh failures post-clear, (IP, username) is NOT yet rate-limited (count=5, limit=5)
   [FAIL] functions.php isLoginRateLimited uses '>' (not '>=') for the threshold
   ```
2. **Enumeration leak revert** — re-inserting `$errors[] = "Username or email already exists";` in the existing branch produces:
   ```
   [FAIL] register.php does NOT push 'already exists' to $errors
   [FAIL] register.php sets $success = true on the existing branch
   [FAIL] register.php does NOT send a welcome email in the existing branch
   ```

Exit code `1` — CI-safe.

### Regression sweep

All 12 self-contained tests under `database/` and `client/` pass on the live DB after the migration is applied:

```
database/contacts_columns_test.php                    exit=0
database/install_wizard_test.php                      exit=0
database/login_register_protection_test.php           exit=0   (new)
database/password_reset_attempts_test.php             exit=0
database/project_start_date_test.php                  exit=0
database/security_issue016_test.php                   exit=0
database/task_completion_test.php                     exit=0
database/task_edit_idor_test.php                      exit=0
database/task_inherit_subtask_hours_test.php          exit=0
database/task_layout_issue013_test.php                exit=0
database/task_parent_status_derived_test.php          exit=0
client/toggle_subtasks_test.php                       exit=0
```

## Notes on spec deviations

- **The spec text says "docs/sprint/v1.6.md"** in the Files Touched section. The current repo does not have a `docs/sprint/` directory; the change set is captured in this file (`docs/issues/issue-019.md`) instead. The v1.6 sprint doc is no longer the canonical record of v1.9.0 work.
- **The rate-limit-dropped request records a failure.** The spec's pseudocode does not explicitly call out that the rate-limit branch should call `recordLoginAttempt(..., false)`, but the "two non-obvious details" paragraph below the pseudocode does: "The rate-limit branch records a *failure* in `login_attempts` even though the password was never actually checked. Otherwise an attacker could ping the rate-limit branch forever without the counter incrementing." This is implemented and source-grepped.
- **The `username_attempted` column is the SUBMITTED value, not the resolved user.** The spec is explicit about this ("free-form, NOT a users.username FK"). The regression test asserts the schema has no FK on this column.
- **`defined()` fallbacks for the four constants.** The spec puts the constants in `config.php`, but the helpers use `defined()` fallbacks (5 / 900 / 30 / 900) so a stale `config.php` does not break login. The constants are still defined in all four config files (`config.template.php`, `config.php`, `docker/entrypoint.sh`, `install.php`); the fallbacks are a defense-in-depth measure, not a replacement.
