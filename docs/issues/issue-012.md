# Parent Task Status — Derived from Sub-Task Statuses

**Status:** Proposed
**Type:** Feature / Behavioural Change
**Priority:** Medium
**Depends on:** [issue-007](./issue-007.md) (parent `completion_percentage` is already derived from sub-tasks)
**Related:** [issue-011](./issue-011.md) (parent **effort** is derived via a checkbox on the same edit form; independent reviewable unit that shares the parent surface)
**Affects:** `task_edit.php`, `task_detail.php`, `tasks.php`, `project_detail.php`, `client/dashboard.php`, `includes/functions.php`, `ajax/complete_task.php`, new `ajax/parent_hold.php`, `ajax/update_task_progress.php`, `ajax/update_task_status.php`, `assets/js/subtask-manager.js`, `assets/css/style.css`

## Problem

Today the **completion percentage** of a parent task is derived from
its sub-tasks (issue-007 / `calculateTaskCompletionMap()` in
`includes/functions.php`). When a sub-task's percentage changes, the
parent's `completion_percentage` is re-written by
`syncProjectTaskCompletions($db, $projectId)`:

```php
// includes/functions.php
function syncProjectTaskCompletions($db, $projectId) {
    $tasks = $db->fetchAll(
        "SELECT id, parent_task_id, estimated_hours, completion_percentage
         FROM tasks WHERE project_id = ?",
        [$projectId]
    );
    $completionMap = calculateTaskCompletionMap($tasks);
    $parentIds = [];

    foreach ($tasks as $task) {
        if ($task['parent_task_id'] !== null) {
            $parentIds[(int)$task['parent_task_id']] = true;
        }
    }

    foreach (array_keys($parentIds) as $parentId) {
        $storedCompletion = round($completionMap['task_completion'][$parentId], 2);
        $db->execute(
            "UPDATE tasks SET completion_percentage = ?, updated_at = NOW()
             WHERE id = ? AND completion_percentage <> ?",
            [/* … */]
        );
    }

    return $completionMap;
}
```

The **status**, however, is fully independent. A parent can be
`not_started` while every one of its sub-tasks is `completed`. The
client portal's task list (`client/dashboard.php`) then renders:

```
[parent]   Not started        100%  ← inconsistent
   [child] Completed          100%
   [child] Completed          100%
```

Two surfaces disagree: the percentage says "done", the status badge
says "not started". The internal console has the same gap on
`task_detail.php` (the "Task Overview" card) and `tasks.php`
(parent cards in the list).

The user request, in the same message that produced issue-011, was
to make the parent's status follow its sub-tasks in the same way
the percentage already does. Issue-011 only covers the **effort**
side of the parent's `estimated_hours`; this issue covers the
**status** side.

The motivation: project managers track work through status, not
percentages. A stakeholder asking "is this workstream done?" reads
the badge, not the bar. A parent that says "not started" while its
sub-tasks all say "completed" is a lie that erodes trust in the
portal.

## Goals

- Make the **status** of a parent task (any task with
  `parent_task_id IS NULL` and at least one child, i.e. a task that
  *has* sub-tasks) follow the aggregate status of its sub-tasks,
  exactly the same way `completion_percentage` already does.
- Define a single, deterministic rule for the parent status from
  its sub-tasks and apply it everywhere a parent's status is
  displayed, sorted, or filtered.
- Keep the `tasks.status` column **stored** on the parent row (not
  virtual) so that:
  - the existing `status = ?` filters on `tasks.php` still work
    without a JOIN / sub-select;
  - sort orders like `ORDER BY status` continue to use the index
    (or no index — at least no full-table scan of sub-tasks);
  - exports, reports, and any other consumer of the column see a
    consistent value with no double-counting.
- Re-sync the parent `status` automatically on every write that
  changes a sub-task's status, completion percentage, or that
  adds / deletes a sub-task, by extending the existing
  `syncProjectTaskCompletions()` helper. No new entry points.
- Show a small **"Auto"** indicator next to the parent's status
  badge in the internal console so a manager knows the value is
  derived, not something they typed.
- On the task edit form for a parent with sub-tasks, render the
  `Status` select as **read-only** with an inline explanation. The
  manager does not need to (and cannot) override the parent status
  directly — the sub-tasks are the source of truth.
- On the task edit form for a sub-task (i.e. the user is editing a
  task whose `parent_task_id IS NOT NULL`), behaviour is unchanged.
  Sub-tasks remain independently editable. Only the **parent** of
  a sub-task tree is locked.
