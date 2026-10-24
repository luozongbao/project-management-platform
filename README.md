# Project Management Platform

A PHP-based project management platform for small teams. It pairs an internal
console (projects, tasks, contacts, users) with a public **client share
portal** that lets stakeholders view read-only progress without logging in.

> **Latest release:** v2.0 (2026-10-25) — see [RELEASE.md](RELEASE.md) for the
> full changelog.
>
> **v2.0 is the front-end redesign.** Every page of the internal console, the
> install wizard and the public client portal has been rebuilt on a shared v2
> design system: a **103-token** layer (`assets/css/v2-tokens.css`), a
> **component library** (`.card`, `.btn`, `.modal`, `.toast`, `.stat-grid`,
> `.task-tree`, `.form-grid`, … in `assets/css/v2-components.css`) and **14
> per-page stylesheets** (`assets/css/pages/`). All inline `<style>` and
> inline `<script>` blocks are gone; shared behaviour now lives in
> `assets/js/script.js`. The superseded v1 CSS was swept out (90 dead
> selectors from `style.css`, 11 from the client portal).
>
> v2.0 is also a **routing release**. A front controller
> (`includes/Router.php`) and a `slug` migration replace the old
> `.php`-suffixed URLs with clean ones, and the canonical detail URLs use
> **numeric IDs** (`/projects/1692`, `/tasks/935`, `/contacts/249`) because
> slugs are mutable — every legacy `.php` and slug URL 301s to the new form.
>
> Other notable additions: **public registration can be closed** with a single
> `const APP_ALLOW_REGISTRATION` line in `config.php` (the `register.php`
> route returns 403 when it is off); two **new delete endpoints**
> (`contact_delete.php`, `task_delete.php`) that previously 404'd; and four
> browser-found bug fixes — the **copy button** now works outside a secure
> context, **task completion dates** no longer lose their time to a `DATE`
> column, the **client language switcher** matches the mockup, and the
> **forgot-password** layout is corrected.
>
> The v2.0 acceptance suite reports **110 PASS / 0 FAIL**; see
> [docs/v2.0-test-report.md](docs/v2.0-test-report.md) for the full run and
> the outstanding operational blocker (the production origin must be rebuilt
> before v2.0 is deployed there).

---

## What's new in v2.0

### v2.0 — The front-end redesign (issues 035–055)

The complete v2.0 surface. Full detail is in
[RELEASE.md](RELEASE.md); the highlights:

| Area | What changed |
|---|---|
| **Design tokens** | `assets/css/v2-tokens.css` — 103 tokens for colour, spacing, radius, typography, shadow and duration. Semantic tints use `-100` / `-500` / `-700`. |
| **Components** | `assets/css/v2-components.css` — the shared vocabulary: `.card`, `.btn`, `.status`, `.badge`, `.modal`, `.toast`, `.stat-grid`, `.detail-list`, `.table`, `.task-tree`, `.form-grid`, `.field`, `.alert`. |
| **Page stylesheets** | `assets/css/pages/` — 14 per-page files. Page styling no longer lives in inline `<style>` blocks. |
| **Routing** | `includes/Router.php` front controller; clean URLs replace `.php` suffixes; canonical detail URLs use numeric IDs; legacy `.php` and slug URLs 301 to the new form. |
| **Client-side JS** | `assets/js/script.js` — `initModals()`, `initRangeSync()`, `initStatusReason()`, `initCopy()` and `pmpToast()`, moved out of per-page inline scripts. |
| **Dead CSS sweep** | 90 selectors removed from `style.css` (984 → 428 lines), 11 from the client portal CSS. |
| **Registration flag** | `const APP_ALLOW_REGISTRATION` in `config.php` closes public sign-up; `/register` returns 403 when off. |
| **New endpoints** | `contact_delete.php` and `task_delete.php` — both previously 404'd. |

**Pages converted:** dashboard, projects, tasks, contacts, profile, project /
task / contact detail, project / contact / task new + edit, login, register,
forgot password, reset password, the install wizard, the client dashboard and
the client landing page.

### v2.0 — New delete endpoints

Both delete links previously pointed at files that did not exist and returned
**HTTP 404**. They are now real, POST-only endpoints that require the CSRF
token **and** a typed confirmation:

- `POST /contacts/<id>/delete` — confirms with the contact's name.
- `POST /tasks/<id>/delete` — confirms with the task's name.

Deleting a parent task cascades to its whole subtree. Deleting a contact
clears its task assignments first (the FK is `RESTRICT`) and keeps the
tasks themselves.

### v2.0 — Public registration can be closed

One constant in `config.php` controls whether anyone can create an account:

````php
const APP_ALLOW_REGISTRATION = false;   // true = open, false = closed
````

When it is `false`, `/register` returns **HTTP 403** for both `GET` and
`POST` — no user is created and no welcome email is sent — and the "Create an
account" button on the login page and the "Register" link on the
forgot-password page are hidden. The check lives **above** the `POST`
handler, so a direct `POST` cannot bypass the UI.

The flag lives in `config.php` rather than `.env` on purpose: shared hosting
(Hostinger / cPanel) has no `docker-compose` and nothing reads `.env`, while
`config.php` is hand-maintained on every deployment. `config.php` is
bind-mounted into the container, so the change takes effect on the next
request — **no container restart, no `docker compose`, no `.env` edit**. Set
it back to `true` to re-open registration.

### v2.0 — Browser-found bug fixes

- **The "Copy link" button did nothing** on a plain-HTTP origin — the Async
  Clipboard API does not exist outside a secure context, and the handler had
  no fallback and no error handling. A new shared `pmpCopyText()` helper
  implements a real fallback chain and reports failure honestly.
