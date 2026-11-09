---
description: "Use when editing PHP application code in this repo — the root page files, includes/, ajax/, and the client portal. Covers the PHP 8.5 floor, the config.php-only configuration contract, the page skeleton, ownership enforcement in SQL, form and delete handling, the AJAX contract, and the domain rules that look wrong but are not."
applyTo: ["*.php", "includes/**/*.php", "ajax/**/*.php", "client/dashboard.php", "client/index.php", "client/lang.php"]
---

# PHP application conventions

## Runtime and install targets

**PHP 8.5 is the floor.** The `Dockerfile` pins `FROM php:8.5-fpm-alpine`; 8.3/8.4 carry
unpatched vulnerabilities. You may use 8.4+/8.5-only syntax freely. Do **not** regress the
floor back to 8.3 in any of these four places — they are a set and must all read 8.5:
`"php": ">=8.5"` in `composer.json`, "PHP 8.5 or higher" in `install.php`, "PHP 8.5" in the
`README.md` tech-stack table, and `FROM php:8.5-fpm-alpine` in the `Dockerfile`.

**Docker is development-only.** Production runs on plain LAMP / shared hosting
(Hostinger / cPanel Apache, `public_html`). The application must install and run with
Docker entirely out of the picture. Consequences for app code:

- **Configuration comes from `config.php` and nothing else.** No `getenv()`, no `$_ENV`,
  no `$_SERVER` lookups for settings, no `.env` parsing in application code. (The test
  harnesses and `_diag.php` use `getenv()` — that is fine, they are not app code.)
- `docker/entrypoint.sh` translates environment variables into `config.php` **before** the
  app starts. That translation is the Docker-specific part, and it lives in `docker/`, not
  in `includes/`. If a feature needs a new setting, add a `config.php` constant and let
  both config paths emit it.
- **Never add a code path that only works under Docker.**
- A new route, rewrite, or deny rule must work under **both** web servers: Nginx
  (`docker/nginx.conf`) and Apache (`.htaccess`). The two configs are checked
  against each other by `database/apache_nginx_parity_test.php` — run it after
  touching either one.

## Apache 2.4 traps (the production target)

The non-Docker install path runs on Apache (Hostinger / cPanel). These three
constraints have each caused a total outage:

| Constraint | Consequence if violated |
|---|---|
| `<DirectoryMatch>` is **server-config context only** | Apache rejects the whole `.htaccess` → **500 on every request**. Use `RewriteRule … [R=404,L]` for directory denies. |
| `mod_rewrite` must be **enabled** | `RewriteEngine On` → `Invalid command` → **500 on every request**. The official `php:apache` image needs `a2enmod rewrite`. |
| `<FilesMatch>` authz runs **before** the rewrite (fixup) phase | An unconditional `<FilesMatch>` turns every intended 404 into a **403**. Keep them inside `<IfModule !mod_rewrite.c>`. |

Always `php -l` every changed file — and note that Apache silently ignores
`.htaccess` syntax it cannot parse in some contexts, so a 200 on a file that
should be denied means the rule never ran.
  (`docker/nginx.conf`) for dev and Apache (`.htaccess`) for prod. Add the rule to both.

## Page skeleton

Root pages are **routing targets** — the router `require`s them by filename, so they are not
independently reachable and must not assume a URL. Every root page follows the same shape:

````php
<?php
require_once 'config.php';
require_once 'includes/Database.php';
require_once 'includes/functions.php';

requireLogin();                      // FIRST — before any DB work

$db = Database::getInstance();
$user_id = getCurrentUserId();
$task_id = $_GET['id'] ?? null;      // injected by the router for /tasks/<id>
?>

<?php include 'includes/header.php'; ?>
<link rel="stylesheet" href="<?= e(asset_url('/assets/css/pages/tasks.css')) ?>">
...
<?php include 'includes/footer.php'; ?>
````

Set `$title`, `$show_nav = true`, `$use_v2_shell = true` **before** including the header.

## Ownership is enforced in the SQL

There is no role system — access derives from `projects.responsible_person_id`. Always join
`projects` and filter on the owner:

