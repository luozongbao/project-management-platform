# Issue 053 — Dead CSS rule sweep (Phase 1.6)

**Branch:** `v.2.0`
**Status:** Complete
**Scope:** `assets/css/style.css`, `client/assets/client.css`, `assets/js/subtask-manager.js`, `profile.php`, and two regression tests.

---

## 1. Problem

After the Phase 1.x v2 conversion, `assets/css/style.css` had accumulated a large
body of rules that no longer had **any consumer** anywhere in the repository:

- legacy layout vocabulary (`.navbar`, `.main-content`, `.form-group`, `.card-header`)
- legacy button modifiers (`.btn-primary`, `.btn-sm`, `.btn-block`, …)
- legacy alert variants (`.alert-success`, `.alert-danger`, …)
- legacy card/table blocks superseded wholesale by the v2 component layer
- spacing/utility helpers (`.mt-*`, `.mb-*`, `.text-*`, `.d-*`, …) with zero usages

The file had grown to **984 lines / 22 604 bytes**, of which roughly two thirds was
dead weight. Three concrete problems followed:

| Symptom | Impact |
| --- | --- |
| Dead rules in the cascade | Legacy rules could still win a specificity tie against a v2 rule, silently overriding the new design. |
| Two competing button vocabularies | A developer adding `class="btn btn-primary"` would get an *unstyled* button with no error — the v2 layer only ships `.btn--primary`. |
| Two competing grid vocabularies | `class="form-grid"` renders **one** column in v2 (the 2-column layout is opt-in via `.form-grid--2`). A legacy page using bare `.form-grid` would silently collapse from 2 columns to 1. |

`client/assets/client.css` had the same problem in a smaller form: a complete
legacy task-list block (`.task-item`, `.task-line`, `.tree-indent`, `.task-body`,
`.task-head`, `.task-name`, `.task-desc`, `.task-meta`, `.task-progress`,
`.progress-text`, …) that was retired in **issue-050** when the client portal moved
to the v2 `.task-node` vocabulary.

---

## 2. Root cause

The v2 conversion was done **page by page**. Each page was migrated to the v2
vocabulary, but the old rules were left in place "just in case" another page still
used them. Once every page was migrated, the rules had no remaining consumers —
but nothing ever removed them.

The same page-by-page migration pattern left two **live** inconsistencies behind:

1. `assets/js/subtask-manager.js` still emitted `btn btn-sm btn-success` /
   `btn btn-sm btn-danger`, which are legacy (now-unstyled) class names.
2. `profile.php` still used bare `class="form-grid"`, which v2 renders as a
   single column.

The second one was the more dangerous of the two: it was not visible in the
markup, only in the rendered result — and it would have become the *only* consumer
of the dead `.form-grid` legacy rule had that rule not been removed first.

---

## 3. The fix

### 3.1 `assets/css/style.css` — 90 dead selectors removed

The file was reduced from **984 lines / 22 604 bytes** to **428 lines / 12 397
bytes** (a net −556 lines, −10 207 bytes). A documentation header was added at
the top of the file that maps every removed rule to its v2 replacement, so the
migration path stays discoverable for future maintainers.

Rules were only removed after proving **zero consumers repo-wide**, counting:

- `class="..."` in every `*.php` file
- `class="..."` and `classList.add/remove/toggle('...')` in every `*.js` file
- class names referenced by any *other* stylesheet (those may load earlier)

### 3.2 `client/assets/client.css` — 11 dead selectors removed

The retired issue-050 task block was removed: **30 091 → 28 368 bytes**
(1 723 bytes reclaimed; 916 → 866 lines). Removed selectors:

```
.task-item          .task-item:hover   .task-line       .tree-indent
.task-body          .task-head         .task-name       .task-desc
.task-meta          .task-meta i       .task-progress   .task-progress .progress-bar
.progress-text
```

**Kept (still live):**

- `.task-tree`, `.task-tree ul`, `.task-tree.root` — the client sub-task toggle
  contract pinned by `client/toggle_subtasks_test.php` and issue-010/027.
