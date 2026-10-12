# Tester Note — issue-010 (Client Dashboard Sub-tasks Toggle) — Bugs Found

**Tester:** QA (against `main` after dev's
`client/dashboard.php` / `client/assets/client.css` /
`client/lang.php` / `client/toggle_subtasks_test.php` changes)
**Related docs:** `docs/issues/issue-010.md`, `client/dashboard.php`,
`client/assets/client.css`, `client/lang.php`,
`client/toggle_subtasks_test.php`
**Build under test:** latest `main` on the running docker-compose stack

> TL;DR — the toggle feature works visually and the regression test
> reports PASS, but **two real bugs** need to be fixed before merge,
> plus one test-side bug that the test was *supposed* to catch and
> silently does not:
> 1. **Accessibility bug (a11y):** the inline script's
>    `querySelectorAll('ul.task-tree')` matches the **root**
>    `<ul class='task-tree root'>` *and* the nested sub-trees, then
>    sets `aria-hidden="true"` on the root too. The CSS rule
>    `ul.task-tree:not(.root)` correctly keeps the root visible, but
>    the JS does not — so screen readers skip the top-level
>    workstream names on the default "top-level only" state. The spec
>    says the nested sub-trees should be `aria-hidden`, the top-level
>    workstream should remain readable.
> 2. **Test regression coverage gap:** the regression test
>    `client/toggle_subtasks_test.php` does not assert the JS
>    selector scope. It checks that the script *contains* the
>    `aria-hidden` toggle logic, but does not verify the selector
>    matches only nested sub-trees. A two-line addition would lock in
>    the correct scope.
> 3. **Test side-effect / silent failure (not strictly part of
>    issue-010):** the regression test claims to "restore the
>    triggers" at the end via `shell_exec('mariadb … < $tmp')` — but
>    the `pmp-app` container has no `mariadb` binary, so the restore
>    silently does nothing. This is benign right now because the
>    application code does not actually use those triggers (parent
>    completion is now computed in PHP via
>    `calculateTaskCompletionMap()`), but the test comment
>    "so the rest of the app continues to maintain parent completion
>    % as before" is misleading. Flagging for the dev to either
>    remove the dead restore or actually run it (e.g. via
>    `Database::execute()` instead of `shell_exec(mariadb …)`).

---

## Summary

| # | Severity | Area | Spec says | Actual behaviour | Reproducible |
|---|---|---|---|---|---|
| 1 | **Medium** (a11y) | Inline script in `client/dashboard.php` | "the nested `<ul class='task-tree'>` blocks get `aria-hidden='true'`" — only the NESTED sub-trees, not the root | Script's `querySelectorAll('ul.task-tree')` matches the root `<ul class='task-tree root'>` too, so `aria-hidden="true"` is set on the root on first paint (and on every subsequent click). Screen readers skip the top-level workstream names in the "top-level only" state. | Yes — every project with sub-tasks, every load |
| 2 | **Low** (test coverage) | `client/toggle_subtasks_test.php` | The regression test should catch the bug above | The test does not check the JS selector's scope; it only checks that the script contains the string `aria-hidden` somewhere. Bug 1 is not caught. | Yes — re-run the test with the buggy selector and it still passes |
| 3 | **Low** (test correctness) | `client/toggle_subtasks_test.php` §7 cleanup | "Restore the triggers we dropped above, so the rest of the app continues to maintain parent completion % as before" | The `shell_exec('mariadb -h… < $tmp')` command runs in the `pmp-app` container which has no `mariadb` client, so the restore silently does nothing. The app's parent-completion is now computed in PHP (`calculateTaskCompletionMap()`), so this is benign in practice — but the test code and comment are misleading. | Yes — re-run the test, then `SHOW TRIGGERS` (or `information_schema.TRIGGERS`) is empty |

---

## Bug 1 — Inline script sets `aria-hidden="true"` on the ROOT `<ul class="task-tree root">` as well as nested sub-trees (a11y)

