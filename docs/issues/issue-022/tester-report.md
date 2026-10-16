# Issue-022 Tester Report — `task_edit.php` IDOR

**Tester:** GitHub Copilot (Tester mode)
**Date:** 2026-10-16
**Branch:** `issue-022`
**Commits under test:**
- `8f56168 fix(issue-022): tighten task_edit.php POST validation against IDOR`
- `fa37e80 fix(issue-021): remove dead stage-2 code from install.php gate` (dev follow-up to issue-021 deviation 1)

---

## Summary

**All 22 IDOR regression-test assertions PASS.** All 8 manual end-to-end IDOR tests behave as expected (3 ACCEPT for legitimate use, 5 REJECT for attack). All 9 prior regression tests still exit=0 (full regression sweep clean). **One functional minor issue (off-by-one in dev's commit message) and three documentation drifts (low-risk).** The implementation is correct and secure.

**Verdict: SHIP** — but with the doc-drift items addressed (see Deviation §1.3, §1.4, §1.5 below) before tagging v1.8.0.

---

## Files changed (verified vs. spec)

| File | State | Notes |
|------|-------|-------|
| `includes/task_edit_auth.php` | new, 113 lines | 3 boolean helpers; all wire-format safe (`(int)$id` casts, `<= 0` short-circuit) |
| `task_edit.php` | +52 / −17 lines | `require_once` the helper, replace 3 existence-only POST checks with helper calls, tighten `$users` dropdown SELECT |
| `database/task_edit_idor_test.php` | new, 22 assertions, 5 sections | unit tests + source-grep wiring checks |
| `install.php` | unrelated issue-021 follow-up | removed dead stage-2 code per my deviation 1 report |
| `docs/issues/issue-021.md` | unrelated issue-021 follow-up | §5 rewritten to single-stage shipped design |
| `docs/issues/issue-021/tester-prompt.md` | unrelated issue-021 follow-up | row E rewritten, regression count adjusted |

---

## 1. Regression test

`database/task_edit_idor_test.php` — 22 assertions, 5 sections, exit=0:

```
## 1. taskEditCanAssignResponsible — project-owner rule
  [PASS] A can assign a task to themselves (current user is always allowed)
  [PASS] A can assign a task to B (B is a project owner)
  [PASS] A CANNOT assign a task to C (C is a registered user but owns no projects — the ex-employee attack)
  [PASS] Bogus user id is rejected (defence against id=0 / id=99999 forgery)
  [PASS] Empty / zero id is rejected

## 2. taskEditCanAssignContact — project-contact rule
  [PASS] A can assign a contact that is linked to A's project
  [PASS] A CANNOT assign a contact that is only on B's project (cross-user leak)
  [PASS] Bogus contact id is rejected
  [PASS] Empty / zero contact id is rejected

## 3. taskEditCanUseProject — owned-project rule
  [PASS] A can use A's own project
  [PASS] A CANNOT use B's project (project_id forgery)
  [PASS] Bogus project id is rejected
  [PASS] Empty / zero project id is rejected

## 4. task_edit.php source-grep — wiring
  [PASS] task_edit.php requires the helper file
  [PASS] task_edit.php POST validation calls taskEditCanAssignResponsible (not the old existence-only check)
  [PASS] task_edit.php POST validation calls taskEditCanAssignContact (not the old existence-only check)
  [PASS] task_edit.php POST validation calls taskEditCanUseProject
  [PASS] task_edit.php emits the "is not a user you can assign a task to" error
  [PASS] task_edit.php emits the "is not on any of your projects" error
  [PASS] task_edit.php emits the "is not one of your projects" error (project_id branch)

## 5. task_edit.php source-grep — $users dropdown is scoped
  [PASS] task_edit.php $users SELECT is scoped to project owners (no more "every user in the database")
  [PASS] task_edit.php $users SELECT no longer says "SELECT id, name, email FROM users ORDER BY name" (the old global query)

Issue-022 task_edit IDOR regression test passed.
```

The test is well-structured: each helper is exercised against a deterministic 3-user / 2-project / 2-contact fixture seed, with `register_shutdown_function` so a fatal error cannot leak seed rows. Sections 4-5 cover wiring — they would have caught a "I just want to inline the query again" regression. Dev confirms (in commit message) that re-introducing the old existence-only checks makes 6 source-grep assertions fail.

### 1.1 Deviation — commit message off-by-one (LOW priority, functional)

Dev's commit message says "install_wizard_test.php at **28/28** assertions" but the actual count is **27/27** (or 27 PASS / 0 FAIL). Section 5a added **2** new assertions (the "no dead-code probe" + "has the explanatory comment" checks) to the existing 25, giving 27 — not 28. The test is correct; only the commit message is off-by-one. No code change needed; just a doc nit.

**Section breakdown of the install_wizard test as shipped:**
- §1: 1 assertion
- §2: 7 assertions
- §3: 2 assertions
- §4: 4 assertions
- §5: 2 assertions
- §5a: 2 assertions (new — covers the dead-code fix)
- §6: 1 assertion
- §7: 2 assertions
- §7b: 1 assertion
- §8: 5 assertions
- **Total: 27 (was 26 before)**

### 1.2 Deviation — `install.php` file-header comment drift (LOW priority, doc)

`install.php` lines 7-9 still describe the wizard as having "a two-stage check: config.php present OR the `users` table already exists in the configured DB". The implementation is now single-stage (just `file_exists('config.php')`). The header comment should be updated to match §1 of the rewritten issue-021 spec.

**Suggested fix:** replace the "two-stage" phrase with "a single-stage check (`config.php` exists → wizard is done)" and add a one-line reference to `tester-report.md` Deviation 1.

### 1.3 Deviation — spec §3 dropdown query still has the same typo fixed in §1 (LOW priority, doc)

`docs/issues/issue-022.md` §3 (around line 247) shows the `$users` dropdown query as:

```php
"SELECT id, name, email FROM users
 WHERE id = ?
    OR id IN (
        SELECT responsible_person_id FROM projects
        WHERE responsible_person_id = ?    ← still a bound param, but the shipped code uses IS NOT NULL
    )
 ORDER BY name",
[$user_id, $user_id]                       ← 2 placeholders, but the shipped code has 1
```

The **shipped code** (`task_edit.php:62-71`) and the **helper** (`includes/task_edit_auth.php:42-45`) both use `WHERE responsible_person_id IS NOT NULL` with `[$user_id]` — **1 placeholder**, which matches the spec's prose ("other project owners", not "other users who are the current user"). The §3 spec block is stale.

Deviation is documentation-only. Behaviour is correct (in fact the shipped `IS NOT NULL` form is **more correct** than the spec's `= ?` form: the `= ?` would be a no-op tautology since the outer `id = ?` already covers the current user).

**Suggested fix:** update §3 to show the shipped query.

### 1.4 Deviation — spec §3 prose vs. shipped query (LOW priority, doc)

The spec prose at the end of §3 (line 264) says: "the query returns the current user and any **other** project-owning user". The shipped query returns the current user and **all** project owners (including the current user if they own projects — which the current user always does if they have any tasks at all). The "other" word is a slight understatement.

**Suggested fix:** change "any other project-owning user" to "any project-owning user (including themselves)".

### 1.5 No issue — spec §1 query is now correct

`docs/issues/issue-022.md` §1 (around line 191) shows `WHERE responsible_person_id IS NOT NULL` with `[$responsible_person_id, $user_id]` — **2 placeholders** — which matches the shipped helper. This was the dev's typo fix (per the commit message) and the spec is now in sync with the code.

---

## 2. Manual end-to-end IDOR tests

**Setup:** seeded via `docker compose exec db mariadb -uroot` (using `MYSQL_ROOT_PASSWORD`):
- User A (id=1, `luozongbao`) — existing
- User B (id=321, `idor022-...-b`) — created, owns Project B (id=392)
- User C (id=322, `idor022-...-c`) — created, **owns no projects** (the "ex-employee" attack target)
- Project A (id=391, owned by A) — created, contains Task 633
- Contact on A (id=105, linked to Project A) — created
- Contact on B (id=106, linked to Project B) — created

**Drive:** logged in as A via curl session jar `/tmp/pmp_jar_a`, loaded `task_edit.php?id=633`, captured CSRF token, posted forged payloads.

All fixtures were deleted in cleanup (verify below).

### TEST 1 — A assigns task to A (ACCEPT, baseline) — **PASS**

```
POST /task_edit.php?id=633
  project_id=391, responsible_person_id=1, contact_person_id=105, ...
```
Response: `HTTP 302 → Location: task_detail.php?id=633`
DB: `tasks.responsible_person_id = 1` ✓

### TEST 2 — A assigns task to C (REJECT, the headline IDOR) — **PASS**

```
POST /task_edit.php?id=633
  project_id=391, responsible_person_id=322, contact_person_id=105, ...
```
Response: `HTTP 200` (form re-renders with error)
Error message in response body: **"Selected responsible person is not a user you can assign a task to."**
DB: `tasks.responsible_person_id = 1` (unchanged) ✓

This is the spec's main attack. The ex-employee / non-owner user is now correctly rejected.

### TEST 3 — A assigns task to B (ACCEPT, legitimate "another owner") — **PASS**

```
POST /task_edit.php?id=633
  project_id=391, responsible_person_id=321, contact_person_id=105, ...
```
Response: `HTTP 302 → Location: task_detail.php?id=633`
DB: `tasks.responsible_person_id = 321` ✓

The "delegate to a colleague who has their own projects" path works. This is the intentional UX expansion called out in the spec's Risks section.

### TEST 4 — A links task to B's contact (REJECT, cross-user contact IDOR) — **PASS**

```
POST /task_edit.php?id=633
  project_id=391, responsible_person_id=321, contact_person_id=106, ...
```
Response: `HTTP 200` (form re-renders with error)
Error message: **"Selected contact is not on any of your projects."**
DB: `tasks.contact_person_id = 105` (A's contact, unchanged) ✓

### TEST 5 — A moves task to B's project (REJECT, project_id IDOR) — **PASS**

```
POST /task_edit.php?id=633
  project_id=392, responsible_person_id=321, contact_person_id=105, ...
```
Response: `HTTP 200` (form re-renders with error)
Error message: **"Selected project is not one of your projects."**
DB: `tasks.project_id = 391` (A's project, unchanged) ✓

Spec also confirms `$form_project_id = null` is set on this branch so a re-submit cannot leak the rejected id — verified in code (`task_edit.php:160-167`).

### TEST 6 — POST with no CSRF token (REJECT, issue-016 regression check) — **PASS**

```
POST /task_edit.php?id=633
  (no csrf_token field)
```
Response: `HTTP 200` (form re-renders with the CSRF token, the no-CSRF path is the existing issue-016 behaviour)
DB: `tasks` unchanged ✓

This is the issue-016 CSRF gate still in effect. It is upstream of the issue-022 IDOR fix and was not modified by this commit.

### TEST 7 — POST with `responsible_person_id=99999999` (REJECT, integer forgery) — **PASS**

```
POST /task_edit.php?id=633
  responsible_person_id=99999999, ...
```
Response: `HTTP 200` (form re-renders with error)
Error message: **"Selected responsible person is not a user you can assign a task to."**
DB: unchanged ✓

The helper's `(int)$rid; if ($rid <= 0) return false;` short-circuit is for zero, but 99999999 is a valid int and falls through to the SQL check — the SQL `WHERE id = ?` returns no row → helper returns false → caller emits the error. Defence-in-depth confirmed.

### TEST 8 — POST with `responsible_person_id=0` (REJECT, empty / zero short-circuit) — **PASS**

```
POST /task_edit.php?id=633
  responsible_person_id=0, ...
```
Response: `HTTP 200` (form re-renders with error)
Error message: **"Responsible person must be assigned to the task."** (the empty branch, not the IDOR error) ✓

The empty check at `task_edit.php:169` (`if (empty($responsible_person_id))`) fires before the helper, so the user sees the more specific "must be assigned" message. Correct ordering.

---

## 3. GET-time dropdown rendering

Loaded `task_edit.php?id=633` as A and inspected the rendered HTML. The form's `<option value="...">` values are:

| Dropdown | Rendered ids | Expected (A is id=1, B is id=321, C is id=322) | Result |
|----------|--------------|-------------------------------------------------|--------|
| `project_id` | `391` (Project A), `7` (Test Project) | only A's projects | ✓ |
| `responsible_person_id` | `1` (Atipat), `321` (Issue022 B) | only A + project owners — **C is absent** | ✓ |
| `contact_person_id` | `105` (A's contact) | only A's project contacts — B's contact (106) is absent | ✓ |

The GET-time and POST-time views now match: a user cannot see a value the POST handler will reject. This eliminates the "selection silently lost on save" UX bug called out in the spec's Goals.

---

## 4. SQL-level helper verification (pre-flight, before driving curl)

Re-ran the helper SQL directly against the seeded DB to confirm the boolean rules:

| Test | Query | Expected | Actual |
|------|-------|----------|--------|
| A's `$users` dropdown | `SELECT id, name, email FROM users WHERE id = 1 OR id IN (SELECT responsible_person_id FROM projects WHERE responsible_person_id IS NOT NULL) ORDER BY name` | A, B (not C) | rows: 1, 321 ✓ |
| A → B (assign) | `SELECT 'allowed' WHERE EXISTS (SELECT 1 FROM users WHERE id = 321 AND (id = 1 OR id IN (...)))` | allowed | row returned ✓ |
| A → C (assign) | same, with id=322 | rejected | empty ✓ |
| A → B's contact | `SELECT 'allowed' WHERE EXISTS (SELECT 1 FROM contacts c JOIN project_contacts pc ON c.id = pc.contact_id JOIN projects p ON pc.project_id = p.id WHERE c.id = 106 AND p.responsible_person_id = 1)` | rejected | empty ✓ |
| A → A's contact | same, with id=105 | allowed | row returned ✓ |
| A → B's project | `SELECT 'allowed' WHERE EXISTS (SELECT 1 FROM projects WHERE id = 392 AND responsible_person_id = 1)` | rejected | empty ✓ |

All 6 SQL rules behave as the spec mandates. Combined with the end-to-end tests (§2) and the unit tests (§1), the rule is covered at three layers: SQL, helper function, and HTTP endpoint.

---

## 5. Full regression sweep

All 9 prior regression tests still exit=0:

| Test | File | Result |
|------|------|--------|
| contacts_columns | `database/contacts_columns_test.php` | passed (the "Database execute failed" line is the test's own intentional negative assertion — exit=0) |
| project_start_date | `database/project_start_date_test.php` | passed |
| task_completion | `database/task_completion_test.php` | passed |
| task_inherit_subtask_hours | `database/task_inherit_subtask_hours_test.php` | passed |
| task_layout_issue013 | `database/task_layout_issue013_test.php` | passed |
| task_parent_status_derived | `database/task_parent_status_derived_test.php` | passed |
| security_issue016 | `database/security_issue016_test.php` | 75/75 checks passed |
| install_wizard | `database/install_wizard_test.php` | 27/27 passed (was 26/26; dev added 2 in §5a for the dead-code fix per issue-021 deviation 1) |
| **task_edit_idor (NEW)** | `database/task_edit_idor_test.php` | 22/22 passed |
| client/toggle_subtasks | `client/toggle_subtasks_test.php` | passed |

**10/10 regression tests exit=0.**

---

## 6. Issue-021 follow-up (commit `fa37e80`)

Dev adopted the recommendation in my issue-021 tester-report.md Deviation 1 and:
- removed the dead stage-2 code from `install.php` (now a single `file_exists` gate)
- updated the explanatory comment to reference `tester-report.md` Deviation 1
- added §5a to `install_wizard_test.php` to assert the dead code is gone and the new comment is present (2 new assertions, §5a)
- updated `docs/issues/issue-021.md` §5 to describe the shipped single-stage design
- updated `docs/issues/issue-021/tester-prompt.md` row E, regression count, and tests 12-15

Verified by re-running `install_wizard_test.php` (27/27, §5 + §5a pass).

**One follow-up deviation (LOW priority, doc):** `install.php` file-header comment at lines 7-9 still describes the wizard as having a "two-stage check". The implementation is now single-stage. The header should be updated to match §1 of the rewritten issue-021 spec — see §1.2 above.

---

## 7. Cleanup verification

After all manual tests, the DB was restored to pre-test baseline:

```
users:    1 row (Atipat Lorwongam / luozongbao / id=1)
projects: 1 row (Test Project / id=7 / owner=1)
tasks:    5 rows (the original issue-016 test task + 4 from earlier sprints)
contacts: 1 row (Test Contact 016 / id=50)
```

All `idor022-1792084003-*` fixtures (users 321, 322; projects 391, 392; contacts 105, 106; task 633) were deleted in dependency order. No orphan rows in `project_contacts` (verified with a LEFT JOIN check).

No tmp files left behind (`/tmp/te_form*.html`, `/tmp/b_t*.html`, `/tmp/h_t*.txt`, `/tmp/pmp_jar_a` all removed).

---

## 8. Acceptance criteria — final verdict

| Spec AC | Test | Result |
|---------|------|--------|
| A can assign a task to themselves (current behaviour) | TEST 1 | ✓ |
| A **cannot** assign a task to a non-owner (the ex-employee attack) | TEST 2 + SQL Test 3 | ✓ |
| A can assign a task to a contact on one of their projects | TEST 1 (contact=105), SQL Test 5 | ✓ |
| A **cannot** assign a task to a contact on a different user's project | TEST 4 + SQL Test 4 | ✓ |
| A cannot move a task to a project they do not own | TEST 5 + SQL Test 6 | ✓ |
| Dropdown on `task_edit.php` does not show non-owners | §3 dropdown inspection (C id=322 absent) | ✓ |

**6/6 acceptance criteria met.**

---

## 9. Recommendation

**SHIP issue-022** (commit `8f56168`).

The implementation is correct, the regression test is comprehensive (3 layers: SQL → helper → HTTP), and all manual end-to-end attacks are blocked. The only deviations are doc-drift items (the `install.php` header comment, the spec §3 query block, the spec §3 prose "other" word) and one off-by-one in the dev's commit message — none affect behaviour. Address them in a small follow-up commit before tagging v1.8.0, or as a single "docs polish" sweep at release time.

**Also ship issue-021 follow-up** (commit `fa37e80`): the install wizard is now provably single-stage and the regression test pins the design. Same doc-drift caveat (the `install.php` file header).

---

## 10. Memory

Updated `/memories/repo/qa-patterns.md` with an `issue-022` section covering:
- the 3-layer IDOR test pattern (SQL → helper → HTTP)
- the fixture seed / cleanup recipe for `users` + `projects` + `contacts` + `project_contacts`
- the bash history-expansion gotcha (`!` in single-quoted PHP `-r` strings breaks on the host shell)
- the MariaDB FK cleanup ordering gotcha (delete `tasks` before `contacts`/`projects` to avoid `tasks_ibfk_4`)
- the `ariaDB` multi-statement mode quirk (`docker compose exec -T db mariadb -e` runs statements in sequence; if the first one returns 0 rows, the shell sometimes prints the *next* statement's error rather than the first one's success)
- the spec-vs-code drift pattern: dev fixed §1 typo but left §3 with the same typo class
