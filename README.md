# Project Management Platform

A PHP-based project management platform for small teams. It pairs an internal
console (projects, tasks, contacts, users) with a public **client share
portal** that lets stakeholders view read-only progress without logging in.

> **Latest release:** v1.2.0 (2026-10-05) — see [RELEASE.md](RELEASE.md) for the full changelog.
>
> **Unreleased (packaged together):** **v1.3** (feature: Project Start Date — issue-008; bug fix: missing contact columns — issue-009) + **v1.3.1** (patch: dashboard CTA + CSRF hardening from issue-008 / tester-note-001).

---

## What's new in the unreleased v1.3 / v1.3.1

> **Not yet released.** v1.3.0 ships the Project Start Date feature
> and the missing `contacts` column fix; v1.3.1 is a small patch on top
> (dashboard CTA + CSRF hardening on the project create / edit form).
> Both will be tagged and announced in a single release.

### v1.3 — Project Start Date + contact schema fix

- **Project Start Date (issue-008)**
  - New optional `projects.start_date DATE` column, distinct from
    `created_at`. The manager now records when work on the project
    actually began, instead of the client portal reporting the row's
    audit insertion time.
  - The project create / edit form has a third date input next to the
    existing `Expected Completion Date` and `Actual Completion Date`,
    pre-filled from the stored value. Submitting with a malformed date
    is rejected with a clear error; submitting with `start_date` later
    than `expected_completion_date` or `completion_date` surfaces a
    non-blocking warning so historical imports stay importable.
  - The internal project detail page shows `Started: <date>` (hidden
    when unset); the projects list shows `Started: <date>` under each
    card; the client dashboard's `Started` row now reads from
    `start_date` and renders `Not set` when the column is `NULL`
    rather than echoing `created_at`.
  - Migration: `database/migrations/20261006_add_project_start_date.sql`
    (idempotent, guarded by `information_schema`). Existing rows keep
    `start_date = NULL` and continue to render with the `Not set`
    fallback.
  - Regression test: `database/project_start_date_test.php` asserts the
    new column drives the displayed dates everywhere and exercises the
    form's parser for valid / leap-year / malformed / empty inputs.
- **Contact write path no longer crashes (issue-009)**
  - Adds the `company`, `position`, and `address` columns to `contacts`
    that the form has been binding all along (`contact_edit.php`,
    `contact_detail.php`, `contacts.php` search). Without these columns,
    every contact create / edit was failing with MariaDB error
    `1054 (42S22): Unknown column 'company' in 'INSERT INTO'` and the
    user saw the generic "Error saving contact: Database execute
    failed" message.
  - Migration: `database/migrations/20261006_add_contacts_company_position_address.sql`
    (idempotent, guarded by `information_schema`). Existing rows stay
    `NULL` and continue to render with the existing "Not set"
    fallbacks.
  - `Database::execute()` and `Database::query()` now prepend the
    underlying PDO error message to the thrown `Exception` so the next
    schema-drift bug surfaces directly to the form instead of being
    hidden as `"Database execute failed"`.

### v1.3.1 — Dashboard CTA + CSRF hardening (issue-008 / tester-note-001)

- Dashboard "Recent Projects" → "+ New Project" button now routes to
  `project_edit.php` (the create form) instead of `projects.php` (the
  list), so the primary CTA matches the label.
- The project create / edit form now embeds the standard `csrf_token`
  and the POST handler validates it via `validateCsrfToken()` — the
  same CSRF protection that v1.2.0 added to the delete dialog. A
  cross-site request without a valid token is no longer able to
  create or modify a project, including setting `start_date`.
- Regression test `database/project_start_date_test.php` now also
  asserts the dashboard CTA target and the form CSRF inputs.

---

## What's new in v1.2