- **Task completion dates silently lost their time** — the form posted
  `datetime-local` values into `DATE` columns, so `2026-10-22 14:30:00` was
  stored as `2026-10-22`. The form now uses `type="date"`, the parser
  actually validates impossible dates, and the AJAX status endpoints were
  corrected to day precision. No schema change.
- **The client language switcher never showed its active state** — the CSS
  rule targeted `button.is-active` while the markup renders links. A scoped
  rule restores it.
- **The forgot-password page was mislaid out** — missing required-field
  marker, no `autocomplete="email"`, no `autofocus`, and the error alert sat
  below the heading instead of above it.

### v2.0 — Routing and navigation

- Clean URLs replace `.php` suffixes (`/login`, `/projects`, `/dashboard`).
- Detail URLs use **numeric IDs** — `/projects/<id>`, `/tasks/<id>`,
  `/contacts/<id>` — because slugs are mutable and renaming an entity used
  to break its links. The `slug` columns are retained so historical slug URLs
  still resolve.
- `GET /reset-password` and `/reset-password/<token>` were declared in the
  router but returned **404**, so every emailed password-reset link was dead.
- Unauthenticated deep links now redirect to the login page with a
  guarded `?redirect=` return path, instead of 404'ing.

### v2.0 — Mockup container (issue-036)

The v2.0 HTML mockup can be browsed locally:

```bash
docker compose up -d mockup
# → http://localhost:8080
```

The service is behind a Compose `profiles` tag, so a plain
`docker compose up -d` does not start it, and it publishes only the mockup
and `issue-035.md` — not the rest of `docs/issues/`.

---

## What's new in v1.7.2

### v1.7.2 — Application "About" / Version popup + Release-process checklist (issue-028)

- **New `APP_VERSION` constant** in `config.php` and
  `config.template.php` (kept byte-identical). Single
  source of truth for the version string; `install.php`
  emits it via `var_export($current_version, true)` in
  the same style as the other constants from issue-021.
  Drift between the four sites (`config.php`,
  `config.template.php`, `RELEASE.md` top entry,
  `README.md:3` banner) is caught by layer 1 of
  `database/about_popup_test.php`.
- **In-app About popup** anchored to a "?" trigger in the
  navbar of the internal app, the public client-portal
  header, the domain-root landing page, and as a floating
  trigger on logged-out pages (login / register / forgot
  / reset). Zero JS — uses native `<details>` /
  `<summary>` so the disclosure is CSP-safe on the
  tightened client portal (issue-020 invariant preserved).
  Shows the version, the dev attribution
  (`Built by: Me` / `Vibe coding with AI agent
  (MiniMaxM3)`), and on the internal app a "View full
  changelog" link to `RELEASE.md`. The link is
  deliberately omitted on the public client portal so
  internal milestones do not leak.
- **New release-process checklist**
  ([`docs/RELEASE_PROCESS.md`](docs/RELEASE_PROCESS.md)).
  6 steps: bump the `APP_VERSION` constant → append the
  `RELEASE.md` entry → update the README banner → update
  docs / inline attribution → run the full test suite →
  smoke-test the About popup. Invoked from the new
  "Release process (issue-028)" footer in `RELEASE.md`
  and from the "About this build" footer in this file.
- **New regression test
  [`database/about_popup_test.php`](database/about_popup_test.php)**
  with four layers: APP_VERSION drift guard, the
  `install.php` `var_export()` writer, the
  `<details class="app-about">` markup in
  `includes/header.php` (with the floating variant for
  logged-out pages), and the existence of the new
  release-process doc with all 6 step headings. Run as
  part of the merge gate alongside the 10 pre-existing
  tests.
- **6 new `about.*` translation keys** in
  `client/lang.php` (both `en` and `zh`). The attribution
  value is the same in both locales per the issue's NG6
  non-goal (no extra localization for the dev
  attribution). `e()` is used to escape the version in
  every render site.
- **Files touched:** `config.php`, `config.template.php`,
  `install.php`, `includes/header.php`,
  `client/dashboard.php`, `index.php`, `client/lang.php`,
  `assets/css/style.css`, `client/assets/client.css`,
  `docs/RELEASE_PROCESS.md` (new), `RELEASE.md`,
  `README.md`, `database/install_wizard_test.php` (new
  layer-2 assertion), `database/about_popup_test.php`
  (new). Pull and reload — no DB / nginx / container
  restart required.

## What's new in v1.7.1.2

### v1.7.1.2 — Sub-tasks toggle controller extracted to an external JS file (issue-027)

- **New file: `client/assets/js/subtask-toggle.js`.** The
  per-device "Show all sub-tasks" toggle on the public
  client dashboard used to ship as an inline
  `<script>(function () { … })();</script>` block in
  `client/dashboard.php`. The v1.7.0 CSP tightening on
  the public portal drops `'unsafe-inline'` from
  `script-src`, so the browser refused to execute the
  controller and the toggle silently no-op'd on a fresh
  page load. v1.7.1.2 moves the controller into a new
  static ES5-compatible file at
  `client/assets/js/subtask-toggle.js` loaded via
  `<script src="assets/js/subtask-toggle.js" defer></script>`.
  Hand-written ES5 (var only, no arrow, no block-scoped
  declarations, no template literals) to match
  `assets/js/script.js`. No build step.
- **Three new `data-*` attributes on the toggle button.**
  `data-share-code="<?= e($project['share_code']) ?>"`,
  `data-label-show-all="<?= e(t('dash.subtasks_show_all')) ?>"`,
  `data-label-show-top="<?= e(t('dash.subtasks_show_top')) ?>"`.
  The controller reads all three via `btn.getAttribute(…)`
  on first paint, so the JS file is fully static and has
  no per-page PHP interpolation. All three values are
  passed through `e()` for HTML-attribute safety.
