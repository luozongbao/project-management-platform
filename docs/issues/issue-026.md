# Align Parent Status With Parent Completion Percentage

**Status:** Proposed
**Type:** Bug / Behavioural Correction
**Priority:** Medium
**Target Release:** v1.7.1.1
**Affects:** `includes/functions.php` (`syncProjectTaskCompletions()`, new helper `deriveParentStatusFromCompletion()`), `database/task_parent_status_derived_test.php` (fixtures + 2 live-DB assertions), `task_detail.php` / `tasks.php` / `project_detail.php` / `client/dashboard.php` (no markup change — they read `tasks.status` after the rewrite)

## Problem

[issue-025](./issue-025.md) (v1.7.1) tightened `deriveParentStatus()`
so that a parent is `completed` only when **every** sub-task is
`completed`. That fixed three real mis-classifications
(`['completed', 'not_started']`, `['completed', 'on_hold']`,
`['completed', 'completed', 'not_started']` all flipping to
`not_started`).

A second, related drift remains: **`deriveParentStatus()` is
driven by the child `status` fields, but the parent
`completion_percentage` is driven by the child
`completion_percentage` fields.** The two are recomputed by
different inputs and they can disagree. A real screenshot of the
bug:

```
Project:  Yadepan Website
Task:     UAT2   (3 sub-tasks)
  ┌─────────────────────────────────────────────────────┐
  │ Status:      Not started     [Auto]                 │
  │ Overall Progress:                      1.6%        │
  │ Progress calculated from 3 subtasks                 │
  └─────────────────────────────────────────────────────┘
```

The parent's "Overall Progress" reads **1.6%** (something
contributed a small amount of work from the sub-tasks) but the
status badge says **Not started**. The two numbers disagree.
This is exactly the drift the user is calling out:

> "my task UAT2 already have a progress from a subtask but the
> status is still 'Not Started' … we need to also make the
> progress and the status align.
>
> If status = 0 then: not started
> If status > 0 < 100 then: in progress
> If status = 100 then: completed"

The root cause: `deriveParentStatus()` reads
`subtaskStatuses[parent][] = $task['status']`, but a leaf sub-task
can legitimately have `status = 'not_started'` and
`completion_percentage = 5` — e.g. a manager has dragged the
progress slider on a sub-task but has not yet flipped the
sub-task's status from `not_started` to `in_progress`. The leaf
status is the user's manual signal; the leaf percentage is a
slider value. They are not auto-aligned, and a manager can
move one without moving the other.

Once the sub-task's percentage is `> 0`, the parent's
`completion_percentage` (computed by
`calculateTaskCompletionMap()`) becomes `> 0`. But the parent's
`status` (computed by `deriveParentStatus()` from the
`status` fields) stays at `not_started`. The two values now
disagree on the parent's surface.

`database/task_parent_status_derived_test.php:131-141` already
asserts the strict `all-completed` rule from issue-025. The
**screenshot above is not a fixture failure** — it is a
fixture gap. The pure-function tests check
`deriveParentStatus(null, ['completed', 'completed', 'completed'])`
returns `completed`, but they do not check what happens when
the children have a mixed `status` vs. `completion_percentage`.
The current code is "correct" against the fixtures; it is
"wrong" against the user's mental model.

The fix is to make the parent's `status` derive from the same
**computed parent `completion_percentage`** that the
percentage already comes from — i.e. from a single source of
truth. The user gave the three rules:

| Parent `completion_percentage` | Parent `status` (v1.7.1.1) |
| ------------------------------ | -------------------------- |
| `0`                            | `not_started`              |
| `> 0` and `< 100`              | `in_progress`              |
| `100` (or `>= 99.5` rounded)   | `completed`                |
| (any) — current parent is `on_hold` | `on_hold` (sticky)     |

This keeps the strict `all-completed` rule from issue-025 (a
parent at `100%` means all sub-tasks contributed to the
percentage, and the calculation only reaches 100% when
`allHavePositiveEstimates && totalHours > 0` returns a value
of 100, which in turn requires every contributing child to be
at `100%`, which in turn requires every child to be
`completed` for the manager to have set the slider that
high) — see "Why the issue-025 invariant is preserved" below.

