# Issue-051 — Client landing page on the v2 component layer

**Phase:** 1.5c (Installation / Client Landing Page / Client Dashboard)
**Branch:** `v.2.0`
**Status:** ✅ Complete
**Files changed:** 5 (2 PHP templates, 1 PHP lang catalog, 1 CSS file, 1 diag pin)

---

## 1. Problem

The public landing page — served at the domain root `/` via
`index.php` → `includes/Router.php` → `pmpDispatch()` → `includes/landing.php` —
was still rendering the **legacy v1 vocabulary**:

```
.client-body  →  .client-shell  →  .client-card  →  .client-brand
  →  .client-tagline  →  .client-form  →  .form-group  →  .owner-login
```

It was the last page in the client-portal surface that had not been moved
onto the v2.0 mockup design (`docs/design/2.0/landing.html`). The client
dashboard itself was already converted in **issue-050**, but the landing page
it links to was not, so the two halves of the same public surface looked like
two different products.

Concretely:

| Symptom | Detail |
|---|---|
| Purple legacy gradient | `.client-body { background: linear-gradient(135deg, #667eea, #764ba2) }` — the v1 brand treatment, not the v2 neutral `--gray-50` canvas |
| Hard-coded legacy colours | `#007bff`, `#667eea`, `#764ba2`, `#e1e4e8`, `#d0d7de`, `#f6f8fa` — none of the 103 v2 design tokens |
| Legacy form controls | `.form-group` / `.client-form label` / `.client-form small` instead of the shared `.field` / `.hint` vocabulary |
| Legacy alert | `.alert.alert-danger` (v1) instead of `.alert.alert--danger` with `.alert__body` / `.alert__title` (v2) |
| Legacy buttons | `.btn.btn-primary.btn-block` instead of `.btn.btn--primary.btn--lg.btn--block` |
| No `is-active` language state | The switcher only listed the *other* locale(s), so the user could not see which language they were currently viewing |
| Dead CSS | ~70 lines of legacy landing rules still shipped in `client/assets/client.css` |

---

## 2. Root cause

Three separate causes:

1. **The landing page never had a mockup-derived markup.** It was written
   in the v1 era and never revisited when the v2 design system landed.

2. **The v2 component layer was never applied to it.** `assets/css/v2-components.css`
   is loaded only by `includes/header.php` (behind `$use_v2_shell`). The
   landing page renders its own `<head>`, so it had to opt in explicitly —
   the same root cause as issue-050 on the client dashboard. The `<link>`
   added in issue-050 to `client/dashboard.php` was **not** present in
   `includes/landing.php` until this issue.

3. **The v1 CSS was never retired.** `client/assets/client.css` carried the
   landing rules indefinitely because the markup still referenced them.

---

## 3. The fix

### 3.1 `includes/landing.php` — v2 markup

The template body was rewritten onto the mockup vocabulary:

```html
<div class="landing">
  <div class="landing__card">
    <div class="landing__logo"><i class="fas fa-project-diagram"></i></div>
    <h1>…</h1>
    <p class="text-muted text-sm">…</p>

    <div class="alert alert--danger" role="alert">
      <i class="fas fa-circle-exclamation"></i>
      <div class="alert__body">
        <div class="alert__title">…</div>
        …
      </div>
    </div>

    <form method="POST" action="/" autocomplete="off" style="text-align:left;">
      <div class="field" style="margin-bottom:1rem;">
        <label for="code">…</label>
        <input class="input landing__code-input" id="code" name="code" …>
        <span class="hint">…</span>
      </div>
      <button class="btn btn--primary btn--lg btn--block" type="submit">…</button>
    </form>

    <div class="auth-card__divider">or</div>
    <a class="btn btn--secondary btn--block" href="/login">…</a>

    <p class="landing__note">…</p>

    <div class="landing__foot">
      <div class="lang-switch" role="group" aria-label="…">…</div>
    </div>
  </div>
</div>
```

### 3.2 `includes/landing.php` — component layer

The `<link rel="stylesheet" href="…/v2-components.css">` was added to the
landing page's `<head>`, in the canonical order established in issue-035 /
issue-050:

```
v2-tokens.css  →  style.css  →  v2-components.css  →  client/assets/client.css
```

### 3.3 `client/lang.php` — full locale list + a new string

Two new helpers and one new string:

```php
// Display labels for every supported locale.
function client_lang_labels() {
    return ['en' => 'ENG', 'zh' => '中文'];
}

// All locales for the language switcher (current locale included).
function client_all_langs() {
    return client_lang_labels();
}
```

`landing.divider_or` was added to both catalogues (`'or'` / `'或'`) for the
"or" divider between the share-code form and the owner sign-in button.

`client_other_langs()` was **left untouched** — it is still used by the
client dashboard header and by `client_lang_url()` consumers.

### 3.4 `client/dashboard.php` — consistent language switcher

The dashboard header switcher previously used `client_other_langs()`, which
hides the *current* locale. It now uses `client_all_langs()` and marks the
active locale with `.is-active` + `aria-current="true"`, so both public
pages behave the same way.