- `.client-body .progress-bar` / `.client-body .progress-fill` — referenced by
  the public landing page's inline styles.
- `.client-body .task-desc` — the v2 override that still targets the class.
- `.client-body .card`, `.client-body .text-muted`, `.client-body .client-brand`.
- The `.app-about` family (20 rules) — pinned by `about_popup_test.php`.

### 3.3 `assets/js/subtask-manager.js` — button vocabulary aligned

```diff
- class="btn btn-sm btn-success save-subtask"
+ class="btn btn--sm btn--success save-subtask"

- class="btn btn-sm btn-danger delete-subtask"
+ class="btn btn--sm btn--danger delete-subtask"
```

The JS hook classes `save-subtask` / `delete-subtask` are unchanged.

### 3.4 `profile.php` — grid modifier restored

Both form grids were migrated from the legacy 2-column `.form-row` layout to the
v2 grid during an earlier issue, but were left as bare `class="form-grid"`:

```diff
- <div class="form-grid">
+ <div class="form-grid form-grid--2">
```

applied to the **Profile information** card and the **Change Password** card.
This is not a cosmetic change: v2's `.form-grid` is intentionally single-column
and the column count is opt-in (see the note in `v2-components.css` line 25).
Without the modifier, both cards would have collapsed to one column.

---

## 4. Regression tests updated

Two tests pinned CSS rules in the legacy layer. Both were re-pointed at the layer
that now owns the rule, and made **stricter** than before.

### 4.1 `database/task_layout_issue013_test.php`

The test asserted needles such as `.form-grid`, `grid-template-columns: repeat(2, 1fr)`,
`.form-section`, `grid-column: 1 / -1`, `.checkbox`, `.range-row`, `.page-header`
and `.page-actions` were present in `style.css`. All of them are now owned by
`v2-components.css`.

The test now:

- reads **both** `style.css` and `v2-components.css` and accepts a rule from
  either layer for the general needles;
- asserts the *specific* rules with strict regexes against `v2-components.css`:
  - `/\.form-grid--2\s*\{[^}]*grid-template-columns:\s*repeat\(2,\s*1fr\)/s`
  - `/\.form-section\s*\{[^}]*grid-column:\s*1\s*\/\s*-1/s`
  - `/\.checkbox\s*,\s*\.radio\s*\{[^}]*display:\s*flex/s`
  - `/\.checkbox\s+input[^{]*\{[^}]*width:\s*16px[^}]*height:\s*16px/s`
  - the 768px mobile collapse for `.form-grid--2` → `grid-template-columns: 1fr`
- adds a **new guard**: 25 retired selectors (`.form-group`, `.form-row`,
  `.btn-primary`, `.card-header`, `.alert-success`, `.navbar`, `.main-content`, …)
  must not reappear in `style.css`.

The guard strips CSS comments first (`preg_replace('#/\*.*?\*/#s', '', $css)`),
because the new documentation header deliberately *names* the retired rules in
prose — a naive `strpos` check would false-positive on it.

### 4.2 `database/task_status_reasons_test.php`

Sections 10 and 11 previously asserted that `.task-line` declared a full-width
flex basis in **both** stylesheets. `.task-line` was retired in issue-050 and has
**zero** consumers in the repository (verified repo-wide, including all
`*_test.php` files) — the test was pinning a dead class.

The test now:

- section 10 keeps its original intent — `.task-reason` must still declare
  `flex-basis: 100%` / `width: 100%` / `align-self: stretch` in **both**
  `style.css` and `client/assets/client.css` (unchanged);
- section 11 now asserts the **live** v2 classes instead:
  - `/\.task-node__head\s*\{[\s\S]*?\}/is` must contain `flex-wrap: wrap`
  - `/\.task-node__reasons\s*\{[\s\S]*?\}/is` must contain `flex-direction: column`
- adds a **new guard**: `.task-line` must not reappear in either stylesheet
  (comments stripped first).

---

## 5. Verification

### 5.1 Syntax

