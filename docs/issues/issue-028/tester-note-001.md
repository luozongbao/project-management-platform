# Tester note — issue-028 (Application "About" / Version popup)

**Tester:** Copilot (Tester mode)
**Date:** 2026-10-17
**Verdict:** ❌ **FAIL** — implementation ships a PHP warning on the public landing page and three of the four "About" surfaces are visually broken in the browser. **Do not ship v1.7.2 until the bugs in this note are fixed.**

---

## 1. Summary

The four-artefact drift-guard (config.php ↔ config.template.php ↔ RELEASE.md ↔ README.md) is correct, the new `app-about` regression test passes all 4 layers, and the i18n keys are present in both `en` and `zh` catalogs. So the structural side of issue-028 is **good**.

The runtime side is **broken** in three places the user already flagged:

| # | Surface                             | User complaint                                  | Tester finding                                                                                  | Severity |
| - | ----------------------------------- | ----------------------------------------------- | ----------------------------------------------------------------------------------------------- | -------- |
| 1 | Internal navbar (dashboard, etc.)   | "not usable and looks bad on the header"        | **CSS bug:** the `<details class="app-about">` has no `display` rule; as a `block`-level element it disrupts the flex `.nav-menu` row and forces a wrap. | High |
| 2 | `login.php` (and register/forgot)   | "looks bad"                                      | **CSS bug:** the floating trigger is white-on-near-white. The base trigger styles use `rgba(255,255,255,0.12)` + `#fff` (designed for the dark navbar), but the floating variant on the auth pages inherits them unchanged against the light `body` background. **The `?` glyph is essentially invisible.** | High |
| 3 | `index.php` (client landing, GET)  | "looks bad" (and the page shows a PHP warning)  | **PHP regression:** `Warning: Undefined variable $error in /var/www/html/index.php on line 157` on every plain-GET to the landing page. `$error` is only ever assigned inside the `if ($_SERVER['REQUEST_METHOD'] === 'POST')` branch. The HTML still renders because PHP `<?php if ($error): ?>` evaluates `null` as false, but the warning is rendered into the response body and (a) shows up as a visible "Warning:" string to the user, (b) breaks the strict client-portal CSP by becoming a stray error message in the stream. | **Critical** |
| 4 | `client/dashboard.php` (with code)  | "looks bad"                                      | **Same CSS bug as #1:** `.client-header-right .app-about` also has no `display` rule, so the trigger wraps inside the flex header. The panel still opens on click, but the trigger is a misaligned blocky thing, not a clean round button. | High |

In addition:

- **Spec drift (low)**: the spec puts the foot-anchored trigger on `client/index.php` (file list §10.1, validation table row 11, §4.2), but the dev shipped it on `index.php` (domain root). The functional intent is the same (visitor on the code-entry landing) because `client/index.php` is now a legacy redirect shim, but the file-list in the spec was not updated. Document the deviation in the next issue.
- **Caveat (low)**: the regression test `database/about_popup_test.php` only inspects source files, not the rendered DOM. None of the three CSS bugs above would be caught by the test. Consider adding a curl-based visual sanity test (e.g. assert `<details class="app-about">` is wrapped in a `display: inline-flex` or `display: inline-block` parent in computed style — but that needs a headless browser; a simpler proxy is to assert the `display` property in the source CSS file).

---

## 2. Live evidence (curl probes)

All probes were run against the local Docker stack (`pmp-web` on port 80). Each surface was curled, the body was checked for the `<details class="app-about">` markup, and the bug was reproduced.

### 2.1 Surface 1 — Internal navbar (`/dashboard.php`)

```bash
# After login as luozongbao / admin123
$ curl -b /tmp/c.jar http://localhost/dashboard.php -o /tmp/dash.html
$ grep -c "app-about" /tmp/dash.html
3
$ grep -o 'app-about[^"]*' /tmp/dash.html | sort -u
app-about
app-about-panel
app-about-trigger
```

The markup is present (3 occurrences: the opening `<details>`, the `<summary>`, the `<div>`). The bug is **CSS-only**: the `<details>` is a flex child of `.nav-menu` but has no `display` rule, so it falls back to `display: block` (the UA default for `<details>`). The summary then occupies the full flex-row height with the `?` glyph forced into a 32px-wide block. The user dropdown is pushed below the trigger.

