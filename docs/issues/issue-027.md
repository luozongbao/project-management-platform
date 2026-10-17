# Client Dashboard Sub-Tasks Toggle Stopped Working (CSP Blocked Inline Script)

**Status:** Proposed
**Type:** Bug / Regression
**Priority:** Medium-High
**Target Release:** v1.7.1.2
**Affects:** `client/dashboard.php`, `client/toggle_subtasks_test.php`, `client/assets/js/subtask-toggle.js` (new), `database/security_headers_test.php` (comment fix only)

## Problem

A stakeholder reports that the "Show all sub-tasks / Show top-level only"
toggle on the public client dashboard
(`https://…/client/dashboard.php?code=…`) no longer responds to clicks.
Clicking the eye-icon button updates the icon and label momentarily
during mouse-down, but the sub-tasks remain hidden and the underlying
task tree does not change. Reloading the page resets to the same
default-collapsed state every time, so the per-project preference is
also not being persisted.

The toggle was originally introduced in [issue-010](./issue-010.md) and
worked correctly in v1.5.x. The regression was introduced by
[issue-020](../RELEASE.md#v160--2026-10-15) ("response security headers
+ session hardening", commit `ef5d061`), which tightened the
Content-Security-Policy on the public client portal to **drop
`'unsafe-inline'` from `script-src`**.

### Why the toggle is silently dead in the browser

The entire sub-tasks toggle controller is an **inline `<script>`**
block at the end of `client/dashboard.php`'s `<body>`:

````php
<?php if ($has_subtasks): ?>
<script>
(function () {
    // ... reads localStorage, wires click handler, flips data-subtasks ...
    btn.addEventListener('click', function () {
        var next = (btn.getAttribute('data-state') === 'visible') ? 'hidden' : 'visible';
        applyState(next);
        writePref(next === 'visible' ? '1' : '0');
    });
})();
</script>
<?php endif; ?>
````

The `includes/security_headers.php` `sendClientPortalSecurityHeaders()`
helper — wired into `client/dashboard.php:30` — sends the following
CSP for the public portal:

````
default-src 'self';
script-src 'self' https://cdnjs.cloudflare.com;
style-src 'self' 'unsafe-inline' https://cdnjs.cloudflare.com;
img-src 'self' data:;
font-src 'self' https://cdnjs.cloudflare.com data:;
connect-src 'self';
frame-ancestors 'none';
base-uri 'self';
form-action 'self'
````

`script-src` is **`'self' https://cdnjs.cloudflare.com`** with no
`'unsafe-inline'` and no nonce. The browser therefore refuses to
execute the inline `<script>` block — DevTools shows the
CSP violation:

> `Refused to execute inline script because it violates the
> following Content Security Policy directive: "script-src 'self'
> https://cdnjs.cloudflare.com". Either the 'unsafe-inline' keyword,
> a hash ('sha256-…'), or a nonce ('nonce-…') is required to enable
> inline execution.`

Without the script ever running:

- `btn.addEventListener('click', …)` is never wired up.
- `localStorage` is never read on first paint (so any previously
  saved `"1"` is ignored — first-paint always lands in the
  `applyState('hidden')` branch).
- `localStorage` is never written on click (so the preference can
  never be persisted).

The result: the button is visible, the eye icon is visible, the
label is visible, the `[Auto]` and `aria-*` markup is all
intact — but clicking does nothing. From the user's perspective
the toggle is broken.

### Why the existing regression test didn't catch this

`client/toggle_subtasks_test.php` is the regression test for
issue-010. It renders the page via `shell_exec('php ' . $tmpfile)`
and inspects the HTML output. It correctly asserts:

- the `#subtasks-toggle` button is present
- the `id="tasks-progress"` section is present
- the inline `<script>` block is present, with the
  `addEventListener('click'`, `localStorage.getItem`,
  `localStorage.setItem`, `client_dash.subtasks_visible.`, and
  `var shareCode = "…"` markers
- the CSS rule `#tasks-progress[data-subtasks="hidden"]` is
  present in `client/assets/client.css`

All of these are still true. The test runs **against the PHP
output only**, not against a real browser, so the CSP block
is invisible to it. The page renders correctly; the browser
refuses to run the script. The test cannot tell the two apart.

`database/security_headers_test.php:307-309` has a comment that
explicitly claims the client portal has no inline scripts:

````php
// Client portal: script-src MUST NOT contain 'unsafe-inline'
// (the only JS is /assets/js/subtask-manager.js).
````

That comment is **factually wrong**. `/assets/js/subtask-manager.js`
is loaded by `includes/header.php` for the **internal app** only;
the client portal never includes that file. The actual toggle
controller is inline in `client/dashboard.php`. The CSP tightening
was made on a false premise: "the only JS is in an external file",
which was true for the **internal** app the test author grepped,
not for the public client portal that the CSP was being applied
to.

The original tightening (issue-020) even added a comment to
`includes/security_headers.php:65-71` saying:

> "Differences from the internal-app CSP: - script-src drops
> 'unsafe-inline' — the toggle JS is in assets/js/subtask-manager.js,
> loaded via `<script src="…">`, and the client portal has no inline
> event handlers."

That comment is also wrong about the file path. The toggle JS
was never moved to an external file. Issue-020 was supposed to
either (a) move the inline script to an external file, or (b)
keep the inline script and not tighten `script-src`. Neither
option was taken; the CSP was tightened and the inline script
was left in place, leaving the toggle dead.

This is the regression. The fix is to do (a) properly: extract
the inline `<script>` from `client/dashboard.php` into
`client/assets/js/subtask-toggle.js` and load it via
`<script src="…">`. The external file satisfies
`script-src 'self'`, the toggle starts working again, and the
CSP tightening is preserved.

## Goals

- Restore the "Show all sub-tasks / Show top-level only" toggle
  on `client/dashboard.php` so a click flips the
  `data-subtasks` attribute, hides/shows the nested
  `<ul class="task-tree">` sub-trees, and persists the choice
  to `localStorage[client_dash.subtasks_visible.<share_code>]`.
- Preserve the issue-020 CSP tightening (no `'unsafe-inline'`
  in `script-src` for the public client portal) — the fix
  must not weaken the security headers. The right way to
  satisfy the tightened CSP is to move the script to an
  external file.
- Preserve all of the existing server-rendered markup for
  the toggle: the `<section id="tasks-progress" data-subtasks="hidden">`,
  the `<button id="subtasks-toggle">` with its `aria-controls`,
  `aria-pressed`, and `aria-label` attributes, the
  `<span class="subtasks-toggle-label">` text node, and the
  `<i class="fas fa-eye">` icon.
- Preserve the existing CSS rule
  `#tasks-progress[data-subtasks="hidden"] ul.task-tree:not(.root)
  { display: none; }` in `client/assets/client.css` — the
  fix does not need to change the CSS. The CSS only knows
  how to hide sub-trees; the visible state is the browser
  default for `<ul>`, which the CSS does not need to
  override.
- Preserve the existing label / icon / aria-pressed state
  machine, the first-paint localStorage read, the
  `aria-hidden` reflection on nested sub-trees, the
  JSON-encoded share code (no string-concat), and the
  `try { … } catch { /* no-op */ }` guards around
  `localStorage` access (Safari private mode, sandboxed
  iframes).
- Preserve the i18n: all three translation keys
  (`dash.subtasks_show_all`, `dash.subtasks_show_top`,
  `dash.subtasks_toggle_aria`) are server-rendered into
  the page and read by the JS. The new external file
  must continue to read them from the DOM (or from
  `data-*` attributes on the button) — not from
  server-side rendering of the JS file — so a future
  language change does not require rebuilding the JS
  asset.
- Update `client/toggle_subtasks_test.php` to assert the
  new contract: the inline `<script>` block is gone from
  the page output, the `<script src="assets/js/subtask-toggle.js" defer>`
  tag is present, the external file exists, and the
  external file contains the controller logic.
- Fix the misleading comment in
  `database/security_headers_test.php:307-309` so a
  future developer does not reintroduce the regression
  by trusting the comment.

## Non-goals

- Re-adding `'unsafe-inline'` to the client portal CSP.
  This would re-open the XSS surface the issue-020
  hardening closed and is strictly the wrong fix.
- Switching to nonces (issue-020 left nonces as a v2.0
  item). The external file is simpler and matches the
  pattern the rest of the app already uses
  (`assets/js/subtask-manager.js`,
  `assets/js/script.js`).
- Changing the toggle's UX, state machine, label, icon,
  or `aria-*` semantics. Those are all issue-010
  invariants; this issue is a pure mechanical
  extraction.
- Changing `client/assets/client.css`. The existing
  `display: none` rule continues to work.
- Touching the internal app's CSP. The internal app
  keeps `'unsafe-inline'` (per the issue-020 comment)
  because the internal app has many inline `onclick=`
  handlers. This issue is scoped to the public
  portal only.
- Adding build tooling, a bundler, a transpiler, or any
  JS framework. The extracted file is hand-written
  ES5-compatible JavaScript (matches the existing
  `assets/js/script.js` style: `var` everywhere, no
  arrow functions, no `const`/`let`, no template
  literals) so it can ship as a single static file
  with no build step.
- Changing the i18n catalog. No new translation keys.
- Adding a migration. The fix is a single-file
  extraction plus a test update.

## UX

The user-visible behaviour after the fix is **identical** to
the behaviour before the issue-020 regression:

- Default state on first visit: sub-tasks hidden (top-level
  only). The eye icon is `fa-eye`; the label is "Show all
  sub-tasks" (EN) / "显示所有子任务" (中文); `aria-pressed="false"`.
- Click the button: sub-trees appear. The icon flips to
  `fa-eye-slash`; the label flips to "Show top-level only"
  / "仅显示顶级任务"; `aria-pressed="true"`. The choice is
  written to `localStorage[client_dash.subtasks_visible.<share_code>] = "1"`.
- Reload the page: the choice is restored. No flash of
  collapsed content. The `data-subtasks` attribute is set
  to `"visible"` on first paint from the script.
- Click again: sub-trees hide. The icon and label flip
  back. `localStorage` is updated to `"0"`.

What changes under the hood is invisible to the user: the
toggle controller is now served from
`/client/assets/js/subtask-toggle.js` instead of being
inline. The browser DevTools "Sources" panel will show the
file at that path; the CSP "Refused to execute inline
script" violation disappears; the console is clean.

## Data flow

The data flow is unchanged from issue-010. The only
mechanical change is **where the script bytes live**:

1. `client/dashboard.php` continues to load the flat
   task list and build the same tree as today
   (no changes to the existing query or to
   `render_task_tree()`).
2. The "Tasks & Progress" section wrapper still has
   `id="tasks-progress"` and
   `data-subtasks="hidden"`. The toggle button still
   has `id="subtasks-toggle"`,
   `data-state="hidden"`, `aria-controls="tasks-progress"`,
   `aria-pressed="false"`,
   `aria-label="<translated>"`, an `<i class="fas fa-eye">`
   icon, and a `<span class="subtasks-toggle-label">`
   with the translated label.
3. **Removed** from the page output: the inline
   `<script>(function () { … })();</script>` block
   currently at lines 372-430 of `client/dashboard.php`.
   It is replaced by:
   ```html
   <script src="assets/js/subtask-toggle.js" defer></script>
   ```
4. **Added** to the workspace: a new file
   `client/assets/js/subtask-toggle.js` containing
   the body of the IIFE verbatim, plus a tiny preamble
   that reads the share code and the three translation
   strings from `data-*` attributes on the button
   (so the JS file does not need to be re-rendered per
   page load). See the "Implementation" section for
   the exact contract.
5. The CSS rule for `data-subtasks="hidden"` is
   unchanged.
6. `nginx.conf` already serves `/client/assets/…` as a
   static directory via the `try_files` location; the
   new `.js` file is picked up automatically by the
   existing static-asset caching rule
   (`expires 30d; Cache-Control "public, max-age=2592000"`).
   No nginx change is required.

## Schema / API definition

No new columns. No new endpoints. The new "API" surface is
**just a static JS file**:

- URL: `/client/assets/js/subtask-toggle.js`
- Served by nginx as a static asset (matches the existing
  `client/assets/client.css` pattern).
- File is version-agnostic — no cache-buster query
  parameter. The existing 30-day `Cache-Control` header
  on the static-asset location handles the load.

DOM contract (unchanged from issue-010):

- Section id: `tasks-progress` (on the `<section>` that
  wraps "Tasks & Progress").
- Section data attribute: `data-subtasks="hidden" | "visible"`.
- Toggle button: `id="subtasks-toggle"`, `data-state="hidden" | "visible"`,
  `aria-pressed="true" | "false"`, `aria-controls="tasks-progress"`.

New `data-*` attributes the JS reads (replaces the
JSON-encoded constants that the inline script had):

- `data-share-code="<UPPERCASE_5-5-5>"` on the button
  (or, more cleanly, on the section). The share code is
  already in the page (in the header `<code>` element);
  duplicating it once in a `data-*` attribute is
  cheaper than serialising JSON into a script tag.
- `data-label-show-all="<translated>"` and
  `data-label-show-top="<translated>"` on the button.
  These replace the `var LABEL_SHOW_ALL = <?= json_encode(...) ?>`
  pattern. The translations are still rendered by PHP
  from `client/lang.php`; they just land as attribute
  values instead of JS string literals.

The JS file reads these via `btn.getAttribute('data-…')`
and the per-project `localStorage` key is built by
concatenating the prefix literal with `data-share-code`.

## Validation & edge cases

| Case                                                        | Expected behaviour                                                                                                                            |
| ----------------------------------------------------------- | --------------------------------------------------------------------------------------------------------------------------------------------- |
| Project WITH sub-tasks, first visit                         | Sub-tasks hidden. Eye icon + "Show all sub-tasks". Click expands.                                                                              |
| Project WITH sub-tasks, second visit with stored `"1"`     | First paint shows sub-tasks visible (no flash). Eye-slash icon + "Show top-level only".                                                       |
| Project WITH sub-tasks, second visit with stored `"0"`     | First paint shows sub-tasks hidden. Eye icon + "Show all sub-tasks".                                                                          |
| Project WITHOUT sub-tasks                                   | Toggle button is **not** rendered. External JS file is **not** loaded. CSS still applies harmlessly because the markup has no `#subtasks-toggle` for the JS to find. |
| Project with NO tasks                                       | Same as no-sub-tasks. Toggle not rendered, external JS not loaded. Empty-state copy is unchanged.                                             |
| Browser with JS disabled                                    | Page renders server-side with sub-tasks hidden by the CSS rule. Toggle button is rendered but inert. Identical to the issue-010 fallback.       |
| Safari private mode / sandboxed iframe (`localStorage` throws) | `readPref()` and `writePref()` catch and no-op. The button still works for the current page load (state is held in the DOM). Reload resets to default. Same as the issue-010 fallback. |
| Share code with non-ASCII characters (currently not possible — `A-Z2-9` charset, but a future migration might extend it) | `data-share-code` is a string attribute. JS does `localStorage.getItem` with it as a key; browser permits any string key. No XSS surface (the value is rendered as an HTML attribute, not interpolated into a `<script>` body). |
| Network failure loading the JS file                          | Toggle button renders but is inert. The page still loads (JS is `defer`red, not `async`, so the page paints first). Same UX as JS disabled.   |
| Two tabs open on the same project, toggle in tab A          | Tab A's click writes to `localStorage`; tab B does not see the update until reload. This matches the issue-010 design — no cross-tab sync.    |
| DevTools "Disable cache" while page is open                  | Static-asset cache miss. The browser re-fetches `/client/assets/js/subtask-toggle.js` from nginx. Behaviour is identical.                       |
| Browser extension that injects a competing `#subtasks-toggle` | The JS uses `document.getElementById('id')` which returns the first match. If the extension injects a button before the real one, the JS wires up the wrong button. **Out of scope** — same risk as today.     |
| `task-tree` markup is re-arranged by a future change         | The CSS selector and the JS aria-hidden selector both use `ul.task-tree:not(.root)`. A future change that re-tags the root must keep the `.root` class. **Out of scope** for this issue; the contract is unchanged.    |
| CSP `script-src` ever changes again                          | The new external file is served from `'self'`. As long as `'self'` is in the script-src, the file loads. If a future CSP narrows `default-src`, the file path is still under `/client/assets/js/` which resolves to `'self'`. |
| Replay of issue-010 acceptance criteria (4×4 state machine, 12 pure-function fixtures, per-project key isolation, etc.) | All of these continue to pass. The only contract change is the "inline `<script>` block is present" assertions, which the test must replace with "external `<script src=…>` tag is present" and "external file contains the controller logic". |

## Acceptance criteria

- `client/dashboard.php` does **not** contain an inline
  `<script>` block. Grepping the rendered HTML for
  `<script>` returns only the CDN `<script>` tags from
  Font Awesome (which are external `<script src="…">`
  tags, not inline) and the new external
  `<script src="assets/js/subtask-toggle.js" defer>` tag.
- `client/assets/js/subtask-toggle.js` exists, is a
  valid JS file, and contains:
  - the IIFE pattern
  - `localStorage.getItem` and `localStorage.setItem`
  - `addEventListener('click', …)` on the button
  - `setAttribute('data-subtasks', …)` on the section
  - `setAttribute('aria-pressed', …)` on the button
  - the `try { … } catch (e) { … }` guards around
    `localStorage` access
  - the scoped `ul.task-tree:not(.root)` selector for
    the `aria-hidden` reflection
  - **no** server-rendered values (no `<?= … ?>` PHP
    tags inside the JS — the file is static and
    reads translations and the share code from
    `data-*` attributes)
- The toggle is wired up correctly: clicking the
  button flips the icon (`fa-eye` ↔ `fa-eye-slash`),
  flips the label, flips `aria-pressed`, hides/shows
  the nested sub-trees, and writes
  `"0"`/`"1"` to `localStorage[client_dash.subtasks_visible.<share_code>]`.
  Reload restores the choice.
- `database/security_headers_test.php:307-309` comment
  is corrected to reflect reality: the public client
  portal's only client-side JS now lives at
  `/client/assets/js/subtask-toggle.js`, not at
  `/assets/js/subtask-manager.js`. The existing
  assertions (no `'unsafe-inline'` in `script-src`,
  `default-src 'self'` present, etc.) are unchanged
  and still pass.
- `client/toggle_subtasks_test.php` is updated:
  - Replaces the "inline `<script>` block is present
    with these markers" assertions (5 of them in
    the current test) with new assertions on the
    external file: file exists, file contains the
    controller markers, file is reachable from the
    dashboard page via the `<script src=…>` tag.
  - Adds a new source-grep layer: `client/dashboard.php`
    must NOT contain a `<script>` block that is not
    either a `<script src=…>` tag or a CDN script.
  - The server-rendered markup assertions (button
    id, section id, default `data-subtasks`,
    `aria-pressed="false"`, the "Show all
    sub-tasks" label, the per-project `data-share-code`
    attribute, the `data-label-show-all` /
    `data-label-show-top` attributes, the `<i class="fas fa-eye">`
    icon) all still pass.
  - The CSS rule, the translation keys, the state
    machine, the per-project `localStorage` key
    isolation, the JSON-encoded share code (now
    via `data-share-code` attribute instead of
    `var shareCode = "…"` literal), the share-code
    embedding check (now checks for the
    `data-share-code="<code>"` attribute), the
    empty-state copy, the no-subs / no-tasks
    branches, the 4×4 state machine parity test,
    and the cleanup-without-`mariadb` sanity check
    all still pass.
