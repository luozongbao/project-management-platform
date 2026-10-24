# issue-055 — Browser-found bugs: copy button, task dates, client language switch, forgot password

## Summary

Four defects were found by manually exercising the application in a browser. They
are unrelated to each other and each has a different root cause, so they are
specified separately below.

| # | area | symptom | root cause | severity |
|---|---|---|---|---|
| 1 | Copy button | Clicking the copy button does nothing | `initCopy()` has no fallback and no error handling | **High** — feature is dead on any non-secure origin |
| 2 | Task form dates | "Expected completion" and "Completion date" do not work | Input types, validation formats, and DB column types disagree | **High** — time is silently discarded |
| 3 | Client dashboard | Language switcher does not match the mockup | Active state is scoped to the wrong container; switcher uses links, mockup uses buttons | **Medium** — visual only |
| 4 | Forgot password | Textbox styling wrong; error not where expected | Markup drifted from the mockup; error block placement | **Low** — cosmetic |

> **Scope note.** The v2 mockups in `docs/design/2.0/` are the visual source of
> truth. Where this issue says "match the mockup", the mockup HTML/CSS is the
> reference — but where the mockup itself reproduces a functional defect
> (bug 2), the mockup must be corrected as part of the fix.

---

## Bug 1 — "Copy link" button does nothing

### Symptom

On the project pages, clicking the copy button next to the share code / share URL
appears to do nothing: no "Copied" confirmation, no icon change, no toast.

### Root cause: `initCopy()` has neither a fallback nor error handling

There are **two independent copy implementations** in the codebase, and only
one of them is robust.

| path | implementation | fallback | error handling | used by |
|---|---|---|---|---|
| A | `assets/js/script.js:641` `initCopy()` | **none** | **none** | `project_edit.php:439` |
| B | `project_detail.php:485` `copyShareUrl()` | `document.execCommand('copy')` → `navigator.clipboard` | try/catch on `execCommand`; `.catch()` on the promise | `project_detail.php:153` |

Path A is the one that is broken:

```js
// assets/js/script.js:641
function initCopy() {
    document.querySelectorAll('[data-copy]').forEach(function(btn) {
        btn.addEventListener('click', function() {
            var text = btn.getAttribute('data-copy');
            var label = btn.innerHTML;
            if (navigator.clipboard && navigator.clipboard.writeText) {
                navigator.clipboard.writeText(text);
            }
            btn.innerHTML = '<i class="fas fa-check"></i> Copied';
            setTimeout(function() { btn.innerHTML = label; }, 1600);
        });
    });
}
```

Three separate defects are visible here:

1. **No `execCommand` fallback.** The Async Clipboard API
   (`navigator.clipboard`) is only exposed in a **secure context** — HTTPS, or
   `localhost`. On any plain-HTTP origin the property is `undefined`, so the
   `if` guard fails and **nothing is copied**. Path B already solves this.
2. **The promise is neither awaited nor `.catch()`-ed.** Even on HTTPS,
   `navigator.clipboard.writeText()` returns a promise that rejects when the
   document is not focused or permission is denied. An unhandled rejection
   means the UI still claims "Copied" while the clipboard was never written.
3. **The guard is dead code.** When `navigator.clipboard` is `undefined`,
   the code silently skips the copy and *still* renders "Copied" — the button
   lies to the user.

Verified in Chromium (Playwright), non-secure origin:

```
secureContext: false
hasClipboard:  false
navigator.clipboard.writeText(...) -> TypeError: Cannot read properties of
                                     undefined (reading 'writeText')
```

The `TypeError` is thrown synchronously inside the click handler, so the
handler aborts before `btn.innerHTML = '<i class="fas fa-check"></i> Copied'`
is reached. The button appears completely dead.

### Fix

**1a. Replace `initCopy()` with a single shared helper that has a real
fallback chain and honest error feedback.**

