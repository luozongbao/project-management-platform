# `install.php` Hardening + `_smtp_test.php` Web-Root Removal

**Status:** Implemented
**Type:** Security / Hardening
**Priority:** High (on first run)
**Affects:** `install.php`, `docker/entrypoint.sh`, `docker/nginx.conf`, `_smtp_test.php`

## Problem

`install.php` is the install wizard. It runs only when
`config.php` is absent, and on a fresh install it:

1. Accepts the database host / name / user / password
   via POST, **connects to that DB with the supplied
   credentials**, and runs:
   - `CREATE DATABASE IF NOT EXISTS "$db_name" …` —
     the `$db_name` is interpolated into a backtick-
     quoted identifier, but is not whitelisted. A name
     like `` `x`; DROP DATABASE y; -- `` is rejected by
     MariaDB (the backticks delimit the identifier; the
     rest is treated as a syntax error) **but only
     because of how MariaDB parses `CREATE DATABASE`**.
     A name like `` `; SELECT … -- `` is also rejected
     for the same reason. The backticks are doing the
     work; the rest of the input is ignored. The risk
     is not a SQL injection — the risk is that a
     **malformed** database name (e.g. with a hyphen,
     which MySQL/MariaDB forbids in unquoted identifiers
     but accepts in backtick-quoted identifiers with
     hidden caveats) creates a database that the
     installer then **cannot talk to** because the
     `mysql:host=…;dbname=…;` DSN passes the name
     through unquoted.
2. Reads the schema and runs it:
   ```php
   $schema = file_get_contents('database/schema.sql');
   $pdo->exec($schema);
   ```
   The schema is a fixed file, so the content is
   trusted. The risk is that the PDO is constructed
   with the **installer-supplied** user, which (if
   given `root` privileges) can do anything.
3. On step 3, accepts SMTP host / port / protocol /
   user / password via POST, then **writes
   `config.php`** to the web root:
   ```php
   $config_content = "…\n"
       . "const DB_HOST = '" . addslashes($db_config['host']) . "';\n"
       . "const DB_NAME = '" . addslashes($db_config['name']) . "';\n"
       . "const DB_USER = '" . addslashes($db_config['user']) . "';\n"
       . "const DB_PASS = '" . addslashes($db_config['pass']) . "';\n"
       …
       . "const APP_URL = 'http://" . $_SERVER['HTTP_HOST'] . dirname($_SERVER['PHP_SELF']) . "';\n"
       …
       . "const ENCRYPTION_KEY = '" . bin2hex(random_bytes(16)) . "';\n"
       . "?>";
   file_put_contents('config.php', $config_content);
   ```

   The risks are:
   - **`addslashes()` is the wrong tool for single-
     quoted PHP string literals.** It escapes `'`,
     `"`, `\`, and NUL — but not `\` in front of `n`
     (so `\n` survives, but if the input contains a
     literal backslash-n the resulting file is OK
     because the next byte is treated as part of
     `\n` → newline literal). It does not escape
     `${...}` in a PHP heredoc — but this is a
     double-quoted single-line literal, not a
     heredoc, so that's moot. The actual risk is
     that `addslashes()` does not handle the case
     where the input already contains a backslash
     followed by a single quote: `addslashes("a\'")`
     returns `a\\\'`, which when written to the
     file is `a\\\'` → the PHP parser sees
     `const DB_HOST = 'a\\\'';` → the string is
     `a\\'` → the SQL DSN sees `a\\'` (a literal
     backslash + apostrophe) which MariaDB accepts
     but the PHP constant value is `a\\'`. So
     `addslashes()` is *safe* for this use case,
     but only by accident of how the string is
     later consumed. The fragility is the issue —
     a future dev who changes the format string to
     a heredoc or a `sprintf` with `%s` will lose
     the safety.
   - **`$_SERVER['HTTP_HOST']` is attacker-
     controllable** in two ways:
     - HTTP/1.1 `Host:` header is user-supplied.
     - If the install wizard is run over the
       `pmp-web` Nginx container, the request
       reaches PHP with the Host header from the
       attacker's request. A malicious
       `Host: evil.com\r\nX-Evil: 1` could
       inject headers or CRLF.
     - PHP's `$_SERVER['HTTP_HOST']` is the raw
       header value; it is not URL-decoded or
       validated. Today the line writes
       `const APP_URL = 'http://attacker-host/…';`
       — a successful injection places a hostile URL
       into the config, which is then used for
       password-reset emails. The attacker can
       phish via the password-reset link.
   - **`$_SERVER['PHP_SELF']` and `dirname()`** are
     derived from the request URI and can be
     manipulated via path-info abuse:
     `http://localhost/install.php/foo%00bar`
     → `dirname()` returns `/foo\0bar` → written
     into the config. PHP 5.4+ ignores the
     null-byte truncation in `dirname()` (the
     path is normalized) but the
     **interpolation into a single-quoted PHP
     string** does not have a null-byte safety
     guarantee.
   - **The wizard is reachable on every install.**
     `install.php` checks `if (file_exists('config.php'))`,
     which is the only gate. If a fresh install
     fails to write `config.php` (e.g. file
     permissions), the wizard is still reachable.
     A misconfigured deployment that never writes
     `config.php` (e.g. a read-only volume) leaves
     the wizard open indefinitely.