- Preserve `on_hold` as a **sticky, manual** state. A parent that
  is `on_hold` does not auto-resume when sub-tasks are added or
  changed. A manager must explicitly un-hold the parent, and the
  act of un-holding re-derives the status from the current
  sub-tasks. `on_hold` is documented as "manager-only" in the
  status dictionary; the user request explicitly does not call for
  it to be auto-set, and the existing v1.2.0 schema treats it as
  a manual signal anyway.
- Make no changes to **project** status. The `projects.status`
  column is independent and out of scope for this issue. (A
  future ticket could derive project status from top-level task
  status; that is a different surface and would also need a
  separate discussion with the PM because of the `share_code`
  exposure on the public client portal.)

## Non-goals

- Auto-deriving **project** status from task status. A separate
  ticket.
- Adding a fourth state (e.g. `partially_completed`) or any change
  to the existing `ENUM('not_started', 'in_progress', 'completed',
  'on_hold')`. The four-state vocabulary is unchanged.
- Auto-completing a sub-task from a parent action. The arrow goes
  one way: sub-tasks drive the parent, never the reverse.
- Cascading `on_hold` to sub-tasks. If the parent is put on hold,
  the sub-tasks are not affected. The parent's `on_hold` is a
  planning signal, not a sub-task freeze.
- A "soft override" mode where a manager can pin a parent's status
  to something the sub-tasks do not support. The parent's status
  is **always** derived when it has children; there is no override
  bit. The accepted override mechanism is the sub-tasks themselves.
- Changing the client portal's status display style. The
  `client/dashboard.php` already renders `tasks.status` with the
  same badge classes; it just sees a different (and now correct)
  value. No CSS or markup change in the portal.
- Re-emitting the full `tasks` row on every status change. The
  helper updates only the parent's `status`, `updated_at`, and (if
  applicable) `completion_date`. Other columns are untouched.

## Status derivation rule

Given a parent task with a non-empty set of sub-tasks, the parent's
status is the **highest non-on-hold sub-task status**, ranked:

```
not_started  <  in_progress  <  completed
```

…with `on_hold` treated as a sticky manual state and **excluded**
from the ranking entirely. Concretely:

```text
effective_parent_status(parent) =
    if parent.status == 'on_hold'      → 'on_hold'         (sticky)
    else if any(child.status ∈ {'in_progress', 'completed'}) for child in children(parent)
                                      → max(child.status)  by the ranking above
    else                              → 'not_started'     (default)
```

Equivalent algorithm (a single SQL aggregate, no PHP ranking):

```sql
SELECT
    CASE
        WHEN parent.status = 'on_hold' THEN 'on_hold'
        WHEN SUM(child.status = 'in_progress') > 0 THEN 'in_progress'
        WHEN SUM(child.status = 'completed')   > 0 THEN 'completed'
        ELSE 'not_started'
    END AS derived_status
FROM tasks parent
LEFT JOIN tasks child ON child.parent_task_id = parent.id
WHERE parent.id = :parent_id
GROUP BY parent.id, parent.status
```

Properties of this rule:

- **Idempotent** — applying it twice in a row yields the same value.
- **Forward-only** — the parent can only auto-promote
  `not_started → in_progress → completed`. It never auto-demotes,
  never auto-`on_hold`s, and never auto-resumes from `on_hold`.
- **One-direction** — sub-tasks can move freely in any direction
  (not_started ↔ in_progress ↔ completed, and to on_hold); the
  parent only ever goes up, except when the manager manually
  clears `on_hold`, at which point the parent's status re-derives
  from the current sub-tasks.
- **Empty children** — if a parent has zero sub-tasks at the
  moment of the call, the rule falls through to the `ELSE
  'not_started'` branch. (Parents with no sub-tasks never enter
  this code path; the helper is only called when
  `child_count >= 1`.)
- **Completion-date alignment** — when the parent transitions
  to `completed` for the first time, mirror the existing
  task_edit.php behaviour: set `completion_date` to NOW, and set
  `completion_percentage = 100`. When the parent transitions
  **away** from `completed` (e.g. a sub-task is moved back to
  `in_progress`), clear `completion_date` and set
  `completion_percentage` to the new derived percentage (the
  issue-007 weighted average, which is already computed in the
  same helper call). This matches the existing behaviour for the
  manual status dropdown on `task_edit.php`.

## UX

### `task_detail.php` — Task Overview card