```js
// assets/js/script.js
function pmpCopyText(text) {
    // Preferred: Clipboard API (HTTPS / localhost only).
    if (navigator.clipboard && navigator.clipboard.writeText) {
        return navigator.clipboard.writeText(text);
    }

    // Fallback: execCommand. Deprecated but still the only option on
    // plain-HTTP origins and inside older WebViews.
    return new Promise(function (resolve, reject) {
        var ta = document.createElement('textarea');
        ta.value = text;
        ta.setAttribute('readonly', '');
        ta.style.position = 'fixed';
        ta.style.top = '-1000px';
        ta.style.opacity = '0';
        document.body.appendChild(ta);
        ta.select();
        ta.setSelectionRange(0, ta.value.length);
        var ok = false;
        try {
            ok = document.execCommand('copy');
        } catch (e) {
            ok = false;
        }
        document.body.removeChild(ta);
        ok ? resolve() : reject(new Error('Copy command was rejected.'));
    });
}

function initCopy() {
    document.querySelectorAll('[data-copy]').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var text = btn.getAttribute('data-copy');
            var label = btn.innerHTML;

            pmpCopyText(text).then(function () {
                btn.innerHTML = '<i class="fas fa-check"></i> Copied';
                btn.classList.add('is-copied');
            }).catch(function () {
                // Do not claim success when the clipboard was not written.
                btn.innerHTML = '<i class="fas fa-circle-exclamation"></i> Copy failed';
                btn.classList.add('is-copy-failed');
            }).then(function () {
                setTimeout(function () {
                    btn.innerHTML = label;
                    btn.classList.remove('is-copied', 'is-copy-failed');
                }, 1600);
            });
        });
    });
}
```

> `initCopy()` must stay **synchronous** in its DOMContentLoaded
> registration — only the copy *operation* is asynchronous. Do not turn the
> function itself into an async callback, or the listener registration will
> race with `DOMContentLoaded`.

**1b. Make `project_detail.php` use the same shared helper** so there is one
implementation, not two that drift apart.

The existing inline `copyShareUrl(btn)` already has the fallback chain, so it
works today. It is not the bug. It should be refactored to call `pmpCopyText()`
so a future fix to one path cannot be missed by the other.

```js
// project_detail.php — replace the inline copyShareUrl body
function copyShareUrl(btn) {
    var code = document.getElementById('shareCode');
    if (!code) { return; }
    var url = code.getAttribute('data-share-url') || code.textContent.trim();
    if (!url) { return; }
    var label = btn.innerHTML;

    pmpCopyText(url).then(function () {
        btn.innerHTML = '<i class="far fa-check"></i> Copied';
        btn.disabled = true;
    }).catch(function () {
        btn.innerHTML = '<i class="fas fa-circle-exclamation"></i> Copy failed';
    }).then(function () {
        setTimeout(function () {
            btn.innerHTML = label;
            btn.disabled = false;
        }, 1500);
    });
}
```

> The current `copyShareUrl()` shows "Copied" **unconditionally**, even if
> both copy mechanisms fail. That is the same "lying button" defect as path A,
> just less visible. Routing it through `pmpCopyText()` fixes that too.
>
> `pmpCopyText()` prefers the Clipboard API and falls back to
> `execCommand`. The current `copyShareUrl()` does the **opposite**
> (`execCommand` first, Clipboard API second). Either order works; the shared
> helper's order is preferable because the Clipboard API is the supported
> path and `execCommand` is the deprecated fallback.

**1c. Decide what is copied.** Path A copies the **share code**
(`AX498-99ZB2-92C3J`); path B copies the **full share URL**. Both are
reasonable, but the button labels differ:

- `project_edit.php:439` — `aria-label="Copy share code"`, copies the code.
- `project_detail.php:153` — copies the full URL.

Keep both behaviours as-is (they are different screens with different needs),
but make sure the visible label and the `aria-label` match what is actually
copied. Do not silently change what a button copies — that is a product
decision, not a bug fix.

### Files touched

| file | change |
|---|---|
| `assets/js/script.js` | add `pmpCopyText()`; rewrite `initCopy()` to use it with `.then()/.catch()` |
| `project_detail.php` | refactor inline `copyShareUrl()` to call `pmpCopyText()` |
| `assets/css/style.css` | optional `.is-copied` / `.is-copy-failed` button states |

---

## Bug 2 — Task form: "Expected completion" and "Completion date" do not work

### Symptom

On the task form (`/tasks/{id}/edit` and `/tasks/new`):

- **Expected completion** — the date picker works, but the value does not
  behave as expected; there is no time component and the field appears
  "not working" relative to the sibling field.
- **Completion date** — a time is entered, but it is **not saved**. After a
  reload the time is gone and the field shows `00:00`.

### Root cause: three layers disagree on the date format

The same field is described differently at every layer.

| layer | `expected_completion_date` | `completion_date` |
|---|---|---|
| HTML input | `type="date"` → posts `Y-m-d` | `type="datetime-local"` → posts `Y-m-d\TH:i` |
| PHP validation | `createFromFormat('Y-m-d', ...)` | `createFromFormat('Y-m-d\TH:i', ...)` |
| value re-render | `date('Y-m-d', strtotime(...))` | `date('Y-m-d\TH:i', strtotime(...))` |
| **DB column** | **`DATE`** | **`DATE`** |