- The `client/dashboard.php` source file is
  shorter than before (the inline block is
  replaced by a single `<script src=…>` tag —
  roughly 60 lines removed).
- No new translation keys. No new CSS. No
  schema change. No nginx change.
- The `client/toggle_subtasks_test.php` test
  reports zero failures.
- The `database/security_headers_test.php` test
  reports zero failures.
- Manual smoke-test in the browser:
  1. Open `https://<host>/client/dashboard.php?code=ABCDE-FGHIJ-KLMNO`
     for a project that has sub-tasks.
  2. Confirm the toggle button is visible. Click it.
     The sub-tasks should appear; the icon should flip
     to `fa-eye-slash`; the label should read "Show
     top-level only" (EN) / "仅显示顶级任务" (中文).
  3. Reload the page. The sub-tasks should still be
     visible (no flash of collapsed content).
  4. Click again. The sub-tasks should hide; the icon
     should flip back to `fa-eye`; the label should
     read "Show all sub-tasks" (EN) / "显示所有子任务"
     (中文).
  5. Open DevTools → Console. There should be **no**
     CSP violation ("Refused to execute inline
     script…"). The Sources panel should show
     `subtask-toggle.js` under the `/client/assets/js/`
     directory, not under `(index)` as an inline
     snippet.
  6. Open DevTools → Application → Local Storage.
     The key `client_dash.subtasks_visible.<share_code>`
     should be present with value `"1"` (after
     expand) or `"0"` (after collapse).

## Out of scope

- Re-adding `'unsafe-inline'` to the client portal CSP.
- Switching to nonces (issue-020 left nonces as a v2.0 item).
- Changing the toggle's UX, state machine, label, icon,
  or `aria-*` semantics.
- Changing `client/assets/client.css`.
- Touching the internal app's CSP.
- Adding build tooling, a bundler, a transpiler, or any
  JS framework.
- Changing the i18n catalog.
- A back-fill / migration. The fix is a single-file
  extraction plus a test update.

## Implementation notes for the developer

The fix is **three files**:

1. **New file `client/assets/js/subtask-toggle.js`** —
   the IIFE body verbatim from
   `client/dashboard.php:372-430`, with three changes
   to remove the server-rendered values:
   - Replace the `var shareCode = <?= json_encode(...) ?>;`
     line with
     `var shareCode = btn.getAttribute('data-share-code') || '';`.
   - Replace the
     `var LABEL_SHOW_ALL = <?= json_encode(t('dash.subtasks_show_all'), ...) ?>;`
     and
     `var LABEL_SHOW_TOP = <?= json_encode(t('dash.subtasks_show_top'), ...) ?>;`
     lines with
     `var LABEL_SHOW_ALL = btn.getAttribute('data-label-show-all') || '';`
     and
     `var LABEL_SHOW_TOP = btn.getAttribute('data-label-show-top') || '';`.
   - The IIFE pattern, the `try`/`catch` guards, the
     `setAttribute` calls, the `aria-hidden` reflection,
     and the `addEventListener('click', …)` wiring are
     all preserved verbatim.
   - Style: `var` only (no `let`/`const`), no arrow
     functions, no template literals — matches
     `assets/js/script.js`. The file is hand-written
     and has no build step.

2. **Edit `client/dashboard.php`**:
   - Add three `data-*` attributes to the toggle
     button (between line 345 and 350): `data-share-code`,
     `data-label-show-all`, `data-label-show-top`. The
     `data-share-code` value is `<?= e($project['share_code']) ?>`;
     the two `data-label-*` values are
     `<?= e(t('dash.subtasks_show_all')) ?>` and
     `<?= e(t('dash.subtasks_show_top')) ?>`. `e()`
     (the existing `htmlspecialchars` wrapper) is the
     same escaper used for the existing attributes.
   - Replace the inline `<script>(function () { … })();</script>`
     block at lines 372-430 with
     `<script src="assets/js/subtask-toggle.js" defer></script>`.
     The `defer` attribute guarantees the script runs
     after the DOM is parsed, matching the original
     end-of-body timing.

3. **Edit `client/toggle_subtasks_test.php`**:
   - Replace the five `strpos($with_subs_html, …)` /
     `preg_match(…)` assertions that look for inline
     markers (`addEventListener('click'`, `localStorage.setItem`,
     `localStorage.getItem`, `data-subtasks` literal,
     the `var shareCode = "…"` assignment) with
     equivalent assertions on the **external file**:
     - `is_file(__DIR__ . '/assets/js/subtask-toggle.js')`
     - `strpos($js, 'addEventListener(\'click\'')` on the
       file contents
     - `strpos($js, 'localStorage.setItem')`
     - `strpos($js, 'localStorage.getItem')`
     - `strpos($js, 'setAttribute(\'data-subtasks\'')` (or
       the equivalent `setAttribute("data-subtasks", …)`)
     - The selector scoped with
       `ul.task-tree:not(.root)`
   - Add a new source-grep layer: the rendered HTML
     for the `with-subs` project must contain
     `<script src="assets/js/subtask-toggle.js" defer>`,
     and must **not** contain a non-CDN, non-src `<script>`
     block (i.e. no `<script>…</script>` whose body
     contains `addEventListener` or `localStorage`).
     The cleanest check is
     `preg_match_all('#<script(?![^>]*\bsrc=)[^>]*>.*?</script>#s', $with_subs_html, $m)`
     returns no matches that contain
     `'addEventListener'`.
   - Add a new layer for the `data-*` attribute
     contract: the rendered button must carry
     `data-share-code="<with-subs share code>"`,
     `data-label-show-all="Show all sub-tasks"`, and
     `data-label-show-top="Show top-level only"`.
   - Remove the now-irrelevant
     `preg_match('#var shareCode = "([^"]*)"#', $with_subs_html, $m)`
     block (the share code is no longer a JS string
     literal — it is a `data-*` attribute on the
     button).
   - Add an additional source-grep layer:
     `client/dashboard.php` source must NOT contain
     a `<script>(function () {` or `<script>\s*\(function`
     block, and MUST contain
     `<script src="assets/js/subtask-toggle.js" defer></script>`.
   - The existing assertion for the
     `<ul class="task-tree root">` and the
     `'(3 total)'` count is unchanged. The
     `must_contain_with_subs` list keeps every line
     except the ones that moved out of the page (the
     `>Show all sub-tasks<` literal still works because
     the span is still server-rendered).

4. **Edit `database/security_headers_test.php`** —
   one comment fix at line 307-309:
   - Change the misleading claim
     "(the only JS is /assets/js/subtask-manager.js)"
     to
     "(the only JS is /client/assets/js/subtask-toggle.js,
     served as an external file via <script src=…> defer)".
   - **No code change in the test itself** — the
     existing assertions (no `'unsafe-inline'` in
     `script-src`, `default-src 'self'` present)
     are still correct and still pass.

