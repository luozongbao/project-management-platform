# Fix Task Edit Form Layout + "Put on Hold" Button Position

**Status:** Proposed
**Type:** Bug / UI Polish
**Priority:** Medium
**Affects:** `task_edit.php` (form layout, helper text, "Total Task Time" warning), `task_detail.php` (page-actions / "Put on Hold" position), `assets/css/style.css`

## Problem

After issues 010 / 011 / 012, two UI regressions are visible on
screenshots of the running app:

### (1) `task_edit.php` — form sections are uncontained

The page renders five form sections inside a single white card
(Basic Information, Status & Progress, Effort Estimate, Assignment,
Dates). Today those sections have no real CSS:

- `assets/css/style.css` defines `.form-row` and `.form-row-3`
  (two- and three-column grids) but **not** `.form-grid`,
  `.form-layout`, `.form-section`, `.page-container`,
  `.page-header`, `.page-actions`, or `.task-form` —
  all of which `task_edit.php` uses.
- Without CSS, the five sections render as five wide blocks, each
  with a single `<h3>` heading and a long single-column stack of
  inputs that runs off the right edge of the card. The screenshot
  shows the "Status & Progress" group (`Status`, `Completion
  Percentage`) bleeding past the card border, and the "Assignment"
  group (`Responsible Person`, `Contact Person`) likewise.
- The "Effort Estimate" section is the worst — the "Total Task
  Time" row shows a real PHP warning: **`Warning: Undefined
  variable $effective_inherit /var/www/html/task_edit.php on line
  505`**. The variable is referenced by the form's helper text
  (see [issue-011.md](./issue-011.md) §UX), but it is not
  assigned anywhere on the page. The current code block in
  `task_edit.php` only assigns the variables `$total_time_value`
  and `$selected_time_unit`; the issue-011 draft markup
  references `$effective_inherit` and `$inherit_subtask_hours`
  that the page never sets. The warning is visible on every
  render of the form, regardless of whether the task has
  sub-tasks.
- The "Effort Estimate" section is also visually fragmented:
  `<h3>Effort Estimate</h3>` sits at the top of a single column
  with the "Total Task Time" field stretched to fill the
  full page width, no `estimate-input` styling to keep the
  number and the unit select together, and the
  `field-help` paragraph running full-width below.

### (2) `task_detail.php` — "Put on Hold" button is mis-positioned

For a task that already started (i.e. `status = 'in_progress'`
or `status = 'completed'`), the detail page's action row shows:

```
[Put on Hold]   [Edit Task]   [+ Add Subtask]
```

But the `<button>` for "Put on Hold" is wrapped in
`<form method="POST" action="ajax/parent_hold.php" class="inline-form">`
— and that `inline-form` block sits *above* the "Edit Task" and
"Add Subtask" `<a>` tags in the DOM order rather than alongside
them. In the screenshot the "Put on Hold" button is **under the
breadcrumb / above the buttons**, which is wrong on two counts:

- It is not in the same horizontal group as "Edit Task" /
  "Add Subtask" (the actual actions on this page).
- It is a destructive / state-change action. State changes
  should sit *with* the other actions, not as a separate
  floating button.

For a task that is `on_hold`, the "Resume" button has the same
problem in the same DOM position.

### Why these are problems

- Managers cannot scan the page. The `Status` select bleeding
  past the card border is a content-overflow bug, not a styling
  preference. The browser's native scroll behaviour inside the
  form is a worse experience than the v1.2.0 desktop layout.
- The `Warning: Undefined variable $effective_inherit` line in
  the form is server noise. Even if a manager ignores it, it
  scares them — and `error_log` records it on every page load.
- The "Put on Hold" / "Resume" button outside the actions row
  is hard to discover and easy to misread as a page navigation
  rather than an action. A stakeholder reading "Put on Hold"
  above the title may think it is a category label.

## Goals

- Restore the **two-column** form layout for `task_edit.php`
  using a CSS-defined grid (`.form-grid`), so that the five
  form sections render as a tidy two-column flow inside the
  existing `.card`.
- Stop the form sections from overflowing the card on
  desktop ≥ 1024 px (the current breakpoint that QA is
  testing at). On mobile < 768 px, the layout collapses to
  a single column (the existing
  `@media (max-width: 768px)` pattern).
- Eliminate the
  `Warning: Undefined variable $effective_inherit` PHP
  warning. Two acceptable fixes; this issue authorises
  either:
  1. Remove the references to `$effective_inherit` (and any
     other variables the page does not actually set) from
     the markup so the warning cannot fire, **or**
  2. Initialise the variables explicitly in the page
     (`$effective_inherit = false; $inherit_subtask_hours =
     $task['inherit_subtask_hours'] ?? null;`).
  This issue does not require implementing issue-011's full
  inherit-mode UI; it only requires silencing the warning
  while keeping the form's behaviour as today.
- Move the "Put on Hold" / "Resume" `<button>` into the same
  `.page-actions` group as "Edit Task" and "+ Add Subtask" on
  `task_detail.php`. After the change, the three buttons (or
  four, when on hold: "Resume" replaces "Put on Hold") sit
  side-by-side in the same row, with consistent vertical
  alignment and spacing.
- Make no other layout changes. This is a layout fix, not a
  redesign. The "Task Overview" card, the breadcrumb, the
  page title, the status badge, the subtask list, the "Task
  Details" side column, and the "Recent Activity" card stay
  exactly where they are today.
- No JS, no schema, no SQL, no new translations. This is a
  pure presentation / markup fix.

## Non-goals

- A general redesign of the task edit page. The five-section
  structure stays; the order stays (Basic Information →
  Status & Progress → Effort Estimate → Assignment → Dates);
  the labels stay; the help text stays.
- Implementing the issue-011 inherit-mode checkbox. The form
  text already mentions the rule, but this issue does not add
  the checkbox; it only makes the existing "Effort Estimate"
  section render cleanly.
- Implementing the issue-012 on-hold button styling beyond
  moving it into the actions row. The `btn-outline` /
  `btn-success` classes, the confirm dialog, the
  `inline-form` wrapper, and the existing icon all stay.
- Touching the client portal (`client/dashboard.php`) or any
  other page. The two issues are confined to the internal
  console's task surface.
- Adding new CSS variables, design tokens, or theme files.
  The fix uses the existing palette (`#f8f9fa` card,
  `#007bff` primary, etc.).