Verified against the live database:

```
tasks.expected_completion_date            = date
tasks.completion_date                     = date
projects.expected_completion_date         = date
projects.completion_date                  = date
task_hierarchy.expected_completion_date   = date
task_hierarchy.completion_date            = date
```

And MariaDB's `DATE` cast discards the time:

```
DATE cast:     2026-10-22
DATETIME cast: 2026-10-22 14:30:00
```

So for `completion_date`:

1. The user submits `2026-10-22T14:30`.
2. MariaDB stores it in a `DATE` column as **`2026-10-22`** — the time is
   **silently truncated**, with no error and no warning.
3. On re-render, `date('Y-m-d\TH:i', strtotime('2026-10-22'))` produces
   `2026-10-22T00:00`.

**The entered time is lost on every save.** This is the defect the user is
reporting.

Two further problems compound it:

**The validated date objects are never used.** `task_edit.php:318` and
`task_edit.php:325` build `$expected_date` and `$comp_date` via
`DateTime::createFromFormat()`, check them for validity, and then **throw them
away**. The raw, unnormalised POST string is what reaches the database:

```php
if (!empty($expected_completion_date)) {
    $expected_date = DateTime::createFromFormat('Y-m-d', $expected_completion_date);
    if (!$expected_date) {
        $errors[] = "Invalid expected completion date format.";
    }
}
if (!empty($completion_date)) {
    $comp_date = DateTime::createFromFormat('Y-m-d\TH:i', $completion_date);
    if (!$comp_date) {
        $errors[] = "Invalid completion date format.";
    }
}
// ... $expected_date and $comp_date are never used again.
$expected_completion_date = $expected_completion_date ?: null;
$completion_date = $completion_date ?: null;
```

Validation and normalisation must be the same step. A field that parses
successfully but is stored in a different format than it was parsed from is
exactly how the truncation goes unnoticed.

**`createFromFormat()` does not validate the calendar.** `DateTime::createFromFormat`
returns a `DateTime` object with **warnings** for values like `2026-02-31`,
not `false`. The current `if (!$expected_date)` check therefore **never fires**
for an impossible date — the object exists, just carrying an overflow warning.
The project form already solves this correctly with `checkdate()`
(`project_edit.php:94-116`).

### The decision the implementer must make first

The codebase contains **two conflicting conventions**, and the fix depends on
which one wins.

| evidence | precision | points to |
|---|---|---|
| `database/schema.sql:91,93,145,146` — all four columns are `DATE` | day | `DATE` is the intended storage |
| `project_edit.php:384,391,397` — **all three** project date inputs are `type="date"` | day | `DATE` is the intended storage |
| `task_edit.php:740` — completion input is `type="datetime-local"` | minute | `DATETIME` was intended for tasks |
| `ajax/complete_task.php:71`, `ajax/update_task_status.php:91`, `ajax/parent_hold.php:147` — all write `date('Y-m-d H:i:s')` | second | the code already thinks in timestamps |

**Recommendation: align the task form to `DATE`, matching the schema and the
project form.**

Rationale:

- The database schema is the contract. Changing it to `DATETIME` means a
  migration plus touching every reader, every writer, the delay calculations
  in `ajax/update_status_reason.php:119` and `task_edit.php:228-232`, the
  schema, the `project_stats` view, and the mockups.
- **The project form already works correctly** with `type="date"` on all three
  fields and a strict `checkdate()` validator. It is the reference
  implementation in this codebase.
- `expected_completion_date` is compared against `date('Y-m-d')` to decide
  whether a task is delayed. Day precision is what that calculation needs.
- The `DATE` columns are also used by the `project_stats` view, which aggregates
  by day.

> **If the product owner decides tasks genuinely need a completion *time*,**
> the decision must be explicit and must cover the whole chain:
> `ALTER TABLE tasks MODIFY completion_date DATETIME NULL` (and the same for
> `task_hierarchy`), update `database/schema.sql`, add a migration under
> `database/migrations/`, keep the input as `datetime-local`, normalise with
> `->format('Y-m-d H:i:00')`, and re-render with `date('Y-m-d\TH:i', ...)`.
> Do **not** mix the two: storing `Y-m-d H:i:s` into a `DATE` column is the
> bug being fixed here, and it currently happens in three AJAX endpoints.

### Fix (recommended path — day precision)

**2a. Change the task form's completion input to `type="date"`.**