- **Security hardening — Nginx deny rules (issue-003)**
  - Explicitly block web access to `.env`, `.env.example`, `config.php`,
    `config.template.php`, `.htaccess`, `_smtp_test.php`, `docs/`,
    `database/`, `vendor/`, `includes/`, and source directories. The
    diagnostic `_smtp_test.php` is no longer callable over HTTP.
  - Authenticated and public PHP entry points are whitelisted; the client
    portal remains reachable and the internal console still requires a
    valid session.
- **Root landing page for clients (issue-004)**
  - `/` now hosts the public share-code form (and an "Owner sign in" link)
    so owners and clients share one entry point. New share URLs are
    generated against the root; the legacy `/client/` URL is preserved as
    a 302 redirect to `/` (see v1.1.0).
- **Project-Code-gated deletion (issue-005)**
  - Project delete now opens a confirmation dialog that requires the
    project manager to type the project's Project Code (the existing
    `share_code`). Deletion is submitted as `POST` with the standard CSRF
    token; the server re-checks ownership, CSRF, and the typed code
    before deleting. Direct `GET` requests and requests missing any of
    the three checks are non-destructive. Projects without a Project Code
    cannot be deleted through this flow.
- **Task time estimates (issue-006)**
  - Every task and subtask can carry an optional total-time estimate,
    entered in **hours** or **mandays** (1 manday = 8 hours). The value
    is stored canonically in `tasks.estimated_hours` (`DECIMAL(12,4)`)
    via the new `20261005_add_task_estimated_hours.sql` migration;
    existing tasks stay unestimated.
  - Validation rejects malformed, negative, or non-finite input; the
    empty string still means "not estimated" and zero is not a usable
    weight.
- **Effort-weighted completion (issue-007)**
  - When every sibling in a group has a positive estimate, completion is
    reported as `Σ(estimated_hours × completion%) / Σ(estimated_hours)`.
    If any sibling is unestimated or zero, the group falls back to the
    existing equal-average so legacy and partially-estimated work is
    not silently dropped.
  - Applies to subtask rollups, parent-task derived completion, and the
    project's `project_stats` view (`20261005_weight_project_completion.sql`).
    All project, task, dashboard, and client-portal displays now agree
    on the same server-side formula.

---

## What's new in v1.1

- **Public client portal** (entry at `/`, dashboard at `/client/dashboard.php`)
  — share a project with a 5-5-5 alphanumeric code; clients see a read-only
  dashboard (project info, overall completion, scope, budget, full task tree
  with status and due dates). The domain root hosts the public share-code
  form; the legacy `/client/` URL 302-redirects to `/` so existing bookmarks
  keep working.
- **English / Simplified Chinese** for the client portal, with a language
  switcher, browser header detection, cookie-persisted preference, and
  `?lang=` override.
- **Docker-first install** — `docker-compose.yml`, `Dockerfile`,
  `docker/entrypoint.sh`, and `config.template.php` together reproduce the
  full stack in three containers.
- **Database migrations** folder; the v1.1.0 migration
  (`20261003_add_contacts_phone.sql`) fixes the `contacts.phone` crash on
  task create / view (issue-001).
- **Profile page polish** in v1.1.1 — restores the logged-in navigation and
  aligns the page header, spacing, and responsive layout with the rest of
  the internal console.

---

## Features

### Internal console (authenticated)

- **Projects** — name, description, scope, budget, currency, status, start /
  expected / completion dates, responsible person, share code.
- **Tasks** — hierarchical (parent + unlimited subtasks), status
  (Not started / In progress / Completed / On hold), **effort-weighted
  completion** when all sibling estimates are positive (equal-average
  fallback when any sibling is unestimated or zero), expected + actual
  completion dates, optional **effort estimate** in hours or mandays
  (1 manday = 8 hours, stored canonically as hours), responsible person,
  contact person.
- **Contacts** — global contact database shared across projects, with
  email, phone, mobile, company, position, address, WeChat / Line / Facebook
  / LinkedIn.
- **Users & auth** — registration with email verification, login
  (username + password, Argon2id), password reset via email token, session
  management.