- **Behaviour is identical to v1.7.1.1.** Default
  collapsed. Click flips `data-subtasks` /
  `data-state` / `aria-pressed` / button label / icon
  class (`fa-eye` ⇄ `fa-eye-slash`) and toggles
  `aria-hidden` on nested `ul.task-tree:not(.root)`
  sub-trees (root `<ul class="task-tree root">` stays
  visible either way). Persisted to
  `localStorage['client_dash.subtasks_visible.' + shareCode]`
  as `"0"` / `"1"`. First-paint read runs before the
  click handler is attached, so a stored `"1"`
  preference is honoured without a flash. Every
  `localStorage` access is wrapped in try/catch so a
  sandboxed iframe or Safari private mode does not
  throw.
- **`<script>` tag is conditional.** The
  `<script src="assets/js/subtask-toggle.js" defer></script>`
  tag is wrapped in `<?php if ($has_subtasks): ?>`, so
  projects with no sub-trees do not pay the extra HTTP
  request.
- **No DB / schema / nginx / CSS / translation change.**
  The fix is one new file + the toggle-button markup +
  the script-tag swap in `client/dashboard.php`. The
  security-headers regression test
  (`database/security_headers_test.php`) has its
  `script-src 'self' / 'unsafe-inline'` comment
  corrected to point at the new controller path
  (the CSP invariant the test enforces is unchanged).

---

## What's new in v1.7.1.1

### v1.7.1.1 — Parent status aligned with parent completion percentage (issue-026)

- **`deriveParentStatusFromCompletion(?string $currentStatus, float $percentage): string` is the new
  percentage-driven helper** in `includes/functions.php`.
  It is used by `syncProjectTaskCompletions()` to derive a
  parent's `status` from its computed
  `completion_percentage`, so the two fields share a single
  source of truth. The rule: `on_hold` (parent) is sticky;
  otherwise `completed` if percentage `>= 99.5`, otherwise
  `in_progress` if percentage `> 0`, otherwise
  `not_started`. The 99.5 threshold matches the
  `round(x, 2)` granularity used at the call site.
- **`deriveParentStatus()` is unchanged** and still owns
  the user-driven `resume` branch in
  `ajax/parent_hold.php`. The two helpers are siblings,
  not replacements; the strict `all-completed` rule from
  v1.7.1 is preserved at the leaf level and at the
  resume path. No AJAX endpoint, JS, CSS, translation, or
  schema change.
- **Closes the UAT2 regression.** A parent with three
  sub-tasks, one at 5% / `not_started` and two at 0% /
  `not_started`, now lands on `in_progress` with a
  positive `completion_percentage` after
  `syncProjectTaskCompletions()`. Under the old helper
  the parent was `not_started` with percentage 0.
- **Files changed:** `includes/functions.php` (new
  helper with full docblock referencing issue-025 and
  issue-026; the call site in
  `syncProjectTaskCompletions()` now uses the new helper)
  and `database/task_parent_status_derived_test.php`
  (new layer-1b block with 12 spec fixtures plus
  boundary stress for 99.49 / 99.50; live-DB sub-test 2a
  extended to assert `completion_percentage === 0.0`;
  new live-DB sub-test 2g for the UAT2 regression;
  live-DB fixtures 2b and 2d updated to give the
  promoted child a non-zero `completion_percentage` so
  the parent percentage is also non-zero under the new
  contract; new layer-4 source-grep check that the
  call site in `syncProjectTaskCompletions()` is
  exactly `deriveParentStatusFromCompletion($currentStatus, (float)$storedCompletion)`).
- **Acceptance check:** run
  `docker compose exec app php database/task_parent_status_derived_test.php`
  — 0 failures (12 new pure-function fixtures, 2
  boundary, 2 from the new matrix stress, 1 new live-DB
  sub-test, 1 new source-grep check). All five other
  regression tests are unchanged and still pass.

---

## What's new in v1.7.1

### v1.7.1 — Parent-task status: strict "all sub-tasks completed" rule (issue-025)

- **`deriveParentStatus()` is now strict on the `completed` branch.**
  A parent task is only classified as `completed` when **every**
  sub-task is `completed` (and the children list is non-empty).
  The previous loose rule promoted the parent to `completed` as
  soon as any sub-task was completed, even when the rest were
  `not_started` or `on_hold` — e.g. sub-tasks
  `['completed', 'not_started']` previously classified the
  parent as `completed`; it is now `not_started`. The other
  three rules are unchanged: `on_hold` parent is still sticky;
  any `in_progress` child still wins; empty children list still
  yields `not_started`.
- **Backwards-incompatible behaviour, automatic demotion.** A
  parent that was previously classified as `completed` under
  the loose rule and still has at least one `not_started`
  sub-task is automatically re-classified to `not_started` on
  the next user interaction that triggers
  `syncProjectTaskCompletions()` (any sub-task edit, hold,
  resume, complete, or progress update). No back-fill script
  is required. The `completion_date` is cleared at the same
  time, consistent with the v1.5.0 demotion rules.
- **Files changed:** `includes/functions.php` (function body
  + docblock) and
  `database/task_parent_status_derived_test.php` (4 pure
  fixtures updated to codify the strict rule, 2f live-DB
  assertion updated to expect `not_started`). No schema, AJAX,
  JS, CSS, or translation change.
- **Acceptance check:** run
  `docker compose exec app php database/task_parent_status_derived_test.php`
  — 0 failures. The test now explicitly asserts the three
  regressed cases (`['completed', 'not_started']`,
  `['completed', 'on_hold']`, and
  `['completed', 'completed', 'not_started']`) and the 4×4
  exhaustive matrix still proves every cell returns a valid
  enum value with `on_hold` sticky.

## What's new in v1.5.1

### v1.5.1 — UI layout fixes on the v1.5.0 task edit / detail surface (issue-013, revised scope)

