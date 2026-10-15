# `client/` — Public client portal

The read-only portal a project stakeholder sees via the `share_code`
link. Lives at `client/dashboard.php` and serves a single page that
looks up a project by its `share_code` query parameter, then renders
its tasks / sub-tasks / completion rollup without ever requiring a
login.

`client/assets/client.css` is the *only* stylesheet the portal loads;
the internal app's `assets/css/style.css` is **not** loaded here.
Adding internal-app styles to the portal requires re-stating them in
`client/assets/client.css`.

---

## Languages

`client/lang.php` is the translation dictionary (EN + ZH). Internal
keys use the `dash.*` namespace; the public surface is the only
multi-lingual piece of the project as of v1.6.0.

To add a key:

1. Add the literal to **both** the EN and ZH dictionaries in
   `client/lang.php`.
2. Reference it via `t('dash.your_key')` in
   `client/dashboard.php`.

The issue-011 follow-up test
(`database/task_inherit_subtask_hours_test.php`) asserts both
dictionaries carry the `dash.not_estimated_parent` key — keep new
keys in lock-step across both languages.

---

## Regression test

`client/toggle_subtasks_test.php` is the one regression test for the
portal. It:

1. Seeds three projects in the DB (with-subs / no-subs / no-tasks).
3. Renders `client/dashboard.php?code=<each>` via a CLI proxy.
4. Asserts the sub-tasks toggle markup, the `localStorage` key
   pattern, and the `aria-hidden` selector fix from the v1.5.0
   tester follow-ups.
5. Cleans up the seeded rows in a `try { } finally { }` block.

To run:

```bash
docker exec project-management-platform-app-1 \
    php /var/www/html/client/toggle_subtasks_test.php
```

---

## ⚠️ `shell_exec('php …')` warning (v1.6.0 / issue-014)

`client/toggle_subtasks_test.php::renderDashboard()` shells out to a
fresh PHP process to render the portal HTML. It relies on the
`pmp-app` container shipping with `php` on `PATH`. v1.6.0 added a
`register_shutdown_function()` to guarantee the proxy file is
deleted even if the test exits early. See
`database/README.md` for the same pattern in
`task_inherit_subtask_hours_test.php`.

### DO NOT add `shell_exec('mariadb …')` to any test

The `pmp-app` container has no `mariadb` / `mysql` CLI client — that
call is a silent no-op. The cleanup block that used to attempt to
restore the `subtask_functions.sql` triggers via that path was
removed in v1.5.0, and the trigger file itself was renamed to
`subtask_functions.sql.disabled` in v1.6.0 (issue-014 §1). For any
DB work in tests, go through `Database::execute()` in
`includes/Database.php`.