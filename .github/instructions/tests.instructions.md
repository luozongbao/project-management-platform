---
description: "Use when writing, running, or debugging the regression tests in database/ and client/. Covers the plain-PHP harness convention, the PHP 8.5 requirement, exit-code detection, DB seeding and cleanup, source-grep assertion pitfalls, live-render proxies, and the self-test step that proves a regression net is real."
applyTo: ["**/*_test.php"]
---

# Regression test conventions

There is **no PHPUnit, no composer script, and no CI**. Every test is a standalone PHP CLI
script that decides pass/fail by its own exit code.

## Running

`php` is **not on the host PATH** — a bare `php test.php` returns exit 127.

```bash
docker exec project-management-platform-app-1 php /var/www/html/database/<test>.php
# or
docker compose exec -T app php database/<test>.php
```

The container is a **dev convenience**, not a requirement — the tests are plain PHP CLI
scripts and run identically against any **PHP 8.5** binary with a reachable MariaDB 10.11
(the DB-backed ones read `config.php` for credentials). A non-Docker developer runs
`php database/<test>.php` locally.

Because PHP 8.5 is the floor, a test may use 8.4+/8.5-only syntax — but a test that
passes on the dev container and fails on an 8.3 host is a false green. Do not lower the
floor to make an old host work.

## Detecting success

Success markers are **inconsistent** across the suite — some print `ALL ASSERTIONS PASSED`,
some `All checks passed.`, some free-form prose, some nothing. **Detect by exit code 0.**

- Never treat "no output" as a pass.
- Never grep for the literal `Failed` — `Failed: 0` is an *informational* line.
- A reliable check is: exit code 0 **and** the output does not contain `ASSERTION(S) FAILED`.

````bash
out=$(docker exec project-management-platform-app-1 php "/var/www/html/$t" 2>&1); code=$?
nf=$(echo "$out" | grep -oE '^Failed: [0-9]+' | tail -1 | grep -oE '[0-9]+')
[[ $code -eq 0 && "${nf:-0}" == "0" ]] && ! echo "$out" | grep -qiE 'ASSERTION\(S\) FAILED'
````

## Harness shape

Two `ok()` dialects exist; follow whichever the surrounding file uses.

````php
$failures = [];
function ok($label, $ok, $detail = '') {
    global $failures;
    if ($ok) { echo "  [PASS] $label\n"; }
    else { $failures[] = $label . ($detail !== '' ? " — $detail" : '');
           echo "  [FAIL] $label" . ($detail !== '' ? " — $detail" : '') . "\n"; }
}
````

Sections are numbered (`// ---- 1. …`) with `echo "## A. …"` headers, and the file ends with a
summary plus a non-zero `exit(1)` on failure.

**Bootstrap order matters:** DB-backed tests `require_once __DIR__ . '/../config.php'` **first**,
then `includes/Database.php`, then `includes/functions.php`. Structural-only tests skip
`config.php` and use `realpath(__DIR__ . '/..')` as the root.

## Seeding and cleanup

- Seed with a **random marker** (`bin2hex(random_bytes(8))`) so re-runs never collide with
  `UNIQUE` constraints.
- Seed a **dedicated user → project → entity chain** so cleanup is targeted.
- `users` has `username VARCHAR(100) NOT NULL UNIQUE` — always populate `username`.
- Cleanup is **unconditional**: `try { … } catch (Exception $e) { }`, or
  `register_shutdown_function()` so a fatal error cannot leak seed rows.
- Cleanup failures print a warning to STDERR but must never mask assertion failures.
- FK cleanup order is **tasks → project_contacts → contacts → projects → users**.
- `tasks.responsible_person_id` is `NOT NULL` — delete the task row rather than detaching it.
- `database/task_hierarchy` is a **VIEW** — `DELETE` on it fails. Delete from `tasks` directly.

## Source-grep assertions

- Strip comments before a negative assertion, or a comment like
  `// we used to call addslashes()` will match it. Use the `strip_php_comments()` helper
  pattern from `database/install_wizard_test.php`.
- Use **single-quoted** PHP strings for any regex containing `$` (`$_POST`, `$_GET`,
  `$_SESSION`) — otherwise PHP interpolates before the regex engine sees it.
- Use `~` as the PCRE delimiter when the pattern contains `#` (a `#` delimiter broke on
  `#expected_completion_date`).
- A selector or literal can survive in a comment while the rule body is deleted — assert on
  the **rule body**, not just the selector string.
- A repo-wide grep is not a scoped assertion: when a check is about one function, capture
  that function's body first, then assert on the capture.
- Grep for the **assignment line number** before referencing a variable in a large test file.
  A variable assigned ~45 lines *after* the assertion block that uses it makes every
  `strpos($fnCode, …)` silently evaluate against null. `php -l` cannot catch this.

## Live-render proxies

````php
$proxy_file = __DIR__ . '/../client/_render_' . bin2hex(random_bytes(4)) . '.php';
file_put_contents($proxy_file, $proxy);
register_shutdown_function(static function () use ($proxy_file) {
    if (is_file($proxy_file)) { @unlink($proxy_file); }
});
$rendered = shell_exec('php ' . escapeshellarg($proxy_file) . ' 2>&1');
@unlink($proxy_file);
````

- The proxy file must be written **next to the page being rendered** (so `__DIR__/lang.php`
  resolves) and the target must be `realpath()`'d.
- In the proxy: do all session setup first, then `ob_start()`, then any debug output, then
  `require $target`, then `ob_get_clean()`. **Any echo before `ob_start()` makes
  `session_start()` fail silently**, the CSRF check then rejects everything, and the failure
  surfaces as a misleading "config not written" error.
- `session.use_strict_mode` must be `0` before `session_id()`, and `session.use_cookies` `0`,
  or the CLI session never starts.
- A CLI session id may contain only `[A-Za-z0-9-,]` — use `bin2hex(random_bytes(16))`, not an
  underscore-bearing name.

## Live-HTTP tests

- Probe **canonical** URLs (`/login`, `/projects/858`), never legacy `.php` paths — a 301 has
  no body, no CSP header, and no `Set-Cookie`, so the assertion checks nothing.
- Set `CURLOPT_FOLLOWLOCATION` when following redirects.
- **Extract cookies from the response headers**, not from a cookie jar — libcurl 8.22 in this
  container silently drops cookies written to a fresh temp file.
- Inside the app container the web server is `http://web`, **not** `http://localhost`.
- Rate limits use `count > limit`, so **N attempts are allowed and the (N+1)th is denied**.
  Assert `(limit + 1)` allowed, then the next denied.

## Proving the net is real

A regression test that always passes is a false positive. The standard workflow is:

1. Implement the fix → write the test → confirm it passes.
2. **Revert the fix** → confirm the test fails with a failure that points at the broken
   behaviour.
3. Restore → confirm it passes again.

````bash
git stash push -u -m "wip" && <run test>; git stash pop
````
