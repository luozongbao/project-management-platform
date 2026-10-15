# Client Share-Code Brute-Force Protection

**Status:** Proposed
**Type:** Security / Abuse Prevention
**Priority:** Medium
**Affects:** `client/dashboard.php`, `index.php`, `includes/functions.php`, `database/migrations/`, new `database/client_share_code_protection_test.php`

## Problem

The public client portal at
`client/dashboard.php?code=…` is the read-only
project view that a manager shares with a client by
sending the URL. The share code is a 15-character
string drawn from a 31-character alphabet
(`[A-Z2-9]`, no `0` / `1` to avoid font
ambiguity):

```php
// 5-5-5 grouping, like XXXXX-XXXXX-XXXXX
preg_match('/^[A-Z2-9]{5}-[A-Z2-9]{5}-[A-Z2-9]{5}$/', $code)
```

…which is 15 × 5 bits = 75 bits, NOT 120 as the
v1.6.0 sprint doc says. The alphabet is 31 chars
(26 letters + 5 digits), so `log2(31) ≈ 4.95` bits
per char, × 15 = ~74.3 bits. That is still
unguessable in absolute terms (a 1 GHz attacker
would take ~10^15 seconds to brute-force), but
**defence in depth is missing**:

### 1. No rate limit on `client/dashboard.php`

A script can submit `?code=AAAAA-AAAAA-AAAAA` in a
tight loop. The page renders the project's name,
dates, budget, owner name, all tasks and sub-tasks,
completion percentages, and contact names. The
response is **distinctive** — a 200 vs. 404 is
distinguishable on first byte, and the rendered
content is 1-10 KB depending on project size. The
attacker can sort guesses by response length to
spot outliers (small projects, recently-created
projects). The combinatorial search is still
infeasible at 74 bits, but the response leak makes
targeted guessing cheap (the attacker tries
"PROJ1" and "PROJ2" and similar).

### 2. The `index.php` "Find by Code" form has no rate limit

`index.php` has a public form (no login required)
that POSTs a `code` and redirects to
`client/dashboard.php?code=…`. A script can submit
this form at the same rate as the GET. The form
adds nothing — it is a redirector — but the lack
of rate limit is symmetric with the GET.

### 3. The share code never expires

A code is stored in `projects.share_code` and lives
until the manager deletes the project or manually
regenerates the code (the v1.4 feature `code`
column on `projects`). There is no per-code
"expiry" timestamp. A leaked code (e.g. a manager
sends the URL to a contractor by email; the
contractor's email is later compromised; the
contractor forwards the link) is a forever-leak.

### 4. The code is logged in browser history, server logs, and CDN cache

A URL like
`https://portal.example.com/client/dashboard.php?code=ABCDE-FGHIJ-KLMNO`
ends up in:

- The browser's history.
- The Nginx access log.
- The corporate proxy's access log.
- Any HTTP-referrer to an external resource (e.g.
  Font Awesome, today at
  `cdnjs.cloudflare.com`). The
  [Referrer-Policy
  hardening](issue-020.md) tightens this but the
  default browser behaviour on a navigation is
  `strict-origin-when-cross-origin`, which still
  sends the *origin* to the CDN but **not** the
  full URL — so the code is no longer leaked. Good.

### 5. The code has no per-user "I've seen this code" lockout

If a project has multiple stakeholders (the
manager, a co-manager, a sponsor) and one of them
accidentally shares the URL, there is no way for
the manager to see "this code has been opened 47
times in the last hour from 12 different IPs" and
rotate the code.

## Goals

- Rate-limit `client/dashboard.php` GETs **and**
  `index.php` POSTs by client IP, with a hard
  lockout after N attempts in M minutes.
- Record every code attempt (success or failure)
  in a new `client_share_code_attempts` table for
  ops visibility.
- Add a `share_code_rotated_at` column to
  `projects` so the manager can see when the code
  was last rotated, and so a future v2.0 signed
  URL can be issued.
- Document the recommendation to **rotate the
  code on a regular schedule** (e.g. every 90
  days) and to **never email the code in plain
  text** (today the manager emails the URL
  directly).

## Non-goals

- No 2FA / per-visitor identity on the client
  portal. The portal is read-only; the spec
  allows anonymous reads.
- No change to the code's character set or
  length. The 74-bit space is the spec.
