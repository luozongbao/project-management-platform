# Tester Note — issue-011 (Parent Task Effort — "Sum of Subtask Hours" Mode) — 1 Bug + 1 Spec Ambiguity

**Tester:** QA (against `main` after dev's `database/migrations/20261012_add_task_inherit_subtask_hours.sql` / `database/schema.sql` / `includes/functions.php` / `task_edit.php` / `task_detail.php` / `tasks.php` / `project_detail.php` / `client/dashboard.php` / `database/task_inherit_subtask_hours_test.php` changes)
**Related docs:** `docs/issues/issue-011.md`, `database/schema.sql`, `includes/functions.php`, `task_edit.php`, `task_detail.php`, `tasks.php`, `project_detail.php`, `client/dashboard.php`, `database/task_inherit_subtask_hours_test.php`
**Build under test:** latest `main` on the running docker-compose stack

> TL;DR — the inherit-from-sub-tasks mode works end-to-end: schema is correct and idempotent, the helper sums positive child estimates, all four display surfaces (internal + client) call the helper and agree numerically, the save handler enforces the spec's "force NULL when inherit=on / force 0 when inherit=off and parent has children" rules, the `project_stats` rollup is preserved (issue-007 unaffected), and the regression test passes. **One real spec violation** needs fixing:
> 1. **"Not estimated" copy missing** — when a parent is in inherit mode and **all** its sub-task estimates are `NULL` / `0` / negative, the spec requires all four display surfaces to render the literal text "Not estimated (no sub-task estimates)". The dev's `task_detail.php` shows a *different* warning banner ("Inherit mode is on but none of the sub-tasks have an estimate…"); `tasks.php`, `project_detail.php`, and `client/dashboard.php` show **nothing at all** (the row is hidden when `$resolved_total === null`).
>
> Plus one **spec-side issue** worth surfacing back to the product/spec author (not a dev bug): the "Localization" section of the spec asks for keys to be added to "the existing internal-app translation catalog", but **no such catalog exists** in this repo. The internal app (`includes/functions.php`, `task_edit.php`, all other non-`/client/` files) is English-only by design (v1.1.0). The dev correctly did not invent a catalog. Strings are hardcoded English and match the spec values verbatim.

---

## Summary

| # | Severity | Area | Spec says | Actual behaviour | Reproducible |
|---|---|---|---|---|---|
| 1 | **Medium** (spec violation) | All four display surfaces (`task_detail.php`, `tasks.php`, `project_detail.php`, `client/dashboard.php`) | "Display shows 'Not estimated (no sub-task estimates)'" for parent in inherit mode where all sub-task estimates are NULL/0/negative (spec table § "Validation edge cases"); also "displays `Not estimated` in both places" (internal + client) | `task_detail.php` shows a *different* warning banner (yellow callout, distinct copy); `tasks.php`, `project_detail.php`, `client/dashboard.php` show **nothing** (helper returns `null` → row is hidden) | Yes — create parent, add ≥1 sub-task, turn inherit on, set all sub-task estimates to NULL or 0; observe four surfaces |
| 2 | **Spec issue** (not a dev bug) | `docs/issues/issue-011.md` § "Localization" | "Add the following keys to the existing internal-app translation catalog (`dash.parent_inherit_on`, `dash.parent_inherit_off`, `dash.subtask_total_label`, `dash.not_estimated_parent`)" | Internal app has no translation catalog (only `/client/lang.php` exists, which is for the client portal). Dev correctly did not invent one. Strings are hardcoded English in the render code and match the spec copy. | N/A — decision needed from product |

### Things that PASS (no action needed)

- **Schema:** `tasks.inherit_subtask_hours TINYINT(1) NULL DEFAULT NULL` added after `estimated_hours` (verified in live MariaDB + `database/schema.sql` line 88). Migration file `20261012_add_task_inherit_subtask_hours.sql` is idempotent (re-runs as no-op via `information_schema.COLUMNS` guard). *(Minor: spec referenced the migration filename as `20261006_…` but dev used `20261012_…` — same column, harmless filename drift.)*
- **Helper:** [`includes/functions.php`](includes/functions.php) line 97 has `resolveTaskEstimateHours($task, $childTasks): ?float` with the exact spec logic: `inherit=1` → sum positive child estimates (skip 0/null/negative), `inherit=0` → return stored `estimated_hours` if positive, else `null`. Docblock references issue-011 and issue-007.
- **All four display surfaces call the helper** (verified via grep): `task_detail.php` (line 241 for sub-task display, line 330 for parent), `tasks.php` (line ~340), `project_detail.php` (line ~290), `client/dashboard.php` (line 124).
- **Internal + client numerical agreement:** a parent with `inherit=1` and sub-tasks 2.0 + 0.5 + 1.5 shows "Total Time: 4 hours" on `task_detail.php` (internal), `tasks.php` (internal), `project_detail.php` (internal), and `client/dashboard.php` — all four display the same number with the same `formatTaskEstimate()` "X hour(s)" formatting.
- **`project_stats` rollup preserved:** issue-007's weighted-completion view still aggregates `estimated_hours` correctly. A parent with `inherit=1` and `estimated_hours=NULL` is rolled up via the SUM of its children by the view, matching the pre-issue-011 behaviour.
- **Save handler (`task_edit.php`):** five sub-cases all pass:
  1. `inherit=1` + stale `total_time` in POST → server forces `NULL` (defence in depth; form disables the field but non-JS browsers still bypass).
  2. `inherit=0` + empty `total_time` + parent has children → validation error.
  3. `inherit=0` + `0` + parent has children → validation error.
  4. `inherit=0` + `7` → valid save.
  5. Parent has 0 children + `total_time=0` → no validation error (allowed: orphan parent with zero estimate).
- **Checkbox render guard:** `task_edit.php` only renders the "Inherit hours from sub-tasks" checkbox when `$is_editing && $subtask_count > 0`. New (un-saved) parents don't see the checkbox.
- **No reparent UI:** the existing `parent_task_id` of a saved task is never updated by `task_edit.php` POST, so sub-tasks cannot be moved out from under a parent via the UI (the spec's "theoretical — no reparent UI" row is true).
- **Regression test:** `database/task_inherit_subtask_hours_test.php` runs and reports PASS. It covers schema introspection, helper math (with NULL/0/negative filtering), live DB round-trip (parent + children + resolution), and source-grep checks.
- **Form validation when children exist and inherit off:** "Estimated hours is required" / "Estimated hours must be greater than zero" server errors are emitted (not silent).

---

## Bug 1 — "Not estimated (no sub-task estimates)" copy missing from all four display surfaces

### Spec quotes

From `docs/issues/issue-011.md`, § "Validation edge cases", the table row "Inherit mode ON, all sub-task estimates NULL/0/negative":

> Display: shows "Not estimated (no sub-task estimates)".

From `docs/issues/issue-011.md` § "Acceptance criteria" (final bullet):

> A parent whose sub-tasks are all NULL/zero in inherit mode displays **"Not estimated" in both places** (internal task list + client dashboard) so the user understands the parent is not estimated rather than reading "0 hours" or a blank cell.

### Actual behaviour (live probes)

I created a parent task with `inherit=1`, then added one child and set the child's `estimated_hours=NULL`. Visited the four display surfaces:

| Surface | What user sees | What spec requires |
|---|---|---|
| [`task_detail.php`](task_detail.php) (parent block, ~line 330) | **Yellow warning banner**: "Inherit mode is on but none of the sub-tasks have an estimate. The parent's completion will use the equal-average fallback." | "Not estimated (no sub-task estimates)" |
| [`tasks.php`](tasks.php) (project task list, ~line 340) | **Nothing** (the "Total Time: …" row is hidden entirely because `$resolved_total === null`) | "Not estimated (no sub-task estimates)" |
| [`project_detail.php`](project_detail.php) (project progress, ~line 290) | **Nothing** (same as above) | "Not estimated (no sub-task estimates)" |
| [`client/dashboard.php`](client/dashboard.php) (total time column, line 124) | **Nothing** (the "Total Time: …" row is hidden) | "Not estimated" (in client copy) |

### Root cause

`resolveTaskEstimateHours()` returns `null` in all four of these cases (correctly per its contract — there's no numeric value to display). All four surfaces then use a pattern like:

```php
$resolved_total = resolveTaskEstimateHours($task, $child_rows);
if ($resolved_total !== null) {
    echo 'Total Time: ' . formatTaskEstimate($resolved_total);
}
```

which silently hides the row. `task_detail.php` has a *different* branch (a warning banner with copy invented by the dev), and that copy does not match the spec either.

### Suggested fix (for dev)

In all four surfaces, after calling `resolveTaskEstimateHours()`, add an `else` branch that renders the spec's "Not estimated (no sub-task estimates)" string when `$task['inherit_subtask_hours'] == 1` (and there is at least one sub-task, so we know we're in the "inherit mode but no positive child estimates" case). Roughly:

```php
$resolved_total = resolveTaskEstimateHours($task, $child_rows);
if ($resolved_total !== null) {
    echo 'Total Time: ' . formatTaskEstimate($resolved_total);
} elseif (!empty($task['inherit_subtask_hours'])) {
    echo 'Not estimated (no sub-task estimates)';
}
```

For the client portal, use the `t('dash.not_estimated_parent')` key (already present in `client/lang.php` per the spec's client-side requirement) instead of the English literal.

For `task_detail.php`, the existing yellow warning banner should be **replaced** with the spec's "Not estimated (no sub-task estimates)" line — the current banner's copy is not in the spec and conflates "no estimate" with "completion will use equal-average fallback", which is a separate concern and is the wrong surface for that message.

### Reproduction (copy-pasteable)

```bash
# 1. log in as test user (code WAHZV-5WQUV-P3T76), pick any project
# 2. create a parent task "Test parent" with no estimate, save
# 3. open the parent for editing → tick "Inherit hours from sub-tasks" → save
# 4. add a child "Test child" with no estimate
# 5. visit:
#      http://web/task_detail.php?id=<parent_id>     → wrong banner
#      http://web/tasks.php?project_id=<pid>         → blank
#      http://web/project_detail.php?id=<pid>        → blank
#      http://web/client/dashboard.php?code=<code>   → blank
# Expected (per spec): "Not estimated (no sub-task estimates)" on all four
```

---

## Spec issue 2 (not a dev bug) — "Localization" section references a catalog that does not exist

`docs/issues/issue-011.md` § "Localization" says:

> Add the following keys to the existing internal-app translation catalog:
> - `dash.parent_inherit_on` — "Inherit hours from sub-tasks"
> - `dash.parent_inherit_off` — (label)
> - `dash.subtask_total_label` — "Total Time"
> - `dash.not_estimated_parent` — "Not estimated (no sub-task estimates)"

But there is **no "internal-app translation catalog"** in this repository. The only translation file is `client/lang.php`, which is used exclusively by the client portal (`/client/*` pages). The internal app (`includes/functions.php`, `task_edit.php`, `task_detail.php`, `tasks.php`, `project_detail.php`, etc.) is hardcoded English by design — this is documented in the repo memory note for i18n and the `client/lang.php` design is intentional (internal v1.1.0 is English-only; i18n for the internal app is a v1.2 task).

The dev's choice: hardcode the English strings in the render code, matching the spec values verbatim. This is **functionally correct** for the only locale the internal app currently supports.

### Recommended action (for product/spec author, not dev)

Decide one of:

1. **Amend the spec:** change the Localization section to "Use the English strings verbatim in the internal app. The `dash.*` keys already exist in `client/lang.php` and cover the client portal. A v1.2 task will add an internal-app catalog." — then no code change is needed.
2. **Add the catalog now:** the dev introduces an `includes/lang.php` (or similar) with the four `dash.*` keys (EN + 中文), then refactors the four render sites to call the new `t()`. This is **out of scope** for issue-011 per the v1.1.0/v1.2 split, so it would need to be a new issue.

I lean toward (1) — adding a brand-new translation layer as a side effect of one feature would be scope creep, and the strings are already correct.

---

## Minor observations (not bugs, no action required)

1. **Migration filename drift:** spec referenced `20261006_add_task_inherit_subtask_hours.sql`; dev used `20261012_add_task_inherit_subtask_hours.sql`. Same column, idempotent, no functional impact. If the spec is the source of truth for migration filenames, the file can be renamed — but doing so is purely cosmetic.
2. **Theoretical checkbox-on-sub-sub-task render:** `task_edit.php` line 442 renders the inherit checkbox when `$subtask_count > 0` regardless of `parent_task_id`. The app's UI does not support sub-sub-tasks (only direct children are loaded by `task_detail.php`/`tasks.php`), so this is unreachable today. If sub-sub-tasks are ever added, the guard should become `parent_task_id IS NULL && subtask_count > 0` to prevent a sub-task with grandchildren from showing the inherit checkbox (a sub-task is itself a child; its hours shouldn't be derived from grandchildren).
3. **Server-side force-NULL defence:** the form `disabled`s the `total_time` field when inherit=on, but the server still re-checks and forces NULL on save (`task_edit.php` lines 178-186). This is good defence-in-depth; worth keeping if the form's HTML is ever refactored.
4. **`tasks.php` and `project_detail.php` show "Total Time" only when numeric** (current behaviour even before this issue). This is consistent within the codebase, but the new spec asks for "Not estimated" to appear explicitly in the same spot — see Bug 1 above.

---

## Verification matrix

| Acceptance criterion (from spec) | Result |
|---|---|
| 1. `tasks.inherit_subtask_hours` column added | ✅ Live DB + `schema.sql` |
| 2. Migration is idempotent | ✅ Re-ran twice, no error |
| 3. `resolveTaskEstimateHours()` helper exists with correct logic | ✅ Tested in `task_inherit_subtask_hours_test.php` |
| 4. Form checkbox hidden for non-parents | ✅ `task_edit.php` line 442 |
| 5. Save forces NULL when inherit=on | ✅ Verified via POST probe |
| 6. Save rejects empty/zero when inherit=off and parent has children | ✅ Verified via POST probe |
| 7. `task_detail.php` uses helper | ✅ Line 241, 330 |
| 8. `tasks.php` uses helper | ✅ Line ~340 |
| 9. `project_detail.php` uses helper | ✅ Line ~290 |
| 10. `client/dashboard.php` uses helper and matches internal number | ✅ Line 124, live probe |
| 11. Inherit + all NULL sub-tasks → "Not estimated (no sub-task estimates)" on all four surfaces | ❌ **Bug 1** |
| 12. `project_stats` view rollup unaffected | ✅ Live probe |

---

## Probes & cleanup

All probe files (`/tmp/probe*.php`, `/tmp/outer.php`, `/tmp/inner.php`) and all test data (users `p11*` / `issue011*` / `probe011*`, projects and tasks created during testing) have been removed from the running MariaDB instance. No leftover state.
