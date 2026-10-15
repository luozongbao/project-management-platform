# Fix Page-Actions Row + "Use Sum of Sub-Task Hours" Checkbox

**Status:** Proposed
**Type:** Bug / UI Polish
**Priority:** Medium
**Affects:** `task_detail.php` (`.page-actions` row), `task_edit.php` (`.checkbox-label` row), `assets/css/style.css`

## Problem

After issues 010 / 011 / 012, two **CSS-class** regressions are
still visible on screenshots of the running app. Both are caused
by the same root cause: classes referenced in markup that have
no rules in `assets/css/style.css`.

### (1) `task_detail.php` — the "Put on Hold" button sits on its own row

For a parent task that already started (`status = 'in_progress'`
or `status = 'completed'`) and has at least one sub-task, the
detail page renders two rows in the page-actions area:

```
Row 1:  [Pause] Put on Hold        (full-width, on its own line)
Row 2:  [Pencil] Edit Task   [Plus] Add Subtask
```

In the in-progress-task screenshot, the "Put on Hold" `<button>`
is wrapped in `<form class="inline-form">` (lines ~96–105 of
`task_detail.php`). The form is **inside** the same
`.page-actions` `<div>` as the "Edit Task" and "+ Add Subtask"
`<a>` tags (lines 89 opens, line 130 closes), so the DOM order
is correct. But because `.page-actions` has no CSS, the
container falls back to `display: block`. The first child is
the `<form class="inline-form">` which renders
`display: inline-block`, but inside the form the
`<button class="btn">` inherits the default `<button>` block
layout, so the form's content pushes the form to occupy the
full row width, and the two `<a class="btn">` siblings fall
to the next line.

For a `not_started` task with **no** sub-tasks (e.g. the
"Task1" screenshot), the page shows
`[Check] Complete Task   [Pencil] Edit Task   [Plus] Add
Subtask` in a single row — that is, the row layout works
*without* a flex container because each `<a class="btn">`
and the `<button class="btn">` are inline-block by default
and there is no `<form>` in between. The moment a
`<form class="inline-form">` is inserted, the row breaks.

This is exactly the regression the user is asking about. The
fix is to give `.page-actions` an explicit
`display: flex; flex-wrap: wrap; gap: 10px; align-items:
center;` rule so that:

- The "Put on Hold" / "Resume" `<form>` and the "Edit Task" /
  "Add Subtask" `<a>` tags all sit on the same row.
- On very narrow viewports they wrap to two lines, but the
  wrap is intentional, not a side effect of missing CSS.
- The "Complete Task" quick-action button (when shown, i.e.
  the task has no sub-tasks and is not yet `completed`) sits
  in the same row. This satisfies the user's "at least it
  should be the same place as 'Complete Task' Button"
  requirement: the on-hold button is now in the same row
  position as the complete button.

### (2) `task_edit.php` — the "Use sum of sub-task hours" checkbox and its label are on separate lines

In the "Effort Estimate" section of the edit form, the new
issue-011 checkbox group renders like this in the screenshot:

```
                                        [   ]   <- checkbox, alone on the line
                Use sum of sub-task hours as total task time    <- label text, wrapped
When checked, the total below is locked to the sum of ...
```

The checkbox and the label text are stacked vertically
instead of side-by-side. The markup is correct (see
`task_edit.php` lines 477–489):

```html
<label class="checkbox-label" for="inherit_subtask_hours">
    <input type="checkbox" id="inherit_subtask_hours" ...>
    <span>Use sum of sub-task hours as total task time</span>
</label>
```

The `<input>` and the `<span>` are inline-level siblings
inside a `<label>`. But because `.checkbox-label` has no
CSS, the `<label>` defaults to `display: block` and
browser-default whitespace handling places the two
children on separate visual rows. The fix is to add
`.checkbox-label { display: flex; align-items: center;
gap: 8px; cursor: pointer; }` so the checkbox and the
text are on the same line, vertically aligned, and the
whole label is clickable.

A second, related bug from the same screenshot: the
checkbox itself is horizontally **centered** inside its
column rather than left-aligned. That is the same root
cause — the `<input type="checkbox">` has no CSS, so it
falls back to the browser's default for a stand-alone
form control (centered in its containing block). With
the new `.checkbox-label` flex rule, the checkbox is a
flex child and naturally left-aligns with its text.

### What is **not** in scope

This issue only addresses the two regressions above. The
following are already fixed in the codebase and stay
unchanged:

- `.form-grid` two-column layout on `task_edit.php` —
  already added. Working.