````php
$task = $db->fetchOne(
    "SELECT t.*, p.name AS project_name
     FROM tasks t JOIN projects p ON t.project_id = p.id
     WHERE t.id = ? AND p.responsible_person_id = ?", [$task_id, $user_id]);
if (!$task) { redirect('/tasks'); }
````

`includes/task_edit_auth.php` holds the three assignment guards
(`taskEditCanAssignResponsible`, `taskEditCanAssignContact`, `taskEditCanUseProject`).
Use them instead of existence-only checks.

## Form handling

Create and edit share one file. The CSRF check wraps the entire work branch:

````php
$errors = [];                                   // must exist before anything appends
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validateCsrfToken($_POST['csrf_token'] ?? null)) {
        $errors[] = 'Your session could not be verified. Please reload the page and submit again.';
    } else {
        // read + validate into $errors[]
    }
    if (empty($errors)) {
        $db->execute("INSERT …", $params);
        $id = (int)$db->lastInsertId();
        redirect('/tasks/' . $id, 'Task created successfully.', 'success');
    }
    // fall through and re-render the form with $errors
}
````

- Validation errors are plain strings in `$errors`, rendered as an `.alert--danger` block.
- On failure the form re-renders with the submitted values.
- Slugs are assigned **on insert only** — they are immutable (`generateUniqueSlug()`).
- Every form needs `<input type="hidden" name="csrf_token" value="<?= e(getCsrfToken()) ?>">`.

## Delete endpoints

`task_delete.php`, `project_delete.php`, `contact_delete.php` are **POST-only** and follow a
fixed 5-step shape:

1. `requireLogin()` plus a method check that redirects on anything other than POST.
2. Validate the id (`ctype_digit`, `>= 1`).
3. Ownership check via the JOIN query → `redirect(..., 'danger')` if absent.
4. `validateCsrfToken()`.
5. **Typed confirmation** — `hash_equals($name, trim($_POST['confirmation_name']))`.

Deleting a parent task cascades to its subtree (`ON DELETE CASCADE`).

## AJAX endpoints

`ajax/*.php` all follow: `header('Content-Type: application/json')` → `isLoggedIn()` → 401 →
method check → 405 → read `json_decode(file_get_contents('php://input'), true)` → CSRF →
ownership → DB → `json_encode(['success' => true, …])`.

Every mutating endpoint finishes with `syncProjectTaskCompletions($db, $projectId)`.

## Flash messages

`redirect($url, $message, $type)` stores the message in the session; `displayMessage()`
renders it once from `includes/header.php`. Types: `'info'`, `'success'`, `'danger'`.
`redirect()` **rejects non-absolute URLs** — always pass a leading `/`.

## Queries

- Prepared statements with `?` placeholders, always.
- The only interpolation into SQL is a hard-coded table allow-list (`projects`, `tasks`, `contacts`).
- Build `WHERE` clauses from an array of fragments joined with `AND`, collecting params in
  parallel:

  ````php
  $where_conditions = ['p.responsible_person_id = ?'];
  $params = [$user_id];
  $where_clause = 'WHERE ' . implode(' AND ', $where_conditions);
  ````

- Whitelist `?status=` values against the enum before use — an arbitrary value is not a
  probe vector.

## Lists, filters and pagination

There is **no pagination** — list pages render the full result set; `LIMIT` appears only in
"recent N" widgets. Filtering is a plain `GET` form that works with JS disabled and is
bookmarkable. Two distinct empty states are standard: "no results for this filter" versus
"you own nothing yet".

## Style

- 4-space indent, `function foo() {` with a space before the brace, `} else {`, `} elseif (...) {`.
- Long docblock at the top of every file describing its contract.
- `//` comments explain **why** and cite the issue number (`// Issue-034 (v1.8.0) — …`).
- `unset($x)` after any `foreach ($x as &$x)` reference loop.
- Root pages use plain relative includes (`require_once 'includes/functions.php'`);
  `includes/` and `client/` use `__DIR__ . '/../…'`.

## Domain rules that look wrong but are not

- `deriveParentStatus()` uses the **strict** rule: a parent is `completed` only when **all**
  children are completed.
- `deriveParentStatusFromCompletion()` is percentage-driven: `on_hold` is sticky, then
  `completed` at `>= 99.5`, then `in_progress` at `> 0`.
- `on_hold_reason` and `delay_reason` are **two independent nullable columns**. A task can be
  both on hold and delayed; the two blocks are never mutually exclusive.
- `isTaskDelayed()` does **not** exclude `on_hold`.
- Parent rollups are computed in **PHP**, not by database triggers.
  `database/subtask_functions.sql.disabled` is historical reference only.