The fix is under 100 lines of diff. The
`client/dashboard.php` change is **net negative** (60 lines
removed, ~10 lines added). The new file is ~60 lines.
The test update is ~30 lines of additions and ~10
lines of removals.

### Why the `data-*` attribute approach instead of `data-*` on a hidden `<template>` or `JSON.parse` of a single attribute

Three options were considered for how the JS file learns
the share code and the translations:

- **Option A: `data-*` attributes on the button.** Each
  value is a separate attribute. The JS reads each via
  `btn.getAttribute('data-…')`. This is the simplest,
  most readable, and easiest-to-test option. The
  existing `e()` (htmlspecialchars) is the correct
  escaper. **Chosen.**
- **Option B: a `<script type="application/json" id="subtask-toggle-config">…</script>` tag.** The
  JS does
  `JSON.parse(document.getElementById('subtask-toggle-config').textContent)`.
  This is more compact (one tag instead of three
  attributes) but adds an `id` to look up, a JSON
  parse, and a new pattern that the rest of the
  codebase does not use.
- **Option C: hardcode the share code into the JS file
  per build.** Requires a build step, which the
  project does not have.

Option A wins on simplicity, no-new-patterns, and
testability. The three `data-*` attributes are
declarative, easy to grep, and easy to assert in
`client/toggle_subtasks_test.php`.

