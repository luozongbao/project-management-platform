# issue-056 — A task that is both On Hold and delayed cannot be saved

## Summary

On the **Task → Edit** form (`/tasks/<id>/edit`), a task can be **On Hold**
*and* **past its expected completion date** at the same time. In that state the
form shows **only the On-hold reason** textarea. The **Delay reason** textarea
is hidden — and the server then rejects the save.

The result is a task that **cannot be updated at all** until its due date is
moved into the future or the status is changed away from `on_hold`.

> **The two reasons are independent in the database and in every other view of
> the app.** Only the Task → Edit form couples them.

| | |
|---|---|
| **Severity** | High — a legitimate, reachable state produces a dead-end form |
| **Affected page** | `task_edit.php` (the Edit form only) |
| **Root cause** | `assets/js/script.js` — `initStatusReason()` |
| **Server-side validation** | **Correct** — no change required |
| **Fix size** | One condition in one JS function |

---

## The state that triggers it

A task satisfies **both** conditions at once:

1. `status = 'on_hold'` — it is on hold, and
2. `expected_completion_date` is set and **strictly before today** — it is
   delayed.

This is entirely legitimate in the real world: a task is blocked on an
external dependency *and* has blown its deadline. The data model already
anticipates it — `tasks.on_hold_reason` and `tasks.delay_reason` are two
separate nullable `TEXT` columns (`database/schema.sql:151-152`), and
`task_detail.php` renders **both** reason editors side by side when both
conditions hold.

---

## Root cause — one boolean in the JS panel toggle

The Edit form hides each reason panel with the `hidden` attribute and toggles
them from `assets/js/script.js`, function `initStatusReason()`.