- Changing the responsive breakpoint below 480 px. The
  existing `@media (max-width: 768px)` and
  `@media (max-width: 480px)` blocks continue to govern.

## UX

### `task_edit.php` — desktop layout (≥ 1024 px)

After the fix, the form card renders as a **two-column grid**:

```
┌────────────────────────────────────────────────────────────┐
│ Basic Information                  │ Status & Progress     │
│ ─────────────────────              │ ──────────────────    │
│ Task Name                          │ Status                │
│ [_______________________]          │ [Not Started     v]   │
│                                    │                       │
│ Description                        │ Completion Percentage │
│ [_______________________]          │ [─── 0.0 ──] %        │
│ [_______________________]          │                       │
│                                    │                       │
│ Project                            │                       │
│ [Test Project             v]       │                       │
├────────────────────────────────────┼───────────────────────┤
│ Effort Estimate                   │ Assignment            │
│ ──────────────                     │ ───────────           │
│ Total Task Time                    │ Responsible Person    │
│ [______] [Hours v]                 │ [Atipat Lorwongam v]  │
│                                    │                       │
│ (One manday equals 8 hours.        │ Contact Person        │
│ Leave blank if the task has        │ [No Contact Person v] │
│ no estimate; zero is saved but     │                       │
│ is not a usable completion         │ (Select a contact     │
│ weight.)                           │  person from the      │
│                                    │  project contacts)    │
├────────────────────────────────────┴───────────────────────┤
│ Dates                                                      │
│ ─────                                                      │
│ Expected Completion   │ (Completion Date — hidden by      │
│ [_______________]     │  default; shown when status=      │
│                       │  completed)                       │
└────────────────────────────────────────────────────────────┘
```

Rules:

- `.form-grid` is a CSS Grid with two equal columns at ≥ 1024 px
  and a single column at < 768 px.
- `.form-section` is just a `<div>` with margin-bottom and an
  `<h3>` heading; the heading is `font-size: 1rem; color: #333;
  border-bottom: 1px solid #e9ecef; padding-bottom: 8px;` (matches
  the existing `info-heading` style in `client/assets/client.css`
  so the look is consistent across the app).
- Each `.form-section` is a grid child. CSS Grid auto-flow places
  them left-to-right, top-to-bottom: `Basic Information` (1, 1),
  `Status & Progress` (1, 2), `Effort Estimate` (2, 1), `Assignment`
  (2, 2), `Dates` (3, 1) — `Dates` spans the full width because
  it is the only one in the last row. Use
  `grid-column: 1 / -1;` on the `Dates` section to make this
  explicit, so a future fifth section does not break the layout.
- The "Effort Estimate" section is constrained to its column
  width. The `Total Task Time` field uses the existing
  `.estimate-input` class (already styled in the form) which is
  a flex row `[number] [unit-select]`; the number field is
  `flex: 1 1 auto` and the unit select is
  `flex: 0 0 110px`.
