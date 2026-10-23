# Issue-050 — Client Dashboard: v2.0 markup + stylesheet layering

**Status:** Complete
**Branch:** `v.2.0`
**Scope:** Phase 1.5b (client portal, part 2 of 2) — the share-code dashboard
**Mockup:** `docs/design/2.0/client-dashboard.html` / `docs/design/2.0/v2-tokens.css` §6

---

## 1. Problem

`client/dashboard.php` was the last page of the internal app still on the
v1.8.0 vocabulary. It rendered its own private class names
(`.client-header`, `.client-body`, `.client-card`, `.client-hero`,
`.client-info-grid`, `.project-summary`, …) and linked only three
stylesheets:

```html
<link rel="stylesheet" href=".../assets/css/v2-tokens.css">
<link rel="stylesheet" href=".../assets/css/style.css">
<link rel="stylesheet" href=".../client/assets/client.css">
```

Two consequences:

1. **The shared v2 component layer was never loaded.** `assets/css/v2-components.css`
   is the layer that owns `.card`, `.card__header`, `.card__body`,
   `.card__actions`, `.card__title-icon`, `.status--*`, `.badge--*`,
   `.reason-chip--*`, `.progress`, `.progress__fill`, `.task-node`,
   `.task-tree`, `.btn--*`, `.empty-state` and `.footer`. Because the client
   portal is served *outside* `includes/header.php` (it has no internal
   app-shell, no sidebar, no topbar), it never opted in — the header's
   `if (!empty($use_v2_shell))` guard never fired for it.

   The result was visible in the browser: the page had the *v2 markup
   shape* but none of the v2 *styling*. Computed styles showed
   `cardBorder: 0px none`, `nodeBorder: 0px none`, `nodePad: 0px`,
   `badgeBg: rgba(0,0,0,0)` — the component rules simply were not in the
   cascade.

2. **Two parallel vocabularies for the same concept.** The client portal
   re-implemented card / badge / progress / button styling locally in
   `client/assets/client.css` with hard-coded hex values. Every fix had to
   be made twice, and the client portal could never pick up a shared
   component improvement.

## 2. Root cause

`includes/header.php` is the only place that loaded `v2-components.css`,
and it loads it conditionally:

```php
<?php if (!empty($use_v2_shell)): ?>
<link rel="stylesheet" href="<?= e(asset_url('/assets/css/v2-components.css')) ?>">
<?php endif; ?>
```

The client portal and the public landing page both render their own
`<head>` and therefore never reached that code path.

## 3. Fix

### 3.1 Load the component layer explicitly (all three client `<head>` blocks)

`client/dashboard.php` has three separate `<head>` blocks — the main render
plus the two error renders (rate-limited, invalid code). Each one now
loads the layer in the canonical order:

```
v2-tokens.css -> style.css -> v2-components.css -> client/assets/client.css
```

This is the same order the internal app uses (`includes/header.php`
lines 45–73). `client/assets/client.css` stays last so the client-specific
deltas win at equal specificity.

The comment is written as a **block comment** (`/* … */`), not `//`.
`database/about_popup_test.php` (tester-note-002 bug 5) rejects any file
that embeds `<?php` or `?>` inside a `//` line comment, because it changes
the file's semantics. A multi-line `//` block whose last line ends in `?>`
trips that guard.

`includes/landing.php` got the same link. It is not converted yet — that
is issue-051 — but the layer has to be in the document before the landing
page can be converted to the v2 vocabulary, and the landing page's own
legacy classes are unaffected by it (they resolve in `style.css`).

### 3.2 Root-absolute client CSS URL

`includes/landing.php` linked the client stylesheet by bare absolute path:

```html
<link rel="stylesheet" href="/client/assets/client.css">
```

Per `docs/issues/issue-035.md` §"Asset paths" every asset reference is
root-absolute **and** versioned via `asset_url()`. The bare path also
bypassed the cache-busting query string, so a client CSS change could be
served stale for 30 days (nginx caches assets). Now:

```html
<link rel="stylesheet" href="<?= e(asset_url('/client/assets/client.css')) ?>">
```

### 3.3 Fix the duplicated `border` declaration

`client/assets/client.css` had:

```css
.client-body .card {
    background: var(--gray-0);
    border: 1px solid var(--gray-200);
    border-radius: var(--r-lg);
    box-shadow: var(--sh-sm);
    border: none;          /* <-- cancelled the border above */
}
```

The second `border` won, so the card outline was silently gone
(computed: `0px none rgb(35,42,59)`). One owner, one value — the
duplicate line is removed and the border kept.

### 3.4 Tokenise the sub-task toggle

`.subtasks-toggle` still used hard-coded `8px / 0.8rem / 4px 10px / 6px`
values, and `.subtasks-toggle-label` — which the toggle controller reads
(`btn.querySelector('.subtasks-toggle-label')`) — had **no CSS rule at
all**. Both are now expressed in v2 tokens, and the label span gets an
explicit rule so it stays legible at every density.

The toggle's red-line contract is untouched:

* `#tasks-progress[data-subtasks="hidden"] ul.task-tree:not(.root)`
* `<section id="tasks-progress" data-subtasks="hidden|visible">`
* `<button id="subtasks-toggle" data-state data-share-code
  data-label-show-all data-label-show-top aria-controls aria-pressed>`
* `<span class="subtasks-toggle-label">`
* localStorage key `client_dash.subtasks_visible.<share code>`