The existing card already shows the badge:

```php
<span class="status status-<?= $task['status'] ?>">
    <?= ucfirst(str_replace('_', ' ', $task['status'])) ?>
</span>
```

Add a small **"Auto"** pill next to the badge when the task has at
least one sub-task:

```php
<?php if (!empty($subtasks)): ?>
    <span class="derived-pill" title="Status follows sub-tasks automatically">
        <i class="fas fa-magic"></i> Auto
    </span>
<?php endif; ?>
```

CSS (added to `assets/css/style.css`):

```css
.derived-pill {
    display: inline-flex;
    align-items: center;
    gap: 4px;
    font-size: 0.72rem;
    font-weight: 600;
    color: #6c757d;
    background: #f1f3f5;
    border: 1px solid #dee2e6;
    border-radius: 999px;
    padding: 2px 8px;
    margin-left: 6px;
    vertical-align: middle;
}
.derived-pill i { font-size: 0.7rem; }
```

The "Mark as Completed" quick-action button is already
conditional:

```php
<?php if ($task['status'] !== 'completed' && empty($subtasks)): ?>
    <button class="btn btn-success quick-complete-btn" … >…
<?php endif; ?>
```

…so it is hidden for parents with sub-tasks already. No change
required, but the conditional is now load-bearing and the
developer must not relax it. The "Edit Task" button still routes
to `task_edit.php`, where the status field is read-only (see
below).

### `task_edit.php` — Status field on a parent with sub-tasks

Today, the `Status` dropdown is a regular `<select>` with all four
options. For a parent that has at least one sub-task, the dropdown
becomes a **read-only display** with an inline explanation:

```html
<div class="form-group">
    <label for="status">Status:</label>
    <input type="text" id="status_display" name="status_display"
           value="<?= e(ucfirst(str_replace('_', ' ', $task['status']))) ?>"
           disabled aria-describedby="status_help">
    <input type="hidden" name="status" value="<?= e($task['status']) ?>">
    <p class="field-help" id="status_help">
        <i class="fas fa-magic"></i>
        This task has <?= count($subtasks) ?> sub-task(s), so its
        status follows the sub-tasks automatically:
        <strong>completed</strong> if all sub-tasks are completed,
        <strong>in progress</strong> if any are in progress, and
        <strong>not started</strong> otherwise. To put this parent
        on hold, use the “On Hold” action on the task detail page.
    </p>
</div>
```

For a sub-task (`parent_task_id IS NOT NULL`) or a parent with
**no** sub-tasks, the dropdown is unchanged.

> **Server-side defence:** even if the browser submits `status`
> in the form, the POST handler **ignores** any `status` value
> for a parent with children, except to recognise the literal
> string `'on_hold'` to clear or set the sticky flag (see On
> Hold below). This is the same pattern used in issue-011 for
> the disabled `total_time` field.

### On Hold (sticky manual state)

The "On Hold" action lives on the task detail page, not the edit
form. It is a single button under the existing page actions:

```html
<?php if ($task['parent_task_id'] === null && count($subtasks) > 0): ?>
    <?php if ($task['status'] !== 'on_hold'): ?>
        <form method="POST" action="ajax/parent_hold.php" class="inline-form">
            <input type="hidden" name="csrf_token" value="<?= e(getCsrfToken()) ?>">
            <input type="hidden" name="task_id" value="<?= (int)$task_id ?>">
            <input type="hidden" name="action" value="hold">
            <button class="btn btn-outline" type="submit"
                    onclick="return confirm('Put this parent task on hold? Sub-tasks are not affected.')">
                <i class="fas fa-pause"></i> Put on Hold
            </button>
        </form>
    <?php else: ?>
        <form method="POST" action="ajax/parent_hold.php" class="inline-form">
            <input type="hidden" name="csrf_token" value="<?= e(getCsrfToken()) ?>">
            <input type="hidden" name="task_id" value="<?= (int)$task_id ?>">
            <input type="hidden" name="action" value="resume">
            <button class="btn btn-success" type="submit">
                <i class="fas fa-play"></i> Resume
            </button>
        </form>
    <?php endif; ?>
<?php endif; ?>
```

The handler:

- **`action=hold`** — sets `tasks.status = 'on_hold'` for the
  parent only. Sub-tasks are untouched. CSRF + ownership checks
  identical to `task_edit.php`. After the write, calls
  `syncProjectTaskCompletions($db, $project_id)` (the helper that
  already exists) to recompute completion percentages in the tree.
