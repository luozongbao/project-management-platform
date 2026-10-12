# Release Notes

## v1.5.0 — 2026-10-12

Parent-task model upgrade. Adds an **inherit mode** for a parent
task's effort so the user no longer has to keep the parent in sync
with the sum of its sub-tasks' estimates by hand, and makes a
parent's **status** derive from its sub-tasks so the project
rollup, the task list, and the client portal all agree on what a
parent is doing. The two changes share the same parent surface
(`task_edit.php` / `task_detail.php`) and are released together so
the validation rules and the display wiring land in a single
coordinated change. One small additive migration. No breaking API
changes.

### v1.5.0 — Tester follow-ups (added 2026-10-12)

Two tester rounds (issue-010 and issue-011) returned with bug
reports against the v1.4.1 / v1.5.0 surface. All reported bugs are
fixed in this release; the fixes do not change the public
behaviour of the inherited mode, but they do tighten the
display contract so the user always sees a meaningful label
rather than an empty cell.

- **Issue-011 tester-note-001 bug 1 — "Not estimated (no sub-task
  estimates)" copy on all four display surfaces.** When a parent
  is in inherit mode and **all** of its sub-task estimates are
  `NULL` / `0` / negative, all four display surfaces now render
  the literal "Not estimated (no sub-task estimates)" label in
  place of the previously hidden / mismatched copy:
  - `task_detail.php` (parent block): the previous yellow
    warning banner that conflated "no estimate" with
    "completion will use equal-average fallback" has been
    removed; the same span is now reused with the spec
    literal.
  - `tasks.php` and `project_detail.php`: the previously
    hidden `Total Time` row now renders the spec literal in
    the same `<span class="task-estimate">` element so the
    row's column alignment is preserved.
  - `client/dashboard.php` (read-only public surface): the
    spec literal is rendered via the new translation key
    `dash.not_estimated_parent`, defined in both EN and ZH
    in `client/lang.php`. EN value matches the internal-app
    literal verbatim; ZH value: "暂未估算(子任务均未填写工时)".
  - Regression test extension: `database/task_inherit_subtask_hours_test.php`
    now source-greps all four surfaces for the spec literal /
    the `t('dash.not_estimated_parent')` call, source-greps
    `client/lang.php` for the new key in both EN and ZH
    dictionaries, and live-renders the unauthenticated client
    dashboard to confirm the translated string reaches the
    served HTML.
- **Issue-010 tester-note-001 — three small bugs in the sub-tasks
  toggle.** All three were fixed against the v1.4.1 surface and
  are listed here so the v1.5.0 changelog captures them.
  - **Bug 1 (a11y):** `client/dashboard.php` `applyState()`
    selector was `section.querySelectorAll('ul.task-tree')`,
    which matched the root `<ul class="task-tree root">` and
    flipped its `aria-hidden`, hiding the top-level workstream
    names from screen readers in the default "hidden" state.
    Selector scoped to `ul.task-tree:not(.root)` to mirror the
    CSS rule.
  - **Bug 2 (test coverage):** `client/toggle_subtasks_test.php`
    only asserted the literal `aria-hidden` substring and
    silently accepted the buggy selector. New assertion
    `strpos($with_subs_html, 'ul.task-tree:not(.root)')` locks
    the fix in.
  - **Bug 3 (dead code):** the test's cleanup section
    attempted to restore the old `subtask_functions.sql`
    triggers via `shell_exec('mariadb … < $tmp')`. The
    `pmp-app` container has no `mariadb` client, so the call
    was a silent no-op. The triggers are also no longer used
    by the app (parent completion is now computed in PHP via
    `calculateTaskCompletionMap()`), so the entire restore
    block was removed and replaced with a `SELECT 1` DB
    connectivity sanity check.

### Highlights

