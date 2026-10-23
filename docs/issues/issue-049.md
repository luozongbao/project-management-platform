# Issue 049 — Client portal: sub-task toggle controller 404'd (dead expand/collapse control)

**Status:** Resolved
**Branch:** `v.2.0`
**Reported by:** plan-sync reconnaissance during Phase 1.5 (client dashboard v2 conversion)

**Depends on:** [issue-027](./issue-027.md), [issue-035](./issue-035.md)

---

## 1. Scope

Phase 1.5 is the conversion of the three public/client-facing surfaces to the
v2 mockup language:

| Surface | File | Phase 1.5 sub-phase |
| --- | --- | --- |
| Client dashboard | `client/dashboard.php` | 1.5a (issue-050) |
| Client landing page | `includes/landing.php` | 1.5b (issue-051) |
| Install wizard | `install.php` | 1.5c (issue-052) |

Before touching the markup, the Phase 1.5 reconnaissance audited the client
dashboard for behavioural regressions. One live defect was found: the
sub-task expand/collapse control on the client portal was **completely dead**
because its controller script was never loaded.

---

## 2. Defect — `subtask-toggle.js` was referenced at a path that 404s

### 2.1 Symptom

On the client dashboard (`/client/dashboard.php?code=…`) the "Show all
sub-tasks" button rendered, but clicking it did nothing: the nested sub-task
lists stayed collapsed, and the button label never changed.

### 2.2 Root cause

The controller lives at:

```
client/assets/js/subtask-toggle.js
```

But `client/dashboard.php` referenced it as:

```html
<script src="/assets/js/subtask-toggle.js" defer></script>
```

`/assets/js/subtask-toggle.js` **does not exist** — the file is under the
`client/` subtree. The request 404'd, so the controller never executed and the
button remained inert.

Verified over HTTP:

| URL | Status |
| --- | --- |
| `/assets/js/subtask-toggle.js` | **404** |
| `/client/assets/js/subtask-toggle.js` | **200** |

The path was introduced by commit `58ca68e (issue027)`, which shipped the tag
as the client-relative `src="assets/js/subtask-toggle.js"` (correct for a page
served under `/client/`). A later edit converted it to a root-relative path but
dropped the `client/` segment.

### 2.3 Why the regression test did not catch it

`client/toggle_subtasks_test.php` asserted the **broken** path string, so the
test actively pinned the bug:

```php
$expected_script_tag = 'src="/assets/js/subtask-toggle.js" defer';
```

A regression test can pin a bug. The fix therefore required correcting the
test's expectation as well as the application code.

---

## 3. Fix

### 3.1 Path form — root-absolute

`docs/issues/issue-035.md` §"The fix — every asset reference becomes
root-absolute" mandates the **root-absolute** form for this exact file:

```html
<script src="/client/assets/js/subtask-toggle.js" defer></script>
```

That form was adopted. It satisfies the issue-035 asset-path contract,
resolves correctly from any URL depth, and matches the root-absolute
convention being applied to the landing page in issue-051.

### 3.2 Files changed

| File | Change |
| --- | --- |
| `client/dashboard.php` | Script tag path corrected to `/client/assets/js/subtask-toggle.js`; issue-049 comment added explaining the path contract |
| `client/toggle_subtasks_test.php` | Expected tag updated to the root-absolute form; new **negative** assertion added to catch a regression back to the 404 path; no-subs / no-tasks needle arrays updated; docblock note added |
| `_diag.php` | `$expected_md5['client/dashboard.php']` re-bumped from `cd97c6477c9b2a144ebd53d6a926d6c5` to `8134855c9ec84d7741bae7d0edcd2aea` (mandatory — the file changed) |

`client/assets/js/subtask-toggle.js` itself is **unchanged** — its own header
comment already documented the correct relative path.

### 3.3 The new negative assertion

```php
if (strpos($with_subs_html, 'src="/assets/js/subtask-toggle.js"') !== false) {
    $failures[] = 'with-subs dashboard references /assets/js/subtask-toggle.js — that path 404s; '
        . 'the file lives at client/assets/js/subtask-toggle.js and must be referenced as '
        . '"/client/assets/js/subtask-toggle.js"';
}
```

This pins the **failure mode** rather than merely the happy path, so any future
edit that reintroduces the missing `client/` segment fails the test.

---

## 4. Verification

### 4.1 Static

| Check | Result |
| --- | --- |
| `php -l client/dashboard.php` | clean |
| `php -l client/toggle_subtasks_test.php` | clean |
| `docker compose exec -T app php /var/www/html/client/toggle_subtasks_test.php` | **"Sub-tasks toggle regression test passed."** (exit 0) |
| Full regression sweep (20 tests) | **20 PASS / 0 FAIL** |

The md5 pin was re-bumped in the same commit, as required by
`database/probe_diag_test.php` (issue-029 contract). The sweep failed with
`19 PASS / 1 FAIL` **before** the pin was updated — which confirms that test is
live and enforcing.

### 4.2 Browser (VS Code integrated browser, client portal code `388YY-CZV2W-R99BA`)

Page reloaded with `console` and `pageerror` listeners attached, then the
toggle was clicked.

**Before click:**

```json
{
  "sectionExists": true,
  "buttonExists": true,
  "dataSubtasks": "hidden",
  "buttonState": "hidden",
  "ariaPressed": "false",
  "buttonLabel": "Show all sub-tasks",
  "nestedTrees": 1,
  "nestedHidden": ["true"]
}
```

**After click:**

```json
{
  "dataSubtasks": "visible",
  "buttonState": "visible",
  "ariaPressed": "true",
  "buttonLabel": "Show top-level only",
  "nestedHidden": ["false"],
  "rootHidden": null
}
```

- **Console errors: `[]`** — zero console errors, zero page errors.
- `aria-hidden` on the nested tree flips `true → false`; the **root** tree keeps
  `aria-hidden: null` (i.e. always visible), which is correct.
- The rendered snapshot shows `Subtask 1` (Completed, 100%), `Subtask 2`
  (Completed, 100%) and `Sub Task3` (On hold, 40%) expanded under `Task 2`.

---

## 5. Red lines observed

- `share_code` stays `CHAR(17)` 5-5-5; `generateShareCode()` untouched.
- `isValidShareCodeFormat()` regex untouched.
- `id="code" name="code"` + `pattern` + `placeholder` untouched.
- `assets/js/share-code-format.js` untouched.
- AJAX endpoint paths untouched.
- `/client/dashboard.php?code=…` URL unchanged.
- Client-relative asset paths elsewhere left as-is.
- nginx `/client/` block and `.htaccess` denies untouched.
- `_diag.php` md5 pin re-bumped in the same commit as the source change.

---

## 6. Follow-up (not part of this issue)

The Phase 1.5 surfaces still carry 100% legacy `client-*` CSS classes and are
scheduled for conversion:

- **issue-050** — client dashboard → `docs/design/2.0/client-dashboard.html`
- **issue-051** — client landing → `docs/design/2.0/landing.html`
- **issue-052** — install wizard → v2 (landing-page visual language)