```php
<!-- task_edit.php:740 — before -->
<input class="input" type="datetime-local" id="completion_date" name="completion_date"
       value="<?= e($_POST['completion_date'] ?? (isset($task['completion_date']) && $task['completion_date'] ? date('Y-m-d\TH:i', strtotime($task['completion_date'])) : '')) ?>">

<!-- task_edit.php:740 — after -->
<input class="input" type="date" id="completion_date" name="completion_date"
       value="<?= e($_POST['completion_date'] ?? (isset($task['completion_date']) && $task['completion_date'] ? date('Y-m-d', strtotime($task['completion_date'])) : '')) ?>">
```

The re-render format must match the input type exactly, or the browser refuses
the value and silently shows an empty field. `type="date"` + `Y-m-d\TH:i` is
the same class of bug as the one being fixed.

**2b. Replace the ad-hoc validation with a strict, normalising parser.**

Mirror the `project_edit.php:94-116` approach, but share it so the two forms
cannot drift again. Put it in `includes/functions.php`:

```php
// includes/functions.php
/**
 * Parse a form date field into a canonical Y-m-d string.
 *
 * Strict: rejects anything that is not exactly Y-m-d, and rejects
 * impossible calendar dates (2026-02-31). Returns null for an empty
 * value; pushes a validation error and returns null for an invalid one.
 *
 * @param mixed  $value  Raw $_POST value.
 * @param string $label  Human-readable field label for the error message.
 * @param array  $errors Error bag, by reference.
 */
function parseFormDate($value, string $label, array &$errors): ?string
{
    if (!is_string($value)) {
        $errors[] = "$label must be a valid date.";
        return null;
    }

    $value = trim($value);
    if ($value === '') {
        return null;
    }

    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/D', $value)) {
        $errors[] = "$label must be a valid date.";
        return null;
    }

    [$year, $month, $day] = array_map('intval', explode('-', $value));
    if (!checkdate($month, $day, $year)) {
        $errors[] = "$label must be a valid date.";
        return null;
    }

    return $value;
}
```

**2c. Use the parser in `task_edit.php` and store its return value.**

```php
// task_edit.php — replace the two createFromFormat blocks
$expected_completion_date = parseFormDate(
    $_POST['expected_completion_date'] ?? '',
    'Expected completion date',
    $errors
);
$completion_date = parseFormDate(
    $_POST['completion_date'] ?? '',
    'Completion date',
    $errors
);
```

The parser's **return value is now the value that is stored**, so validation
and normalisation are the same step and cannot disagree.

> `task_edit.php:129-130` currently initialises
> `$expected_completion_date` / `$completion_date` from `$_POST` at the top of
> the script. Keep those lines (other code below reads the raw POST values
> for the delay calculation) but make sure the **values passed to the
> `UPDATE`/`INSERT`** are the parsed ones. Check the `$is_deleting` /
> `$is_subtask` branches as well — a subtask save has its own parameter list.

**2d. Fix the auto-set completion date to match the column precision.**

```php
// task_edit.php:335 — before
if (empty($completion_date)) {
    $completion_date = date('Y-m-d H:i:s');
}

// task_edit.php — after
if (empty($completion_date)) {
    $completion_date = date('Y-m-d');
}
```

**2e. Align the three AJAX endpoints** that write a timestamp into a `DATE`
column. These are not user-visible today, but they are the same latent bug and
they will keep the data inconsistent.

```php
// ajax/complete_task.php:71
// ajax/update_task_status.php:91
// ajax/parent_hold.php:147  (NOW() -> CURDATE())
$completion_date = date('Y-m-d');
```

`ajax/parent_hold.php:147` uses `NOW()` in SQL — change it to `CURDATE()`.

**2f. Apply the same correction to the mockup.**

`docs/design/2.0/task-form.html:217` currently has
`<input type="datetime-local" ... name="completion_date">`. It reproduces the
production defect. Change it to `type="date"` so the mockup and the fix agree.

> The mockup is generated. Edit
> `docs/design/2.0/_build/screens_part4.py` (or whichever `_build` module
> renders the task form), then regenerate with
> `python3 docs/design/2.0/_build/main.py`. **Never hand-edit the generated
> `.html` files.**

**2g. `expected_completion_date` is already `type="date"`** — the input type is
correct, but it suffered from the same dead validation (`createFromFormat`
without `checkdate`) and the same unnormalised store. Steps 2b–2c fix both
fields at once.

### Files touched