| File | `php -l` |
| --- | --- |
| `profile.php` | No syntax errors detected |
| `database/task_layout_issue013_test.php` | No syntax errors detected |
| `database/task_status_reasons_test.php` | No syntax errors detected |

### 5.2 Full regression sweep — 20 PASS / 0 FAIL

```
=== TOTAL: 20 PASS / 0 FAIL ===
```

(Baseline is 20 PASS / 0 FAIL; the two rewritten tests are included in the count.)

### 5.3 CSS token resolution

```bash
grep -ohE 'var\(--[a-z0-9-]+' client/assets/client.css | sed 's/var(//' | sort -u > /tmp/u.txt
grep -ohE '(^|[;{ ])--[a-z0-9-]+' assets/css/v2-tokens.css | tr -d ';{ ' | sort -u > /tmp/d.txt
comm -23 /tmp/u.txt /tmp/d.txt
```

**Result: empty** — every `var(--…)` reference resolves against the 103 defined
tokens. No rule was removed that would have left a dangling variable.

### 5.4 Class-resolution check

Every `class="…"` was extracted from the rendered HTML of 13 pages
(dashboard, projects, tasks, contacts, contact_detail, project_detail,
task_detail, task_edit, profile, login, register, forgot_password, client
landing) — **201 distinct classes** — and each was checked against the
concatenated stylesheet set.

| | Count |
| --- | --- |
| Distinct classes | 201 |
| Unresolved | 5 |
| Baseline (pre-change, via `git stash`) | 5 — **identical** |

The 5 unresolved classes are **pre-existing** and byte-identical to the
pre-change baseline, i.e. **zero regressions** introduced by this issue:

- `.ct-projects` — defined only as `.ct-projects__label` in `pages/contacts.css`
- `.quick-complete-btn`
- `.task-reason-card`
- `.tk-table`
- `.tk-table-card`

### 5.5 HTTP status sweep (authenticated)

| Route | Status |
| --- | --- |
| `/dashboard` | 200 |
| `/projects` | 200 |
| `/tasks` | 200 |
| `/contacts` | 200 |
| `/contacts/249` | 200 |
| `/projects/1692` | 200 |
| `/tasks/935` | 200 |
| `/profile.php` | 301 → `/profile` (expected canonical redirect) |
| `/client/dashboard.php?code=…` | 200 |

### 5.6 Red lines — verified intact

| Red line | Check | Result |
| --- | --- | --- |
| `.app-about` family stays in `style.css` | `grep -c 'app-about'` | **17** ✓ |
| `.app-about` family stays in `client/assets/client.css` | `grep -c 'app-about'` | **20** ✓ |
| `share_code` `CHAR(17)` / `generateShareCode()` / `isValidShareCodeFormat()` | untouched | ✓ |
| `id="code" name="code"` field attributes + inline style | untouched | ✓ |
| `assets/js/share-code-format.js` + literal `<script src="/assets/js/…">` on landing | untouched | ✓ |
| `POST action="/"` on the landing form | untouched | ✓ |
| `/client/dashboard.php?code=…` URL | unchanged | ✓ |
| "Rotate code" / `share_code_rotated_at` / 90-day nudge | untouched | ✓ |
| `install.php` favicon tag (exact literal) | untouched | ✓ |
| Client sub-task toggle contract (`#tasks-progress`, `#subtasks-toggle`, `ul.task-tree`, localStorage key) | untouched | ✓ |
| About-popup contract (two `<details>`, `getLatestReleaseSummary(`, no `.md` hrefs) | untouched | ✓ |

### 5.7 `_diag.php` md5 pins — no re-bump required

The pin list is:

```php
$expected_md5 = [
    'config.php'              => '…',
    'client/dashboard.php'    => '…',
    'client/lang.php'         => '…',
    'includes/header.php'     => '…',
    'includes/functions.php'  => '…',
];
```

**None** of the issue-053 changed files
(`assets/css/style.css`, `client/assets/client.css`,
`assets/js/subtask-manager.js`, `profile.php`) appear in that list, so no md5
re-bump was needed and none was performed.