### 3.5 `client/assets/client.css` — v2 landing rules added, legacy retired

**Added** (new "Landing page (v2)" section at the end of the file), mirroring
`docs/design/2.0/v2-tokens.css` lines 1109–1141:

`.landing`, `.landing__card`, `.landing__card h1`, `.landing__logo`,
`.landing__code-input`, `.landing__foot`, `.landing__note`,
`.landing__card .auth-card__divider` (+ `::before`/`::after`),
`.landing .lang-switch`, `.landing .lang-switch-link`,
`.landing .lang-switch-link.is-active`,
`.client-header__tools .lang-switch-link.is-active` (dark-header variant),
`.landing .footer { display: none }`, plus a `@media (max-width: 520px)` block.

**Retired** (dead after the conversion):

| Removed rule | Why it is safe |
|---|---|
| `.client-body` (purple gradient) | Re-declared in the v2 section as `background: var(--gray-50)` |
| `.client-shell` (max-width 960px) | Re-declared in the v2 section |
| `.client-card`, `.client-card h2`, `.client-card h2 i` | Landing-only; no PHP references them |
| `.client-brand`, `.client-brand i`, `.client-brand h1` | Re-declared as `.client-body .client-brand` for the two **dashboard error paths** |
| `.client-tagline`, `.client-intro` | Landing-only |
| `.client-form .form-group`, `.client-form label`, `.client-form small` | Landing-only |
| `.client-foot` | Landing-only |
| `.client-body .lang-switch` (pill style, legacy) | Superseded by the v2 segmented-control rules further down the file |
| `.client-body .lang-switch-label` | No PHP reference remains |
| `.client-body .lang-switch-link` (pill style, legacy) | Superseded |
| `.client-body .owner-login`, `.client-body .owner-login-link` | The owner sign-in is now a `.btn--secondary` |
| `.client-foot .app-about*` | The landing page no longer renders a foot-anchored about-disclosure |

### 3.6 `_diag.php` — md5 pins re-bumped

Two of the five pinned files changed, so both md5 entries were re-bumped
**in the same commit** as the source change (enforced by
`database/probe_diag_test.php`):

| File | Old md5 | New md5 |
|---|---|---|
| `client/dashboard.php` | `7b90d58ee71b3bae0906583523d2f0b9` | `6e2b03107d540373417abc337f3fee04` |
| `client/lang.php` | `c1377682444842f7b3772a8b2b2a1c12` | `5d83e532b71d6cc1ef6410609aee2e8c` |

`config.php`, `includes/header.php` and `includes/functions.php` were not
touched, so their pins are unchanged.

---

## 4. Verification

### 4.1 Landing page — light mode (computed styles)

Verified in the VS Code integrated browser with
`page.emulateMedia({ colorScheme: 'light' })` against the light mockup:

| Element | Computed value | Mockup token |
|---|---|---|
| `.landing` background | `rgb(247, 249, 252)` | `--gray-50` |
| `.landing__card` background | `rgb(255, 255, 255)` | `--gray-0` |
| `.landing__card` border | `0.8px solid rgb(226, 231, 240)` | `--gray-200` |
| `.landing__card` radius / padding | `20px` / `40px` | `--r-xl` / `--sp-10` |
| `.landing__card` max-width | `520px` | 520px |
| `.landing__logo` | `64px × 64px`, brand gradient | `--r-lg` |
| `#code` font / size | `monospace` / `18.4px` | `--font-mono` / `--fs-xl` |
| `#code` align / letter-spacing | `center` / `1.84px` | `.12em` |
| `.btn--primary` background | `rgb(31, 74, 224)` | `--brand-600` |
| `.btn--secondary` background | `rgb(255, 255, 255)` | `--gray-0` |
| `.auth-card__divider` colour | `rgb(154, 165, 187)` | `--gray-400` |
| `.auth-card__divider::before/::after` | `rgb(226, 231, 240)`, `1px` | `--gray-200` |
| `.lang-switch` border / radius | `0.8px solid rgb(226,231,240)` / `10px` | `--gray-200` / `--r-md` |
| `.lang-switch-link.is-active` | `rgb(31, 74, 224)` on `rgb(255,255,255)` text | `--brand-600` |

### 4.2 Error path

Submitting `XXXXX-XXXXX-XXXXX` renders the v2 danger alert:

| Property | Value |
|---|---|
| `.alert--danger` background | `rgb(251, 220, 220)` |
| `.alert--danger` colour | `rgb(160, 27, 27)` |
| `.alert--danger` border | `0.8px solid rgb(243, 194, 194)` |
| `.alert--danger` display / padding | `flex` / `12px 16px` |
| `.alert__title` colour / weight | `rgb(160, 27, 27)` / `600` |
| `.alert i` colour | `rgb(160, 27, 27)` |

### 4.3 Functional checks