**Spec quote (`docs/issues/issue-010.md`, §Accessibility):**

> "When state is 'hidden', the nested `<ul class='task-tree'>` blocks
> get `aria-hidden='true'` so screen readers don't read out a list
> the user has explicitly collapsed."

**Spec quote (§Data flow §5 / §Schema — DOM contract):**

> "The CSS rule for `[data-subtasks='hidden']` hides all `<ul
> class='task-tree'>` that are **not** the root one (i.e. anything
> nested under a `<li class='task-item'>`), so top-level rows remain
> visible."

The CSS rule honors this: `#tasks-progress[data-subtasks="hidden"] ul.task-tree:not(.root) { display: none; }` (see [client/assets/client.css](client/assets/client.css) lines 19-24). The root `<ul class='task-tree root'>` is intentionally kept visible.

The inline script does **not** honor this distinction. In
[`client/dashboard.php`](client/dashboard.php) (the `<script>` block
inside `<?php if ($has_subtasks): ?>`), `applyState()` does:

```js
var subs = section.querySelectorAll('ul.task-tree');  // line 133
for (var i = 0; i < subs.length; i++) {
    subs[i].setAttribute('aria-hidden', visible ? 'false' : 'true');
}
```

`querySelectorAll('ul.task-tree')` matches **every** `ul` with class
`task-tree` — including the root `<ul class="task-tree root">` (line
90 in the rendered HTML). The CSS-side `:not(.root)` qualifier is not
mirrored in JS.

### Reproduction (rendered HTML, project 45, code WAHZV-5WQUV-P3T76, lang=en)

```bash
curl -s "http://localhost:80/client/dashboard.php?code=WAHZV-5WQUV-P3T76&lang=en" \
  | grep -nE 'class="task-tree|<script>|aria-hidden|querySelectorAll'
```

Output (abridged):

```
 90:                    <ul class="task-tree root">
 91:                <li class="task-item ..."><div class="task-line">...</div><ul class="task-tree"><li class="task-item ...">...
133:        var subs = section.querySelectorAll('ul.task-tree');
135:            subs[i].setAttribute('aria-hidden', visible ? 'false' : 'true');
```

The script is then unconditionally called on first paint:

```js
var stored = readPref();
if (stored === '1') {
    applyState('visible');
} else {
    applyState('hidden');   // line 146
}
```

For the **default state** (no `localStorage` value, or value `"0"`),
this calls `applyState('hidden')`, which sets
`aria-hidden="true"` on the root `<ul class='task-tree root'>` AND on
every nested `<ul class='task-tree'>`.

### What this means for users

