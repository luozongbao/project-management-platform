# Add Project Start Date Field

**Status:** Accepted — Unreleased, ships in **v1.3.0**
**Type:** Feature / Data Model
**Priority:** Medium
**Release:** v1.3.0 (unreleased, packaged together with v1.3.1; see [RELEASE.md](../../RELEASE.md))
**Affects:** `projects` table, project create/edit form, project read view, client portal dashboard

## Problem

The client dashboard renders a `Started` / `开始日期` row:

```php
// client/dashboard.php
<li><span class="k"><?= e(t('dash.started')) ?></span>
     <span class="v"><?= e(formatDateTime($project['created_at'], 'M j, Y')) ?></span></li>
```

Today this row is bound to `projects.created_at`, which is the **database
row creation timestamp** — i.e. the moment the project was first inserted.
That value is:

1. Not user-controlled. The project manager has no way to record when the
   project actually began (a project is rarely created on the day it
   starts; it is often created weeks or months later as a tracking shell).
2. Semantically wrong. "Started" on a project dashboard is a planning
   concept ("when did work begin?"), not an audit concept ("when was this
   row inserted?"). Confusing the two means the displayed value is wrong
   for any project created retroactively.
3. Sometimes empty in the UI. When a project's `created_at` happens to
   fall outside the user's display range, or when the cell is rendered
   for a project that was migrated in without a creation timestamp, the
   row appears blank with no way to fill it in.
4. Not editable. The project edit form
   (`project_edit.php`) has no input for it. There is no `start_date`
   column on `projects`. The schema only carries `created_at`,
   `updated_at`, `expected_completion_date`, `completion_date`.

The internal console has the same gap: `project_detail.php` and
`projects.php` do not surface a project start date at all, so the only
place a value appears today is the client portal — and there it's wrong.

## Goals

- Add a user-settable **Project Start Date** that represents when work on
  the project actually began.
- Make it editable from the project create/edit form, alongside the
  existing `expected_completion_date` and `completion_date` inputs.
- Render it consistently in:
  - The internal project detail page (read mode and edit mode).
  - The internal projects list / dashboard, where a start date is
    meaningful for "days in flight" or "schedule slippage" views.
  - The public client portal dashboard, where the existing `Started` row
    currently mis-reports `created_at` as the start date.
- Preserve `created_at` for what it is — an audit timestamp — and stop
  using it as a user-facing field.
- Be safe to roll out on databases that already contain projects: the
  new column must default to `NULL`, and existing rows must keep
  displaying sensibly (i.e. the row shows "Not set" rather than an
  empty cell).

## Data model

### New column

```sql
ALTER TABLE projects
  ADD COLUMN start_date DATE NULL AFTER expected_completion_date;
```

Semantics:

- `DATE` (not `DATETIME`) — consistent with the existing
  `expected_completion_date` and `completion_date` columns.
- `NULL` means "start date unknown / not recorded". Existing rows stay
  `NULL`.
- No default value. Today is rarely the correct project start date and
  back-filling it would silently invent data.
- `start_date <= expected_completion_date` and, once a project is
  completed, `start_date <= completion_date`. The application should
  surface a non-blocking warning rather than reject the save, so
  historical imports aren't blocked.
- Independent of `status`. A project can be `not_started` with a
  `start_date` (planning) or `in_progress` without one (forgotten).

### Migration

- New file: `database/migrations/20261006_add_project_start_date.sql`.
- Must be idempotent — guard the `ALTER TABLE` with
  `information_schema.COLUMNS` the same way
  `20261005_add_task_estimated_hours.sql` does.
- MariaDB DDL implicitly commits; do not wrap in a transaction.
- Documented in `README.md` next to the other v1.3 migrations.

## UX / form changes (`project_edit.php`)

- Add a third date input in the same row group as `expected_completion_date`
  and `completion_date`:

  ```html
  <div class="form-row">
      <div class="form-group">
          <label for="start_date"><i class="fas fa-flag"></i> Project Start Date</label>
          <input type="date" id="start_date" name="start_date"
                 value="<?= e($project['start_date'] ?? $_POST['start_date'] ?? '') ?>">
          <small>Optional. The day work on this project actually began.</small>
      </div>
      <div class="form-group">…expected_completion_date…</div>
      <div class="form-group">…completion_date…</div>
  </div>
  ```

- Validation rules (mirroring the existing
  `$parseProjectDate` helper in `project_edit.php`):
  - Must match `^\d{4}-\d{2}-\d{2}$` if non-empty.
  - Must be a real calendar date (`checkdate`).
  - If both `start_date` and `expected_completion_date` are set and
    `start_date > expected_completion_date`, push a non-blocking error
    like `"Start date is later than expected completion date."` and let
    the user confirm.
  - If `completion_date` is set and `start_date > completion_date`,
    same treatment.
- On create: insert the column. On update: update it. The form's
  prefill loop must read `$project['start_date']`.
- No auto-population from `created_at`. The two fields are different
  concepts; silently setting one from the other surprises the user.

## Display changes

### Internal — `project_detail.php`

- In the existing `project-meta` strip (which currently shows
  `status`, `responsible person`, and the due date), add a
  `Started:` span conditional on `$project['start_date']` being non-null,
  mirroring the existing `Due:` pattern.
- Do **not** remove `created_at` from the audit log, but stop surfacing
  it as the project's start date.

### Internal — `projects.php` list

- Optionally show `Started → <date>` as a small meta line under the
  project card, alongside the existing status / progress line. Keep
  layout minimal; this card already has a lot of content.

### External — `client/dashboard.php`

- Replace the existing row:

  ```php
  <li><span class="k"><?= e(t('dash.started')) ?></span>
       <span class="v"><?= e(formatDateTime($project['created_at'], 'M j, Y')) ?></span></li>
  ```

  with a `start_date`-aware version. If `start_date` is `NULL`, show
  the same "Not set" pattern used for `expected`:

  ```php
  <li><span class="k"><?= e(t('dash.started')) ?></span>
       <span class="v"><?= $project['start_date']
              ? e(formatDateTime($project['start_date'], 'M j, Y'))
              : '<em class="text-muted">' . e(t('dash.not_set')) . '</em>' ?></span></li>
  ```

- The client dashboard's SELECT (currently
  `SELECT p.id, p.name, …, p.created_at, …`) must add
  `p.start_date` so the value is available to the template without an
  extra query.