- **Dashboard** — task / project statistics, upcoming deadlines,
  per-project completion percentages.
- **AJAX endpoints** for in-place task progress, status, completion, and
  contact autocomplete.
- **PHPMailer 6.8** for transactional email (invitations, password
  resets).

### Client portal (public, no login)

- **Root entry point** (`/`) — the domain root now hosts the public
  share-code form, so clients and project managers have a single shared
  landing page. An "Owner sign in" link on the form reaches `/login.php`.
- **Legacy `/client/` URL** — preserved as a redirect shim that 302-redirects
  to `/` while passing through the original query string (so existing
  bookmarks like `/client/?code=…` still work).
- **Landing page** with auto-formatting input — uppercase letters and
  dashes are inserted automatically as the user types the share code.
- **Read-only project dashboard** showing:
  - Project name, description, status badge.
  - Overall completion (average of top-level task percentages).
  - Project info: start / expected / completion dates, project manager.
  - Budget in the project's chosen currency.
  - Counts: tasks, completed, open, contacts.
  - Project scope (one bullet per line).
  - Full task tree — every task and subtask with status, due date,
    completion %, and responsible person.
- **Share codes** generated with confusing characters excluded
  (`0/O/1/I/L`).
- **Localization** — English (default) and Simplified Chinese. Set via
  `?lang=zh`, persisted in a 1-year cookie, falling back to
  `Accept-Language`. Switcher is rendered on the landing page and the
  dashboard header.

---

## Quick start (Docker)

The fastest path. Requires Docker 24+ and Docker Compose v2.

```bash
git clone <your-fork-or-this-repo> project-management-platform
cd project-management-platform

# Copy the env template and edit APP_PORT, APP_URL, DB credentials, SMTP.
cp .env.example .env
# edit .env

docker compose up -d
```

Then open `http://localhost:${APP_PORT}/install.php` in your browser and
follow the installer. After install, log in at `http://localhost:${APP_PORT}/`.

To run pending `.sql` files in `database/migrations/` against an existing
database:

```bash
docker compose exec db \
    mariadb -udbuser -p"$(grep DB_PASSWORD .env | cut -d= -f2)" dbname \
    < database/migrations/20261003_add_client_share.sql

docker compose exec db \
    mariadb -udbuser -p"$(grep DB_PASSWORD .env | cut -d= -f2)" dbname \
    < database/migrations/20261003_add_contacts_phone.sql

docker compose exec db \
    mariadb -udbuser -p"$(grep DB_PASSWORD .env | cut -d= -f2)" dbname \
    < database/migrations/20261005_add_task_estimated_hours.sql

docker compose exec db \
    mariadb -udbuser -p"$(grep DB_PASSWORD .env | cut -d= -f2)" dbname \
    < database/migrations/20261005_add_task_estimated_hours.sql

docker compose exec db \
    mariadb -udbuser -p"$(grep DB_PASSWORD .env | cut -d= -f2)" dbname \
    < database/migrations/20261005_weight_project_completion.sql

# v1.3.0 — Project Start Date (issue-008) + contact schema fix (issue-009)
docker compose exec db \
    mariadb -udbuser -p"$(grep DB_PASSWORD .env | cut -d= -f2)" dbname \
    < database/migrations/20261006_add_project_start_date.sql
docker compose exec db \
    mariadb -udbuser -p"$(grep DB_PASSWORD .env | cut -d= -f2)" dbname \
    < database/migrations/20261006_add_contacts_company_position_address.sql
```

The included migrations are idempotent — safe to re-run.

---

## Tech stack

| Layer       | Choice                                              |
| ----------- | --------------------------------------------------- |
| Language    | PHP 8.3 (procedural, per-page)                      |
| DB          | MariaDB 10.11 (MySQL-compatible)                     |
| Web server  | nginx (alpine), reverse-proxied to php-fpm          |
| Email       | PHPMailer 6.8 (SMTP)                                |
| Composer    | One dependency (`phpmailer/phpmailer`)              |
| Auth        | Argon2id password hashing, server-side sessions     |
| Containers  | `pmp-app` (php-fpm), `pmp-web` (nginx), `pmp-db` (mariadb) |