- The `.field-help` paragraph below "Total Task Time" reads
  "One manday equals 8 hours. Leave blank if the task has no
  estimate; zero is saved but is not a usable completion
  weight." — exactly the current text. **No change to the
  text; only the `Warning: Undefined variable
  $effective_inherit` is silenced.**

### `task_edit.php` — mobile layout (< 768 px)

Single column. Sections stack vertically in their existing
order. The existing `@media (max-width: 768px)` block
already collapses `.form-row` and `.form-row-3`; this issue
adds `.form-grid { grid-template-columns: 1fr; }` to the
same breakpoint block.

### `task_detail.php` — actions row

After the fix, the page-actions row looks like:

```
                                     [Put on Hold]  [Edit Task]  [+ Add Subtask]
```

or, when the task is `on_hold`:

```
                                     [Resume]       [Edit Task]  [+ Add Subtask]
```

The three (or four) buttons sit in the same
`.page-actions` flex row, with the same `gap` value the
existing buttons already use (8–10 px), vertically aligned,
right-aligned in the same container the title is in.

The `inline-form` wrapper still exists (the `<button>` is
inside a `<form method="POST" action="ajax/parent_hold.php">`
to make the CSRF + project-ownership check work); it just
lives *inside* `.page-actions` instead of *above* it.

For a sub-task (a task whose `parent_task_id IS NOT NULL`),
the "Put on Hold" / "Resume" button is not rendered at all
(both today and after the fix). The detail page for a
sub-task shows only "Edit Task".

For a task that has zero sub-tasks (i.e. the on-hold button
would be useless because there is no parent status to
hold), the button is also not rendered. The condition is
the same as today:
`$task['parent_task_id'] === null && count($subtasks) > 0`.

## Implementation

### CSS — `assets/css/style.css`

Add the following rules. **No other CSS changes are
required.** Place them next to the existing `.form-row`
/ `.form-row-3` rules so future readers can find them
together.

```css
/* Two-column form grid for the task edit form. Used by
   task_edit.php via <div class="form-grid">. Auto-flow
   fills the grid left-to-right, top-to-bottom. Sections
   that should span both columns (e.g. "Dates") get
   grid-column: 1 / -1. Collapses to a single column at
   the same breakpoint the rest of the app collapses. */
.form-grid {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 20px 28px;
    align-items: start;
}

.form-grid .form-section {
    min-width: 0;          /* allow inputs to shrink to the column */
}

.form-grid .form-section.full-row {
    grid-column: 1 / -1;
}

@media (max-width: 768px) {
    .form-grid {
        grid-template-columns: 1fr;
        gap: 0;
    }
}

/* Section heading inside a form-grid section. Matches
   the "info-heading" colour on the client portal so the
   internal and external surfaces feel like one app. */
.form-grid .form-section h3 {
    margin: 0 0 12px 0;
    font-size: 1rem;
    color: #333;
    border-bottom: 1px solid #e9ecef;
    padding-bottom: 8px;
    font-weight: 600;
}
```

`min-width: 0` on the grid child is the standard fix for
"grid item content overflows its track". Without it, a
long input inside a column can stretch the column past
the card edge. The screenshot's "Status" select bleeding
past the card is exactly this bug; the rule fixes it
in one line.

### `task_edit.php` — markup changes

- Wrap the existing
  `<div class="form-grid">…</div>` (the markup is already
  there) so the CSS now actually applies.
- Add `class="full-row"` to the `Dates` section's wrapper
  so it spans both columns.
- Wrap each existing `<div class="form-section">` in a
  `<div class="form-section">` whose first child is the
  existing `<h3>` heading. No structural change to the
  inputs themselves; only the wrapping class and the
  heading.
- In the "Effort Estimate" section, **remove** every PHP
  reference to `$effective_inherit` (and any other
  variables the form does not actually set), OR
  initialise the variable explicitly at the top of the
  page. The author of the issue is indifferent between
  the two; the chosen fix must be commented with a
  single line of context so the next developer who
  refactors the form does not re-introduce the
  warning.

No new inputs. No new buttons. No new sections. The
five-section structure is unchanged.

### `task_detail.php` — markup changes

- Move the `<form class="inline-form">…</form>` for the
  "Put on Hold" / "Resume" button **inside** the
  existing `.page-actions` `<div>`, alongside the
  "Edit Task" and "+ Add Subtask" anchors.
- The button order inside `.page-actions` is left to the
  dev, but the natural order is
  `[Put on Hold | Resume] [Edit Task] [+ Add Subtask]`
  (state-change first, edit second, add third). The
  "Complete Task" quick-action button (when shown) stays
  in the same row.