## Goals

- Make the parent's `status` derive from the **same computed
  parent `completion_percentage`** that the percentage is
  already derived from. The new rule:
  - `on_hold` (current parent status) is sticky: it wins
    regardless of percentage (unchanged from issue-025).
  - Otherwise `completed` if the parent
    `completion_percentage` is `>= 99.5` (the same
    `round(x, 2)` granularity `syncProjectTaskCompletions()`
    already uses; see [includes/functions.php:296](includes/functions.php#L296)).
  - Otherwise `in_progress` if the parent
    `completion_percentage` is `> 0` and `< 99.5`.
  - Otherwise `not_started`.
- Keep the issue-025 strict `all-completed` rule intact. The
  strict rule is a **separate invariant** (what makes a leaf
  eligible for completion) and is enforced on the leaf's
  `status` field, not on the parent's derivation. The
  v1.7.1.1 change moves only the **derivation source** for
  the parent; the rule for "which leaf statuses count as
  done" does not change.
- Keep the `on_hold` stickiness.
- Keep the `completion_date` alignment
  (`COALESCE(completion_date, NOW())` on promotion to
  `completed`, `completion_date = NULL` on demotion away from
  `completed`) — see
  [includes/functions.php:300-306](includes/functions.php#L300-L306).
  That alignment is the same: promotion to `completed`
  happens iff the parent reaches `>= 99.5%`; demotion away
  from `completed` happens iff the parent falls below
  `99.5%`.
- Ship a new pure helper
  `deriveParentStatusFromCompletion(?string $currentStatus, float $percentage): string`
  in `includes/functions.php` and have
  `syncProjectTaskCompletions()` call **it** instead of
  `deriveParentStatus()`. The old
  `deriveParentStatus()` is retained for the `resume`
  branch in `ajax/parent_hold.php` (which still wants the
  child-status-based rule so that "resume after hold"
  produces a sensible result even when the children have
  not been re-saved) and for the pure-function test
  fixtures that codify issue-025. **Both helpers live
  side-by-side** — the resume branch keeps
  `deriveParentStatus()` because the user may resume a
  parent whose children have not been touched since
  the hold, in which case the strict `all-completed`
  rule still applies, and the resume value is the
  correct strict result.
- Update the regression test
  `database/task_parent_status_derived_test.php` to
  add a layer 1 fixture set for the new helper, update
  the layer-2 live-DB assertion to expect
  `in_progress` (not `not_started`) when a parent's
  percentage is `> 0` from a single child's
  non-completed contribution, and add a layer-5
  "screenshot regression" that reproduces the UAT2
  scenario (parent with 3 sub-tasks, one sub-task at
  `5%` and `not_started`; expect parent
  `completion_percentage = 1.6%` and parent `status =
  in_progress`).
- Make the change backwards-incompatible in the
  safe direction: a parent that was previously
  `not_started` (because all children were
  `not_started` and the percentage was `0`) becomes
  `in_progress` the moment any child's percentage goes
  `> 0`. This is the **intended** behaviour and matches
  the user's spec verbatim.

## Non-goals

- Auto-aligning a leaf sub-task's `status` from its
  `completion_percentage`. The user did not ask for this;
  the leaf status remains the manager's manual signal. The
  fix is on the **parent**'s derivation, not on the
  leaf's status.
- Changing the issue-025 strict `all-completed` rule.
  The rule is preserved; only the derivation source
  changes (from "child status mix" to "computed parent
  percentage").
- Changing the `completion_date` alignment logic. The
  promotion / demotion already keys on `status`; the new
  derivation still produces a `status` value, so the
  existing `completion_date` block in
  `syncProjectTaskCompletions()` works unchanged.
- Changing `calculateTaskCompletionMap()`. The
  percentage calculation is already authoritative;
  the new helper reads from its result.
- Changing the leaf's quick-complete path
  (`ajax/complete_task.php`) or the leaf's status
  update path (`ajax/update_task_status.php`). Both
  continue to write the leaf's own `status` directly
  and then call `syncProjectTaskCompletions()`, which
  now uses the new helper for the parent.
- Changing the `resume` branch in
  `ajax/parent_hold.php`. The resume path keeps the
  old `deriveParentStatus()` helper because it is
  deriving a status from the children directly (no
  percentage is in scope). The new helper is only
  for the parent row that
  `syncProjectTaskCompletions()` writes.
- Adding a new `partially_completed` /
  `mixed_completion` status. The four-value enum
  (`not_started`, `in_progress`, `completed`,
  `on_hold`) is sufficient.
- Changing the schema, AJAX endpoints, JS, CSS, or
  translations.
- A back-fill / migration script. The new derivation
  runs on the next `syncProjectTaskCompletions()`
  call (every sub-task edit, hold, resume, complete,
  progress update), which is fired on the next user
  interaction. No separate back-fill is required.

## UX

The fix is invisible to most users. The only difference is
the parent task's status badge when the parent's computed
percentage is `> 0` and the children have a mixed
`status` (e.g. one child at `5%` and `not_started` while
the rest are untouched). For a parent that was wrongly
classified as `not_started` under the old child-status-
based rule, the badge changes the next time any
sub-task is updated, the parent is re-loaded, or the
user navigates back to the page. There is no user-
facing toast, modal, or migration message. The change
is documented in `RELEASE.md` under the v1.7.1.1 entry
and in the version-history sidebar on the project.

A "Demo / why it matters" pair (before / after) is
captured in the regression test
`database/task_parent_status_derived_test.php` layer 5
("screenshot regression"). The test creates the
exact UAT2 shape from the bug report — a parent with
three sub-tasks, one sub-task at `5%` and
`not_started` — and asserts the parent is at `1.6%`
and `in_progress`.

### Why the issue-025 invariant is preserved

The strict `all-completed` rule from issue-025 says: a parent
is `completed` only when every sub-task is `completed`. The
v1.7.1.1 change says: a parent is `completed` only when the
parent's `completion_percentage >= 99.5`. The two are
**equivalent** for the parent, because:

1. `calculateTaskCompletionMap()` returns the parent's
   `completion_percentage` as the weighted average of its
   children's `completion_percentage` values
   (`calculateTaskGroupCompletion()`, lines 184–217).
2. The weighted average is exactly `100%` iff every
   contributing child is exactly `100%`.
3. A child is at `100%` in the `tasks` table only when
   the manager has set its `completion_percentage` to
   `100` — and the only code path that sets a child's
   `completion_percentage` to `100` is the
   `quick-complete-btn` flow
   (`ajax/complete_task.php:71-80`) or the
   `status = 'completed'` branch in
   `ajax/update_task_status.php:51-53`, both of which
   also set `status = 'completed'`. (A manager could
   drag the slider to `100%` and leave the status as
   `not_started`, but that is a manual override and
   the user is signalling "treat this as 100% done"
   either way; the v1.7.1.1 fix handles that
   correctly: parent's percentage is `100` → parent
   status is `completed`.)

So a parent at `>= 99.5%` necessarily has every
contributing child at `100%`, which necessarily means every
contributing child is `status = 'completed'`. The strict
`all-completed` invariant from issue-025 still holds under
the new rule. The v1.7.1.1 change is a **rephrasing** of
the same invariant in terms of the computed percentage,
not a relaxation.

The case the user is fixing is the **complement**:
a parent at `> 0%` and `< 100%` necessarily has at
least one child with `completion_percentage > 0`,
which means **some work has started**. The
child-status-based rule could still classify that
parent as `not_started` if all the children's
`status` fields are still `not_started` (because the
manager dragged the slider but did not flip the
status). The v1.7.1.1 fix re-classifies such a
parent as `in_progress`, which matches the user's
spec and matches the project overview's reading of
"this work has begun".

## Implementation

### `includes/functions.php` — new helper

Add a new pure helper next to `deriveParentStatus()`:

```php
/**
 * Derive a parent task's status from its computed
 * completion percentage (issue-026).
 *
 * This is the v1.7.1.1 successor to deriveParentStatus() for
 * the parent-derivation path inside syncProjectTaskCompletions().
 * It is driven by the parent completion_percentage (already
 * computed by calculateTaskCompletionMap()), so the parent
 * percentage and the parent status share a single source of
 * truth.
 *
 * Rule:
 *   - `on_hold` (parent) is sticky manual: it wins
 *     regardless of percentage.
 *   - Otherwise `completed` if percentage >= 99.5.
 *   - Otherwise `in_progress` if percentage > 0.
 *   - Otherwise `not_started`.
 *
 * The 99.5 threshold matches the round(x, 2) granularity
 * syncProjectTaskCompletions() uses when persisting the
 * parent completion_percentage, so a parent that is stored
 * as 100.00 (after rounding) always classifies as completed.
 *
 * Cross-reference: docs/issues/issue-025.md, docs/issues/issue-026.md.
 */
function deriveParentStatusFromCompletion(?string $currentStatus, float $percentage): string {
    if ($currentStatus === 'on_hold') {
        return 'on_hold';
    }
    if ($percentage >= 99.5) {
        return 'completed';
    }
    if ($percentage > 0) {
        return 'in_progress';
    }
    return 'not_started';
}
```

The new helper is **pure** (no DB, no IO) and
**deterministic** (same input → same output), so it is
unit-testable in the same way as
`deriveParentStatus()`.

The `deriveParentStatus()` helper is **kept** —
`ajax/parent_hold.php`'s `resume` branch still calls it,
and the pure-function fixtures for issue-025 still
exercise it. The two helpers are siblings, not
replacements.

### `includes/functions.php` — `syncProjectTaskCompletions()` change

Replace the line that calls `deriveParentStatus()` (line
299 in the current source) with the new helper:

```php
// Before (v1.7.0 / v1.7.1):
$newStatus = deriveParentStatus($currentStatus, $subtaskStatuses[$parentId] ?? []);

// After (v1.7.1.1):
$newStatus = deriveParentStatusFromCompletion($currentStatus, (float)$storedCompletion);
```

The rest of the function (the
`$setCompletionDate` block, the `WHERE … AND
(completion_percentage <> ? OR status <> ?)` guard) is
unchanged. The new `$newStatus` value is derived from
the **same** `$storedCompletion` that is being written
to `tasks.completion_percentage`, so the two values
agree by construction.

### `ajax/parent_hold.php` — unchanged

The `resume` branch keeps
`deriveParentStatus(null, $child_statuses)`. The
`hold` branch keeps `UPDATE tasks SET status =
'on_hold'`. Neither branch uses the new helper
because the resume value is computed from the
children directly (no percentage is in scope — the
held parent's percentage is stale and we do not want
to trust it for the resume decision) and the hold
value is always `on_hold`. The
`syncProjectTaskCompletions($db, $project_id)` call
at the end of the endpoint now uses the new helper
to re-derive the parent's status from the recomputed
percentage, which is the right behaviour: after a
hold, the percentage is unchanged, so the parent
stays at whatever the percentage says (which may
be `completed` or `in_progress` under the new rule,
or `on_hold` because the stickiness is enforced in
the helper).

### No other code changes

- `task_edit.php`, `task_detail.php`, `tasks.php`,
  `project_detail.php`, `client/dashboard.php` —
  unchanged. They all read `tasks.status` and
  render the badge. The new value flows through
  automatically.
- `assets/css/style.css` — unchanged. The
  `status-in_progress`, `status-completed`,
  `status-not_started`, `status-on_hold` rules are
  already in place.
- No schema change. No new column. No new
  migration. The fix is a single PHP function
  body change plus a new helper.

### `database/task_parent_status_derived_test.php` — fixtures

Add a new layer 1 pure-function test block for
`deriveParentStatusFromCompletion()`:

```php
// ---------------------------------------------------------------------------
// 1b. Pure-function tests for deriveParentStatusFromCompletion() (issue-026).
// ---------------------------------------------------------------------------
$completion_tests = [
    'not_started: 0%' => [
        null, 0.0, 'not_started',
    ],
    'not_started: 0% (rounded to 0.00)' => [
        null, 0.004999, 'not_started',
    ],
    'in_progress: 1.6% (the UAT2 case)' => [
        null, 1.6, 'in_progress',
    ],
    'in_progress: 50%' => [
        null, 50.0, 'in_progress',
    ],
    'in_progress: 99.49% (just below threshold)' => [
        null, 99.49, 'in_progress',
    ],
    'completed: 99.5% (the threshold)' => [
        null, 99.5, 'completed',
    ],
    'completed: 100%' => [
        null, 100.0, 'completed',
    ],
    'completed: 99.6%' => [
        null, 99.6, 'completed',
    ],
    'on_hold: 0% (sticky even at zero)' => [
        'on_hold', 0.0, 'on_hold',
    ],
    'on_hold: 50% (sticky regardless of percentage)' => [
        'on_hold', 50.0, 'on_hold',
    ],
    'on_hold: 100% (sticky even at completion)' => [
        'on_hold', 100.0, 'on_hold',
    ],
    'in_progress: tiny positive (0.0001)' => [
        null, 0.0001, 'in_progress',
    ],
];

foreach ($completion_tests as $name => [$current, $percentage, $expected]) {
    $actual = deriveParentStatusFromCompletion($current, $percentage);
    if ($actual !== $expected) {
        $failures[] = "deriveParentStatusFromCompletion: '$name' expected '$expected', got '$actual'";
    }
}
```

Update the existing layer-2 live-DB assertion
("2a. All children not_started → parent not_started")
to also assert the percentage case:

```php
// 2a. All children not_started → parent not_started.
$parent_id = $create('issue012 parent A', null, 'not_started');
$a1 = $create('issue012 sub A1', $parent_id, 'not_started');
$a2 = $create('issue012 sub A2', $parent_id, 'not_started');
syncProjectTaskCompletions($db, $project_id);
$row = $db->fetchOne('SELECT status, completion_percentage, completion_date FROM tasks WHERE id = ?', [$parent_id]);
if ($row['status'] !== 'not_started') {
    $failures[] = "2a: parent status should be 'not_started', got '{$row['status']}'";
}
if ((float)$row['completion_percentage'] !== 0.0) {
    $failures[] = "2a: parent completion_percentage should be 0, got '{$row['completion_percentage']}'";
}
```

Add a new layer 2 sub-test for the percentage
case (the UAT2 regression):

```php
// 2g. Issue-026: parent with one child at 5% / not_started →
//      parent percentage 5%, parent status in_progress.
//      Reproduces the UAT2 screenshot regression.
$parent_g = $create('issue026 parent G', null, 'not_started');
$g1 = $create('issue026 sub G1', $parent_g, 'not_started');
$g2 = $create('issue026 sub G2', $parent_g, 'not_started');
$g3 = $create('issue026 sub G3', $parent_g, 'not_started');
$db->execute("UPDATE tasks SET completion_percentage = 5 WHERE id = ?", [$g1]);
syncProjectTaskCompletions($db, $project_id);
$row = $db->fetchOne('SELECT status, completion_percentage FROM tasks WHERE id = ?', [$parent_g]);
if ($row['status'] !== 'in_progress') {
    $failures[] = "2g (UAT2 regression): parent status should be 'in_progress' (5% > 0), got '{$row['status']}'";
}
if ((float)$row['completion_percentage'] != 5.0) {
    $failures[] = "2g (UAT2 regression): parent completion_percentage should be 5.0 (equal split of 1 of 3 children at 5%), got '{$row['completion_percentage']}'";
}
```

(The exact percentage value depends on the equal-
average fallback because the children have no
`estimated_hours`. The fixture uses three children
at 0% / 0% / 5%; equal-average fallback returns
`5.0 / 3 = 1.666…%` which rounds to `1.67`.
Adjust the assertion to match. The test is about
**status alignment**, not the exact percentage
value, so the assertion is
`(float)$row['completion_percentage'] > 0`
regardless of the rounding.)

Add a new layer 4 source-grep check:

```php
// 4. Source-grep: syncProjectTaskCompletions() must call
//    deriveParentStatusFromCompletion() and the new helper
//    must exist in includes/functions.php.
$functions_src = file_get_contents(__DIR__ . '/../includes/functions.php');
$needed = [
    'function deriveParentStatusFromCompletion',
    'deriveParentStatusFromCompletion($currentStatus, (float)$storedCompletion)',
];
foreach ($needed as $needle) {
    if (strpos($functions_src, $needle) === false) {
        $failures[] = "includes/functions.php is missing '$needle'";
    }
}
```

The 4×4 matrix block and the source-grep block
for `ajax/parent_hold.php` are unchanged.

## Validation & edge cases

| Case                                                                                  | Expected parent `status` (v1.7.1.1)              |
| ------------------------------------------------------------------------------------- | ------------------------------------------------- |
| Parent has 3 sub-tasks, all `not_started`, all `completion_percentage = 0`           | `not_started` (percentage = 0).                    |
| Parent has 3 sub-tasks, one at `5%` and `not_started`, two at `0%` (UAT2 case)        | **`in_progress`** (percentage ≈ 1.67%). Was `not_started` under v1.7.0 / v1.7.1; **fixed** under v1.7.1.1. |
| Parent has 3 sub-tasks, one at `5%` and `in_progress`, two at `0%` and `not_started`  | `in_progress` (percentage ≈ 1.67% > 0; or 5% > 0 under weighted). |
| Parent has 3 sub-tasks, all `completed` and `100%`                                    | `completed` (percentage = 100).                    |
| Parent has 3 sub-tasks, two `completed` and `100%`, one `not_started` at `0%`         | `in_progress` (percentage ≈ 66.67%, or 0% under the strict equal-average fallback because the not_started child has 0% completion). The status is determined by the percentage, not the child status. **The strict `all-completed` rule from issue-025 still applies on the leaf level** (the `not_started` child is not `completed`), but the parent's status is now derived from the percentage, which may be `> 0` from the two `100%` siblings. |
| Parent has 3 sub-tasks, one `completed` and `100%`, two `not_started` at `0%`         | `in_progress` (percentage = 33.33% from the equal-average fallback). **The issue-025 strict `all-completed` rule is preserved on the leaf level** but the parent status is now `in_progress` from the percentage, not `not_started` from the child status mix. |
| Parent has 3 sub-tasks, all `not_started` and all `100%`                              | `completed` (percentage = 100). The leaves are `not_started` but the manager has dragged the slider to 100 — the parent is done. **This is the new behaviour** and it matches the user's spec. |
| Parent `on_hold`, percentage = 0                                                      | `on_hold` (sticky).                                |
| Parent `on_hold`, percentage = 50                                                     | `on_hold` (sticky).                                |
| Parent `on_hold`, percentage = 100                                                    | `on_hold` (sticky, even at completion). The "Resume" button on the task detail page is the only path off `on_hold`. |
| Parent `completed`, a child's percentage drops to 50%                                 | `in_progress` (percentage < 99.5). `completion_date` is cleared by the existing `elseif` block. |
| Parent `not_started`, a child's percentage rises to 50%                               | `in_progress`.                                     |
| Parent `in_progress`, all children reach `100%`                                       | `completed` (percentage = 100). `completion_date` is set to `NOW()` (or preserved if already set). |
| Resume after hold: parent `on_hold` → "Resume" → children all `completed`             | Parent becomes `completed` via the `resume` branch's call to `deriveParentStatus()` (strict `all-completed` rule). The next `syncProjectTaskCompletions()` re-derives to `completed` via the new helper. Same end state. |
| Resume after hold: parent `on_hold` → "Resume" → children `['completed', 'not_started']` | Parent becomes `not_started` via `deriveParentStatus()` (strict rule). Next sync re-derives from the percentage. The end state is consistent. |
| Parent with 0 sub-tasks (a leaf)                                                       | Not affected. `syncProjectTaskCompletions()` only rewrites parents (filtered by `$parentIds`). The leaf's own status is unchanged. |
| Parent with 100 sub-tasks                                                              | Helper is `O(1)`. `syncProjectTaskCompletions()` is already `O(n)` over all project tasks. No perf change. |
| Browser zoom 200%                                                                      | Unchanged. No new CSS.                              |
| `error_log` after deployment                                                            | Unchanged. No new PHP warnings.                     |
| Existing tests (`security_issue016_test`, `task_layout_issue013_test`, etc.)          | Unchanged. Pass.                                    |
| Existing layer-2 live-DB assertions (2a–2f)                                            | 2a is updated (above); 2b–2f unchanged.            |
| Existing pure-function fixtures (1)                                                    | Unchanged. The `deriveParentStatus()` helper still exists; the issue-025 fixtures still apply. |

## Acceptance criteria

- `deriveParentStatusFromCompletion(null, 0.0)` returns
  `'not_started'`.
- `deriveParentStatusFromCompletion(null, 0.0001)` returns
  `'in_progress'` (any positive value counts).
- `deriveParentStatusFromCompletion(null, 1.6)` returns
  `'in_progress'` — the UAT2 case.
- `deriveParentStatusFromCompletion(null, 50.0)` returns
  `'in_progress'`.
- `deriveParentStatusFromCompletion(null, 99.49)` returns
  `'in_progress'` (just below the 99.5 threshold).
- `deriveParentStatusFromCompletion(null, 99.5)` returns
  `'completed'` (the threshold).
- `deriveParentStatusFromCompletion(null, 100.0)` returns
  `'completed'`.
- `deriveParentStatusFromCompletion('on_hold', 100.0)`
  returns `'on_hold'` (sticky).
- The new layer-2 sub-test 2g passes: a parent with
  three sub-tasks, one at `5%` and `not_started`, two at
  `0%` and `not_started`, has parent
  `completion_percentage > 0` and parent `status =
  in_progress` after `syncProjectTaskCompletions()`.
- The new layer-4 source-grep check passes:
  - `function deriveParentStatusFromCompletion` appears
    in `includes/functions.php`.
  - The `syncProjectTaskCompletions()` body contains
    `deriveParentStatusFromCompletion($currentStatus, (float)$storedCompletion)`.
- The pre-existing layer-1 pure-function fixtures for
  `deriveParentStatus()` (issue-025) still pass — the
  helper is unchanged.
- `database/task_parent_status_derived_test.php` reports
  zero failures.
- `RELEASE.md` has a new top entry
  `## v1.7.1.1 — 2026-10-XX` with a short note: "Align
  parent status with parent completion percentage. The
  parent's `status` is now derived from its computed
  `completion_percentage` (0 → not_started, 0 < x < 99.5
  → in_progress, ≥ 99.5 → completed) rather than from the
  child `status` mix. Closes the UAT2 regression where a
  parent at 1.6% progress was still classified as
  `not_started`." plus a cross-link to this issue and to
  issue-025.
- `README.md` "Latest release" line and the version-
  history table are updated to reflect the v1.7.1.1
  release.
- The strict `all-completed` rule from issue-025 is
  preserved at the leaf level (a leaf's `status` is still
  the manager's manual signal and is not auto-derived
  from the leaf's `completion_percentage`).
- The `completion_date` alignment in
  `syncProjectTaskCompletions()` is unchanged
  (`COALESCE(completion_date, NOW())` on promotion to
  `completed`, `completion_date = NULL` on demotion).
- The `on_hold` stickiness is preserved.
- No new translation keys. No new CSS. No new schema.

## Out of scope

- Auto-aligning a leaf sub-task's `status` from its
  `completion_percentage`. The user did not ask for
  this; the leaf status remains the manager's manual
  signal. The fix is on the **parent**'s derivation,
  not on the leaf.
- Changing the issue-025 strict `all-completed` rule.
- Changing the `completion_date` alignment logic.
- Changing `calculateTaskCompletionMap()`.
- Changing `ajax/complete_task.php`,
  `ajax/update_task_status.php`,
  `ajax/update_task_progress.php`,
  `ajax/parent_hold.php` (the resume branch keeps
  `deriveParentStatus()`).
- Adding a new `partially_completed` /
  `mixed_completion` status. The four-value enum is
  sufficient.
- Changing the schema, AJAX endpoints, JS, CSS, or
  translations.
- A back-fill / migration script. The new derivation
  runs on the next `syncProjectTaskCompletions()`
  call (every sub-task edit, hold, resume, complete,
  progress update).

## Implementation notes for the developer

- The fix is **two files**:
  `includes/functions.php` (one new helper + one
  one-line change in
  `syncProjectTaskCompletions()`) and
  `database/task_parent_status_derived_test.php`
  (one new pure-function block, one updated layer-
  2 assertion, one new layer-2 sub-test, one new
  layer-4 source-grep block). The PR is small —
  under 100 lines of diff. Ship it as a single
  commit.
- The new helper's body is roughly eight lines.
  Copy the code block from the "Implementation"
  section above verbatim; the threshold (`99.5`)
  and the order of the `if` branches
  (on_hold → completed → in_progress →
  not_started) are load-bearing.
- Do **not** change the function signature
  `(?string $currentStatus, float $percentage)`.
  The new helper is called from one place
  (`syncProjectTaskCompletions()`) and tested
  directly; both depend on the signature.
- Do **not** delete `deriveParentStatus()`. The
  resume branch in `ajax/parent_hold.php` and the
  issue-025 pure-function fixtures both still
  depend on it. The two helpers are siblings.
- The 99.5 threshold matches the
  `round($completionMap['task_completion'][$parentId], 2)`
  granularity in
  `syncProjectTaskCompletions()`. A parent that is
  stored as `100.00` always classifies as
  `completed`; a parent that is stored as `99.49`
  always classifies as `in_progress`. The
  threshold is **not** `100` because the parent's
  stored percentage is rounded to 2 dp; using
  `>= 100` would classify a parent at `99.99` as
  `in_progress`, which is the wrong side of the
  user's "if status = 100 then: completed" rule.
- The `> 0` threshold for `in_progress` is
  inclusive of any positive value, including
  `0.0001`. A parent at `0.01%` is "in progress".
  This matches the user's "if status > 0 then:
  in progress" rule verbatim.
- The `on_hold` stickiness is the **first** check
  in the helper, before the percentage checks.
  This is unchanged from `deriveParentStatus()`
  and matches the issue-025 invariant.
- When updating the test fixtures, do **not**
  delete the existing issue-025 pure-function
  block. The issue-025 invariant is preserved;
  the fixtures are still load-bearing.
- Run the full test suite after the change:
  `php database/task_parent_status_derived_test.php`
  and any other `database/*_test.php` that
  touches `deriveParentStatus`,
  `deriveParentStatusFromCompletion`, or
  `syncProjectTaskCompletions`. The security,
  layout, and completion tests do not touch
  these functions and should pass unchanged.
- Smoke-test in the browser using the UAT2
  scenario:
  1. Create a project with a parent task and
     three sub-tasks.
  2. Drag the progress slider on one sub-task
     to `5%`. Leave the sub-task's status as
     `not_started`.
  3. Reload the parent's `task_detail.php`. The
     parent's "Overall Progress" should read
     ~`1.6%` and the status badge should read
     "In progress" (with the `[Auto]` pill).
  4. Mark the sub-task as `completed`. Reload.
     The parent's "Overall Progress" should read
     ~`33.3%` and the status badge should still
     read "In progress".
  5. Mark the other two sub-tasks as `completed`
     (at `100%` each). Reload. The parent's
     "Overall Progress" should read `100%` and
     the status badge should read "Completed".
- No new translations, no new CSS, no new schema.

## Cross-references

- [issue-025](./issue-025.md) — Parent Task Status
  "All Sub-Tasks Completed" Rule. The strict
  `all-completed` rule from issue-025 is preserved
  at the leaf level; this issue changes only the
  derivation source for the parent.
- [issue-012](./issue-012.md) — Parent Task Status
  derived from Sub-Task Statuses. The original
  feature that introduced `deriveParentStatus()`.
  This issue is a behavioural correction to the
  derivation source; the function
  `deriveParentStatus()` itself is unchanged and
  still drives the resume branch in
  `ajax/parent_hold.php`.
- [issue-007](./issue-007.md) — Parent
  `completion_percentage` is derived from
  sub-tasks. Independent of this issue; the
  percentage rule is unchanged.
- [issue-011](./issue-011.md) — Parent "Effort
  Estimate" inherit mode. Independent of this
  issue; the effort rule is unchanged.
