# Release Notes

> **Unreleased** — v1.3.0 + v1.3.1 will be tagged and announced
> together as a single release. The notes below describe the planned
> contents of that release.

## v1.3.1 — Unreleased (2026-10-06)

> **Patch release shipped together with v1.3.0.** No schema changes; no
> migration required. See the **v1.3 / v1.3.1 unified upgrade notes** at
> the bottom of this file.

Patch release responding to QA findings documented in
`docs/issues/issue-008/tester-note-001.md`. No schema changes; no
migration required.

### Highlights

- **Dashboard "New Project" CTA — bug 2**
  - `dashboard.php` "Recent Projects" → "+ New Project" button now
    routes to `project_edit.php` (the create form), not to
    `projects.php` (the list). Matches the label and reduces clicks to
    one. The same CTA on `projects.php` already routed correctly.
- **CSRF protection on project create / edit POST — bug 4**
  - `project_edit.php` now allocates the CSRF token for both create
    and edit (was previously only allocated when editing an existing
    project).
  - The main create / edit form embeds a hidden `csrf_token` input, and
    the POST handler calls `validateCsrfToken()` before processing any
    other field. A cross-site request without a valid token is rejected
    with `"Invalid form token. Please reload the page and submit
    again."` and does not mutate `projects` (including `start_date`,
    `expected_completion_date`, `completion_date`, `status`).
  - The inline delete dialog continues to validate its own CSRF token
    via `project_delete.php` (unchanged).
  - Out of scope for this patch: same CSRF hardening should be applied
    to other write handlers (`task_edit.php`, `contact_edit.php`,
    `register.php`, `forgot_password.php`, `reset_password.php`, AJAX
    endpoints). Track under a separate ticket.

### Upgrade notes

> Use the **v1.3 / v1.3.1 unified upgrade notes** at the bottom of
> this file — they describe the single combined upgrade flow for both
> versions since they ship together.

## v1.3.0 — Unreleased (2026-10-06)

This release introduces an optional, user-settable **Project Start Date**
that the client portal can render in its `Started` row instead of the
audit `created_at` timestamp, and finishes a long-standing schema-drift
fix on the `contacts` table. Shipped together with **v1.3.1** as a
single release — see the **v1.3 / v1.3.1 unified upgrade notes** at the
bottom of this file.

### Highlights

- **Project Start Date (issue-008)**
  - New optional `projects.start_date DATE` column, distinct from
    `created_at`. The manager decides when work on the project actually
    began; the database row's insertion time is no longer mis-reported
    as the project's start date.
  - The project create / edit form gains a third date field next to
    `Expected Completion Date` and `Actual Completion Date`. The form
    pre-fills from the stored value, parses dates with the existing
    `parseProjectDate` helper, and surfaces a **non-blocking** warning
    if `start_date` is later than `expected_completion_date` or
    `completion_date` (historical imports stay importable).
  - The internal project detail page shows `Started: <date>` in the
    project-meta strip (conditional on the column being non-null).
  - The internal projects list shows `Started: <date>` as a small meta
    line under each project card.
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

- **Contact create / edit no longer crashes — missing `contacts`
  columns (issue-009)**
  - Adds `contacts.company VARCHAR(255) NULL`,
    `contacts.position VARCHAR(255) NULL`, and
    `contacts.address TEXT NULL`. The contact write path
    (`contact_edit.php`) and the contacts search (`contacts.php`) have
    always referenced these columns; without the schema the create /
    edit form failed on every submit with MariaDB error
    `1054 (42S22): Unknown column 'company' in 'INSERT INTO'` and the
    user saw the generic "Error saving contact: Database execute
    failed" message. The same drift class was previously fixed for
    `phone` in issue-001 / v1.1.0.
  - Migration:
    `database/migrations/20261006_add_contacts_company_position_address.sql`
    (idempotent, guarded by `information_schema`). Existing rows stay
    `NULL` and continue to render with the existing "Not set" fallbacks.
  - `Database::execute()` and `Database::query()` now prepend the
    underlying PDO error message to the thrown `Exception`, so future
    schema-drift bugs surface directly to the form instead of being
    hidden as `"Database execute failed"`.

### v1.3 / v1.3.1 — unified upgrade notes

Because v1.3.0 and v1.3.1 are packaged together, the upgrade flow is
the combined one. Apply this from any prior release (including a
fresh `v1.2.0` install):

1. Pull the latest code.
2. Re-run the v1.3.0 migrations (both are idempotent):
   ```bash
   docker compose exec db mariadb -u"$DB_USER" -p"$DB_PASSWORD" "$DB_NAME" \
     < database/migrations/20261006_add_project_start_date.sql
   docker compose exec db mariadb -u"$DB_USER" -p"$DB_PASSWORD" "$DB_NAME" \
     < database/migrations/20261006_add_contacts_company_position_address.sql
   ```
   v1.3.1 has no database change.
3. (Optional) Run the extended regression test inside the app
   container. It now also asserts the dashboard CTA target and the
   form CSRF inputs introduced in v1.3.1:
   ```bash
   docker compose exec app php database/project_start_date_test.php
   ```
4. For fresh installs, point `docker-compose.yml` at a clean checkout
   and run `docker compose up -d`. The installer is reachable at
   `http://localhost:${APP_PORT}/install.php`. Fresh databases include
   the new columns via the updated `database/schema.sql`.

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
> and is **resolved in v1.3.0** (see issue-009 below).

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