- The `inline-form` `<form>` wrapper keeps its `display:
  inline-block` styling; the existing
  `.inline-form { display: inline-block; margin: 0; }`
  rule in `style.css` already exists for exactly this
  case. No new CSS.

No other markup changes. The breadcrumb, the title, the
status badge, the "Task Overview" card, the "Subtasks"
card, the "Task Details" side column, and the "Recent
Activity" card are untouched.

## Validation & edge cases

| Case                                                          | Behavior                                                                                                                                              |
| ------------------------------------------------------------- | ----------------------------------------------------------------------------------------------------------------------------------------------------- |
| Desktop ≥ 1024 px                                             | Two-column `.form-grid` layout. All five sections visible. "Dates" spans both columns.                                                                |
| Tablet 768–1023 px                                            | Two-column `.form-grid` layout. The 28 px column gap keeps the columns from feeling cramped.                                                          |
| Mobile < 768 px                                               | Single column. Sections stack in their existing order.                                                                                               |
| Mobile < 480 px                                               | Single column with the existing `padding: 10px` navbar treatment. No new breakpoint.                                                                  |
| Task edit form on a brand-new task                            | Layout looks the same as on an existing task.                                                                                                          |
| Task edit form on a task with sub-tasks (issue-011 territory) | The "Effort Estimate" section renders cleanly. The `$effective_inherit` warning is gone. The form does **not** yet show the inherit checkbox — that ships in issue-011. |
| "Put on Hold" button on a parent with ≥ 1 sub-task            | Button is rendered inside `.page-actions`, alongside "Edit Task" and "+ Add Subtask".                                                                  |
| "Put on Hold" button on a sub-task (`parent_task_id != null`) | Button is not rendered. Action row shows only "Edit Task".                                                                                             |
| "Put on Hold" button on a parent with **0** sub-tasks         | Button is not rendered. Action row shows only "Edit Task" and "+ Add Subtask". The condition `count($subtasks) > 0` already handles this.            |
| "Resume" button on a task that is `on_hold`                   | Same layout as "Put on Hold" — rendered in `.page-actions` next to "Edit Task".                                                                       |
| `inline-form` inside a flex container                         | The form renders as `display: inline-block` (its existing class), which flex treats as an item. The button keeps its size and spacing.               |
| `csrf_token` missing on the "Put on Hold" POST                | Same behaviour as today: `400` from `ajax/parent_hold.php`. The button position does not affect the security path.                                   |
| Form validation failure on save                               | Same as today: the form re-renders with the user's inputs. Layout is unchanged.                                                                       |
| `error_log` on every form load                                | After the fix, `Undefined variable $effective_inherit` does not appear. The error log is clean for `task_edit.php`.                                  |
| Existing CSS classes introduced by issues 010 / 011 / 012     | `.derived-pill`, `.fa-magic` tint, `.inline-form`, and any other new classes are **not touched**. This issue is additive.                           |
| `task_detail.php` for a sub-task that has its own sub-tasks (theoretical, not in the current UI) | The on-hold button remains hidden for any task whose `parent_task_id IS NOT NULL`. Cascade is not in scope. |
| Migration / fresh install                                     | No migration. No schema change.                                                                                                                       |
| Browser zoom 200%                                             | Single-column fallback kicks in at 768 px viewport. The form remains readable.                                                                       |
| Right-to-left languages (the internal app is EN-only today)   | `dir="rtl"` is not in scope, but `.form-grid` uses logical CSS (`gap`, `grid-column: 1 / -1`) so a future RTL switch is a one-liner.                 |

## Acceptance criteria

- `task_edit.php` renders all five form sections inside a
  two-column grid on a 1280×800 viewport. No input overflows
  the card edge. The "Status" select, the "Completion
  Percentage" row, the "Responsible Person" select, and the
  "Contact Person" select all sit comfortably inside the
  right-hand column.
- On a 375 px wide viewport (mobile), all five sections
  stack vertically and remain readable. No horizontal
  scrollbar.
- The `Warning: Undefined variable $effective_inherit` line
  is gone from the rendered form. `error_log` shows no
  `Undefined variable` warnings from `task_edit.php` for the
  form's normal GET path.
- The "Dates" section spans the full width of the form card
  on both desktop and mobile, so the two date fields do not
  feel orphaned.
- The "Total Task Time" field is a single line: a number
  input on the left and a unit select (`Hours` / `Mandays`)
  on the right, sized via the existing `.estimate-input`
  flex row. The field is contained within the column width
  — it does not stretch past the section.
