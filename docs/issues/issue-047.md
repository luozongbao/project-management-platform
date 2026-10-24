# Issue 047 — New/Edit form alignment (Project, Contact, Task)

**Status:** Resolved
**Branch:** `v.2.0`
**Reported by:** user ("now the 3 detail page is done, let's move to work on the 3 New/Edit pages. check the mock up and start working.")
**Depends on:** [issue-046](./issue-046.md)

---

## 1. Scope

The three detail pages were aligned to the v2 mockups in [issue-046](./issue-046.md).
This issue covers the remaining three **New/Edit** surfaces:

| Page | Mockup | Live form |
| --- | --- | --- |
| Project | `docs/design/2.0/project-form.html` | `project_edit.php` |
| Contact | `docs/design/2.0/contact-form.html` | `contact_edit.php` |
| Task | `docs/design/2.0/task-form.html` | `task_edit.php` |

The three forms were still on the v1 markup and CSS. Converting them surfaced
**two pre-existing broken delete endpoints** (both returning HTTP 404) and **one
live SQL bug** in the contact-ownership check, all detailed below.

---

## 2. Form markup conversion

All three forms were rebuilt against the mockup's structure using the shared v2
vocabulary from `assets/css/v2-components.css`:

- `.content` → `.breadcrumb` → `.page-header` (icon + `.page-header__text` +
  `.page-header__sub`) → `.page-actions`
- `<form class="card">` with `.card__header` (`.card__title-icon` +
  `<h2>`) → `.card__body` → `.form-grid.form-grid--2` → `.card__footer`
- `.field` / `.input` / `.select` / `.textarea` / `.field .hint` /
  `.field .error-text`
- `.alert.alert--info` / `.alert.alert--warning` / `.alert.alert--danger`
- `.danger-zone` card (danger-tinted header) + `.modal-backdrop` confirm modal

### 2.1 Task form field order

Matches the mockup exactly:

1. `name` (full-width section)
2. `project_id` — a `<select>`; for a **subtask** it becomes a readonly
   `project_display` input plus a hidden `project_id`