---

## Project layout

```
.
├── ajax/                  AJAX endpoints (progress, status, contact lookup, …)
├── assets/
│   ├── css/               Internal stylesheet
│   ├── js/                Internal JS
│   └── …
├── client/                Public client share portal (no login)
│   ├── index.php          Code-entry landing page
│   ├── dashboard.php      Read-only project dashboard
│   ├── lang.php           ENG / 中文 translation catalog + helpers
│   └── assets/client.css  Client portal stylesheet
├── database/
│   ├── schema.sql         Canonical schema (used by installer + migrations)
│   ├── subtask_functions.sql   Optional trigger helper (see Known gaps)
│   └── migrations/        Numbered, idempotent migration files
├── docker/
│   ├── entrypoint.sh      Container bootstrap
│   └── nginx.conf         nginx site config
├── docs/
│   ├── requirements.md            v1.0 spec
│   ├── 20261003 requirements.md    v1.1 spec (client portal + i18n)
│   └── issues/                    Resolved bug reports
├── includes/
│   ├── Database.php       PDO singleton
│   ├── EmailService.php   PHPMailer wrapper
│   ├── functions.php      Shared helpers (e, formatMoney, generateShareCode, …)
│   ├── header.php / footer.php   Internal layout
│   └── …
├── vendor/                Composer install (PHPMailer)
├── config.php             Generated by installer (not committed)
├── config.template.php    Template for manual setup
├── docker-compose.yml     Three-container stack
├── Dockerfile             php-fpm 8.3 image
├── install.php            First-run installer
├── login.php / register.php / forgot_password.php / profile.php / logout.php
├── dashboard.php / projects.php / tasks.php / contacts.php
├── project_*.php / task_*.php / contact_*.php   CRUD pages
├── index.php              Internal landing page (post-login)
├── composer.json
├── README.md              ← you are here
└── RELEASE.md             Per-version release notes
```

---

## Configuration

Configuration is loaded from `config.php`, which is generated by
`install.php`. To configure manually, start from `config.template.php`:

```php
const APP_NAME = 'Project Management System';
const APP_URL  = 'http://localhost';
```

Database credentials come from `.env` (see `.env.example`). Mail settings
are read at runtime by `includes/EmailService.php` and configured through
the admin UI after install.

## Web access boundaries

The Docker deployment uses Nginx; `.htaccess` rules are not applied. The Nginx
site configuration blocks direct HTTP access to dotfiles, configuration and
deployment files, documentation, database scripts, dependencies, and internal
source directories. Keep those deny rules ahead of PHP routing rules when
changing `docker/nginx.conf`, because the first matching Nginx regex location
is selected.

Intentionally public PHP entry points are the authentication and first-run
pages and the client portal (`client/index.php` and
`client/dashboard.php`). The client dashboard requires the project's random
share code and is read-only. Owner pages and AJAX endpoints must continue to
enforce session authentication and resource ownership in PHP; hiding links or
checking the request referrer is not an access-control mechanism. Diagnostic
scripts such as `_smtp_test.php` must not be callable over HTTP.

After changing the Nginx rules, validate the deployed configuration and smoke
test that private files remain unavailable (adjust the host/port if `APP_PORT`
is not the default):

```sh
docker compose exec web nginx -t
for path in .env .env.example .htaccess config.php config.template.php \
  _smtp_test.php docs/requirements.md database/schema.sql; do
    code=$(curl -sS -o /dev/null -w '%{http_code}' "http://localhost/$path")
    test "$code" = 404 || {
        echo "$path returned $code (expected 404)" >&2
        exit 1
    }
done
curl -fsS -o /dev/null http://localhost/assets/css/style.css
```

---

## Localization