| file | change |
|---|---|
| `task_edit.php` | `type="date"` on completion input; re-render with `date('Y-m-d')`; use `parseFormDate()`; store the parsed value; `date('Y-m-d')` for auto-set |
| `includes/functions.php` | add `parseFormDate()` helper |
| `ajax/complete_task.php` | `date('Y-m-d')` instead of `date('Y-m-d H:i:s')` |
| `ajax/update_task_status.php` | `date('Y-m-d')` instead of `date('Y-m-d H:i:s')` |
| `ajax/parent_hold.php` | `NOW()` → `CURDATE()` |
| `docs/design/2.0/_build/screens_part4.py` | mockup completion input → `type="date"`; regenerate |
| `database/schema.sql` | **unchanged** under the recommended path |

---

## Bug 3 — Client dashboard language switcher does not match the mockup

### Symptom

The language switcher on the client dashboard (`/client/{share_code}/`) looks
different from the v2 mockup: the active language is not highlighted with the
brand colour, and the control does not read as the segmented button group
shown in the mockup.

### Root cause: the active-state rule is scoped to the wrong container

The switcher markup is at `client/dashboard.php:430-436`:

```php
<div class="lang-switch inline" aria-label="<?= e(t('landing.lang_label')) ?>">
    <?php foreach (client_all_langs() as $code2 => $label): ?>
        <a href="<?= e(client_lang_url($code2)) ?>"
           class="lang-switch-link<?= ($code2 === client_current_lang()) ? ' is-active' : '' ?>"
           <?= ($code2 === client_current_lang()) ? ' aria-current="true"' : '' ?>><?= e($label) ?></a>
    <?php endforeach; ?>
</div>
```

The mockup (`docs/design/2.0/client-dashboard.html:22-25`) uses **buttons**:

```html
<div class="lang-switch" data-lang-switch role="group" aria-label="Language">
  <button type="button" data-lang-set="en" class="is-active">ENG</button>
  <button type="button" data-lang-set="zh">中文</button>
</div>
```

The mockup's active state comes from the shared token stylesheet
(`docs/design/2.0/v2-tokens.css:1145`):

```css
.lang-switch button.is-active { background: var(--brand-600); color: #fff; }
```

That selector matches **`button.is-active`**. Production renders
**`<a class="lang-switch-link is-active">`**, so the rule never applies.

Production already matches the mockup's **container and link** styling almost
exactly. `client/assets/client.css:499-535` reproduces the mockup's segmented
control token for token:

```css
/* client/assets/client.css:499 */
.client-body .lang-switch {
    display: inline-flex;
    border: 1px solid var(--gray-200);
    border-radius: var(--r-md);
    overflow: hidden;
    background: var(--gray-0);
    ...
}
.client-body .lang-switch-link {
    display: inline-block;
    padding: var(--sp-1) var(--sp-3);
    ...
    color: var(--gray-500);
    background: var(--gray-0);
    ...
}
```

which matches the mockup's `v2-tokens.css:1140-1144` (`.lang-switch` /
`.lang-switch button`).

**The only missing piece is the active state.** Production *does* have
client-scoped active rules, but they target different containers:

| selector | line | matches the dashboard switcher? |
|---|---|---|
| `.landing .lang-switch-link.is-active` | 844 | no — the dashboard is not `.landing` |
| `.client-header__tools .lang-switch-link.is-active` | 853 | **yes, but the wrong colours** |

The second rule *does* match, because the dashboard switcher sits inside
`.client-header__tools` (`client/dashboard.php:429`). But it applies
**header-appropriate colours** — white background, dark text:

```css
/* Issue-051: the client dashboard's language switcher sits inside the dark
   .client-header__tools bar, so its active chip needs a header-appropriate
   treatment rather than the default brand-600 fill. */
.client-header__tools .lang-switch-link.is-active {
  background: #fff;
  color: var(--gray-900);
}
```

That is **not** what the mockup shows. The mockup's switcher sits on a **light**
background and uses the brand fill:

```css
.lang-switch button.is-active { background: var(--brand-600); color: #fff; }
```

So the defect is a **specificity/scoping conflict**: an earlier issue-051 fix
made the active chip white-on-dark for the dark header bar, but the v2 mockup
now shows a light-surface switcher with a brand-600 active chip. The
`.client-header__tools` rule wins over any generic rule because it is more
specific, so the switcher cannot pick up the mockup styling.

A secondary difference: production uses `<a>` links, the mockup uses
`<button>`. This is **not** itself a defect — links are the correct choice for
language switching because they are server-rendered, work without JavaScript,
and can be opened in a new tab. The mockup's buttons are a client-side
enhancement. **Keep the links** and style them to match; do not replace them
with buttons.

