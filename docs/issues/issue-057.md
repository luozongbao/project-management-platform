# issue-057 — The Status Reason inline editor on the task detail page renders unstyled

## Summary

On the **Task → Detail** page (`/tasks/<id>`), the **Status Reason** card
contains an inline editor behind the **Edit** / **Add reason** disclosure.
When the user opens it, the textarea and the **Save** button render as
**raw, unstyled browser defaults** — a small white box with a tiny grey
button, visually detached from the rest of the dark-themed card.

The markup is correct and the CSS rules exist. The elements are simply
**missing the class attributes** that the stylesheet selects on.

| | |
|---|---|
| **Severity** | Medium — cosmetic, but the editor is effectively unusable and unreadable |
| **Affected page** | `task_detail.php` (Status Reason card only) |
| **Root cause** | Missing `class` attributes in the rendered markup |
| **CSS** | **Correct** — no stylesheet change required |
| **Fix size** | Two attributes per editor, in two files |

---

## The state that triggers it

Any task that is **On Hold** or **Delayed** renders a Status Reason card.
Inside it, each reason field has a `<details class="reason-inline-edit">`
disclosure. Clicking **Edit** (or **Add reason** when the reason is missing)
reveals the textarea + Save button.

The defect appears the moment the disclosure is opened — it is visible on
**every** task in either state, with no special data required.

**Reproduce:**

1. Log in as the owner of a task with `status = 'on_hold'` or a
   past-due `expected_completion_date`.
2. Open `/tasks/<id>`.
3. Scroll to the **Status Reason** card.
4. Click **Edit** under the reason text.

**Observed** (matches the bug report screenshot):

- The textarea renders as a **small white rectangle** with a default
  monospace font and a thin black border — roughly 90×20 px.
- The **Save** button next to it renders as a **tiny native button**.
- Neither inherits the card's dark surface, border, radius, or spacing.
- The whole editor reads as an artefact of a different website.

**Expected** (per the v2 design reference): a full-width textarea using the
app's `.textarea` component — themed background, `--gray-300` border,
`--r-md` radius, `--fs-sm` font, 88 px minimum height, vertically
resizable — followed by a `.btn btn--sm btn--primary` Save button.

---

## Root cause — the class attributes were never applied

### The CSS is fine

All the rules that should style these elements already exist in
[`assets/css/v2-components.css`](../../assets/css/v2-components.css):

```css
/* line 598 */
.input, .select, .textarea {
    width: 100%;
    padding: var(--sp-2) var(--sp-3);
    border: 1px solid var(--gray-300);
    border-radius: var(--r-md);
    background: var(--gray-0);
    font-size: var(--fs-sm);
    line-height: 1.5;
    ...
}
/* line 617 */
.textarea { resize: vertical; min-height: 88px; }

/* line 939 */
.reason-inline-edit .textarea { margin-bottom: var(--sp-2); }
```

And the Save button's component class:

```css
/* line 376 */
.btn {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: var(--sp-2);
    padding: var(--sp-2) var(--sp-4);
    border: 1px solid transparent;
    border-radius: var(--r-md);
    ...
}
```

Every one of these selectors is **class-based**. They only match if the
element carries the class.

### The markup does not carry the class

In [`task_detail.php`](../../task_detail.php), the inline editor is rendered
four times — two for the on-hold reason, two for the delay reason. The
existing-reason branch at
[`task_detail.php:499-502`](../../task_detail.php#L499-L502):

```html
<details class="reason-inline-edit">
    <summary>Edit</summary>
    <textarea data-update-reason
              data-task-id="<?= (int)$task_id ?>"
              data-field="on_hold_reason"
              rows="2" maxlength="2000"><?= e($detail_on_hold) ?></textarea>
    <button type="button"
            data-save-reason
            data-task-id="<?= (int)$task_id ?>"
            data-field="on_hold_reason">Save</button>
```

**There is no `class` attribute on the `<textarea>`, and none on the
`<button>`.**

A repo-wide audit confirms these are the **only** textareas in the entire
application without `class="textarea"`:

| File | Line | Element | Class |
|---|---|---|---|
| `task_detail.php` | 499 | `<textarea data-update-reason>` | **missing** |
| `task_detail.php` | 516 | `<textarea data-update-reason>` | **missing** |
| `task_detail.php` | 537 | `<textarea data-update-reason>` | **missing** |
| `task_detail.php` | 554 | `<textarea data-update-reason>` | **missing** |

Every other textarea in the app is correct:

```
project_edit.php:350   <textarea class="textarea" id="description" ...>
project_edit.php:356   <textarea class="textarea" id="scope" ...>
task_edit.php:550      <textarea class="textarea" id="description" ...>
task_edit.php:641      <textarea class="textarea" id="on_hold_reason" ...>
task_edit.php:651      <textarea class="textarea" id="delay_reason" ...>
contact_edit.php:266   <textarea class="textarea" id="description" ...>
contact_edit.php:293   <textarea class="textarea" id="address" ...>
```

The four Save buttons are equally unstyled — no `class` at all.

### Why it renders as *white* rather than dark

The v2 component layer contains a single global reset:

```css
/* assets/css/v2-components.css:94 */
button, input, select, textarea { font: inherit; color: inherit; }
```

This normalises the **font** and the **text colour** but leaves the
**background**, **border**, **width**, and **padding** at their browser
defaults. So the textarea inherits the app's font and text colour (which is
why it reads as dark text) but keeps the UA's white canvas and default
`rows="2"` height — producing the exact white-box artefact in the
screenshot.

