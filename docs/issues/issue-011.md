# Parent Task Effort — "Sum of Subtask Hours" Mode

**Status:** Proposed
**Type:** Feature / Data Model (small) / UX
**Priority:** Medium
**Depends on:** [issue-006](./issue-006.md) (task estimates), [issue-007](./issue-007.md) (effort-weighted completion)
**Affects:** `tasks` table, `task_edit.php`, `task_detail.php`, `tasks.php`, `client/dashboard.php`, `database/schema.sql`, new `database/migrations/…sql`, `includes/functions.php`

## Problem

After issue-006 ships, every task has an optional `estimated_hours`
field. After issue-007, parent-task completion is computed by
`calculateTaskCompletionMap()` in `includes/functions.php` and a
parent's own `estimated_hours` is **not** part of that formula — only
its children's hours are. That works, but it leaves two usability
gaps:

1. **The "Total Task Time" field on a parent task is dead weight.**
   A user opening `task_edit.php` for a task that has sub-tasks sees
   a free-text Total Task Time input that the completion math ignores.
   Users do not know this, and routinely type a number into it
   expecting it to count.
2. **The user has to type the parent's effort twice.** When the
   parent's effort is the sum of its sub-tasks' efforts, the user
   currently has to compute that sum themselves and enter it as the
   parent's `estimated_hours`. If a sub-task's estimate changes, the
   parent's manual entry silently goes stale and the project dashboard
   and client portal continue to roll up using the **children's**
   estimates (because the parent's `estimated_hours` is *not* used in
   the rollup), so the work the user did adding the parent's number
   is purely cosmetic.

The user request:

> For tasks that have subtasks, if total hours not specified use total
> subtask hours as total hours. Or else, all tasks must specify task
> time. But since the main task must be created before the sub task,
> the main task with subtask can check a checkbox "use total sub task
> as total hours" and disable Total Task Time. Otherwise, must specify
> total task time.

Restated, the rule is:

- **A task with at least one existing sub-task** has two valid states
  for `Total Task Time`:
  1. **Inherit mode** — the parent is set to inherit its estimate
     from the sum of its children's `estimated_hours`. The
     `Total Task Time` field is disabled and shows the live sum.
  2. **Manual mode** — the parent supplies its own
     `estimated_hours`. The `Total Task Time` field is enabled and
     required (a non-empty, positive value).
- **A task with no sub-tasks** behaves as today: `Total Task Time`
  stays optional, and `NULL` is allowed.
- **A parent task at create time** is *always* a task without
  sub-tasks (because the parent must be created first; this is the
  invariant the user explicitly named). The checkbox is therefore
  not shown on create. It is shown only on edit, and only when the
  task currently has at least one child.

The motivation: effort-weighted completion rollups
([issue-007](./issue-007.md)) already use the **children's**
`estimated_hours` to compute the parent's completion. The user's
checkbox wires the parent's *displayed* and *persisted* estimate to
the same source of truth, so dashboards, exports, and the client's
read-only portal all agree.

## Goals

- Add a new nullable column `tasks.inherit_subtask_hours TINYINT(1)
  NULL` (or equivalent Boolean) that records whether the parent's
  `estimated_hours` is the sum of its children's `estimated_hours`.
- On the task edit form, when editing a task that has at least one
  sub-task:
  - Show a checkbox labelled **"Use sum of sub-task hours as total
    task time"** (plus 中文).
  - When the checkbox is **checked**, disable and grey out the
    `Total Task Time` numeric input and unit selector, and render
    a live read-only "Computed total: <sum> hours" hint computed
    from the saved sub-tasks.
  - When the checkbox is **unchecked**, require a non-empty positive
    `Total Task Time` (same validation as the existing field; the
    "zero is not a usable weight" rule from issue-006 still applies,
    so the minimum is `> 0`).
- On the task edit form, when creating a new task (no existing
  children by definition): the checkbox is not shown; the existing
  optional `Total Task Time` field is unchanged.
- On the task edit form, when editing a task that has no sub-tasks
  at all: the checkbox is not shown; the field is unchanged.
- Persist the choice server-side. On reload after save, the checkbox
  state, the field's enabled/disabled state, and the persisted
  `estimated_hours` must all agree.