- **`task_detail.php` "Put on Hold" / "Resume" button falls onto
  its own row — fixed.** `.page-actions` had no rule in
  `assets/css/style.css`, so it defaulted to `display: block`
  and the Hold / Resume `<form class="inline-form">` (which is
  correctly nested inside `<div class="page-actions">`) wrapped
  below the other action buttons. A new `.page-actions` rule
  (`display: flex; flex-wrap: wrap; align-items: center; gap: 10px;
  justify-content: flex-end; margin-bottom: 20px;`) puts every
  action control back on one row. A new `.page-header` flex rule
  (with `justify-content: space-between`) lays out the page
  title and the action row on the same baseline.
- **`task_edit.php` "Use sum of sub-task hours" checkbox and its
  text label render on separate lines — fixed.** `.checkbox-label`
  had no rule in `assets/css/style.css`, so the default block-flow
  put the input and the `<span>` on two separate lines. A new
  `.checkbox-label` rule (`display: flex; align-items: center;
  gap: 8px; cursor: pointer; font-weight: 500; color: #333; line-height: 1.4;`)
  puts them back on one line, and the inner
  `.checkbox-label input[type="checkbox"]` rule pins the touch
  target to `18px × 18px` (WCAG 2.5.5 target size, sufficient).
- **Mobile breakpoint extended.** The existing
  `@media (max-width: 768px)` block now also collapses
  `.page-header` to a column (`flex-direction: column;
  align-items: stretch;`) and stretches the action buttons to
  full width, so the action row stays reachable with a thumb
  on a phone.
- **Background (unchanged from the original 2026-10-12 spec).**
  The 2-column `.form-grid` layout in `task_edit.php` is
  defined in `assets/css/style.css` with the Dates section
  marked `class="form-section full-row"`; the
  `$effective_inherit` PHP warning on a brand-new task is
  silenced by a top-level initialisation; the Hold / Resume
  `<form class="inline-form">` in `task_detail.php` is rendered
  inside the `<div class="page-actions">` row (the v1.5.1 fix
  is the new `.page-actions` flex rule above).
- **Regression test:** `database/task_layout_issue013_test.php`
  (new) — a structural source-grep test that asserts the
  `.form-grid`, `.page-header`, `.page-actions`,
  `.checkbox-label` (with 18x18 checkbox), and mobile
  breakpoint rules are in `style.css`; the
  `$effective_inherit` initialisation appears before the form
  HTML in `task_edit.php`; the `class="form-section full-row"`
  is present on the Dates section; the Hold / Resume form is
  positioned inside `<div class="page-actions">` in
  `task_detail.php`; and both modified PHP files lint cleanly.
  Verified the test catches a regression by temporarily
  removing the `.checkbox-label` rule body (test fails) and
  restoring it (test passes).

## What's new in v1.5.0

### v1.5.0 — Parent effort inherit mode + parent status derived from sub-tasks (issues 011 + 012)

- **Parent effort inherit mode (issue-011).** A parent task that has
  sub-tasks can now opt into a sum-of-sub-task-hours mode for its
  effort estimate. A new nullable `tasks.inherit_subtask_hours
  TINYINT(1)` column records the choice. A new shared helper
  `resolveTaskEstimateHours()` in `includes/functions.php` is the
  single source of truth for the displayed number, consumed by
  `task_detail.php`, `tasks.php`, `project_detail.php`, and
  `client/dashboard.php`. The task edit form renders a new
  **"Use sum of sub-task hours as total task time"** checkbox
  (only when editing a task that already has sub-tasks, to
  preserve the parent-must-be-created-first invariant). When
  ticked, the `Total Task Time` input is disabled and the live
  sum is shown next to it. A server-side defence forces
  `estimated_hours = NULL` on save when the flag is set, and a
  validation rule rejects an empty / zero manual `Total Task
  Time` for a parent that has sub-tasks and is not in inherit
  mode. The project detail page now shows a `Total Time` cell
  in the task table (previously missing entirely).
  Migration: `database/migrations/20261012_add_task_inherit_subtask_hours.sql`
  (idempotent, `information_schema` guard). Regression test:
  `database/task_inherit_subtask_hours_test.php`.
- **Parent status derived from sub-tasks (issue-012).** A parent
  task's `status` is now derived from its sub-tasks' statuses.
  The shared helper `deriveParentStatus()` (in
  `includes/functions.php`) implements the rule from the issue
  brief: any child `in_progress` → `in_progress`; otherwise
  any child `completed` → `completed`; otherwise `not_started`;
  `on_hold` is sticky (a held parent stays held across sub-task
  changes; only an explicit *Resume* brings it back). The
  helper is consumed by both the extended
  `syncProjectTaskCompletions()` and the new
  `ajax/parent_hold.php` endpoint, which is the **only**
  legitimate path that mutates a parent's status while it has
  children. The task detail page shows an **"Auto"** pill
  (`fa-magic`, `.derived-pill`) next to the status badge for
  any task that has sub-tasks. The task edit page renders the
  status field as a read-only badge for parents with sub-tasks
  (the submitted value is ignored on the server). New *On
  Hold* / *Resume* buttons on the task detail page POST to
  `ajax/parent_hold.php` with `action=hold` or `action=resume`;
  both paths call `syncProjectTaskCompletions()` at the end so
  the project rollup, the task list, and the client portal stay
  in sync. `ajax/complete_task.php` calls the helper directly
  in both branches now (the local `updateParentTaskCompletion`
  wrapper was removed in the same change to keep a single
  source of truth). `completion_date` is set on transition to
  `completed` and cleared on demotion from `completed`, with a
  `WHERE completion_percentage <> ? OR status <> ?` guard to
  keep `updated_at` honest on no-op syncs. Regression test:
  `database/task_parent_status_derived_test.php`.