- The `Warning: Undefined variable $effective_inherit`
  at `task_edit.php:505` — already initialised up-front
  in the page. Gone.
- The "Total Task Time" field is a horizontal
  `number + unit select` row at desktop, stacking
  vertically at `<768px`. Working.
- The "Dates" section spans the full form width via
  `.form-section.full-row`. Working.
- The "Status" / "Completion Percentage" / "Responsible
  Person" / "Contact Person" overflow on `task_edit.php`
  — fixed by the `.form-grid { min-width: 0 }` rule. Not
  observed in the new screenshots.

## Goals

- Make `.page-actions` a single horizontal row on desktop,
  holding the "Put on Hold" / "Resume" button, the "Edit
  Task" link, the "+ Add Subtask" link, and (when
  applicable) the "Complete Task" quick-action button, all
  right-aligned in the same container the page title is
  in.
- Make `.checkbox-label` a flex row so the checkbox and
  its text label render on the same line, with the
  checkbox vertically centered with the first line of
  text.
- No changes to data, schema, JS, AJAX endpoints, or
  translations. Pure CSS.
- No changes to the on-hold / resume button's behaviour,
  confirm dialog, or `inline-form` wrapper. The existing
  `ajax/parent_hold.php` flow is unchanged.

## Non-goals

- Implementing the issue-011 inherit-mode behaviour
  beyond what is already in the form. The checkbox +
  computed-total hint is already rendered; this issue
  only fixes its visual layout.
- Implementing the issue-012 status-derivation logic
  beyond what is already in the form. The status field
  is already read-only with the "Auto" hint when the
  task has sub-tasks; this issue does not touch it.
- A general redesign of the page-header / page-actions
  pattern across other pages. Other pages that use the
  same classes (`project_detail.php`,
  `contact_detail.php`, `dashboard.php`, etc.) **will**
  pick up the new CSS automatically because the rules
  are class-scoped, not page-scoped — that is intentional
  and is the right long-term behaviour, but no other
  page is changed in this issue. If a regression shows
  up on another page during QA, it is fixed in a
  follow-up.
- Changing the responsive breakpoint below 480 px. The
  existing `@media (max-width: 768px)` and
  `@media (max-width: 480px)` blocks continue to govern.
- Touching the client portal
  (`client/dashboard.php`) — it has its own
  `client/assets/client.css`.
- Adding a new font, icon, or asset. The Font Awesome 6
  bundle already loaded by the page is sufficient.

## Follow-up

