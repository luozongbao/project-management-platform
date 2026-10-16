# Parent Task Status — "All Sub-Tasks Completed" Rule

**Status:** Proposed
**Type:** Bug / Behavioural Correction
**Priority:** Medium
**Target Release:** v1.7.1
**Affects:** `includes/functions.php` (`deriveParentStatus()`), `database/task_parent_status_derived_test.php` (test fixtures only; logic and pure-function tests must be updated to match the new rule)

## Problem

[issue-012](./issue-012.md) introduced `deriveParentStatus()` in
`includes/functions.php` (lines 148–171) to derive a parent task's
status from its sub-task statuses. The current implementation uses
a **loose** "any" rule for completion:

```php
// includes/functions.php:159
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

The natural reading of "all sub-tasks completed" is that the parent
becomes `completed` only when **every** sub-task is `completed`.
But the current rule says "the parent is `completed` if **any**
sub-task is `completed`", provided no sub-task is `in_progress` and
the parent is not `on_hold`.

That divergence causes three real, observable mis-classifications:

| Sub-task statuses                                | Current result (loose) | User's expected result (strict) |
| ------------------------------------------------ | ---------------------- | ------------------------------- |
| `['completed', 'not_started']`                   | `completed`            | `not_started`                   |
| `['completed', 'on_hold']`                      | `completed`            | `not_started`                   |
| `['completed', 'completed', 'not_started']`      | `completed`            | `not_started`                   |
| `['completed', 'completed']`                     | `completed`            | `completed`                     |
| `['not_started', 'not_started']`                 | `not_started`          | `not_started`                   |
| `['in_progress', 'not_started']`                 | `in_progress`          | `in_progress`                   |
| `[]` (no sub-tasks)                              | `not_started`          | `not_started`                   |
| current = `on_hold`, any children                | `on_hold`              | `on_hold` (sticky)              |

The user's three explicit rules from the bug report:

> 1. if some task started but not all completed, then main task: In Progress
> 2. if All not started, then main task: Not Started
> 3. If all subtaskes completed, then main task: Completed

Match the **strict** interpretation, not the loose one. The third
row of the table above — `['completed', 'not_started']` → loose
gives `completed`, strict gives `not_started` — is the exact case
the user is calling out. The current behaviour is wrong because it
tells the project overview "this task is done" when a third of the
work is still untouched.

The existing test
`database/task_parent_status_derived_test.php` even names this as
a "loose rule" (the fixture is labelled
`'completed beats not_started when no in_progress (loose rule)'`).
The fixture is correct in describing the **current** code; it is
**wrong** as a product specification. The product team
(intuited from the user's bug report and from the way the
`in_progress` rule is already strict) wants the strict
interpretation.

This is a small, low-risk change: the function is pure, the
callers (issue-012) re-write the parent row only when the value
changes (`AND (completion_percentage <> ? OR status <> ?)` in
`syncProjectTaskCompletions()`), and the existing exhaustive
4×4 matrix test already proves every cell returns a valid enum
value, so the fix cannot introduce an out-of-range status. The
change is also **non-destructive on completed projects**: a
parent that was promoted to `completed` under the loose rule
and is still showing one `not_started` sub-task will be
demoted back to `not_started` (or `in_progress` if any
sub-task is `in_progress`) on the next
`syncProjectTaskCompletions()` call, which is fired by every
edit, complete, hold, and resume. The demotion is the
intended outcome.

## Goals

- Change `deriveParentStatus()` so that the `completed` branch
  fires **only when every sub-task is `completed`**. The new
  rule is:
  - `on_hold` (current parent status) is sticky: it wins
    regardless of children (unchanged).
  - Otherwise `in_progress` if **any** child is `in_progress`
    (unchanged).
  - Otherwise `completed` if **every** child is `completed`
    AND the children list is non-empty (changed).
  - Otherwise `not_started` (unchanged).
- Empty children list `[]` returns `not_started` (unchanged;
  the new `completed` branch is guarded by `count > 0`).
- Update the existing fixture
  `'completed beats not_started when no in_progress (loose
  rule)'` in
  `database/task_parent_status_derived_test.php` to reflect
  the new strict rule. Add the two additional cases shown in
  the table above (mixed completed + not_started / on_hold).
- Update the four existing test cases that codify the loose
  rule. The full list of test fixtures that must change is
  in the "Test fixtures" section below.