- **`action=resume`** — sets `tasks.status` to the derived
  status from the current sub-tasks (per the rule above). Same
  helper for percentages.

This is the **only** path that changes a parent's `status` while
the parent has children. The dropdown on `task_edit.php` is
display-only; the hold/resume buttons are the manager's lever.

### `tasks.php` list

The list already shows a `.status.status-{value}` badge per card.
No markup change. The badges simply start reflecting the
sub-task-driven value. The `status` filter dropdown on `tasks.php`
filters by `t.status = ?` — that filter continues to work, and
now means "show me parents whose sub-tasks collectively look like
…", which is the intended behaviour.

### `project_detail.php` task table

The task table is unchanged. The `t.status` column it selects is
now the derived value for parents.

### `client/dashboard.php`

The client portal selects `t.status` in the existing flat task
query. The new derived value flows through automatically. The
client does not see the "Auto" pill — the badge colour is the
only thing the client reads, and it now agrees with the percentage
bar. **This is the primary user-visible win of the change.**

## Data flow

### Writes that must re-derive parent status

| Write                                                                                  | Path that already exists              | Where to add the re-derive call                            |
| -------------------------------------------------------------------------------------- | ------------------------------------- | ---------------------------------------------------------- |
| Edit a sub-task's status / completion / etc.                                           | `task_edit.php` POST                  | Already calls `syncProjectTaskCompletions()` after write    |
| Add a sub-task under a parent (insert into `tasks`)                                    | `task_edit.php` POST (subtask create) | Same                                                       |
| Delete a sub-task (`project_delete.php`-style; currently inline in `task_detail.php`)  | `task_detail.php` delete action       | Same                                                       |
| Quick-complete a sub-task (the existing AJAX endpoint)                                 | `ajax/complete_task.php`              | Add `syncProjectTaskCompletions()` call at the end         |
| Update a sub-task's progress / status from the inline subtask manager                  | `assets/js/subtask-manager.js` → `ajax/update_task_progress.php`, `ajax/update_task_status.php` | Add `syncProjectTaskCompletions()` call at the end of each |
| Sub-task status flipped programmatically (e.g. by a future automated workflow)        | any future endpoint                   | New writes must call `syncProjectTaskCompletions()`        |

The re-derive call already exists; this issue extends the helper,
not the call sites. **Every existing caller benefits with no
edits.** Future writers are documented to call the helper as a
side-effect of mutating a sub-task.

### Extension to `syncProjectTaskCompletions()`

```php
function syncProjectTaskCompletions($db, $projectId) {
    $tasks = $db->fetchAll(
        "SELECT id, parent_task_id, estimated_hours, completion_percentage
         FROM tasks WHERE project_id = ?",
        [$projectId]
    );
    $completionMap = calculateTaskCompletionMap($tasks);

    // NEW: gather sub-task status snapshots for each parent.
    $subtaskStatuses = [];
    foreach ($tasks as $task) {
        if ($task['parent_task_id'] !== null) {
            $subtaskStatuses[(int)$task['parent_task_id']][] = $task['status'] ?? 'not_started';
        }
    }

    // Pre-existing percentage re-write.
    foreach (array_keys($completionMap['task_completion']) as $taskId) {
        $parentId = $taskId;
        if (!isset($subtaskStatuses[$parentId])) {
            continue; // leaf or no sub-tasks; not a parent
        }
        $newCompletion = round($completionMap['task_completion'][$parentId], 2);
        $newStatus     = deriveParentStatus(
            $db->fetchOne("SELECT status FROM tasks WHERE id = ?", [$parentId])['status'] ?? null,
            $subtaskStatuses[$parentId]
        );
        $now = date('Y-m-d H:i:s');
        $completionDateClause = '';
        $params = [$newCompletion, $newStatus, $parentId, $newCompletion, $newStatus];
        if ($newStatus === 'completed') {
            $completionDateClause = ', completion_date = COALESCE(completion_date, ?)';
            $params[] = $now;
        } elseif ($newStatus !== 'completed') {
            $completionDateClause = ', completion_date = NULL';
        }
        $db->execute(
            "UPDATE tasks
             SET completion_percentage = ?, status = ?, updated_at = NOW()
                 $completionDateClause
             WHERE id = ?
               AND (completion_percentage <> ? OR status <> ?)",
            $params
        );
    }

    return $completionMap;
}
```

`deriveParentStatus()` is a small, pure helper:

```php
function deriveParentStatus(?string $currentStatus, array $childStatuses): string {
    if ($currentStatus === 'on_hold') {
        return 'on_hold';
    }
    foreach ($childStatuses as $s) {
        if ($s === 'in_progress') return 'in_progress';
    }
    foreach ($childStatuses as $s) {
        if ($s === 'completed') return 'completed';
    }
    return 'not_started';
}
```

Notes:

- The function is **deterministic** and unit-testable in
  isolation.
- It short-circuits on `on_hold` first, so a parent's sticky
  manual state is preserved across any number of sub-task
  changes, including the deletion of all sub-tasks (the parent
  remains `on_hold` until a manager resumes it).
- The SQL is idempotent — the `WHERE … AND (… OR …)` guard
  prevents a write when neither value would change, which keeps
  `updated_at` honest and avoids bumping parent rows on every
  unrelated sub-task edit.

### Sub-task writes

`task_edit.php`'s existing flow already calls
`syncProjectTaskCompletions($db, $project_id)` after every INSERT
or UPDATE. With the helper extended, **no change to
`task_edit.php` is required to ship this issue** other than:

1. Render the read-only status display for parents with children.
2. Ignore the `status` POST value for parents with children
   (server-side defence; the manager has the on-hold button on
   the detail page instead).

`ajax/complete_task.php` and the two `update_task_*` endpoints
called from `subtask-manager.js` need to add a single call to
`syncProjectTaskCompletions($db, $projectId)` at the end of their
happy path, after they have already updated the sub-task row. The
helper is the same one `task_edit.php` uses, so the call shape
is identical.

## Schema / API definition

### Schema

**No new columns, no new tables, no new indexes.** The
`tasks.status` `ENUM` is unchanged. The existing column is now
**derived-when-the-task-has-children** and **manual-when-it-does-not**,
which is enforced by the application.

### API

No new HTTP endpoints. The only new surface is:

- `ajax/parent_hold.php` — a new tiny endpoint that accepts
  `action=hold|resume` + `task_id` + CSRF token, validates
  ownership, and updates the parent's `status` directly. It is
  the **only** way to set a parent's status to `on_hold` (or to
  resume it), because `task_edit.php`'s status field is
  read-only for parents.
- `ajax/complete_task.php` and `ajax/update_task_*.php` gain one
  extra call (`syncProjectTaskCompletions()`) but their existing
  request/response shape is unchanged.

## Validation & edge cases