The internal console is English-only in v1.1.0. The **client portal**
(`client/index.php`, `client/dashboard.php`) supports:

- **English** (default)
- **简体中文**

Locale is resolved in this order:

1. `?lang=en|zh` query parameter (also writes a cookie).
2. `client_lang` cookie (1 year).
3. `Accept-Language` request header.
4. Falls back to `en`.

The translation catalog lives in [client/lang.php](client/lang.php). Add a
new language by adding a top-level key to `client_translations()` and
listing the code in `client_supported_langs()`.

To extend localization to the internal console, copy the helper functions
from `client/lang.php` into `includes/functions.php` (or move them there)
and wrap the literals in the page files with `t('…')`.

---

## Database

`database/schema.sql` is the canonical schema. It is loaded by
`install.php` for fresh installs and serves as the reference for
migrations. Apply new ones from `docker compose exec db … < migrations/<file>.sql`.
schema additions:

- v1.1.0: `projects.scope TEXT` (multi-line project scope rendered on the
  client dashboard), `projects.budget_amount DECIMAL(15,2)`,
  `projects.currency CHAR(3)` (THB, USD, CNY, JPY, SGD, EUR),
  `projects.share_code CHAR(17)` (the 5-5-5 code shared with clients),
  `projects.expected_completion_date DATE`, `contacts.phone VARCHAR(20)`.
- v1.2.0: `tasks.estimated_hours DECIMAL(12,4) NULL` — optional per-task
  effort estimate, used as the weight for effort-weighted completion. The
  `project_stats` view is updated so the project's reported completion
  applies the same weighted-or-equal-average rule to its top-level
  tasks
- `contacts.phone VARCHAR(20)` (added in v1.1.0 migration).
- v1.3.0: `projects.start_date DATE NULL` — user-settable project start
  date, distinct from the audit `created_at`; renders on the project
  detail page, the projects list, and the client portal dashboard.
  `contacts.company VARCHAR(255) NULL`,
  `contacts.position VARCHAR(255) NULL`,
  `contacts.address TEXT NULL` — the columns the contact write/search
  code has been using all along; missing from the original schema and
  fixed in the same release.

---

## Development

The app is procedural PHP — one file per route, no framework. Pages
require `config.php` (auto-generated) plus `includes/Database.php` and
`includes/functions.php`, then output HTML directly.

Helpers worth knowing (see `includes/functions.php`):

- `e($s)` — HTML-escape a string for output.
- `formatDateTime($utc, $format)` — display UTC timestamps in the user's
  timezone while preserving SQL calendar dates as entered.
- `formatMoney($amount, $currency)` — render an amount with currency
  symbol.
- `generateShareCode()` — produce a fresh 5-5-5 share code.
- `buildClientShareUrl($code)` — build the absolute client URL.

Database access goes through the PDO singleton:

```php
$db   = Database::getInstance();
$rows = $db->fetchAll("SELECT … WHERE id = ?", [$id]);
$row  = $db->fetchOne("SELECT … WHERE id = ?", [$id]);
$db->execute("UPDATE … WHERE id = ?", [$id]);
```

---
- The auto-subtask trigger (`database/subtask_functions.sql`) is not
  applied because MariaDB ≥ 10.5 disallows recursive stored functions.
  v1.2.0 replaces it with application-level rollups for parent-task
  completion (effort-weighted when all siblings are estimated, equal
  average otherwise), so the manual-update step is no longer required
  for progress display. The optional trigger file is kept for reference
  only.
- Internal app pages remain English-only. The client portal is fully
  i18n (EN / 简体中文). Extending the `client/lang.php` catalog to the
  internal surface is tracked for a future releasee
  them manually. A migration to replace it with application-level rollups
  is planned.
- Internal app pages remain English-only. Client portal is fully i18n.

See [RELEASE.md](RELEASE.md) for the per-version changelog and [docs/issues](docs/issues)
for resolved bug reports.

---

## License

Internal project. License TBD.