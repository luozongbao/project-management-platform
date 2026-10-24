# Issue 046 — Task sidebar stat strip + Contact Overview alignment

**Status:** Resolved
**Branch:** `v.2.0`
**Reported by:** user (mockup comparison against live `/tasks/1419` and `/contacts/249`)

---

## 1. Task detail: missing "Completion" and "Subtasks" above the "Time" card

### Symptom

The v2 task-detail mockup (`docs/design/2.0/task-detail.html`) opens its sidebar
column with a two-up stat strip — **Completion** (`fa-chart-pie`, `stat--success`)
and **Subtasks** (`fa-diagram-project`) — and only then renders the **Time**
card. The live page rendered the **Time** card as the very first sidebar
element, so the sidebar looked empty compared to the mockup.

### Root cause

The stat strip was never ported. In the earlier alignment passes the sidebar
was reduced to a single card (the Time rollup) and the mockup's stat strip was
not among the components carried across from the mockup stylesheet, so there
was no markup to mirror.

### Fix

`task_detail.php` — a `.stat-grid.td-stats` block is now the first child of the
sidebar column, ahead of the Time card:

```html
<div class="stat-grid td-stats">
    <div class="stat stat--success">
        <div class="stat__icon"><i class="fas fa-chart-pie"></i></div>
        <div>
            <div class="stat__value">85%</div>
            <div class="stat__label">Completion</div>
        </div>
    </div>
    <div class="stat">
        <div class="stat__icon"><i class="fas fa-diagram-project"></i></div>
        <div>
            <div class="stat__value">3</div>
            <div class="stat__label">Subtasks</div>
        </div>
    </div>
</div>
```

The completion figure reuses `$calculated_percentage` — the same weighted
roll-up that the **Task Details** card renders as a progress bar — so the stat
strip and the progress bar can never disagree.

`assets/css/pages/task_detail.css` — new `.td-stats` rule:

```css
.td-stats {
    grid-template-columns: 1fr 1fr;
    margin-top: 0;
    margin-bottom: 0;
}
```

The shared `.stat-grid` default is `repeat(auto-fit, minmax(210px, 1fr))`,
which would stack the two tiles vertically inside the 340px sidebar column.
The columns are pinned to two equal tracks and the bottom margin is dropped
because the Time card below supplies the separation — matching the mockup,
where the same adjustment is applied inline.

The shared `.stat` / `.stat--success` vocabulary was already present in
`assets/css/v2-components.css`, so no shared component CSS was changed.

---

## 2. Contact detail: Overview card did not match the mockup

### Symptom

The user reported that the contact **Overview** information looked "somewhat
different" from the mockup, and asked whether the difference was caused by
missing input data.

### Diagnosis — two distinct causes

**(a) Markup divergence.** The mockup's Overview card contains the free-text
description only:

```html
<div class="card__body">
  <p class="text-sm text-muted" style="margin:0;">Site manager responsible for ...</p>
</div>
```

Our page rendered an avatar identity block (`.cd-identity` → `.cd-avatar`,
`.cd-identity__name`, `.cd-identity__position`, `.cd-identity__company`)
followed by a `Description` heading and a `.cd-description` wrapper.

The identity block was **redundant**: the contact name, position and company
already ride in the page header as the `<h1>` plus badge chips
(`includes/header.php` pattern used by `contact_detail.php`). Rendering them
a second time inside the sidebar card duplicated the header.

The card body now matches the mockup — a single `.text-sm.text-muted`
paragraph, with an explicit "no description yet" fallback so the card is
never blank.

**(b) Missing input data — confirmed.** The `contacts` table *does* have the
columns (`description`, `position`, `company`, `mobile`, `address`, `wechat`,
`line_id`, `facebook`, `linkedin`), but every row had them `NULL`:

```
id  | name               | position | company | description | mobile | wechat | linkedin
50  | Test Contact 016   | NULL    | NULL    | NULL        | NULL   | NULL   | NULL
249 | Live Check Contact | NULL    | NULL    | NULL        | NULL   | NULL   | NULL
```

So the mockup's description paragraph, the Social Media card, and the
position/company header chips had no data to render. The mockup renders them
because its fixture contact is fully populated.

Contact **249** ("Live Check Contact") was seeded with the mockup's fixture
values so the page can be compared against the mockup end to end:

| field | value |
| --- | --- |
| `position` | Site Manager |
| `company` | Bangkok Build Co. |
| `description` | Site manager responsible for day-to-day site coordination and quality sign-off on all structural and MEP installation work. |
| `mobile` / `phone` | +66 81 234 5678 |
| `address` | 88/12 Soi Sukhumvit 21, Khlong Toei Nuea, Watthana, Bangkok 10110 |
| `wechat` | surasak_w |
| `line_id` | surasak_line |
| `facebook` | surasak.fb |
| `linkedin` | in/surasak |

The Social Media card is rendered only when at least one of
`wechat` / `line_id` / `facebook` / `linkedin` is set — with the data above it
now appears, and it disappears again automatically if the fields are cleared.

### CSS cleanup

`assets/css/pages/contact_detail.css` — the now-orphaned identity rules
(`.cd-identity`, `.cd-avatar`, `.cd-identity__text`, `.cd-identity__name`,
`.cd-identity__position`, `.cd-identity__company`, `.cd-description`,
`.cd-description h4`) were removed. `.cd-description__body` was kept and
reused as the class on the mockup-shaped paragraph so the description keeps
its muted body typography.

The old `@media (max-width: 640px)` block that only repositioned the identity
block was replaced with one that stacks `.cd-stats` into a single column on
narrow screens — which is what that breakpoint now actually needs to handle.

---

## 3. Preserved invariants

- `includes/header.php` — **not modified** (md5 pinned by `about_popup_test.php`).
- `share_code` / `generateShareCode()` / `isValidShareCodeFormat()` — untouched.
- AJAX endpoint paths, `name=`/`id=` attributes and query parameters — untouched.
- Test-pinned marker classes (`task-reason-card`, `derived-pill`,
  `quick-complete-btn`, `td-reason-flag`, `parent-hold-form`) — untouched.
- `assets/css/v2-components.css` — **not loosened**; the new `.td-stats` rule
  is page-scoped in `assets/css/pages/task_detail.css`.
- `.app-about` rules remain in `assets/css/style.css`.

---

## 4. Verification

| Check | Result |
| --- | --- |
| `php -l` on `task_detail.php`, `contact_detail.php` | clean |
| Live fetch `/tasks/10`, `/tasks/1419`, `/contacts/50`, `/contacts/249`, `/projects/858` | all HTTP 200 |
| `Fatal error` / `Warning:` / `Notice:` grep on live output | 0 hits |
| HTML parse (`/tmp/parse.py`) — mismatches / unclosed | none / none on all 5 pages |
| Sidebar column tree vs mockup | Task Overview → Subtasks → Task Details → Recent Activity │ **Completion + Subtasks stat strip** → Time — identical |
| CSS token resolution (`var(--…)` vs `v2-tokens.css`) | no undefined tokens |
| Class resolution (live classes vs stylesheets) | only the 2 known intentional JS/test markers unresolved |
| Regression sweep (`database/*_test.php`, `client/*_test.php`) | **20 PASS / 0 FAIL** |