| Check | Result |
|---|---|
| `GET /` | 200, 2993 b |
| Valid code `388YY-CZV2W-R99BA` | → `302` → `/client/dashboard.php?code=388YY-CZV2W-R99BA` ✅ |
| Invalid code | 200, error alert rendered, input value preserved |
| `GET /?lang=zh` | All strings Chinese; `中文` marked `.is-active`; divider shows `或` ✅ |
| `GET /?lang=en` | All strings English; `ENG` marked `.is-active` ✅ |
| `GET /client/dashboard.php?code=…` | 200, 0 console errors, 0 failed requests, task tree + sub-task toggle + about popup all intact |
| Dashboard language switch | Shows `ENG` (active, white chip on dark header) + `中文` |
| `GET /login` | 200 (owner sign-in link target healthy) |
| `GET /assets/js/share-code-format.js` | 200 |
| `GET /client/assets/client.css` | 200 |

### 4.4 Static checks

| Check | Result |
|---|---|
| `php -l includes/landing.php` | clean |
| `php -l client/lang.php` | clean |
| `php -l client/dashboard.php` | clean |
| `php -l _diag.php` | clean |
| CSS token resolution (`var(--…)` vs `v2-tokens.css`) | **0 undefined** |
| Class resolution (landing + dashboard) | **0 missing** |
| Regression sweep (20 test files) | **20 PASS / 0 FAIL** |

### 4.5 Dark mode

`prefers-color-scheme: dark` flips the `--gray-*` scale (a designed feature of
`v2-components.css` §10, mirrored in the mockup at `v2-tokens.css:1328`).
Verified: body `rgb(18, 22, 31)`, card `rgb(12, 16, 24)`, h1
`rgb(241, 244, 249)`, active language chip `rgb(31, 74, 224)` on white text —
all correct.

---

## 5. Red lines observed

| Red line | Status |
|---|---|
| `id="code" name="code"` + `pattern` + `placeholder` + the frozen inline `style` block on the code input | ✅ unchanged — the `assets/js/share-code-format.js` contract is intact |
| `<script src="/assets/js/share-code-format.js" defer></script>` — **root-absolute, literal, unversioned** | ✅ unchanged — `database/probe_diag_test.php` asserts the literal `<script src="/assets/js/` prefix, so `asset_url()` was deliberately **not** used here |
| `POST action="/"` | ✅ unchanged |
| Rate-limit caps and ordering; DB lookup order; `isValidShareCodeFormat()` | ✅ untouched — the entire PHP logic block above the template was left alone |
| `share_code` format (5-5-5) and `generateShareCode()` | ✅ untouched |
| `/client/dashboard.php?code=…` URL | ✅ unchanged |
| `client_other_langs()` | ✅ left in place (still used by the dashboard header) |
| `client_lang_url()` | ✅ untouched |
| nginx `/client/` block, `.htaccess` denies | ✅ untouched |
| `.client-header-right .app-about` CSS (pinned by `about_popup_test.php`) | ✅ kept — even though the dashboard now uses `.client-header__tools` |
| `_diag.php` md5 pins re-bumped in the SAME commit | ✅ both changed files re-bumped |
| `//` line comments in `includes/landing.php` containing `<?php`/`?>` | ✅ used `/* … */` block comments only (enforced by `about_popup_test.php`) |

> **Note on a PHP parsing trap hit during this issue:** the block comment
> documenting the red lines originally contained the literal text `.btn--* /`,
> whose `*` + `/` sequence terminated the block comment early and produced
> `Parse error: Unmatched '}'`. The vocabulary list was rewritten without the
> `*` glob.

---

## 6. Design decisions

1. **The language switcher stays server-rendered `<a>` links.** The mockup
   uses `<button data-lang-set="…">` with a JS handler, but the production
   page persists the language in a **server-side cookie** (`client_lang`),
   so the server must render the links. The mockup's `is-active` visual
   state was reproduced by marking the current locale `.is-active` and
   `aria-current="true"`.

2. **The "Lost your code?" line was kept** (`landing.foot`) and given its own
   `.landing__note` class so it does not share the mockup's `.landing__foot`
   spacing. It is genuinely useful production guidance and is not in the
   mockup.

3. **`landing.divider_or` is a translatable string**, not a hard-coded `or`,
   because the divider renders on the Chinese page too.

4. **The dashboard's active language chip is white**, not brand-600, because
   the switcher lives inside the dark `.client-header__tools` bar.

---

## 7. Follow-ups

- **issue-052** — install page v2 (`install.php` still carries a 79-line
  inline `<style>` block; the user approved reusing the landing page's
  visual language).
- **issue-053** — Phase 1.6: strip the ~94 dead rules from
  `assets/css/style.css`.
- The remaining legacy `client/assets/client.css` rules flagged in the file's
  own header comment (`.task-item`, `.task-line`, `.task-body`,
  `.task-meta`, `.task-progress`, `.progress-bar`, `.progress-fill`,
  `.progress-text`, `.tree-indent`) can now be dropped once the last
  consumer of the v1 task markup is retagged.