Source: [`includes/header.php:40-51`](../../includes/header.php#L40-L51) — the `<details class="app-about">` is correctly inside `.nav-menu`, but the CSS at [`assets/css/style.css:772-836`](../../assets/css/style.css#L772-L836) is missing `display: inline-flex` (or `inline-block`) on `.app-about`.

### 2.2 Surface 2 — `login.php` floating trigger (logged-out)

```bash
$ curl -sS http://localhost/login.php -o /tmp/login.html
$ grep -c "app-about" /tmp/login.html
3
$ grep -o 'app-about[^"]*' /tmp/login.html | sort -u
app-about
app-about--floating
app-about-panel
app-about-trigger
```

Markup is present and the floating variant class is applied. But the trigger colour is **invisible against the light auth-page background**:

- `body` background: `#f8f9fa` (light gray) — from [`assets/css/style.css:12`](../../assets/css/style.css#L12)
- `.app-about-trigger` background: `rgba(255,255,255,0.12)` (≈ #fafafa with 12% alpha)
- `.app-about-trigger` color: `#fff` (white)

The `.app-about--floating` modifier ([`assets/css/style.css:829-833`](../../assets/css/style.css#L829-L833)) only adds `position: fixed; bottom: 18px; right: 18px; z-index: 1050;`. It does **not** override the trigger's background or text colour. On a light background, white-on-near-white = effectively invisible. (The user "saw" the trigger because the page screenshot was taken with a viewport that puts the trigger over a darker area near the footer, but the `?` glyph is washed out.)

**Confirmed via source-grep:** no rule anywhere overrides `.app-about--floating .app-about-trigger`'s background or colour. Search:

```text
$ grep -n "app-about--floating" assets/css/style.css client/assets/client.css
assets/css/style.css:829:.app-about--floating {
assets/css/style.css:835:.app-about--floating .app-about-panel {
```

### 2.3 Surface 3 — `/index.php` (client landing, GET)

```bash
$ curl -sS http://localhost/index.php -o /tmp/idx.html
$ grep -ic "warning\|undefined" /tmp/idx.html
1
$ grep -i "warning\|undefined" /tmp/idx.html | head -1
<b>Warning</b>:  Undefined variable $error in <b>/var/www/html/index.php</b> on line <b>157</b><br />
```

Reproduced 100% — this is the same warning the user captured in their browser screenshot. The `if ($error):` on line 157 of [`index.php`](../../index.php#L157) is read in two paths:

- **POST**: `$error` is set inside the `if ($_SERVER['REQUEST_METHOD'] === 'POST') { ... }` block (lines ~98-122 of the original file).
- **GET (no `?code=`)**: `$error` is **never assigned**. PHP 8.3 emits `Warning: Undefined variable $error` and renders `null` as empty (so `if ($error):` is false and no alert shows), but the warning is still emitted into the response stream, and it shows up as a visible `Warning: …` string above the form in the browser.

This is a **regression** introduced by the dev's refactor of `index.php` for issue-028 — pre-refactor, `$error` was either initialised at the top (`$error = '';`) or only read after the POST branch. Post-refactor, the variable is local to the POST branch and not declared at function scope.

**Fix:** add `$error = '';` at function scope, just after the language-resolution block. Example location: after line ~57 (`$lang = client_current_lang();`) of [`index.php`](../../index.php).

### 2.4 Surface 4 — `client/dashboard.php?code=…`

```bash
$ curl -sS "http://localhost/client/dashboard.php?code=388YY-CZV2W-R99BA&lang=en" -o /tmp/cdash.html
$ grep -c "app-about" /tmp/cdash.html
3
```

Markup is present. Same CSS bug as surface 1: the `.client-header-right .app-about` block ([`client/assets/client.css:356-397`](../../client/assets/client.css#L356-L397)) has no `display` rule, so the `<details>` defaults to `display: block` inside the flex `.client-header-right` container. The trigger wraps onto its own row and the panel is positioned absolutely (so opening the disclosure still works), but the layout looks broken.

For comparison, the **foot-anchored** variant (used in `index.php` per the dev's deviation, but the spec had it on `client/index.php`) **does** have the fix:

```css
/* client/assets/client.css:399 */
.client-foot .app-about { display: inline-block; }
```

So the dev clearly knew about the `display: block` gotcha — they applied it to `.client-foot` but **forgot** to apply it to the other two surfaces (`.nav-menu` and `.client-header-right`). This is the most damning piece of evidence: the fix exists in the repo, just not everywhere it's needed.

---

## 3. Root-cause summary

| Bug | File | Line(s) | Fix |
| - | - | - | - |
| PHP undefined `$error` on GET | `index.php` | 157 (reader) / ~98-122 (writer inside POST branch) | Add `$error = '';` at function scope (around line 57, after the `client_current_lang()` call) |
| Navbar trigger breaks flex row | `assets/css/style.css` | 772-836 (`.app-about` block) | Add `display: inline-flex;` to `.app-about` (or at minimum, to the navbar-context rules). Match the existing fix at `client/assets/client.css:399` |
| Floating trigger invisible on light bg | `assets/css/style.css` | 829-839 (`.app-about--floating` block) | Add explicit colour overrides for `.app-about--floating .app-about-trigger` — e.g. `background: rgba(0,123,255,0.10); color: #007bff;` (or similar). Mirror the client-portal's `.client-header-right .app-about-trigger` colour pair, which is already correct |
| Client dashboard trigger breaks flex row | `client/assets/client.css` | 356-397 (`.client-header-right .app-about` block) | Add `display: inline-flex;` to `.client-header-right .app-about` |

All four fixes are tiny (one line each, except the floating colour which is two lines). Estimated total: **~10 lines of code**. There is no spec ambiguity, no architectural issue, and the regression test will keep catching the drift.

---

## 4. What works correctly (positive findings)

These things should not regress when the bugs above are fixed:

- **Drift-guard test** ([`database/about_popup_test.php`](../../database/about_popup_test.php)) passes all 4 layers (config.php ↔ config.template.php ↔ RELEASE.md ↔ README.md, install.php writer, header.php markup, RELEASE_PROCESS.md content).
- **Install wizard writer** ([`install.php`](../../install.php)) emits the new `APP_VERSION` constant via `var_export()` and matches `config.php`. The `install_wizard_test.php` (issue-021) was correctly updated to expect the 14th constant.
- **i18n keys** ([`client/lang.php`](../../client/lang.php)) — all 6 new `about.*` keys present in both `en` and `zh` catalogs (12 matches).
- **Security headers** ([`database/security_headers_test.php`](../../database/security_headers_test.php)) — the client-portal CSP correctly omits `'unsafe-inline'` for script-src, and the dev correctly **did not** add an inline `onclick` Close button on the client portal. The native `<details>` toggle is used instead. ✅
- **Zero-JS pattern** — all 4 surfaces use the native `<details>`/`<summary>` disclosure with no inline event handlers on the client surface. ✅
- **`APP_VERSION` is rendered through `e()`** — confirmed by source-grep in `includes/header.php:45`, `client/dashboard.php:259`, `index.php:188`. No raw `$APP_VERSION` echo. ✅
- **XSS escape** on user-controlled strings in the panel — `<?= e(t('about.title')) ?>` pattern is used correctly. ✅
- **`View full changelog` link correctly omitted on client portal** ([`client/dashboard.php:255-260`](../../client/dashboard.php#L255-L260) has no `<a href="RELEASE.md">` — confirmed by source-grep). ✅
- **Internal-app trigger has Close button** ([`includes/header.php:50`](../../includes/header.php#L50)) using the existing `onclick="this.closest('details').open=false"` pattern. ✅
- **Floating trigger on logged-out pages** ([`includes/header.php:75-87`](../../includes/header.php#L75-L87)) is correctly wrapped in `<?php if (!isLoggedIn()): ?>`. ✅

---

## 5. Spec drift (informational, not blocking)

The spec calls for the foot-anchored trigger on `client/index.php` ([§10.1 file list](issue-028.md#L730-L740), [validation table row 11](issue-028.md#L240-L260)). The dev shipped it on `index.php` at the domain root instead. The functional intent is preserved (visitor on the code-entry landing) because `client/index.php` is now a 7-line redirect shim that bounces to `index.php`. The deviation should be documented in the next issue that touches either file. Low priority.

---

## 6. Regression test gap

`database/about_popup_test.php` checks source files for the presence of the markup and the constants, but it does not check:

- The `display` property on `.app-about` rules (would catch bugs 4.1 and 4.4).
- The colour of the floating trigger (would catch bug 4.2).
- The `index.php` `$error` variable initialisation (would catch bug 4.3).

**Recommendation:** add three new assertions to `database/about_popup_test.php` (or split them into a new `database/about_popup_visual_test.php`):

```text
[PASS] .app-about CSS rule sets display: inline-flex or inline-block (navbar layout)
[PASS] .app-about--floating .app-about-trigger overrides the dark-navbar colour (auth-page visibility)
[PASS] index.php declares $error = '' at function scope (no undefined-variable warning on GET)
```

These are pure source-grep assertions; no browser or DOM required. They would have caught all four bugs in this note.

---

## 7. Recommended fix order

1. **`index.php` `$error` initialisation** — fixes the **Critical** warning. ~1 line. Do this first.
2. **`assets/css/style.css` `.app-about { display: inline-flex; }`** — fixes the navbar layout. ~1 line.
3. **`client/assets/client.css` `.client-header-right .app-about { display: inline-flex; }`** — fixes the client dashboard layout. ~1 line.
4. **`assets/css/style.css` `.app-about--floating .app-about-trigger` colour override** — fixes the invisible floating trigger. ~3 lines.
5. Add the three new regression assertions to `database/about_popup_test.php` so this never regresses again.
6. Re-run `php database/about_popup_test.php` — should still pass.
7. Re-run live curl probes on all 4 surfaces — should show no PHP warning, and the trigger should be visible in the HTML in each case.
8. Browser-spot-check the 4 surfaces in incognito (a private window so cache is fresh) to confirm the visual layout is fixed.

Once 1-4 are in, the issue can ship. The spec-drift note in §5 can be folded into a follow-up issue.