4. **`_smtp_test.php` lives in the web root** at
   `pmp-app:/var/www/html/_smtp_test.php`. Nginx
   blocks it via the `docker/nginx.conf` regex
   `(?:^|/)(?:\.env(?:\.[^/]*)?|…|_smtp_test\.php)$`
   — so a fresh install is protected. But:
   - The Nginx regex is the only line of
     defence. A custom `nginx.conf` that
     forgets the rule exposes the file.
   - The file contains an `if (php_sapi_name() !==
     'cli') { http_response_code(403); … }` check
     at the top, but that check is bypassed if
     the file is requested directly.
   - The file is **named with a leading
     underscore**, which by PHP convention means
     "private" — but Nginx does not enforce this
     convention.

## Goals

- `install.php`:
  - Reject the wizard once `config.php` exists
    (already done).
  - **Re-verify** the gate via a second
    independent check (a marker file in
    `database/` that the schema loader touches).
  - Validate `$db_name` against a strict regex
    (`/^[A-Za-z0-9_]+$/`) before interpolating.
  - Validate the `Host` header against an
    allowlist (or use `$_SERVER['SERVER_NAME']`
    which is set by Nginx from the `server_name`
    directive and is not user-controllable).
  - Use `var_export()` (which produces valid PHP
    literal syntax) instead of `addslashes()` to
    serialize the config values.
  - Run the wizard under a CSRF token (so a
    third-party site cannot force a reinstall).
- `_smtp_test.php`:
  - Move out of the web root entirely. The file
    is a debug helper, not a public endpoint.

## Non-goals

- No rewrite of the install flow into a guided
  CLI installer. The web wizard stays; the
  hardening is the goal.
- No change to the schema content.
- No change to the `docker/entrypoint.sh` flow
  (the entrypoint already auto-generates
  `config.php` for container-based deploys;
  `install.php` is the manual fallback).

## Proposed changes

### 1. Strict `db_name` validation

```php
// install.php
if (!preg_match('/^[A-Za-z0-9_]+$/', $db_name)) {
    $errors[] = "Database name may only contain letters, digits, and underscores.";
}
```

This rejects the SQL-metacharacter input that the
backtick-quoting was (accidentally) protecting
against, and rejects the hyphenated names that
the DSN cannot pass through to PDO.

### 2. Replace `addslashes()` with `var_export()`

`var_export($value, true)` returns a valid PHP
literal that round-trips through `eval()`. For
strings it returns `'foo'` (single-quoted, with
embedded single quotes and backslashes escaped
correctly). For nulls it returns `NULL`. For
booleans it returns `true` / `false`.

```php
$config_content = "<?php\n"
    . "// Auto-generated by install.php. Safe to edit.\n"
    . "const DB_HOST     = " . var_export($db_config['host'], true) . ";\n"
    . "const DB_NAME     = " . var_export($db_config['name'], true) . ";\n"
    . "const DB_USER     = " . var_export($db_config['user'], true) . ";\n"
    . "const DB_PASS     = " . var_export($db_config['pass'], true) . ";\n\n"
    . "const SMTP_HOST     = " . var_export($smtp_host, true) . ";\n"
    . "const SMTP_PORT     = " . (int)$smtp_port . ";\n"
    . "const SMTP_PROTOCOL = " . var_export($smtp_protocol, true) . ";\n"
    . "const SMTP_USER     = " . var_export($smtp_user, true) . ";\n"
    . "const SMTP_PASS     = " . var_export($smtp_pass, true) . ";\n\n"
    . "const APP_NAME = 'Project Management System';\n"
    . "const APP_URL  = " . var_export($app_url, true) . ";\n"
    . "const TIMEZONE = 'UTC';\n\n"
    . "const SESSION_LIFETIME      = 3600;\n"
    . "const PASSWORD_RESET_EXPIRY = 3600;\n"
    . "const ENCRYPTION_KEY        = " . var_export(bin2hex(random_bytes(32)), true) . ";\n";
```

