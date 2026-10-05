# Release Notes

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
  fail until those columns are added.
- The auto-generated subtask trigger (defined in
  `database/subtask_functions.sql`) is rejected by MariaDB ≥ 10.5 because
  stored functions may not be recursive. Subtasks therefore do not
  auto-roll-up completion percentages; managers must update subtasks
  manually. A migration to replace the trigger with application-level
  rollups is tracked for v1.2.
- The internal app (`/`, `/projects.php`, `/tasks.php`, …) is **English
  only** in this release. v1.2 will extend the `lang.php` catalog to the
  internal surface as well.
- The Dockerfile pulls base images from `docker.m.daocloud.io` mirrors
  because `registry-1.docker.io` is not reachable from some networks.
  Adjust the `image:` lines in `docker-compose.yml` if you have direct
  Docker Hub access.

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