3. `description` (full-width section)
4. `status_display` (readonly input + hint)
5. `status` (`<select data-status-select>`; hidden + disabled input when the
   task has subtasks, because a parent's status is **derived**)
6. `completion_percentage` — `.range-row` with a slider
   (`data-range-sync="#completion_percentage"` / `data-range-out="#completion_pct"`),
   a number input and a `<span class="range-value">`
7. `on_hold_reason` (`.form-section`, `data-reason-panel="on_hold"`)
8. `delay_reason` (`.form-section`, `data-reason-panel="delayed"`)
9. `data-delay-hint` (`.form-section`, `.alert.alert--warning`)
10. **Total Task Time** — `.range-row` with a number input, a unit `<select>`
    (hours / mandays) and an **inherit from subtasks** checkbox
11. `responsible_person_id`
12. `contact_person_id`
13. `expected_completion_date`
14. `completion_date`

### 2.2 Removed inline assets

Three inline `<style>` blocks (project, contact, task forms) and all the
per-page inline `<script>` blocks were deleted:

- `handleStatusChange()`, `updatePercentageInput()`, `updatePercentageSlider()`
- the inherit-checkbox handler
- the total-time-unit handler
- the dead `loadProjectContacts()` helper

The responsibilities moved to `assets/js/script.js` (see §4).

### 2.3 `assets/css/pages/forms.css` (new)

Shared styling for all three surfaces: `.form-page`, `.form-page__aside`,
`.danger-zone` (`.card__header` tinted with `--danger-100`, `.card__title-icon`
in `--danger-700`), `.readonly-field`, `.control-row`, `.percentage-input`,
`.percentage-symbol`, `.project-checkboxes`
(`grid-template-columns: repeat(auto-fill, minmax(180px, 1fr))`),
`.no-projects-message`, plus 900px and 640px breakpoints.

`assets/css/pages/task_edit.css` (new) adds `.te-time-hint`, the
`#inherit-sum` / `#inherit-count` numeric read-outs
(`font-variant-numeric: tabular-nums` + `var(--font-mono)`), and the 900px /
640px breakpoints (the `.range-row` wraps and the slider goes full width).

`assets/css/pages/project_edit.css` (new) adds `.pe-meta`, `.pe-rotate-form`,
`.pe-danger-field` and a 900px breakpoint.

---

## 3. New delete endpoints (two pre-existing 404 bugs)

### 3.1 `contact_delete.php` (new)

`project_edit.php` and `contact_edit.php` both offered a delete action that
**did not work**: the link pointed at `contact_delete.php?id=…`, a file that
never existed, and no route was registered — `curl http://localhost/contact_delete.php?id=249`
returned **HTTP 404**.

Created `contact_delete.php` as a POST-only endpoint:

```
POST /contacts/<id>/delete     body: csrf_token, contact_id, confirmation_name
POST /contacts/<slug>/delete   body: (same)
```

The confirmation is the contact's own name, compared with `hash_equals()`.
Routes were added to `includes/Router.php` and `contact_delete` was already
present in the `.htaccess` rewrite list.

### 3.2 `task_delete.php` (new)

Same class of bug: `task_edit.php`'s `confirmDelete()` navigated to
`task_delete.php?id=…`, which did not exist and had no route — **HTTP 404**.

Created `task_delete.php`:

```
POST /tasks/<id>/delete     body: csrf_token, task_id, confirmation_code
POST /tasks/<slug>/delete   body: (same)
```

The confirmation code is the task's runtime-derived code:

```php
$task_code = 'TSK-' . str_pad((string)(int)$task_id, 4, '0', STR_PAD_LEFT);
if (!is_string($confirmation) || !hash_equals($task_code, strtoupper(trim($confirmation)))) {
    redirect('/tasks/' . $task_id . '/edit', 'Confirmation code does not match.', 'danger');
}
```

> The `tasks` table has **no `code` column**, so the code is derived from the
> row id at render time. No schema change was made.

`taskDel` was added to `pmpRouteSlugTable()` and `task_delete` was added to the
`.htaccess` rewrite list.

### 3.3 FK behaviour (checked against the live schema)

| FK | On delete | Consequence |
| --- | --- | --- |
| `tasks.parent_task_id` | `CASCADE` | Deleting a parent removes its whole subtree in one statement — no manual subtask cleanup needed. |
| `tasks.contact_person_id` | `RESTRICT` (no action) | A contact assigned to a task **cannot** be deleted. |

Because of the RESTRICT constraint, `contact_delete.php` first clears the
assignment, matching the mockup's promise that *"Tasks already assigned to them
are kept."*:

```php
$db->query("UPDATE tasks SET contact_person_id = NULL WHERE contact_person_id = ?", [$contact_id]);
$db->query("DELETE FROM project_contacts WHERE contact_id = ?", [$contact_id]);
$db->query("DELETE FROM contacts WHERE id = ?", [$contact_id]);
```

The tasks themselves are kept — only the contact reference is nulled.

### 3.4 SQL bug found and fixed (`c.contact_id`)

The first version of the contact-ownership check joined on a column that does
not exist:

```sql
-- BROKEN — PDOException: Unknown column 'c.contact_id' in 'ON'
JOIN project_contacts pc ON c.contact_id = pc.contact_id
```

The `contacts` table's primary key is `id`; `contact_id` lives on
`project_contacts`. The correct join is:

```sql
JOIN project_contacts pc ON c.id = pc.contact_id
```

This was only caught by actually POSTing to the endpoint — the ownership check
is not reached by any GET.

---

## 4. JavaScript ported to `assets/js/script.js`

The mockup's behaviour was moved out of the per-page inline scripts into the
shared bundle, so all three forms (and future ones) get it for free:

| Initializer | Purpose |
| --- | --- |
| `initModals()` | `.modal-backdrop` open/close via `data-modal-open` / `data-modal-close` |
| `initRangeSync()` | slider ↔ number input via `data-range-sync` / `data-range-out` |
| `initStatusReason()` | toggles `data-reason-panel="on_hold"` / `data-reason-panel="delayed"` from `data-status-select` |
| `initCopy()` | `data-copy` → clipboard write + toast |
| `pmpToast(msg, kind)` | toast stack (`initToastTriggers()` wires the triggers) |

`initStatusReason()` also manages the `required` / `disabled` state of the
reason textareas and hides the `.req` marker when a panel is collapsed:

```js
function initStatusReason() {
    var statusSel = document.querySelector('[data-status-select]');
    if (!statusSel) return;
    var panels = {
        on_hold: document.querySelector('[data-reason-panel="on_hold"]'),
        delayed: document.querySelector('[data-reason-panel="delayed"]')
    };
    var delayHint = document.querySelector('[data-delay-hint]');
    function sync() {
        var v = statusSel.value;
        Object.keys(panels).forEach(function(key) {
            if (!panels[key]) return;
            var show = (key === 'on_hold' && v === 'on_hold') ||
                       (key === 'delayed' && v !== 'completed' && v !== 'on_hold');
            panels[key].hidden = !show;
            var ta = panels[key].querySelector('textarea');
            var lab = panels[key].querySelector('.req');
            if (ta) { ta.required = show; ta.disabled = !show; }
            if (lab) { lab.hidden = !show; }
        });
        if (delayHint) { delayHint.hidden = statusSel.value === 'completed'; }
    }
    statusSel.addEventListener('change', sync);
    sync();
}
```

All initializers are registered in the existing `DOMContentLoaded` handler.

---

## 5. `.share-box` relocated

`.share-box` (and `.share-box code`) lived in `assets/css/pages/project_detail.css`,
but the **project edit form** also renders the share-code rotation card. Since
the class is now used on two pages, it was moved to the shared
`assets/css/v2-components.css` and removed from the page-scoped stylesheet with
an explanatory comment.

---

## 6. `$effective_inherit` de-duplication

The v2 task markup re-assigned `$effective_inherit`:

```php
// BEFORE — duplicated the logic already computed at task_edit.php:110
$effective_inherit = $task['parent_task_id'] ? (int) ($task['inherit_hours'] ?? 0) : 0;
// AFTER — the markup only READS the value computed in the PHP block above
```

The duplicate assignment was removed; `$effective_inherit` is now computed in
exactly one place and read by the markup.

---

## 7. Preserved invariants

- `includes/header.php` — **not modified** (md5 pinned by `about_popup_test.php`:
  `7eef73e262a1896a14055a1e826fba10`).
- `share_code` stays `CHAR(17)` in the 5-5-5 format; `generateShareCode()`,
  `isValidShareCodeFormat()` and `assets/js/share-code-format.js` — untouched.
- `id="code" name="code"` + `pattern` + `placeholder` — untouched.
- The **Rotate code** action, `share_code_rotated_at`, the "Last rotated" line
  and the 90-day nudge — all preserved.
- Rate-limit caps and ordering, AJAX endpoint paths — untouched.
- No `name=` / `id=` renames. The contact form's project checkboxes stayed
  `projects[]` (the v1 field name), not `project_ids[]`.
- No query-parameter renames; `/client/dashboard.php?code=…` unchanged.
- Client-relative asset paths unchanged; the nginx `/client/` block and the
  `.htaccess` denies untouched.
- `.app-about` rules remain in `assets/css/style.css`.

---

## 8. Regression tests updated (4)

Four tests pinned **v1 markup details** that the v2 conversion intentionally
replaced. Each was rewritten to assert the **same intent** against the v2
markup — none were deleted or weakened.

| Test | v1 pin | v2 assertion |
| --- | --- | --- |
| `database/project_start_date_test.php` | `<form method="POST" data-validate>` | POST form whose first hidden field is `csrf_token` |
| `database/task_status_reasons_test.php` | inline `<script>` toggling `on_hold_reason_group` / `delay_reason_group` | either the v1 group ids **or** `data-reason-panel` + `on_hold` / `delayed` + `panels[key].hidden` (the ported `initStatusReason`) |
| `database/task_layout_issue013_test.php` | `<div class="form-section full-row"><h3>Dates</h3>` | field presence: `id="expected_completion_date"` and `id="completion_date"` |
| `database/client_share_code_protection_test.php` | `share-rotation-card` | `share-rotation-card` **or** `share-box` |

Each rewritten assertion carries an `issue-047` comment explaining the v2 change.

---

## 9. Verification

| Check | Result |
| --- | --- |
| `php -l` on `task_edit.php`, `project_edit.php`, `contact_edit.php`, `task_delete.php`, `contact_delete.php` | clean |
| Live fetch `/tasks/new`, `/tasks/1419/edit`, `/tasks/10/edit`, `/projects/new`, `/projects/858/edit`, `/projects/7/edit`, `/contacts/new`, `/contacts/249/edit` | all HTTP 200 |
| `Fatal error` / `Warning:` / `Notice:` / `Deprecated:` grep on live output | 0 hits on all 8 pages |
| Smoke sweep of `/`, `/dashboard`, `/projects`, `/tasks`, `/contacts`, `/profile`, detail pages, `/login`, `/register` | all HTTP 200, 0 errors |
| HTML parse (`/tmp/parse.py`) — mismatches / unclosed | none / none |
| Form tree vs mockup (`/tmp/formtree.py`) | identical for task and contact forms, except the inherit checkbox (correctly absent on a task with no subtasks, present on task 10 which has 3) |
| CSS token resolution (`var(--…)` vs `v2-tokens.css`) | no undefined tokens |
| Class resolution (82 live classes across the 8 form pages) | 0 missing |
| `POST /contacts/<id>/delete` — wrong name / correct name | 302 back to edit / 302 to `/contacts`; contact + `project_contacts` rows gone |
| `POST /tasks/<id>/delete` — wrong code / correct code | 302 back to edit / 302 to `/tasks`; parent **and both subtasks** removed (cascade) |
| Save round-trip (task 1419, contact 249, project 858) | 302 to the detail page, DB values verified, originals restored |
| `includes/header.php` md5 | `7eef73e262a1896a14055a1e826fba10` (unchanged) |
| Regression sweep (`database/*_test.php`, `client/*_test.php`) | **20 PASS / 0 FAIL** |