### Why the JS file is `var`-only, ES5-compatible

- `assets/js/script.js` is the existing style:
  `var` everywhere, no arrow functions, no
  `const`/`let`, no template literals. The new file
  matches so the diff is stylistically consistent
  with the rest of the codebase.
- The new file is served as a static asset. There is
  no build step. Writing ES5 keeps the file
  immediately readable and prevents "why is this
  `var` not `const`?" questions in code review.
- The new file is **not** loaded as a module (no
  `type="module"`). Module scripts are deferred-by-
  default and have stricter CSP requirements
  (they always need a `nonce` or `'self'`,
  depending on how they're loaded). A plain script
  with `defer` is simpler and works with the existing
  `'self'` allowance in `script-src`.

### What to **not** change

- The CSS rule
  `#tasks-progress[data-subtasks="hidden"] ul.task-tree:not(.root) { display: none; }`.
  It still works because the visible state is the
  browser default for `<ul>` (no CSS rule needed).
- The internal app's CSP. The internal app keeps
  `'unsafe-inline'` in `script-src` for its many
  inline `onclick=` handlers (issue-020's v2.0
  item is to switch to nonces).
- The translation catalog. No new keys.
- The toggle's UX, state machine, label, icon, or
  `aria-*` semantics. All issue-010 invariants.
- The `localStorage` key name
  (`client_dash.subtasks_visible.<share_code>`) and
  the `"0"` / `"1"` value convention.
- The 4×4 state machine parity test in
  `client/toggle_subtasks_test.php:296-353` — the
  state machine is unchanged.
- The nginx config — the existing static-asset
  location serves `.js` files from any path under
  the doc root, so the new file is picked up
  automatically.
- The `includes/security_headers.php` helper. The
  CSP is correct; the assumption that the script
  was external is now true again.
- The `subtask_functions.sql` triggers. They are
  not used by the app; the comment in
  `client/toggle_subtasks_test.php:65-80` already
  documents this.

## Cross-references

- [issue-010](./issue-010.md) — the original "Client
  Dashboard — Toggle to Show / Hide Subtasks" feature.
  The UX, state machine, label / icon / aria-pressed
  contract, per-project localStorage key,
  default-collapsed behaviour, and the
  `ul.task-tree:not(.root)` selector all come from
  issue-010. This issue is a **mechanical
  extraction** of the existing controller, not a
  behaviour change.
- [issue-020](../RELEASE.md#v160--2026-10-15) — "Response
  security headers + session hardening". The CSP
  tightening on the public client portal is the
  cause of the regression; the fix preserves the
  tightening. The misleading comment in
  `includes/security_headers.php:65-71` ("the toggle
  JS is in assets/js/subtask-manager.js") and the
  one in
  `database/security_headers_test.php:307-309` ("the
  only JS is /assets/js/subtask-manager.js") both
  pointed to the wrong file and are the root cause
  of the original false-premise that made the
  regression possible. This issue corrects the
  reality to match the assumption.
- [issue-006](./issue-006.md) — the original "Client
  Portal — Public Code-Keyed Project View" feature.
  Out of scope for this issue, but the page
  structure (`<section class="card client-section" id="tasks-progress">`)
  comes from issue-006.
- [issue-004](./issue-004.md) — moved the public
  client portal to the domain root. Out of scope
  here; the toggle is rendered by
  `client/dashboard.php` regardless of how the user
  reached it.
- [issue-025](./issue-025.md) — strict
  `all-completed` rule for parent task status. Out
  of scope; independent surface.
- [issue-026](./issue-026.md) — align parent status
  with parent completion percentage. Out of scope;
  independent surface. Both ship in the same
  v1.7.1.2 release.
