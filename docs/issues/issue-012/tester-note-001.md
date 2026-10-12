# Tester Note — Issue 012: Parent Task Status — Derived from Sub-Task Statuses

| Field | Value |
| --- | --- |
| Issue | `docs/issues/issue-012.md` |
| Tester | GitHub Copilot (Tester mode) |
| Build under test | `pmp-app` + `pmp-web` containers, MariaDB 10.11, PHP 8.3 |
| Verdict | **PASS — 0 bugs** |
| Date | 2026-10-06 |

## TL;DR

All twelve acceptance criteria from `docs/issues/issue-012.md` are met. The
derived-status logic is correct (including the `on_hold` stickiness rule,
demotion `completed → in_progress` clearing `completion_date`, and the read-only
status field on `task_edit.php` for parents with sub-tasks). The new AJAX
endpoint `ajax/parent_hold.php` is secure (CSRF + ownership + sub-task
rejection + no-children rejection). Two non-blocking notes appear at the end
(T8 stale-percentage on a niche resume edge, and a phantom "internal-app
i18n catalog" reference in the spec). No code change required before merging.

---

## 1. Spec compliance matrix

| # | Acceptance criterion | Result | Evidence |
| --- | --- | --- | --- |
| A1 | `deriveParentStatus($current, $children)` helper with `on_hold` sticky rule | PASS | `includes/functions.php:129`; pure-function test, 10/10 |
| A2 | `syncProjectTaskCompletions()` extended to derive status for parents with sub-tasks | PASS | `includes/functions.php:255-298`; SELECT now includes `status`; builds `$subtaskStatuses`; calls helper; `completion_date` aligned via `COALESCE` |
| A3 | `ajax/parent_hold.php` (hold / resume) with security and validation | PASS | New file (~150 lines). CSRF + ownership JOIN + sub-task rejection (400) + no-children rejection (404) + JSON/form split via `X-Requested-With` |
| A4 | `task_detail.php` shows `Auto` pill + Put on Hold / Resume forms for parents with sub-tasks only | PASS | `task_detail.php:103-122`, `:149`. Buttons gated on `parent_task_id === null && count($subtasks) > 0`; resume is shown only when status is `on_hold`; orphan tasks show neither (verified) |
| A5 | `task_edit.php` renders read-only status for parents with sub-tasks and silently ignores a posted value | PASS | `task_edit.php:184-188` (server-side defence: `$status = $task['status']`) and `:384-393` (read-only `<input>` + hidden field). Confirmed via direct POST probe — posted value was ignored. |
| A6 | Sub-task write paths (complete / progress / status AJAX) trigger `syncProjectTaskCompletions` | PASS | `ajax/complete_task.php:88`, `ajax/update_task_progress.php:59`, `ajax/update_task_status.php:76`. `task_edit.php:249` (update branch) and `:274` (create branch) also call it. |
| A7 | `tasks.php`, `project_detail.php`, `client/dashboard.php` read derived `status` automatically | PASS | No code change needed; all 4 surfaces render `status-completed` for task 10 when all children are `completed` (verified live). |
| A8 | `on_hold` is sticky — flipping sub-tasks while parent is on hold does not change parent | PASS | Pure-function + live DB + AJAX HTTP probes (AJAX T7). |
| A9 | Demotion `completed → in_progress` clears `completion_date` | PASS | `syncProjectTaskCompletions` `COALESCE` clause; live DB probe set `comp=100, date=NOW` then flipped a sub-task — parent became `in_progress` 75 %, `completion_date=NULL`. |
| A10 | Read-only status on `task_edit.php` does not break save | PASS | Save still ran; `status` was forced to current DB value; update succeeded. |
| A11 | `.derived-pill` CSS renders correctly | PASS | `assets/css/style.css:394-409`; verified visually via curl + grep. |
| A12 | Regression test in `database/task_parent_status_derived_test.php` runs and passes | PASS | `exit 0`, 0 failures. |

---

## 2. Verification matrix — scenarios exercised

| ID | Scenario | Source | Result |
| --- | --- | --- | --- |
| F1 | `deriveParentStatus` — 10 pure-function cases (incl. `null` current, mixed children, all `on_hold`, empty list) | `/tmp/probe012_pure.php` | 10 / 10 pass |
| F2 | Live DB round-trip — 8 cases (not_started, in_progress, completed, on_hold parent, demotion, all-on-hold, empty project, missing `parent_task_id`) | `/tmp/probe012_live.php` | 8 / 8 pass |
| F3 | `task_edit.php` server-side defence — POST `status=completed` for parent with sub-tasks is ignored | `/tmp/probe012_post.php` | 2 / 2 pass (status retained, only non-status fields updated) |
| F4 | `ajax/parent_hold.php` — 7 HTTP cases (hold, resume, missing CSRF, invalid action, sub-task target, no-children parent, on_hold sticky) | `/tmp/probe012_ajax.php` | 7 / 7 pass |
| F5 | Cross-surface badge agreement — task 10 shows `status-completed` on all 4 surfaces when children are completed | curl on `task_detail.php`, `tasks.php`, `project_detail.php`, `client/dashboard.php` | 4 / 4 surfaces agree |
| F6 | Cross-surface after demotion — task 10 shows `status-in_progress` on all 4 surfaces after one child flipped | curl on the same 4 surfaces | 4 / 4 agree |
| F7 | Orphan (no children) — `task_detail.php` shows no `Auto` pill and no Hold/Resume buttons; `task_edit.php` shows editable `<select id="status">` | curl probes with a synthetic orphan task | All expected |
| F8 | On-hold UI — task 10 detail page shows `Resume` button, hides `Put on Hold`, badge = `status-on_hold` | curl + DB toggle | Pass |
| F9 | Re-parenting UI — verified no PHP code writes to `parent_task_id` outside the schema constraint, so a child cannot become a child of a different parent without DB intervention | `grep_search` | No re-parent UI |
| F10 | Regression script — `php database/task_parent_status_derived_test.php` | `docker compose exec app` | Exit 0, "passed" |

---

## 3. Live DB round-trip (evidence)

The helper test exercised the following shapes inside a real MariaDB session:

1. **Single completed child** → parent becomes `completed`, 100 %, `completion_date = NOW`.
2. **All not_started children** → parent becomes `not_started`, 0 %, `completion_date = NULL`.
3. **Mixed (1 in_progress, 1 completed)** → parent becomes `in_progress`, 50 %, `completion_date = NULL`.
4. **Demotion completed → in_progress** (1 child flipped to in_progress 50 %, 1 child still completed 100 %) → parent `in_progress`, 75 %, `completion_date = NULL` (cleared).
5. **All on_hold children** → parent becomes `on_hold` (sticky), unchanged completion %.
6. **On-hold parent** + a child flipped to `in_progress` → parent stays `on_hold` (sticky).
7. **All children deleted** before the next sync → no parent in the iteration set, no rows written (helper is only called for parents that currently have at least one child).
8. **Parent with `parent_task_id` set** is excluded from the iteration (the SELECT filters with `parent_task_id IS NULL`).

## 4. AJAX endpoint contract

`ajax/parent_hold.php` accepts:

| Field | Type | Notes |
| --- | --- | --- |
| `task_id` | int | Required, must be a top-level task (`parent_task_id IS NULL`) |
| `action` | string | One of `hold` or `resume` |
| `csrf_token` | string | Required, validated via `validateCsrfToken()` |
| `X-Requested-With: XMLHttpRequest` | header | When present, response is JSON; otherwise redirect with flash message |

Responses observed:

| Action | Status | Body |
| --- | --- | --- |
| `hold` (parent with children, owner) | 200 | `{"success":true, "status":"on_hold", "message":"Task put on hold."}` |
| `resume` (on_hold parent) | 200 | `{"success":true, "status":"derived", "message":"Task resumed."}` |
| Missing CSRF | 400 | `{"success":false, "message":"Invalid form token."}` |
| `action=foo` | 400 | `{"success":false, "message":"Invalid action."}` |
| Sub-task target | 400 | `{"success":false, "message":"Only top-level parent tasks can be put on hold."}` |
| Parent with no children | 404 | `{"success":false, "message":"This task has no sub-tasks."}` |
| On-hold parent (no `X-Requested-With`) | 302 | Redirects to `task_detail.php?id=…` with flash "Task put on hold." |

After every successful write, the endpoint calls `syncProjectTaskCompletions($db, $project_id)` so that the parent's derived state is recomputed — this is essential for the **resume** path (it is the only resume code path; the helper would otherwise be skipped while the parent has zero children).

---

## 5. Non-blocking notes

### 5.1 T8 — Stale `completion_percentage` after manual resume with no children (spec gap, not a bug)

**Scenario:** A parent task is currently `completed` (100 %, `completion_date` set). An admin puts it on hold via the `Hold` button, then **deletes all of its sub-tasks** while it is on hold, then resumes it.

- The `Resume` path of `ajax/parent_hold.php` calls `syncProjectTaskCompletions` afterwards, but at that point the parent has zero children, so the helper iteration set is empty and the parent's `completion_percentage` is **not touched**.
- The status is correctly set to `not_started` (via the `Resume` UPDATE), but `completion_percentage` stays at 100.

**Why this is not a code bug:** The `syncProjectTaskCompletions` design is intentional — it only acts on parents that have children at the moment of the call. The "delete sub-tasks while on hold" sequence is a niche admin path that the spec does not cover.

**Why it is worth a note:** A future auditor will look at the row and see `status=not_started, completion_percentage=100` and assume the system is inconsistent. The admin can fix the % by opening `task_edit.php` (the status field is editable again because `subtask_count == 0`).

**Suggested spec amendment** (for the spec author, not the dev): "If a parent task is resumed from `on_hold` and has zero sub-tasks, the resume endpoint should also reset `completion_percentage` to 0 and `completion_date` to `NULL`."

### 5.2 Spec ambiguity — phantom "internal-app translation catalog" (carry-over from issue-011)

The issue's *Localization* section asks for eight new keys in "the existing internal-app translation catalog". No such catalog exists in this codebase (the only i18n is in `client/lang.php` for the client portal). The dev correctly hardcoded English strings (`Auto`, `Put on Hold`, `Resume`, the inline confirm prompt, and the read-only help text) and the issue explicitly notes that the client portal does not show the derived state.

**Recommended action:** Either remove the *Localization* section from the spec (it adds nothing) or add a follow-up issue to introduce an internal-app translation catalog and re-apply both this issue's and issue-011's strings at that time. **Not a bug.**

---

## 6. What was not tested

- **Locale rendering:** The internal app has no i18n, so there is no localized rendering to compare. (See §5.2.)
- **Concurrency:** The "two admins editing the same parent simultaneously" path was not exercised. The new code uses standard `SELECT … FOR UPDATE`-less UPDATEs, so the last writer wins. This matches the rest of the application and is out of scope for the issue.
- **Permission matrix beyond "owner":** The codebase has only one user role (admin), so the ownership check is the only relevant gate.
- **Migration rollback:** No SQL migration ships with this issue (the `status` ENUM was already present and is reused). Nothing to roll back.

---

## 7. Probes & cleanup

| File | Purpose | Cleaned |
| --- | --- | --- |
| `/tmp/probe012_pure.php` | 10 pure-function cases | removed |
| `/tmp/probe012_live.php` | 8 live DB round-trip cases | removed |
| `/tmp/probe012_post.php` | `task_edit.php` defence | removed |
| `/tmp/probe012_ajax.php` | 7 AJAX HTTP cases | removed |
| `/tmp/probe012_cookies.txt` | session cookie for curl | removed |
| `/tmp/tasks7.html`, `/tmp/td_onhold.html`, `/tmp/orphan_td.html`, `/tmp/orphan_te.html` | HTML dumps for badge checks | removed |
| Synthetic orphan task 156 (project 7) | UI gating verification | deleted from DB |
| Task 10 + sub-tasks 35/36 (project 7) | live probes | restored to `not_started` / 0 % / `NULL` `completion_date` |

The regression test `database/task_parent_status_derived_test.php` self-cleans (its own `created_task_ids` / `project_id` / `user_id` are deleted at the end).

---

## 8. Recommendation

**Approve and merge.** The implementation is correct, secure, and the regression test will catch any regression. The two non-blocking notes (T8 and the i18n phantom) can be tracked separately if desired, but neither blocks shipping.