- Keep `on_hold` stickiness, the `in_progress` rule, the
  valid-enum guarantee, and the 4×4 matrix coverage
  unchanged.
- The change ships in v1.7.1 because it is a
  backwards-incompatible **behaviour** change (a parent that
  was previously `completed` may now be `not_started`) and
  needs to be called out in the changelog. There is no
  schema change; this is a single-function logic fix.

## Non-goals

- Reworking the `in_progress` rule. The user did not ask
  for it; the existing `in_progress` if any child is
  `in_progress` is the same as "if some task started" and
  matches rule 1.
- Adding a new `partially_completed` or
  `mixed_completion` status. The user's three rules map
  cleanly onto the existing four-value enum; no new
  status is needed.
- Changing the on-hold stickiness. The user did not flag
  it; the existing behaviour (parent `on_hold` wins
  regardless of children) is correct.
- Changing the schema, AJAX endpoints, or
  `syncProjectTaskCompletions()` itself. The fix is
  entirely inside `deriveParentStatus()`.
- Re-running the data migration on existing projects. The
  demotion happens automatically on the next
  `syncProjectTaskCompletions()` call (every edit, hold,
  resume, complete, or progress update), which is fired
  on the next user interaction. No back-fill script is
  required.
- Changing the test framework or test runner. The fix
  updates the existing test fixtures in
  `database/task_parent_status_derived_test.php`.
- Changing the client portal
  (`client/dashboard.php`) or any other display surface.
  The client portal reads the parent's `status` field
  after `syncProjectTaskCompletions()` runs, so it
  picks up the new value automatically.
- Adding new translations. The four status labels
  (`Not started`, `In progress`, `Completed`, `On
  Hold`) are unchanged.

## UX

The fix is invisible to most users. The only difference is
the parent task's status badge on surfaces that read
`tasks.status`:

- `task_detail.php` — "Task Overview" card, right-aligned
  status pill (currently shows `In progress`, `Not
  started`, `Completed`, or `On Hold`).
- `tasks.php` — per-row status pill.
- `project_detail.php` — per-task status pill.
- `client/dashboard.php` — per-task status pill (locale-
  aware EN / 中文).

For a parent whose sub-task mix produces a different
classification under the strict rule, the badge changes
the next time any sub-task is updated, the parent is
re-loaded, or the user navigates back to the page. There
is no user-facing toast, modal, or migration message.
The change is documented in `RELEASE.md` under the v1.7.1
entry and in the changelog sidebar on the project.

A "Demo / why it matters" screenshot pair (before / after)
is **not** required for the issue; the test fixtures
document the change.

## Implementation

### `includes/functions.php` — `deriveParentStatus()`

Replace the body of the function (lines 159–171) with the
strict version. The function signature, docblock, and
sticky-`on_hold` first-branch are unchanged.

```php
function deriveParentStatus(?string $currentStatus, array $childStatuses): string {
    if ($currentStatus === 'on_hold') {
        return 'on_hold';
    }
    foreach ($childStatuses as $s) {
        if ($s === 'in_progress') return 'in_progress';
    }
    if (!empty($childStatuses)) {
        $allCompleted = true;
        foreach ($childStatuses as $s) {
            if ($s !== 'completed') { $allCompleted = false; break; }
        }
        if ($allCompleted) {
            return 'completed';
        }
    }
    return 'not_started';
}
```

Three behavioural diffs versus the current code:

1. The `in_progress` rule is unchanged: any child
   `in_progress` wins.
2. The `completed` rule is now **strict**: every child
   must be `completed`. The function short-circuits on
   the first non-`completed` child to keep the loop
   `O(n)` worst case.
3. The `!empty($childStatuses)` guard prevents
   `[]` from returning `completed`. (The current code
   already returns `not_started` for `[]` because the
   `foreach` is a no-op, but the explicit guard
   documents the intent.)

### `database/task_parent_status_derived_test.php` — fixtures

The current "pure-function" `$pure_tests` array
(lines 49–84) has four fixtures that codify the loose
rule. They must change. The new fixtures, in the same
order:

```php
$pure_tests = [
    // 1. Rule 1 from the bug report: "some task started but
    //    not all completed → in_progress".
    'in_progress: one in_progress, one not_started' => [
        null, ['in_progress', 'not_started'], 'in_progress',
    ],
    'in_progress: one in_progress, rest completed' => [
        null, ['in_progress', 'completed', 'completed'], 'in_progress',
    ],

    // 2. Rule 2 from the bug report: "all not_started → not_started".
    'not_started: all not_started' => [
        null, ['not_started', 'not_started'], 'not_started',
    ],
    'not_started: empty children list' => [
        null, [], 'not_started',
    ],

    // 3. Rule 3 from the bug report: "all sub-tasks completed
    //    → completed". Strict: even one non-completed child
    //    prevents promotion.
    'completed: all completed' => [
        null, ['completed', 'completed'], 'completed',
    ],
    'completed: single completed child' => [
        null, ['completed'], 'completed',
    ],

    // 4. The "loose rule" cases — these now return
    //    not_started under the strict rule. They are the
    //    regressed cases this issue fixes.
    'not_started: completed + not_started (strict rule)' => [
        null, ['completed', 'not_started'], 'not_started',
    ],
    'not_started: completed + on_hold (strict rule)' => [
        null, ['completed', 'on_hold'], 'not_started',
    ],
    'not_started: completed + completed + not_started (strict rule)' => [
        null, ['completed', 'completed', 'not_started'], 'not_started',
    ],

    // 5. on_hold children do not promote to in_progress or completed.
    'not_started: on_hold only (no progress, not all completed)' => [
        null, ['on_hold'], 'not_started',
    ],
    'in_progress: mixed on_hold + in_progress (on_hold is the child, not parent)' => [
        null, ['on_hold', 'in_progress', 'not_started'], 'in_progress',
    ],
    'not_started: mixed on_hold + completed (on_hold blocks strict completed)' => [
        null, ['on_hold', 'completed'], 'not_started',
    ],
];
```

The 4×4 exhaustive matrix block
(`foreach ($VALID as $current) { foreach ($VALID as $c0) { ... } }`)
stays unchanged. It already verifies:

- Every result is a valid enum value.
- `current = 'on_hold'` is sticky regardless of the child.

The new fixtures above replace the four old "loose rule"
fixtures. The "Source-grep checks" block
(`ajax/parent_hold.php` etc.) and the
"Live DB exercise of `ajax/parent_hold.php`" block are
unchanged — the wiring still calls
`deriveParentStatus()`, only the function body changed.

### No other code changes

- `ajax/parent_hold.php` — unchanged. The `resume` branch
  still calls `deriveParentStatus(null, $child_statuses)`
  and persists the result. With the strict rule, a resume
  on a parent whose sub-tasks are `['completed',
  'not_started']` now correctly sets the parent back to
  `not_started` instead of `completed`. This is the
  intended behaviour: the resume action re-derives
  status from the **current** sub-task mix.
- `ajax/complete_task.php` — unchanged. It rejects
  parents with sub-tasks (HTTP 409) and only completes
  leaf tasks. The strict rule has no effect on this
  path.
- `ajax/update_task_status.php` — unchanged. The
  strict rule has no effect on leaf tasks; for a
  parent, the AJAX endpoint either succeeds (if the
  target status is one of the four valid values)
  or fails validation. The next
  `syncProjectTaskCompletions()` call inside the
  handler still re-derives the status from the
  children, so a parent whose sub-task mix
  contradicts its new status will be corrected
  immediately.
- `ajax/update_task_progress.php` — unchanged for
  the same reason.
- `task_edit.php` — unchanged. The `Status` field
  on a parent with sub-tasks is already
  read-only (issue-012); the user cannot manually
  set the parent status to anything other than
  `on_hold` (via the on-hold button) or whatever
  `deriveParentStatus()` returns.
- `task_detail.php`, `tasks.php`,
  `project_detail.php`, `client/dashboard.php` —
  unchanged. They all read `tasks.status` and
  render the badge. The new value flows through
  automatically.
- `assets/css/style.css` — unchanged. The
  `status-in_progress`, `status-completed`,
  `status-not_started`, `status-on_hold` rules
  are already in place.
- No schema change. No new column. No new
  migration. The fix is a single PHP function
  body.

## Validation & edge cases