- **Tester follow-up fixes (issue-010 + issue-011).** All bugs
  reported by QA against the v1.4.1 / v1.5.0 surface are fixed:
  - *Issue-011 bug 1* — when a parent is in inherit mode and
    **all** sub-task estimates are `NULL` / `0` / negative, all
    four display surfaces (`task_detail.php`, `tasks.php`,
    `project_detail.php`, `client/dashboard.php`) now render the
    literal "Not estimated (no sub-task estimates)" label in
    place of the previously hidden / mismatched copy. The
    client portal uses the new translation key
    `dash.not_estimated_parent` (EN + ZH). The
    `database/task_inherit_subtask_hours_test.php` regression
    test now source-greps all four surfaces, asserts the
    translation key in both dictionaries, and live-renders the
    unauthenticated client dashboard to confirm the translated
    string reaches the served HTML.
  - *Issue-010 bug 1* (a11y) — `client/dashboard.php` selector
    was scoped to `ul.task-tree:not(.root)` so the root
    workstream list is never hidden from screen readers in the
    default "top-level only" state.
  - *Issue-010 bug 2* — `client/toggle_subtasks_test.php` now
    asserts the scoped selector literally, locking the fix in.
  - *Issue-010 bug 3* — removed the dead `shell_exec('mariadb …')`
    trigger restore from the test cleanup (silent no-op on
    this image; triggers are also unused by the app since
    parent-completion moved to PHP). Replaced with a `SELECT 1`
    DB connectivity sanity check.

## What's new in v1.4.1

### v1.4.1 — Sub-tasks toggle on the client dashboard (issue-010)

- New "Show all sub-tasks" / "Show top-level only" toggle rendered inline
  with the "Tasks & Progress" heading on `client/dashboard.php`. Click
  flips between collapsed and expanded views of the task hierarchy.
- The toggle only appears when the project has at least one sub-task
  (i.e. at least one task row with `subtask_count > 0`); projects with
  no sub-tasks still render the flat list as before, with no extra
  control to misread.
- **Default state is "top-level only"** for the first visit and for
  every project that has sub-tasks. Returning visitors who chose
  "expanded" see the full tree on first paint with no flash of
  collapsed content (the inline script runs at the end of `<body>`,
  before the browser commits the first frame).
- The choice is persisted in `localStorage` under the per-project key
  `client_dash.subtasks_visible.<share_code>`. Each project has its
  own key, so a stakeholder who manages two projects keeps both
  preferences.
- When `localStorage` is unavailable (private mode, sandboxed
  iframe, JS disabled), the page still defaults to "top-level only"
  and the toggle remains visible but its click is a safe no-op.
- Accessibility: the control is a real `<button type="button">` with
  `aria-pressed`, `aria-controls="tasks-progress"`, and an
  `aria-label`; nested `<ul class="task-tree">` carry
  `aria-hidden="true"` while collapsed so screen readers skip the
  subtree. The icon flips between `fa-eye` and `fa-eye-slash` to
  make the current state obvious without relying on color.
- Purely client-side: no new DB columns, no new SQL, no new
  endpoints. The existing `render_task_tree()` is untouched; only
  the wrapper section's id, the new button, the localised labels,
  the inline script, and one scoped CSS rule are added.
- Localized: three new keys in `client/lang.php`
  (`dash.subtasks_show_all`, `dash.subtasks_show_top`,
  `dash.subtasks_toggle_aria`) for both English and Simplified
  Chinese.
- Regression test: `client/toggle_subtasks_test.php` exercises the
  server-rendered DOM contract (toggle button + section ids + script
  + CSS rule + per-project localStorage key isolation) for projects
  **with** and **without** sub-tasks, plus a project with no tasks
  at all, and re-implements the click flip in PHP to assert the
  state machine parity. Self-contained: drops and re-creates the
  `CalculateTaskCompletion` triggers around the test so the test is
  not coupled to MariaDB's recursive-trigger guard.

---

## What's new in v1.2.1

### v1.2.1 — Contacts `company` / `position` / `address` columns (issue-009, originally planned as v1.4)

- The contact create / edit form, the read view (`contact_detail.php`),
  and the search box (`contacts.php`) all referenced `c.company`,
  `c.position`, and `c.address`, but the `contacts` table never had
  those columns. Every contact create / edit failed with
  `"Database execute failed"`. v1.2.1 ships the three missing columns.
- `contacts.company VARCHAR(255) NULL`, `contacts.position
  VARCHAR(255) NULL`, `contacts.address TEXT NULL`. All optional;
  only `name` is required. Existing rows keep all three as `NULL`.
- Migration: `database/migrations/20261006_add_contacts_company_position_address.sql`
  (idempotent, `information_schema` guard, same shape as
  `20261003_add_contacts_phone.sql`).
- `database/schema.sql` `CREATE TABLE contacts` updated so a fresh
  install no longer needs the migration.
- `Database::execute()` and `Database::query()` now surface the
  underlying PDO error in the thrown exception's message
  (`"Database execute failed: Unknown column 'x' in …"`), so the
  next schema-drift bug is visible in the form rather than only in
  `error_log`.
- Regression test: `database/contacts_columns_test.php` performs the
  exact failing `INSERT` from the bug report (name `张总`, wechat
  `YADEPAN18`), then `UPDATE`s the new columns and re-reads them; it
  also exercises the search-by-company query and the new
  error-message wrapper, then cleans up after itself.

### v1.2.1 — Project Start Date (issue-008, originally planned as v1.3)

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

### v1.2.1 — Dashboard CTA + CSRF hardening (issue-008 / tester-note-001, originally planned as v1.3.1)

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
  contact person. v1.8.0 adds **on-hold and delay reasons**: a
  non-empty reason is required when a task moves to `on_hold` or
  lands in a delayed state (expected completion date in the past and
  status != completed), shown as chips on the dashboard, task list
  and task detail, and as inline-fillable "missing" marks on the
  status badge.
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
- **Configurable public registration** (v2.0) — a single
  `const APP_ALLOW_REGISTRATION` in `config.php` closes public sign-up;
  `/register` then returns 403 for both `GET` and `POST`, and the login and
  forgot-password pages stop offering a registration link. Existing accounts
  are unaffected.