- Update the project completion rollup
  (`project_stats` view + `calculateTaskGroupCompletion()`) so that
  a parent in **inherit mode** is treated as if its
  `estimated_hours` equals the sum of its children's
  `estimated_hours` for both the application-side and the SQL-side
  rollup. **Otherwise the user's existing v1.2.0 weighted completion
  numbers would silently change.**
- On `task_detail.php`, in `tasks.php`, in the internal
  `project_detail.php`, and in `client/dashboard.php`, render the
  parent's `Total Task Time` consistently:
  - Inherit mode + sub-tasks present with positive estimates →
    render `<sum> hours` with a small helper icon (`fa-calculator` or
    `fa-link`) and a tooltip "Auto-calculated from sub-tasks".
  - Inherit mode + sub-tasks all `NULL` or non-positive → render
    `Not estimated (no sub-task estimates)` and surface a one-time
    warning to the manager on `task_detail.php` ("Inherit mode is
    on but none of the sub-tasks have an estimate; the parent's
    completion will fall back to the equal average.").
  - Manual mode → render the saved value as today.
  - No sub-tasks at all → render the saved value or `Not estimated`
    as today.
- Make the migration **idempotent** in the project's standard shape
  (`information_schema` guard, no implicit transaction, fresh-install
  `database/schema.sql` updated to match).

## Non-goals

- Auto-promoting a sub-task's edit into a parent update. When a
  sub-task's `estimated_hours` changes, the parent in inherit mode
  is automatically recomputed *on read* (it is not a stored value),
  but no notification / activity feed / email is fired.
- Changing the completion rollup math for **sub-tasks**. Sub-tasks
  are still leaf nodes; their estimates are still individual
  weights, not subject to a further "use grandchildren" cascade.
- Auto-detecting when "all sub-tasks have an estimate" and silently
  flipping a parent into inherit mode. The user (manager) is the one
  who decides; we never move the checkbox for them.
- Persisting the `inherit_subtask_hours` choice across the moment a
  parent loses its last sub-task. If a sub-task is deleted and the
  parent now has zero children, the `inherit_subtask_hours` flag is
  preserved on the row (for audit), but it is no longer consulted
  anywhere — the field falls back to the saved `estimated_hours`.
- A new "weighted sum" mode for the parent. There are exactly two
  modes: inherit and manual. No mid-ground like "inherit if
  positive, otherwise manual"; the checkbox is the single source of
  truth.
- Anything that changes today's behaviour for tasks with **no**
  sub-tasks. The v1.2.0 contract from issue-006 is preserved.

## UX

### `task_edit.php` — create flow (parent without children)

The form is unchanged. The "Effort Estimate" section continues to
render the existing optional `Total Task Time` field. **No checkbox
is shown.** This is the rule "main task must be created before sub
task" honoured: a brand-new task has no children, so the inherit
question cannot be answered yet.

### `task_edit.php` — edit flow (parent with no children)

Same as create. The checkbox is hidden when
`SELECT COUNT(*) FROM tasks WHERE parent_task_id = ?` is `0`. The
field remains optional.

### `task_edit.php` — edit flow (parent with ≥ 1 child)

The "Effort Estimate" section gains a single line above the existing
`Total Task Time` row:

```html
<div class="form-group">
    <label class="checkbox">
        <input type="checkbox"
               id="inherit_subtask_hours"
               name="inherit_subtask_hours"
               value="1"
               <?= !empty($task['inherit_subtask_hours']) ? 'checked' : '' ?>>
        <i class="fas fa-link"></i>
        Use sum of sub-task hours as total task time
    </label>
    <small class="field-help">
        When checked, the total below is locked to the sum of this
        task's sub-task estimates. Leave unchecked to enter a manual
        total.
    </small>
</div>
```

Then the existing `Total Task Time` field gets a `data-locked`
attribute and a sibling hint:

```html
<div class="form-group">
    <label for="total_time">Total Task Time:</label>
    <div class="estimate-input" data-locked="<?= !empty($task['inherit_subtask_hours']) ? '1' : '0' ?>">
        <input type="number" id="total_time" name="total_time"
               min="0" step="any"
               value="<?= e($total_time_value) ?>"
               <?= !empty($task['inherit_subtask_hours']) ? 'disabled' : '' ?>
               placeholder="<?= !empty($task['inherit_subtask_hours']) ? 'Computed from sub-tasks' : 'Not estimated' ?>">
        <select id="total_time_unit" name="total_time_unit" aria-label="Total task time unit"
                <?= !empty($task['inherit_subtask_hours']) ? 'disabled' : '' ?>>
            <option value="hours"  <?= $selected_time_unit === 'hours'  ? 'selected' : '' ?>>Hours</option>
            <option value="mandays"<?= $selected_time_unit === 'mandays' ? 'selected' : '' ?>>Mandays</option>
        </select>
    </div>
    <p class="field-help" id="inherit-hint" hidden>
        Computed total: <strong><span id="inherit-sum">0</span></strong>
        hours (from <span id="inherit-count">0</span> sub-task(s) with
        positive estimates).
    </p>
    <p class="field-help" id="inherit-empty-hint" hidden>
        None of the sub-tasks currently have a positive estimate. The
        parent's completion will use the equal-average fallback until
        at least one sub-task has a value.
    </p>
</div>
```

Behaviour:

| Checkbox | Field state                                  | Validation                                                                                                                            |
| -------- | -------------------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------- |
| Checked  | `disabled`, value = (server-rendered) sum    | `total_time` and `total_time_unit` are ignored on submit even if the browser keeps a stale value; the form treats them as null.        |
| Unchecked| Enabled, empty `placeholder="Not estimated"` | If the task has sub-tasks, `total_time` must be a finite, non-negative number; **zero is rejected** (issue-006 rule still applies). |
| Unchecked| Enabled, value > 0                           | Saved as `estimated_hours = value * (unit === 'mandays' ? 8 : 1)`.                                                                    |

The "lock" is enforced both client-side (the disabled attribute +
`aria-disabled`) and server-side (the POST handler must reject
writes to `total_time` when `inherit_subtask_hours` is set, and
must clear `estimated_hours` to `NULL` on save when in inherit
mode — the value is always derived, never stored on the parent
row).

### Display surfaces

| Surface               | Manual mode                                       | Inherit mode + positive child estimates                                | Inherit mode + no positive child estimates                              |
| --------------------- | ------------------------------------------------- | ---------------------------------------------------------------------- | ----------------------------------------------------------------------- |
| `task_detail.php`     | `<value> hours` (or `×N mandays`)                 | `<sum> hours` + `fa-link` icon + tooltip "Auto-calculated from sub-tasks" | `Not estimated (no sub-task estimates)` + warning banner above the field |
| `tasks.php` list      | `<value> hours`                                   | `<sum> hours` (calc.)                                                  | `Not estimated`                                                        |
| `client/dashboard.php`| `<value> hours` (already shown if not null)       | `<sum> hours` (calc.)                                                  | `Not estimated`                                                        |
| `project_detail.php`  | `<value> hours` (unchanged)                       | `<sum> hours` (calc.)                                                  | `Not estimated`                                                        |

The client portal shows the **same number** the internal console
shows, because both call the same helper.

### Empty / disabled states

- The checkbox is **disabled** (not hidden) on a parent with **zero**
  sub-tasks, with a tooltip "Add a sub-task to enable inherit mode."
  The form hides the input via CSS to avoid misleading users, but
  the disabled state keeps the layout stable if children are added
  mid-edit.
- If a sub-task is created in another tab and the page is reloaded,
  the checkbox becomes enabled and re-renders with the up-to-date
  sum.

## Data flow

### Save path (POST `task_edit.php`)

1. Resolve `inherit_subtask_hours` from `$_POST['inherit_subtask_hours']`
   (string `'1'` or unset). Treat anything other than the literal
   string `'1'` as `false`.
2. Look up the task's child count:
   ```sql
   SELECT COUNT(*) AS c FROM tasks WHERE parent_task_id = :task_id
   ```
3. If `child_count === 0`:
   - The checkbox value is **forced to `0`** regardless of what
     the user posted (defence in depth — the field should not have
     been rendered, but a stale POST must not corrupt state).
   - `estimated_hours` is computed exactly as today from
     `total_time` and `total_time_unit` (or `NULL` if blank).
4. If `child_count >= 1`:
   - If `inherit_subtask_hours` is true:
     - `estimated_hours` is **set to `NULL` on the parent row**.
       The displayed/stored total is always derived on read.
     - Persist `inherit_subtask_hours = 1`.
   - If `inherit_subtask_hours` is false:
     - The existing validation for `total_time` runs as today.
     - **Additional check:** if the value is empty **or** zero,
       reject with the message "Tasks with sub-tasks must specify a
       non-zero total task time, or check 'Use sum of sub-task
       hours'."
     - Persist `inherit_subtask_hours = 0`.

### Read path

A single new helper:

```php
/**
 * Resolve the effective estimated hours for a task, honouring the
 * inherit-from-subtasks flag. Returns:
 *  - float|int  if a usable estimate exists
 *  - null       if there is no usable estimate
 *
 * The function reads a snapshot of the task's children and respects
 * the same "all siblings must have positive estimates" rule used by
 * the effort-weighted completion rollup, so the parent's *displayed*
 * estimate agrees with the *computed* completion.
 */
function resolveTaskEstimateHours(array $task, array $childTasks): ?float {
    if (!empty($task['inherit_subtask_hours'])) {
        $sum = 0.0;
        $hasPositive = false;
        foreach ($childTasks as $child) {
            $h = $child['estimated_hours'] ?? null;
            if ($h !== null && is_numeric($h) && (float)$h > 0) {
                $sum += (float)$h;
                $hasPositive = true;
            }
        }
        return $hasPositive ? round($sum, 4) : null;
    }
    $h = $task['estimated_hours'] ?? null;
    if ($h === null || !is_numeric($h) || (float)$h <= 0) {
        return null;
    }
    return round((float)$h, 4);
}
```

Callers:

- `task_detail.php` — replace the existing
  `task.estimated_hours` display with
  `resolveTaskEstimateHours($task, $subtasks)`. Add the
  `fa-link` icon and tooltip when the function's result is
  inherit-derived.
- `tasks.php` list — same.
- `client/dashboard.php` — same, but the existing client portal
  only renders `estimated_hours` for tasks; replace with the
  resolved value. The existing client display does not surface
  the inherit mode indicator to keep the read-only view uncluttered;
  the number alone must match the internal console.
- `calculateTaskGroupCompletion()` in
  `includes/functions.php` — when the parent is in inherit
  mode, the parent row's `estimated_hours` is logically `null`
  for the weighted rollup. The function already treats
  `null/<=0` as "fall back to equal average", which is the
  correct behaviour for inherit mode *only when* some child is
  also unestimated. The dev must **not** inject the resolved
  sum into the weighted sum silently, because the rollup
  formula in issue-007 already adds up the **children's**
  estimates directly — the parent's number must stay `null`
  to keep the formula unchanged.
- `project_stats` view (issue-007 SQL) — unchanged, for the
  same reason: it already iterates the children directly.

The net effect: the parent's `estimated_hours` column becomes a
**shadow** of the inherit flag. When `inherit_subtask_hours = 1`,
the column is forced `NULL` and never read. When the flag is `0`,
the column behaves exactly as it does today.

## Schema / API definition

### New column

```sql
ALTER TABLE tasks
  ADD COLUMN inherit_subtask_hours TINYINT(1) NULL DEFAULT NULL
  AFTER estimated_hours;
```

Semantics:

- `TINYINT(1) NULL` so the absence-of-decision is distinguishable
  from a literal "no, do not inherit". Existing rows default to
  `NULL`, which the application treats as "no" (manual mode) — the
  current behaviour.
- Independent of `estimated_hours`. The application forces the two
  to be consistent on save (inherit = `NULL`, manual = filled),
  but the schema does not enforce it.
- Independent of `parent_task_id`. The schema does not need to
  know that a task has children; the application enforces the
  "checkbox only on parents with children" rule on the way in.

### Migration

- New file:
  `database/migrations/20261006_add_task_inherit_subtask_hours.sql`.
- Idempotent, guarded with `information_schema.COLUMNS` the same
  way `20261005_add_task_estimated_hours.sql` is. No transaction
  wrapper (MariaDB DDL implicitly commits).
- `database/schema.sql`'s `CREATE TABLE tasks` block gets the new
  column so a fresh install does not need to run the migration.

### API surface

No new HTTP endpoints. The only "API" surface is the
`inherit_subtask_hours` field on the existing POST body of
`task_edit.php` and the `tasks.inherit_subtask_hours` column on
the `tasks` table. No AJAX, no JSON shape change.

## Validation & edge cases

| Case                                                                                         | Behavior                                                                                                                                            |
| -------------------------------------------------------------------------------------------- | --------------------------------------------------------------------------------------------------------------------------------------------------- |
| New task, no children yet                                                                    | Checkbox not shown. `Total Task Time` optional as today.                                                                                            |
| Existing task, no children, edit                                                              | Checkbox not shown. `Total Task Time` optional as today.                                                                                            |
| Existing task, ≥ 1 child, checkbox **off**, `total_time` empty                                | `400`-equivalent form error: "Tasks with sub-tasks must specify a non-zero total task time, or check 'Use sum of sub-task hours'."                |
| Existing task, ≥ 1 child, checkbox **off**, `total_time = 0`                                  | Same error (zero is not a usable weight per issue-006).                                                                                             |
| Existing task, ≥ 1 child, checkbox **off**, `total_time = 0.0001` (very small positive)       | Accepted; stored as `0.0001` hours.                                                                                                                |
| Existing task, ≥ 1 child, checkbox **off**, `total_time` non-numeric                          | Same error path as today.                                                                                                                          |
| Existing task, ≥ 1 child, checkbox **on**, `total_time` filled in the disabled field         | Field is `disabled`; browser does not submit it; server still defends by ignoring `total_time` whenever `inherit_subtask_hours === '1'`.           |
| Existing task, ≥ 1 child, checkbox **on**, all sub-task estimates `NULL`/zero                 | Inherit mode stays on. Display shows "Not estimated (no sub-task estimates)". Completion rollup uses equal-average fallback (issue-007 rule). |
| Existing task, ≥ 1 child, checkbox **on**, mixed sub-task estimates (some positive, some not) | Sum uses the positives only; the unmatched children still contribute to the completion rollup via the fallback rule (issue-007 unchanged).     |
| Existing task, ≥ 1 child, manager un-checks the checkbox and types a manual total             | The parent's `inherit_subtask_hours` is set to `0`, the parent's `estimated_hours` is set to the typed value, no recomputation occurs.          |
| Existing task, ≥ 1 child, manager checks the checkbox                                         | `inherit_subtask_hours = 1`, `estimated_hours = NULL` on the parent. The next read recomputes from the saved sub-tasks.                          |
| All sub-tasks deleted while parent is in inherit mode                                         | `inherit_subtask_hours` stays `1` on the row (audit), but the read path returns `null` (no children, no positive estimates). Display shows "Not estimated". The completion rollup on this parent falls back to the equal-average of zero sub-tasks (= 0%), which is the existing v1.2.0 behaviour. |
| Sub-task moved out from under a parent (theoretical — there is no reparent UI today)         | Treat as "all sub-tasks deleted" above.                                                                                                            |
| New sub-task added under a parent in inherit mode                                             | The next read picks up the new child's estimate and recomputes. No save is required on the parent.                                                |
| Race: two tabs both edit the same parent                                                      | Last write wins on the `inherit_subtask_hours` column. The completion rollup is read-time, so no recompute is needed.                             |
| Older client browser without `disabled` styling for `<select>`                               | The CSS in `assets/css/style.css` already styles `[disabled]` on form controls. Verify the new field uses the same `estimate-input` wrapper.       |
| Localization                                                                                 | Add EN + 中文 strings. The default is English; see Localization section.                                                                           |
| `csrf_token` missing on POST                                                                 | Existing CSRF check on `task_edit.php` already rejects (issue from v1.2.1). No change.                                                            |
| Migration re-run on a DB that already has the column                                          | No-op (idempotent).                                                                                                                                |
| `database/schema.sql` and migration out of sync                                               | The migration is the source of truth at runtime; the schema file is updated to match for fresh installs.                                          |

## Localization

Add the following keys to the existing internal-app translation
catalog (where `task_edit.php` labels live). Match the project's
convention — both English and 中文:

| Key                              | English                                                 | 中文                              |
| -------------------------------- | ------------------------------------------------------- | --------------------------------- |
| `task.inherit_subtask_label`     | `Use sum of sub-task hours as total task time`          | `使用子任务小时数之和作为总工时`   |
| `task.inherit_subtask_help`      | `When checked, the total below is locked to the sum of this task's sub-task estimates. Leave unchecked to enter a manual total.` | `勾选后,下方总工时将锁定为该任务所有子任务工时之和;不勾选则手动填写。` |
| `task.inherit_computed_total`    | `Computed total: %1$s hours (from %2$s sub-task(s) with positive estimates).` | `计算结果:%1$s 小时(来自 %2$s 个有正工时估计的子任务)。` |
| `task.inherit_no_positive`       | `None of the sub-tasks currently have a positive estimate. The parent's completion will use the equal-average fallback until at least one sub-task has a value.` | `当前子任务均无正工时估计。在至少一个子任务填入估计值之前,父任务的进度将回退为简单平均。` |
| `task.estimate_required_with_subs` | `Tasks with sub-tasks must specify a non-zero total task time, or check 'Use sum of sub-task hours'.` | `有子任务的任务必须填入非零的总工时,或勾选「使用子任务小时数之和作为总工时」。` |
| `task.inherit_tooltip`           | `Auto-calculated from sub-tasks`                        | `由子任务自动计算`               |
| `task.inherit_icon_alt`          | `Computed from sub-tasks`                               | `由子任务自动计算`               |

The existing `Total Task Time` label and its `field-help` text are
unchanged. The icon used is Font Awesome 6's `fa-link` (already in
the project bundle via the `font-awesome` CDN include on
`task_edit.php`).

