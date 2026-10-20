# Tester note — issue-034 (v1.8.0: on-hold / delayed reasons) — round 3, after the user proved cache is NOT the cause

> **Author:** Tester
> **Purpose:** **Supersedes `tester-note-002.md`.** Round-2 wrongly blamed nginx / browser cache for the still-broken overlap visual. The user loaded the screenshot in their next message with DevTools **"Disable cache"** turned ON and the Network panel showing fresh fetches — yet the bug is still present. The cache theory is therefore wrong, and the round-2 note must be retracted.
> **New verdict (real root cause):** the dev's `flex-basis: 100%` fix is **necessary but not sufficient.** It misses the missing `flex-wrap: wrap;` on the parent container `.task-line`. Without wrapping enabled on the parent, `flex-basis: 100%` is silently treated as "I want 100% but I'm allowed to shrink" — and the chip ends up on **the same row as the title**, occupying most of the visible width and visually covering the rest.
> **Fix:** **one-line CSS change** in each of the two files that style `.task-line`. The dev can ship it in 5 minutes. No need to wait for any "long-term cache-buster" work; cache-busters would not have fixed this.

---

## 0. Retraction of round 2

`tester-note-002.md` diagnosed the still-broken visual as "browser cache of `*.css`". After re-reading the user's screenshot description and confirming with the user that DevTools "Disable cache" was on while the bug was photographed:

| Claim in round 2 | Reality |
|---|---|
| "stale browser cache of `client/assets/client.css`" | **Wrong.** User's Network tab shows the file was fetched fresh while the bug was on screen. |
| "hard reload fixes it" | **Wrong.** User hard reloaded and the overlap is still there. |
| "long-term fix is a cache-buster query string on `<link>`" | **Acknowledged still useful long-term,** but not the cause of the overlap. Cache-busters do nothing for an incomplete CSS rule. |
| "v1.8.0's CSS code is fixed correctly" | **Wrong.** The dev shipped **one of two required changes** but forgot the other (the parent `flex-wrap`). |

Nothing in round 2 should be relayed to the dev as a diagnosis. Please use **this** note when going back to the dev.

---

## 1. Quick summary