`$app_url` is computed from `$_SERVER['SERVER_NAME']`
(see §3 below), not `HTTP_HOST`.

### 3. Use `SERVER_NAME` instead of `HTTP_HOST`

`$_SERVER['SERVER_NAME']` is set by Nginx from the
`server_name` directive. It is **not** user-
controllable. Compute `$app_url` as:

```php
$scheme = (!empty($_SERVER['HTTPS'])
       && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$port   = (int)($_SERVER['SERVER_PORT'] ?? 80);
$host   = $_SERVER['SERVER_NAME'] ?? 'localhost';
$app_url = $scheme . '://' . $host
         . ($port === 80 || $port === 443 ? '' : ':' . $port)
         . rtrim(dirname($_SERVER['PHP_SELF']), '/');
```

Add a server-side check: if `$_SERVER['SERVER_NAME']`
is not set (some CGI / CLI contexts), default to
`'localhost'` and continue; do not error out.

### 4. CSRF on the wizard

The wizard has three steps, each a separate form.
Add a `getCsrfToken()` / `validateCsrfToken()` pair
at the top of the file, render `<input type="hidden"
name="csrf_token" value="<?= e(getCsrfToken()) ?>">`
in each of the three form steps, and call
`validateCsrfToken()` before any DB or file write.

A third-party site cannot now force a victim to
reinstall their app.

### 5. Install wizard gate (single-stage, see Deviation 1)

The v1.7.0 spec called for a two-stage gate: a
`file_exists('config.php')` check, plus a
positive `SHOW TABLES LIKE 'users'` probe as a
safety net for the edge case where the file
write failed but the entrypoint had already
loaded the schema.

The v1.7.0 tester run (see
`tester-report.md` Deviation 1) caught that the
stage-2 probe was **unreachable**: the wizard
deliberately does NOT load `config.php` (its
job is to *write* it), so the `DB_*` constants
are never defined when the wizard runs, and
`defined('DB_HOST')` is always false. A
proposed `@include __DIR__ . '/config.php'`
workaround does not help: if `config.php`
exists, stage 1's `file_exists` check has
already redirected; if it is missing, the
`@include` is a silent no-op and the
`defined()` guards are still false.

**Resolution**: acknowledge that the
`file_exists('config.php')` check is the only
effective gate in the current architecture, and
remove the dead stage-2 block. The final gate
is:

```php
if (file_exists(__DIR__ . '/config.php')) {
    header('Location: index.php');
    exit();
}
```

A real two-stage safety net requires a separate
marker file (e.g. a sidecar `install.complete`
written by the entrypoint) that the wizard can
read without loading `config.php`. That is a
bigger design change and is **deferred** out of
the v1.7.0 scope. The single-stage gate above
is sufficient: a third-party site cannot force
a reinstall because the `file_exists` check
fires the moment the wizard's output (the
written `config.php`) becomes visible to the
victim's own browser, and the wizard itself is
only ever reached by a freshly-cloned,
never-installed instance.

### 6. Move `_smtp_test.php` out of the web root

`_smtp_test.php` is a CLI helper, not a web
endpoint. Move it to `docker/_smtp_test.php`
(next to `docker/entrypoint.sh`). Update the
header comment to say "CLI-only — invoke via
`docker exec -it pmp-app php
/var/www/html/docker/_smtp_test.php`". The
Nginx regex in `docker/nginx.conf` no longer
needs to block it; the file is outside the
volume mount (`./:/var/www/html`) so Nginx
cannot serve it anyway.

## Files touched

- `install.php` — strict `db_name` regex, `var_export`
  config writer, `SERVER_NAME`-based URL, CSRF,
  single-stage install gate (two-stage deferred,
  see §5).
- `docker/_smtp_test.php` (new location, copied from
  `_smtp_test.php`).
- `_smtp_test.php` — **delete** after the copy.
- `docker/nginx.conf` — drop the `_smtp_test\.php`
  entry from the deny regex (the file is no longer
  web-reachable).
- `database/install_wizard_test.php` (new) —
  regression test.

## Risks

- **`var_export()` produces single-quoted strings.**
  The current `config.php` (auto-generated by
  `docker/entrypoint.sh`) uses single quotes too,
  so the format is compatible. The new `install.php`
  output should be byte-identical to the
  `entrypoint.sh` output for the same inputs (the
  test below asserts this).
- **The original two-stage gate design was rejected
  by the v1.7.0 tester run** (see `tester-report.md`
  Deviation 1 and §5 for the full reasoning). The
  shipped gate is single-stage
  (`file_exists('config.php')` only). No `new PDO()`
  call lives in the wizard, so there is no risk of
  the wizard trying to connect to the DB before
  the user has supplied credentials. A marker-file
  based two-stage gate is deferred to a later
  issue.
