# Project Management Platform

A PHP-based project management platform for small teams. It pairs an internal
console (projects, tasks, contacts, users) with a public **client share
portal** that lets stakeholders view read-only progress without logging in.

> **Latest release:** v1.1.1 — see [RELEASE.md](RELEASE.md) for highlights.

---

## What's new in v1.1

- **Public client portal** (`/client/`) — share a project with a 5-5-5
  alphanumeric code; clients see a read-only dashboard (project info,
  overall completion, scope, budget, full task tree with status and due
  dates).
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
  (Not started / In progress / Completed / On hold), completion percentage,
  expected + actual completion dates, responsible person, contact person.
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

Key v1.1 additions to the schema:

- `projects.scope TEXT` — multi-line project scope rendered on the client
  dashboard.
- `projects.budget_amount DECIMAL(15,2)`, `projects.currency CHAR(3)` —
  budget display (currencies: THB, USD, CNY, JPY, SGD, EUR).
- `projects.share_code CHAR(17)` — the 5-5-5 code shared with clients.
- `projects.expected_completion_date DATE`.
- `contacts.phone VARCHAR(20)` (added in v1.1.0 migration).

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

## Known gaps

These are tracked for v1.2:

- `contacts` table is missing `company`, `position`, `address` columns
  that `contact_edit.php` writes and `contacts.php?search=…` filters on.
  Direct visits to `/contacts.php` work; search and create fail until
  those columns are added.
- The auto-subtask trigger (`database/subtask_functions.sql`) is not
  applied because MariaDB ≥ 10.5 disallows recursive stored functions.
  Subtask completion does not auto-roll-up to parents; managers update
  them manually. A migration to replace it with application-level rollups
  is planned.
- Internal app pages remain English-only. Client portal is fully i18n.

See [RELEASE.md](RELEASE.md) for the per-version changelog and [docs/issues](docs/issues)
for resolved bug reports.

---

## License

Internal project. License TBD.