- **Delete flows for contacts and tasks** (v2.0) — both are POST-only, CSRF-
  protected, and require a typed confirmation (the contact / task name).

### Client portal (public, no login)

- **Root entry point** (`/`) — the domain root hosts the public share-code
  form, so clients and project managers have a single shared landing page.
  An "Owner sign in" link on the form reaches `/login`.
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
  dashboard header, with a visible active state on both.
- **v2.0 styling** — the landing page and the client dashboard both load the
  shared v2 token and component layers, so they match the internal console
  instead of carrying their own hand-rolled CSS.

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

# v1.2.1 — Project Start Date (issue-008) + contact schema fix (issue-009)
docker compose exec db \
    mariadb -udbuser -p"$(grep DB_PASSWORD .env | cut -d= -f2)" dbname \
    < database/migrations/20261006_add_project_start_date.sql
docker compose exec db \
    mariadb -udbuser -p"$(grep DB_PASSWORD .env | cut -d= -f2)" dbname \
    < database/migrations/20261006_add_contacts_company_position_address.sql

# v1.5.0 — Parent effort inherit mode (issue-011) + parent status derived
# from sub-tasks (issue-012)
docker compose exec db \
    mariadb -udbuser -p"$(grep DB_PASSWORD .env | cut -d= -f2)" dbname \
    < database/migrations/20261012_add_task_inherit_subtask_hours.sql

# v1.7.0 — Security + bugfix bundle (issues 015–024). Four additive
# migrations, all idempotent via CREATE TABLE IF NOT EXISTS / the
# information_schema guard on the projects column add. Apply in date
# order; order is not load-bearing for correctness but matches the
# release manifest. The backfill UPDATE in the rotated_at migration
# is a no-op on a re-run.
docker compose exec db \
    mariadb -udbuser -p"$(grep DB_PASSWORD .env | cut -d= -f2)" dbname \
    < database/migrations/20261015_add_password_reset_attempts.sql
docker compose exec db \
    mariadb -udbuser -p"$(grep DB_PASSWORD .env | cut -d= -f2)" dbname \
    < database/migrations/20261015_add_login_attempts.sql
docker compose exec db \
    mariadb -udbuser -p"$(grep DB_PASSWORD .env | cut -d= -f2)" dbname \
    < database/migrations/20261016_add_client_share_code_attempts.sql
docker compose exec db \
    mariadb -udbuser -p"$(grep DB_PASSWORD .env | cut -d= -f2)" dbname \
    < database/migrations/20261016_add_projects_share_code_rotated_at.sql
