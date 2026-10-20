# Tester note — issue-034 (v1.8.0: delayed / on-hold reasons) — round 1, browser re-test

> **Author:** Tester (manual browser re-test of the dev's v1.8.0 implementation,
> after their `task_status_reasons_test.php` + source changes were deployed).
> **Scope:** 2 new bugs found in the browser that the structural
> drift-guard does NOT cover. Both have working reproduction scripts at the
> top of each section.
>
> **Quick verdict:** v1.8.0 is **NOT ready to ship**. 2 functional bugs and
> 1 layout bug need fixes before the dev cycle is done.

---

## 1. Summary

| # | Bug | Status | Severity |
|---|---|---|---|
| 8 | `task_edit.php` — picking "On Hold" from the status dropdown does NOT show the on-hold-reason `<textarea>` until after the form is submitted and rejected by the server | **Unfixed — affects every leaf task save** | High |
| 9 | `client/dashboard.php` — the new `.task-reason` chip is a flex sibling of `.task-body` inside `.task-line`; the chip is not constrained to its own row, so a multi-line reason visually overlaps the task title and badge | **Unfixed — visible on every on-hold / delayed task with a reason longer than a few words** | High |
| – | All 7 functional checks in the dev's reminder (parent put-on-hold, AJAX status change, inline `[data-save-reason]`, `tasks.php?status=delayed&missing_reason=1`, `client/dashboard.php` per-task + project summary, `_diag.php` md5s, parent-with-subtasks on-hold) **work as spec'd**. All `ok()` assertions in `database/task_status_reasons_test.php` pass. The 2 new bugs above are the only blockers. | | |

The structural drift-guard (`task_status_reasons_test.php`, 8 `ok()`
assertions) is **GREEN** — it catches the presence of the right files /
helpers / endpoints / CSS classes but it deliberately doesn't exercise the
DOM shape or the runtime JS event handlers, which is exactly where the 2
new bugs live.

---

## 2. Live evidence + root cause

### 2.1 Surface 8 — `task_edit.php` status dropdown does NOT show the on-hold / delay-reason `<textarea>` until after a failed submit

**Reproduction (live, as `luozongbao`):**

1. Open any leaf task (a task with no sub-tasks) that is currently `not_started` or `in_progress` in `task_edit.php?id=<id>`.
   - Test task: Sub Task3 (id=935, on-hold, but the same flow reproduces when you save it back to `not_started`).