| # | Finding | Status |
|---|---------|--------|
| **8** | JS handler gap (status dropdown didn't show reason textarea) | **Resolved** by dev in commit `7ff6869` — `<div id="…_reason_group">` wrappers added, `handleStatusChange()` extended, regression assertions added to `task_status_reasons_test.php` (10 checks / 33 individual assertions). |
| **9a** | Dev's CSS fix on the chip (`.task-reason`) — `flex-basis: 100%; min-width: 0; word-wrap: break-word; align-self: stretch;` | **Shipped correctly** in both `assets/css/style.css` and `client/assets/client.css`. The chip itself is good. |
| **9b (NEW)** | **Missing `flex-wrap: wrap;` on the parent `.task-line`** — without it, the chip's `flex-basis: 100%` cannot trigger a new line; chip stays on the same row as the title and visually covers it | **Open.** One-line CSS change in two files (`client/assets/client.css:216-221` and a brand-new `.task-line` block in `assets/css/style.css`). Recommended fix below. |

---

## 2. Proof the dev's chip CSS is in the served file

```bash
$ curl -s "http://localhost/client/assets/client.css?nocache=$(date +%s)" | awk '
    /^\.task-reason[[:space:]]*\{/,/^\}/' | head -25
.task-reason {
    margin: 8px 0 6px 0;
    padding: 8px 10px;
    border-radius: 4px;
    background: #f6f8fa;
    border-left: 3px solid #d0d7de;
    font-size: 0.86rem;
    line-height: 1.4;
    flex-basis: 100%;
    min-width: 0;
    word-wrap: break-word;
    align-self: stretch;
}
.task-reason.on-hold {
    border-left-color: #d4a72c;
    background: #fff8e1;
}
```

Same snippet confirmed in `assets/css/style.css` lines 893-915 (drift-guard in section 10 of `database/task_status_reasons_test.php`).
The chip rule **is** present in the served file. So why does the chip still sit on row 1?

---

## 3. The real root cause — `flex-wrap` is missing on `.task-line`

### 3.1 The DOM hierarchy (what the browser sees)

```html
<li class="task-item status-on_hold">
  <div class="task-line">              ← FLEX CONTAINER
    <span class="tree-indent">└─</span>
    <div class="task-body">…title, meta, progress…</div>
    <div class="task-reason on-hold">…chip…</div>
  </div>
</li>
```

Verified via PHP `DOMDocument` + XPath load of the live dashboard and via `/tmp/tester-flex/test.html` page mirroring this DOM.

### 3.2 The current `.task-line` rule

In `client/assets/client.css` lines 216-221:

```css
.task-line {
    display: flex;
    gap: 8px;
    align-items: flex-start;
    padding: 12px 14px;
    /* flex-wrap is MISSING — default is nowrap */
}
```

In `assets/css/style.css` (owner app) **`there is no `.task-line` rule at all`** — only the chip rule that lives inside it.

### 3.3 W3C flex spec — `flex-basis: 100%` is not a wrap trigger

From CSS Flexible Box Layout §9:

> The `flex-wrap` property specifies whether flex items are forced onto one line or can wrap onto multiple lines.
> `nowrap` … flex items are laid out in a single line; they may overflow the flex container.
> `wrap` … flex items break across multiple lines.

`flex-basis: 100%` only **requests** "I would like 100% of the inline size as my main size." Whether the item actually lands on a new line depends entirely on the parent's `flex-wrap`. With `nowrap` (default), `flex-basis: 100%` is silently treated as a basis request; the item is then subjected to the same shrinking rules as any other flex item. The chip **stays on row 1**.

### 3.4 Numerical proof — W3C §9 flex layout algorithm

Ran the algorithm against the actual DOM (container width = 592px after padding):

```text
Container content width: 592, gap: 8
Sum of bases: 617 + gaps 16 = 633
Free space: -41 (NEGATIVE -> shrink path)

  tree-indent   : basis=  25, final=  23.3px
  task-body     : basis=   0, final=   0.0px     ← flex:1 + min-width:0 shrinks fully
  task-reason   : basis= 592, final= 552.7px    ← still on ROW 1, not wrapped

Remaining after shrink: -0.07

=== FINAL LAYOUT ===
  row 1  tree-indent   :   23.3px wide
  row 1  task-body     :    0.0px wide
  row 1  task-reason   :  552.7px wide

VERDICT: chip is on the SAME row as task-body.
flex-wrap is nowrap (default) on .task-line; the chip's
flex-basis:100% does NOT cause it to wrap.

==> FIX: add `flex-wrap: wrap;` to .task-line.
```

(The Python simulation lives at `/tmp/layout.py` — re-runnable; matches the W3C free-space distribution in §9.3 and the shrinking algorithm in §9.4.)

### 3.5 Why it looks like the chip is "covering" the title

In real Chrome, the body has `flex: 1; min-width: 0`. All three items have `min-width: 0`. So when total basis > container width, the algorithm shrinks freely:

- Tree-indent → ~23 px
- Body → 0 px (content overflows vertically past the row's bottom)
- Chip → ~553 px (almost the whole row)

The chip is painted **on top of the body** because it comes later in DOM order (default stacking) and has `align-self: stretch` so it spans the row's cross-axis (vertical) for the full row height (~100 px). The body's title/meta/progress are forced below this yellow band — and because `flex: 1` had to compress it to width 0, they form a narrow vertical column clipped under or beside the chip. That is exactly the "chip covering the title" effect the user reported in the screenshot.

---

## 4. Visual proof — side-by-side comparison page

Deployed to the nginx container at:

```text
http://localhost/tester-flex.html
```

Card 1 reproduces the **current behaviour** (chip stays on row 1, covers the title).
Card 2 reproduces the **proposed fix** (chip wraps to its own row at the bottom, full width, with `flex-wrap: wrap;`).

Open it in a browser; you will immediately see the difference. The only CSS change between the cards is one line on `.task-line`.

---

## 5. Recommended fix (5-minute change)

### 5.1 `client/assets/client.css` — add one property to the existing rule (line 216)

```css
/* filepath: client/assets/client.css */
.task-line {
    display: flex;
    gap: 8px;
    align-items: flex-start;
    padding: 12px 14px;
    flex-wrap: wrap;        /* ADD THIS LINE — let flex-basis:100% actually wrap */
}
```

### 5.2 `assets/css/style.css` — add a brand-new `.task-line` block (owner app didn't have one)

```css
/* filepath: assets/css/style.css */
.task-line {
    display: flex;
    gap: 8px;
    align-items: flex-start;
    padding: 12px 14px;
    flex-wrap: wrap;
}
```

(Patch whichever section of `style.css` organises layout rules — near the existing `.task-reason` block at 893-915 is fine. The rule was missing entirely from this file, which is why the same bug is latent in `tasks.php` even though the user only reported it on the client dashboard.)

### 5.3 No PHP changes

DOM is correct. The chip's `flex-basis: 100%` becomes meaningful once the parent allows wrapping — no markup move is needed.

---

## 6. Regression assertion to add

Extend `database/task_status_reasons_test.php` with two checks alongside section 10:

```php
// Bug 9 (revised) — .task-line must declare flex-wrap so the chip's flex-basis:100%
// actually lands the chip on its own row.
$client_css = file_get_contents(__DIR__ . '/../client/assets/client.css');
ok(
    'client.css: .task-line has flex-wrap:wrap so chip drops to own row',
    (bool) preg_match('/\.task-line\s*\{[^}]*flex-wrap:\s*wrap/s', $client_css),
    'Add flex-wrap:wrap; to .task-line in client/assets/client.css'
);
$app_css = file_get_contents(__DIR__ . '/../assets/css/style.css');
ok(
    'style.css: .task-line has flex-wrap:wrap so chip drops to own row',
    (bool) preg_match('/\.task-line\s*\{[^}]*flex-wrap:\s*wrap/s', $app_css),
    'Add a brand-new .task-line rule with flex-wrap:wrap; to assets/css/style.css'
);
```

The current section 10 only checks that `.task-reason` exists with `flex-basis: 100%`. It does not verify that the parent declares wrapping, which is exactly the gap that allowed this regression.

---

## 7. Optional long-term hygiene (not a bug fix for v1.8.0)

The 30-day `Cache-Control` on `*.css` from `docker/nginx.conf` is still a bad idea for a project that ships CSS fixes between releases. After this v1.8.0 hotfix, recommend the dev add a per-deploy cache-buster (e.g. `?v=1.8.1`) to the `<link rel="stylesheet">` tags in `includes/header.php` and `client/dashboard.php` separately. But **please do not let this long-term work delay shipping the one-line `flex-wrap: wrap;` fix — they are independent issues.**

---

## 8. Verdict

- My round-2 cache diagnosis was wrong; that note should not be forwarded to the dev.
- The **correct** diagnosis is **missing `flex-wrap: wrap;` on `.task-line`** in both the client CSS and the owner-app CSS.
- Fix is two files × one CSS line each; the dev can ship it in 5 minutes.
- The user is invited to verify visually at `http://localhost/tester-flex.html`.
- After the fix lands, both browsers (Chrome + Firefox + Safari) will render the chip on row 2, full width, immediately below the progress bar — which is what the user's screenshot request described from the start.