- The four pages that picked up the new global rules
  (`task_edit.php`, `task_detail.php`, `contact_edit.php`,
  `project_edit.php`) still carry per-page `<style>` blocks
  that duplicate most of the new rules. The duplication is
  intentional in this issue (no other page is touched) but
  is a known liability. The follow-up work to strip the
  duplicate per-page rules and consolidate everything in
  `assets/css/style.css` is tracked as
  [issue-014 §2](./issue-014.md#2-collapse-per-page-css-duplication)
  in the v1.6 audit.

## UX

### `task_detail.php` — page-actions row (desktop ≥ 768 px)

After the fix, the page-actions row renders as a single
right-aligned flex row:

```
┌──────────────────────────────────────────────────────────────┐
│  Dashboard / Tasks / Test Project / Task 2                  │
│  ≡ Task 2                                                   │
│                                       [Pause] Put on Hold   │
│                                       [Pencil] Edit Task    │
│                                       [Plus]  Add Subtask   │
└──────────────────────────────────────────────────────────────┘
```

The three buttons (or four, when "Complete Task" applies
because the task has no sub-tasks) sit side-by-side, with
an 8–10 px gap, vertically centered with the title text.
The order inside the row is the same DOM order:

1. "Complete Task" (only if `$task['status'] !==
   'completed'` and `empty($subtasks)`) — green
   `btn-success`.
2. "Put on Hold" or "Resume" (only if
   `$task['parent_task_id'] === null` and
   `count($subtasks) > 0`) — outline / green.
3. "Edit Task" — primary `btn-primary`.
4. "+ Add Subtask" (only if not a sub-task) —
   `btn-secondary`.

This matches the user's request: "at least it should be
the same place as 'Complete Task' Button." The on-hold
button is now in the **same row** as the complete button
would be in, and the same row as Edit / Add Subtask.

For a sub-task (a task whose `parent_task_id` is not
null), the row shows only "Edit Task" — unchanged from
today.

For a parent with **no** sub-tasks and status not
completed, the row shows "Complete Task", "Edit Task",
"+ Add Subtask" — three buttons, no on-hold. Unchanged
from today.

### `task_detail.php` — page-actions row (mobile < 768 px)

On phones, the buttons stack vertically inside a
full-width container. The existing
`@media (max-width: 768px) .btn { width: 100%;
margin-bottom: 10px; }` rule already applies. The new
flex container still uses `flex-direction: row` on
mobile but `flex-wrap: wrap` ensures the buttons wrap
onto multiple lines cleanly when the row is too narrow.

### `task_edit.php` — inherit-mode checkbox (all viewports)

After the fix, the "Effort Estimate" section renders:

```
Effort Estimate
─────────────
[✓] Use sum of sub-task hours as total task time
When checked, the total below is locked to the sum of
this task's sub-task estimates. Leave unchecked to
enter a manual total.

Computed total: 12.0000 hours (from 2 sub-task(s) with
positive estimates).

Total Task Time: [ 12 ] [Hours v]
One manday equals 8 hours. Leave blank if the task has
no estimate; zero is saved but is not a usable
completion weight.
```

The checkbox and its text label are on the **same
line**, vertically centered, with an 8 px gap. The whole
label is clickable (clicking the text toggles the
checkbox — standard `<label>` behaviour). The two
`field-help` paragraphs below the label keep their
existing left-aligned block layout, untouched.

## Implementation

### CSS — `assets/css/style.css`

Add the following rules. **No other CSS changes are
required.** Place the `.page-*` rules near the existing
`.inline-form` rule and the `.checkbox-label` rule near
the existing `.inherit-hint` rule so future readers can
find them together.

```css
/* Page header: two-column flex row, title on the left,
 * actions on the right. On phones the actions wrap
 * below the title. */
.page-header {
    display: flex;
    align-items: flex-start;
    justify-content: space-between;
    gap: 16px;
    margin-bottom: 24px;
    flex-wrap: wrap;
}

.page-header .page-title {
    flex: 1 1 320px;
    min-width: 0;
}

.page-header .page-actions {
    flex: 0 0 auto;
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    gap: 10px;
    justify-content: flex-end;
}

/* A single .page-actions row that is NOT inside a
 * .page-header still needs the flex layout so the
 * children line up horizontally. (Some pages render
 * .page-actions without a .page-header wrapper.) */
.page-actions {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    gap: 10px;
    justify-content: flex-end;
    margin-bottom: 20px;
}

@media (max-width: 768px) {
    .page-header {
        flex-direction: column;
        align-items: stretch;
    }

    .page-header .page-actions {
        justify-content: stretch;
    }

    .page-header .page-actions .btn,
    .page-header .page-actions .inline-form {
        flex: 1 1 auto;
    }
}

/* Checkbox row: input + label text side-by-side, with
 * the whole <label> clickable. Used by the issue-011
 * "Use sum of sub-task hours" checkbox and any future
 * checkbox row. */
.checkbox-label {
    display: flex;
    align-items: center;
    gap: 8px;
    cursor: pointer;
    font-weight: 500;
    color: #333;
    line-height: 1.4;
}

.checkbox-label input[type="checkbox"] {
    flex: 0 0 auto;
    width: 18px;
    height: 18px;
    margin: 0;
    cursor: pointer;
}

.checkbox-label > span {
    flex: 1 1 auto;
    min-width: 0;
}
```

Notes on the rules:

- The `min-width: 0` on `.page-title` is the standard
  flex-overflow fix; without it, a long task name would
  push `.page-actions` off the right edge of the page.
- `flex: 0 0 auto` on `.page-actions` means the actions
  row only takes the space its children need. The
  buttons keep their natural width.
- The `gap: 10px` matches the value used elsewhere in
  the app (`.btn-group` and `.form-row` already use
  8–20 px). 10 px is a good middle value.
- The `flex: 1 1 auto` rule on the mobile buttons
  ensures each button takes an equal share of the row
  when there is more than one button — it pairs with
  the existing `width: 100%` rule on `.btn` at
  `max-width: 768px`. Both rules together give
  full-width stacked buttons on phones and an equal-
  width flex row on very narrow viewports where the
  row is too small for three buttons side by side.
- The `cursor: pointer` on `.checkbox-label` and the
  `<input type="checkbox">` inside it reinforces the
  "click anywhere on the row to toggle" affordance.
  The native `<label>` already does this; the cursor
  is a visual hint, not a functional change.

### `task_edit.php` — markup changes

None. The `.checkbox-label` class is already on the
`<label>` element (line 478). The fix is purely CSS.
The existing `<input>` + `<span>` children are
inline-friendly.

### `task_detail.php` — markup changes

None. The `<form class="inline-form">` is already
inside `.page-actions` (lines 96–124 are inside the
`.page-actions` `<div>` opened on line 89). The fix
is purely CSS.

## Validation & edge cases

| Case                                                                 | Behavior                                                                                                                                                |
| -------------------------------------------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Desktop ≥ 1024 px, parent with sub-tasks, status = `in_progress`    | Single row: `[Pause] Put on Hold   [Pencil] Edit Task   [Plus] Add Subtask`. All right-aligned.                                                          |
| Desktop ≥ 1024 px, parent with sub-tasks, status = `completed`       | Single row: `[Pencil] Edit Task   [Plus] Add Subtask` (no on-hold button; task is already done).                                                          |
| Desktop ≥ 1024 px, parent with sub-tasks, status = `on_hold`         | Single row: `[Play] Resume   [Pencil] Edit Task   [Plus] Add Subtask`. The "Resume" button replaces "Put on Hold".                                       |
| Desktop ≥ 1024 px, parent **with no** sub-tasks, status = `not_started` | Single row: `[Check] Complete Task   [Pencil] Edit Task   [Plus] Add Subtask`. Three buttons.                                                              |
| Desktop ≥ 1024 px, sub-task (parent_task_id != null)                | Single row: `[Pencil] Edit Task` only. No on-hold, no add-subtask, no complete.                                                                            |
| Tablet 768–1023 px, parent with sub-tasks, status = `in_progress`    | Single row, same as desktop. The buttons' natural width fits in the available space at this breakpoint.                                                  |
| Mobile < 768 px                                                      | Buttons stack vertically. Each button is full-width. The "Put on Hold" / "Resume" button is the first item, then "Edit Task", then "+ Add Subtask".     |
| Mobile < 480 px                                                      | Same as above; the existing 10 px navbar padding applies. No new breakpoint.                                                                             |
| Checkbox on the "Effort Estimate" section                            | Checkbox and text label are on the same line, vertically centered, with an 8 px gap. The whole label is clickable.                                       |
| Checkbox when the task has 0 sub-tasks                               | The checkbox is not rendered at all (the existing `<?php if ($is_editing && $subtask_count > 0): ?>` condition). Nothing to test.                       |
| Checkbox when the task has sub-tasks and the saved `inherit_subtask_hours = 0` | Checkbox renders unchecked. Text label is on the same line.                                                                                              |
| Checkbox when the task has sub-tasks and the saved `inherit_subtask_hours = 1` | Checkbox renders checked. Text label is on the same line. The "Total Task Time" number field is `disabled` (existing behaviour).                        |
| `inline-form` button with a long label (e.g. "Put on Hold")          | The button's natural width is preserved; the row does not stretch.                                                                                       |
| Form validation failure on save (`task_edit.php` POST re-render)     | The checkbox renders in its last-saved state (existing behaviour). The label is still on the same line.                                                  |
| `csrf_token` missing on the "Put on Hold" POST                       | Same behaviour as today: `400` from `ajax/parent_hold.php`. The button position does not affect the security path.                                       |
| Browser zoom 200%                                                     | The `.page-actions` flex container wraps cleanly; the buttons fall onto two rows rather than overflowing horizontally.                                   |
| Right-to-left languages (the internal app is EN-only today)          | The `flex-direction: row` is logical; a future `dir="rtl"` switch is a one-liner.                                                                        |
| Other pages that use `.page-header` / `.page-actions`                | The new CSS rules apply class-wide. `project_detail.php`, `contact_detail.php`, `dashboard.php`, `project_edit.php`, `contact_edit.php`, etc. all pick up the flex layout. **No regressions are expected** because the rule only changes layout from "block, no gap" to "flex, 10 px gap" — pages that were already visually working keep working, and pages that had a similar button outside the row will now correctly line it up. If a regression is observed, file a follow-up. |
| `error_log` on every page load                                       | Unchanged. No new PHP warnings are introduced by this issue.                                                                                            |

## Acceptance criteria

- `task_detail.php?id=10` (Task 2, in progress, has
  sub-tasks) shows the "Put on Hold" button in the
  **same row** as "Edit Task" and "+ Add Subtask", all
  three right-aligned, vertically aligned with each
  other, with a 10 px gap between them.
- `task_edit.php?id=10` (Task 2, in progress, has
  sub-tasks) shows the "Use sum of sub-task hours as
  total task time" checkbox and its text label on the
  **same line**, with the checkbox vertically centered
  with the first line of text, an 8 px gap between
  checkbox and text, and the whole label clickable.
- Clicking anywhere on the "Use sum of sub-task hours"
  text label toggles the checkbox (standard
  `<label>` behaviour; verify in the smoke test).
- On a 375 px wide viewport, `task_detail.php` shows
  the buttons stacked vertically, each full-width, in
  the DOM order (Complete → On-Hold → Edit → Add).
- On a 375 px wide viewport, `task_edit.php` shows the
  checkbox and its label on the same line (the line
  is wide enough on a phone); if the text wraps to
  two lines, the checkbox stays vertically centered
  with the first line (the flex `align-items:
  center` rule handles this).
- A regression test
  `database/task_layout_issue013_test.php` is **not
  required** for this issue — it is a CSS-only fix
  and there is no DB or PHP logic to assert. Instead,
  a manual screenshot test on three viewports
  (1280×800, 768×1024, 375×667) is the verification
  artefact, attached to the PR description.

## Out of scope

- Implementing the issue-011 inherit-mode UI. The
  checkbox + computed-total hint is already rendered;
  this issue only fixes its visual layout.
- Implementing the issue-012 on-hold button styling
  beyond moving it into the actions row. The
  `btn-outline` / `btn-success` classes, the confirm
  dialog, and the existing icon all stay.
- Touching the client portal (`client/dashboard.php`)
  or any other page's bespoke stylesheet.
- Adding new CSS variables, design tokens, or theme
  files. The fix uses the existing palette.
- Changing the responsive breakpoint values.
- Adding a new font, icon, or asset.

## Implementation notes for the developer

- The two CSS rule blocks are the entirety of the
  implementation. No PHP or HTML changes are
  required.
- The `.page-header` rule is technically optional for
  this issue — only `.page-actions` is needed to fix
  the reported bug. But the same screenshots show
  that the page title and the page-actions row are
  not vertically aligned, and adding the
  `.page-header` rule at the same time keeps the
  layout consistent for future pages. **If the dev
  prefers to keep this issue minimal**, they may
  drop the `.page-header` block and ship only the
  `.page-actions` + `.checkbox-label` rules. The
  acceptance criteria still pass.
- The `min-width: 0` on `.page-title` is the single
  most important line in the `.page-header` block.
  Without it, a long task name will push
  `.page-actions` off the right edge of the page.
  Keep it.
- The `flex-wrap: wrap` on `.page-actions` is what
  makes the row degrade gracefully on narrow
  viewports. Do not remove it; do not set
  `flex-wrap: nowrap` without testing mobile.
- The `width: 18px; height: 18px;` on the
  `<input type="checkbox">` inside `.checkbox-label`
  is a deliberate touch-target upgrade. The
  browser's default checkbox is 13×13 px, which
  fails WCAG 2.5.5 (Target Size, minimum 44×44 is
  the AAA target, 24×24 is the AA-Sufficient
  target). 18 px is a reasonable compromise — the
  surrounding 8 px gap and the `padding: 12px` on
  the label give a clickable surface of roughly
  34 px. If a stricter WCAG conformance is needed,
  increase to 24×24 in a follow-up. The dev is free
  to adjust the size to 16×16 or 20×20 to taste; the
  only requirement is that the checkbox and label
  are on the same line and the whole label is
  clickable.
- The `cursor: pointer` on `.checkbox-label` is a
  visual hint, not a functional change. Native
  `<label>` already handles the click-to-toggle.
- Run a manual smoke test after the change: load
  `task_detail.php?id=10` and confirm the row; load
  `task_edit.php?id=10` and confirm the checkbox
  row. Then load a sub-task (`task_detail.php?id=1`
  for example) and confirm only "Edit Task"
  appears.
- No new translations. The English-only form
  preserves all existing strings verbatim.

## Cross-references

- [issue-010](./issue-010.md) — sub-task toggle on the
  client portal. Different surface, no impact here.
- [issue-011](./issue-011.md) — parent's "Effort
  Estimate" inherit mode. The `.checkbox-label` rule
  in this issue is the visual fix that complements
  the issue-011 markup. The form's PHP logic (the
  `inherit_sum` / `inherit_count` /
  `effective_inherit` variables, the `disabled`
  attribute, the `inherit-hint` /
  `inherit-empty-hint` paragraphs) is unchanged by
  this issue.
- [issue-012](./issue-012.md) — parent's "Status"
  derived from sub-tasks. The `.page-actions` rule
  in this issue is the visual fix that positions the
  on-hold / resume button added in issue-012. The
  `ajax/parent_hold.php` flow, the CSRF token, and
  the confirm dialog are unchanged.
