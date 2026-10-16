# `database/` — Schema, migrations, and regression tests

This directory owns three things:

1. **`schema.sql`** — the canonical, fresh-install schema. Loaded by the
   `db` service via the bind mount
   `./database/schema.sql:/docker-entrypoint-initdb.d/01-schema.sql:ro`.
2. **`migrations/*.sql`** — additive migrations applied on top of an
   existing schema. Each file is idempotent (uses an
   `information_schema` guard) and is safe to re-run.
3. **`*_test.php`** — regression tests. Most are DB-backed (they
   require a running `pmp-db`); one is structural / source-grep only
   and runs without a DB connection.

---

## Regression tests

The tests in this directory fall into two camps:

| Test | Type | Notes |
|---|---|---|
| `contacts_columns_test.php` | DB + source-grep | Verifies the `contacts` columns added by issue-009. |
| `project_start_date_test.php` | DB + source-grep | Verifies the `projects.start_date` column from issue-008. |
| `task_completion_test.php` | DB | Verifies `calculateTaskCompletionMap()` weighted rollups. |
| `task_inherit_subtask_hours_test.php` | DB + CLI proxy render | Verifies the issue-011 "sum of sub-task hours" inherit mode. |
| `task_parent_status_derived_test.php` | DB | Verifies the issue-012 parent-status derivation rule. |
| `task_layout_issue013_test.php` | source-grep + lint | Pure CSS / markup regression — no DB. |

To run all DB-backed tests:

```bash
docker exec project-management-platform-app-1 \
    php /var/www/html/database/task_inherit_subtask_hours_test.php
```

The structural test (`task_layout_issue013_test.php`) runs without a DB:

```bash
docker exec project-management-platform-app-1 \
    php /var/www/html/database/task_layout_issue013_test.php
```

---

## ⚠️ `shell_exec('php …')` warnings (v1.6.0 / issue-014)

Three tests in this tree (and one in `client/`) shell out to a fresh
PHP process to render a page or capture its HTML:

- `task_layout_issue013_test.php` — `shell_exec('php -l <file>')`
  for a syntax lint (no temp files, safe).
- `task_inherit_subtask_hours_test.php` — `shell_exec('php …')` to
  render `client/dashboard.php?code=…` and assert on the served HTML.
- `client/toggle_subtasks_test.php` — same pattern, also
  `client/dashboard.php`.

The render pattern relies on the `pmp-app` container shipping with
`php` on `PATH`. It is fragile in two ways that the v1.6.0 work
tightened:

1. **Cleanup on failure** — the proxy file is written to
   `client/_issue011_render_<rand>.php` (or similar). The original
   code only `@unlink()`'d on the happy path; if the test failed
   before the unlink, the file was left behind. v1.6.0 wraps the
   unlink in a `register_shutdown_function()` closure so the file
   is always cleaned up, even on a fatal error or early `exit(1)`.
2. **`__DIR__` of the test file** — the proxy file is written next
   to the page being rendered (so `__DIR__/lang.php` is resolvable).
   A test that runs from a different working directory must
   `realpath()` the page script first; the v1.6.0 proxies do this
   in both helpers.

### DO NOT add a `shell_exec('mariadb …')` call

The `pmp-app` container has **no** `mariadb` / `mysql` CLI client.
`shell_exec('mariadb …')` is a silent no-op. The
`client/toggle_subtasks_test.php` cleanup block that used to attempt
to restore the old `subtask_functions.sql` triggers via that path
was removed in v1.5.0 — the live DB has zero triggers and the file
is gone (renamed to `subtask_functions.sql.disabled` in v1.6.0).

If a future test needs to seed the DB, **go through
`Database::execute()`** — never `shell_exec`. The helper lives in
`includes/Database.php` and is available via the test's
`require '../includes/Database.php';` (most tests already do this).

---

## Schema change checklist

When adding a new column:

1. Add a migration in `migrations/<YYYYMMDD>_<short-name>.sql` with
   an `information_schema` guard, no implicit `START TRANSACTION`,
   and a `SELECT '<column> migration OK'` at the end so the loader
   prints confirmation.
2. Mirror the column in `schema.sql` so a fresh install gets it.
3. Add a regression test (DB-backed) that:
   - Creates the test fixture via `Database::execute()`.
   - Asserts the column is queryable.
   - Cleans up via `DELETE` in a `try { } finally { }` block.
4. Update `contacts_columns_test.php` / `project_start_date_test.php`
   / etc. only if you are renaming or dropping the column.

See `database/migrations/` for the eight shipped examples.