## 4. Verification

### 4.1 Browser (VS Code integrated browser, Playwright)

`http://localhost/client/dashboard.php?code=388YY-CZV2W-R99BA`

| Check | Result |
|---|---|
| Console errors | **0** |
| Failed requests / HTTP ≥ 400 | **0** |
| Loaded stylesheets | `v2-tokens.css`, `style.css`, `v2-components.css`, `client.css`, Font Awesome |
| `.card` background | `rgb(255,255,255)` |
| `.card` border | `1px solid rgb(226,231,240)` |
| `.card__header` background | `rgb(251,252,254)` |
| `.task-node` border / padding / radius | `0.8px solid rgb(226,231,240)` / `12px 16px` / `10px` |
| `.badge--danger` bg / fg | `rgb(251,220,220)` / `rgb(160,27,27)` |
| `.status--not_started` bg / fg | `rgb(226,231,240)` / `rgb(54,63,86)` |
| `.reason-chip--on-hold` bg | `rgb(253,240,201)` (amber) |
| `.progress__fill` | `rgb(51,102,255)` (brand) |
| `.card__title-icon` colour | `rgb(51,102,255)` |

Dark mode (`prefers-color-scheme: dark`) also verified — the shared
`v2-components.css` §10 dark block flips the gray scale and the page
renders dark consistently. **This is intended behaviour**: the mockup
stylesheet `docs/design/2.0/v2-tokens.css` has the same block at line
1328, so dark mode is a designed feature, not a defect.

### 4.2 Sub-task toggle cycle

| State | `data-subtasks` | `aria-pressed` | Label | Nested tree visible |
|---|---|---|---|---|
| initial | `visible` | `true` | "Show top-level only" | yes |
| after click | `hidden` | `false` | "Show all sub-tasks" | **no** |
| after 2nd click | `visible` | `true` | "Show top-level only" | yes |

localStorage key written: `client_dash.subtasks_visible.388YY-CZV2W-R99BA`.
Console errors: 0.

### 4.3 CSS token resolution

```bash
grep -ohE 'var\(--[a-z0-9-]+' client/assets/client.css | sed 's/var(//' | sort -u > /tmp/u.txt
grep -ohE '(^|[;{ ])--[a-z0-9-]+' assets/css/v2-tokens.css | tr -d ';{ ' | sort -u > /tmp/d.txt
comm -23 /tmp/u.txt /tmp/d.txt     # empty
```

**0 undefined tokens** in `client/assets/client.css` and in
`assets/css/v2-components.css`.

### 4.4 Class resolution

Every class emitted by the client dashboard resolves to a rule in
`assets/css/pages/*.css` + `v2-components.css` + `style.css` +
`client/assets/client.css`. **0 missing** (the only one found initially,
`.subtasks-toggle-label`, is now defined — §3.4).

The public landing page was checked the same way: **0 missing** (it is
still on the v1 vocabulary; issue-051 converts it).

### 4.5 `php -l`

```
No syntax errors detected in client/dashboard.php
No syntax errors detected in includes/landing.php
No syntax errors detected in includes/functions.php
```

### 4.6 Regression suite

**20 PASS / 0 FAIL** — back to the established baseline.

Three tests needed their expectations updated to follow the markup
change (the assertion *intent* is preserved in every case):

| Test | Old expectation | New expectation | Why |
|---|---|---|---|
| `client/toggle_subtasks_test.php` | `preg_match('/\(3 total\)/')` | `.badge--neutral` chip containing `3 total` | the count moved from inline v1 text `(3 total)` into a v2 badge in `.card__actions` |
| `database/task_status_reasons_test.php` | `strpos($cdash, 'reason-pill')` | `reason-chip` **plus** both `reason-chip--on-hold` and `reason-chip--delay` | the page-local `.reason-pill` became the shared `.reason-chip`; issue-034 asked for two *distinct, colour-coded* chips, so the new assertion is **stricter** than the old one |
| `database/probe_diag_test.php` | md5 pin `942e94a1…` | md5 pin `7b90d58e…` | `client/dashboard.php` changed — the pin is re-bumped in the same commit, as the red line requires |

## 5. Red lines respected

* `assets/js/share-code-format.js` — untouched.
* `share_code` `CHAR(17)` / `generateShareCode()` / `isValidShareCodeFormat()` — untouched.
* `id="code" name="code"` + `pattern` + `placeholder` + inline style — untouched.
* AJAX endpoint paths — untouched.
* Query-parameter names — untouched.
* `/client/dashboard.php?code=…` URL — unchanged.
* nginx `/client/` block, `.htaccess` — untouched.
* The sub-task toggle contract (issue-010 / issue-027) — intact, verified by the cycle test in §4.2.
* `_diag.php` md5 pins re-bumped in the same commit as the `client/dashboard.php` change.

## 6. Follow-up

* **issue-051** — public landing page (`includes/landing.php`): convert the
  legacy `client-*` vocabulary to the mockup's `landing` / `landing__card` /
  `landing__code-input` / `landing__foot` / `landing__logo` /
  `auth-card__divider` vocabulary, and drop the legacy hard-coded
  `client/assets/client.css` block for the landing page.
* **issue-052** — installation page (`install.php`): remove the 79-line
  inline `<style>` block and adopt the landing page's visual language.
* **issue-053** — Phase 1.6: strip the 94 dead rules from
  `assets/css/style.css` once every page is converted.
