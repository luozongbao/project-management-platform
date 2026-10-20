# Issue-034 — v1.8.0: delayed / on-hold status reasons + dashboard counters + client-portal rendering

> **Status:** spec ready, awaiting dev pickup.
> **Target release:** v1.8.0 (minor bump — new features + a schema change).
> **Severity:** **medium** — UX/informational, not a recovery hotfix. v1.8.0
> ships at the user's pace after v1.7.3.2 is in.
>
> **Author of this spec:** Sa (system-analyst mode). Triaged from the user's
> 5-point requirements list (recapped in §1). The spec proposes concrete
> data-model + UI + AJAX + test changes; the user should review §2 (data
> model — 2 new columns), §3 (UI surface — where the reason is entered
> and where it is shown), and §11 (out-of-scope items) before the dev picks
> it up.
>
> **Upstream dependencies:** none — v1.8.0 is independent of v1.7.3.2.
> v1.7.3.2 ships first (issue-033); v1.8.0 ships after.
>
> **Prior context (carried from issue-033):** prod is
> `https://project.lorwongam.com` (Hostinger shared hosting, doc root
> `/home/u162912405/public_html/`); dev is local docker-compose
> (`http://web.local`); language policy is ENG + 中文 via
> `client/lang.php` and `includes/functions.php::t()`; status enum is
> `('not_started', 'in_progress', 'completed', 'on_hold')`; the project's
> existing release-process gates are described in
> `docs/RELEASE_PROCESS.md` (5 steps + the v1.7.3.2 step 5.7 all-pages
> probe).

---

## Table of contents