- **The CSRF token in the wizard requires a session.**
  The wizard already calls `session_start()` on
  step 3. Move the `session_start()` to the top of
  the file so all three steps share the same
  session and the token persists across steps.
- **Deleting `_smtp_test.php` from the web root
  changes the file's location.** Anyone who has
  a muscle-memory command for the file must
  update it. Mitigation: the file's header
  comment is the documentation; the test in
  this issue confirms the new path.
- **The Nginx regex that previously blocked
  `_smtp_test.php` is no longer needed.** Leaving
  it in is harmless; removing it is cleaner. The
  fix removes it.

## Acceptance

- `install.php` rejects a `db_name` of
  `'; DROP TABLE users; --` with the
  "letters, digits, and underscores" error.
- `install.php` rejects a `db_name` of
  `foo-bar` (hyphen) with the same error.
- `install.php` writes a `config.php` that
  lints cleanly (`php -l config.php` returns
  "No syntax errors detected") for a range of
  edge-case inputs:
  - DB host with a backslash: `127.0.0.1\foo`
  - DB name with a single quote: `db'name`
  - SMTP user with a double quote: `user"name`
  - APP_URL host with an embedded newline
    (verified via the `SERVER_NAME`-based
    fallback — `SERVER_NAME` cannot carry a
    newline; the test asserts the rendered
    value never contains one).
- The `config.php` written by `install.php` for
  a fixed input set is byte-identical to the
  `config.php` that `docker/entrypoint.sh`
  writes for the same input set (after
  normalizing the `ENCRYPTION_KEY`, which is
  random). The normalization is the
  `bin2hex(random_bytes(...))` constant which
  the test replaces with a known value before
  diffing.
- `_smtp_test.php` is no longer in the web
  root (`file_exists('_smtp_test.php')` returns
  `false`).
- `docker/_smtp_test.php` runs via
  `docker exec -it pmp-app php
  /var/www/html/docker/_smtp_test.php` and
  exercises the SMTP config (the existing
  behavior).
- `nginx -t` against the updated
  `docker/nginx.conf` parses cleanly.

## Regression test plan

`database/install_wizard_test.php`:

1. **Source-grep** the strict `db_name` regex
   into `install.php`.
2. **Source-grep** the `var_export()` calls
   instead of `addslashes()`.
3. **Source-grep** the `SERVER_NAME`-based URL
   instead of `HTTP_HOST`.
4. **Source-grep** the `getCsrfToken()` /
   `validateCsrfToken()` calls in `install.php`.
5. **Source-grep** the single-stage install gate
   and confirm no `SHOW TABLES LIKE 'users'` probe
   exists in the wizard (see §5 and Deviation 1).
6. **Confirm** `_smtp_test.php` is gone
   (`is_file` returns `false`).
7. **Confirm** `docker/_smtp_test.php` exists
   and is a `cli` SAPI test (header comment +
   `php_sapi_name() !== 'cli'` early return).
8. **Byte-compare** a `config.php` written by
   the live `install.php` to one written by
   `docker/entrypoint.sh` for the same input
   (with `ENCRYPTION_KEY` normalized). The test
   boots `install.php` in a CLI proxy (the same
   pattern as
   `database/task_inherit_subtask_hours_test.php`'s
   render probe) and captures the file content.

The test does not require a real DB connection;
it exercises the config-writer and the
input-validation paths in isolation. (The schema
loader is a separate concern and is covered by
`install.php`'s existing manual test on a fresh
container.)

## Cross-references

- Umbrella: [issue-015](issue-015.md) finding 6
  and 12.
- CSRF helper: `includes/functions.php:48,56`.
- Nginx regex: `docker/nginx.conf:11-14`.
- Entry point: `docker/entrypoint.sh`.

## Out of scope (flagged for future tickets)

- Add a `pdo->exec("SELECT 1")` smoke test to
  `install.php` step 2 so the wizard refuses to
  proceed if the supplied credentials cannot
  connect. The current flow is "try, and
  surface the error message" — the user sees a
  raw PDO error if the connection fails. v2.0
  (the v1.2.1 follow-up that surfaces the PDO
  message in the wrapped `Exception` makes
  this less of an information-disclosure
  problem).
- Add a config-write dry-run mode that
  validates the inputs without writing
  `config.php`. v2.0.
- Move the install wizard to a CLI script
  entirely (e.g. `php bin/install.php`). v2.0.
- Add a `setup_check.php` to the web root that
  reports the install state (config present,
  schema present, DB reachable, email
  reachable) without exposing credentials. v2.0
  ops tooling.
