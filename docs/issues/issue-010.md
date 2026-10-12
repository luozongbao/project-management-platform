# Client Dashboard — Toggle to Show / Hide Subtasks

**Status:** Proposed
**Type:** Feature / UI
**Priority:** Low–Medium
**Affects:** `client/dashboard.php`, `client/assets/client.css`, `client/assets/` (new JS), `client/lang.php`

## Problem

The client portal dashboard (`client/dashboard.php`) renders the project's
full task hierarchy under the "Tasks & Progress" section, built by the
recursive `render_task_tree()` helper:

```php
// client/dashboard.php
function render_task_tree($tasks, $by_parent, $level = 0) {
    foreach ($tasks as $task) {
        // ... renders task line ...
        $children = $by_parent[$task['id']] ?? [];
        if (!empty($children)) {
            echo '<ul class="task-tree">';
            render_task_tree($children, $by_parent, $level + 1);
            echo '</ul>';
        }
    }
}
```

For projects that use deep task trees (e.g. a project with 5 top-level
workstreams, each with 4–6 sub-deliverables, each with 2–3 sub-tasks),
this produces a long, deeply indented list that runs off small screens
and pushes the "Last updated / contact your PM" footer far below the
fold.

Clients on a quick check-in (the main use case for the public portal)
want a one-glance summary — the top-level workstreams and their overall
status — without having to scroll past a wall of sub-tasks they do not
own. Internal managers, on the other hand, sometimes *do* want the
full breakdown from the same dashboard (it's read-only and code-keyed;
no extra cost to render).

Today there is no way to collapse the tree. A stakeholder has to either
read everything or use the browser's find-in-page to skip around.
Neither is acceptable for a portal that exists specifically to make
project status quick to consume.

## Goals

- Add a single, obvious control to the "Tasks & Progress" section that
  lets a client collapse or expand **all** sub-tasks below the
  top-level workstreams.
- Default to **collapsed** for projects that have any sub-tasks, so
  the first impression of the page is the one-glance summary clients
  came for. (Override-able via the toggle and via a per-device
  preference.)
- Persist the user's last choice on the same device for subsequent
  visits to the same project, so a stakeholder who wants the
  detailed view is not forced to re-toggle on every page load.
- Make no backend / schema changes. The data is already loaded by
  `client/dashboard.php`; the toggle is a purely client-side render
  decision.
- Be consistent with the existing portal style (read-only, code-keyed,
  no third-party JS frameworks, no build step).

## Non-goals

- Per-task **collapsible** (accordion) behaviour. The toggle is a
  single, page-level switch — show all sub-tasks or show only
  top-level tasks. Per-branch collapse is out of scope; it triples
  the state management and is a different interaction model.
- Changing the data model, the SQL, or the `tasks` / `subtasks`
  representation. We already load the whole tree; we just choose
  whether to render its inner levels.
- Persisting the choice on the **server** (logged-in users, cross-device).
  The client portal is unauthenticated; the preference is per-device.
- Changing the internal console's task display. The internal
  `tasks.php` / `task_detail.php` is a different surface and is not
  in scope for this issue.

## UX

### Default state

- On first load, the toggle is in the **"Top-level only"** state when
  the loaded task list contains at least one task with children. For
  projects with no sub-tasks, the toggle is hidden (nothing to
  collapse) and the full flat list is shown.
- The toggle is rendered as a single button or pill control placed
  inline with the existing `Tasks & Progress (N total)` heading. It
  reads:

  | State                | Label (EN)                | Label (中文)         |
  | -------------------- | ------------------------- | -------------------- |
  | Sub-tasks visible    | `Show top-level only`     | `仅显示顶级任务`     |
  | Sub-tasks hidden     | `Show all sub-tasks`      | `显示所有子任务`     |

- When the page is in the "top-level only" state, every nested
  `<ul class="task-tree">` is visually hidden via a class on the
  section root (e.g. `data-subtasks="hidden"`), not by re-rendering
  server-side — the HTML is the same on every load; only the CSS and
  a single ARIA state differ.

### Interaction

- Clicking the toggle flips the state for the current page load
  **and** writes the new preference to `localStorage` under a
  per-project key.
- The state must be recoverable without JS: if JS is disabled or the
  client is using a browser that blocks `localStorage`, the default
  "top-level only" state still applies (CSS is server-rendered with
  the appropriate class) and the toggle is a no-op.

### Persistence

- Key: `client_dash.subtasks_visible.<share_code>`.
  - The share code is already rendered into the page
    (`<code><?= e($project['share_code']) ?></code>` in the header);
    it is the natural per-project identifier on the unauthenticated
    surface.
  - Values: `"1"` (sub-tasks visible) or `"0"` (sub-tasks hidden).
  - Missing or unparseable → default state ("hidden" / top-level
    only).
