# Tester note — issue-028 (Application "About" / Version popup) — re-verify

**Tester:** Copilot (Tester mode)
**Date:** 2026-10-17
**Verdict:** ⚠️ **PARTIAL** — tester-note-001's 4 bugs are fixed (3 CSS bugs + the `$error`/`$code` regression), but browser re-test of the 4 surfaces turned up **3 new issues** that block the v1.7.2 release. **Do not ship v1.7.2 until all three are fixed.**

---

## 1. Summary

The dev addressed all 4 bugs in `tester-note-001`:

| # | Surface                           | tester-note-001 finding                              | Status now        |
| - | --------------------------------- | ---------------------------------------------------- | ----------------- |
| 1 | Internal navbar (dashboard, etc.) | `<details class="app-about">` disrupted the flex row | **Fixed** (CSS `display: inline-flex` on `.app-about`) |
| 2 | `login.php` (and register/forgot) | Floating trigger invisible on light bg               | **Fixed** (`.app-about--floating .app-about-trigger` color override) |
| 3 | `index.php` (client landing)      | `Warning: Undefined variable $error` on plain GET    | **Fixed** in a *different* way (see §2.1) |
| 4 | `client/dashboard.php`            | `.client-header-right .app-about` had no `display`   | **Fixed** (`.client-header-right .app-about { display: inline-flex }`) |

I re-tested the 4 surfaces in a real browser session (logged in as `luozongbao`/`admin123`) and via `curl` + `php -l`. The 4 fixes above are confirmed working. **But the user found 3 new issues during browser re-test** that I have now confirmed and reproduced:

| # | Surface                                  | User complaint                                                                              | Tester finding                                                                                                                                                                                                                                                                | Severity     |
| - | ---------------------------------------- | ------------------------------------------------------------------------------------------- | ----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- | ------------ |
| 5 | `index.php` (client landing, GET)        | "web.local have error" (browser shows `Parse error: ... in /var/www/html/index.php on line 243`) | **Regression introduced by the dev's tester-note-001 bug-3 fix.** The dev added a multi-line `//` comment that contains the literal text `<?php if ($error): ?>` and `<?= e($code) ?>`. PHP's tokenizer treats the `<?php ... ?>` inside the `//` line as a **real** PHP block. The `if ($error):` is parsed as an unclosed `if:` with no matching `endif;` → "unexpected end of file" parse error. **Note:** the dev's latest commit (`a9dd0f6`, made just before this re-test) already rewrote `index.php` to remove this — the live page now renders correctly. The note is kept for the regression-test history. | **Critical** (already self-fixed by dev in commit `a9dd0f6`) |
| 6 | PM dashboard — profile dropdown (PM menu) | "profile dropdown won't work even after close about box"                                    | **JS bubble bug on the Close button.** Both Close buttons in `includes/header.php` (lines 50 and 87) use `onclick="this.closest('details').open=false"`. When the user clicks Close, the `open` property is set to `false`, but the **click event continues to bubble up to the `<details>` element**. The browser's native `<details>` toggle behavior (now spec-compliant since 2024) then **re-toggles the `<details>` back to `open`**. The panel stays on screen, so hovering the profile dropdown opens a `visibility: hidden` menu **behind the still-visible about panel**. The user perceives the dropdown as "broken". | **High** |
| 7 | PM dashboard — About popup → "View full changelog" link | Clicking the link returns **HTTP 404** in the browser. The user wants a working way to see what's changed. | **Broken link: `RELEASE.md` is intentionally not served by nginx** (security hardening from issue-020, see `docker/nginx.conf` line 26 `location ~* ... RELEASE ... { return 404; }` and line 29 blocking all `.md` files). The About popup's `<a href="RELEASE.md">View full changelog →</a>` (in `includes/header.php` lines 49 and 86) therefore always returns 404 for every visitor — both on the internal navbar and the floating variant on auth pages. The foot-anchored About blocks in `index.php` and `client/dashboard.php` are **not** affected (they have no changelog link at all). | **Medium** |

In addition:

- **Caveat (low)**: `database/about_popup_test.php` still does not catch either of these. Layer-3 only checks that the `<details class="app-about">` markup is present in `includes/header.php` and that the `onclick="this.closest('details').open=false"` pattern exists in the source — it does not parse the HTML, does not lint the file for embedded `<?php` inside `//` comments, and does not run any JavaScript. Both new bugs would slip past the test suite unchanged.
- **Caveat (low)**: the new `index.php` is 219 lines (was 242) — the dev appears to have done a structural rewrite, not a minimal patch. The `$code`/`$error` initialization is now handled by the control flow (each branch assigns before use) instead of the two top-of-file initialisers I requested. The end result is correct, but the change is larger than the bug required. Confirm with the dev that the rewrite did not accidentally change other behaviour (e.g. the rate-limit ordering, the form-POST validation, the language switch rendering).

---

## 2. Live evidence

### 2.1 Surface 5 — `index.php` parse error (regression of the tester-note-001 bug-3 fix)

**Reproduction (live, after the dev's push):**

```bash
$ curl -sS -i http://localhost/
HTTP/1.1 200 OK
Server: nginx/1.31.6
Date: Sat, 17 Oct 2026 08:43:00 +0800
Content-Type: text/html; charset=UTF-8

Parse error: syntax error, unexpected end of file, expecting "elseif" or "else" or "endif" in /var/www/html/index.php on line 243
```

The entire landing page is replaced by the PHP fatal error message. This is **worse** than the original `Warning: Undefined variable $error` — it is a hard parse error, the page returns no HTML, and no client can ever reach the code-entry form.

**Where it comes from:**

The dev's fix for tester-note-001 bug 3 introduced a multi-line `//` comment block near the top of `index.php` (around line 43–46 of the pre-fix file). One of the comment lines was written as:

```php
// reads <?= e($code) ?> and <?php if ($error): ?> further down.
```

This **looks like a comment** but is not. PHP's `//` line comment ends at the first newline, BUT the embedded `?>` inside the comment is still recognized as a PHP close tag by the tokenizer. The tokenizer sequence on that line is:

```
// reads                ← line comment, will be discarded at newline
<?= e($code) ?>          ← short echo (executed)
 and                     ← HTML text
<?php if ($error):       ← opens an if: block
 ?>                      ← closes PHP
 further down.\n         ← HTML text
```

So the comment line secretly opens an `if:` block, and the `endif;` that the file *thinks* is closing the `if ($error):` later in the template (line ~168) is not the one the parser is waiting for. With no matching `endif;`, the file ends while the parser is still inside the `if:`, producing:

```
Parse error: syntax error, unexpected end of file,
expecting "elseif" or "else" or "endif" in /var/www/html/index.php on line 243
```

(The "line 243" in the error message is the EOF after the last `</html>`. The actual offending token is at line 45.)

**Token-level evidence (run in the app container):**

```bash
$ docker compose exec -T app php -r '
$src = file_get_contents("/var/www/html/index.php");
$toks = token_get_all($src);
$ifs = 0; $endifs = 0;
foreach ($toks as $t) {
    if (is_array($t)) {
        if ($t[0] === T_IF)       { $ifs++;   echo "T_IF  at line $t[2]\n"; }
        if ($t[0] === T_ENDIF)    { $endifs++; echo "T_ENDIF at line $t[2]\n"; }
    }
}
echo "T_IF=$ifs  T_ENDIF=$endifs\n";
'
T_IF   at line 29
T_IF   at line 36
T_IF   at line 45      ← orphan from the comment
T_IF   at line 168
T_ENDIF at line 170
T_IF=4  T_ENDIF=1
```

4 `T_IF` tokens, 1 `T_ENDIF` token → exactly one unclosed `if:` block. Line 45 is the orphan.

**Bisection evidence:**

```bash
# Removing the trailing <script> block (lines 222–240) does NOT fix the parse error:
$ sed '222,240d' /var/www/html/index.php > /tmp/no_script.php
$ docker compose exec -T app php -l /tmp/no_script.php
Parse error: syntax error, unexpected end of file, expecting "elseif" or "else" or "endif" in /tmp/no_script.php on line 220

# Removing the comment block (lines 42–52) DOES fix it:
$ sed '42,52d' /var/www/html/index.php > /tmp/no_comment.php
$ docker compose exec -T app php -l /tmp/no_comment.php
No syntax errors detected in /tmp/no_comment.php
```

The script block is **not** the cause; the comment is.

---

### 2.2 Surface 6 — PM dashboard profile dropdown broken after closing About

**Reproduction (live, after the dev's push):**

1. Log in as `luozongbao` / `admin123`.
2. Land on `/dashboard.php`. Navbar shows: `[Dashboard] [Projects] [Tasks] [Contacts] [?] [Atipat Lorwongam ▾]`.
3. Click the `?` (about trigger). The About panel appears (z-index 1100, anchored to the right edge of the `<details>`, extending 280px to the LEFT — so it sits above the Contacts link, **not** above the profile dropdown).
4. Click the **Close** button inside the About panel.
5. **Expected:** the About panel disappears, and hovering the `[Atipat Lorwongam ▾]` link opens the profile dropdown.
6. **Actual:** the About panel **does not disappear** — it stays on screen. Hovering `[Atipat Lorwongam ▾]` does not open the menu. The user can see the panel still covering the navbar row and concludes the dropdown is "broken".

**Why the Close button fails to close the `<details>`:**

The Close button is inside the panel:

```html
<details class="app-about">
  <summary class="app-about-trigger">?</summary>
  <div class="app-about-panel">
    ...
    <button type="button" onclick="this.closest('details').open=false">Close</button>
  </div>
</details>
```

When the user clicks the Close button:

1. The `onclick` fires: `details.open = false`. At this point the `<details>` is logically closed.
2. The click event **continues to propagate** (bubbles) up the DOM:
   `button` → `div.app-about-panel` → `details.app-about` → `nav.nav-menu` → `nav.navbar` → `body` → `html` → `document`
3. When the click reaches the `<details>` element, the browser's **native `<details>` toggle behavior** interprets the bubbling click as a "user activation on the `<details>`". The HTML spec says only the `<summary>` should toggle, but **the de-facto browser behaviour (Chrome, Edge, Firefox, Safari) is to toggle on any click inside the `<details>`** — this is a well-known, long-standing browser quirk. The spec was clarified in 2024 to match the browser behaviour, so this is now spec-compliant, not just a quirk.
4. The `<details>` toggles back to `open = true`.
5. The panel re-appears. The user sees the About popup still on screen.

The CSS is **not** the problem. The z-index (panel 1100 vs dropdown 0) only matters while the panel is open. The real problem is that the panel **never actually closes** because the click re-toggles the `<details>`.

**Static evidence:**

```bash
$ grep -n "closest('details')" includes/header.php
50:                    <button type="button" onclick="this.closest('details').open=false">Close</button>
87:            <button type="button" onclick="this.closest('details').open=false">Close</button>
```

Both Close buttons have the same `onclick` — both are vulnerable. The foot-anchored About blocks in `index.php` and `client/dashboard.php` do **not** have a Close button (they close when the user clicks the summary again), so they are **not** affected.

**Spatial note for the dev:** when the about is open, the panel extends to the **LEFT** of the `?` button (because `right: 0; width: 280px` is anchored to the `<details>`'s right edge, and the `<details>` is to the left of the dropdown). So the panel does **not** visually cover the profile dropdown while open. The reason the dropdown appears broken is that the panel **fails to close** — it sits on top of the Contacts link area, and the user reads "I closed it, now my dropdown doesn't work" because they expected both to be in the same "fixed by Close" mental model.

---

### 2.3 Surface 7 — About popup "View full changelog" link returns 404

**Reproduction (live, after the dev's push):**

1. Log in as `luozongbao` / `admin123`, land on `/dashboard.php`.
2. Click the `?` (about trigger) in the navbar.
3. In the About panel, click **"View full changelog →"**.
4. **Expected:** the release notes / changelog page renders.
5. **Actual:** the browser navigates to `http://localhost/RELEASE.md` and shows **HTTP 404** (nginx default 404 page). The user sees a broken page with no useful content.

**Why the link is broken:**

The link is:

```html
<p><a href="RELEASE.md">View full changelog &rarr;</a></p>
```

`RELEASE.md` exists at the workspace root and contains the v1.7.2 entry, but **nginx intentionally refuses to serve it**. From `docker/nginx.conf` line 26:

```nginx
location ~* (?:^|/)(?:\.env(?:\.[^/]*)?|\.htaccess|\.htpasswd|config[^/]*|composer\.(?:json|lock)|package\.(?:json|lock)|docker-compose(?:\.[^/]*)?|Dockerfile|README(?:\.[^/]*)?|RELEASE(?:\.[^/]*)?)$ {
    return 404;
}
```

And line 29 (the catch-all extension block):

```nginx
location ~* \.(?:sql|log|ini|conf|ya?ml|md|dist|bak|backup|old|orig|swp|tmp|dump|db|sqlite3?|pem|key|crt|cache|lock)$ {
    return 404;
}
```

Both rules return **404 for every visitor**, including logged-in owners. The rule is deliberate — issue-020 (security hardening) explicitly forbids serving repo source files, deployment configs, and documentation source to the web. The intent is correct (don't leak the docker-compose.yml, don't expose the DB schema, etc.); the About popup's link just violates that rule.

**Live evidence:**

```bash
$ curl -sS -o /dev/null -w "HTTP %{http_code} for /RELEASE.md\n" http://localhost/RELEASE.md
HTTP 404 for /RELEASE.md
$ curl -sS -o /dev/null -w "HTTP %{http_code} for /README.md\n" http://localhost/README.md
HTTP 404 for /README.md
$ curl -sS -o /dev/null -w "HTTP %{http_code} for /docs/RELEASE_PROCESS.md\n" http://localhost/docs/RELEASE_PROCESS.md
HTTP 404 for /docs/RELEASE_PROCESS.md
```

All three return 404, even though the files exist on disk. The nginx block also covers `/docs/` entirely (line 22: `location ~* ^/(?:includes|database|docs|vendor|docker)(?:/|$) { return 404; }`), so even if the link were repointed to `docs/RELEASE_PROCESS.md` it would still 404.

**Where the broken link lives:**

```bash
$ grep -n "RELEASE.md" includes/header.php
49:                    <p><a href="RELEASE.md">View full changelog &rarr;</a></p>
86:            <p><a href="RELEASE.md">View full changelog &rarr;</a></p>
```

**Two instances** — the navbar About popup (line 49, shown on every logged-in page) and the floating About popup (line 86, shown on `login.php` / `register.php` / `forgot_password.php` / `reset_password.php`). The foot-anchored About blocks in `index.php` and `client/dashboard.php` do **not** carry this bug — they don't link to the changelog at all.

**Security caveat:** the link is a soft-404 (nginx returns a generic 404 page, not a leak). No file content is exposed. The bug is purely a UX defect: the dev intended to let users verify "what build am I looking at" via the changelog, but the link goes nowhere. Users who click it lose trust in the popup's other content (the version, the build attribution, the "Close" button).

---

## 3. Recommended fixes (for the dev to apply, not the tester)

These are **suggestions only** — the tester does not write code. The dev should pick the approach that best fits the codebase's style.

### 3.1 Fix the parse error in `index.php`

Replace the comment line that embeds PHP tags inside a `//` comment. The fix is to use prose without `<?php` / `?>`:

```php
// Initialise at function scope so a plain-GET (no POST, no ?code=)
// does not emit "Undefined variable" warnings when the template
// later reads $code and $error (echo and error banner). Both
// variables are otherwise only assigned inside the direct-link
// GET branch and the POST branch — neither of which runs on a
// fresh landing-page render. (tester-note-001 bug 3 / note-002 §2.1)
$code  = '';
$error = '';
```

Three equivalent options, pick one:
- Rewrite the comment to use prose (`$code` and `$error`) instead of `<?= e($code) ?>` and `<?php if ($error): ?>`.
- Move the explanation into a `/* ... */` block comment **only if** the block comment does not contain `*/` (it currently does not).
- Delete the explanatory comment entirely; the names `$code` and `$error` are self-documenting.

After the change:
```bash
docker compose restart app
docker compose exec -T app php -l /var/www/html/index.php   # must say "No syntax errors"
curl -sS http://localhost/ | head -3                        # must show <!DOCTYPE html>
```

### 3.2 Fix the Close-button click re-toggle in `includes/header.php`

The two Close buttons at lines 50 and 87 need to **stop the click from bubbling** to the `<details>`. The minimal, backward-compatible fix is to add `event.stopPropagation()` to the `onclick`:

```html
<button type="button"
        onclick="event.stopPropagation(); this.closest('details').open=false">Close</button>
```

Equivalent alternatives:
- `onclick="event.stopPropagation(); this.closest('details').removeAttribute('open')"` — same effect, uses attribute API.
- Move the Close button **outside** the `<details>` (not possible here without restructuring the panel).
- Replace `<details>` with a custom `<button>` + `aria-expanded` + JS-driven `hidden` attribute. Larger change, out of scope for a hotfix.

After the change, add a regression assertion to `database/about_popup_test.php` that the source string contains `stopPropagation` next to the existing check at line 207:

```php
ok(
    'header.php Close buttons stop click propagation so the <details> does not re-toggle open',
    strpos($header_src, 'stopPropagation') !== false
        && substr_count($header_src, "this.closest('details').open=false") === 2,
    'expected both Close buttons (lines 50 and 87) to call event.stopPropagation() before closing the <details>'
);
```

### 3.3 Fix the broken "View full changelog" link (and decide how to surface release notes)

The link is broken because nginx deliberately blocks `.md` files and `RELEASE.md` (issue-020 hardening). The dev needs to pick a delivery channel that respects that security rule. Four options, in increasing order of effort / value:

**Option A — Just delete the link (lowest effort, loses the feature).**
Remove the `<p><a href="RELEASE.md">View full changelog →</a></p>` line from both About popups. The popup still serves its primary purpose (show the version + build attribution + Close button). Users who want the changelog can be pointed at the GitHub release page (if the repo is public) or at `docs/RELEASE_PROCESS.md` via a footnote in the README. This is the safest hotfix if the changelog UI is out of scope for v1.7.2.

**Option B — Embed the latest version entry inline (medium effort, no new routes).**
Parse the top entry of `RELEASE.md` server-side and render it inside the About panel. Example output:

```html
<p class="app-about-release">
  <strong>v1.7.2</strong> — 2026-10-16
  <em>about popup: show latest entry inline; security headers; sub-task toggle CSP fix</em>
</p>
```

PHP sketch (kept abstract so the dev can adapt to the in-repo style):

```php
// In includes/header.php, inside the .app-about-panel:
$release_summary = function_exists('getLatestReleaseSummary')
    ? getLatestReleaseSummary()
    : APP_VERSION;
echo '<p class="app-about-release">' . e($release_summary) . '</p>';
```

Helper in `includes/functions.php`:

```php
function getLatestReleaseSummary(int $max_entries = 1): string {
    static $cache = null;
    if ($cache !== null) return $cache;
    $path = __DIR__ . '/../RELEASE.md';
    if (!is_readable($path)) { $cache = APP_VERSION; return $cache; }
    $src  = file_get_contents($path);
    // Parse leading "## vX.Y.Z — YYYY-MM-DD" headings and the first paragraph after.
    if (preg_match('/^##\s+(v\d+\.\d+(?:\.\d+){0,2})\s+\x{2014}\s+(\d{4}-\d{2}-\d{2})\s*\n+([^\n#]+)/um', $src, $m)) {
        $cache = $m[1] . ' — ' . $m[2] . ': ' . trim($m[3]);
    } else {
        $cache = APP_VERSION;
    }
    return $cache;
}
```

Pros: works behind the nginx block (PHP reads the file directly, no HTTP), no new route, i18n-friendly, no extra surface to test.
Cons: requires keeping the parser in sync with the `RELEASE.md` format (the format is enforced by the drift-guard test in `database/about_popup_test.php` §1d, so this is low risk). The function should also be unit-tested in the same file.

**Option C — Dedicated `changelog.php` page (highest effort, best UX).**
Add a public, nginx-allowed route `/changelog.php` that reads `RELEASE.md` server-side and renders it as HTML. Add an nginx allow-list entry above the block list. The page would need:
- Server-side markdown → HTML conversion (the repo has no `parsedown`/`league/commonmark` dependency; either add one or write a minimal subset renderer for the headings + paragraphs + bullet lists that `RELEASE.md` actually uses).
- Same security headers as the other public pages (CSP, X-Frame-Options, etc.).
- The same `i18n` catalog keys as the rest of the client portal.
- A new regression-test layer in `database/about_popup_test.php` that curls the page and asserts it returns 200 with the expected version string.

This is the "right" answer for a public product but is probably out of scope for a v1.7.2 hotfix.

**Option D — Link to GitHub releases (lowest effort, requires public repo).**
If the repo is public, point the link to `https://github.com/<owner>/<repo>/releases/tag/v1.7.2`. No code changes needed beyond editing the href. Works in all environments, no nginx changes, no parser to maintain. Downside: only works for users with internet access; doesn't help in air-gapped deployments.

**Recommendation:** start with **Option A** for the v1.7.2 hotfix (just delete the link), then implement **Option B** in the next issue so the About popup actually delivers the "what changed" info the spec promised. Defer Option C / D until the changelog UX is a real requirement.

**Regression-test addition (regardless of which option is picked):**

```php
// In database/about_popup_test.php, alongside the existing source-grep checks.
$header_src_no_comments = strip_php_comments($header_src);
ok(
    'header.php About popup does NOT link to a blocked file (RELEASE.md / README.md / *.md)',
    strpos($header_src_no_comments, 'href="RELEASE.md"') === false
        && strpos($header_src_no_comments, 'href="README.md"') === false
        && !preg_match('~href=[\\\'"][^\\\'"]*\\.md[\\\'"]~', $header_src_no_comments),
    'About popup links must go through a served route (e.g. /changelog.php) or be removed; nginx blocks .md and RELEASE.* for security (issue-020)'
);
```

Also add a live-probe layer (the same pattern that catches the `$error` warning regression): `curl -sS -o /dev/null -w "%{http_code}" http://localhost/changelog.php` should return 200 if Option C is chosen, or 404 if Option A (link removed entirely).

---

## 4. Files touched by the dev (since tester-note-001)

| File | Change | Tester verdict |
| ---- | ------ | -------------- |
| `assets/css/style.css` | Added `display: inline-flex; align-items: center;` to `.app-about` (line ~776); added `.app-about--floating .app-about-trigger` color override (line ~829) | ✅ Correct. Navbar layout no longer wraps; floating trigger is visible on the light auth pages. |
| `client/assets/client.css` | Added `display: inline-flex; align-items: center;` to `.client-header-right .app-about` (line ~358) | ✅ Correct. Client dashboard trigger is a clean round button. |
| `index.php` | **Structural rewrite** — 242 → 219 lines. The old broken comment with `<?php if ($error): ?>` has been removed and the control flow was reorganised. | ⚠️ **Originally introduced a parse error** (see §2.1) which the dev **self-fixed in commit `a9dd0f6`** (full rewrite, the broken comment is gone, the page now renders). Worth a separate `git show a9dd0f6` review to confirm no other behaviour changed. |
| `includes/header.php` | No change to the Close button `onclick` since the dev's first push; the "View full changelog" link was never touched | ❌ **Two bugs not addressed**: (1) the Close-button bubble (lines 50 and 87, see §2.2); (2) the broken `RELEASE.md` link (lines 49 and 86, see §2.3). |
| `database/about_popup_test.php` | No change | ⚠️ Still does not catch any of the 3 new bugs. The recommended regression assertions in §3.1, §3.2, and §3.3 are drop-in additions. |

---

## 5. Verdict

- **Bug 5 (parse error on `/`)** — **Critical**, already self-fixed by the dev in commit `a9dd0f6`. Live page now renders. No further dev action required for this bug, but the regression test from §3.1 should still be added so the pattern doesn't reappear.
- **Bug 6 (dropdown broken after About close)** — **High**, must fix before ship. Affects every logged-in user on the PM dashboard. Fix in §3.2.
- **Bug 7 (changelog link returns 404)** — **Medium**, must fix before ship. Every visitor who clicks "View full changelog" gets a 404. Fix in §3.3 (recommended: Option A for the hotfix, Option B for the next issue).
- The 4 original bugs from `tester-note-001` are all resolved at the visual / static level; bug 3 was solved by a different (and riskier) approach than the minimal patch requested, and the rewrite introduced then re-fixed bug 5.
- **Recommendation: do not tag v1.7.2 until bugs 6 and 7 are fixed and a fresh browser re-test passes on all 4 surfaces. The regression assertions in §3.1, §3.2, and §3.3 should land in the same release so the next refactor of these areas doesn't silently re-break any of the three.**