- **Parent effort inherit mode (issue-011)**
  - New nullable column `tasks.inherit_subtask_hours TINYINT(1) NULL`
    (default `NULL`; existing rows are unchanged). The flag is only
    surfaced on the edit form when the task already has at least
    one sub-task — the parent-must-be-created-first invariant from
    the original issue brief is preserved.
  - New helper `resolveTaskEstimateHours(?array $task, array $childTasks)`
    in `includes/functions.php` is the **single source of truth**
    consumed by all four display surfaces:
    - When `inherit_subtask_hours = 1`, the function returns the
      sum of the children's positive `estimated_hours` (zero and
      NULL child estimates are excluded), or `NULL` if no child has
      a positive estimate.
    - When the flag is `0` or `NULL`, the function returns the
      stored `estimated_hours` (with the existing null / zero /
      negative guards) so manual-mode parents behave exactly as
      they did before this release.
  - The task edit form (`task_edit.php`) renders a new checkbox
    **"Use sum of sub-task hours as total task time"** (EN) /
    inline equivalent on the internal app. When ticked, the
    `Total Task Time` numeric input is disabled, an inline script
    toggles the disabled state on click, and a read-only hint
    shows the current live sum. A **server-side defence** forces
    `estimated_hours = NULL` on save whenever the flag is `1`, so
    even a hand-crafted POST cannot leave a stale manual value in
    the row.
  - Validation: editing a parent that has sub-tasks with the flag
    off and an empty / zero / non-positive `Total Task Time` now
    fails with the existing error-pattern:
    *"Tasks with sub-tasks must specify a non-zero total task time,
    or check 'Use sum of sub-task hours'."* — the user is
    steered to either pick a number or tick the box.
  - All four display surfaces now agree on the number:
    - `task_detail.php` (parent block)
    - `tasks.php` (list view, N+1-safe — child estimates are
      loaded in a single follow-up query for parents with the
      flag set, not row-by-row)
    - `project_detail.php` (the project-level task table now
      shows a `Total Time` cell that was previously missing
      entirely)
    - `client/dashboard.php` (read-only client portal, the
      number alone — no inherit-mode indicator on the public
      surface per the issue spec)
  - Inherit-mode rows render a small `fa-link` icon next to the
    number in the internal console so the manager can see at a
    glance that the value is computed, not typed. If a parent
    is in inherit mode but none of its children have positive
    estimates, `task_detail.php` shows an `alert alert-warning`
    banner so the manager is not silently left with a missing
    number.
  - Migration: `database/migrations/20261012_add_task_inherit_subtask_hours.sql`
    (idempotent, guarded by `information_schema.COLUMNS`). Fresh
    installs are covered by the matching `database/schema.sql`
    update. Existing rows are untouched (the column is `NULL`
    by default and `NULL` is treated as "manual mode").
  - Regression test: `database/task_inherit_subtask_hours_test.php`
    asserts the column exists in the right slot of the table,
    exercises `resolveTaskEstimateHours()` against twelve pure-function
    cases (inherit, manual, mixed-child, rounding, zero / null /
    negative children), and runs a live DB round-trip that
    toggles the flag and confirms the stored `estimated_hours`
    is forced `NULL` on save. Source-grep checks confirm all
    four display surfaces call the helper, the form binds the
    field, the migration declares the column with the
    information_schema guard, and `schema.sql` declares the
    column for fresh installs.