2. The form loads with **Status: Not started** — no reason field visible. ✅
3. Click the **Status** dropdown and pick **"On Hold"**.
4. **Expected:** the "On-hold reason" `<textarea>` appears immediately below the dropdown (the dev's spec §3.1 calls for "Entered when the owner changes the status to on_hold … shows a `<input type="text" name="on_hold_reason" required>` field above the submit button").
5. **Actual:** nothing happens. The **Save** button at the bottom of the form is still the only thing that's changed.
6. Click **Update Task**.
7. The server validates and **returns 200 with `errors[] = "An on-hold reason is required when status is On Hold."`** (see `task_edit.php:223`). The page reloads with the **on-hold reason `<textarea>` now visible** (because `$show_on_hold_reason = ($_POST['status'] ?? ($task['status'] ?? 'not_started')) === 'on_hold'` flips to true on the re-render).

**Root cause:**

The `<select>` is rendered with an `onchange` handler that calls an inline JS function:

```html
<!-- task_edit.php ~line 534 -->
<select id="status" name="status" onchange="handleStatusChange(this.value)">
```

But `handleStatusChange()` only manages the **Completion date** group and the **Completion % = 100** rule for "Completed". It does **NOT** toggle the visibility of the reason textareas:

```js
// task_edit.php ~line 814
function handleStatusChange(status) {
    const completionDateGroup = document.getElementById('completion_date_group');
    const completionPercentage = document.getElementById('completion_percentage');
    const completionSlider = document.getElementById('completion_slider');

    if (status === 'completed') {
        completionDateGroup.style.display = 'block';
        completionPercentage.value = 100;
        completionSlider.value = 100;
        // Set completion date to now if empty
        …
    } else {
        completionDateGroup.style.display = 'none';
        document.getElementById('completion_date').value = '';
    }
}
```

The reason `<textarea>` fields are **only rendered server-side** at PHP
form-render time, gated by:

```php
// task_edit.php ~line 583
$show_on_hold_reason = ($_POST['status'] ?? ($task['status'] ?? 'not_started')) === 'on_hold';
```

Once the page is rendered, the field is **either present in the DOM** or
**not present at all** — there's no element to show / hide, so the JS
handler can never bring it into view.

That explains the user's reported flow:

- Change to On Hold → click Update → server-side `<ul class="alert-danger">` error → page re-renders with `$_POST['status'] === 'on_hold'` → `$show_on_hold_reason === true` → textarea appears **only now**.

**Why the dev's drift-guard didn't catch it:** `task_status_reasons_test.php` assertion 5 ("`task_edit.php` has the 2 new `<textarea>` fields with the correct `name` attributes") greps for `on_hold_reason` in the PHP source — it finds the conditional render and is happy. The test has no `jQuery`-style browser test rig (the project doesn't have one) so the missing JS handler is invisible to it.

**Reference for the dev — the right places to fix:**

| # | Option (descending effort) | Effort | Notes |
|---|---|---|---|
| **A** | Render BOTH reason textareas as **always-present** `<div>` blocks with `style="display:none"` toggled by `handleStatusChange`. PHP keeps one render, JS adds the show/hide logic. | **Lowest** | Recommended. The HTML5 `required` attribute becomes unconditional — fine, the server normalizer (`$stored_on_hold_reason = ($status === 'on_hold') ? … : null;`) will NULL the column out when status is not `on_hold`. The "field is required" UX message will simply never fire for tasks that aren't on hold because `required` is only enforced when the field is visible (browsers don't enforce `required` on display:none fields). |
| B | Keep the conditional server-side render but add `handleStatusChange` to **inject** a `<textarea>` into the DOM when status flips to on_hold or delayed, and remove it on flip-back | Medium | More moving parts; needs the same `data-*` attributes the PHP-rendered version uses (so any future JS validation doesn't have to know which path produced the field) |
| C | Add a redirect-after-POST cycle where the user's status pick is preserved and the page re-renders with the textarea in place | Medium | Slower UX (full page round-trip for every dropdown change), no benefit over A |

The full fix should also cover the **`delay_reason` mirror case**: a task whose user manually sets `expected_completion_date` to a past date and then clicks Update should NOT have to round-trip before seeing the "Delay reason" field appear. Today's PHP logic for `$show_delay_reason` is the same pattern (render-only at PHP time), so a single JS path that handles both is the right answer.

**Recommended fix sketch (kept abstract so the dev adapts to the in-repo style):**

```js
function handleStatusChange(status) {
    // existing completion-date + 100% logic ...

    // Issue-034 v1.8.0: reveal the on-hold / delay-reason textareas
    // whenever their condition could become true on the next submit.
    // The server-side normalizer keeps the DB in sync, so we don't
    // need to worry about storing whitespace when the user picks then
    // unpicks on_hold.
    const holdField  = document.getElementById('on_hold_reason_group');
    const delayField = document.getElementById('delay_reason_group');
    if (holdField)  holdField.style.display  = (status === 'on_hold') ? 'block' : 'none';
    if (delayField) {
        const expectedDate = (document.getElementById('expected_completion_date') || {}).value || '';
        const todayIso = new Date().toISOString().slice(0, 10);
        const isDelayed = status !== 'completed' && expectedDate !== '' && expectedDate < todayIso;
        delayField.style.display = isDelayed ? 'block' : 'none';
    }
}
```

And in PHP, wrap each `<textarea>` render in a wrapper `<div id="…_group">` so the JS has a target without changing the existing form layout.

### 2.2 Surface 9 — `client/dashboard.php` per-task `.task-reason` chip overlaps the title row when the reason is more than a few words

**Reproduction (live, anonymous browser):**

1. Open `http://web.local/client/dashboard.php?code=388YY-CZV2W-R99BA&lang=en` (or any project that contains an on-hold or delayed task whose reason is longer than ~30 chars).
2. Look at **Sub Task3** (id=935 in this DB). It's `on_hold`, and its `on_hold_reason` is set to a 470-char "Lorem ipsum dolor sit amet, consectetur adipiscing elit. …" paragraph (this was a manual test entry from the dev's earlier put-on-hold flow; any real-world reason of similar length triggers the same rendering).
3. **Expected (per the user's screenshot):** the chip "On hold: <reason text>" sits cleanly **below** the progress bar, in its own row, with the full task content above it intact.
4. **Actual (per the user's screenshot):** the chip's first 1-2 lines render alongside "Sub Task3" and the orange "On hold" badge, making the chip text appear to "overlap" the task title and the description that would otherwise be below it. Once you scroll, the chip text continues downward over the same vertical range as the task meta line ("Atipat Lorwongam · 4 hours").

**Why this happens — DOM structure:**

The chip is rendered as a **flex sibling of `.task-body`, inside the `.task-line` flex row**:

```html
<!-- ACTUAL rendered HTML for Sub Task3 (curl http://localhost/client/dashboard.php?code=388YY-CZV2W-R99BA&lang=en) -->
<li class="task-item status-on_hold" data-level="1">
  <div class="task-line">                       ← display: flex; gap: 8px; align-items: flex-start
    <span class="tree-indent">└─</span>
    <div class="task-body">                     ← flex: 1; min-width: 0  (this is why the chip can sit beside it)
      <div class="task-head">…title + badge…</div>
      <div class="task-meta">…user · 4 hours…</div>
      <div class="task-progress">…40.0%…</div>
    </div>                                      ← /task-body
    <div class="task-reason on-hold">           ← NO flex constraint → it eats the leftover width
      <span class="task-reason-label">on hold:</span>
      Lorem ipsum dolor sit amet, consectetur adipiscing elit. Suspendisse
      elementum elementum massa, ut varius sapien consectetur et. …
    </div>
  </div>                                        ← /task-line
</li>
```

And the CSS confirms it (`client/assets/client.css:434-453`):

```css
.task-reason {
    margin: 8px 0 6px 0;
    padding: 8px 10px;
    border-radius: 4px;
    background: #f6f8fa;
    border-left: 3px solid #d0d7de;
    font-size: 0.86rem;
    color: #24292f;
    line-height: 1.4;
}
.task-reason.delay   { border-left-color: #cf222e; background: #fff5f5; }
.task-reason.on-hold { border-left-color: #d4a72c; background: #fff8e1; }
.task-reason .task-reason-label { font-weight: 600; color: #57606a; margin-right: 4px; }
```

The chip has **no `flex` properties, no `width`, no `align-self`**. Combined
with the parent's `align-items: flex-start`, the chip is laid out as the
second flex item at the **top** of `.task-line`, **next to** `.task-body`
rather than **below** it. Short reasons render OK because the chip's
content height is small enough that the visual "where does it start" is
ambiguous, but a long reason (the Lorem ipsum paragraph) makes the
malpositioning obvious: the chip starts at the same vertical line as the
task title, and its multi-line text descends across the entire left edge
of the row, covering (visually) the title area, the meta line, and the
progress bar.

The owner-app side (`tasks.php`) has the same DOM shape and the same CSS
in `assets/css/style.css:881+`, so the same bug exists on the internal
PM dashboard. Not visible in this user's screenshot, but **same bug,
different surface**.

**Reference for the dev — right places to fix:**

The chip needs to be a child of `.task-body` (so it participates in the
flex column inside `.task-body`), OR a sibling of `.task-line` (so it
sits below the whole row). Two equivalent CSS-level fixes; pick one:

| # | Option | Notes |
|---|---|---|
| **A** | Move the chip render into `.task-body` in `client/dashboard.php` (and `tasks.php`); close `.task-body` AFTER the chip | Smaller patch; the chip inherits the body's width and naturally stacks. Preferred for the symmetry with the spec §3.2 markup sketch. |
| B | Add `flex-basis: 100%; min-width: 0;` to `.task-reason` (in both CSS files) so the chip forces its own row without moving the PHP | Smaller CSS-only patch; does not need a PHP change. Works because `.task-line` is already `flex` and `flex-wrap` (default) lets a 100%-basis item drop to its own line. |

Option B is the lowest-risk patch (no PHP changes → no impact on the
test suite, no impact on the inline-edit `<details>` on
`task_detail.php`, no impact on future subtask counting). The dev
should ship B for the hotfix and consider folding in A in the next
issue for code clarity.

**Recommended fix sketch (kept abstract):**

```css
/* Append to client/assets/client.css, mirroring in assets/css/style.css */
.task-reason {
    /* existing rules preserved ... */
    flex-basis: 100%;      /* force own row inside .task-line flex container */
    min-width: 0;          /* let long text wrap, don't overflow the row */
    word-wrap: break-word; /* safety against unbreakable tokens */
}
```

The drift-guard `task_status_reasons_test.php` assertion 4 greps for
`task-reason-` in the PHP source, so option B (CSS-only) is invisible
to it; the dev should add a separate CSS-grep check that the
`.task-reason` rule ships a `flex-basis` or `width: 100%` — see §3.3
below.

---

## 3. Recommended fixes (for the dev to apply, not the tester)

### 3.1 Fix the JS-handler gap in `task_edit.php`

- Wrap each existing conditional render of `<textarea name="on_hold_reason">` and `<textarea name="delay_reason">` in a `<div id="…_group">` so the JS has a target.
- Add 2 show/hide branches to `handleStatusChange()` per the sketch in §2.1: on-hold flips on `status === 'on_hold'`; delay flips on `status !== 'completed' && expected_completion_date < CURDATE()` (mirror the server-side rule).
- Make sure the `DOMContentLoaded` handler at the top of the same `<script>` block calls `handleStatusChange(document.getElementById('status').value)` so a row that loads already-on-hold or already-delayed (with a pre-existing reason) still matches the JS state.
- The PHP conditional (`$show_on_hold_reason`, `$show_delay_reason`) MUST stay — the JS path is a UX nicety, the server-side render is the authoritative "first paint" path.

**Regression-test addition (drop into `database/task_status_reasons_test.php`):**

```php
// Read the inline <script> block at the bottom of task_edit.php.
$edit_src = file_get_contents(__DIR__ . '/../task_edit.php');
$script_block = '';
if (preg_match('~<script\b(?!er)(?!src)[^>]*>(.*?)</script>~si', $edit_src, $m)) {
    $script_block = $m[1];
}
ok(
    'task_edit.php handleStatusChange() toggles the on-hold-reason textarea',
    strpos($script_block, 'on_hold_reason') !== false
        && preg_match('/on_hold_reason[^"]*group[^"]*\.style\.display/s', $script_block),
    'expected handleStatusChange() to show/hide the on-hold reason field by id'
);
ok(
    'task_edit.php handleStatusChange() toggles the delay-reason textarea',
    strpos($script_block, 'delay_reason') !== false
        && preg_match('/delay_reason[^"]*group[^"]*\.style\.display/s', $script_block),
    'expected handleStatusChange() to show/hide the delay reason field by id'
);
```

The drift-guard runs in <2s (no DB, source-grep only); the cost of adding
this assertion is ~5 lines.

### 3.2 Fix the chip layout in `client/dashboard.php` and `tasks.php`

- Add the 3 CSS rules from §2.2 Option B to `client/assets/client.css` (the chip rules at lines 441-453) and to `assets/css/style.css` (the chip rules at lines 893+).
- No PHP changes are required.

**Regression-test addition (drop into `database/task_status_reasons_test.php`):**

```php
$client_css = file_get_contents(__DIR__ . '/../client/assets/client.css');
ok(
    'client/assets/client.css .task-reason declares a flex / width rule so the chip takes its own row',
    preg_match('/\.task-reason\s*\{[^}]*(flex-basis:\s*100%|width:\s*100%|align-self:\s*stretch)/s', $client_css),
    'expected .task-reason to ship flex-basis: 100% (or width: 100% / align-self: stretch) so it never sits beside .task-body'
);
$app_css = file_get_contents(__DIR__ . '/../assets/css/style.css');
ok(
    'assets/css/style.css .task-reason declares a flex / width rule so the chip takes its own row',
    preg_match('/\.task-reason\s*\{[^}]*(flex-basis:\s*100%|width:\s*100%|align-self:\s*stretch)/s', $app_css),
    'expected .task-reason to ship the same layout fix in the owner-app stylesheet (tasks.php shows the same chip)'
);
```

### 3.3 Do NOT change any of the working pieces below in this dev cycle

These were verified manually against the dev's reminder list and are
**not buggy**:

- `task_detail.php` parent-hold form (the `<input type="text" name="on_hold_reason" required>` above the **Put on Hold** button at lines 113-127) — works as spec'd. The form is server-rendered with the reason field ALREADY VISIBLE when the parent is not yet on hold, so no JS gap.
- `task_detail.php` inline `[data-save-reason]` button + `ajax/update_status_reason.php` endpoint — both verified; the full round-trip (edit textarea → click Save → in-place render of populated / missing state) works.
- `tasks.php?status=delayed&missing_reason=1` filter — verified; 4 active tasks tagged delayed; banner + per-row inline "Add reason" block visible.
- `client/dashboard.php` per-task chip + project-level summary row — verified; "2 on hold" pill rendered in the Project Info kv-list.
- `assets/js/subtask-manager.js` AJAX status change — verified; `window.prompt('Why is this task going on hold? (required)')` fires on `on_hold`; cancel reverts; submit forwards the reason. **This surface is the "Right Way" reference for how the on-hold UX should feel** — `task_edit.php` §3.1 should converge on this pattern.
- `_diag.php` all-pages probe md5s — verified; 5/5 expected md5s match (the dev's `database/probe_diag_test.php` is GREEN).
- `database/task_status_reasons_test.php` — verified; 8 `ok()` assertions pass.
- All other regression tests in `database/*_test.php` — verified; only pre-existing failures are the 3 in `client_share_code_protection_test.php` (the failed-rate-limit-per-IP cases, NOT related to v1.8.0).

The "vibe-coding with AI agent" credit line on the About popup also shipped already (the v1.7.2.2 inline-release summary). The acceptance of the v1.7.2.2 tester-note-002 (no `RELEASE.md` 404) and the v1.7.3.2 probe-diagnostics test mean this About box is the right place to surface a per-version summary going forward — not a blocker for v1.8.0.

---

## 4. Files referenced (the dev to review)

| File | Lines | Notes |
|---|---|---|
| `task_edit.php` | ~534, ~583, ~620, ~640, ~814 | §2.1 — the `onchange` handler does NOT toggle the reason fields; the conditional render at ~620 and ~640 only happens on page load |
| `client/dashboard.php` | ~280-326 (the `render_task_tree()` function) | §2.2 — the chip is rendered OUTSIDE `.task-body` but INSIDE `.task-line` (flex sibling) |
| `client/assets/client.css` | 434-453 | §2.2 — the chip's CSS has no `flex` / `width` constraint |
| `assets/css/style.css` | 893+ | §2.2 mirror — same CSS gap, same fix needed |
| `task_detail.php` | 113-127 (parent-hold form), 441-510 (Status Reason card) | ✅ already correct |
| `ajax/update_status_reason.php` | full file | ✅ verified |
| `assets/js/subtask-manager.js` | ~108 (AJAX POST), ~219 (saveInlineReason) | ✅ verified — reference pattern |
| `database/task_status_reasons_test.php` | drops in §3.1 and §3.2 | regression assertions for both bugs |

---

## 5. Verdict

- **Bug 8 (status dropdown does not show reason field)** — **High**, must fix before ship. Affects every leaf task save where the owner flips status to `on_hold` (or makes it delayed by editing the date). UX is jarring: the user thinks the form is broken because "clicking On Hold did nothing", then finds the field only after a confusing server-side error.
- **Bug 9 (chip overlaps title row)** — **High**, must fix before ship. Visible on every project that contains a delayed / on-hold task with a non-trivial reason; the spec explicitly promises the reason "is shown on the public client dashboard" and the current rendering makes the reason unreadable on the most important task states.
- The 7 working pieces (§3.3) confirm the dev's spec implementation is correct in all areas except the 2 places above; the regression test gap is a structural one (no browser test rig), and the patch should add 4 source-grep assertions to lock the JS handler + CSS layout fix in place.
- **Recommendation: do not tag v1.8.0 until bugs 8 and 9 are fixed and the new 4 assertions in §3.1 / §3.2 land. The 3-line CSS patch for 9 + the ~10-line JS patch for 8 are well under an hour of work; doing both keeps the next refactor from silently re-breaking the layout.**