- No change to the schema beyond the new
  `share_code_rotated_at` column and the new
  `client_share_code_attempts` table.
- No change to the public client's
  read-only contract — the manager can keep
  using long-lived codes indefinitely. The
  rotation is *recommended*, not enforced.
- No move to a "magic link" or "OTP via SMS" model
  for client access. v2.0.
- No change to the `?code=…` URL shape today.
  The v2.0 plan is to switch to
  `?code=…&exp=…&sig=…` signed URLs; that is in
  the "Out of scope" section.

## Proposed changes

### 1. New `client_share_code_attempts` table

```sql
-- database/migrations/20261015_add_client_share_code_attempts.sql

CREATE TABLE IF NOT EXISTS client_share_code_attempts (
    id INT PRIMARY KEY AUTO_INCREMENT,
    ip_address VARCHAR(45) NOT NULL,
    code_attempted VARCHAR(20) NOT NULL,  -- the submitted code, not a FK
    project_id INT NULL,                  -- NULL for unknown codes
    success TINYINT(1) NOT NULL DEFAULT 0,
    attempted_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_csca_ip_time (ip_address, attempted_at),
    INDEX idx_csca_project_time (project_id, attempted_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

Idempotent via `IF NOT EXISTS`.

### 2. New `share_code_rotated_at` column on `projects`

```sql
-- database/migrations/20261015_add_projects_share_code_rotated_at.sql

SET @col := (SELECT COUNT(*) FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = 'projects'
               AND COLUMN_NAME = 'share_code_rotated_at');
