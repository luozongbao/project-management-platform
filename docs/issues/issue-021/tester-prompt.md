# Tester prompt — issue-021 (install.php hardening + _smtp_test.php web-root removal)

## What was changed

Issue-021 closed the install-wizard attack surface and removed `_smtp_test.php` from the web root (see `docs/issues/issue-021.md` for the full spec):

| Area | Files |
| --- | --- |
| **A. Strict `db_name` validation** | `install.php` — step 2 now rejects any `db_name` that does not match `/^[A-Za-z0-9_]+$/` with the error "Database name may only contain letters, digits, and underscores." |
| **B. `var_export()` config writer** | `install.php` — step 3 now writes `config.php` with `var_export($value, true)` for every string. No more `addslashes()`. |
| **C. `SERVER_NAME`-based URL** | `install.php` — `APP_URL` is now derived from `$_SERVER['SERVER_NAME']` (set by Nginx `server_name`, **not** user-controllable) plus `$_SERVER['SERVER_PORT']`. `HTTP_HOST` is no longer read anywhere. |
| **D. CSRF on the wizard** | `install.php` — `startSession()` is called at the very top of the file. Step 2 and step 3 each call `validateCsrfToken($_POST['csrf_token'])` before doing any DB or file work, and each form renders a hidden `csrf_token` input. |
| **E. Two-stage gate** | `install.php` — the existing `file_exists(config.php)` redirect is now augmented with a `SHOW TABLES LIKE 'users'` probe. If the schema loader already created the `users` table, the wizard redirects to `index.php` even if `config.php` is missing (read-only volume, partial install, etc.). |
| **F. `_smtp_test.php` moved out of the web root** | `_smtp_test.php` is **deleted**; `docker/_smtp_test.php` is the new location. The new file keeps the `php_sapi_name() !== 'cli'` early-return guard (defence in depth) and the header now reads "CLI-ONLY". |
| **G. `docker/nginx.conf` cleanup** | The `_smtp_test\.php` entry has been removed from the deny regex (the file is no longer web-reachable, so the rule is redundant). |
| **Regression test** | `database/install_wizard_test.php` — 26 assertions across 8 sections: (1) strict `db_name` regex, (2) `var_export()` writer and no `addslashes()`, (3) `SERVER_NAME`-based URL, (4) CSRF on the wizard, (5) two-stage gate, (6) `_smtp_test.php` gone from the web root, (7) `docker/_smtp_test.php` exists and is CLI-only, (7b) nginx regex no longer references `_smtp_test.php`, (8) byte-compare of `config.php` written by the live `install.php` to the `docker/entrypoint.sh` heredoc format (boots `install.php` in a CLI proxy and captures the file content). |

## How to run the regression test

```bash
docker exec project-management-platform-app-1 php /var/www/html/database/install_wizard_test.php
```

Expected: `Issue-021 install wizard regression test passed.` and `exit=0`. The full suite (8 pre-existing + this one) should also stay green:

```bash
docker exec project-management-platform-app-1 sh -c 'cd /var/www/html && for t in database/*_test.php client/*_test.php; do echo "$t"; php "$t" 2>&1 | tail -2; done'
```

## What to test manually (acceptance criteria)

For each item below, exercise the live UI in a browser (or `curl` for the raw checks) and report PASS/FAIL with a one-line reproduction.

### A. Strict `db_name` validation (install.php step 2)

1. **SQL metacharacter rejection** — on a fresh container (or with `config.php` deleted), submit step 2 with `db_name = "'; DROP TABLE users; --"`. The page should reload with the error **"Database name may only contain letters, digits, and underscores."** and **no** `CREATE DATABASE` query should reach MariaDB. Confirm via `docker exec pmp-db mariadb -e "SHOW DATABASES;" | grep -i drop` (must be empty).
2. **Hyphen rejection** — submit step 2 with `db_name = "foo-bar"`. Same error, no DB created.
3. **Backtick rejection** — submit step 2 with `db_name = "foo\`; SELECT SLEEP(5); --"`. Same error.
4. **Positive case** — submit step 2 with `db_name = "project_management_2026"`. Wizard advances to step 3.

### B. `var_export()` config writer (install.php step 3)

