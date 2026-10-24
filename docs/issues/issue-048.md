# Issue 048 — Edit-mode look defects: stuck delete modal, missing Project ID / share code, task delete confirmation

**Status:** Resolved
**Branch:** `v.2.0`
**Reported by:** user ("all 3 pages New mode look fine. for edit mode — Projects/<id>/edit:
missing project ID and renew code (for 858); delete pop up show on load/ and can not
close make user can not do anything; to confirm project deletion use project code (Correct).
Tasks/<id>/edit: delete pop up show on load/ and can not close make user can not do
anything; should use Tasks/contact name for confirmation (should fix).")

**Depends on:** [issue-047](./issue-047.md)

---

## 1. Scope

Issue 047 converted the three New/Edit pages to the v2 mockup. A follow-up
browser pass on the **edit** modes only surfaced four defects:

| # | Defect | Severity |
| --- | --- | --- |
| A | The delete-confirmation modal rendered open on page load and could not be closed — the page was unusable | Blocking |
| B | A corrected stylesheet did not reach the browser for up to 30 days (nginx asset cache) | High |
| C | `Projects/<id>/edit` showed neither the Project ID nor a way to obtain a share code when `share_code IS NULL` | Medium |
| D | `Tasks/<id>/edit` asked the user to type the internal task **code** to confirm deletion, instead of the task **name** | Medium |

The user explicitly confirmed two behaviours that are **correct** and must not
change: project deletion is confirmed with the **project code**, and contact
deletion is confirmed with the **contact name**. Only the task flow was wrong.

---

## 2. Defect A — the delete modal rendered open on page load

### 2.1 Symptom

On `Projects/<id>/edit` and `Tasks/<id>/edit` the delete-confirmation modal was
painted over the whole viewport as soon as the page loaded. Because the
backdrop is `position: fixed; inset: 0; z-index: 300`, it intercepted every
click on the page behind it, so the user "can not do anything".

### 2.2 Root cause

The modal markup is correct — it carries the `hidden` attribute:

```html
<div class="modal-backdrop" id="confirm-delete-task" hidden>
```

But the UA stylesheet's `[hidden] { display: none }` rule has the **lowest
possible specificity** (a single attribute selector, specificity 0-1-0). The v2
component stylesheet sets `display` on the same element via a **class**
selector:

```css
.modal-backdrop {
    display: grid;
    place-items: center;
}
```

A class selector (0-1-0) with the same specificity resolves later in the
cascade, so `.modal-backdrop { display: grid }` won and the `hidden` attribute
became inert. The JS in `assets/js/script.js` (`initModals()`) was correct all
along — it sets `backdrop.hidden = true` on close — the attribute simply had no
effect on the rendered box.

Measured in the browser before the fix:

```json
{
  "hasHiddenAttr": true,
  "display": "grid",
  "visibility": "visible",
  "zIndex": "300",
  "position": "fixed",
  "topElementAtCenter": "alert alert--danger"
}
```

### 2.3 Fix

A single global rule in `assets/css/v2-components.css`, placed immediately
above the `.modal-backdrop` block:

```css
[hidden] { display: none !important; }
```

The `!important` is deliberate. `hidden` is used on several element types
(`.modal-backdrop`, `.hint`, `.form-section`, `.req`); only the modal backdrop
sets `display`, but a scoped `.modal-backdrop[hidden]` rule would leave the
other users at the mercy of whatever a future stylesheet does to `display`.
The global rule is the standard fix and is what the HTML spec's "hidden"
behaviour expects.

### 2.4 Verification

| State | `display` | `hidden` attr | `body` overflow |
| --- | --- | --- | --- |
| On load | `none` | present | unset |
| After opening | `grid` | removed | `hidden` |
| After closing | `none` | present | unset |

Checked in the integrated browser on all three edit pages
(`/tasks/9/edit`, `/projects/858/edit`, `/contacts/249/edit`) with a full
open → close cycle via the ✕ button.

---

## 3. Defect B — the 30-day asset cache hid the fix

### 3.1 Symptom

After the CSS fix landed, the browser **still** showed the broken modal. The
stylesheet request came back with:

```
Expires: Sun, 22 Nov 2026 19:28:29 GMT
Cache-Control: public, max-age=2592000
```

`docker/nginx.conf` serves every static asset with `expires 30d;`. That is
right for images and fonts, but a **corrected stylesheet** would not reach a
returning browser for up to a month — actively harmful during a redesign,
where every iteration changes the CSS.

### 3.2 Fix — `asset_url()` cache-busting

A new helper in `includes/functions.php` appends the file's mtime to
root-relative asset URLs:

```php
function asset_url($path) {
    static $mtimes = [];

    // Only local, root-relative asset paths are versioned. Absolute URLs
    // (the Font Awesome CDN) and the client portal's relative paths
    // (assets/client.css) are passed through untouched.
    if (!is_string($path) || $path === '' || $path[0] !== '/') {
        return $path;
    }

    if (!array_key_exists($path, $mtimes)) {
        $file = __DIR__ . '/..' . $path;
        $mtimes[$path] = is_file($file) ? (string) filemtime($file) : '1';
    }

    $sep = strpos($path, '?') === false ? '?' : '&';
    return $path . $sep . 'v=' . $mtimes[$path];
}
```

The URL changes exactly when the file changes, so the browser fetches the new
bytes; unchanged files keep their cached copy. The mtime is read from disk on
first use per request and memoised, so a page linking five stylesheets stats
five files once.

### 3.3 Coverage

Every local stylesheet and script reference now goes through `asset_url()`:

| File | Links versioned |
| --- | --- |
| `includes/header.php` | `v2-tokens.css`, `style.css`, `v2-components.css`, `subtask-manager.js` |
| `includes/footer.php` | `script.js` |
| `includes/Router.php` | the two links on the 404 error page |
| `includes/landing.php` | `v2-tokens.css`, `style.css` |
| `install.php` | `v2-tokens.css`, `style.css` |
| `client/dashboard.php` | all three `<head>` blocks |
| 15 page files | each page-level `assets/css/pages/*.css` |

Deliberately **not** versioned: the Font Awesome CDN URL and the client
portal's relative `assets/client.css` — neither is served by this origin, so
neither is cached by nginx with a 30-day TTL.

Rendered result:

```
href="/assets/css/v2-tokens.css?v=1792685249"
href="/assets/css/style.css?v=1792685447"
href="/assets/css/v2-components.css?v=1792783683"
src="/assets/js/script.js?v=1792780458"
```

### 3.4 Diagnostic probe re-pinned

`_diag.php` pins the md5 of three files that this change touched. All three
were recomputed and updated in the same commit:

| File | Old md5 | New md5 |
| --- | --- | --- |
| `includes/header.php` | `7eef73e2…` | `04947db7…` |
| `includes/functions.php` | `376bc27e…` | `f3b67df8…` |
| `client/dashboard.php` | `6ee95006…` | `cd97c647…` |

`config.php` and `client/lang.php` were not touched and keep their pins.

---

## 4. Defect C — missing Project ID and no way to obtain a share code

### 4.1 Symptom

On `Projects/858/edit` the user saw neither a project ID nor a "renew code"
action. The cause is that `project_edit.php` wrapped the **entire** Client-share
card — and the **entire** delete modal — in a single guard:

```php
if ($project && !empty($project['share_code'])):
```

A project whose `share_code` is `NULL` therefore rendered:

- no "Code …" in the page header sub,
- no Client-share card at all (so no rotate action),
- no delete modal,
- and a Danger zone whose Delete button was `disabled`, with the copy
  "This project has no Project Code, so it cannot be deleted from here."

Projects created through the normal flow always get a code, but projects
created by a test round-trip or a direct DB insert can end up with
`share_code = NULL` — and then the manager is stuck: the project can neither be
shared nor deleted.

### 4.2 Fix

Three changes, all in `project_edit.php`:

1. **The header sub always shows the numeric Project ID**, and says so
   explicitly when there is no code:

   ```php
   <p class="page-header__sub">
       <?= e($project['name']) ?>
       &middot; Project ID <?= (int)$project['id'] ?>
       <?php if (!empty($project['share_code'])): ?>
           &middot; Code <?= e($project['share_code']) ?>
       <?php else: ?>
           &middot; <span class="text-muted">No Code</span>
       <?php endif; ?>
   </p>
   ```

   Rendered: `Live Check Proj · Project ID 858 · Code MATVR-E9MDX-J8ZBM`

2. **The Client-share card is now always rendered.** When a code exists it is
   shown with the copy button, the "Last rotated" line, the 90-day nudge and
   the "Rotate share code" action, exactly as before. When no code exists the
   card shows an info alert and a **"Generate share code"** button.

   The generate action reuses the **existing** `action=rotate_share_code`
   handler, which already generates a fresh code and stamps
   `share_code_rotated_at` — it just needed to stop being unreachable. The
   rotate button's `onclick="return confirm(...)"` warning is only attached to
   the rotate variant; there is no previous URL to invalidate when generating
   the first code.

3. **The Danger zone explains how to unblock itself** rather than dead-ending:

   > This project has no Project Code yet, and the code is the delete
   > confirmation secret. Generate one in **Client share** above, then delete
   > it here.

   The Delete button stays `disabled` until a code exists, because the
   project code **is** the delete confirmation secret (confirmed correct by
   the user).

The delete modal itself remains inside the `share_code` guard, which is
correct: while the Delete button is disabled the modal is unreachable, and
rendering a modal whose confirmation secret does not yet exist would be
misleading.

### 4.3 Verification

A code-less project (`share_code = NULL`) was created directly in the DB and
loaded in the browser:

- Header: `ZZ CodeLess Test · Project ID 1640 · No Code`
- Client share card: info alert + **Generate share code** button, no rotate button
- Danger zone: explanatory copy, Delete button `disabled`
- Clicking **Generate share code** → code `3JUQY-E3SQ3-W48BH` created, toast
  "Share code rotated. The previous URL is now invalid.", and the page
  re-renders with the full share card, the "Type the project code to confirm"
  field and an **enabled** Delete button.
- The delete modal then opens, shows the project code, and closes again.

The test project was removed afterwards.

---

## 5. Defect D — task delete confirmed with the internal code

### 5.1 Symptom

`Tasks/<id>/edit` asked the user to type `TSK-1419` — a code derived from the
row id, shown nowhere else in the UI — to confirm deletion. The contact flow
already asked for the contact's **name**, and the user asked for the task flow
to match.

### 5.2 Fix

**`task_edit.php`** — the modal now names the task:

- header copy: "Type the task name exactly as shown to confirm."
- alert title: the task name instead of the code
- field label: `Task Name` instead of `Task Code`
- input: `name="confirmation_name"`, placeholder = the task name
- a `.hint` line mirroring the project modal: "Must exactly match
  **\<task name\>**."

**`task_delete.php`** — the comparison now uses the name:

```php
$task_name = (string)$task['name'];

$confirmation = $_POST['confirmation_name'] ?? null;
if (!is_string($confirmation) || !hash_equals($task_name, trim($confirmation))) {
    redirect(
        '/tasks/' . $task_id . '/edit',
        'The Task Name did not match. The task was not deleted.',
        'danger'
    );
}
```

`hash_equals()` is retained so the check is not timing-leaky. The comparison
is case-sensitive and trimmed, matching the contact-delete flow.

The POST field name changed from `confirmation_code` to `confirmation_name`.
Both sides of the pair changed together — the old name is read nowhere else
in the codebase.

### 5.3 Verification

Against a task named `Live Check Task`:

| Posted value | Result |
| --- | --- |
| `TSK-1419` (the old code) | HTTP 302 → `/tasks/1419/edit`, task **kept** |
| `Live Check Task` | HTTP 302 → `/tasks`, task **deleted** |

### 5.4 What did not change

| Flow | Confirmation secret | Status |
| --- | --- | --- |
| Project delete | project code | unchanged (user confirmed correct) |
| Contact delete | contact name | unchanged (already correct) |
| Task delete | **task name** | changed (this issue) |

---

## 6. Preserved invariants

- `share_code` stays `CHAR(17)` in the 5-5-5 format; `generateShareCode()` and
  `isValidShareCodeFormat()` are untouched.
- The `id="code" name="code"` field, its `pattern` and its `placeholder` are
  untouched; `assets/js/share-code-format.js` is untouched.
- The "Rotate code" action, `share_code_rotated_at`, the "Last rotated" line
  and the 90-day rotation nudge all still render exactly as before for a
  project that has a code.
- Rate-limit caps and ordering are untouched.
- AJAX endpoint paths are untouched.
- No `name=`/`id=` attribute read by an unchanged PHP file was renamed.
- No query parameter was renamed.
- `/client/dashboard.php?code=…` is unchanged.
- The client portal's relative asset paths are unchanged.
- The nginx `/client/` block and the `.htaccess` denies are unchanged.
- `.app-about` CSS rules remain in `assets/css/style.css`.
- `includes/header.php` keeps both `<details class="app-about">` blocks, the
  two `this.closest('details').open=false` calls, the `stopPropagation` call
  and both `getLatestReleaseSummary(` calls — `database/about_popup_test.php`
  still passes.

---

## 7. Regression tests

No new test file was added; the existing suite covers the touched code:

| Test | Covers |
| --- | --- |
| `database/about_popup_test.php` | the `header.php` structure changed by `asset_url()` |
| `database/probe_diag_test.php` | the `_diag.php` md5 pins re-bumped in §3.4 |
| `database/task_edit_idor_test.php` | the `task_edit.php` delete modal |
| `database/task_status_reasons_test.php` | the `task_edit.php` form |
| `database/client_share_code_protection_test.php` | the share-code contract |

Sweep result:

```
=== TOTAL: 20 PASS / 0 FAIL ===
```

---

## 8. Verification summary

| Check | Result |
| --- | --- |
| `php -l` on all 25 changed PHP files | no syntax errors |
| CSS token resolution (`var(--…)` vs `v2-tokens.css`) | all 13 stylesheets resolve |
| Class resolution on the three edit pages | no new unresolved classes |
| Regression sweep | 20 PASS / 0 FAIL |
| Browser: modal hidden on load, opens, closes | all 3 edit pages pass |
| Browser: `body` overflow restored after close | pass |
| Browser: code-less project → Generate share code → Delete enabled | pass |
| Browser: task delete with wrong / correct confirmation | rejected / accepted |

---

## 9. Files changed

| File | Change |
| --- | --- |
| `assets/css/v2-components.css` | `[hidden] { display: none !important; }` |
| `includes/functions.php` | new `asset_url()` helper |
| `includes/header.php` | 4 asset links versioned |
| `includes/footer.php` | `script.js` versioned |
| `includes/Router.php` | 404 page CSS links versioned |
| `includes/landing.php` | 2 CSS links versioned |
| `install.php` | 2 CSS links versioned |
| `client/dashboard.php` | 3 `<head>` blocks versioned |
| 15 page files | page-level CSS link versioned |
| `_diag.php` | 3 md5 pins re-bumped |
| `project_edit.php` | Project ID in header; share card always rendered; Generate share code; Danger-zone copy |
| `task_edit.php` | delete modal confirms with the task name |
| `task_delete.php` | compares the task name |

---

## 10. Notes for the next session

- The `[hidden]` rule is global. If a future component needs to show a
  `hidden` element on purpose, it must override it explicitly — that is
  intentional.
- `asset_url()` only versions paths that start with `/`. A relative path
  (the client portal) or an absolute URL (the Font Awesome CDN) is returned
  unchanged.
- The `_diag.php` md5 pins are a release-time artefact. Any change to
  `includes/header.php`, `includes/functions.php` or `client/dashboard.php`
  must be re-pinned in the same commit.
