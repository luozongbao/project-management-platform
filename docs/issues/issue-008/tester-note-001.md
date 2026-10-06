# Tester Note — issue-008 / v1.3.0 (Project Start Date) — Bugs Found

**Tester:** QA (against v1.3.0 code)
**Related docs:** `docs/issues/issue-008.md`, `README.md` (What's new in v1.3),
`RELEASE.md` (v1.3.0 highlights)
**Build under test:** latest `main` after applying
`database/migrations/20261006_add_project_start_date.sql`

> TL;DR — the originally reported two bugs (one field missing on the
> edit page, one dashboard button pointing to the wrong page) plus
> **two more issues** found while re-verifying the v1.3.0 surface
> against the spec:
> 3. The Project Start Date form row uses the wrong FA icon (a generic
>    flag instead of a calendar / play icon, duplicating the
>    `Expected Completion Date` icon two columns over).
> 4. The project create/edit POST handler has **no CSRF protection**
>    (no token is rendered into the form, and the handler does not
>    validate one) — even though the same file added `validateCsrfToken()`
>    enforcement to the delete dialog in the same release. This is a
>    regression / inconsistency, not strictly part of issue-008, but
>    it surfaces during a v1.3.0 audit so it's flagged together.

---

## Summary table

| # | Severity | Area | Spec says | Actual behaviour | Reproducible |
|---|---|---|---|---|---|
| 1 | **High** (visual / spec mismatch) | Internal console — project edit page must show the Project Start Date input | `project_edit.php` is supposed to render a third date input "next to the existing `Expected Completion Date` and `Actual Completion Date`" | I do not see a Project Start Date input on `/project_edit.php?id=…` | Needs a second look — see note below |
| 2 | **High** (broken primary CTA) | Dashboard "New Project" button | The button must take the user directly to the create form | Dashboard's "New Project" button links to **`projects.php`** (the list), not to `project_edit.php` (the create form) | Yes — 1/1 in static source review |
| 3 | **Low** (visual / spec drift) | Project edit form — start_date icon | The README/RELEASE v1.3.0 narrative implies the third date field is visually grouped with the other two date fields, with consistent calendar-style icons (the other two use `fa-calendar` / `fa-calendar-check`) | The Project Start Date label uses `<i class="fas fa-flag">`, the same icon used for the project status field. The adjacent `expected_completion_date` uses `fa-calendar` and `completion_date` uses `fa-calendar-check`, so the three date columns look inconsistent and the start-date label re-uses the status-icon family | Yes — 1/1 in static source review |
| 4 | **High** (security / regression) | Project create + edit POST handler | All project-state-changing requests must be guarded against CSRF (the delete dialog added in v1.2.0 enforces `validateCsrfToken()`; the new `getCsrfToken()` helper exists for this purpose) | The create/edit `<form method="POST" data-validate>` (around [project_edit.php:188](../../project_edit.php)) does **not** render a hidden `csrf_token` input, and the POST handler at the top of `project_edit.php` does **not** call `validateCsrfToken()`. A cross-site form can submit a `POST` to `project_edit.php` while the victim is signed in and create / modify projects, including setting `start_date`. The same file added the delete-dialog CSRF guard in v1.2.0, so this is a pre-existing inconsistency that should be fixed alongside the v1.3.0 changes | Yes — 1/1 in static source review (no CSRF token in HTML, no `validateCsrfToken` call in handler) |

---

## Bug 1 — "I don't see Project Start Date box in the project edit page any where"

**Spec quote (README / RELEASE / docs/issues/issue-008):**

> "The project create / edit form has a third date input next to the
> existing `Expected Completion Date` and `Actual Completion Date`,
> pre-filled from the stored value."

**Investigation:**

- The `start_date` form field **is** present in
  [`project_edit.php`](project_edit.php) (lines ~269–278) inside the
  `<div class="form-row form-row-3">…</div>` block, sitting to the
  left of `expected_completion_date` and `completion_date`.
- The server-side POST handler in the same script reads
  `$_POST['start_date']`, runs it through the new
  `parseProjectDate()` validator, and writes it to
  `projects.start_date` (UPDATE and INSERT both bind it).
- The CSS class `form-row-3` exists in
  [assets/css/style.css](assets/css/style.css) (~line 166) and renders
  as a 3-column grid on wide screens and a 1-column stack under the
  ~768 px breakpoint.
- The DB migration
  [`database/migrations/20261006_add_project_start_date.sql`](../../database/migrations/20261006_add_project_start_date.sql)
  is idempotent and runs cleanly against the test DB.

**So the field is in the source.** The user-reported "I don't see it"
has not been reproduced in static analysis.** It is still worth verifying
in the running browser because the failure mode that fits the report is
common and easy to miss:

### Likely causes to rule out before patching code

1. **Stale browser cache / no hard reload.**
   The user is most likely seeing the v1.2.0 page (no `form-row-3`
   block, no `start_date` input). The PHP file changed but the asset
   bundle / HTML the browser cached still has the old DOM.
   *Ask the user to do a hard reload (Ctrl+Shift+R) and re-test before
   treating this as a real bug.* This is the single most likely cause
   given that the field is clearly in the source.
2. **Wrong file served.** Confirm the deployment is actually serving
   the new `project_edit.php`. If the user is on a separate stage
   branch / a forked container that was rebuilt before the migration
   ran, the schema and the source could disagree.
3. **Browser extension** (form filler, dark-mode CSS injector) hiding
   the `start_date` row. Have the user open DevTools, inspect the
   DOM, and confirm whether `<input id="start_date" …>` exists at all.
5. **The user is looking at `project_detail.php` (the read page), not
   the edit page.** Worth asking which URL they actually opened.
   `project_detail.php` does NOT have an editable field by design —
   it shows a `Started: …` line only when `start_date` is non-null
   (see ~line 106).
6. **`form-row-3` layout collapses under the viewport breakpoint
   and is hidden behind something else.** Unlikely but worth checking
   at a few different widths.

### If the field really is missing on the running page

Likely fix: ensure the deployed `project_edit.php` contains the
`<div class="form-row form-row-3">` block, and that `assets/css/style.css`
contains the `.form-row-3` rule. If both are present in the container's
working copy, the issue is environmental (cache, reverse-proxy, branch
mismatch) rather than a code defect.

### Regression test gap

There is no automated check that asserts the edit page actually renders
the `start_date` input. The existing regression test
`database/project_start_date_test.php` only covers the value (DB +
client dashboard). Recommend adding a minimal "form contains the
field" check (e.g. `grep` against the rendered HTML, or a DOM-level
assertion in Playwright/Cypress if/when those are added).