## Acceptance criteria

- A new column `tasks.inherit_subtask_hours TINYINT(1) NULL` exists
  in the schema and is added by an idempotent migration
  (`database/migrations/20261006_add_task_inherit_subtask_hours.sql`)
  that is safe to re-run.
- `database/schema.sql`'s `CREATE TABLE tasks` block includes the
  new column so fresh installs do not need the migration.
- On the task edit form for a task with no sub-tasks (and on all
  create forms), the checkbox is not rendered. The `Total Task
  Time` field behaves exactly as today (optional, NULL allowed).
- On the task edit form for a task with at least one sub-task, the
  checkbox **is** rendered. Toggling it:
  - On → disables and grey-styles `total_time` and `total_time_unit`.
    The hint "Computed total: N hours (from M sub-task(s) with
    positive estimates)" is shown with the live sum.
  - Off → enables the field. Saving with the field empty or `0`
    surfaces the form error
    `"Tasks with sub-tasks must specify a non-zero total task time,
    or check 'Use sum of sub-task hours'."` and does not write.
- A successful save persists the column:
  - Checkbox on → `inherit_subtask_hours = 1`, `estimated_hours = NULL`.
  - Checkbox off → `inherit_subtask_hours = 0`, `estimated_hours` =
    the typed value (subject to the existing issue-006 validation).