The visibility rule is a single `if` at
[`assets/js/script.js:620-621`](../../assets/js/script.js#L620-L621):

```js
var show = (key === 'on_hold' && v === 'on_hold') ||
           (key === 'delayed' && v !== 'completed' && v !== 'on_hold');
```

The second clause says: show the **delay** panel only when the status is
neither `completed` **nor `on_hold`**.

**That `v !== 'on_hold'` exclusion is the bug.** The panel's visibility is
being driven by the *status* alone, but the panel's real condition is
"**is this task delayed?**" — and a delayed task is delayed *regardless* of
whether it is also on hold. The status and the due date are two independent
inputs; the code treats them as one.

### Why `on_hold` was excluded in the first place

The intent was to avoid showing two "you must explain yourself" prompts at
once. But `on_hold` and `delayed` are **not** mutually exclusive states —
they are two separate questions:

| Question | Answered by | Stored in |
|---|---|---|
| Why is this task **on hold**? | `status = 'on_hold'` | `tasks.on_hold_reason` |
| Why is this task **delayed**? | due date has passed, not completed | `tasks.delay_reason` |

A task can be on hold because it is waiting for a client, *and* delayed
because it missed its date — and a manager may need to explain both to the
client.

### The current visibility matrix

Reproduced from the `sync()` logic at `assets/js/script.js:616-632`:

| `status` | On-hold panel | Delay panel | Correct? |
|---|---|---|---|
| `not_started` | hidden | **shown** | yes |
| `in_progress` | hidden | **shown** | yes |
| `on_hold` | **shown** | **hidden** | ❌ **BUG** |
| `completed` | hidden | hidden | yes |

The `on_hold` row is the bug: the delay panel is hidden even when the due
date has passed.

---

## The full failure chain

The bug is not only that the field is hidden — the hidden field is
**actively disabled**, which turns it into a save failure.

**Step 1 — the panel is hidden and the textarea is disabled.**

[`assets/js/script.js:622-629`](../../assets/js/script.js#L622-L629):

```js
panels[key].hidden = !show;
var ta = panels[key].querySelector('textarea');
if (ta) {
    ta.required = show;
    if (!show) { ta.disabled = true; } else { ta.disabled = false; }
}
```

When `show` is false the textarea gets `disabled = true`.

**Step 2 — a disabled textarea is not submitted.**

The browser omits disabled controls from the POST body entirely. So
`delay_reason` is **absent** from `$_POST`.

**Step 3 — the server treats the missing field as "no reason given".**

[`task_edit.php:175-186`](../../task_edit.php#L175-L186) reads the absent
field as an empty string:

```php
$delay_reason_input = $_POST['delay_reason'] ?? '';
if (is_string($delay_reason_input)) {
    $delay_reason_input = trim($delay_reason_input);
} else {
    $delay_reason_input = '';
}
```

**Step 4 — validation rejects the save.**

[`task_edit.php:263-271`](../../task_edit.php#L263-L271) — and note this
condition is **correct**: a delayed task must have a delay reason.

```php
$expected_for_delay_check = !empty($expected_completion_date)
    ? (string)$expected_completion_date
    : (string)($task['expected_completion_date'] ?? '');
$is_delayed_for_post = $status !== 'completed'
    && $expected_for_delay_check !== ''
    && strtotime($expected_for_delay_check) < strtotime(date('Y-m-d'));
if ($is_delayed_for_post && $delay_reason_input === '') {
    $errors[] = "A delay reason is required when the task is past its expected completion date.";
}
```

**Step 5 — the error is shown, and the user cannot satisfy it.**

The form re-renders with:

> A delay reason is required when the task is past its expected completion
> date.

…and the Delay reason textarea is **still hidden and still disabled**, so
there is no way to enter one. The form is a dead end.

> **This is the trap to avoid:** a tempting "fix" is to relax the server-side
> validation so the save goes through. **Do not do that.** The server rule is
> correct and is deliberately mirrored in `task_detail.php` and
> `ajax/update_task_status.php`. The bug is entirely in the browser.

---

## The server side is already correct — do not change it

This is worth stating explicitly, because the symptom looks like a
validation bug and invites a wrong fix.

| Location | Rule | Verdict |
|---|---|---|
| [`task_edit.php:254-256`](../../task_edit.php#L254-L256) | `status === 'on_hold'` ⇒ on-hold reason required | correct |
| [`task_edit.php:263-271`](../../task_edit.php#L263-L271) | task is delayed ⇒ delay reason required | correct |
| [`task_edit.php:337`](../../task_edit.php#L337) | store on-hold reason iff `status === 'on_hold'` | correct |
| [`task_edit.php:338-342`](../../task_edit.php#L338-L342) | store delay reason iff delayed (status `!= completed`) | correct |
| [`task_detail.php:76-78`](../../task_detail.php#L76-L78) | delayed predicate | correct |
| [`includes/functions.php:281-295`](../../includes/functions.php#L281-L295) | `isTaskDelayed()` — the canonical predicate | correct |

Two independent facts already prove the two reasons are meant to coexist:

1. **`task_detail.php` renders both panels at once.** At
   [`task_detail.php:480-566`](../../task_detail.php#L480-L566) the Status
   Reason card has two independent `if` blocks —
   `if ($detail_is_on_hold):` and `if ($detail_is_delayed):` — each with its
   own textarea and Save button. A task that is both gets **both editors**.
   The Edit form is the only place that prevents this.

2. **The schema has two independent nullable columns.**
   `database/schema.sql:151-152`.

> The canonical "is delayed" predicate is `isTaskDelayed()` in
> `includes/functions.php`:
>
> ```php
> function isTaskDelayed(array $task): bool {
>     $date = $task['expected_completion_date'] ?? null;
>     if ($date === null || $date === '') { return false; }
>     $ts = strtotime((string)$date);
>     if ($ts === false) { return false; }
>     if ($ts >= strtotime(date('Y-m-d'))) { return false; }
>     $status = $task['status'] ?? null;
>     return $status !== 'completed';   // ← on_hold STILL counts as delayed
> }
> ```
>
> Note the last line: **`on_hold` is explicitly not excluded.** The server
> agrees with `task_detail.php`. Only the JS disagrees.

---

## The fix

### One condition in `assets/js/script.js`

The delay panel's visibility must depend on **the due date**, not on the
status alone. Remove the `v !== 'on_hold'` exclusion:

```js
// assets/js/script.js — initStatusReason(), sync()
var show = (key === 'on_hold' && v === 'on_hold') ||
           (key === 'delayed' && v !== 'completed');
```

That alone makes the panel visible for an on-hold delayed task.

### But there is a second half to the fix — the JS cannot see the due date

`initStatusReason()` only has `statusSel.value`. It has **no access to the
expected completion date**, so it cannot decide "is this task delayed?" on its
own. The server already computes that predicate from the due date and the
status, but the JS has to reimplement it.

**The cleanest approach** is to have the server emit the initial visibility
flags as data attributes, so PHP stays the single source of truth for the
delayed predicate (and the JS does not have to re-derive the date rule a
third time).

#### Step A — emit the server-computed flags from `task_edit.php`

The render block already computes `$show_delay_reason` at
[`task_edit.php:617-619`](../../task_edit.php#L617-L619):

```php
$expected_for_render = $_POST['expected_completion_date'] ?? ($task['expected_completion_date'] ?? null);
$show_delay_reason = $current_status !== 'completed'
    && !empty($expected_for_render)
    && strtotime((string)$expected_for_render) < strtotime(date('Y-m-d'));
```

Add the flags to the two panel wrappers so the JS can read them:

```php
<div class="field form-section" data-reason-panel="on_hold"
     data-reason-show="1"
     <?= $current_status === 'on_hold' ? '' : 'hidden' ?>>
    ...
</div>

<div class="field form-section" data-reason-panel="delayed"
     data-reason-show="<?= $show_delay_reason ? '1' : '0' ?>"
     <?= $show_delay_reason ? '' : 'hidden' ?>>
    ...
</div>
```

#### Step B — read the flags and re-sync on date change

In `initStatusReason()`, read the server's initial answer and keep it in
sync when the user edits the date:

```js
function initStatusReason() {
    var statusSel = document.querySelector('[data-status-select]');
    if (!statusSel) return;

    var panels = {
        on_hold: document.querySelector('[data-reason-panel="on_hold"]'),
        delayed: document.querySelector('[data-reason-panel="delayed"]')
    };
    var delayHint = document.querySelector('[data-delay-hint]');

    // The server decides "is delayed?" (it owns the date rule). Read its
    // initial answer from the data attribute.
    var isDelayed = function() {
        if (!panels.delayed) return false;
        return panels.delayed.getAttribute('data-reason-show') === '1';
    };

    function sync() {
        var v = statusSel.value;

        // On-hold panel: shown exactly when the status is on_hold.
        if (panels.on_hold) {
            panels.on_hold.hidden = (v !== 'on_hold');
        }

        // Delay panel: shown exactly when the task is delayed. A task that
        // is on_hold AND past its due date shows BOTH panels.
        if (panels.delayed) {
            var delayed = isDelayed();
            panels.delayed.hidden = !delayed;
            if (delayHint) { delayHint.hidden = !delayed; }
        }

        // Apply required/disabled consistently to whichever panels are open.
        Object.keys(panels).forEach(function(key) {
            var p = panels[key];
            if (!p) return;
            var show = !p.hidden;
            var ta = p.querySelector('textarea');
            var lab = p.querySelector('.req');
            if (ta) {
                ta.required = show;
                ta.disabled = !show;
            }
            if (lab) { lab.hidden = !show; }
        });
    }

    statusSel.addEventListener('change', sync);

    // Keep the delay panel in step with the due date: the user can move the
    // expected completion date, which changes whether the task is delayed.
    var dateInput = document.querySelector('#expected_completion_date');
    if (dateInput) {
        dateInput.addEventListener('change', function() {
            // Recompute the delayed predicate the same way PHP does.
            var v = dateInput.value;
            if (!v) {
                panels.delayed.setAttribute('data-reason-show', '0');
            } else {
                var ts = Date.parse(v + 'T00:00:00');
                var today = new Date();
                today.setHours(0, 0, 0, 0);
                var delayed = ts < today.getTime() && statusSel.value !== 'completed';
                panels.delayed.setAttribute('data-reason-show', delayed ? '1' : '0');
            }
            sync();
        });
    }

    sync();
}
```

> **The `data-reason-show` attribute is the authoritative flag.** The
> `dateInput` listener only updates it when the user actually changes the
> date; the server-provided value is used for every initial render and after
> every form round-trip (including a failed validation re-render, where PHP
> re-computes `$show_delay_reason` from `$_POST`).

---

## Why the date listener matters

Without it there is a second, subtler version of the same bug:

- The task is `on_hold`, due date **in the future** → delay panel hidden.
  Correct today.
- The user opens the date picker and moves the due date to **yesterday** →
  the task is now delayed, but the delay panel is still hidden, so the next
  save fails with the same dead-end error.

The server-side `$show_delay_reason` uses `$_POST['expected_completion_date']`
first, so it **does** see the new date on re-render — which means the user
sees the correct error message but still has no field to fill in. The date
listener closes that gap live, without a round-trip.

---

## Alternative: a server-driven approach (also acceptable)

If the dev prefers not to touch the JS at all, the panels can be rendered
unconditionally and revealed purely by CSS/JS using the existing
`data-reason-show` attribute — but the `disabled` attribute **must not** be
set for the delay textarea while `status !== 'completed'`. Remember that
`disabled` is what removes the field from the POST body; `readonly` or a
`hidden` wrapper alone would keep the value submitted.

The essential invariants are the same either way:

| Invariant | Why |
|---|---|
| The delay textarea is **not** `disabled` while the task is delayed | A disabled control is not submitted, which is what produces the empty POST field |
| The delay panel visibility is driven by the **due date**, not the status | The two conditions are independent |
| `status === 'on_hold'` must **not** hide the delay panel | This is the bug |

---

## Regression tests to add

Extend `database/task_status_reasons_test.php` (it already pins both reason
fields in `task_edit.php`). The current assertions are string-presence
checks; add behavioural ones.

### 1. Source-level guard — the exclusion must be gone

```php
// The delay panel must not be hidden merely because the status is on_hold.
ok(strpos($script, "v !== 'completed' && v !== 'on_hold'") === false,
    'assets/js/script.js still excludes on_hold from the delay-reason panel');

ok((bool)preg_match("/data-reason-show/", $edit),
    'task_edit.php does not emit the server-computed reason-panel visibility flag');
```

### 2. Behavioural test — the on-hold + delayed form

Create a task with `status = 'on_hold'`, an `expected_completion_date` in the
past, and a non-empty `on_hold_reason`. Then:

1. `GET /tasks/<id>/edit` and assert the response contains **both**:
   - `id="on_hold_reason"` inside a **non-hidden** panel
   - `id="delay_reason"` inside a **non-hidden** panel
2. `POST /tasks/<id>/edit` with `status=on_hold`, a valid `on_hold_reason`,
   a valid `delay_reason`, and the rest of the form → assert the save
   **succeeds**.
3. Assert the row now has **both** columns populated.
4. `POST` the same form but with `delay_reason` empty → assert the save is
   **rejected** with the delay-reason error (the server rule must stay
   strict).

### 3. Matrix test

Assert the panel visibility for all four statuses × (due date past / future):

| `status` | due date past | due date future | expected delay panel |
|---|---|---|---|
| `not_started` | shown | hidden | — |
| `in_progress` | shown | hidden | — |
| `on_hold` | **shown** | hidden | ← the regression |
| `completed` | hidden | hidden | — |

### 4. The disabled-attribute trap

Assert the delay textarea is **not** `disabled` whenever the delay panel is
visible:

```php
// A disabled textarea is omitted from the POST body, which is exactly how
// the original bug turned a hidden field into a failed save.
ok(preg_match('/<textarea[^>]*id="delay_reason"[^>]*>/', $html, $m)
   && strpos($m[0], 'disabled') === false,
   'the delay_reason textarea is disabled while the task is delayed');
```

> The rendered HTML will not contain `disabled` on the textarea today, because
> the `disabled` attribute is added by JavaScript at runtime — so this check
> must be a **browser** assertion, not a raw-HTML one. Use the same approach
> as the existing browser-found checks in
> `database/v2_0_acceptance_test.php`.

---

## Files to change

| file | change |
|---|---|
| `assets/js/script.js` | `initStatusReason()` — read `data-reason-show`; drop the `v !== 'on_hold'` exclusion; add the due-date `change` listener; keep the `required` / `disabled` handling consistent with the *visible* state |
| `task_edit.php` | Emit `data-reason-show` on both reason panels (the server-computed visibility) |
| `database/task_status_reasons_test.php` | Add the regression checks above |

### Not to be changed

| file | why |
|---|---|
| `task_edit.php` validation block (`:254-271`) | already correct — do not relax it |
| `task_edit.php` storage normalizer (`:337-342`) | already correct — both reasons are stored independently |
| `includes/functions.php` `isTaskDelayed()` | already correct — `on_hold` counts as delayed |
| `task_detail.php` Status Reason card | already correct — renders both panels together |
| `ajax/update_task_status.php` | already correct |
| `database/schema.sql` | already correct — two independent nullable `TEXT` columns |

---

## Acceptance criteria

- [ ] A task with `status = 'on_hold'` **and** a past `expected_completion_date`
      shows **both** the On-hold reason and the Delay reason textareas on
      `/tasks/<id>/edit`.
- [ ] Both textareas are **enabled** (not `disabled`) and both are `required`
      when their condition holds.
- [ ] Saving that task with **both** reasons succeeds, and the row stores both
      values.
- [ ] Saving it with an **empty delay reason** still fails with
      "A delay reason is required when the task is past its expected completion
      date." — the server-side rule is unchanged.
- [ ] Saving it with an **empty on-hold reason** still fails with
      "An on-hold reason is required when status is On Hold."
- [ ] A task with `status = 'on_hold'` and a **future** due date shows only
      the On-hold reason textarea.
- [ ] A task with `status = 'in_progress'` and a past due date still shows
      the Delay reason textarea (no regression on the existing behaviour).
- [ ] Moving the expected completion date in the form to the past reveals
      the Delay reason textarea without a page reload; moving it to the future
      hides it again.
- [ ] `database/task_status_reasons_test.php` passes with the new checks
      added.
- [ ] `php -l task_edit.php` is clean.
- [ ] No `disabled` attribute is present on a visible reason textarea
      (verified in the browser, not in raw HTML).

---

## Cross-references

- [issue-034](issue-034.md) — the original Status Reason feature: the two
  columns, the delayed semantics, and the required-reason rules.
- [issue-043](issue-043.md) — the v2 conversion of `task_detail.php`, which
  proves both reason editors coexist on the detail page.
- [issue-055](issue-055.md) — the other browser-found defects in the v2.0
  cycle; same "validate in a real browser" lesson.