```

The included migrations are idempotent — safe to re-run.

### Browsing the v2.0 design mockup

The v2.0 front-end mockup lives in `docs/design/2.0/`. It is a set of
standalone HTML files — no PHP, no database, no application code.

```bash
docker compose up -d mockup
# open http://localhost:8080/
```

| Command | Effect |
|---|---|
| `docker compose up -d mockup` | Start the mockup only (the app stack is untouched) |
| `docker compose stop mockup` | Stop it, keep the container |
| `docker compose down mockup` | Stop and remove it |
| `docker compose --profile mockup up -d` | Start it together with the full stack |

Change the host port with `MOCKUP_PORT` in `.env` (default `8080`).

Without Docker:

```bash
cd docs/design/2.0 && python3 -m http.server 8080
```

See `docs/issues/issue-035.md` for the full design specification and
`docs/issues/issue-036.md` for the ops notes.

### v2.0 release status

**v2.0 is released** (2026-10-25). `APP_VERSION` is `v2.0` and the v2.0
acceptance suite reports **110 PASS / 0 FAIL**
([docs/v2.0-test-report.md](docs/v2.0-test-report.md)).

| Phase | Scope | Status |
|---|---|---|
| Phase 0 | Slug routing — slug columns, front controller, route table, 301 redirects | Done ([issue-035](docs/issues/issue-035.md), [issue-040](docs/issues/issue-040.md)) |
| Phase 1.1 | Design tokens — `assets/css/v2-tokens.css` | Done ([issue-035](docs/issues/issue-035.md)) |
| Phase 1.2 | Component layer — `assets/css/v2-components.css` | Done ([issue-035](docs/issues/issue-035.md)) |
| Phase 1.3 | App shell — `includes/header.php` / `includes/footer.php` | Done ([issue-035](docs/issues/issue-035.md)) |
| Phase 1.4 | Page conversions | Done — dashboard, projects, tasks, contacts, profile, detail pages, the three New/Edit forms, and the auth pages ([issue-038](docs/issues/issue-038.md) … [issue-047](docs/issues/issue-047.md)) |
| Phase 1.5 | Client portal — `client/dashboard.php` | Done ([issue-038](docs/issues/issue-038.md)) |
| Phase 1.6 | Strip the superseded v1 rules from `style.css` | Done ([issue-053](docs/issues/issue-053.md)) — 90 dead selectors removed from `assets/css/style.css` and 11 from `client/assets/client.css`; the two remaining v1→v2 inconsistencies (`subtask-manager.js` button classes, `profile.php`'s 2-column grid) fixed |
| Phase 1.7 | Bump `APP_VERSION` to `v2.0` in `config.php`, `config.template.php` and `install.php` | Done |
| Phase 1.8 | Public registration flag, new delete endpoints, browser-found bug fixes, acceptance-test remediation | Done ([issue-039](docs/issues/issue-039.md), [issue-055](docs/issues/issue-055.md)) |

Converting a page means: rebuild its markup on the v2 component vocabulary,
move its styles into `assets/css/pages/<page>.css`, delete its inline
`<style>` and inline `<script>` blocks, and keep the page's `name=` / `id=`
attributes, form actions and AJAX endpoint shapes untouched.

> ### ⚠️ Before deploying v2.0 to production
>
> The production origin `project.lorwongam.com` currently serves a
> **BSC crypto-miner** instead of the application — a P0 operational incident
> that no code change can fix. The origin must be **rebuilt** before v2.0 is
> deployed there. See the deploy note at the top of
> [RELEASE.md](RELEASE.md) and the incident section of
> [docs/v2.0-test-report.md](docs/v2.0-test-report.md).

### Asset cache-busting

Local stylesheets and scripts are referenced through the `asset_url()`
helper in [includes/functions.php](includes/functions.php). It appends the
file's mtime to the URL (`/assets/css/style.css?v=1792685447`), so a changed
file is fetched immediately even though nginx serves static assets with a
30-day `Cache-Control`. Absolute URLs (the Font Awesome CDN) and the client
portal's relative paths are returned unchanged.

When you add or move a stylesheet or script, link it with:

````php
<link rel="stylesheet" href="<?= e(asset_url('/assets/css/pages/<page>.css')) ?>">
````

> A change to `includes/header.php`, `includes/functions.php` or
> `client/dashboard.php` must be re-pinned in `_diag.php` in the same commit
> — see [docs/issues/issue-048.md](docs/issues/issue-048.md) §3.4.

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
├── ajax/                  AJAX endpoints (progress, status, contact lookup, parent hold, …)
├── assets/
│   ├── css/
│   │   ├── v2-tokens.css      Design tokens (colour, spacing, radius, type, …)
│   │   ├── v2-components.css  Shared component library
│   │   ├── style.css          Base / layout stylesheet
│   │   └── pages/             14 per-page stylesheets
│   ├── js/
│   │   ├── script.js          Shared client-side behaviour
│   │   └── subtask-manager.js Sub-task add / delete
│   └── image/                Favicon
├── client/                Public client share portal (no login)
│   ├── dashboard.php      Read-only project dashboard
│   ├── lang.php           ENG / 中文 translation catalog + helpers
│   └── assets/client.css  Client portal stylesheet
├── database/
│   ├── schema.sql         Canonical schema (used by installer + migrations)
│   ├── subtask_functions.sql   Optional trigger helper (see Known gaps)
│   └── migrations/        Numbered, idempotent migration files
├── docker/
│   ├── entrypoint.sh      Container bootstrap
│   ├── nginx.conf         nginx site config
│   └── mockup-nginx.conf  Static mockup server (issue-036)
├── docs/
│   ├── requirements.md            v1.0 spec
│   ├── 20261003 requirements.md    v1.1 spec (client portal + i18n)
│   ├── design/2.0/                v2.0 HTML mockup + Python generator
│   ├── v2.0-test-report.md        v2.0 acceptance test report
│   └── issues/                    Resolved bug reports
├── includes/
│   ├── Database.php       PDO singleton
│   ├── EmailService.php   PHPMailer wrapper
│   ├── Router.php         Front controller — route table + dispatch
│   ├── landing.php        Public share-code landing page
│   ├── legacy_redirects.php  .php / slug → canonical URL 301s
│   ├── slug.php           Slug helpers
│   ├── functions.php      Shared helpers (e, formatMoney, generateShareCode, …)
│   ├── header.php / footer.php   Internal layout (v2 shell)
│   └── …
├── vendor/                Composer install (PHPMailer)
├── config.php             Generated by installer (not committed)
├── config.template.php    Template for manual setup
├── docker-compose.yml     Three-container stack (+ optional mockup profile)
├── Dockerfile             php-fpm 8.3 image
├── install.php            First-run installer
├── login.php / register.php / forgot_password.php / profile.php / logout.php
├── reset_password.php     Password reset (consumes the emailed token)
├── dashboard.php / projects.php / tasks.php / contacts.php
├── project_*.php / task_*.php / contact_*.php   CRUD pages
│   ├── project_detail.php / project_edit.php / project_delete.php
│   ├── task_detail.php / task_edit.php / task_delete.php
│   └── contact_detail.php / contact_edit.php / contact_delete.php
├── index.php              Public share-code landing entry
├── composer.json
├── README.md              ← you are here
└── RELEASE.md             Per-version release notes
```

> **URL shape (v2.0).** The app is served through a front controller, so the
> clean paths (`/login`, `/projects`, `/projects/1692`) are the canonical
> form. The legacy `.php` files still exist on disk and are the handlers the
> router dispatches to — they are not linked directly. Every legacy `.php` URL
> and every old slug URL 301-redirects to the canonical one.

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
- v1.2.1: `projects.start_date DATE NULL` — user-settable project start
  date, distinct from the audit `created_at`; renders on the project
  detail page, the projects list, and the client portal dashboard.
  `contacts.company VARCHAR(255) NULL`,
  `contacts.position VARCHAR(255) NULL`,
  `contacts.address TEXT NULL` — the columns the contact write/search
  code has been using all along; missing from the original schema and
  fixed in the same release.