| Case                                                                                            | Behavior                                                                                                                                              |
| ----------------------------------------------------------------------------------------------- | ----------------------------------------------------------------------------------------------------------------------------------------------------- |
| New task, no children yet                                                                       | Manual status as today. Helper leaves parents with zero children untouched.                                                                          |
| Parent with one sub-task, sub-task is `not_started`                                              | Parent `not_started`.                                                                                                                                 |
| Parent with one sub-task, sub-task flips to `in_progress`                                        | Parent flips to `in_progress` on the next `syncProjectTaskCompletions()` call.                                                                       |
| Parent with one sub-task, sub-task flips to `completed`                                         | Parent flips to `completed`; `completion_date` is set to NOW if previously NULL.                                                                    |
| Parent with mixed sub-tasks (`completed` and `in_progress`)                                     | Parent `in_progress` (highest non-completed wins).                                                                                                    |
| Parent with all sub-tasks `completed`                                                           | Parent `completed`; `completion_date` set if NULL.                                                                                                    |
| Parent with one sub-task that is `on_hold`                                                      | Parent follows the rule: `on_hold` is ignored for the rank, so the other rules apply. (Sub-tasks can be on hold independently.)                       |
| Parent that is `on_hold`, sub-task added                                                        | Parent stays `on_hold`.                                                                                                                                |
| Parent that is `on_hold`, manager resumes via detail page button                                 | Parent re-derives from current sub-tasks.                                                                                                             |
| Parent that is `on_hold`, all sub-tasks deleted                                                 | Parent stays `on_hold` until manually resumed. The form helper re-derives on `resume`.                                                               |
| Sub-task edited via `task_edit.php`                                                             | `syncProjectTaskCompletions()` is already called; the helper now also updates parent status.                                                         |
| Sub-task edited via `ajax/complete_task.php`                                                    | Add `syncProjectTaskCompletions()` call at the end.                                                                                                   |
| Sub-task edited via `subtask-manager.js` (progress slider, status select)                       | Add `syncProjectTaskCompletions()` call at the end of the corresponding `ajax/update_task_*.php` handler.                                             |
| Sub-task deleted (currently inline on `task_detail.php`)                                        | The delete handler already calls `syncProjectTaskCompletions()` per the existing pattern.                                                            |
| Sub-task moved out from under a parent (theoretical — not in the current UI)                    | Treat as "all sub-tasks deleted" for the parent.                                                                                                      |
| Parent with one sub-task that is `completed` and the manager marks the parent `on_hold` via the new button | Parent `status` set to `on_hold`; the helper still re-derives percentages but the `WHERE … AND status <> ?` guard prevents the helper from re-promoting it. |
| Parent with children, manager types a value into the (read-only) `status` select via dev tools   | The POST handler reads `status` only to recognise the literal `'on_hold'` to flip the sticky bit. Any other value is ignored.                        |
| Parent with children, manager selects `on_hold` in the (read-only) select via dev tools          | Same — the read-only field is not a real `<select>` for parents; the POST never carries a value.                                                      |
| Concurrent edits: two tabs flip two different sub-tasks of the same parent around the same time  | Last writer wins per sub-task. The helper is called once per request, so the parent's status reflects the last sub-task the server saw. The next request re-derives again. No data loss, eventually consistent. |
| Parent's old stored status is `completed` but its sub-tasks are not all `completed` (pre-existing inconsistency) | On the next write that calls `syncProjectTaskCompletions()`, the helper **demotes** the parent. This is the documented "forward-only" rule with one explicit exception: demotion from `completed` is allowed when the data has drifted, to fix the bad data. Demotion from `on_hold` is **not** allowed (the sticky rule wins). |
| `tasks.status` filter on `tasks.php`                                                            | Continues to work, now means "show parents with derived status X". A filter for `on_hold` still surfaces only manually-held parents.                  |
| `client/dashboard.php` renders `t.status`                                                       | Continues to work; the badge colour now matches the percentage bar.                                                                                   |
| `project_stats` view (issue-007 SQL)                                                            | Unchanged. It does not include `status`; it counts completed tasks via `t.status = 'completed'`. The view stays consistent because parent completion matches its derived status. |
| `csrf_token` missing on the new `parent_hold.php` POST                                          | Rejected with `400 "Invalid form token."` (uses the existing `validateCsrfToken()` helper).                                                          |
| Project ownership check                                                                         | Same `JOIN projects p ON t.project_id = p.id WHERE p.responsible_person_id = ?` pattern as `task_edit.php`.                                            |
| Sub-task with children (currently the model allows arbitrary nesting)                           | The same rule cascades. A task whose own parent is also a parent will have its status re-derived when any descendant changes. The helper is called per project, so the cascade is one request, not N. |
| Migration / fresh install                                                                       | No migration. `database/schema.sql` and existing migrations are unchanged.                                                                            |
| New ENUM value needed in the future (e.g. `cancelled`)                                         | Out of scope. The ENUM is unchanged in this issue.                                                                                                    |

## Localization

Add the following keys to the existing internal-app translation
catalog (where `task_edit.php` / `task_detail.php` live). Match
the project's EN + 中文 pattern:

| Key                              | English                                                                                                  | 中文                                                                                                |
| -------------------------------- | -------------------------------------------------------------------------------------------------------- | --------------------------------------------------------------------------------------------------- |
| `task.status_auto_pill`          | `Auto`                                                                                                   | `自动`                                                                                              |
| `task.status_auto_pill_title`    | `Status follows sub-tasks automatically`                                                                 | `状态自动根据子任务计算`                                                                            |
| `task.status_locked_help`        | `This task has %1$d sub-task(s), so its status follows the sub-tasks automatically: completed if all sub-tasks are completed, in progress if any are in progress, and not started otherwise. To put this parent on hold, use the “On Hold” action on the task detail page.` | `此任务包含 %1$d 个子任务,因此其状态将自动根据子任务计算:所有子任务已完成则为"已完成",任一子任务进行中则为"进行中",否则为"未开始"。如需将父任务置为"已暂停",请在任务详情页使用"挂起"按钮。` |
| `task.hold_button`               | `Put on Hold`                                                                                            | `挂起`                                                                                              |
| `task.resume_button`             | `Resume`                                                                                                 | `恢复`                                                                                              |
| `task.hold_confirm`              | `Put this parent task on hold? Sub-tasks are not affected.`                                              | `确定将此父任务挂起吗?子任务不会受到影响。`                                                          |
| `task.hold_invalid_csrf`         | `Invalid form token. Please reload the page and try again.`                                              | `表单令牌无效,请刷新页面后重试。`                                                                   |
| `task.hold_unauthorized`         | `You are not allowed to change the hold state of this task.`                                             | `您没有权限修改此任务的挂起状态。`                                                                  |

