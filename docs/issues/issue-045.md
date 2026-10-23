# issue-045 — Mockup parity for task and contact detail pages

## Summary

A fifth mockup-alignment pass over `/tasks/:id` and `/contacts/:id`. The two
pages now match `docs/design/2.0/task-detail.html` and
`docs/design/2.0/contact-detail.html` structurally — same two-column split,
same card order, same card bodies, same shared component vocabulary.

The previous pass compared the live pages against the mockups **by eye** and
concluded they were aligned. They were not. The differences were only
discoverable by parsing both documents into a column/card tree and diffing
the two trees.

---

## What was actually different

| Area | Before | After |
|---|---|---|
| `task_detail.php` — column split | Task Details / Status Reason / Recent Activity were in the **sidebar** | All in the **main** column; sidebar holds only the **Time** card |
| `task_detail.php` — Task Overview body | Bespoke `.td-progress` block + `.td-description` heading | `<p class="text-sm">` description + `.detail-list` rows (Project / Status / Responsible person / Contact person / Total task time) |
| `task_detail.php` — Task Details body | Project / Responsible / Total Time / Contact Person / Created / … | Created / Expected Completion / Completed / **Completion** (progress bar) |
| `task_detail.php` — Subtasks | Bespoke `.td-subtask` blocks | `<ul class="task-tree">` / `.task-node` — the shared vocabulary |
| `contact_detail.php` — column split | Contact Information (and Social Media) were in the **sidebar** | Main column holds Recent Tasks / Contact Information / Social Media; sidebar holds Projects / Overview |
| `contact_detail.php` — stat strip | `.cd-stats` carried `margin-bottom: var(--sp-5)` | 3 equal columns, `margin-bottom: 0` (the gap the screenshot showed) |

---

## Root cause

Two distinct problems, neither of which is a missing CSS rule.

### 1. The shared component vocabulary was available but unused

`assets/css/v2-components.css` already shipped `.task-tree` / `.task-node`
(lines 898–927) — byte-identical to the mockup's copy — because
`project_detail.php` used it. `task_detail.php` had instead grown a bespoke
`.td-subtask*` block during the v1.8.0 era. The mockup had been right all
along; the page was the outlier.

### 2. The columns were assigned by eye, not by structure

Both pages use `.grid-sidebar`, a two-column grid. The mockup's HTML puts five
cards in the left child and one in the right. The live pages had the same
*number* of cards in a different *order* — and because every card is visually
a card, the misplacement read as "roughly right" rather than as a bug.

A DOM-level diff surfaced it immediately:

```
### LIVE tasks/10          ### MOCKUP task-detail
COLUMN 0                   COLUMN 0
   CARD: Task Overview        CARD: Task Overview
   CARD: Subtasks             CARD: Subtasks
   CARD: Task Details         CARD: Task Details
   CARD: Recent Activity      CARD: Status Reason
COLUMN 1                      CARD: Recent Activity
   CARD: Time              COLUMN 1
                                CARD: Time
```

---

## Changes

### `task_detail.php`

- **Column restructure.** Task Details / Status Reason / Recent Activity moved
  from the sidebar into the main column. The sidebar now holds a single
  **Time** card (Total time / Source / Unit), matching the mockup.
- **Task Overview card body** rebuilt as `<p class="text-sm">` + a
  `.detail-list` with the mockup's five rows. The status row also renders the
  `Delayed` chip when the task is past its expected completion date.
- **Task Details card body** reduced to Created / Expected Completion /
  Completed / Completion, where Completion is a `.progress` bar. The mockup
  has no progress bar in the Overview card — its four `progress__fill`
  elements belong to the sub-task tree — so the bar moved here.
- **Subtasks card** rewritten from `.td-subtask` blocks to
  `<ul class="task-tree">` / `<li class="task-node">`, reusing the shared
  vocabulary. The per-subtask quick-complete button (`quick-complete-btn`)
  and edit link are preserved, moved into a new `.task-node__actions` row.
- **Variable hoist.** `$resolved_estimate`, `$inherit_active`,
  `$detail_is_delayed` and friends were computed inline inside the Task
  Details card. The Task Overview card renders *before* it and needs the same
  values, so they moved to the data section above the template.
- **Bug fix:** `formatDate()` does not exist in this codebase — the only date
  helpers are `formatDateTime()` and `formatDateForInput()`. Calling it made
  `/tasks/10` die with a fatal error part-way down the page. Now uses
  `formatDateTime($date, 'M d, Y')`.

### `contact_detail.php`

- **Column restructure.** Contact Information and Social Media moved from the
  sidebar into the main column, directly under Recent Tasks. The sidebar now
  holds Projects and Overview.
- **Stat strip.** `.cd-stats` now pins `grid-template-columns: repeat(3, 1fr)`
  with no top/bottom margin, matching the mockup's inline style. The stray
  bottom margin was the visible gap in the screenshot.

### `assets/css/pages/task_detail.css`

- Deleted the now-orphaned `.td-progress*`, `.td-description*` and
  `.td-subtask*` blocks (10 rules).
- Added `.task-node__name a` (link inherits the node colour), `.task-node__name
  a:hover` and `.task-node__actions`.

### `assets/css/pages/contact_detail.css`

- `.cd-stats` rewritten to the mockup's three-column, zero-margin form.

---

## Preserved invariants

The v1.8.0 marker classes that the test suite pins all survive the markup
change:

- `task-reason-card` — Status Reason card
- `derived-pill` — "status follows sub-tasks" affordance in the Task Overview
  header
- `quick-complete-btn` — per-subtask quick-complete button
- `td-reason-flag` — the superscript "reason missing" mark on the status badge

`includes/header.php` was not modified. No route, query parameter, form
field name or `id` was renamed. No database schema change.

---

## Verification

| Check | Result |
|---|---|
| `php -l` on both pages | clean |
| HTML structure (`/tmp/parse.py`) on 5 live pages | 0 mismatches, 0 unclosed |
| Column/card tree vs mockup (`/tmp/cols.py`) | identical for both pages |
| Class resolution (every emitted class resolves to a rule) | only the 4 intentional JS/test marker classes unresolved |
| CSS token resolution | all `var(--*)` resolve |
| Regression sweep | **20 PASS / 0 FAIL** |

Live pages verified: `/tasks/10` (3 subtasks), `/tasks/935` (on hold),
`/contacts/50`, `/contacts/249`, `/projects/858`.

---

## Notes

The mockups are the authority for both markup **and** CSS. When a mockup
uses a class this project does not have, the answer is not to invent a
page-local equivalent — it is to find the component we already ship under a
different page (`.table--fit` and `.task-tree` were both already present) or
to port the rule verbatim.

Diffing the live page against the mockup by eye is not sufficient. Both
failure modes here — a misassigned column and a stray margin — look correct
in a screenshot and are obvious in a parsed tree.