1. Problem statement (the user's 5-point requirements)
2. Data model (2 new columns on `tasks`)
3. UI surface (where reasons are entered and where they are shown)
4. "Delayed" semantics — the canonical definition
5. API / AJAX surface (3 new endpoints, 2 modified)
6. Validation rules (what is required, when, and how the server enforces)
7. Migration (the `database/migrations/…` SQL)
8. i18n (3 new `client/lang.php` keys, 1 new `lang.php` key)
9. CSS (3 new classes, 2 modified)
10. Updated `RELEASE.md` and `README.md` for v1.8.0
11. Validation table (manual + scripted)
12. What is explicitly **out of scope**
13. Cross-references
14. Risk and rollback
15. Estimated effort

---

## 1. Problem statement (the user's 5-point requirements)

Recapped from the user's message:

1. **Tasks and sub-tasks can be delayed.**
2. **If a task or sub-task is delayed, OR on hold, a reason must be
   specified.**
3. **The reason must be editable** (the owner can update the reason
   later without having to re-do the status change).
4. **The delay or on-hold reason is shown on the client dashboard**
   (the public, code-keyed view) in red (delayed) or orange (on hold).
5. **Both the owner dashboard and the client dashboard show a count of
   delayed and on-hold tasks + sub-tasks.**

The five requirements decompose into:

- **Requirement 1 + 4 + 5 (delayed detection + client dashboard render
  + counts):** schema-agnostic — a task is "delayed" iff
  `expected_completion_date IS NOT NULL AND expected_completion_date <
  CURDATE() AND status != 'completed'`. The current schema already has
  the 3 fields needed to compute this (no schema change). The new
  behavior is: (a) surface the delayed status in the existing UI with a
  red badge, (b) show the count on both dashboards, (c) require a
  reason when the task is first detected as delayed (see req. 2).

- **Requirement 2 + 3 (reason required + editable):** requires a new
  column on `tasks` (one for the on-hold reason, one for the delay
  reason — see §2 for why 2 columns is cleaner than 1). The reason is
  set the first time the task becomes delayed / on hold (at the moment
  the user changes the status or, for delay, at the moment the dev
  publishes v1.8.0 — see §6.3 for the backfill policy). The reason is
  editable from the task edit page and from the task detail page (so
  owners do not need to navigate to the full edit form just to update a
  reason).

- **Requirement 4 (client dashboard red/orange):** a query-side change
  in `client/dashboard.php` to fetch the reason columns and render them
  in the existing task tree. The reasons are always shown when present
  (the column is nullable — a task with no delay / no on-hold has a
  `NULL` reason).

- **Requirement 5 (dashboards show counts):** 2 new stat-card entries
  on `dashboard.php` (Delayed Tasks count, On Hold Tasks count), 1 new
  block on `client/dashboard.php` (project-level delayed + on-hold
  counts above the task tree).

---

## 2. Data model

Two new nullable `TEXT` columns on the existing `tasks` table:

```sql
ALTER TABLE tasks
  ADD COLUMN on_hold_reason TEXT NULL AFTER status,
  ADD COLUMN delay_reason   TEXT NULL AFTER on_hold_reason;
```

**Why 2 columns and not 1 combined `status_reason` column:**

- **Distinct semantics.** A task can be both on-hold AND delayed with
  different reasons (e.g. "client paused scope" for on-hold, "vendor
  late on part delivery" for delay). One column would force the owner
  to pick one reason or concatenate two, which is awkward to render.
- **Independent edit history.** The 2 reasons are entered at different
  times (on-hold reason at the status-change moment; delay reason at
  the moment the task first crosses into delayed state, or via the
  backfill prompt on v1.8.0 deploy — see §6.3). Two columns let the
  server audit each independently.
- **Client-portal render fidelity.** The client dashboard needs to
  colour the two cases differently (red for delayed, orange for
  on-hold). Two columns keep the render logic trivial:
  `if (delayed && delay_reason) RED; if (on_hold && on_hold_reason)
  ORANGE;`. One column would force a runtime rule for which reason is
  "active" given the current status.
- **No N+1.** Both columns are on the same row as the rest of the
  task fields; the existing queries fetch the task once and the
  reasons ride along. No new joins.

**Why `TEXT NULL` and not `VARCHAR(255)`:**

- The user did not specify a max length. 255 is too tight for a real
  reason ("vendor X has a 2-week backlog on part Y because of the
  customs delay in Shenzhen" is already 80 chars). `TEXT` is
  arbitrarily long in MySQL/MariaDB and the UI will display the full
  text (no truncation).
- `NULL` means "no reason set yet" — distinct from empty string
  (which would mean "reason set to empty"). The validation rules in §6
  enforce that the column is non-NULL when the relevant condition is
  true, and NULL when the relevant condition is false.

**Why not on a separate `task_status_reasons` table:**

- No audit trail requirement (the user did not ask for one). If a
  future issue asks for "show the history of reason changes", that
  becomes a new table.
- No multi-row cardinality (each task has at most one on-hold reason
  and at most one delay reason — a task is on-hold or it isn't; a
  task is delayed or it isn't).

---

## 3. UI surface

### 3.1 Where the reason is entered

**On-hold reason:**

- Entered when the owner clicks the "Put on Hold" button on
  `task_detail.php` (existing flow, the
  `<form action="ajax/parent_hold.php">` block) — the existing flow
  is extended to include a `<input type="text" name="on_hold_reason"
  required>` field above the submit button. If the reason is empty,
  the form is rejected client-side (HTML5 `required`) and
  server-side (PHP check in `ajax/parent_hold.php`).
- For tasks without sub-tasks (which use the AJAX status dropdown
  via `assets/js/subtask-manager.js` + `ajax/update_task_status.php`),
  the same flow applies — the AJAX endpoint accepts the optional
  `on_hold_reason` field. If the new status is `on_hold` and the
  reason is missing, the AJAX endpoint returns a 400 with an
  actionable error and the JS shows a small inline `<input>` for
  the reason (mirrors the existing "complete task" inline prompt
  pattern).
- Editable post-hoc from `task_edit.php` (the new
  `on_hold_reason` `<textarea>` appears in the "Status" form section
  whenever `status = 'on_hold'`; same for `delay_reason` whenever
  the task is currently delayed).
- Editable post-hoc from `task_detail.php` via a new inline-edit
  block (a `<details>` element with the reason `<textarea>` and a
  "Save Reason" button that POSTs to `ajax/update_status_reason.php`
  — see §5.3). This is the **primary** edit path for the reason
  (the detail page is the most common landing page when an owner
  opens a task to triage it).

**Delay reason:**

- Entered when the task **first crosses into the delayed state**. The
  trigger is the task edit page: when `task_edit.php` detects the
  task will be saved with `expected_completion_date < CURDATE() AND
  status != 'completed'` AND no existing `delay_reason`, the page
  shows a "This task is delayed — please specify a reason"
  callout above the "Save" button and a required `<textarea
  name="delay_reason" required>` field.
- Backfill prompt on v1.8.0 deploy — see §6.3 for the one-time
  prompt that surfaces any task that was already delayed before
  v1.8.0 shipped.
- Editable post-hoc from `task_edit.php` and `task_detail.php` in the
  same way as the on-hold reason.

### 3.2 Where the reason is shown

**`client/dashboard.php` (public, code-keyed):**

- Inside the existing `<li class="task-item status-{status}">` block
  in `render_task_tree()` — add a new sub-element that renders the
  reason when present. The element looks like:

  ```html
  <div class="task-reason task-reason-<?= $cssClass ?>">
      <i class="fas fa-<?= $iconClass ?>"></i>
      <?= nl2br(e($reason)) ?>
  </div>
  ```

  Where `$cssClass` is `delay` (red) or `on-hold` (orange) and
  `$iconClass` is `clock` or `pause` respectively. The block is
  rendered only when the reason is non-NULL (a task with no reason
  renders nothing — same as before v1.8.0).
- Above the task tree, add a new project-level summary line:
  "<strong>3 delayed</strong>, <strong>1 on hold</strong>" — counts
  appear inline with the existing project info card (the same
  horizontal info-list that shows "Scope", "Budget", etc.).
- The reason text is rendered with `htmlspecialchars()` then
  `nl2br()` — same pattern as the existing task description
  rendering in `client/dashboard.php`.

**`dashboard.php` (owner):**

- 2 new stat-card entries in the existing `.stats-grid` block (after
  the "Pending Tasks" card, before the "Upcoming Deadlines" card):

  ```html
  <div class="stat-card stat-card-delayed">
      <div class="stat-icon"><i class="fas fa-clock"></i></div>
      <div class="stat-content">
          <h3><?= $delayed_tasks_count ?></h3>
          <p>Delayed Tasks</p>
      </div>
  </div>
  <div class="stat-card stat-card-on-hold">
      <div class="stat-icon"><i class="fas fa-pause"></i></div>
      <div class="stat-content">
          <h3><?= $on_hold_tasks_count ?></h3>
          <p>On Hold Tasks</p>
      </div>
  </div>
  ```

  Both counts are account-wide (across all the owner's projects, same
  as the existing `$uncompleted_tasks` count).

**`task_detail.php` (owner):**

- A new "Status Reason" card on the right column (next to the
  existing "Recent Activity" card) showing the on-hold and/or
  delay reason when present. The card has an inline edit toggle
  (a `<details>` with the `<textarea>` and "Save Reason" button —
  same as §5.3 AJAX endpoint).
- The status badge itself gets a small superscript icon when a
  reason is required-but-missing (the task is on-hold or delayed
  but the reason is NULL): `<i class="fas fa-exclamation-triangle"
  title="Reason required"></i>`. This is a visual cue that the
  task needs attention.

**`tasks.php` (owner task list):**

- Each task row's existing `<span class="status status-{status}">`
  badge gets a small superscript icon when the task is delayed
  (`<i class="fas fa-clock text-danger"></i>`) and/or on-hold
  (`<i class="fas fa-pause text-warning"></i>`). The reason text
  itself is not shown in the list (would clutter the row); the
  owner clicks through to the detail page to see / edit it.
- The filter dropdown (existing, the "Status:" `<select>`) gets
  one new option: "Delayed" (which is a computed filter, not a
  status enum value — implemented as
  `(expected_completion_date < CURDATE() AND status != 'completed')`).

**`task_edit.php` (owner edit form):**

- 2 new `<textarea>` fields in the existing "Status" form section:
  - `on_hold_reason` — visible only when `$task['status'] ===
    'on_hold'`. The form section heading is "On Hold Reason".
  - `delay_reason` — visible only when the task is currently
    delayed (per §4). The form section heading is "Delay Reason".
- Both fields are pre-populated with the existing value, marked
  `required` when the relevant condition is true, and saved to the
  corresponding column on POST.

---

## 4. "Delayed" semantics — the canonical definition

A task is **delayed** iff all 4 of these conditions hold:

1. `expected_completion_date IS NOT NULL` (the task has a planned
   completion date)
2. `expected_completion_date < CURDATE()` (the planned date is in
   the past, evaluated at the SQL server's current date — same as
   the existing `.deadline.overdue` heuristic in `tasks.php` and
   `task_detail.php`)
3. `status != 'completed'` (a completed task cannot be "delayed" —
   it shipped on the planned date or the user accepted the slip —
   either way it's done)
4. `parent_task_id IS NULL` (top-level only — see below) **OR**
   `parent_task_id IS NOT NULL` (sub-task included — see below)

**Both top-level tasks and sub-tasks count.** The existing `.overdue`
class heuristic in `tasks.php` already treats top-level and sub-tasks
the same (the query has no `parent_task_id` filter); v1.8.0 keeps
that behaviour. A delayed sub-task bumps the account-wide counter
on the owner dashboard and shows the red badge on the client
dashboard alongside its parent.

**Why not exclude sub-tasks from the count:** the user's req. 5
says "dashboards show number of delayed … tasks & sub-task". Both
top-level tasks and sub-tasks contribute.

**Why not include completed-but-overdue tasks:** the user's req. 1
is about **active** delay (work that should have happened by now
and did not). A completed task is no longer delayed — it either
shipped (which closes the delay) or it was marked completed after
the slip (which is the owner's choice and a separate concern). The
existing `.deadline.overdue` class heuristic in `tasks.php` already
uses this rule (`status != 'completed'` in the ternary).

**Definition as a SQL fragment** (reused in every query):

```sql
(expected_completion_date IS NOT NULL
 AND expected_completion_date < CURDATE()
 AND status != 'completed')
```

The dev wraps the same fragment in a helper function
`isTaskDelayed(array $task): bool` in `includes/functions.php` so
the PHP-side check stays in lock-step with the SQL-side check. The
helper centralizes the 4 conditions in one place; every PHP page
that needs the check calls the helper, every SQL query that needs
the filter uses the SQL fragment.

---

## 5. API / AJAX surface

### 5.1 New endpoint: `ajax/parent_hold_reason.php`

The existing `ajax/parent_hold.php` endpoint (which handles the
"Put on Hold" / "Resume" forms on `task_detail.php`) is extended
to accept an optional `on_hold_reason` field. **No new endpoint
file** — the existing endpoint is the right place (it already has
the parent-task-with-subtasks check, the CSRF check, and the
audit-log write). The change is:

```php
$on_hold_reason = trim($_POST['on_hold_reason'] ?? '');
if ($action === 'hold' && $on_hold_reason === '') {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error' => 'A reason is required when putting a task on hold.',
    ]);
    exit;
}
$db->execute(
    "UPDATE tasks
     SET status = ?, on_hold_reason = ?
     WHERE id = ?",
    [$new_status, $action === 'hold' ? $on_hold_reason : null, $task_id]
);
```

For `$action === 'resume'`, the existing flow sets
`on_hold_reason = NULL` (the reason is cleared when the task
resumes — same as the `completion_percentage` reset).

The form on `task_detail.php` is extended with the
`<input type="text" name="on_hold_reason" required>` above the
"Put on Hold" button. The input has a `maxlength="500"` (the
soft cap — the column is `TEXT` so no hard DB cap) and a
`placeholder="Why is this task on hold?"`.

### 5.2 Modified endpoint: `ajax/update_task_status.php`

The existing endpoint (handles the AJAX-driven status dropdown on
sub-task rows) is extended to accept the same `on_hold_reason`
field. The change is identical to §5.1: if `$status ===
'on_hold'` and `$on_hold_reason` is empty, return a 400. The
response payload gets a new `reason_prompt` field so the
client-side JS in `assets/js/subtask-manager.js` can show an
inline prompt:

```json
{
  "success": true,
  "task_progress": 75,
  "parent_task_id": 12,
  "parent_progress": 50
}
```

→ on reason required:

```json
{
  "success": false,
  "error": "A reason is required when putting a task on hold.",
  "field": "on_hold_reason"
}
```

The JS in `subtask-manager.js` reads `field` and inserts a
`<input class="inline-reason-prompt">` next to the status
dropdown. The user types the reason, the JS re-POSTs with the
`on_hold_reason` field, and the update goes through.

### 5.3 New endpoint: `ajax/update_status_reason.php`

A new endpoint that handles the inline-edit `<details>` blocks on
`task_detail.php`. POST body:

```json
{
  "csrf_token": "...",
  "task_id": 42,
  "field": "on_hold_reason" | "delay_reason",
  "value": "vendor X has a 2-week backlog"
}
```

Behaviour:

- CSRF check (standard — uses `getCsrfToken()`).
- Auth check (must be logged in).
- Ownership check (the project's `responsible_person_id` must match
  the current user — same as the existing endpoints).
- Field whitelist (`field` must be one of `on_hold_reason` or
  `delay_reason` — never accepts arbitrary column names).
- Value validation (trim; reject empty when the relevant condition
  is true per §6; otherwise accept the trimmed value or NULL if
  the trimmed value is empty).
- Update the row.
- Return the new value (so the JS can re-render the inline edit
  block with the updated text without a full page reload).

The endpoint also clears the superscript "Reason required" icon
on the status badge when the reason is set.

### 5.4 Modified endpoint: `task_edit.php` (not strictly AJAX, but the
same pattern)

The existing `task_edit.php` POST handler is updated to accept the
two new fields:

```php
$on_hold_reason = $_POST['on_hold_reason'] ?? null;
$delay_reason = $_POST['delay_reason'] ?? null;
if ($task['status'] === 'on_hold') {
    $on_hold_reason = trim($on_hold_reason ?? '');
    if ($on_hold_reason === '') {
        $errors[] = 'A reason is required when the status is On Hold.';
    }
}
if (isTaskDelayed($task)) {
    $delay_reason = trim($delay_reason ?? '');
    if ($delay_reason === '') {
        $errors[] = 'A delay reason is required for delayed tasks.';
    }
}
$db->execute(
    "UPDATE tasks
     SET ... , on_hold_reason = ?, delay_reason = ?
     WHERE id = ?",
    [..., $on_hold_reason ?: null, $delay_reason ?: null, $task_id]
);
```

The "This task is delayed — please specify a reason" callout
above the Save button (per §3.1) is rendered when:

- The task is currently delayed (per §4), AND
- The existing `delay_reason` is NULL or empty.

The callout disappears after a successful save.

---

## 6. Validation rules

### 6.1 Server-side (authoritative)

| Condition | Required field | Validation |
| --- | --- | --- |
| `status = 'on_hold'` | `on_hold_reason` | non-empty after `trim()` |
| Task is delayed (per §4) | `delay_reason` | non-empty after `trim()` |
| `status != 'on_hold'` | `on_hold_reason` | set to NULL |
| Task is not delayed | `delay_reason` | set to NULL |

The rules are applied at every write site:

- `task_edit.php` (POST handler) — applies both rules; the
  conditional renders of the `<textarea>` fields per §3.2 mirror
  the rules.
- `ajax/parent_hold.php` — applies the on-hold rule only (the
  endpoint never touches `delay_reason`; that field is set by the
  edit page or by the backfill prompt per §6.3).
- `ajax/update_task_status.php` — applies the on-hold rule only
  (same reasoning; the delay_reason is set on the next edit).
- `ajax/update_status_reason.php` — applies both rules per the
  `field` parameter (the only endpoint that can change
  `delay_reason` outside of `task_edit.php`).

The reason columns are never empty strings (only NULL or non-empty
text). The `trim()` check rejects whitespace-only reasons.

### 6.2 Client-side (UX hint, not authoritative)

- The `<textarea name="on_hold_reason" required>` field on
  `task_edit.php` uses HTML5 `required` for the on-hold case (the
  field is rendered only when `status === 'on_hold'`).
- The `<textarea name="delay_reason" required>` field uses HTML5
  `required` when the task is currently delayed.
- The inline-edit `<details>` blocks on `task_detail.php` show a
  red border + "Required" hint when the JS detects an empty
  submission.
- HTML5 `required` is **not** authoritative — the dev always
  validates server-side per §6.1 because the JS could be disabled
  or tampered with (the same posture the existing CSRF token
  check uses).

### 6.3 Backfill on v1.8.0 deploy

When v1.8.0 ships, any task that is **already delayed** in the
DB but has no `delay_reason` will be flagged with a one-time
backfill prompt. The dev implements this as:

- A new banner at the top of `dashboard.php` (rendered only when
  the user has at least one delayed task with a NULL `delay_reason`):
  "**5 tasks are delayed and missing a reason. Click here to fix.**"
  The link goes to `tasks.php?status=delayed&missing_reason=1`
  (a new filter combination that lists exactly those rows).
- Each row in that filter has an inline "Add reason" `<details>`
  block (same JS endpoint as §5.3, same `<details>` UI as
  `task_detail.php`).
- The banner disappears as soon as the user fills in all
  outstanding reasons (a new query in `dashboard.php` re-checks
  the count on every page load — no caching).

For the on-hold case, no backfill prompt is needed: every
on-hold task was put on hold via the existing flow
(`ajax/parent_hold.php` or `ajax/update_task_status.php`), and
those endpoints already enforce the reason-required rule from
v1.8.0 onward. The only on-hold tasks that could be missing a
reason in the DB are tasks that were put on hold **before**
v1.8.0 shipped — those get the same backfill banner treatment
("**1 task is on hold and missing a reason.**").

The dev-side migration does **not** backfill the reasons
automatically — the user must provide them interactively. The
migration only adds the columns (NULL default); no `UPDATE … SET
delay_reason = …` is run.

---

## 7. Migration

A new file: `database/migrations/20261019_add_task_status_reasons.sql`
(filename follows the `YYYYMMDD_<description>.sql` convention
established by the existing 12 migrations).

```sql
-- Adds the on_hold_reason and delay_reason columns to the tasks
-- table for v1.8.0 (issue-034). Both columns are nullable TEXT;
-- no default value. Existing rows get NULL for both columns —
-- the backfill prompt on v1.8.0 deploy surfaces any delayed /
-- on-hold task that lacks a reason (see
-- docs/issues/issue-034.md §6.3).
--
-- Safe to re-run: idempotent via information_schema guard.
--   docker compose exec -T db mariadb -u"$DB_USER" -p"$DB_PASSWORD" "$DB_NAME" \
--     < database/migrations/20261019_add_task_status_reasons.sql
--
-- MariaDB DDL implicitly commits; do not wrap this migration in a transaction.

SET @col_hold := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'tasks'
      AND COLUMN_NAME = 'on_hold_reason'
);
SET @ddl_hold := IF(@col_hold = 0,
    'ALTER TABLE tasks ADD COLUMN on_hold_reason TEXT NULL AFTER status',
    'SELECT 1');
PREPARE stmt FROM @ddl_hold; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col_delay := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'tasks'
      AND COLUMN_NAME = 'delay_reason'
);
SET @ddl_delay := IF(@col_delay = 0,
    'ALTER TABLE tasks ADD COLUMN delay_reason TEXT NULL AFTER on_hold_reason',
    'SELECT 1');
PREPARE stmt FROM @ddl_delay; EXECUTE stmt; DEALLOCATE PREPARE stmt;
```

Mirrors the existing migration style (idempotent via
information_schema guards, prepared-statement DDL, no transaction
wrapper). The dev runs it as part of the standard release
process (step 5.4 in `docs/RELEASE_PROCESS.md`).

---

## 8. i18n

Three new keys in `client/lang.php` (for the client dashboard
copy):

```php
'dash.delayed' => 'Delayed',
'dash.on_hold' => 'On Hold',
'dash.reason'  => 'Reason',
```

Two new keys in `includes/functions.php` (or wherever the
non-client-portal `lang.php` lives — the dev checks):

```php
'dash.delayed' => 'Delayed Tasks',
'dash.on_hold' => 'On Hold Tasks',
```

The 3 client-portal keys are used in `client/dashboard.php`'s new
project-level summary line. The 2 owner-dashboard keys are used in
the new stat-card entries on `dashboard.php`.

The reason text itself is **not** translated — reasons are
free-form text entered by the owner in their language. No
machine-translation, no `t('dash.reason_format', …)` indirection.

---

## 9. CSS

Three new classes in `assets/css/style.css`:

```css
.task-reason {
    margin-top: 6px;
    padding: 6px 10px;
    border-radius: 4px;
    font-size: 0.9rem;
    display: flex;
    align-items: flex-start;
    gap: 6px;
}
.task-reason.delay   { background: #f8d7da; color: #721c24; border-left: 4px solid #dc3545; }
.task-reason.on-hold { background: #fff3cd; color: #664d03; border-left: 4px solid #ffc107; }
.task-reason i { margin-top: 2px; flex-shrink: 0; }
```

Mirrors the existing `.status-on_hold` palette (`#fff3cd` /
`#664d03`) and the existing `.deadline.overdue` palette (`#f8d7da`
/ `#721c24`) — the colours the existing site uses for "due today
or past" and "on hold", so the new styling is consistent with the
existing vocabulary.

Two new stat-card variants in the same file:

```css
.stat-card.stat-card-delayed .stat-card-icon  { color: #dc3545; }
.stat-card.stat-card-on-hold  .stat-card-icon  { color: #ffc107; }
```

(The existing `.stat-card .stat-icon` rule applies the base
`color`; the two new variants override it for the delayed /
on-hold cases.)

The `client/assets/client.css` file gets the same 3 new
`.task-reason` classes (the client dashboard uses its own scoped
CSS). No client.css changes beyond copying the 3 rules.

---

## 10. Updated `RELEASE.md` and `README.md` for v1.8.0

### 10.1 `RELEASE.md` top entry

```markdown
## v1.8.0 — 2026-10-19 (minor)

- **New:** Tasks and sub-tasks can be flagged as delayed (computed
  from `expected_completion_date < CURDATE() AND status !=
  'completed'`).
- **New:** When a task or sub-task is delayed, OR on hold, a reason
  is required. The reason is editable from the task detail page
  (inline edit) and from the task edit page.
- **New:** The delay / on-hold reason is shown on the public client
  dashboard in red (delay) or orange (on hold).
- **New:** The owner dashboard shows account-wide counts of delayed
  and on-hold tasks + sub-tasks (2 new stat cards).
- **Schema:** `tasks` table gets 2 new nullable `TEXT` columns
  (`on_hold_reason`, `delay_reason`). Migration:
  `database/migrations/20261019_add_task_status_reasons.sql`
  (idempotent).
- **Backfill prompt:** on first login after v1.8.0 ships, any
  pre-existing delayed or on-hold task that is missing a reason
  is flagged on the dashboard with a one-time banner. The banner
  disappears once all missing reasons are filled in.
```

### 10.2 `README.md` Features section

A new bullet under the "Features" heading (or whichever heading
the dev prefers):

```markdown
- Tasks and sub-tasks track their **delayed** and **on-hold**
  status with a required **reason**, surfaced on both the owner
  dashboard and the public client portal.
```

### 10.3 `_diag.php` md5 re-bump

The dev re-bumps **all 5 of the 5 expected md5s** in `_diag.php`
(v1.8.0 touches every file that has an md5 entry):

- `config.php` — `APP_VERSION` bump (v1.7.3.2 → v1.8.0).
- `client/dashboard.php` — new reason-rendering blocks +
  project-level summary line + new query fields.
- `client/lang.php` — 3 new translation keys.
- `includes/header.php` — no code change in v1.8.0, but the md5
  re-bump is required by the v1.7.3.2 release process step 5.7
  (the all-pages probe checks the md5s on every run, so the dev
  re-runs `md5sum` after the deploy and updates the expected
  values).
- `includes/functions.php` — new `isTaskDelayed()` helper.

> **Note on `includes/header.php`:** strictly speaking the file
> is unchanged in v1.8.0, but the all-pages probe in `_diag.php`
> (added in v1.7.3.2) re-checks the md5 on every probe run, so
> the dev re-runs `md5sum` on the deployed file and pastes the
> actual hash into the expected list. The hash may match the
> v1.7.3.2 hash (no code change), but the dev still verifies
> the match — that is the purpose of the probe.

The dev-side drift-guard `database/probe_diag_test.php` is not
modified (the probe source is unchanged).

---

## 11. Validation table (manual + scripted)

The dev runs all 15 checks below before declaring v1.8.0
"shipped".

| # | Check | How | Pass criterion |
| --- | --- | --- | --- |
| 1 | Migration runs clean on dev DB | `docker compose exec -T db mariadb … < database/migrations/20261019_add_task_status_reasons.sql` | No errors; `SHOW COLUMNS FROM tasks` lists `on_hold_reason` and `delay_reason` |
| 2 | Migration is idempotent | Run the same SQL a second time | No errors (the information_schema guard short-circuits the ALTER) |
| 3 | Migration runs clean on prod DB | Same as #1 on the prod shell | Same as #1 |
| 4 | Owner dashboard shows new stat cards | Open `https://project.lorwongam.com/dashboard.php` in browser | 2 new cards visible ("Delayed Tasks", "On Hold Tasks") with the right counts |
| 5 | Client dashboard shows new summary line | Open a share-code URL with at least 1 delayed + 1 on-hold task | The summary line shows "X delayed, Y on hold" with the right counts |
| 6 | Client dashboard renders the reason in red/orange | Same as #1; check the task tree | A delayed task shows the red reason block with the entered text; an on-hold task shows the orange block |
| 7 | `task_edit.php` requires reason on hold | Open a task, set status to On Hold, leave reason empty, click Save | Server-side error: "A reason is required when the status is On Hold." |
| 8 | `task_edit.php` requires reason on delay | Open a task whose `expected_completion_date` is in the past, leave delay_reason empty, click Save | Server-side error: "A delay reason is required for delayed tasks." |
| 9 | Inline reason edit on `task_detail.php` works | Open a delayed task, click the `<details>` "Edit reason" block, change the reason, click Save | The reason text updates without a page reload; the superscript "Reason required" icon disappears |
| 10 | AJAX status change to on_hold without reason returns 400 | From a sub-task row, change status to On Hold via the AJAX dropdown without a reason | The JS shows an inline reason prompt; submitting empty returns 400 |
| 11 | Backfill banner appears for pre-v1.8.0 delayed tasks | After deploy, log in as the user, open the dashboard | A banner "N tasks are delayed and missing a reason" appears (if any exist) |
| 12 | Backfill banner disappears after reasons are filled | Fill in all missing reasons via the inline edit | The banner disappears on next page load |
| 13 | Existing tests still pass | `for t in database/*_test.php; do php "$t" 2>&1 | tail -1; done` (the dev's standard loop) | All tests print "ALL CHECKS PASSED" (or equivalent) |
| 14 | New reason-column-related behaviour matches the spec | New `database/task_status_reasons_test.php` (see §11.1) | All 8 `ok()` assertions pass |
| 15 | All-pages probe from v1.7.3.2 is still green | `php /home/u162912405/public_html/_diag.php` on prod shell | §3.6 row is all-green (✓ OK) |

### 11.1 New dev-side drift-guard test

A new file `database/task_status_reasons_test.php`. Structural /
source-grep verifier (mirrors the existing `task_layout_issue013_test.php`
style). Asserts:

1. The migration file
   `database/migrations/20261019_add_task_status_reasons.sql`
   exists and contains the 2 ALTER TABLE statements.
2. `includes/functions.php` defines `isTaskDelayed()` and the
   helper returns `true` iff the 4 conditions of §4 hold (tested
   with 4 sample rows: a task with no expected_completion_date
   (false), a task with future date (false), a task with past
   date and completed status (false), a task with past date and
   in-progress status (true)).
3. `dashboard.php` queries the 2 new counts and renders the 2 new
   stat cards (`$delayed_tasks_count` and `$on_hold_tasks_count`
   in scope).
4. `client/dashboard.php` queries the 2 new columns and renders
   the `.task-reason` blocks (greps for `on_hold_reason`,
   `delay_reason`, and `task-reason-`).
5. `task_edit.php` has the 2 new `<textarea>` fields with the
   correct `name` attributes (`on_hold_reason`, `delay_reason`).
6. `task_detail.php` has the inline-edit `<details>` block that
   posts to `ajax/update_status_reason.php`.
7. `ajax/parent_hold.php` accepts the `on_hold_reason` field and
   rejects empty values (greps for the validation block).
8. `ajax/update_status_reason.php` exists and has the CSRF +
   ownership + field-whitelist checks (greps for the 3 check
   blocks).

The test runs in <2 seconds (no DB connection — all source-grep
checks).

---

## 12. What is explicitly **out of scope**

- **Reason history / audit trail.** The user did not ask for "show
  the history of reason changes". A future issue can add a
  `task_status_reason_history` table if needed.
- **Email notifications when a task becomes delayed.** A task
  that crosses into the delayed state silently today would still
  cross silently in v1.8.0 (the v1.8.0 change is the required
  reason, not a notification). The dev does **not** add a
  cron-style check for "tasks that became delayed in the last 24
  hours" — a future issue can add that.
- **Per-status-time reason** (e.g. "this task was on hold from
  Jan 5 to Jan 12 for reason X"). A future issue can add this
  if needed; v1.8.0 stores only the **current** reason.
- **Bulk "add reason to all delayed tasks" UI.** The backfill
  banner + per-task inline edit is sufficient; the dev does not
  add a bulk-edit page.
- **Translating the reason text.** Reasons are free-form text in
  the owner's language. No machine-translation, no
  per-language fields.
- **A separate `on_hold_date` or `delayed_date` column.** The
  `updated_at` timestamp on the existing row is sufficient to
  know "when did the task last become on-hold or delayed". No
  new timestamp column.
- **A separate "delayed" status enum value.** Delayed is a
  computed state (per §4), not a stored status. The existing
  `on_hold` enum value remains the only non-progress status;
  delayed is derived.
- **A new dev-side helper for the SQL-side delayed fragment.**
  The SQL fragment is inlined in the queries that need it (the
  existing `project_stats` view, the new `dashboard.php` count
  queries, the new `client/dashboard.php` query). A view or
  helper function would add an abstraction layer without
  measurable benefit at this scale (3 queries total).
- **Renaming `.deadline.overdue` to `.deadline.delayed`.** The
  existing class is fine; the new `task-reason.delay` class
  is for the reason text block, not for the deadline. The two
  styles are distinct (deadline is a small inline date text;
  reason is a multi-line block).

---

## 13. Cross-references

- `docs/issues/issue-012.md` — the original "Put on Hold" button
  for parent tasks with sub-tasks (issue-034 extends this flow
  with the required reason).
- `docs/issues/issue-011.md` — task effort estimates and the
  inherit_subtask_hours flag (independent of v1.8.0 but uses
  the same task-detail UI surface).
- `docs/issues/issue-013.md` — the UI layout fixes (v1.8.0's new
  inline-edit `<details>` block follows the same page-actions
  pattern).
- `docs/issues/issue-033.md` — the v1.7.3.2 hotfix (v1.8.0 ships
  after v1.7.3.2; the `_diag.php` all-pages probe and the
  defined() guard from v1.7.3.2 are prerequisites).
- `docs/RELEASE_PROCESS.md` — step 5.7 (added in v1.7.3.2) is
  the post-probe smoke gate the dev runs after v1.8.0 deploy.
- `database/schema.sql` — the existing `tasks` table definition
  (v1.8.0 adds 2 columns; `schema.sql` is updated to match the
  post-migration state).

---

## 14. Risk and rollback

**Risk:** **low**.

- The schema change is 2 nullable `TEXT` columns. Adding nullable
  columns is online and lock-free in MariaDB 10.11 (the existing
  prod DB version).
- The migration is idempotent (re-runnable without error per §7).
- The PHP changes are additive (new endpoints, new form fields,
  new stat cards). No existing behaviour changes for tasks that
  are not delayed and not on hold (the most common case).
- The CSS changes are additive (3 new classes). No existing rule
  is modified.
- The client-dashboard changes are read-only — the dashboard
  already fetches the task row; the new code adds 2 more columns
  to the SELECT and renders 2 more elements when the columns are
  non-NULL. No client-dashboard functionality is removed.

**Rollback:** if v1.8.0 ships and a regression is reported:

1. Revert the migration by running
   `ALTER TABLE tasks DROP COLUMN delay_reason, DROP COLUMN on_hold_reason;`
   (a one-shot destructive migration; the data is lost — no
   audit trail was kept per §12).
2. Revert the PHP changes in `task_edit.php`, `task_detail.php`,
   `dashboard.php`, `client/dashboard.php`, `tasks.php`,
   `projects.php`, `ajax/parent_hold.php`,
   `ajax/update_task_status.php`, and `assets/js/subtask-manager.js`.
3. Delete the new `ajax/update_status_reason.php` file.
4. Delete the new `database/task_status_reasons_test.php` file.
5. Revert the i18n keys in `client/lang.php` and the non-client
   `lang.php` (if added).
6. Revert the CSS additions in `assets/css/style.css` and
   `client/assets/client.css`.
7. Revert the `RELEASE.md`, `README.md`, `_diag.php`, and
   `schema.sql` changes.
8. Bump `APP_VERSION` back to `v1.7.3.2` in 3 sites.

The rollback is mechanical (each step is a `git revert` of the
matching commit).

---

## 15. Estimated effort

| Task | Effort |
| --- | --- |
| Migration SQL (§7) | 5 min |
| `dashboard.php` 2 new stat cards + counts (§3.2) | 30 min |
| `client/dashboard.php` reason render + summary line (§3.2) | 45 min |
| `task_edit.php` 2 new `<textarea>` fields + validation (§3.2 + §5.4) | 45 min |
| `task_detail.php` inline-edit `<details>` block + reason card (§3.1 + §3.2) | 60 min |
| `ajax/parent_hold.php` accept `on_hold_reason` (§5.1) | 15 min |
| `ajax/update_task_status.php` accept `on_hold_reason` + reason prompt (§5.2) | 30 min |
| New `ajax/update_status_reason.php` (§5.3) | 30 min |
| `assets/js/subtask-manager.js` inline reason prompt (§5.2) | 30 min |
| `tasks.php` filter option + status badge superscript (§3.2) | 20 min |
| `tasks.php` backfill banner filter (`status=delayed&missing_reason=1`) | 15 min |
| `dashboard.php` backfill banner (§6.3) | 20 min |
| `includes/functions.php` `isTaskDelayed()` helper (§4) | 10 min |
| `client/lang.php` 3 new keys (§8) | 5 min |
| Non-client `lang.php` 2 new keys (§8) | 5 min |
| CSS additions to `assets/css/style.css` + `client/assets/client.css` (§9) | 15 min |
| New `database/task_status_reasons_test.php` (§11.1, 8 `ok()` assertions) | 45 min |
| Bump `APP_VERSION` in 3 sites (`config.template.php`, `config.php`, `install.php`) | 5 min |
| Re-bump all 5 `_diag.php` md5s after deploy (§10.3) | 10 min |
| Update `RELEASE.md` + `README.md` v1.8.0 entry (§10) | 15 min |
| Update `schema.sql` to match post-migration state | 5 min |
| Run validation table §11 end-to-end | 30 min |
| Manual smoke test in browser (owner + client portal) | 15 min |

**Total: ~7 hours** (one dev, one focused session, no reviewer
beyond the standard "test passes + manual smoke" gate). The
schema change + the inline-edit UI surface are the two biggest
single items (migration is trivial; the inline-edit JS is the
largest piece of new code).

The 3 dev-side drift-guard tests that run after every deploy
(`about_popup_test`, `install_wizard_test`, `probe_diag_test`)
are not modified in v1.8.0 — the new `task_status_reasons_test`
joins the existing test loop.