> The mockup is the visual source of truth, and its switcher is on a light
> surface. Before applying the fix, confirm which surface the production
> dashboard actually renders the switcher on (`client/dashboard.php:409-436`
> places it inside the dark `.client-header`). If the production header stays
> dark, the switcher must keep a **header-appropriate** active state, and the
> **mockup** is what needs correcting — not the code. The requirement from the
> user is "same style and colour as the mockup", so this decision must be
> settled explicitly during implementation, and the mockup regenerated if it
> changes.

### Fix

**3a. Add the missing active-state rule.** The existing container and link
rules are already correct — **do not rewrite them**. Only the active state
needs to be added:

```css
/* client/assets/client.css */

/* Issue-055: the v2 mockup (docs/design/2.0/client-dashboard.html) marks the
   active locale with the brand fill. The shared token rule targets
   `button.is-active`, but the dashboard renders <a class="lang-switch-link">,
   so the state never applied. Scoped with three classes so it outranks the
   two-class .client-header__tools rule from issue-051. */
.client-body .lang-switch.inline .lang-switch-link.is-active {
    background: var(--brand-600);
    color: #fff;
}
```

If the production switcher must stay legible on the **dark** header, use the
header-appropriate treatment instead — but then regenerate the mockup so the
two agree:

```css
.client-body .lang-switch.inline .lang-switch-link.is-active {
    background: #fff;
    color: var(--gray-900);
}
```

**3b. Add a visible focus state.** The current link rules define `:hover` but
no `:focus-visible`, so keyboard users get only the browser default outline on
a low-contrast segmented control.

```css
.client-body .lang-switch.inline .lang-switch-link:focus-visible {
    outline: 2px solid var(--brand-600);
    outline-offset: -2px;
}
```

**3c. Keep the `<a>` links, but align the semantics with the mockup.**

Add `role="group"` to the container so assistive technology announces it as a
group, exactly as the mockup does:

```php
<div class="lang-switch inline" role="group" aria-label="<?= e(t('landing.lang_label')) ?>">
```

`aria-current="true"` is already correct on the active link and should be kept.

**3d. Verify the labels.** Production uses
`client_lang_labels() → ['en' => 'ENG', 'zh' => '中文']`
(`client/lang.php:274-276`), which **already matches** the mockup's `ENG` /
`中文`. No change needed.

### Files touched

| file | change |
|---|---|
| `client/assets/client.css` | add `.client-body .lang-switch.inline .lang-switch-link.is-active` and `:focus-visible` rules |
| `client/dashboard.php` | add `role="group"` to the switcher container |
| `docs/design/2.0/_build/screens_part*.py` | regenerate **only if** the mockup surface changes (see caveat above) |

---

## Bug 4 — Forgot password page: textbox styling and error placement

### Symptom

On `/forgot_password`:

- The email textbox does not match the mockup's styling.
- The validation error is not shown "on top" where expected.

### Root cause: markup drift from the mockup

The production markup (`forgot_password.php:135-166`):

```php
<h1>Forgot password</h1>
<p class="auth-card__sub">We will email you a password reset link.</p>

<?php if (!empty($errors)): ?>
    <div class="alert alert--danger" role="alert">
        <i class="fas fa-circle-exclamation"></i>
        <div class="alert__body">
            <?php foreach ($errors as $error): ?>
                <div><?= e($error) ?></div>
            <?php endforeach; ?>
        </div>
    </div>
<?php endif; ?>

<form method="POST" action="">
    <div class="field">
        <label for="email">Email Address</label>
        <input class="input" type="email" id="email" name="email"
               value="<?= e($_POST['email'] ?? '') ?>"
               placeholder="you@example.com" required>
    </div>

    <button type="submit" class="btn btn--primary btn--lg btn--block">
        <i class="fas fa-paper-plane"></i> Send Reset Link
    </button>
</form>
```

The mockup (`docs/design/2.0/forgot-password.html`):

```html
<label for="email">Email Address <span class="req" aria-hidden="true">*</span></label>
<input class="input" type="email" id="email" name="email"
       placeholder="you@example.com" autocomplete="email" required autofocus>
...
<button class="btn btn--primary btn--lg btn--block" type="submit" style="margin-top:.5rem;">
```

Differences:

| # | production | mockup | impact |
|---|---|---|---|
| a | `<label>Email Address</label>` | `Email Address <span class="req">*</span>` | no required marker — the field *looks* optional |
| b | no `autocomplete="email"` | `autocomplete="email"` | no browser autofill / email keyboard |
| c | no `autofocus` | `autofocus` | keyboard focus starts nowhere |
| d | button has no top margin | `style="margin-top:.5rem;"` | button is flush against the field |
| e | input has no `value` re-render issue — **it does** keep `$_POST['email']` | mockup has no `value` | production behaviour is **better**; keep it |
| f | errors render **after** `<h1>`/`<p>`, before the form | mockup alerts also render before the form | **already matches** |