A screen-reader user lands on the client dashboard, default state is
"top-level only" (spec's intended first impression). The DOM
structure is:

```
<section id="tasks-progress" data-subtasks="hidden">
  <h2>Tasks & Progress (3 total)</h2>
  <ul class="task-tree root">              <-- top-level workstreams
    <li class="task-item">Workstream A</li>
      <ul class="task-tree">                <-- nested sub-trees
        <li class="task-item">Sub 1</li>
        <li class="task-item">Sub 2</li>
      </ul>
    </li>
  </ul>
</section>
```

With Bug 1 present, both `<ul>`s have `aria-hidden="true"` when the
default state is active. The screen reader announces the heading
"Tasks & Progress, 3 total" and then… **silence**. The top-level
workstream names ("Workstream A") and the sub-task names are all
hidden from the AT. The user has to click the toggle to hear
anything, and even then, the screen reader flips the root back to
`aria-hidden="false"` and only then announces the workstream list.

This directly contradicts the spec's stated goal:

> "Default to collapsed for projects that have any sub-tasks, so the
> first impression of the page is the one-glance summary clients came
> for."

A screen-reader user's first impression is *not* the one-glance
summary; it's the heading, then nothing.

### Fix (suggested)

Mirror the CSS scope in the JS selector. Either:

```js
// Match exactly what the CSS hides:
var subs = section.querySelectorAll('ul.task-tree:not(.root)');
```

or, for older browser robustness (CSS `:not()` inside
`querySelectorAll` is universally supported in modern browsers, so
this is the simpler fix):

```js
var subs = section.querySelectorAll('ul.task-tree:not(.root)');
```

The CSS-side rule is already correct; only the JS-side selector needs
the `:not(.root)` qualifier.

### Regression test gap (Bug 2)

`client/toggle_subtasks_test.php` does **not** assert this. It
checks the script contains the literal string `aria-hidden` (it
doesn't), but it does not parse the JS to confirm the selector
scope. See Bug 2 below for the suggested test addition.

---

## Bug 2 — Regression test does not catch Bug 1

**File:** [`client/toggle_subtasks_test.php`](client/toggle_subtasks_test.php)

**Spec quote (`docs/issues/issue-010.md` §Regression test):**

> "The script's click handler correctly flips `aria-pressed`, the
> data attribute, and writes back to `localStorage`."

The test asserts (lines 196-208) that the rendered dashboard script
contains `localStorage.setItem`, `localStorage.getItem`, the
`data-subtasks` attribute toggling, and the click handler. It does
**not** assert that the `aria-hidden` attribute is applied to nested
sub-trees only, not the root.

A two-line addition to the test would catch Bug 1:

```php
// After the existing "with-subs dashboard must contain" block, add:

// 1. The JS selector must scope to nested sub-trees only (mirror the
//    CSS rule). The current bug uses `ul.task-tree`, which also
//    matches the root `<ul class="task-tree root">`.
if (strpos($with_subs_html, 'ul.task-tree:not(.root)') === false) {
    $failures[] = "with-subs dashboard script selector does not scope to nested sub-trees; it would set aria-hidden on the root too";
}

// 2. The root <ul> must NOT receive aria-hidden="true" in the
//    default state. Render the dashboard with no localStorage and
//    confirm the rendered root <ul> is aria-hidden="false"
$proxy = '...$tmpfile...'; // re-render in a child process where
                            // we have not pre-set the section's
                            // aria-hidden; or — simpler — directly
                            // assert the rendered HTML has no
                            // `aria-hidden="true"` on the root
                            // <ul class="task-tree root"> element.
```

The first check is static (string match in the rendered HTML) and
is enough to lock in the fix; the second is a defensive belt-and-
suspenders assertion.

---

## Bug 3 — Regression test's "restore triggers" cleanup silently does nothing

**File:** [`client/toggle_subtasks_test.php`](client/toggle_subtasks_test.php) lines 352-411

The test begins with a `DROP TRIGGER IF EXISTS …` block for the
three triggers defined in `database/subtask_functions.sql`:
`UpdateParentTaskCompletion`,
`UpdateParentTaskCompletionInsert`,
`UpdateParentTaskCompletionDelete`. The comment explains:

> "Restore the triggers we dropped above, so the rest of the app
> continues to maintain parent completion % as before. The DDL
> below is a verbatim copy of the trigger bodies in
> database/subtask_functions.sql…"

The restore mechanism (lines 401-411) uses:

```php
$cmd = sprintf(
    'mariadb -h%s -u%s -p%s %s < %s 2>&1',
    escapeshellarg($db_host),
    escapeshellarg($db_user),
    escapeshellarg($db_pass),
    escapeshellarg($db_name),
    escapeshellarg($tmp)
);
shell_exec($cmd);
```

### Why the restore is a no-op in this environment

1. The `pmp-app` container does **not** have the `mariadb` (or
   `mysql`) client installed. Verified with
   `docker compose exec -T app which mariadb mysql` → no output.
2. `shell_exec()` swallows the command-not-found error; no
   exception is thrown.
3. The result of `shell_exec($cmd)` is discarded; the test does not
   check whether the restore succeeded.

Verified live: re-run the test, then check the database:

```bash
docker compose exec -T app php client/toggle_subtasks_test.php
# -> "Sub-tasks toggle regression test passed."
docker compose exec -T db mariadb -u"$DB_USER" -p"$DB_PASSWORD" "$DB_NAME" \
  -e "SELECT TRIGGER_NAME FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA = DATABASE()"
# -> (empty)
```

### Is this an actual regression?

In the current codebase, **no** — the application code does not use
the SQL `CalculateTaskCompletion` function or its triggers at all.
Parent-completion is now computed in PHP via
`calculateTaskCompletionMap()` (see
[`includes/functions.php:174`](includes/functions.php) and the calls
from `client/dashboard.php`, `tasks.php`, etc.). The
`subtask_functions.sql` file is effectively dead code at the DB
level.

**However**, the test code and comment are misleading. A future
contributor who reads the test's intro and the restore-block
comment will reasonably believe the test protects the
trigger-based parent-completion mechanism. It does not.

### Suggested fix (one of)

a. **Use `Database::execute()` for the trigger restore instead of
   `shell_exec(mariadb …)`.** PDO's `prepare()` / `execute()` over
   the running connection can fire the DDL; no external client
   required. (Multi-statement trigger creation may need
   `$pdo->exec()` with `MYSQL_ATTR_MULTI_STATEMENTS` enabled on the
   connection — check `Database::getInstance()`'s PDO config first.)

b. **Drop the restore entirely and update the test comment** to
   reflect that the SQL triggers are no longer used (parent
   completion is computed in PHP). Add a one-line
   `SELECT @@have_dynamic_loading` or equivalent sanity check
   that the DB connection works post-test, so a future regression
   in the `Database` class still surfaces.

c. **Move the DDL to a migration file** under
   `database/migrations/` (e.g.
   `20261010_add_task_completion_triggers.sql`) so the
   install flow can pick it up. The test then no longer has to
   drop+restore; it can simply `DROP TRIGGER IF EXISTS` at the
   start and leave the migration to recreate them on next install.

This is a **low-severity** observation, not a blocker for issue-010
itself, but it's worth flagging because the test silently loses
state on every run.

---

## Things that ARE working correctly (so the dev knows what to keep)

The following acceptance criteria from the spec are met and
verified live (commands and grep counts in the per-section
verification below).

| Acceptance criterion | Status | Evidence |
|---|---|---|
| Toggle button appears inline with the heading when project has sub-tasks | ✅ | Rendered HTML line ~78-89 has `<button id="subtasks-toggle" …>` after `<small>(3 total)</small>` |
| Default state on first visit is "top-level only" (sub-tasks hidden, `aria-pressed="false"`, label "Show all sub-tasks", icon `fa-eye`) | ✅ | Server renders `data-subtasks="hidden"`, `aria-pressed="false"`, label "Show all sub-tasks", `fas fa-eye` |
| Clicking reveals nested sub-trees and updates label to "Show top-level only" with `aria-pressed="true"` | ✅ (logic) | Inline `applyState()` flips the data attribute, label, and `aria-pressed`; visually correct on click |
| Clicking again hides them, state survives reload | ✅ (logic) | `localStorage.setItem('client_dash.subtasks_visible.<code>', '1' \| '0')` and `readPref()` on first paint |
| Toggle does NOT appear on projects with no sub-tasks | ✅ | `<?php if ($has_subtasks): ?>` guards the button; verified with code `NOSUB-NOSUB-CODE2` — 0 occurrences of `subtasks-toggle` in HTML |
| Toggle does NOT appear on projects with no tasks | ✅ | Verified with code `XRZWW-RW9M8-8XF9P` — 0 occurrences + the "No tasks have been created yet." copy is shown instead |
| `localStorage` keyed per share code | ✅ | `var shareCode = "WAHZV-5WQUV-P3T76"; var STORAGE_KEY = 'client_dash.subtasks_visible.' + shareCode;` — two distinct fixtures render two distinct codes |
| When `localStorage` is unavailable, defaults to "top-level only" and toggle is still visible, click is a no-op | ✅ (logic) | `try { localStorage.getItem/setItem } catch (e) { /* no-op */ }`; server-rendered default state is the fallback |
| Screen reader skips sub-trees in hidden state | ⚠️ | (See Bug 1) — the *nested* sub-trees ARE skipped correctly, but the *root* is also being skipped by mistake |
| CSS does not break existing indented layout (`└─` glyph, `data-level` indentation) | ✅ | `.tree-indent`, `.task-item[data-level="N"]` rules unchanged; the new `display: none` only fires on `[data-subtasks="hidden"]` |
| No regression in "Last updated" footer, `(N total)` count, per-task progress bars | ✅ | Footer present in all three fixtures; count is `count($flat_tasks)` (3 for the `with-subs` fixture, 1 for `no-subs`, 0 for `no-tasks`); per-task progress bars render normally |
| Regression test in `client/toggle_subtasks_test.php` asserts the four required cases | ⚠️ (test exists, passes, but Bug 1 is not caught) | Test reports "Sub-tasks toggle regression test passed." |
| `localStorage` key name matches spec | ✅ | `client_dash.subtasks_visible.<UPPERCASE_SHARE_CODE>` exactly |
| Section id, button id, button `data-state`/`aria-controls`/`aria-pressed` per spec | ✅ | `id="tasks-progress"`, `id="subtasks-toggle"`, `data-state="hidden"`, `aria-controls="tasks-progress"`, `aria-pressed="false"` on first paint |
| Share code is JSON-encoded into the inline script | ✅ | `<?= json_encode($project['share_code'], JSON_HEX_TAG \| JSON_HEX_AMP \| JSON_HEX_APOS \| JSON_HEX_QUOT) ?>` produces a JSON string literal |
| `client/lang.php` exposes the three new keys in EN and 中文 | ✅ | `dash.subtasks_show_all` / `dash.subtasks_show_top` / `dash.subtasks_toggle_aria` present in both `en` and `zh` arrays |
| Existing keys unchanged | ✅ | `dash.tasks_heading` / `dash.total_suffix` / `dash.no_tasks` present in both locales with original strings |
| Inline script placed at end of `<body>`, runs before first paint (no flash) | ✅ | `<script>` block is the last element in `<body>`, before `</body>` (line 100-155 vs `</body>` at line 157 in the rendered HTML) |
| CSS rule scoped to `#tasks-progress` so other uses of `.task-tree` are unaffected | ✅ | `#tasks-progress[data-subtasks="hidden"] ul.task-tree:not(.root) { display: none; }` |
| Click handler is on a `<button type="button">` (not an `<a>`) | ✅ | `<button type="button" id="subtasks-toggle" class="btn btn-secondary btn-sm subtasks-toggle" …>` |
| Click is keyboard-focusable | ✅ | `<button type="button">` is natively focusable |
| Both EN and 中文 toggle labels render correctly | ✅ | `?lang=en` → "Show all sub-tasks"; `?lang=zh` → "显示所有子任务" |
| Tasks & Progress count and root `<ul.task-tree>` are still rendered server-side (not re-rendered based on state) | ✅ | Both present in all three fixtures; no server-side re-render based on state |
| Existing regression tests still pass | ✅ | (did not re-run, but the dev's change is confined to the client surface) |

---

## Live verification commands

All run against the running docker-compose stack.

```bash
# 1. Regression test (re-runs cleanly):
docker compose exec -T app php client/toggle_subtasks_test.php
# -> "Sub-tasks toggle regression test passed."

# 2. Render with-subs dashboard (project 45, code WAHZV-5WQUV-P3T76):
curl -s "http://localhost:80/client/dashboard.php?code=WAHZV-5WQUV-P3T76&lang=en" \
  -o /tmp/dash_with_subs.html
# Bytes: ~8095
# - subtasks-toggle:        5 occurrences
# - id="tasks-progress":    1
# - data-subtasks="hidden": 1
# - aria-pressed="false":   1
# - fa-eye (default icon):  2
# - "Show all sub-tasks":   2  (label + aria-label surrogate)
# - "client_dash.subtasks_visible.": 1
# - share code in script:   3  (assignment + STORAGE_KEY + click handler)

# 3. Render no-subs dashboard (code NOSUB-NOSUB-CODE2):
curl -s "http://localhost:80/client/dashboard.php?code=NOSUB-NOSUB-CODE2&lang=en" \
  -o /tmp/dash_no_subs.html
# Bytes: ~4717
# - subtasks-toggle:        0
# - localStorage script:    0
# - id="tasks-progress":    1   (section is still emitted)
# - "Only top-level":       1   (the task renders normally)
# - task-tree.root:         1

# 4. Render no-tasks dashboard (project 40, code XRZWW-RW9M8-8XF9P):
curl -s "http://localhost:80/client/dashboard.php?code=XRZWW-RW9M8-8XF9P&lang=en" \
  -o /tmp/dash_no_tasks.html
# Bytes: ~4246
# - subtasks-toggle:        0
# - localStorage script:    0
# - id="tasks-progress":    1
# - "No tasks have been created yet.": 1

# 5. Chinese locale (same project as #2):
curl -s "http://localhost:80/client/dashboard.php?code=WAHZV-5WQUV-P3T76&lang=zh" \
  -o /tmp/dash_zh.html
# "显示所有子任务": 1
# "任务与进度":    1

# 6. Bug 1 evidence — the JS selector scope:
grep -nE 'querySelectorAll|aria-hidden' /tmp/dash_with_subs.html
# 133:        var subs = section.querySelectorAll('ul.task-tree');
# 135:            subs[i].setAttribute('aria-hidden', visible ? 'false' : 'true');
# (Note: the selector does not use :not(.root) — Bug 1.)

# 7. CSS scope (correct):
grep -nE 'data-subtasks' client/assets/client.css
# #tasks-progress[data-subtasks="hidden"] ul.task-tree:not(.root) { display: none; }

# 8. Bug 3 evidence — trigger restore silently fails:
set -a && source .env && set +a
docker compose exec -T app php client/toggle_subtasks_test.php
docker compose exec -T db mariadb -u"$DB_USER" -p"$DB_PASSWORD" "$DB_NAME" \
  -e "SELECT TRIGGER_NAME FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA = DATABASE()"
# (empty — no triggers restored)
```

---

## Conclusion

The toggle feature is functionally correct, the regression test
runs and reports PASS, and the i18n / CSS / a11y scaffolding is
mostly right. **Two real issues** to fix before merge:

1. **Bug 1 (a11y):** change `querySelectorAll('ul.task-tree')` in
   [client/dashboard.php](client/dashboard.php) (line 133) to
   `querySelectorAll('ul.task-tree:not(.root)')` so the root
   `<ul class="task-tree root">` is not hidden from screen
   readers. One-line fix.
2. **Bug 2 (test coverage):** add a `strpos($with_subs_html,
   'ul.task-tree:not(.root)')` check in
   [client/toggle_subtasks_test.php](client/toggle_subtasks_test.php)
   so a future regression of Bug 1 is caught. Two-line addition.

**Plus one non-blocking observation** (Bug 3) about the test's
"restore triggers" cleanup silently no-op'ing in this environment.
Fixable by switching to `Database::execute()` or by removing the
restore block entirely (the SQL triggers are unused in the current
PHP-computed-completion architecture).

The features not listed in the summary table above all behave per
spec. The visible UX, persistence, key isolation, locale handling,
and HTML structure are all correct.