- After save, reloading the edit form re-renders the same state:
  checkbox checked/unchecked, field enabled/disabled, hint visible
  or hidden. No flicker.
- `resolveTaskEstimateHours()` is the single helper that all four
  display surfaces (`task_detail.php`, `tasks.php`,
  `project_detail.php`, `client/dashboard.php`) use to compute the
  parent's `Total Task Time`. All four surfaces agree on the
  displayed number.
- A parent in inherit mode whose sub-tasks have a combined
  positive estimate displays the **same** number on the internal
  console and the client portal. A parent whose sub-tasks are all
  `NULL`/zero in inherit mode displays `Not estimated` in both
  places.
- The completion rollup is **not** silently changed for tasks that
  do not use inherit mode. The issue-007 weighted-or-equal-average
  behaviour is preserved for all existing rows. (Verified by
  regression test on a project whose parent's `estimated_hours`
  is set manually, no inherit flag.)
- A parent in inherit mode with sub-tasks whose total is e.g. 12
  hours: project completion rollup equals
  `Σ(child.estimated_hours × child.completion%) / Σ(child.estimated_hours)`,
  i.e. **unchanged** from issue-007 — the parent's saved `NULL`
  estimate does not enter the formula.
- A parent in inherit mode with sub-tasks all unestimated: project
  completion rollup falls back to the equal-average of children's
  completion percentages. Same as issue-007.