> **On "error code on top":** the error block is already rendered above the
> form, which is the same position as the mockup and the same position as
> `register.php:138-147`. What the user is most likely reporting is that the
> error appears **below the heading** rather than at the **top of the card**.
> Move the alert block **above** the `<h1>` so it is the first thing in the
> card. This must be applied consistently to `forgot_password.php` **and**
> `register.php`, otherwise the two auth pages will disagree.

> **The `.req` CSS class already exists** in production
> (`assets/css/v2-components.css:594`: `.field label .req { color: var(--danger-500); }`)
> and in the mockup (`docs/design/2.0/v2-tokens.css:605`). It is simply never
> used on this page. No new CSS is required for the marker.

> **The user's snippet `<?php $use_v2_shell = true; $content_bleed = true; ?>`**
> is the v2 shell toggle that is already present in `register.php` and
> `forgot_password.php`. It is **not** a bug — it is how the pages opt into the
> v2 layout. It should remain. No change is required for it.

### Fix

**4a. Bring the form markup in line with the mockup.**

```php
<!-- forgot_password.php -->
<form method="POST" action="">
    <div class="field">
        <label for="email">Email Address <span class="req" aria-hidden="true">*</span></label>
        <input class="input" type="email" id="email" name="email"
               value="<?= e($_POST['email'] ?? '') ?>"
               placeholder="you@example.com" autocomplete="email" required autofocus>
    </div>

    <button type="submit" class="btn btn--primary btn--lg btn--block" style="margin-top:.5rem;">
        <i class="fas fa-paper-plane"></i> Send Reset Link
    </button>
</form>
```

**4b. Move the error/success alert to the top of the card**, above the
heading.

```php
<!-- forgot_password.php — new order -->
<div class="auth-card">
    <?php if (!empty($errors)): ?>
        <div class="alert alert--danger" role="alert">
            <i class="fas fa-circle-exclamation"></i>
            <div class="alert__body">
                <?php foreach ($errors as $error): ?>
                    <div><?= e($error) ?></div>
                <?php endforeach; ?>
            </div>
        </div>
    <?php endif; ?>

    <h1>Forgot password</h1>
    <p class="auth-card__sub">We will email you a password reset link.</p>
    ...
```

Apply the same reordering to `register.php:135-147` so both auth pages are
consistent.

> **The success alert is a different block.** `forgot_password.php:120-133`
> renders `alert--success` ("Check your email") in the `$success` branch, which
> replaces the form entirely. It should be moved to the top of the card in the
> same way, for consistency with the error block.

### Files touched

| file | change |
|---|---|
| `forgot_password.php` | add `.req` marker; add `autocomplete="email"` and `autofocus`; add button top margin; move error + success alerts above the heading |
| `register.php` | move error alert above the heading (consistency) |

---

## Acceptance criteria

### Bug 1 — copy button

1. Clicking the copy button on `/projects/{id}/edit` copies the share code to
   the clipboard and shows the "Copied" confirmation.
2. Clicking the copy button on `/projects/{id}` copies the full share URL and
   shows the "Copied" confirmation.
3. On a **plain-HTTP** origin (no secure context), the copy button still copies
   via the `document.execCommand('copy')` fallback.
4. When the clipboard write genuinely fails (permission denied), the button
   shows a **"Copy failed"** state and **does not** claim "Copied".
5. The button's original label and icon are restored after the confirmation
   timeout.
6. The same button is not left permanently disabled after a successful copy.
7. Only one copy implementation remains in the production JavaScript;
   `project_detail.php` delegates to the shared helper.
8. No unhandled promise rejection appears in the browser console when the copy
   button is clicked.

### Bug 2 — task form dates

9. Entering an expected completion date on the task form saves and re-renders
   the **same** date.
10. Entering a completion date on the task form saves and re-renders the
    **same** value, with no time component silently dropped.
11. The completion input and the expected-completion input use the same
    precision and the same re-render format, so neither shows an empty field
    after a reload.
12. A value that is not a valid date (`2026-13-01`) is rejected with a clear
    validation message and is **not** written to the database.
13. An impossible calendar date (`2026-02-31`) is rejected with a clear
    validation message and is **not** written to the database.
14. The value written to the database is the **parsed** value, not the raw POST
    string.