- On page load, a small inline script reads the value, sets a
  `data-subtasks` attribute on the section, and updates the toggle
  label. If the value is `"1"`, the section is rendered as fully
  expanded immediately, so there is no flash of collapsed content.
- We do **not** clear the value when the project changes — each
  project has its own key. A stakeholder who manages two projects
  keeps both preferences.

### Accessibility

- The toggle is a `<button type="button">` (not an `<a>`) so it is
  keyboard-focusable by default and announces as a control.
- It carries `aria-pressed` reflecting the current state and
  `aria-controls="<id-of-task-tree-section>"`.
- When state is "hidden", the nested `<ul class="task-tree">` blocks
  get `aria-hidden="true"` so screen readers don't read out a list
  the user has explicitly collapsed.
- The button text changes with the state — it is a verb describing
  what clicking it will do, not the current state (matches the
  Material "show more / show less" pattern). `aria-pressed` carries
  the canonical state.
- No reliance on color alone. The control uses an icon plus text
  (`<i class="fas fa-eye-slash"></i>` when sub-tasks are visible →
  click to hide; `<i class="fas fa-eye"></i>` when hidden → click to
  show).

## Data flow

This change is **entirely client-side**; no new DB columns, no new
SQL, no new endpoints. The flow:

1. `client/dashboard.php` continues to load the flat task list and
   build the same tree as today (no changes to the existing query or
   to `render_task_tree()`).
2. The "Tasks & Progress" section wrapper gets a stable
   `id="tasks-progress"` so the toggle can `aria-controls` it and
   the script can target it.
3. The toggle button is rendered server-side with the
   "Top-level only" label (the default state) and the matching icon
   and `aria-pressed="false"`. The full task tree HTML is still
   emitted by `render_task_tree()`; the CSS class on the section
   hides nested sub-trees by default.
4. A small inline `<script>` at the end of the page:
   - Reads `localStorage[client_dash.subtasks_visible.<code>]`.
   - If the value is `"1"`, flips the section to
     `data-subtasks="visible"`, updates the button label, icon, and
     `aria-pressed`.
   - Wires a single `click` handler that toggles state, writes back
     to `localStorage`, and updates the DOM.
5. The CSS rule for `data-subtasks="hidden"` hides all `<ul
   class="task-tree">` that are **not** the root one (i.e. anything
   nested under a `<li class="task-item">`), so top-level rows
   remain visible. Switching the data attribute to `"visible"`
   re-exposes them.

## Schema / API definition

No new columns. No new endpoints. The only "API" surface is:

- `localStorage` key
  `client_dash.subtasks_visible.<UPPERCASE_SHARE_CODE>` →
  `"0"` | `"1"`.
- DOM contract:
  - Section id: `tasks-progress` (on the `<section>` that wraps
    "Tasks & Progress").
  - Toggle button: `id="subtasks-toggle"`, `data-state="hidden" | "visible"`,
    `aria-pressed="true" | "false"`, `aria-controls="tasks-progress"`.
  - Section data attribute: `data-subtasks="hidden" | "visible"`.

## Validation & edge cases