| Case                                                              | Expected result                                                                                          |
| ----------------------------------------------------------------- | -------------------------------------------------------------------------------------------------------- |
| Sub-tasks: `['completed', 'completed']`                           | `completed` (was `completed` under loose; still `completed` under strict).                              |
| Sub-tasks: `['completed', 'not_started']`                         | `not_started` (was `completed` under loose; **fixed** to `not_started` under strict).                   |
| Sub-tasks: `['completed', 'on_hold']`                             | `not_started` (was `completed` under loose; **fixed** to `not_started` under strict).                   |
| Sub-tasks: `['completed', 'completed', 'not_started']`            | `not_started` (was `completed` under loose; **fixed** to `not_started` under strict).                   |
| Sub-tasks: `['completed', 'completed', 'completed']`              | `completed` (unchanged).                                                                                  |
| Sub-tasks: `['in_progress', 'not_started']`                       | `in_progress` (unchanged; matches rule 1 of the bug report).                                            |
| Sub-tasks: `['in_progress', 'completed']`                         | `in_progress` (unchanged; rule 1 wins over rule 3).                                                     |
| Sub-tasks: `['in_progress', 'on_hold']`                           | `in_progress` (unchanged; on-hold child does not block in_progress promotion).                          |
| Sub-tasks: `['not_started', 'not_started']`                       | `not_started` (unchanged; matches rule 2 of the bug report).                                            |
| Sub-tasks: `['not_started', 'completed']`                         | `not_started` (was `completed` under loose; **fixed** to `not_started` under strict).                   |
| Sub-tasks: `[]`                                                  | `not_started` (unchanged; explicit `!empty()` guard).                                                   |
| Sub-tasks: `['completed']` (single sub-task)                      | `completed` (unchanged).                                                                                  |
| Sub-tasks: `['on_hold']` (only child on hold)                     | `not_started` (no progress, not all completed).                                                          |
| current = `on_hold`, any children                                 | `on_hold` (sticky; unchanged).                                                                           |
| current = `not_started`, children: `['completed', 'completed']`   | `completed` (unchanged).                                                                                  |
| current = `completed`, children: `['completed', 'not_started']`  | `not_started` (sticky override does not apply because current is not `on_hold`; new rule fires).        |
| current = `completed`, children: `['completed', 'completed']`     | `completed` (unchanged).                                                                                  |
| current = `in_progress`, children: `['not_started', 'not_started']` | `not_started` (the in_progress current is **not** sticky; new derived value wins).                     |
| Parent that was promoted to `completed` under the loose rule and still has a `not_started` sub-task | On the next `syncProjectTaskCompletions()` (any sub-task edit, hold, resume, complete, progress), the parent is demoted to `not_started`. Expected. |
| Parent that was promoted to `completed` under the loose rule and all sub-tasks are now `completed` | Stays `completed`.                                                                                       |
| `error_log` after deployment                                       | Unchanged. No new PHP warnings.                                                                           |
| Performance: parent with 100 sub-tasks                            | Function is `O(n)` worst case (early exit on first non-`completed` child). No DB call. The calling `syncProjectTaskCompletions()` already fetches all project tasks. |
| Backwards-incompatible behaviour                                  | Called out in `RELEASE.md` under v1.7.1 with the three rule deltas. **No data migration** is required.  |
| Re-running the test suite                                         | `database/task_parent_status_derived_test.php` reports 0 failures.                                        |
| Re-running the full `database/*_test.php` suite                   | All other tests (security, layout, completion) pass unchanged.                                            |

## Acceptance criteria

- `deriveParentStatus(null, ['completed', 'not_started'])`
  returns `'not_started'`, not `'completed'`. This is the
  single most important assertion; it is the case the
  user explicitly called out.
- `deriveParentStatus(null, ['completed', 'completed'])`
  returns `'completed'`.
- `deriveParentStatus(null, ['in_progress', 'not_started'])`
  returns `'in_progress'`.
- `deriveParentStatus(null, ['not_started', 'not_started'])`
  returns `'not_started'`.
- `deriveParentStatus(null, [])` returns `'not_started'`.
- `deriveParentStatus('on_hold', ['in_progress', 'not_started'])`
  returns `'on_hold'` (sticky).
- The exhaustive 4×4 matrix test (every `current` × every
  single-child `status`) still passes, with every cell
  returning a valid enum value and `current = 'on_hold'`
  always returning `'on_hold'`.
- `database/task_parent_status_derived_test.php` runs
  clean (zero failures).
- The "Live DB exercise of `ajax/parent_hold.php`" block
  in the same test file still passes — the wiring is
  unchanged, only the function body is.
- `RELEASE.md` has a new top entry `## v1.7.1 — 2026-10-XX`
  with a short note: "Tighten parent-task status rule:
  the parent is now `completed` only when **all** sub-tasks
  are `completed` (was: any sub-task `completed`)." and a
  cross-link to this issue.