The existing status labels (`status.not_started`, `status.in_progress`,
`status.completed`, `status.on_hold`) in `client/lang.php` and the
internal app's translation table are unchanged.

## Acceptance criteria

- A parent task with at least one sub-task has its `status`
  **derived** from its sub-tasks per the rule in this issue. The
  derivation is performed by the extended
  `syncProjectTaskCompletions()` helper, which is called from
  every existing sub-task write site without any per-site code
  change beyond the new call in the two AJAX endpoints.
- The derivation rule is exactly:
  - `on_hold` (parent only) is sticky and wins.
  - Otherwise, `in_progress` if any child is `in_progress`.
  - Otherwise, `completed` if any child is `completed`.
  - Otherwise, `not_started`.
- A parent with no sub-tasks (or a sub-task) is **never** touched
  by the helper. The status field remains free-form editable on
  the edit form, exactly as today.
- On `task_detail.php`, a parent with sub-tasks shows an `Auto`
  pill next to the status badge. A sub-task, or a parent with no
  sub-tasks, does not.
- On `task_edit.php`, a parent with sub-tasks renders the `Status`
  field as a read-only display with the inline explanation. The
  manager can no longer pick a status from a dropdown for a
  parent that has sub-tasks. The server ignores any `status`
  value the manager (or a misbehaving client) tries to submit.
- A new `ajax/parent_hold.php` endpoint accepts
  `action=hold|resume` + `task_id` + CSRF and:
  - Updates the parent's `status` accordingly.
  - Calls `syncProjectTaskCompletions()` afterwards so the
    percentage and any cascading sub-tree statuses stay in sync.
  - Returns a `4xx` on missing CSRF, missing task, wrong owner,
    or a non-parent target.
  - Logs nothing on success (no new error_log noise).
- The two existing AJAX sub-task write endpoints
  (`ajax/complete_task.php` and the two `ajax/update_task_*.php`
  used by `subtask-manager.js`) call
  `syncProjectTaskCompletions()` after their happy-path write.
- A parent in `on_hold` that has sub-tasks added, edited, or
  deleted stays `on_hold` until the manager explicitly resumes
  it. The helper's `on_hold` short-circuit makes this
  unconditional.
- A parent in `on_hold` whose sub-tasks are all deleted stays
  `on_hold`. (The helper still finds zero sub-tasks, but the
  short-circuit applies first.)
- A parent whose sub-tasks are all `completed` shows
  `status = completed` and a `completion_date` of NOW on the
  next `syncProjectTaskCompletions()` call. If the
  `completion_date` is already set, it is **not** overwritten.
- A parent that is `completed` whose sub-tasks subsequently
  include an `in_progress` (i.e. the manager re-opens a
  sub-task) is demoted back to `in_progress` and its
  `completion_date` is cleared.
- A parent that is `completed` whose sub-tasks are all deleted
  is demoted back to `not_started` and its `completion_date`
  is cleared. (This is the "fix bad data" path; it only runs
  when the helper is called for a reason, not as a background
  job.)
- The `client/dashboard.php` badge and percentage bar agree for
  every parent task. A task cannot show "100% / Not started".
- A regression test
  `database/task_parent_status_derived_test.php` asserts:
  - `deriveParentStatus()` is correct for all 4⁴ combinations of
    parent + child statuses (with the `on_hold` short-circuit).
  - A parent with `status = 'in_progress'` and all sub-tasks
    `not_started` is corrected to `not_started` on the next
    helper call.
  - A parent with `status = 'completed'` and a sub-task flipped
    to `in_progress` is demoted to `in_progress` and its
    `completion_date` cleared.
  - A parent in `on_hold` is **not** demoted when its sub-tasks
    change.
  - The `client/dashboard.php` rendered HTML for a seed project
    has matching `status-` class and `progress-fill` width for
    every parent.
  - The `ajax/parent_hold.php` endpoint accepts `hold` and
    `resume` for an authorized manager, rejects with `400` on
    missing CSRF, and rejects with `403` on a task the user
    does not own.

## Out of scope