5. **DB host with a backslash** — complete the wizard with `db_host = "127.0.0.1\foo"`, then run `php -l /var/www/html/config.php` from inside the container. Must report "No syntax errors detected".
6. **DB name with a single quote** — complete the wizard with `db_name = "db'name"` (after §A above, only pass this in via a custom build — easiest: edit the strict regex in your local copy to allow the apostrophe just for this run, then revert). `config.php` must lint cleanly and the value of `DB_NAME` must equal the input (round-trips through `var_export`).
7. **SMTP user with a double quote** — complete the wizard with `smtp_user = 'user"name'`. `config.php` must lint cleanly and the value of `SMTP_USER` must equal the input.
8. **APP_URL no longer has CRLF** — `grep -c $'\r' /var/www/html/config.php` (or `od -c /var/www/html/config.php | grep '\\r'`) must return 0. The APP_URL is now derived from `SERVER_NAME`, which the web server controls, so CRLF is unreachable.

### C. CSRF on the wizard

9. **CSRF on step 2** — open the wizard, capture the hidden `<input name="csrf_token" value="…">` from the rendered step-1 → step-2 form, tamper the value to `BROKEN`, submit. Page should show **"Your session could not be verified. Please reload the page and submit again."** and **no** DB connection / no schema load.
10. **CSRF on step 3** — same drill on the step-2 → step-3 form. The page should refuse the POST before any `config.php` write.
11. **Session persists across steps** — load step 1, fill it, advance to step 2 (server-rendered form). The hidden `csrf_token` in step 2 must be the same value as in step 1 (i.e. the session was started at the top of the file, not lazily on step 3). Quick check: `curl -c /tmp/jar -b /tmp/jar http://localhost/install.php | grep -oE 'csrf_token" value="[^"]+"'` twice in a row should print the same value.

### D. Two-stage gate

12. **Negative case** — on a fresh container with no `config.php` and no `users` table, the wizard is reachable.
13. **First stage** — write a `config.php` (`touch /var/www/html/config.php`), then `curl -s -o /dev/null -w '%{redirect_url}\n' http://localhost/install.php` should report a redirect to `index.php`.
14. **Second stage (the safety net)** — delete `config.php` but leave the `users` table behind (e.g. `docker exec pmp-db mariadb -e "CREATE TABLE IF NOT EXISTS users (id INT)" pmp_test`). Now `curl -s -o /dev/null -w '%{redirect_url}\n' http://localhost/install.php` should still redirect to `index.php` because the `users` table probe fires.
15. **Session-less first load** — first hit to `install.php` on a fresh container must not error out (the `users`-table probe is gated on `file_exists('database/schema.sql')` and the `DB_*` constants being defined; the constants come from `require_once 'config.php'` which fails silently in the absent-file case, and the probe is wrapped in a silent-PDO `try`).

### E. `_smtp_test.php` web-root removal

16. **File gone** — `ls -la /var/www/html/_smtp_test.php` from inside the `pmp-app` container should report "No such file or directory".
17. **CLI smoke** — `docker exec project-management-platform-app-1 php /var/www/html/docker/_smtp_test.php` should still run the SMTP probe (or report "SMTP not configured" — both are acceptable; the test is that the file is executable in its new location).
18. **Defence in depth** — `grep -n "php_sapi_name" /var/www/html/docker/_smtp_test.php` should show the `!== 'cli'` early return. If anyone ever re-mounts the file into the web root by accident, the guard will still 403.
19. **Nginx regex** — `grep -n _smtp_test docker/nginx.conf` should report nothing (the entry was removed from the deny regex).

## What is OUT of scope (per the issue)

- A `pdo->exec("SELECT 1")` smoke test on step 2 (separate issue).
- A config-write dry-run mode (separate issue).
- Moving the install wizard to a CLI script (separate issue).
- A `setup_check.php` ops endpoint (separate issue).

## Reporting

Please file findings under `docs/issues/issue-021/tester-report.md`. For each numbered check above, write one line of either:

```
1. SQL metacharacter rejection: PASS — wizard refused "'; DROP TABLE users; --", no CREATE DATABASE reached MariaDB.
```

or

```
3. Backtick rejection: FAIL — reproduced: <paste the failing wizard behaviour>.
```

At the end, summarise:
- Which of the 19 numbered checks passed.
- Whether the regression test runs green.
- Any deviations from the spec (with rationale).