---

## Bug 2 — Dashboard "+ New Project" must go to create-new-project page (not the list)

**Spec quote (README / docs/issues/issue-004, docs/issues/issue-008):**

> "The domain root hosts the public share-code form; an `Owner sign
> in` link on the form reaches `/login.php`. The internal console
> (`dashboard.php`, `projects.php`, …) drives authenticated users."
>
> Implicit but obvious: a `+ New Project` button on the dashboard must
> take the user **directly to the create form**
> (`project_edit.php` with no `?id=…`), not to the projects list.

**Actual behaviour** (static read of
[`dashboard.php`](dashboard.php) lines 178–184):

````html
<div class="section-header">
    <h2>
        <i class="fas fa-folder-open"></i>
        Recent Projects
    </h2>
    <a href="projects.php" class="btn btn-primary">
        <i class="fas fa-plus"></i>
        New Project
    </a>
</div>
````

The button labelled **"New Project"** is rendered as
`<a href="projects.php">`. Clicking it lands the user on the
**projects list page**, not on the create form. The user has to then
click another `+ New Project` button at the top of `projects.php`
(which **does** correctly point to `project_edit.php`, see line 44).

**Reproduction (manual):**

1. Sign in.
2. Open `/dashboard.php`.
3. In the **Recent Projects** section, click the blue **+ New Project**
   button (top-right of the section).
5. Observe the URL changes to `/projects.php` — the projects list,
   not the create form.

**Expected:** URL should change to `/project_edit.php` (no `?id=…`)
— the create-new-project form, which is the same page as the project
edit page per issue-008 / v1.3.0 spec.

**Severity:** High — this is a primary CTA on the most visited page
in the internal console. Every new project requires two clicks
instead of one, and the button label is misleading ("New Project"
should create, not list).

### Fix recommendation

Change the `href` on line 182 of `dashboard.php`:

````html
<!-- from -->
<a href="projects.php" class="btn btn-primary">
    <i class="fas fa-plus"></i>
    New Project
</a>

<!-- to -->
<a href="project_edit.php" class="btn btn-primary">
    <i class="fas fa-plus"></i>
    New Project
</a>
````

For consistency, consider whether the same fix should be applied to
any other "in-section" New-Project buttons elsewhere on the dashboard
(future feature work — not blocking for v1.3.0).

### Regression test gap

The existing test
`database/project_start_date_test.php` does not assert where dashboard
CTAs point. Recommend adding a small DOM-level assertion (or a Playwright
smoke test) for: "Dashboard `+ New Project` button → routes to
`project_edit.php` with no `?id=…`."

---

## Bug 3 — Project Start Date label uses the wrong icon (`fa-flag`, conflicts with status icon)

**Spec quote (README / RELEASE / docs/issues/issue-008):**

> "The project create / edit form has a third date input next to the
> existing `Expected Completion Date` and `Actual Completion Date`."

The spec groups the new field with the other two date fields. Both
existing date fields use calendar-style FontAwesome icons:

- `expected_completion_date` → `<i class="fas fa-calendar"></i>`
- `completion_date`         → `<i class="fas fa-calendar-check"></i>`

**Actual behaviour** (static read of
[`project_edit.php`](project_edit.php) line 272, inside the
`<div class="form-row form-row-3">` block introduced for v1.3.0):

````html
<label for="start_date">
    <i class="fas fa-flag"></i>
    Project Start Date
</label>
````

Two issues:

1. The flag icon is the **same icon used for the project status
   field** higher up on the same form (line ~217: `<label for="status">
   <i class="fas fa-flag"></i> Status`). That creates visual
   ambiguity — the user has to think twice about which "flag" they
   are reading.
2. The three date columns end up with two different icon families
   (`flag` vs `calendar` / `calendar-check`), so the date row does not
   read as a consistent group of three date pickers.

**Severity:** Low (cosmetic), but it directly contradicts the implicit
"third date input next to the other two" spec phrasing.

**Reproduction (static):**

1. Open `project_edit.php`.
2. Search for `id="start_date"`.
3. Observe the icon is `fa-flag`, not a calendar-style icon.

**Expected:** An icon from the calendar family
(`fa-calendar-day` is the closest FontAwesome equivalent — a single
calendar page) so the three columns look like a cohesive date picker
row.

### Fix recommendation

In [project_edit.php:272](../../project_edit.php):

````html
<!-- from -->
<label for="start_date">
    <i class="fas fa-flag"></i>
    Project Start Date
</label>

<!-- to -->
<label for="start_date">
    <i class="fas fa-calendar-day"></i>
    Project Start Date
</label>
````

Optionally audit any other v1.3.0 / v1.2.0 additions for icon
consistency (out of scope for this tester note).

---

## Bug 4 — Project create / edit POST handler has no CSRF protection (regression / inconsistency)

**Spec quote (README / RELEASE / docs/issues/issue-005):**

> "Deletion is submitted as `POST` with the application's standard
> CSRF token. The server re-checks session authentication, project
> ownership, the CSRF token, and the typed Project Code before
> deleting."

`includes/functions.php` ships both `getCsrfToken()` and
`validateCsrfToken()` for exactly this purpose, and the project
delete dialog (also in `project_edit.php` since v1.2.0) renders a
`<input type="hidden" name="csrf_token" …>` and validates it in
[`project_delete.php`](../../project_delete.php).

**Actual behaviour:**

- The create/edit `<form method="POST" data-validate>` in
  [project_edit.php:188](../../project_edit.php) does **not** render a
  hidden `csrf_token` input.
- The POST handler at the top of the same file does **not** call
  `validateCsrfToken($_POST['csrf_token'] ?? null)` before reading
  `$_POST` and binding values into the `INSERT` / `UPDATE` against
  the `projects` table (including the new `start_date`).
- `$csrf_token = $project ? getCsrfToken() : null;` on line 155 is
  declared only so the **delete dialog** can render its hidden
  input — the main create/edit form never reads `$csrf_token`.

**Impact:**

- A cross-site form (or any unauthenticated POST) can submit a
  request to `project_edit.php` while the victim is signed in and:
  - Create a new project with a chosen `name`, `scope`, `budget`,
    `currency`, `start_date`, `expected_completion_date`,
    `completion_date`, and `status` — including the new `start_date`
    that v1.3.0 just introduced.
  - Update an existing project they own by adding `?id=<their_id>` to
    the action URL.
- The same vulnerability exists on `contact_edit.php`, `task_edit.php`,
  and other write handlers that have not yet been audited for CSRF.
  This tester note covers `project_edit.php` because that is the
  surface that issue-008 touches. A separate ticket should sweep
  the rest.

**Severity:** High. CSRF on a project-mutation endpoint is the
classic "funds transfer" class of issue — the user is signed in,
the attacker forges the action, and the user is plausibly the actor.
The CSRF helper already exists in this codebase; the omission is
not a design question, it is a missing call.

**Reproduction (static — no live repro needed):**

1. Open [`project_edit.php`](../../project_edit.php).
2. Observe line 188:
   ````html
   <form method="POST" data-validate>
   ````
   No `<input type="hidden" name="csrf_token" …>` is rendered inside
   the form.
3. Observe the POST handler at the top of the same file: it reads
   `$_POST['name']`, `$_POST['start_date']`, etc. and writes to the
   DB without ever calling `validateCsrfToken()`.
4. Confirm the CSRF helpers do exist in
   [`includes/functions.php`](../../includes/functions.php) (lines
   ~55–70): `getCsrfToken()` and `validateCsrfToken($token)`.

A live-browser repro (PoC) can also be staged from any external origin
the victim visits while signed in to the app, by auto-submitting a
hidden form pointing at `/project_edit.php`. We do not include the
HTML here; the static evidence above is sufficient.

### Fix recommendation

In [project_edit.php](../../project_edit.php), two edits:

1. Always generate the CSRF token, not only on edit:

   ````php
   // top of file (around line 155)
   $csrf_token = getCsrfToken();          // was: $project ? getCsrfToken() : null;
   ````

2. Render the hidden input in the create/edit form, next to the
   existing form-actions block:

   ````html
   <form method="POST" data-validate>
       <input type="hidden" name="csrf_token" value="<?= e($csrf_token) ?>">
       <!-- ... existing rows ... -->
   </form>
   ````

3. Validate it at the top of the POST handler, before reading any
   other `$_POST` value:

   ````php
   if ($_POST) {
       if (!validateCsrfToken($_POST['csrf_token'] ?? null)) {
           $errors[] = 'Invalid form token. Please reload the page and submit again.';
           // Bail out of the rest of the handler; the form will re-render
           // with this error pre-filled. We deliberately do NOT validate
           // the other fields once CSRF has failed, to keep the error
           // UX clean.
       } else {
           // ... existing parseProjectDate / validation / DB write ...
       }
   }
   ````

4. (Separate ticket, not blocking for v1.3.0) Sweep all other write
   handlers in the codebase for the same omission:
   `task_edit.php`, `contact_edit.php`, `register.php`,
   `forgot_password.php`, `reset_password.php`, AJAX endpoints in
   `ajax/`.

### Regression test gap

Neither `database/project_start_date_test.php` nor any other regression
test in the repo asserts the presence of a CSRF token in the
project-edit form. Recommend adding a Playwright/Cypress smoke test
that:

- Submits a `POST` to `project_edit.php` **without** a CSRF token.
- Asserts the response surfaces an error and no row in `projects`
  is mutated (count rows before and after).

---

## Cross-check against the spec

The v1.3.0 release notes promise:

| Promise | Status |
|---|---|
| `projects.start_date DATE` column, NULL-able, idempotent migration | ✅ Confirmed in [`database/migrations/20261006_add_project_start_date.sql`](../../database/migrations/20261006_add_project_start_date.sql) |
| Project create / edit form has a third date input (Project Start Date) | ⚠️ Source has it, but user reports not seeing it — needs a live re-test before treating as a regression |
| Form pre-fills from stored value | ✅ Source uses `$project['start_date'] ?? $_POST['start_date'] ?? ''` |
| Malformed date → clear error | ✅ `parseProjectDate` pushes `$errors[]` for bad format / non-calendar / non-string |
| `start_date` later than expected/actual completion → non-blocking warning | ✅ Two `errors[]` warnings added; spec says "non-blocking" — note: they are still rendered in the `alert-danger` block above the form, which is **blocking-looking** UX even if the value still saves. Out of scope of these two bugs but flagging it. |
| Internal project detail page shows `Started: <date>` (hidden when NULL) | ✅ Confirmed at [`project_detail.php`](project_detail.php) ~line 106 (`<?php if (!empty($project['start_date'])): ?>`) |
| Internal projects list shows `Started: <date>` under each card | ✅ Confirmed at [`projects.php`](projects.php) ~line 113 |
| Client dashboard `Started` row reads `start_date`, renders `Not set` when NULL | ✅ Confirmed at [`client/dashboard.php:215`](../../client/dashboard.php) — uses `!empty($project['start_date']) ? formatDateTime(...) : <em>Not set</em>` |
| `created_at` still used for sort order on the projects list | ✅ Confirmed — `projects.php` query orders by `p.created_at DESC` |
| Existing rows keep `start_date = NULL` after migration | ✅ Migration does not back-fill |
| Project Start Date label uses a calendar icon consistent with the other two date columns | ❌ **Bug 3** — `project_edit.php:272` uses `fa-flag`, which collides with the project `Status` icon two rows up |
| CSRF protection on project create / edit POST (consistent with delete-dialog CSRF introduced in v1.2.0) | ❌ **Bug 4** — `project_edit.php` does not render a hidden `csrf_token` input in the create/edit form and the handler does not call `validateCsrfToken()` |

---

## Recommended follow-up

1. Ask the user to **hard-reload** and re-test bug 1 in a fresh
   browser profile before patching. If still missing on the live
   page, capture a screenshot of the rendered DOM (DevTools →
   Elements → search `start_date`) and attach to the issue.
2. Apply the one-line fix in [dashboard.php](dashboard.php) for bug 2
   (`href="projects.php"` → `href="project_edit.php"`).
3. **Bug 3 — icon fix.** In
   [project_edit.php:272](../../project_edit.php), change
   `<i class="fas fa-flag"></i>` on the Project Start Date label to
   `<i class="fas fa-calendar-day"></i>` (or `fa-play`) so the three
   date columns form a visually consistent row of calendar icons.
   Also audit any other newly-added icons that came in with v1.3.0
   for consistency.
4. **Bug 4 — CSRF fix (high priority).** In
   [project_edit.php](../../project_edit.php):
   - Always render a hidden `csrf_token` input in the create/edit
     form, not only inside the delete dialog:

     ````php
     // top of file
     $csrf_token = getCsrfToken();

     // inside the form, before any submit button
     <input type="hidden" name="csrf_token" value="<?= e($csrf_token) ?>">
     ````

   - In the POST handler, before the `if (empty($errors))` block:

     ````php
     if (!validateCsrfToken($_POST['csrf_token'] ?? null)) {
         $errors[] = 'Invalid form token. Please reload the page and submit.';
     }
     ````

   - The same pattern should be applied to other state-changing
     handlers that currently don't validate CSRF (`task_edit.php`,
     `contact_edit.php`, `register.php`, `forgot_password.php`,
     `reset_password.php` — out of scope for this tester note but
     worth a separate ticket).
5. Add a Playwright/Cypress regression that:
   - Visits `/dashboard.php`.
   - Asserts the `+ New Project` button under "Recent Projects" has
     `href="project_edit.php"`.
   - Asserts the rendered edit page has `<input id="start_date"
     name="start_date">`.
   - Submits a `POST` to `project_edit.php` **without** a CSRF token
     and asserts the response surfaces an error and does not mutate
     any row.
6. (Out of scope but worth a ticket) The "non-blocking warning" is
   currently rendered in the same `alert-danger` block as blocking
   validation errors. Per spec it should be visually distinct so
   users do not feel they have to fix something to save.

---

## Files referenced

- [`project_edit.php`](../../project_edit.php)
- [`dashboard.php`](../../dashboard.php)
- [`projects.php`](../../projects.php)
- [`project_detail.php`](../../project_detail.php)
- [`assets/css/style.css`](../../assets/css/style.css)
- [`database/migrations/20261006_add_project_start_date.sql`](../../database/migrations/20261006_add_project_start_date.sql)
- [`database/project_start_date_test.php`](../../database/project_start_date_test.php)
- [`README.md`](../../README.md) (What's new in v1.3)
- [`RELEASE.md`](../../RELEASE.md) (v1.3.0)
- [`docs/issues/issue-008.md`](../issue-008.md)