SET @sql := IF(@col = 0,
    'ALTER TABLE projects
     ADD COLUMN share_code_rotated_at TIMESTAMP NULL DEFAULT NULL
         AFTER share_code',
    'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
```

Backfill existing rows: `UPDATE projects SET
share_code_rotated_at = NOW() WHERE
share_code_rotated_at IS NULL AND share_code IS
NOT NULL;` (idempotent — the second pass is a
no-op).

### 3. Rate-limit helpers

Add to `includes/functions.php`:

```php
function recordShareCodeAttempt(
    string $ip,
    string $code,
    ?int $projectId,
    bool $success
): void;

function isShareCodeRateLimited(string $ip): bool;
```

The policy:

- **20 attempts in 5 minutes** per IP. The cap is
  higher than `login.php`'s 5-in-15 because the
  client portal is anonymous and a misbehaving
  corporate proxy may issue many requests under
  the same IP. The cap is still low enough that a
  brute-force at 100k attempts/sec from a
  residential connection is throttled.
- A **successful** attempt clears the IP's
  per-(IP) failure count.
- An **additional** per-code cap: 5 distinct
  submitted codes from the same IP in 5 minutes
  triggers the per-IP lockout. (Defends against
  the "I have a list of leaked codes and want to
  validate them all" attack.)

The constants live in `config.php`:

```php
const SHARE_CODE_IP_LIMIT = 20;
const SHARE_CODE_IP_WINDOW = 300;     // 5 min
const SHARE_CODE_DISTINCT_LIMIT = 5;
```

### 4. `client/dashboard.php` — apply the limit

```php
$clientIp = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
$code = $_GET['code'] ?? $_POST['code'] ?? '';

if (isShareCodeRateLimited($clientIp)) {
    recordShareCodeAttempt($clientIp, $code, null, false);
    http_response_code(429);
    echo "<h1>Too many requests</h1>"
       . "<p>Please try again later.</p>";
    exit();
}

// existing format check
if (!preg_match('/^[A-Z2-9]{5}-[A-Z2-9]{5}-[A-Z2-9]{5}$/', $code)) {
    recordShareCodeAttempt($clientIp, $code, null, false);
    http_response_code(404);
    include '404.php';
    exit();
}

// existing project lookup
$project = $db->fetchOne(
    "SELECT p.*, … FROM projects p WHERE p.share_code = ?",
    [$code]
);

if (!$project) {
    recordShareCodeAttempt($clientIp, $code, null, false);
    http_response_code(404);
    include '404.php';
    exit();
}

recordShareCodeAttempt($clientIp, $code, $project['id'], true);
// existing render
```

Note: the format-check and the project-lookup
record **failures** in the attempts table before
the limit check is reached again. This is
intentional — the next iteration of the same
attacker hitting the page should still be counted
even if the limit was just *not* hit. The order
is: limit-check → format-check → project-lookup
→ record (success or failure). On a limit-hit,
the failure is recorded but no further DB work
is done.

### 5. `index.php` "Find by Code" form

The form POSTs a `code` to `index.php` and
redirects to `client/dashboard.php?code=…`. The
redirect itself triggers the rate limit. The POST
handler records an attempt and applies the same
limit:

```php
$clientIp = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';

if ($_SERVER['REQUEST_METHOD'] === 'POST'
    && isset($_POST['action'])
    && $_POST['action'] === 'find_by_code') {

    $code = strtoupper(trim($_POST['code'] ?? ''));

    if (isShareCodeRateLimited($clientIp)) {
        recordShareCodeAttempt($clientIp, $code, null, false);
        $errors[] = "Too many requests. Please try again later.";
    } else {
        // existing format check + redirect
        if (!preg_match('/^[A-Z2-9]{5}-[A-Z2-9]{5}-[A-Z2-9]{5}$/', $code)) {
            $errors[] = "Invalid code format.";
            recordShareCodeAttempt($clientIp, $code, null, false);
        } else {
            recordShareCodeAttempt($clientIp, $code, null, true);
            header('Location: client/dashboard.php?code=' . urlencode($code));
            exit();
        }
    }
}
```

The POST records a "success" because the form
validation passed; the GET that follows is a
separate request and records its own success or
failure against the *project*. The two records
together let ops correlate "I posted a valid
code" with "the GET actually reached the project".

### 6. Manager-side rotation nudge

On `project_edit.php` (the v1.4 feature), when a
project is rendered with a `share_code`, the form
adds a small line:

> Last rotated: <code>share_code_rotated_at</code> ago.
> [Rotate code] (regenerates the share code and
> invalidates the previous URL.)

The "Rotate code" button POSTs to
`project_edit.php?action=rotate_share_code&id=…`
and:

- Generates a new code via
  `generateShareCode()` (existing helper).
- Updates `projects.share_code` and
  `projects.share_code_rotated_at = NOW()`.
- Re-issues the `project_contacts` / `client`
  invitation flow as needed.
- Renders the new URL on the next page render.

The CSRF token from [issue-016](issue-016.md)
already covers `project_edit.php`'s POST branch,
so the rotation is CSRF-protected by default.

### 7. Optional: a "this code has been opened N times" badge

The same manager-facing page also reads:

```sql
SELECT COUNT(*) AS opens
FROM client_share_code_attempts
WHERE project_id = ?
  AND success = 1
  AND attempted_at > NOW() - INTERVAL 30 DAY;
```

…so the manager can see recent open counts. No
PII (no IP, no UA) is exposed to the manager —
just the count.

## Files touched

- `database/migrations/20261015_add_client_share_code_attempts.sql`
  (new, idempotent).
- `database/migrations/20261015_add_projects_share_code_rotated_at.sql`
  (new, idempotent).
- `database/schema.sql` — add the same two
  definitions for fresh installs.
- `includes/functions.php` — add the two new
  helpers and the three new constants.
- `config.php` — add the three constants.
- `client/dashboard.php` — apply the rate limit
  at the top of the page.
- `index.php` — apply the rate limit on the
  "Find by Code" form.
- `project_edit.php` — render the
  `share_code_rotated_at` line and the "Rotate
  code" action.
- `database/client_share_code_protection_test.php`
  (new) — regression test.

## Risks

- **The per-IP cap of 20-in-5 is a UX regression
  for an office where multiple users share an
  IP.** A contractor's office where 5 staff
  members each open 5 client-portal pages per
  day is fine (5 × 5 = 25 across 8 hours, well
  under the cap). The risk is a busy BPO that
  opens 30+ client-portal pages per 5 minutes
  under one corporate IP. The constants are
  configurable; ops can raise the cap. A
  successful attempt clears the count, so a
  legitimate user who is browsing normally is
  unaffected.
- **The 5-distinct-codes-in-5-min cap can lock
  out a user who types two wrong codes and then
  the right one.** A typo'd code is one of the
  two distinct submissions; the right code is
  the third. So the third submission *is* the
  distinct-cap. The acceptance test verifies
  this — the lockout only triggers after
  *failures*, not after successes. The helper
  counts distinct codes in the **last 5
  minutes** but only counts failed attempts
  (successes are excluded from the distinct
  count).
- **The `share_code_rotated_at` column is added
  to `projects` mid-table** (`AFTER
  share_code`). The migration is idempotent
  via the `information_schema` guard, so re-
  applying on a live DB is a no-op.
- **The "Rotate code" button invalidates the
  previous URL silently.** A client who has
  bookmarked the URL sees a 404 on the next
  visit and has to ask the manager for the new
  URL. This is the intended behaviour but is
  worth a heads-up. The manager-side UI shows
  a confirmation dialog: "Rotating the share
  code will invalidate the previous URL.
  Continue?".

## Acceptance

- The migration applies cleanly on a fresh
  install and on the live DB (idempotency).
- The 21st `client/dashboard.php` request from
  a single IP within 5 minutes returns HTTP
  429 with the "Too many requests" body. The
  20 prior requests are recorded in
  `client_share_code_attempts` regardless of
  success or failure.
- The 6th **distinct failed** code from a single
  IP within 5 minutes triggers the
  per-IP lockout, even if the total attempt
  count is under 20.
- A successful code lookup **clears** the
  per-IP failure count, so a legitimate user
  who mistypes twice and then types the right
  code is back to 0 failures.
- The `share_code_rotated_at` column is
  populated for every row in `projects` that
  has a non-null `share_code` (backfill).
- The "Rotate code" button in
  `project_edit.php` updates the
  `share_code` and the
  `share_code_rotated_at` in a single
  transaction. The previous URL returns 404
  on the next request.

## Regression test plan

`database/client_share_code_protection_test.php`:

1. Apply both migrations (idempotent).
2. Seed a project with a known share code.
3. Call `recordShareCodeAttempt($ip, $code,
   $projectId, false)` 20 times with distinct
   `code` values. Assert
   `isShareCodeRateLimited($ip) === false`
   after each. Call once more and assert
   `true`.
4. Reset: call `recordShareCodeAttempt($ip,
   $code, $projectId, true)` (success).
   Assert the helper returns `false` on the
   next call.
5. Re-seed and call 5 times with the same
   failing code. Assert the helper still
   returns `false` (5 failures of the same
   code is not 5 distinct codes). Call once
   more with a *different* failing code and
   assert the helper returns `true` (6
   distinct failed codes).
6. Backfill: insert a project with `share_code =
   'ABCDE-FGHIJ-KLMNO'`, `share_code_rotated_at =
   NULL`. Apply the migration. Assert the
   backfill UPDATE populated
   `share_code_rotated_at` to `NOW()`.
7. Clean up: DELETE the project. DROP the
   `client_share_code_attempts` table on
   teardown so re-runs start clean.

The live-render of the
`client/dashboard.php` "Too many requests"
copy is verified by a CLI proxy + curl
assertion (same pattern as
`database/task_inherit_subtask_hours_test.php`).

## Cross-references

- Umbrella: [issue-015](issue-015.md) finding 9.
- Headers: [issue-020](issue-020.md) — the
  `Referrer-Policy: strict-origin-when-cross-origin`
  header mitigates the CDN-cache leak.
- CSRF: [issue-016](issue-016.md) — the
  "Rotate code" button is CSRF-protected by
  the existing `project_edit.php` token.

## Out of scope (flagged for future tickets)

- Switch the public client portal to signed
  URLs (`?code=…&exp=…&sig=…`) where `sig` is
  an HMAC of `code + exp` keyed by
  `ENCRYPTION_KEY`. A signed URL with
  `exp = NOW() + 30 days` lets the manager
  issue a self-expiring link to a contractor.
  The 404 path is replaced with a 401 "Link
  expired" page that prompts the contractor to
  request a new link. v2.0.
- Add a per-visitor "magic link" or OTP model:
  the manager enters the visitor's email; the
  portal emails a one-time link. v2.0.
- Per-code read counter with a soft lockout
  (a successful read counts as 1; a hit on the
  rate-limit threshold pauses the code for 1
  hour). v2.0.
- Anomaly detection: a project whose share
  code is opened from >5 distinct IPs in 24
  hours is flagged in the manager's dashboard.
  v2.0.
- CSP-tightening: the public client portal
  already has a tighter CSP than the internal
  app (no `'unsafe-inline'` in `script-src`).
  The next CSP pass should add nonces. v2.0.