- v1.7.0 (security + bugfix bundle, issues 015–024). Four additive
  migrations; all are guarded (`CREATE TABLE IF NOT EXISTS` /
  `information_schema`) and re-applicable. Mirror these in
  `schema.sql` so a fresh install picks them up automatically.
  - `password_reset_attempts` (issue-018) — per-IP + per-user attempt
    log for the forgot/reset password flow. Columns: `id`,
    `ip_address VARCHAR(45)`, `action ENUM('request','consume')`,
    `user_id INT NULL` (FK to `users`, ON DELETE CASCADE),
    `success TINYINT(1)`, `attempted_at TIMESTAMP`. Indexed on
    `(ip_address, action, attempted_at)` and `(user_id, attempted_at)`.
  - `login_attempts` (issue-019) — per-(IP, username) attempt log for
    the login flow. Columns: `id`, `ip_address VARCHAR(45)`,
    `username_attempted VARCHAR(255)` (the *submitted* value, not a
    `users` FK — this is the field that records a credential-stuffing
    attack rotating usernames), `success TINYINT(1)`,
    `attempted_at TIMESTAMP`. Indexed on
    `(username_attempted, attempted_at)` and `(ip_address, attempted_at)`.
  - `client_share_code_attempts` (issue-023) — per-IP attempt log for
    the public client portal share-code lookups. Columns: `id`,
    `ip_address VARCHAR(45)`, `code_attempted VARCHAR(20)` (the
    submitted value, not a `projects` FK), `project_id INT NULL` (NULL
    for unknown / malformed codes; no `ON DELETE CASCADE` — attempts
    against a deleted project are useful forensic data), `success
    TINYINT(1)`, `attempted_at TIMESTAMP`. Indexed on
    `(ip_address, attempted_at)` and `(project_id, attempted_at)`.
  - `projects.share_code_rotated_at TIMESTAMP NULL DEFAULT NULL`
    (issue-023) — wall-clock time the project's current share code
    was last rotated. Backfilled to `NOW()` for every existing row
    that has a non-null `share_code`. The migration is
    `information_schema`-guarded; the backfill `UPDATE` is
    idempotent (a re-run is a no-op because the `WHERE share_code
    _rotated_at IS NULL` clause matches nothing on the second pass).
- v2.0 (routing): `projects.slug VARCHAR(191) NULL`,
  `tasks.slug VARCHAR(191) NULL`, `contacts.slug VARCHAR(191) NULL` —
  added by `database/migrations/20261022_add_slugs.sql`. Existing rows
  receive a slug on first write. The columns are **retained** even though
  the canonical URLs now use numeric IDs, so that historical slug URLs can
  still resolve and 301 to the current canonical URL.

Individual schema changes ship as **numbered, idempotent** files in
`database/migrations/`. Apply them in date order; each one is safe to run
more than once.

| Migration | What it does |
|---|---|
| `20261003_add_client_share.sql` | Adds the client share-code columns. |
| `20261003_add_contacts_phone.sql` | Adds the contact phone column. |
| `20261005_add_task_estimated_hours.sql` | Adds the task estimated-hours column. |
| `20261005_weight_project_completion.sql` | Weights project completion by task effort. |
| `20261022_add_slugs.sql` | **v2.0** — adds the `slug` column to `projects`, `tasks` and `contacts`. |

---

## Development

The app is procedural PHP — one file per route, no framework. Pages
require `config.php` (auto-generated) plus `includes/Database.php` and
`includes/functions.php`, then output HTML directly.

Since v2.0 a **front controller** (`includes/Router.php`) owns the public URL
space and dispatches to those same page files. The route table lives in one
place; `includes/legacy_redirects.php` resolves historical `.php` and slug
URLs and 301s them to the canonical form. New pages should be added to the
route table, not linked directly as `.php` URLs.

Helpers worth knowing (see `includes/functions.php`):

- `e($s)` — HTML-escape a string for output.
- `formatDateTime($utc, $format)` — display UTC timestamps in the user's
  timezone while preserving SQL calendar dates as entered.
- `formatMoney($amount, $currency)` — render an amount with currency
  symbol.
- `generateShareCode()` — produce a fresh 5-5-5 share code.
- `buildClientShareUrl($code)` — build the absolute client URL.
- `asset_url($path)` — append the file's mtime to a local asset URL so
  browsers pick up a corrected stylesheet or script immediately, despite the
  30-day `Cache-Control` header (v2.0).
- `isRegistrationAllowed()` — resolve `APP_ALLOW_REGISTRATION`; permissive
  when the constant is absent (v2.0).
- `slugify()` / `generateUniqueSlug()` — build the entity slugs used by the
  legacy-redirect layer (v2.0).

Database access goes through the PDO singleton:

```php
$db   = Database::getInstance();
$rows = $db->fetchAll("SELECT … WHERE id = ?", [$id]);
$row  = $db->fetchOne("SELECT … WHERE id = ?", [$id]);
$db->execute("UPDATE … WHERE id = ?", [$id]);
```

Regression tests live in `database/` and `client/`. Each is a single
self-contained PHP script that connects via the same `config.php` +
`includes/` chain the app uses, exercises the behavior, and cleans up
after itself. Run with:

```bash
docker compose exec app php database/task_completion_test.php
docker compose exec app php database/task_inherit_subtask_hours_test.php    # v1.5.0
docker compose exec app php database/task_parent_status_derived_test.php    # v1.5.0
docker compose exec app php database/contacts_columns_test.php
docker compose exec app php database/project_start_date_test.php
docker compose exec app php client/toggle_subtasks_test.php
```

All tests should print a single `… passed.` line and exit 0.

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

## About this build

**Current version:** `v2.0` (2026-10-25) — the v2.0 front-end redesign.
See [RELEASE.md](RELEASE.md) for the full per-version changelog and
[docs/issues/](docs/issues/) for the individual issue write-ups.

The application version shown in the in-app About popup, the README banner,
and the top entry of [RELEASE.md](RELEASE.md) is sourced from the
`APP_VERSION` constant in [`config.php`](config.php). A regression test
([`database/about_popup_test.php`](database/about_popup_test.php))
enforces the drift guard across all four sites.

For the canonical release checklist (bump the constant → changelog → README
banner → docs / attribution → run the tests → smoke-test the popup) see
[`docs/RELEASE_PROCESS.md`](docs/RELEASE_PROCESS.md).

## License

Internal project. License TBD.