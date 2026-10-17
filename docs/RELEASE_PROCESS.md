# Release Process

> **Source of truth for the application version is the `APP_VERSION` constant
> in [`config.php`](../config.php) and [`config.template.php`](../config.template.php).**
> All other locations (README banner, RELEASE.md top entry, About popup) MUST
> be kept in lock-step with that constant or the drift-guard in
> `database/about_popup_test.php` will fail.

This document is the canonical release checklist. It is invoked by every new
issue, by the `RELEASE.md` top entry, and by the About popup's "View full
changelog" link.

---

## 1. Bump the `APP_VERSION` constant

Edit `config.php` and `config.template.php` in lock-step. The two files MUST
remain byte-identical for the keys below. The `install.php` writer also
embeds the same string via `var_export($current_version, true)`, so the
hardcoded `$current_version` in `install.php` is the third site.

```php
// config.php and config.template.php
const APP_VERSION = 'vX.Y.Z';
```

If the three sites drift, the regression test in
`database/about_popup_test.php` (layer 1) reports the exact mismatch.

## 2. Append the changelog entry to `RELEASE.md`

Open [`RELEASE.md`](../RELEASE.md) and add a new top entry. The format is
strict so the drift guard can parse it with a regex:

```markdown
## vX.Y.Z — YYYY-MM-DD

- **<area>:** <one-line summary>
- **<area>:** <one-line summary>
```

Use the date the change ships, not the date it was authored. Group bullets
by area (UI, schema, security, docs, tests).

## 3. Update the README banner

The first 3 lines of [`README.md`](../README.md) carry a one-line banner that
includes the current version. Keep it in sync with `APP_VERSION` so anyone
cloning the repo can see the build they have without running the app.

```text
# Project Management Platform — vX.Y.Z
```

## 4. Update documentation and inline attribution

If the release changes any user-facing behaviour, update
[`docs/requirements.md`](requirements.md) or the per-issue spec inside
[`docs/issues/`](issues/). The About popup's "Built by" line is currently
hardcoded to `Me` and the attribution line is hardcoded to
`Vibe coding with AI agent (MiniMaxM3)` — change them here AND in
`includes/header.php` AND in the two client-portal files
(`client/dashboard.php` and `index.php`).

## 5. Run the full test suite

From the project root:

```bash
docker compose exec -T app php database/about_popup_test.php
docker compose exec -T app php database/install_wizard_test.php
docker compose exec -T app php database/task_inherit_subtask_hours_test.php
docker compose exec -T app php database/task_parent_status_derived_test.php
docker compose exec -T app php database/task_completion_test.php
docker compose exec -T app php database/contacts_columns_test.php
docker compose exec -T app php database/project_start_date_test.php
docker compose exec -T app php database/task_layout_issue013_test.php
docker compose exec -T app php database/task_edit_idor_test.php
docker compose exec -T app php database/security_headers_test.php
docker compose exec -T app php client/toggle_subtasks_test.php
```

All 11 tests MUST pass before tagging the release. A self-test on the
`APP_VERSION` drift guard (change the constant in only one site, restore it,
re-run) is also part of the merge gate.

## 6. Smoke-test the About popup in the browser

Open the app in a browser and verify the `?` icon in the navbar reveals the
current version, the attribution, and a working "View full changelog" link.
Repeat on the public landing page (`/`) and on a client dashboard
(`/client/dashboard.php?code=…`) — these are the CSP-tightened surfaces
where a regression would otherwise go unnoticed.