15. Auto-setting the completion date when the status becomes `completed` writes
    a value that matches the column precision and round-trips through the form.
16. `ajax/complete_task.php`, `ajax/update_task_status.php` and
    `ajax/parent_hold.php` write the same precision as `task_edit.php`.
17. The delay calculation (task is delayed when
    `expected_completion_date < today`) still behaves as before.
18. The mockup task form uses the same input type as the fixed production form,
    and the mockup is regenerated from `_build` (no hand-edited `.html`).
19. `database/schema.sql` is unchanged under the recommended day-precision path.

### Bug 3 — client language switcher

20. The client dashboard language switcher renders the active locale with the
    same colour treatment as the v2 mockup.
21. The active chip is visually distinct from the inactive chips.
22. Hover and keyboard-focus states are present and visible.
23. The switcher container exposes `role="group"` and an accessible label.
24. The active link carries `aria-current="true"`.
25. The labels read `ENG` and `中文`, matching the mockup.
26. Switching language still performs a full navigation and the selected
    locale is persisted.

### Bug 4 — forgot password

27. The email field label shows the red required marker.
28. The email input has `autocomplete="email"` and receives focus on load.
29. The submit button has a visible gap from the field above it, matching the
    mockup.
30. A validation error appears at the **top of the card**, above the
    "Forgot password" heading.
31. The success alert ("Check your email") also appears at the top of the card.
32. The error block renders **before** the form and is announced via
    `role="alert"`.
33. The submitted email value is preserved in the input after a validation
    failure.
34. `register.php` renders its error alert in the same position as
    `forgot_password.php`.
35. The `$use_v2_shell` / `$content_bleed` toggle is still present and the page
    still renders in the v2 shell.

---

## Notes and risks

- **Bug 2 requires a product decision** before implementation: day precision
  (align to the schema, recommended) or minute precision (widen the columns
  to `DATETIME` and migrate). The recommended path needs **no schema
  migration**. The alternative path must also update `database/schema.sql`,
  add a `database/migrations/` entry, the `project_stats` view, every reader
  and writer, and the mockups.
- **Do not mix precisions.** Writing `Y-m-d H:i:s` into a `DATE` column is the
  defect; three AJAX endpoints currently do exactly that. Fixing only the
  form would leave the data inconsistent.
- **`DateTime::createFromFormat()` returns an object, not `false`, for
  impossible dates** — it emits warnings instead. The current
  `if (!$expected_date)` guard therefore does not catch `2026-02-31`. The
  `checkdate()`-based `parseFormDate()` does.
- **The mockup for bug 2 carries the same defect as production**
  (`type="datetime-local"` into a `DATE` column). Correcting the mockup
  requires editing the `_build` source and regenerating — never the generated
  `.html`.
- **For bug 3, confirm the surface before choosing colours.** The production
  switcher sits in the dark `.client-header`; the mockup shows it on a light
  surface with a brand-600 active chip. If the mockup is authoritative, either
  the mockup must be regenerated to show the switcher on the dark header, or
  the active chip needs a header-appropriate treatment. Do not leave the two
  disagreeing.
- **`navigator.clipboard` is unavailable outside a secure context.** The
  `execCommand` fallback is deprecated but is still the only option on plain
  HTTP and in some embedded WebViews. Keep it.
- **Keep the `<a>` elements for the language switcher.** They work without
  JavaScript and can be bookmarked; the mockup's `<button>` is a
  client-side enhancement, not a requirement.
- **Neither issue file requires a production code change to be merged** — both
  are specifications for the developer.

---

## Related issues

| issue | relationship |
|---|---|
| `issue-039` | Registration feature flag — also in the v2.0 release |
| `issue-043` | House-style exemplar for this document |
| `issue-051` | Introduced the `.client-header__tools .lang-switch-link.is-active` rule that conflicts with bug 3 |

Both `issue-039` and `issue-055` are scheduled for the **v2.0 release**.
Neither requires a production code change to be merged — both are
specifications for the developer.

> **Note — `forgot_password.php` is touched by both issues.** The edits are
> in different regions and do not conflict:
>
> | issue | region of `forgot_password.php` |
> |---|---|
> | `issue-039` step 4 | the `.auth-links` row, `:162-166` — hides the "Register" link |
> | `issue-055` bug 4 | the form and alerts, `:135-161` — required marker, `autocomplete`, `autofocus`, button margin, error placement |
>
> Apply both in the same pass. The one thing to watch: bug 4 moves the
> error/success alert block **above** the `<h1>`. That reordering must not
> accidentally move the `.auth-links` row, which stays at the bottom of the
> card.