## Localization

- `client/lang.php` already has `dash.started` ("Started" /
  "开始日期"). The label is fine; no change needed.
- If we want a separate "Start Date" label distinct from "Started", add
  a new key (e.g. `dash.start_date`) and use it on the internal form;
  keep the existing `dash.started` for the dashboard label so we don't
  break translations.

## Error responses

| Condition                                                   | Response                                                              |
| ----------------------------------------------------------- | --------------------------------------------------------------------- |
| `start_date` empty                                          | OK — treat as "not set".                                              |
| `start_date` not in `YYYY-MM-DD`                            | `400` with `"Project start date must be a valid date."`               |
| `start_date` not a real calendar date                       | `400` with the same message.                                          |
| `start_date > expected_completion_date`                     | Non-blocking warning; user can save anyway.                           |
| `start_date > completion_date`                              | Non-blocking warning; user can save anyway.                           |
| Migration re-run on a DB that already has the column        | No-op (idempotent).                                                   |

## Acceptance criteria

- A new column `projects.start_date DATE NULL` exists, guarded by an
  idempotent migration that is safe to re-run.
- The project create form has a `Start Date` input that defaults to
  empty.
- The project edit form has the same input, prefilled with the stored
  value, and submitting it writes the column.
- Validation rejects malformed dates with a clear error and accepts
  empty input as "not set".
- The project detail (read) page shows `Started: <date>` when set and
  hides the label when not set.
- The client portal dashboard shows the project's actual `start_date`
  in the `Started` row instead of `created_at`. When `start_date` is
  `NULL`, the row renders as "Not set" instead of an empty cell.
- The internal `created_at` audit timestamp continues to work as
  before (sort order on `projects.php`, "Created" stamps, etc.).
- Existing projects with no `start_date` continue to render
  correctly — the field is optional everywhere.
- A regression test asserts that, given a `start_date` different from
  `created_at`, the client dashboard and project detail page both
  display the user-supplied value, not `created_at`.

## Out of scope

- Auto-populating `start_date` from `created_at` on existing rows.
- A "Started" / "Not started" boolean derived from `start_date` and
  `status` (separate concern; would touch the `projects.status` enum).
- Notifications or activity-feed entries when `start_date` is set or
  changed.
- Restricting `start_date` to be unset until the project transitions
  to `in_progress`.