- `README.md` "Latest release" line and the "v1.7.1" line
  in the version-history table are updated to reflect the
  new release.
- A parent that was previously classified as `completed`
  under the loose rule and still has at least one
  `not_started` sub-task is re-classified to
  `not_started` on the next user interaction that triggers
  `syncProjectTaskCompletions()`. This is automatic and
  requires no migration script.
- No new translation keys. No new CSS. No new schema.

## Out of scope

- A general rework of the parent-status rule (e.g. weighted
  by hours, or by completion-percentage instead of by
  sub-task status). The user asked for a strict version of
  the existing rule, not a new rule.
- Adding a new `partially_completed` or
  `mixed_completion` enum value. The four-value enum is
  sufficient for the user's three rules.
- Changing the on-hold stickiness, the `in_progress`
  rule, the empty-children rule, or the valid-enum
  guarantee. Those are unchanged.
- Changing the schema, AJAX endpoints, or
  `syncProjectTaskCompletions()` itself.
- A back-fill / migration script for existing projects.
  The demotion happens automatically on the next
  user interaction.
- Changing the test framework or test runner.
- Changing the client portal
  (`client/dashboard.php`) or any other display surface.
- Adding new translations.

## Implementation notes for the developer

- The fix is **two files**: `includes/functions.php`
  (function body) and
  `database/task_parent_status_derived_test.php`
  (test fixtures). The PR is small — under 50 lines
  of diff. Ship it as a single commit.
- The function body diff is roughly six lines
  (early exit on `in_progress`, `all-completed`
  check with empty guard, default `not_started`).
  Copy the code block from the "Implementation"
  section above verbatim; do not add an
  `array_unique()` or `array_filter()` to "clean up"
  the children list — the `in_progress` short-circuit
  already short-circuits on the first match and the
  strict check is `O(n)`.
- Do **not** change the function signature
  (`?string $currentStatus, array $childStatuses`).
  All four call sites
  (`syncProjectTaskCompletions()`,
  `ajax/parent_hold.php` resume branch, the test
  suite's pure-function block, and the test suite's
  matrix block) depend on the signature.
- Do **not** change the docblock rule list. The
  current docblock at lines 152–157 reads:
  > - `on_hold` (parent) is sticky manual: it wins
  >   regardless of children.
  > - Otherwise `in_progress` if any child is
  >   `in_progress`.
  > - Otherwise `completed` if any child is
  >   `completed`.
  > - Otherwise `not_started`.
  The third bullet must change to
  > - Otherwise `completed` if **all** children are
  >   `completed`.
  Update it in the same commit.
- When updating the test fixtures, do **not** delete
  the four old "loose rule" fixtures. Replace them
  with the new fixtures in the "Implementation"
  section above. The new fixtures include both the
  regressed cases (which now return `not_started`)
  and a positive case (`['completed', 'completed']`
  → `completed`) to make the new rule obvious.
- Run the full test suite after the change:
  `php database/task_parent_status_derived_test.php`
  and any other `database/*_test.php` that touches
  `deriveParentStatus` or
  `syncProjectTaskCompletions`. The security,
  layout, and completion tests do not touch this
  function and should pass unchanged.
- Smoke-test in the browser:
  1. Create a parent task with two sub-tasks.
  2. Mark sub-task A as `completed`.
  3. Mark sub-task B as `not_started` (or leave it
     `not_started`).
  4. Reload the parent's `task_detail.php`. The
     parent's status badge should read
     "Not started", not "Completed".
  5. Mark sub-task B as `in_progress`.
  6. Reload. The badge should read "In progress".
  7. Mark sub-task B as `completed`.
  8. Reload. The badge should read "Completed".
- No new translations, no new CSS, no new schema.

## Cross-references

- [issue-012](./issue-012.md) — Parent Task Status derived
  from sub-task statuses (the feature that introduced
  `deriveParentStatus()`). This issue is a behavioural
  correction to the rule introduced there; the function
  signature, the call sites, and the wiring are
  unchanged.
- [issue-007](./issue-007.md) — Parent `completion_percentage`
  is derived from sub-tasks. Independent of this issue;
  the percentage rule is unchanged.
- [issue-011](./issue-011.md) — Parent "Effort Estimate"
  inherit mode. Independent of this issue; the effort
  rule is unchanged.
- [issue-015](./issue-015.md) — v1.6.0 security audit
  umbrella. This issue is **not** a security fix; it is
  a behavioural correction shipped under the v1.7.1
  bugfix line.