- Auto-deriving **project** status from task status. The
  `projects.status` column is independent; that is a separate
  ticket and would also need a PM discussion because of the
  client portal's `share_code` exposure.
- Cascading `on_hold` to sub-tasks. The sticky state is a
  planning signal on the parent, not a freeze on the sub-tasks.
- A "soft override" mode. The parent's status is always derived
  when it has children.
- An "Auto-pause" rule that puts a parent on hold when all
  sub-tasks are blocked. Out of scope; would need its own
  product discussion.
- Re-emitting the full `tasks` row on every status change. The
  helper updates only `status`, `completion_percentage`,
  `completion_date` (when applicable), and `updated_at`.
- Re-rendering the task detail page client-side on every AJAX
  status change. The existing `subtask-manager.js` re-fetches
  affected partials; that flow is unchanged.
- Any change to the `tasks.status` `ENUM` definition.

## Implementation notes for the developer

- Extend `includes/functions.php`. Add `deriveParentStatus()` and
  the new branch inside `syncProjectTaskCompletions()`. Do not
  duplicate the helper in a class or service container; this
  project is procedural per-page, and the function call shape
  must remain `$db` as the first argument for the existing
  callers to keep working.
- The new branch is **gated on the parent having children**:
  `if (!isset($subtaskStatuses[$parentId])) continue;`. Without
  that guard, leaf tasks (which never need their status
  overwritten) would have their `status` field re-asserted on
  every call, including when the manager is editing them through
  the edit form.
- Use a single `UPDATE` per parent with a `WHERE … AND
  (completion_percentage <> ? OR status <> ?)` guard. The guard
  is what keeps `updated_at` from bumping on no-op redraws.
- `ajax/parent_hold.php` is a new file in `ajax/`. It must
  follow the existing patterns in that directory: `requireLogin`,
  CSRF check, project ownership JOIN, narrow `UPDATE … WHERE id
  = ? AND project_id IN (SELECT id FROM projects WHERE
  responsible_person_id = ?)`. Mirror `ajax/complete_task.php`'s
  shape so a code reviewer can spot drift quickly.
- The new endpoint must call `syncProjectTaskCompletions($db,
  $projectId)` at the end of its happy path. Without that call,
  a `hold` action would leave the parent's `completion_percentage`
  unchanged (which is fine for hold, but for `resume` the
  percentage would be stale until the next sub-task edit). Doing
  it in both cases is the safe default.
- `task_edit.php`'s server-side status defence is one line in
  the POST handler:

  ```php
  // After $errors[] validation, before $db->execute():
  if ($is_editing && $task['parent_task_id'] === null) {
      $childCount = (int)$db->fetchOne(
          "SELECT COUNT(*) AS c FROM tasks WHERE parent_task_id = ?",
          [$task_id]
      )['c'];
      if ($childCount > 0) {
          // Parent with children: ignore the submitted status.
          // The on-hold / resume button is the only path.
          $status = $task['status'];
      }
  }
  ```

  Place this **after** the existing `if ($status === 'completed')`
  logic so the auto-100% / auto-completion_date behaviour is
  preserved for sub-tasks and parents-without-children.

- The CSS for `.derived-pill` is a single small block in
  `assets/css/style.css`. Match the existing `.status-badge` /
  `.task-status-card` colour palette; do not introduce a new
  palette.
- Do not change `client/dashboard.php`'s query. The derived
  status flows through automatically. The only file in `client/`
  that should change at all is (optionally) `client/lang.php`
  if the client portal wants to localise the new `Auto` pill
  — but the client portal does not show the pill, so no change
  is required there. Keep this issue's blast radius inside the
  internal console.
- The regression test follows the existing
  `database/*_test.php` pattern (PHP script, runnable via
  `docker compose exec app php database/…`, no PHPUnit). The
  test must clean up its rows.
- Cross-link this issue from [issue-011](./issue-011.md)'s
  "Out of scope" list and from the existing `task_detail.php`
  source comment block ("progress calculated from N subtasks")
  once shipped, so the next developer looking at the helper can
  see both pieces.

## Cross-references

- [issue-007](./issue-007.md) — effort-weighted completion; the
  parent's `completion_percentage` is already derived from
  sub-tasks. This issue extends the same helper to derive
  `status` as well.
- [issue-011](./issue-011.md) — parent's `estimated_hours` is
  derived (or manually overridden) using a checkbox on the same
  edit form. The two issues are independent reviewable units
  but share the same parent surface (`task_edit.php`).