- A regression test
  `database/task_inherit_subtask_hours_test.php` asserts:
  - Creating a parent with `estimated_hours` and then adding
    sub-tasks does not change the parent.
  - Checking the box and saving clears the parent's
    `estimated_hours` to `NULL`.
  - Un-checking the box and saving restores a manual
    `estimated_hours`.
  - The `resolveTaskEstimateHours` helper returns the expected
    sum, `null` when all children are unestimated, and the manual
    value when the flag is off.
  - The validation rejects empty/zero values for a parent with
    children when the flag is off, and accepts them when the flag
    is on.
  - The `project_stats` view returns the same number before and
    after the migration on a representative seed (no silent
    regression for projects that never set the flag).
- The `task_detail.php` warning banner ("Inherit mode is on but
  none of the sub-tasks have an estimate") appears for the
  matching case and not otherwise.
- The client portal dashboard renders the same `Total Task Time`
  number as the internal console for a parent in inherit mode.

## Out of scope

- A "weighted sum" mode or any other third state between manual
  and inherit. The checkbox is binary.
- Server-pushed notifications when a sub-task's estimate changes
  and the parent is in inherit mode. The number updates silently
  on next read; that is the desired behaviour.
- Exporting the `inherit_subtask_hours` flag as a separate field
  in CSV / PDF reports. Reports that show `Total Task Time`
  should show the resolved value; the flag is a UI concern, not a
  reporting concern.
- Allowing the checkbox on a *sub-task* of a parent. A task that
  itself has a parent cannot itself use inherit mode (it has
  children, not a parent, to inherit from). The form hides the
  checkbox for any task whose `parent_task_id IS NOT NULL`.
- Re-parenting a sub-task under a different parent. The
  `inherit_subtask_hours` flag, if set, follows the *original*
  parent. The data model assumes sub-tasks are never reparented
  in the current app; this issue does not change that.
- A migration that back-fills `inherit_subtask_hours = 1` for any
  existing row. The flag is a fresh decision; old data stays
  manual (`NULL` → `0` semantics).

## Implementation notes for the developer

- The migration is a single `ALTER TABLE … ADD COLUMN` wrapped in
  an `information_schema.COLUMNS` guard. Follow the exact pattern
  in `database/migrations/20261005_add_task_estimated_hours.sql`.
- The `task_edit.php` POST handler is the single integration point
  for validation. The "checkbox only on parents with children"
  rule is enforced by counting children in the handler, not by
  trusting the form HTML.
- The form's `inherit_subtask_hours` value must be re-rendered
  on validation failure. The current `task_edit.php` preserves
  `$_POST` values via `$_POST['name'] ?? $task['name'] ?? ''`
  fallbacks; add a parallel
  `$_POST['inherit_subtask_hours'] ?? $task['inherit_subtask_hours'] ?? null`
  pattern so a failed save does not silently clear the flag.
- The `<select>` `disabled` attribute is enough for modern
  browsers, but a non-JS user agent may still submit the value.
  The server must defend by ignoring `total_time` when the flag
  is set, as documented in the Save path section.
- The `resolveTaskEstimateHours` helper goes in
  `includes/functions.php` next to
  `calculateTaskCompletionMap`. Add a short docblock
  referencing both this issue and issue-007 so the next developer
  understands why the helper exists.
- For the warning banner on `task_detail.php`, reuse the existing
  `.alert .alert-warning` style already used elsewhere in the
  app. Do not introduce a new colour.
- For the live "Computed total" hint, the value is computed
  server-side and rendered into the hint's `<span
  id="inherit-sum">`. A small inline `<script>` (consistent with
  the rest of `task_edit.php`) is **not** required because the
  hint is not interactive; the page reloads after a sub-task
  change. If a richer UX is desired later, it is a separate
  ticket.
- Mirror the regression test pattern used by
  `database/task_completion_test.php` — a single `.php` file that
  performs the assertions against a real DB, runnable via
  `docker compose exec app php database/task_inherit_subtask_hours_test.php`.
  No PHPUnit. No mocks. The test must clean up its rows
  afterwards.
- The new migration is dated `20261006_…` to fit the existing
  filename convention alongside the other v1.2.1 migrations
  (`20261006_add_project_start_date.sql`,
  `20261006_add_contacts_company_position_address.sql`).
- `task_detail.php` already has a sub-task loop; pull the
  `estimated_hours` column from each child and feed it to
  `resolveTaskEstimateHours()`. No new SQL.
- The completion rollup **does not change**. Issue a one-line
  note in `RELEASE.md` and `README.md` when the feature ships,
  but the formula in issue-007 is preserved verbatim. Anyone
  re-reading the rollup code should not have to discover a
  hidden special case.
