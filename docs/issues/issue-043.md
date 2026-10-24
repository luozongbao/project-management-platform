# issue-043 — Blank grey card headers on task and contact detail pages

## Summary

`/tasks/935` and `/contacts/249` rendered **blank, light-grey horizontal bars**
where their card headings should be. The four card titles — "Task Details",
"Subtasks", "Contact Overview", "Recent Tasks" — were effectively invisible.

Both pages are now fully converted to the v2 design system.

| | before | after |
|---|---|---|
| `task_detail.php` | 1017 lines | 687 lines |
| `contact_detail.php` | 774 lines | 417 lines |
| inline `<style>` blocks | 349 + 378 lines | 0 |
| new page stylesheet | — | `assets/css/pages/task_detail.css` (213 lines)<br>`assets/css/pages/contact_detail.css` (129 lines) |

---

## Root cause: a v1/v2 class-name collision, not a missing style

The pages were never converted. They still emitted the v1.8.0 class names
`card-header` and `card-body`. Meanwhile `style.css:249` hardcodes:

```css
.card-header { background: #f8f9fa; }
```

`#f8f9fa` is a **light** grey. The v2 dark theme now loads underneath it, so the
page body went dark while the card headers stayed pale — pale slabs with
near-invisible dark text. That is exactly what the screenshots showed.

### Why a rename alone would not have fixed it

The tempting one-line fix is to swap `card-header` → `card__header`. That would
have made it *worse*. None of these pages' own classes have any v2 styling:

- `task_detail.php`: `task-detail-layout`, `subtask-item`, `activity-item`,
  `detail-item`, `progress-bar-large`, `task-reason-card`
- `contact_detail.php`: `contact-detail-layout`, `contact-overview-card`,
  `stat-card`, `task-item`, `detail-item`, `address-content`, `no-projects`

A rename with no accompanying CSS yields a **fully unstyled page** — grey bars
replaced by unstyled text on a dark background. A real conversion was
required: v2 structural classes (`.content`, `.grid-sidebar`, `.card__header`,
`.detail-row`, `.stat-grid`, `.status--*`, `.progress__fill`, `.empty-state`)
plus a new stylesheet for the genuinely page-specific pieces.

---

## Design decisions

### Structural mapping

| v1 | v2 |
|---|---|
| `.page-container` | `.content` (header shell already provides padding) |
| `.contact-detail-layout` / `.main-content` + `.sidebar` | `.grid-sidebar` with two plain `<div>` children |
| `.page-title` | `.page-header__text` |
| `.card-header` / `<h3>` | `.card__header` / `<h2>` |
| `.detail-item` + `<label>` | `.detail-row` + `.detail-row__k` / `.detail-row__v` |
| `.stat-card` / `.stat-content` | `.stat` / `.stat-grid` / `.stat__value` / `.stat__label` |
| `.progress-bar` / `.progress-fill` | `.progress` / `.progress__fill` |
| `.no-projects` | `.empty-state` + `.empty-state__icon` |
| `.status status-<x>` | `.status status--<x>` (**double** hyphen) |

Note the double hyphen: v2 uses BEM `__` for elements and `--` for modifiers.
Writing `.status status-on_hold` parses fine, lints fine, returns HTTP 200 —
and renders with no colour at all.

### Page-specific classes

New classes are prefixed `td-` (`task_detail`) and `cd-` (`contact_detail`) so
they cannot collide with v2 component names. Two of the v1 page classes were
reused verbatim rather than renamed: `.quick-complete-btn` is a **JS hook**
(`assets/js/subtask-manager.js` targets it) and `.timeline` is a **v2
component** whose required markup is `li` + `.timeline__body` + `.timeline__meta`.

---

## Bug found and fixed during the conversion

**Parent Task linked to itself.** `task_detail.php` emitted:

```php
href="/tasks/<?= (int)$task['id'] ?>"
```

for the Parent Task row — the current page's own ID, so the link was a no-op.
The query already selects the correct value:

```sql
SELECT t.*, ..., parent.name as parent_task_name, parent.id as parent_task_id
FROM tasks t ... LEFT JOIN tasks parent ON t.parent_task_id = parent.id
```

Corrected to `href="/tasks/<?= (int)$task['parent_task_id'] ?>"`.

Two other markup bugs were caught by the class-resolution check:
`.status status-on_hold` (should be `status--on_hold`), and a `.timeline` built
from `div.timeline__item` / `__content` / `__date`, none of which exist.

---

## Two "marker" classes deliberately kept

The v2 conversion dropped `task-reason-card` and `derived-pill`. That broke two
regression tests immediately:

```
FAIL task_status_reasons_test.php
  - task_detail.php is missing the .task-reason-card sidebar card
FAIL task_parent_status_derived_test.php
  - task_detail.php is missing 'derived-pill'
```

These are **not** stale assertions like issue-042's. They are behavioural
guards: `.task-reason-card` identifies the issue-034 Status Reason feature and
`derived-pill` identifies the issue-012/025/026 "status derived from
sub-tasks" affordance. The features still work — only the class names moved.

Rather than weaken the tests, both were restored as **additive marker
classes** alongside the v2 styling, with a comment explaining why they exist:

```php
<div class="card task-reason-card">
<span class="badge badge--outline derived-pill" title="...">
```

The lesson: a bespoke class name is load-bearing whenever a test pins it. Check
the suite before renaming, not after.

---

## Validation performed

Regression suite: **20 PASS / 0 FAIL** (the same green state issue-042 reached).

| check | result |
|---|---|
| `php -l` both files | clean |
| inline `<style>` blocks | 0 |
| live `/tasks/935`, `/contacts/249`, `/contacts/50` | HTTP 200, 10–20 KB |
| v1 `card-header` in live HTML | 0 |
| v2 `card__header` in live HTML | 3–5 per page |
| `$use_v2_shell` leaked into output | no |
| page stylesheet linked | yes |
| HTML tag balance (`parse.py`) | 0 mismatches, 0 unclosed |
| every emitted class resolves in CSS | yes |
| every `var(--token)` resolves in `v2-tokens.css` | yes (0 undefined) |
| applied hex values in new CSS | 0 (3 occurrences, all in comments) |
| PHP structure parity vs `HEAD` | `if` 20/20, `endif` 18/18, `foreach` 5/5, `endforeach` 2/2 |

`e(` escaping went 31 → 33 on `contact_detail.php` — two additional `(int)`
casts were added on the `count($contact_id)` / stats paths, so the increase is
intentional, not a regression.

---

## Files changed

| file | change |
|---|---|
| `task_detail.php` | v2 conversion, inline style removed, parent link fixed, 2 marker classes restored |
| `contact_detail.php` | v2 conversion, inline style removed, `rel="noopener"` added to external social links |
| `assets/css/pages/task_detail.css` | new — v2 tokens only |
| `assets/css/pages/contact_detail.css` | new — v2 tokens only |

## Not changed (deliberately)

- `includes/header.php` — untouched (md5 pinned by `about_popup_test.php`)
- `project_edit.php` still contains `card-body` in source, but **none of it
  renders** (verified: 0 occurrences in live HTML). Left alone.