| Case                                                       | Behavior                                                                                                              |
| ---------------------------------------------------------- | --------------------------------------------------------------------------------------------------------------------- |
| Project has zero tasks                                     | Toggle hidden; existing "No tasks have been created yet." copy still shows.                                            |
| Project has tasks but **no** sub-tasks (no `parent_task_id` references) | Toggle hidden — there is nothing to collapse, so the control would be misleading.  |
| First visit (no `localStorage` value)                      | Default to "hidden" (top-level only).                                                                                 |
| Returning visit with `"0"`                                 | "Hidden" — top-level only.                                                                                            |
| Returning visit with `"1"`                                 | "Visible" — full tree on first paint, no flash.                                                                       |
| `localStorage` blocked / disabled                          | Server-rendered default state still applies. The button is still rendered but its click handler no-ops safely (script guards on `try { localStorage } catch`). |
| `share_code` contains an unusual character (it won't, by schema — `[A-Z2-9]{5}-[A-Z2-9]{5}-[A-Z2-9]{5}`) | The value is interpolated into a JS string literal; HTML-encode via `e()` and additionally JSON-encode (`json_encode`) before injecting into the inline script to prevent any breakage. (The character set is safe, but the pattern is documented for future issues.) |
| User clicks toggle, then reloads                           | New state must be reflected on first paint — the inline script must run before the browser paints the tree. Place the script at the end of `<body>` and use `document.documentElement` attribute toggles rather than relying on `DOMContentLoaded` to avoid a flash. |
| User has two tabs open on the same project                 | Each tab has its own state. We do not cross-tab sync (out of scope).                                                  |
| `?lang=` is `zh`                                          | Toggle label and `aria-label` come from `client/lang.php` — both EN and 中文 must be added (see Localization).        |
| Project share code changed (rare)                          | Old `localStorage` key is silently ignored — different key, no read.                                                  |
| `display: none` vs. `visibility: hidden` vs. `aria-hidden` | Use `aria-hidden="true"` plus CSS `display: none` so screen readers skip the subtree and screen layout collapses cleanly. (Avoid `visibility: hidden` — it preserves space.) |

## Localization

Add the following keys to `client_translations()` in `client/lang.php`:

```php
// English
'dash.subtasks_show_all'    => 'Show all sub-tasks',
'dash.subtasks_show_top'    => 'Show top-level only',
'dash.subtasks_toggle_aria' => 'Toggle sub-tasks visibility',

// 中文
'dash.subtasks_show_all'    => '显示所有子任务',
'dash.subtasks_show_top'    => '仅显示顶级任务',
'dash.subtasks_toggle_aria' => '切换子任务显示',
```

Existing keys (`dash.tasks_heading`, `dash.total_suffix`, `dash.no_tasks`)
are unchanged.

## Acceptance criteria

- A toggle button appears inline with the "Tasks & Progress" heading
  on the client dashboard whenever the project has at least one
  sub-task.
- Default state on first visit is "top-level only" — the page renders
  with sub-tasks hidden, the toggle reads "Show all sub-tasks", and
  `aria-pressed="false"`.
- Clicking the toggle reveals the nested `<ul class="task-tree">`
  blocks under each top-level task and updates the button label to
  "Show top-level only" with `aria-pressed="true"`.
- Clicking again hides them. The state survives a full page reload.
- The toggle does **not** appear on projects with no sub-tasks
  (projects where every `parent_task_id IS NULL`) or on projects
  with no tasks at all.
- `localStorage` is keyed per share code; visiting a different
  project does not inherit the previous project's preference.
- When `localStorage` is unavailable, the page still defaults to
  "top-level only" and the toggle is still visible but its click is
  a no-op (no JS errors in the console).
- A screen reader reading the section in the "hidden" state skips
  the sub-task trees; reading the section in the "visible" state
  reads the full tree including sub-tasks.
- The CSS does not break the existing indented layout (the
  `tree-indent` `└─` glyph and `data-level` indentation) when
  sub-tasks are visible.
- No regression in the "Last updated" footer placement, the
  `<small>(N total)</small>` count, or the existing progress bars
  per task.
- A regression test in `client/` (e.g.
  `client/toggle_subtasks_test.php`) asserts:
  - A project with sub-tasks renders the toggle button.
  - A project without sub-tasks does not.
  - The inline script, when `localStorage[code] = "1"`, produces the
    visible-on-first-paint state (no flash).
  - The script's click handler correctly flips `aria-pressed`,
    the data attribute, and writes back to `localStorage`.

## Out of scope

- Per-task accordion (collapsing individual sub-trees independently).
- A "Expand all / Collapse all" pair of controls. The single toggle is
  sufficient for the use case.
- Persisting the choice across devices or browsers. This is a
  per-device preference, like the existing `client_lang` cookie.
- Surfacing the toggle on the internal console's task pages.
- Adding a "remembered" indicator to the toggle (e.g. a small dot when
  the choice differs from the default). The `aria-pressed` attribute
  is the canonical signal.
- A server-side query parameter override (e.g. `?subtasks=1`). The
  internal links to the client dashboard don't need to differ
  between stakeholders; a query parameter would be premature
  complexity.

## Implementation notes for the developer

- Server-side edits are confined to `client/dashboard.php` (add the
  button and `id`s, leave `render_task_tree()` alone) and
  `client/lang.php` (add the three translation keys).
- The inline script should be a single `<script>` block at the
  bottom of `<body>` in `client/dashboard.php`, not a separate
  asset — it is small (~25 lines) and only meaningful on this page.
  Do not add a new `assets/js/` file for this; it would force
  `<script src=…>` and break the "no extra request for the portal"
  expectation.
- The CSS for `[data-subtasks="hidden"] .task-tree { display: none; }`
  must be scoped to the section so that future re-use of
  `.task-tree` elsewhere is not affected. Prefer
  `#tasks-progress[data-subtasks="hidden"] ul.task-tree { display: none; }`.
- Use `e()` for the share code when interpolating it into the JS
  string literal **and** `json_encode($shareCode, JSON_HEX_TAG |
  JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT)` so a future change
  to the share-code character set cannot break the page.
- Mirror the change in the regression test pattern used by
  `database/project_start_date_test.php` and
  `database/contacts_columns_test.php` — separate `.php` test
  scripts runnable via `docker compose exec app php …`, not a
  PHPUnit suite. There is no PHPUnit in `composer.json`.