### Origin

`git blame` attributes the markup to commit `a75dba5` — *"Align task and
contact detail pages with v2 mockups"* (2026-10-24). The v2 conversion
rewrote this block and dropped the class attributes while copying the
surrounding structure.

### The design reference has the classes — production drifted from it

The v2 mockup is the authoritative design reference for this component, and
it carries both classes correctly.

[`docs/design/2.0/task-detail.html:208-216`](../../docs/design/2.0/task-detail.html#L208-L216):

```html
<details class="reason-inline-edit" open>
    <summary>Add reason</summary>
    <textarea class="textarea" id="detail_on_hold_reason"
              data-update-reason data-field="on_hold_reason"
              rows="3" maxlength="2000"
              placeholder="Why is this task on hold?"></textarea>
    <button class="btn btn--sm btn--primary" type="button"
            data-save-reason data-field="on_hold_reason">
      <i class="fas fa-floppy-disk"></i> Save
    </button>
```

So the mockup and production have **diverged**: the mockup says
`class="textarea"` and `class="btn btn--sm btn--primary"`; production says
nothing. The fix is to restore parity with the mockup, not to invent new
styling.

---

## The same defect exists in the JavaScript renderer

[`assets/js/subtask-manager.js`](../../assets/js/subtask-manager.js) rebuilds
the reason block at runtime via `renderReasonBlock()`. It injects the **same
class-less** markup — so the defect reappears after any client-side
re-render, even if only the PHP is fixed.

[`assets/js/subtask-manager.js:235-236`](../../assets/js/subtask-manager.js#L235-L236):

```js
<textarea data-update-reason data-task-id="${taskId}" data-field="${field}" rows="2" maxlength="2000" placeholder="Add reason..."></textarea>
<button type="button" data-save-reason data-task-id="${taskId}" data-field="${field}">Save</button>
```

[`assets/js/subtask-manager.js:245-246`](../../assets/js/subtask-manager.js#L245-L246):

```js
<textarea data-update-reason data-task-id="${taskId}" data-field="${field}" rows="2" maxlength="2000">${escapedValue}</textarea>
<button type="button" data-save-reason data-task-id="${taskId}" data-field="${field}">Save</button>
```

**Fixing only `task_detail.php` is not enough** — the page would look right
on first load and revert to the broken appearance after a status change
triggers a re-render.

---

## The fix

### Step A — `task_detail.php`: add the class attributes

For **all four** textareas (lines 499, 516, 537, 554), add
`class="textarea"`:

```html
<textarea class="textarea" data-update-reason
          data-task-id="<?= (int)$task_id ?>"
          data-field="on_hold_reason"
          rows="2" maxlength="2000"><?= e($detail_on_hold) ?></textarea>
```

For **all four** Save buttons (lines 503, 521, 541, 559), add
`class="btn btn--sm btn--primary"`:

```html
<button class="btn btn--sm btn--primary" type="button"
        data-save-reason
        data-task-id="<?= (int)$task_id ?>"
        data-field="on_hold_reason">Save</button>
```

The `btn btn--sm btn--primary` combination matches the app's own
convention for compact primary actions — e.g. the **Complete Task** button
at [`task_detail.php:392`](../../task_detail.php#L392) uses
`class="btn btn--sm btn--success btn--icon"`, and the mockup reference
uses `class="btn btn--sm btn--primary"`.

> Optional but recommended: the mockup also prefixes the Save label with a
> floppy-disk icon (`<i class="fas fa-floppy-disk"></i> Save`). Adding it is
> a cosmetic nicety, not part of the defect. If added, it must be added to
> **both** the PHP and the JS renderer to stay in parity.

### Step B — `assets/js/subtask-manager.js`: same change in `renderReasonBlock()`

Apply the identical two class attributes to the **two** textarea/button
pairs at lines 235-236 and 245-246.

### Step C — nothing else

No stylesheet change is required. The rules already exist and already match
once the classes are present.

---

## Why the existing regression test did not catch this

[`database/task_status_reasons_test.php`](../../database/task_status_reasons_test.php)
section 8 (lines 226-241) asserts the inline editor's **data attributes**:

```php
ok(strpos($tdetail, 'data-update-reason') !== false,
    'task_detail.php is missing the data-update-reason inline-edit textarea attribute');
ok(strpos($tdetail, 'data-save-reason') !== false,
    'task_detail.php is missing the data-save-reason Save button attribute');
```

These checks confirm the editor is **wired up** — they say nothing about
how it **looks**. A textarea with no class satisfies every one of them.

The gap is structural: the suite verifies that the behaviour hooks exist,
but never that the element carries the **presentation class** the
stylesheet selects on.

---

## Regression tests to add

Add a new section to
[`database/task_status_reasons_test.php`](../../database/task_status_reasons_test.php),
or a dedicated `database/issue057_reason_editor_css_test.php`. All checks
must be **source-grep** based, consistent with the file's existing style.

### 1. Every reason textarea carries `class="textarea"`

Parse `task_detail.php` and assert that **every** `<textarea>` carrying
`data-update-reason` also carries the `textarea` class:

```php
preg_match_all('/<textarea\b([^>]*)>/i', $tdetail, $m);
$reasonTextareas = 0;
foreach ($m[1] as $attrs) {
    if (strpos($attrs, 'data-update-reason') === false) { continue; }
    $reasonTextareas++;
    ok(preg_match('/class\s*=\s*["\'][^"\']*\btextarea\b[^"\']*["\']/i', $attrs) === 1,
        'a reason inline-edit textarea in task_detail.php is missing class="textarea"');
}
ok($reasonTextareas === 4,
    'expected 4 reason inline-edit textareas in task_detail.php, found ' . $reasonTextareas);
```

The count assertion matters as much as the class assertion: it prevents a
future refactor from silently dropping an editor.

### 2. Every reason Save button carries the button class

```php
preg_match_all('/<button\b([^>]*)>/i', $tdetail, $m);
$reasonButtons = 0;
foreach ($m[1] as $attrs) {
    if (strpos($attrs, 'data-save-reason') === false) { continue; }
    $reasonButtons++;
    ok(preg_match('/class\s*=\s*["\'][^"\']*\bbtn\b[^"\']*["\']/i', $attrs) === 1,
        'a reason inline-edit Save button in task_detail.php is missing the .btn class');
}
ok($reasonButtons === 4,
    'expected 4 reason inline-edit Save buttons in task_detail.php, found ' . $reasonButtons);
```

### 3. The JavaScript renderer carries the same classes

Assert that in `assets/js/subtask-manager.js`, every injected
`<textarea data-update-reason` and `<button ... data-save-reason` also
carries `class="textarea"` / `class="btn ...`:

```php
$sharedJs = read($root . '/assets/js/subtask-manager.js');
ok(preg_match('/<textarea[^>]*class="textarea"[^>]*data-update-reason/i', $sharedJs) === 1,
    'subtask-manager.js injects a reason textarea without class="textarea"');
ok(preg_match('/<button[^>]*class="btn[^"]*"[^>]*data-save-reason/i', $sharedJs) === 1,
    'subtask-manager.js injects a reason Save button without the .btn class');
```

> **Watch the attribute order.** The JS templates put `data-update-reason`
> *before* `data-task-id`, and the PHP puts `class` first. A single regex
> that assumes one order will pass on one file and fail on the other. Use
> the order-tolerant form above, or match `class` and the data attribute
> independently.

### 4. A whole-app sweep — no unstyled textarea anywhere

The same defect class could reappear in any future form. Assert that
**every** `<textarea>` in every routed PHP file carries `class="textarea"`:

```php
foreach (glob($root . '/*.php') as $phpFile) {
    $src = read($phpFile);
    if (strpos($src, '<textarea') === false) { continue; }
    preg_match_all('/<textarea\b([^>]*)>/i', $src, $m);
    foreach ($m[1] as $attrs) {
        ok(preg_match('/class\s*=\s*["\'][^"\']*\btextarea\b[^"\']*["\']/i', $attrs) === 1,
            basename($phpFile) . ' contains a <textarea> without class="textarea"');
    }
}
```

This is the check that would have caught the original defect at the moment
it was introduced.

---

## Files to change

| File | Change |
|---|---|
| `task_detail.php` | Add `class="textarea"` to 4 textareas; add `class="btn btn--sm btn--primary"` to 4 Save buttons |
| `assets/js/subtask-manager.js` | Add the same two class attributes to the 4 injected elements in `renderReasonBlock()` |
| `database/task_status_reasons_test.php` *(or a new test file)* | Add the 4 regression checks above |

---

## Not to be changed

| Do not touch | Why |
|---|---|
| `assets/css/v2-components.css` | The `.textarea` and `.btn` rules already exist and are correct. Adding a `.reason-inline-edit textarea` element-selector rule would mask the missing class and leave the markup wrong. |
| `assets/css/style.css` | Legacy layer; not involved. |
| `assets/css/pages/task_detail.css` | Page layer; the component classes are the right mechanism. |
| `docs/design/2.0/*` | The mockup is already correct — it is the reference, not the defect. |
| The `data-update-reason` / `data-save-reason` / `data-task-id` / `data-field` attributes | The JS in `assets/js/subtask-manager.js` locates the elements by these. Changing them would break the save round-trip. |
| `rows="2"` / `maxlength="2000"` | Working as intended. `min-height: 88px` comes from `.textarea`. |
| The `data-save-status` span | Correctly targeted by `.reason-inline-edit [data-save-status]` (an attribute selector, not a class) — already styled. |
| The on-hold / delay *validation* in `task_edit.php` | Unrelated to this defect. |
| `includes/functions.php` `isTaskDelayed()` | Unrelated to this defect. |
| `database/schema.sql` | No schema change involved. |

---

## Acceptance criteria

1. On `/tasks/<id>` for a task with a saved **on-hold** reason, opening
   **Edit** renders a **full-width, themed textarea** — app background
   colour, `--gray-300` border, `--r-md` radius, `--fs-sm` font, at least
   88 px tall, vertically resizable — and **not** a small white box.
2. The **Save** button renders as a themed compact primary button
   (`btn btn--sm btn--primary`), not a native browser button.
3. The same holds for the **delay** reason editor.
4. The same holds for the **Add reason** variant, where the reason is
   currently empty and the disclosure renders `open`.
5. The textarea is **full width** of the `.reason-inline-edit` container,
   with the intended `var(--sp-2)` bottom margin between it and the Save
   button.
6. In **dark mode** (`prefers-color-scheme: dark`) the textarea and button
   use the dark surface and remain legible.
7. After a status change that triggers `renderReasonBlock()` in
   `assets/js/subtask-manager.js`, the re-rendered editor is **still
   styled** — the defect does not reappear.
8. Saving a reason from the inline editor still works and the reason text
   updates in the card — the fix is presentational only.
9. **All four** textareas in `task_detail.php` carry `class="textarea"` and
   **all four** Save buttons carry a `.btn` class.
10. **Every** `<textarea>` in every routed PHP file carries
    `class="textarea"`.
11. The new regression checks pass, and the **existing** suite still
    passes — in particular `database/task_status_reasons_test.php` and
    `database/v2_0_acceptance_test.php`.
12. `php -l` is clean on `task_detail.php`. The JS file has no build step
    and no linter in this repo.

---

## Verification commands

Run inside the container (`php` is not on the host PATH):

```bash
# Lint the changed PHP file
docker compose exec -T app php -l /var/www/html/task_detail.php

# The focused regression suite
docker compose exec -T app php /var/www/html/database/task_status_reasons_test.php

# The whole suite — the exit code is the signal, not the output
pass=0; fail=0; for t in $(ls database/*_test.php client/*_test.php); do
  if docker compose exec -T app php /var/www/html/$t >/dev/null 2>&1; then pass=$((pass+1));
  else fail=$((fail+1)); echo "FAIL $t"; fi; done
echo "=== $pass PASS / $fail FAIL ==="
```

> Success is determined by **exit code 0**. Never grep the output for the
> literal `Failed` — `Failed: 0` is an informational line.

---

## Cross-references

- [`task_detail.php:480-566`](../../task_detail.php#L480-L566) — the Status
  Reason card (the affected markup)
- [`assets/css/v2-components.css:598-618`](../../assets/css/v2-components.css#L598-L618)
  — the `.textarea` component rules (already correct)
- [`assets/css/v2-components.css:376-396`](../../assets/css/v2-components.css#L376-L396)
  — the `.btn` component rules (already correct)
- [`assets/css/v2-components.css:923-942`](../../assets/css/v2-components.css#L923-L942)
  — the `.reason-inline-edit` block
- [`assets/js/subtask-manager.js:215-250`](../../assets/js/subtask-manager.js#L215-L250)
  — `renderReasonBlock()`, the JS re-renderer with the same defect
- [`database/task_status_reasons_test.php:226-242`](../../database/task_status_reasons_test.php#L226-L242)
  — the existing coverage gap
- [`docs/design/2.0/task-detail.html:208-216`](../../docs/design/2.0/task-detail.html#L208-L216)
  — the correct design reference
- [`docs/issues/issue-034.md`](issue-034.md) — the original Status Reason
  feature (on-hold / delay reason + inline edit)
- [`docs/issues/issue-056.md`](issue-056.md) — the separate Edit-form
  on-hold + delayed visibility defect
- [`AGENTS.md`](../../AGENTS.md) — the CSS load-order contract and the
  regression-test conventions this issue follows