- **Parent status derived from sub-tasks (issue-012)**
  - `deriveParentStatus(?string $currentStatus, array $childStatuses)`
    in `includes/functions.php` is the **single source of truth**
    for parent → child status rollup, consumed by both the
    server-side `syncProjectTaskCompletions()` extension and the
    new `ajax/parent_hold.php` endpoint:
    - **on_hold is sticky** — once a parent is held, subsequent
      sub-task changes do **not** auto-resume it; the manager
      must explicitly press *Resume* on the task detail page.
    - Otherwise, the derived status is: any child `in_progress`
      → `in_progress`; otherwise any child `completed` →
      `completed`; otherwise `not_started`. (The implementation
      follows the exact spec the issue brief documents; the rule
      is documented and unit-tested.)
  - `syncProjectTaskCompletions()` now also re-derives the
    parent's `status` and aligns `completion_date`:
    - On transition to `completed`, `completion_date` is set to
      `COALESCE(completion_date, NOW())` so a completed parent
      never has a missing completion date.
    - On demotion **from** `completed`, `completion_date` is
      cleared to `NULL` so a re-opened parent does not keep
      stale "completed" history.
    - A `WHERE completion_percentage <> ? OR status <> ?` guard
      keeps `updated_at` honest when the helper is a no-op.
  - `task_detail.php` shows a small **"Auto"** pill (`fa-magic`,
    `.derived-pill`) next to the status badge for any task that
    has sub-tasks — visual cue that the value is derived, not
    typed. The pill is suppressed on the client portal.
  - `task_edit.php` now renders the **status** field as a
    **read-only** badge for any task that has sub-tasks (the
    dropdown is replaced with the current status label and an
    explanation text: *"This task has sub-tasks, so its status
    is derived automatically. To put it on hold, use the 'On
    Hold' action on the task detail page."*). The submitted
    `status` value is **ignored** for parents with sub-tasks
    (server-side defence) so hand-crafted POSTs cannot desync
    the rollup.
  - New endpoint `ajax/parent_hold.php` is the only legitimate
    path that mutates a parent's status while it has children:
    - `action=hold` sets `tasks.status = 'on_hold'`.
    - `action=resume` re-derives the status from the current
      sub-task statuses via `deriveParentStatus(null, …)` and
      updates `completion_date` in the same way
      `syncProjectTaskCompletions()` does.
    - Both paths call `syncProjectTaskCompletions($db, $projectId)`
      at the end so the project's rollup, the task list, and the
      client portal are all updated atomically.
    - CSRF, login, ownership (project owner), and parent-with-
      children constraints are enforced. Errors return
      `400` / `403` / `404` / `405` as appropriate. The
      endpoint detects `X-Requested-With: XMLHttpRequest` and
      returns JSON; otherwise it redirects back to the task
      detail page so the buttons can be plain form submits.
  - `ajax/complete_task.php` now calls `syncProjectTaskCompletions()`
    directly in both branches (sub-task and top-level). The
    helper extension makes the old local `updateParentTaskCompletion()`
    wrapper redundant, so it was removed in the same change to
    keep a single source of truth.
  - Regression test: `database/task_parent_status_derived_test.php`
    exercises `deriveParentStatus()` against twelve pure-function
    cases plus an exhaustive 4×4 current × child matrix, runs a
    live DB round-trip that exercises: (a) all-not-started →
    not_started, (b) one in_progress child → in_progress, (c)
    all children completed → completed with `completion_date`
    set, (d) demotion to in_progress clears `completion_date`,
    (e) on_hold stickiness across `syncProjectTaskCompletions()`,
    (f) resume derivation. Source-grep checks confirm
    `ajax/parent_hold.php` calls the right helpers,
    `task_detail.php` renders the pill and the buttons, and
    `includes/functions.php` exports both new helpers.

## v1.4.1 — 2026-10-12

Client-portal-only feature release. Adds a sub-tasks toggle to the
public client dashboard so a stakeholder can collapse or expand the
nested sub-trees under each top-level workstream without scrolling
through a wall of sub-tasks. No DB changes, no migrations, no new
endpoints. The state is stored per device in `localStorage`.

### Highlights

- **Sub-tasks toggle on the client dashboard (issue-010)**
  - Inline button rendered next to the "Tasks & Progress" heading on
    `client/dashboard.php`. Label flips between **"Show all
    sub-tasks"** (EN) / **"显示所有子任务"** (中文) and **"Show
    top-level only"** (EN) / **"仅显示顶级任务"** (中文); the icon
    flips between `fa-eye` and `fa-eye-slash`. The control is hidden
    entirely on projects that have no sub-tasks — nothing to
    collapse, so the control would be misleading.
  - **Default state is "top-level only"** for first-time visitors and
    for any project that has sub-tasks. The nested sub-trees are
    visually hidden via a scoped CSS rule, not by re-rendering
    server-side; the page HTML is the same on every load and only
    the section's `data-subtasks` attribute + the button's
    `aria-pressed` differ.
  - The user's choice is persisted in `localStorage` under
    `client_dash.subtasks_visible.<share_code>`. Returning visitors
    who previously chose "expanded" see the full tree on first
    paint with no flash of collapsed content (the inline script
    runs at the end of `<body>`, before the browser commits the
    first frame). Each project has its own key, so two projects
    keep two separate preferences.
  - When `localStorage` is unavailable (private mode, sandboxed
    iframe, JS disabled), the page still defaults to "top-level
    only" and the toggle remains visible but its click is a safe
    no-op.
  - Accessibility: the control is a real `<button type="button">`
    with `aria-pressed`, `aria-controls="tasks-progress"`, and an
    `aria-label`; nested `<ul class="task-tree">` carry
    `aria-hidden="true"` while collapsed so screen readers skip
    the subtree. The icon + text combination means there is no
    reliance on colour alone.
  - Purely client-side: no new DB columns, no new SQL, no new
    endpoints. The existing `render_task_tree()` is untouched;
    only the wrapper section's id, the new button, the localised
    labels, the inline script, and one scoped CSS rule
    (`#tasks-progress[data-subtasks="hidden"] ul.task-tree:not(.root)`)
    are added.
  - Share code is interpolated into the inline script via
    `json_encode(..., JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS |
    JSON_HEX_QUOT)` so a future change to the share-code character
    set cannot break the page.

### Localisation

Three new keys added to `client_translations()` in `client/lang.php`
for both English and Simplified Chinese:

| Key                          | EN                       | 中文                   |
| ---------------------------- | ------------------------ | ---------------------- |
| `dash.subtasks_show_all`     | `Show all sub-tasks`     | `显示所有子任务`        |
| `dash.subtasks_show_top`     | `Show top-level only`    | `仅显示顶级任务`        |
| `dash.subtasks_toggle_aria`  | `Toggle sub-tasks visibility` | `切换子任务显示`   |

### Upgrade notes

This release has **no database change** and **no migration**. To
deploy:

1. Pull the latest code. The change is server-rendered HTML + one
   inline `<script>` + one CSS rule, so no rebuild is needed.
2. (Optional) Run the new regression test inside the app container:
   ```bash
   docker compose exec app php client/toggle_subtasks_test.php
   ```
3. Clear any CDN / reverse-proxy cache for `client/dashboard.php`
   and `client/assets/client.css` so visitors see the new control
   on the next page load.

### Out of scope

- Per-task accordion (collapsing individual sub-trees independently).
- An "Expand all / Collapse all" pair of controls. The single toggle
  is sufficient for the use case.
- Persisting the choice across devices or browsers. This is a
  per-device preference, like the existing `client_lang` cookie.
- Surfacing the toggle on the internal console's task pages.
- A server-side query-parameter override (e.g. `?subtasks=1`).

---

## v1.2.1 — 2026-10-06

Bundles the planned **v1.3**, **v1.3.1**, and **v1.4** work-items into a
single **v1.2.1** release (the next patch slot after v1.2.0) because
they all touch the same project + contact surface and the same DB
shape.

- Originally planned as **v1.3**: optional **Project Start Date**
  (issue-008).
- Originally planned as **v1.3.1**: a small patch on top —
  **dashboard "New Project" CTA target** + **CSRF hardening** on the
  project create / edit POST handler (QA findings against the v1.3
  draft, documented in
  `docs/issues/issue-008/tester-note-001.md`).
- Originally planned as **v1.4**: ships the missing `contacts`
  columns (**company**, **position**, **address**) so the contact
  create / edit form stops crashing, and makes the underlying PDO
  error visible in the next schema-drift bug (issue-009).

All three shipped together under the **v1.2.1** tag.

### Highlights

- **Project Start Date (issue-008, originally planned as v1.3)**
  - New optional `projects.start_date DATE` column, distinct from
    `created_at`. The manager decides when work on the project
    actually began; the database row's insertion time is no longer
    mis-reported as the project's start date.
  - The project create / edit form gains a third date field next to
    `Expected Completion Date` and `Actual Completion Date`. The form
    pre-fills from the stored value, parses dates with the existing
    `parseProjectDate` helper, and surfaces a **non-blocking** warning
    if `start_date` is later than `expected_completion_date` or
    `completion_date` (historical imports stay importable).
  - The internal project detail page shows `Started: <date>` in the
    project-meta strip (conditional on the column being non-null).
  - The internal projects list shows `Started: <date>` as a small
    meta line under each project card.
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

- **Contact write path no longer crashes — missing `contacts` columns
  (issue-009, originally planned as v1.4)**
  - Adds `contacts.company VARCHAR(255) NULL`,
    `contacts.position VARCHAR(255) NULL`, and
    `contacts.address TEXT NULL`. The contact write path
    (`contact_edit.php`), the read view (`contact_detail.php`), and
    the contacts search (`contacts.php`) have all been referencing
    these columns; without the schema the create / edit form failed on
    every submit with MariaDB error
    `1054 (42S22): Unknown column 'company' in 'INSERT INTO'` and the
    user saw the generic `"Error saving contact: Database execute
    failed"` message. The same drift class was previously fixed for
    `phone` in issue-001 / v1.1.0.
  - Migration:
    `database/migrations/20261006_add_contacts_company_position_address.sql`
    (idempotent, guarded by `information_schema`). Existing rows stay
    `NULL` and continue to render with the existing "Not set" fallbacks.
  - `Database::execute()` and `Database::query()` now prepend the
    underlying PDO error message to the thrown `Exception`, so the
    next schema-drift bug surfaces directly to the form instead of
    being hidden as `"Database execute failed"`.
  - Regression test: `database/contacts_columns_test.php` performs the
    exact failing `INSERT` from the bug report (name `张总`, wechat
    `YADEPAN18`), then `UPDATE`s the new columns and re-reads them; it
    also exercises the search-by-company query and the new
    error-message wrapper, then cleans up after itself.

- **Dashboard "New Project" CTA — bug 2 from `tester-note-001`
  (originally planned as v1.3.1)**
  - `dashboard.php` "Recent Projects" → "+ New Project" button now
    routes to `project_edit.php` (the create form), not to
    `projects.php` (the list). Matches the label and reduces clicks to
    one. The same CTA on `projects.php` already routed correctly.

- **CSRF protection on project create / edit POST — bug 4 from
  `tester-note-001` (originally planned as v1.3.1)**
  - `project_edit.php` now allocates the CSRF token for both create
    and edit (was previously only allocated when editing an existing
    project).
  - The main create / edit form embeds a hidden `csrf_token` input,
    and the POST handler calls `validateCsrfToken()` before
    processing any other field. A cross-site request without a valid
    token is rejected with
    `"Invalid form token. Please reload the page and submit again."`
    and does not mutate `projects` (including `start_date`,
    `expected_completion_date`, `completion_date`, `status`).
  - The inline delete dialog continues to validate its own CSRF token
    via `project_delete.php` (unchanged).
  - The `project_start_date_test.php` regression test was extended to
    assert the dashboard CTA target and the form CSRF inputs.
  - Out of scope for this release: same CSRF hardening should be
    applied to other write handlers (`task_edit.php`,
    `contact_edit.php`, `register.php`, `forgot_password.php`,
    `reset_password.php`, AJAX endpoints). Track under a separate
    ticket.

### Upgrade notes

Apply this from any prior release (including a fresh `v1.2.0` install):

1. Pull the latest code.
2. Re-run the v1.2.1 migrations (both are idempotent):
   ```bash
   docker compose exec db mariadb -u"$DB_USER" -p"$DB_PASSWORD" "$DB_NAME" \
     < database/migrations/20261006_add_project_start_date.sql
   docker compose exec db mariadb -u"$DB_USER" -p"$DB_PASSWORD" "$DB_NAME" \
     < database/migrations/20261006_add_contacts_company_position_address.sql
   ```
3. (Recommended) Run the regression tests inside the app container:
   ```bash
   docker compose exec app php database/project_start_date_test.php
   docker compose exec app php database/contacts_columns_test.php
   docker compose exec app php database/task_completion_test.php
   ```
4. For fresh installs, point `docker-compose.yml` at a clean checkout
   and run `docker compose up -d`. The installer is reachable at
   `http://localhost:${APP_PORT}/install.php`. Fresh databases include
   the new columns via the updated `database/schema.sql`.

### Refusals / non-changes

The QA report on issue-008 also flagged a third bullet about
`fa-flag` on the dashboard "Recent Projects" item. v1.2.1 does **not**
change that icon because `docs/issues/issue-008.md:102` explicitly
specifies `fa-flag` for that exact entry. Reviewed: a later
"themed icons" pass is the right place to revisit the entire icon set
together.

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
> and is **resolved in v1.2.1** (see issue-009 below).

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