- `task_detail.php` page-actions row contains the "Put on
  Hold" / "Resume" button alongside "Edit Task" and
  "+ Add Subtask" (when those apply). All buttons are
  vertically aligned in the same row.
- For a sub-task, the "Put on Hold" button is still
  absent. For a parent with no sub-tasks, the button is
  still absent. The condition logic is unchanged; only
  the position has moved.
- The "Task Overview" card, the breadcrumb, the title, the
  status badge with the `Auto` pill, the "Subtasks" card,
  the "Task Details" side column, and the "Recent Activity"
  card are visually unchanged from today.
- A regression test
  `database/task_layout_issue013_test.php` is **not
  required** for this issue — it is a CSS/markup fix and
  there is no DB or PHP logic to assert. Instead, a
  manual screenshot test on three viewports
  (1280×800, 768×1024, 375×667) is the verification
  artefact, attached to the PR description.

## Out of scope

- A general redesign of the task edit page. The five-section
  structure stays; the order stays; the labels stay; the
  help text stays.
- Implementing the issue-011 inherit-mode checkbox. The
  `$effective_inherit` variable may stay referenced or be
  removed; either is acceptable. The form does not gain
  the checkbox in this issue.
- Implementing the issue-012 on-hold button styling beyond
  moving it into the actions row. The `btn-outline` /
  `btn-success` classes, the confirm dialog, and the
  existing icon all stay.
- Touching the client portal (`client/dashboard.php`) or any
  other page. The two fixes are confined to
  `task_edit.php` and `task_detail.php`.
- Adding new CSS variables, design tokens, or theme files.
  The fix uses the existing palette.
- Changing the responsive breakpoints. The existing
  `@media (max-width: 768px)` and
  `@media (max-width: 480px)` blocks continue to govern.
- Adding a new font, icon, or asset. The Font Awesome 6
  bundle already loaded by the page is sufficient.
- Cleaning up any other `Undefined variable` warnings on
  the page beyond `$effective_inherit`. If other
  undefined variables surface during the fix, the dev
  should fix them in the same PR (they are the same
  class of bug), but the issue does not require an
  exhaustive scan.

## Implementation notes for the developer

- The simplest path is two PRs: (1) CSS in
  `assets/css/style.css`, (2) markup in `task_edit.php` and
  `task_detail.php`. They can be one PR if the dev
  prefers; the diff is small either way.
- The `min-width: 0` rule on the grid child is the
  single most important CSS line in this fix. Without
  it, a long input (e.g. the "Description" textarea or
  the "Project" select on a long project name) will
  re-introduce the overflow bug. The dev must keep
  that rule.
- The "Dates" section is the only one that should
  span both columns. Marking it with
  `class="full-row"` is the simplest signal. Do **not**
  mark any other section as full-row; the order is
  deliberate.
- When fixing the `Undefined variable
  $effective_inherit` warning, prefer removing the
  reference over initialising the variable. The
  variable is part of an issue-011 markup draft; the
  real inherit-mode UI is still scoped to issue-011
  and the issue-011 dev will introduce the variable
  when they ship the feature. Today, the form's "Effort
  Estimate" section has only the existing "Total Task
  Time" field and its help text. Initialising the
  variable is acceptable but is more code to remove
  later.
- Do not change the `task-form` class or any other
  existing class on the form. The form's `<form>`
  element keeps `class="task-form"`; the grid lives
  inside it.
- The "Put on Hold" / "Resume" button's `inline-form`
  wrapper is load-bearing: it carries the CSRF token
  and the form's `action="ajax/parent_hold.php"`.
  When moving it into `.page-actions`, do not
  restructure the form; just relocate the existing
  `<form>` element. The button, the hidden inputs,
  and the `<i class="fas fa-pause">` / `fa-play` icon
  all stay.
- Run a manual smoke test on the edit page after the
  change: load it, change a field, save, reload, and
  confirm the form re-renders with the same layout.
  This catches any accidental breakage of the
  `$_POST` re-population that `task_edit.php` does
  on validation failure.
- No new translations. The English-only form
  preserves all existing strings verbatim.

## Cross-references

- [issue-010](./issue-010.md) — sub-task toggle on the
  client portal. Different surface, no impact here.
- [issue-011](./issue-011.md) — parent's "Effort
  Estimate" inherit mode. This issue clears the
  `Undefined variable $effective_inherit` warning
  but does not implement the inherit-mode UI;
  that ships in issue-011.
- [issue-012](./issue-012.md) — parent's "Status"
  derived from sub-tasks. This issue moves the
  on-hold / resume button into the actions row
  but does not implement the status derivation
  UI; that ships in issue-012.