### 5.8 Browser verification (VS Code integrated browser)

Verified visually and via the accessibility tree:

| Page | Result |
| --- | --- |
| `login.php` | v2 auth shell; floating About popup present |
| `profile.php` | v2 shell; **both cards render 2-column** (`form-grid form-grid--2` confirmed in the served HTML) |
| `task_edit.php?id=935` | v2 `.field` wrappers, `.range-row` slider, alerts, danger zone |
| `tasks.php` | table view renders; no retired task classes in the markup |
| `dashboard.php` | stat grid, recent projects table, sidebar all render |
| `projects.php` | search + status filter, 3 project cards with progress / pending / contacts |
| `contacts.php` | search + project filter, 2 contact cards with email / phone / actions |
| `contact_detail.php?id=249` | info card, stat row, projects table, overview |
| `project_detail.php?id=1692` | renders |
| `task_detail.php?id=935` | renders |
| client portal (`?code=388YY-CZV2W-R99BA`) | completion hero 28.3 %, "1 delayed" + "2 on hold" badges, `.task-node__reasons` with the **on-hold** and **delayed** chips correctly nested under "Sub Task3", sub-task toggle button present |

### 5.9 Responsive / overflow audit at 390px

Static scan of all four loaded stylesheet layers plus `assets/css/pages/*.css`
for page-level horizontal-overflow sources:

| Candidate | Finding |
| --- | --- |
| `.table { min-width: 1280px }` | Contained — `.table-wrap { overflow-x: auto }` gives the table its own horizontal scroller. |
| `.auth-aside::after { width: 420px }` | Decorative circle inside `.auth-aside`, which sets `overflow: hidden`. `.auth-aside` is additionally hidden below 992px. |
| `.auth-aside__headline { max-width: 420px }` | A **max**-width — shrinks, never overflows. |

The v2 component layer provides the narrow-viewport collapse rules that the
stripped legacy layer used to duplicate:

```css
@media (max-width: 768px) {
    .form-grid--2, .form-grid--3, .form-grid--4, .grid-2, .grid-3 { grid-template-columns: 1fr; }
    .page-header { flex-direction: column; align-items: stretch; }
    .detail-row { flex-direction: column; gap: var(--sp-1); }
}
```

**No page-level horizontal overflow at 390px.**

---

## 6. Design decisions

1. **Removal, not deprecation.** The retired rules were deleted outright rather
   than kept behind a `.legacy-` prefix. Nothing consumed them, and leaving them
   in place is precisely the ambiguity that caused the problem.

2. **A header documenting the mapping.** The top of `style.css` now lists every
   removed rule next to its v2 replacement. A future contributor who reaches for
   `.btn-primary` finds the answer without digging through git history.

3. **Tests re-pointed, not deleted.** Both rewritten tests kept their original
   *intent* (the form grid must be 2-column; the task reason must be
   full-width) and now assert it against the layer that actually owns the rule,
   with stricter regexes plus a re-introduction guard.

4. **Guard assertions strip CSS comments.** The new documentation header names
   the retired classes in prose; the guards strip comments first so the header
   does not trip them.

5. **`.task-line` treated as retired, not as a live class.** A repo-wide grep
   (including every `*_test.php`) proved zero consumers. The test that pinned it
   was pinning a bug — the class itself had been retired in issue-050.

6. **`form-grid--2` added to `profile.php` explicitly.** Bare `.form-grid` is
   single-column in v2 by design. Relying on a legacy rule to restore two
   columns would have re-created the very coupling this issue removes.

---

## 7. Follow-ups

- The 5 unresolved classes from §5.4 are pre-existing and out of scope for this
  issue; they are candidates for a future cleanup issue.
- `assets/css/style.css` still contains the legacy `body` background/font rules
  (`#333` on `#f8f9fa`). The v2 layer overrides them via tokens, so they are
  harmless, but they could be folded into the token layer in a later phase.
- A future phase could move the remaining `style.css` rules (button, card,
  alert, footer) into `v2-components.css` entirely and retire `style.css`.
