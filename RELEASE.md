# Release Notes

## v1.7.3.1 — 2026-10-17

**Hotfix for the v1.7.3 `Executing inline script violates CSP` console error on the public landing `/`.** Closes [issue-032](docs/issues/issue-032.md): the v1.7.3 favicon ship (the same release that fixed the `GET /favicon.ico 404` console message) introduced a new console error on the public landing — the browser was logging

```
(index):61 Executing inline script violates the following Content Security Policy
directive 'script-src 'self' https://cdnjs.cloudflare.com
https://static.cloudflareinsights.com'. Either the 'unsafe-inline' keyword, a
hash ('sha256-…'), or a nonce ('nonce-…') is required to enable inline execution.
The action has been blocked.
```

at line 61 of the rendered HTML on every visit to `/`. The cause is that `index.php` (the public landing) had an inline `<script>(function () { … })();</script>` block (the share-code auto-format IIFE — auto-uppercase + auto-insert dashes while the user types a 5-5-5 share code), but `/` is served with the **client-portal** CSP (`script-src 'self' https://cdnjs.cloudflare.com https://static.cloudflareinsights.com` — no `'unsafe-inline'`). The issue-020 invariant requires the client-portal CSP to **not** contain `'unsafe-inline'` in `script-src` (this is what makes the public client portal resistant to XSS via injected inline scripts), so the fix is **not** to relax the CSP — it is to externalise the inline script. The fix follows the exact pattern set by issue-027 (which extracted the same kind of inline controller block from `client/dashboard.php`):

- A new external file `assets/js/share-code-format.js` is added to the repo. The file contains the same share-code auto-format IIFE that was previously inlined — the logic is unchanged (uppercase the input, strip non-alphanumeric, cap at 15 chars, insert dashes at positions 5 and 10). The file is committed to git (not gitignored) and is served by nginx under the existing `script-src 'self'` directive — no CSP change is needed.
- The inline `<script>` block at the end of `index.php` (the one between `</main>` and `</body>`) is replaced with `<script src="assets/js/share-code-format.js" defer></script>`. The `defer` attribute is added so the script does not block the parser and runs after the DOM is built (the inline block previously ran synchronously, so the `defer` is a behavioural equivalent for the auto-format use case — the `input` event fires after the user starts typing, by which time the DOM is fully built and the `defer` script has registered its `input` listener).
- A new check #9 in `database/probe_diag_test.php` asserts: (a) `index.php` has no inline `<script>(function` IIFE pattern (the v1.7.3 shape we extracted), and (b) `index.php` contains at least one `<script src="assets/js/…">` tag (the dev may rename the file later, so the check does not pin the exact filename — it just asserts the externalised loader is in place). Catches a future dev who adds a new inline `<script>` to `index.php` (a tempting optimisation for "small scripts" that has now broken the page twice in 2 releases), or who reverts the v1.7.3.1 externalise edit. The check is source-grep on the `index.php` source, runs in <1 ms.

No schema, no public-API change, no JS / CSS change in any file other than the new `assets/js/share-code-format.js` and the 1-line edit in `index.php`. The 5 v1.7.2.2-shipped md5sums in `_diag.php` are re-bumped for 2 of the 5 files (the inline-script externalise changes the md5 of `index.php`, and the `APP_VERSION` v1.7.3 → v1.7.3.1 bump changes the md5 of the local `config.php` again).

### Files changed

- `assets/js/share-code-format.js` — **new file**, ~20 lines, contains the same share-code auto-format IIFE that was previously inlined at the bottom of `index.php`. Loaded via `<script src="…">` from the public landing. The file is committed to git (not gitignored) and is served by nginx at `/assets/js/share-code-format.js` (200 OK, no auth).
- `index.php` — replaces the 17-line inline `<script>(function () { … })();</script>` block (between `</main>` and `</body>`) with a 1-line `<script src="assets/js/share-code-format.js" defer></script>` reference. The `defer` attribute makes the load non-blocking and guarantees the script runs after the DOM is built (the original inline block ran synchronously, so this is a behavioural equivalent for the `input` listener use case). No other edit in `index.php`.
- `config.php` and `config.template.php` — bump `APP_VERSION` from `'v1.7.3'` to `'v1.7.3.1'`. Both files stay byte-identical, asserted by the layer-1 drift guard.
- `install.php` — bump `$current_version` from `'v1.7.3'` to `'v1.7.3.1'` (a second edit, in addition to the v1.7.3 favicon link above). The writer line that emits `var_export($current_version, true)` is unchanged.
- `database/about_popup_test.php` — bumps the `define('APP_VERSION', 'v1.7.3')` test fixture and the corresponding helper-output assertion from `'v1.7.3'` to `'v1.7.3.1'`. The drift-guard in layer 1 is updated to the new version string everywhere it appears.
- `database/install_wizard_test.php` — bumps the 14th expected constant from `'APP_VERSION' => "'v1.7.3'"` to `'APP_VERSION' => "'v1.7.3.1'"` and updates the matching parsed-config assertion.
- `database/probe_diag_test.php` — adds 1 new check (#9) that asserts `index.php` has no inline `<script>(function …)</script>` IIFE pattern AND contains at least one `<script src="assets/js/…">` reference. The check is source-grep on the `index.php` source, runs in <1 ms. The docblock at the top of the file is updated to list the new check. The existing checks (#1 through #8) are unchanged. The success message at the end is updated to mention "issue-032 v1.7.3.1 inline script externalised".
- `_diag.php` — re-bumps 2 of the 5 expected md5s: `config.php` (because the `APP_VERSION` literal changed inside the file) and `index.php` (because the inline-script externalise changed the file content). The other 3 entries (`client/dashboard.php`, `client/lang.php`, `includes/functions.php`) keep their v1.7.3 / v1.7.2.2 md5s because v1.7.3.1 did not touch them. The comment block above the `$expected_md5` array is updated to mention the v1.7.3.1 re-bump.
- `README.md` — updates the "Latest release" banner to v1.7.3.1 with a one-paragraph summary of the inline-script externalise hotfix. The v1.7.3 favicon paragraph and the v1.7.2.3 / v1.7.2.2 paragraphs are preserved as historical entries below.

### Why externalise and not add `'unsafe-inline'` to the client CSP

Two alternatives were considered and rejected:

1. **Add `'unsafe-inline'` to the client-portal `script-src`.** This is the trivial fix (1 character in `includes/security_headers.php`) but it would re-introduce the XSS surface that the issue-020 invariant specifically forbids. The whole point of the no-`'unsafe-inline'` rule on the client portal is that an attacker who can inject HTML into a public page (e.g. via the share-code input) cannot also inject an inline `<script>` to run arbitrary JS in the visitor's session. The internal app keeps `'unsafe-inline'` because the internal app has inline `onclick=` handlers in 9 pages and a nonce-based refactor is a v2.0 item (per the comment in `database/security_headers_test.php`); the client portal has no such legacy and must stay strict.
2. **Use a CSP hash for the inline script.** The error message itself offers this option: "a hash ('sha256-jX2av0cah+pT9Mgh7LEOctlfgLqp3FLbtrKAbudpdPE=') is required to enable inline execution." A `script-src 'self' https://cdnjs.cloudflare.com https://static.cloudflareinsights.com 'sha256-jX2av0cah+pT9Mgh7LEOctlfgLqp3FLbtrKAbudpdPE='` directive would work, but it has 2 drawbacks: (a) the hash is content-derived, so a future dev who edits the inline script (even a 1-character comment change) must re-bump the hash in the CSP — this is exactly the kind of "I changed a comment and the prod went red" surprise the project tries to avoid; (b) it still requires a source change to `includes/security_headers.php` and a matching test in `database/security_headers_test.php`, which is more surface area than the 1-line externalise edit.

The chosen fix (move the script to an external file, load it via `<script src="…">`) is the durable, refactor-friendly, and security-preserving solution — the same pattern set by issue-027 for `client/dashboard.php` and the only one consistent with the issue-020 invariant.

### Upgrade notes

- Pull the latest code, then re-upload **3 files** to prod: `index.php` (with the new `<script src="…">` line), the new `assets/js/share-code-format.js` (~20 lines, new file), and the updated `database/probe_diag_test.php` is **dev-side only** (no need to upload to prod). The 3 version-bump files (`config.php`, `config.template.php`, `install.php`'s `$current_version` line) are gitignored-only-on-prod (the prod `config.php` is local) — bump the prod `config.php` to `v1.7.3.1` after the upload.
- Hard-reload `https://project.lorwongam.com/` in a private browser window. Open the browser console. The previous `Executing inline script violates the following Content Security Policy directive 'script-src 'self' …` line should be gone — there should be no CSP violation at all on the landing page. The share-code input should still auto-uppercase and auto-insert dashes while typing (the script is now loaded from `/assets/js/share-code-format.js` and runs the same logic).
- From the prod shell: `php /home/u162912405/public_html/_diag.php`. Confirm the existing 5 md5s (now re-bumped to v1.7.3.1) still show as OK, the "Landing page (`/`)" sub-section shows 4 green ✓ sub-checks, and the overall banner is "ALL CHECKS PASSED" (green).
- The 3 dev-side regression tests that touch the version string (`database/about_popup_test.php`, `database/install_wizard_test.php`, `database/probe_diag_test.php`) all pass on the v1.7.3.1 code; no manual fix-up is needed beyond running the test suite once.
- No new release-process step is needed. v1.7.3.1 is a 1-line HTML change in `index.php` + a new external JS file, well within the "edit-and-ship" category that step 4 of `docs/RELEASE_PROCESS.md` already covers.

## v1.7.3 — 2026-10-17

**Project favicon — eliminate the `/favicon.ico 404` in the browser console.** Closes [issue-031](docs/issues/issue-031.md): every page in the project (the public landing `index.php`, the owner `login.php` / `dashboard.php` / `projects.php` / etc. that include `includes/header.php`, the install wizard `install.php`, and the 3 styled pages of `client/dashboard.php`) was logging `GET /favicon.ico 404 (Not Found)` in the browser console because no `<link rel="icon">` tag was declared in any `<head>`. Browsers, when they see no icon declaration, fall back to a default `GET /favicon.ico` against the domain root. There was no `/favicon.ico` in the repo and no `RewriteRule` to a real icon, so the request 404'd. The 404 is cosmetic (the page still renders correctly), but it spams the console on every navigation and confuses any future error-monitoring tool that watches console output. The fix is the standard `<link rel="icon" type="image/png" href="…">` tag in every `<head>` that ships HTML to the browser, pointing to the new asset `assets/image/project-favicon.png` (a 41 KB PNG, user-supplied).

- A new asset `assets/image/project-favicon.png` is added to the repo. The file is in a new `assets/image/` directory (the `assets/` tree previously contained only `css/` and `js/`). The file is committed to git (not gitignored) and is served by both the internal app and the client portal under the existing `img-src 'self' data:` CSP — no CSP change is needed.
- `<link rel="icon" type="image/png" href="assets/image/project-favicon.png">` is added to every root-level `<head>`: `includes/header.php` (which is included by every internal-app page), `index.php` (the public landing), and `install.php` (the install wizard). The link tag is placed immediately after the `<title>` line and before the stylesheet link, so the browser sees the icon declaration before it starts requesting any other asset.
- The 3 styled `<head>` sections in `client/dashboard.php` (the 429 page, the empty-code landing page, and the normal project dashboard page) all get the same link tag with the href rewritten to `../assets/image/project-favicon.png` because `client/dashboard.php` is served from `/client/` and the asset is at the repo root. The 1 raw `<head>` in `client/dashboard.php` (the `headers_sent()` meta-refresh fallback for the empty-code redirect) is intentionally **not** updated: the meta-refresh fires within 0 seconds and the browser loads `index.php`, which has the favicon link, so the user never sees the raw head in a steady state.
- 2 new assertions in `database/probe_diag_test.php` (checks #7 and #8): check #7 verifies the exact link tag string is present in each of the 4 files that ship a `<head>` (with the right path for each file's serving depth), and check #8 verifies the favicon PNG itself is on disk and not an empty/truncated file. Both checks are source-grep on the file contents (no runtime fetch) so they run in <1 ms.

No schema, no public-API change, no JS / CSS change. The 5 v1.7.2.2-shipped md5sums in `_diag.php` are re-bumped to v1.7.3 (the favicon edit changes the md5 of `includes/header.php` and `client/dashboard.php`).

### Files changed

- `assets/image/project-favicon.png` — **new file**, 41 KB PNG, user-supplied. The first asset in the new `assets/image/` directory (which previously did not exist in the repo). Committed to git so a fresh clone of the repo includes the favicon — without it, the new `<link rel="icon">` tag would itself 404.
- `includes/header.php` — adds `<link rel="icon" type="image/png" href="assets/image/project-favicon.png">` immediately after the `<title>` line. Because every internal-app page (`login.php`, `dashboard.php`, `projects.php`, `tasks.php`, `project_*.php`, `task_*.php`, `contacts.php`, `contact_*.php`, `profile.php`, `forgot_password.php`, `reset_password.php`, `register.php`, `logout.php`, and the AJAX handlers are no-header) goes through `includes/header.php`, a single edit covers the entire internal app. The href is root-relative because `header.php` is included from root-level files.
- `index.php` — adds the same link tag (root-relative href) immediately after the `<title>` line. `index.php` does not include `includes/header.php` (it is the public landing page, designed to be reachable without an auth-context), so it needs its own copy of the link tag.
- `install.php` — adds the same link tag (root-relative href) immediately after the `<title>` line. `install.php` also does not include `includes/header.php` (it is the install wizard, which renders before the project is set up). Even though `install.php` is meant to be deleted after install, it ships in the repo and a fresh install does exercise the page, so the favicon link is included.
- `client/dashboard.php` — adds the same link tag with the href rewritten to `../assets/image/project-favicon.png` (one level up, because `client/dashboard.php` is served from `/client/`) in all 3 styled `<head>` sections: the 429 rate-limit page, the empty-code landing page, and the normal project dashboard page. The 1 raw `<head>` in the `headers_sent()` meta-refresh fallback is intentionally left without the link tag (the meta-refresh fires within 0 seconds and the browser follows it to `index.php`, which has the favicon link).
- `config.php` and `config.template.php` — bump `APP_VERSION` from `'v1.7.2.3'` to `'v1.7.3'`. Both files stay byte-identical, asserted by the layer-1 drift guard.
- `install.php` — bump `$current_version` from `'v1.7.2.3'` to `'v1.7.3'` (a second edit, in addition to the favicon link above). The writer line that emits `var_export($current_version, true)` is unchanged.
- `database/about_popup_test.php` — bumps the `define('APP_VERSION', 'v1.7.2.3')` test fixture and the corresponding helper-output assertion from `'v1.7.2.3'` to `'v1.7.3'`. The drift-guard in layer 1 is updated to the new version string everywhere it appears.
- `database/install_wizard_test.php` — bumps the 14th expected constant from `'APP_VERSION' => "'v1.7.2.3'"` to `'APP_VERSION' => "'v1.7.3'"` and updates the matching parsed-config assertion.
- `database/probe_diag_test.php` — adds 2 new checks (#7 and #8). Check #7 iterates the 4 files that ship a `<head>` and asserts the exact `<link rel="icon" type="image/png" href="…">` tag string is present in each, with the file-specific href (root-relative for `includes/header.php`, `index.php`, `install.php`; `../assets/…` for `client/dashboard.php`). Check #8 asserts `assets/image/project-favicon.png` is on disk and not empty (≥100 bytes). Both checks are source-grep, run in <1 ms, and the docblock at the top of the file is updated to list the new checks. The existing checks (#1 through #6) are unchanged.
- `README.md` — updates the "Latest release" banner to v1.7.3 with a one-paragraph summary of the favicon fix (replaces the v1.7.2.3 summary) so a casual reader sees this is the v1.7.3 favicon ship, not a separate prod hotfix. The v1.7.2.3 and v1.7.2.2 paragraphs are preserved as historical entries below.

### Why `<link rel="icon">` and not a rewrite

Two alternatives were considered and rejected:

1. **`.htaccess` rewrite of `/favicon.ico` → `/assets/image/project-favicon.png`.** A `RewriteRule ^favicon\.ico$ /assets/image/project-favicon.png [L]` would suppress the 404 but is a workaround that the user has to set up correctly. Worse, it does not teach the browser about the icon (the browser still treats it as a generic ICO), so the tab icon and bookmark icon would not show the PNG. The 1-line `<link rel="icon">` is the standard HTML5 fix and teaches the browser about the icon asset explicitly.
2. **Drop a `favicon.ico` at the repo root.** A 16×16 .ico would be the legacy fix (browsers in IE / very old Safari fall back to `/favicon.ico` if no `<link rel="icon">` is present), but modern browsers all respect `<link rel="icon">` and ignore the legacy `/favicon.ico` lookup when a link tag is present. A `.ico` would also be lower-resolution than the user-supplied PNG, so the PNG is the better asset to ship.

The chosen fix (PNG + `<link rel="icon" type="image/png">` in every head) is the modern, standards-compliant, and asset-quality-preserving solution.

### Upgrade notes

- Pull the latest code, then re-upload **5 files** to prod: `includes/header.php`, `index.php`, `install.php`, `client/dashboard.php` (all 4 with the new `<link rel="icon">` tag), and the new `assets/image/project-favicon.png` (and the new `assets/image/` directory if uploading via FTP — most FTP clients create the directory automatically). The 3 version-bump files (`config.php`, `config.template.php`, `install.php`'s `$current_version` line) are gitignored-only-on-prod (the prod `config.php` is local) — bump the prod `config.php` to `v1.7.3` after the upload.
- Hard-reload `https://project.lorwongam.com/` in a private browser window. Open the browser console. The previous `GET /favicon.ico 404 (Not Found)` line should be gone, replaced by a successful `GET /assets/image/project-favicon.png 200` (the browser follows the new link tag). The tab icon and any bookmark icon should now show the new favicon.
- From the prod shell: `php /home/u162912405/public_html/_diag.php`. Confirm the existing 5 md5s (now re-bumped to v1.7.3) still show as OK, the "Landing page (`/`)" sub-section shows 4 green ✓ sub-checks, and the overall banner is "ALL CHECKS PASSED" (green).
- The 3 dev-side regression tests that touch the version string (`database/about_popup_test.php`, `database/install_wizard_test.php`, `database/probe_diag_test.php`) all pass on the v1.7.3 code; no manual fix-up is needed beyond running the test suite once.
- No new release-process step is needed. v1.7.3 is a 1-line HTML change in each of 4 files, plus an asset — it is well within the "edit-and-ship" category that step 4 of `docs/RELEASE_PROCESS.md` already covers.

## v1.7.2.3 — 2026-10-17

**Prod hotfix for the v1.7.2.2 silent redirect of `/` to `/login.php` (cPanel hPanel).** Closes [issue-030](docs/issues/issue-030.md): after the v1.7.2.2 ship to `https://project.lorwongam.com` (Cloudflare-fronted shared hosting, cPanel/Hostinger), hitting the domain root `https://project.lorwongam.com/` silently 301-redirects to `https://project.lorwongam.com/login.php` instead of rendering the public landing form (`index.php` with the v1.7.2 about popup, the EN/zh lang switch, and the "Owner sign in" link). The cause is **not** in the code or in the repo `.htaccess` — it is a cPanel hPanel setting on the `project.lorwongam.com` subdomain (either a per-subdomain Redirects UI rule with `Source = /` → `Destination = /login.php`, **or** an Index Manager override that puts `login.php` ahead of `index.php` in the default index list). cPanel's per-vhost config runs **before** `.htaccess` at the `mod_dir` / `mod_alias` layer, so no `.htaccess` directive can override it. The actual fix is **one toggle in the prod host's hPanel UI** (hPanel → Websites → `project.lorwongam.com` → Manage → Redirects, delete the rule; hPanel → Files → Directory Privacy or Advanced → Index Manager, add `index.php` to the list). The code-side changes are defensive and durable:

- A new 1-line `DirectoryIndex index.php index.html` in `.htaccess` so a future hosting migration (away from cPanel) does not re-introduce the same bug.
- A new live-URL probe in `_diag.php` — the "Landing page (`/`)" sub-section (§3.5) does `curl https://project.lorwongam.com/` and asserts: (1) HTTP 200, (2) the `Location` header is not `/login.php`, (3) the body contains the public landing form (`name="code"`), (4) the body does **not** contain the owner login form (`name="username"`). If the assertion fails, the diag prints an actionable hPanel fix message and increments the failure count (the "ALL CHECKS PASSED" / "N CHECKS FAILED" banner at the top of the page turns red). The probe is gated by a `DIAG_SKIP_LIVE=1` env var so a dev running the diag locally on a non-prod box does not get a false-positive failure from a network that cannot reach the public URL; the gate is documented in the file's docblock.
- 2 new assertions in `database/probe_diag_test.php` (`diagHasLandingProbe` and `diagRejectsLoginRedirect`) catch a future dev who removes the new probe or weakens its assertion. The existing read-only invariants (no DB, no SMTP, no `<?php` inside a `//` line comment) are preserved; the v1.7.2.2 "no HTTP" rule is intentionally relaxed in v1.7.2.3 because the landing-page probe legitimately does outbound HTTP via `curl_init()` against the public `$public_base` constant.
- A new "step 5.6 — cPanel hPanel sanity check" in `docs/RELEASE_PROCESS.md` that requires the dev to verify the hPanel Redirects and Index Manager settings on every prod deploy, hard-reload `/` in a private browser window, and paste screenshots into the GitHub release notes as the durable audit trail.

No schema, no public-API change, no JS / CSS change. The 5 v1.7.2.2-shipped md5sums in `_diag.php` are unchanged (v1.7.2.3 did not touch any of the 5 source files that the probe hashes — only `.htaccess`, `_diag.php` itself, and `database/probe_diag_test.php`).

### Files changed

- `.htaccess` — 1-line `DirectoryIndex index.php index.html` added before the existing `RewriteEngine On`. Defensive only; the real fix is the hPanel UI change documented in the new step 5.6 of the release process. The line is commented with the issue-030 rationale so a future dev who wants to remove it understands the hosting-migration context.
- `_diag.php` — new "Landing page (`/`)" sub-section (§3.5) between the existing "About-popup lang keys (v1.7.2)" and "Expected Content-Security-Policy" sections. The section is built by a new `probe_row($label, $result, $failed, $help = null)` helper that renders one `<tr>` with an optional help-text `<tr>`. The `$public_base` constant is the only outbound-HTTP target and is overridable from a dev-side test wrapper (the helper ships with the default `https://project.lorwongam.com`). The probe is gated by `getenv('DIAG_SKIP_LIVE') === '1'` for offline development. The 4 sub-checks use `name="code"` (landing form indicator) and `name="username"` (login form indicator) as the body markers — these are stable across releases, unlike the form `action=""` which is a no-op. The overall banner is now computed at the end of the file via `ob_start()` + `ob_get_clean()` + a `__DIAG_BANNER__` placeholder swap, so a probe failure in §3.5 correctly turns the top banner red (the previous "compute `$banner` early, render it inline" pattern would have rendered a stale green banner if the §3.5 probe incremented `$failed` after the banner was already drawn).
- `database/probe_diag_test.php` — 2 new `ok()` assertions in the source-grep section: `diagHasLandingProbe` (asserts all 4 "Sub-check N: …" strings are present in `_diag.php`) and `diagRejectsLoginRedirect` (asserts the probe rejects a response that is a redirect to `/login.php` AND asserts the probe looks for the owner login form indicator `name="username"` in the body). The existing read-only invariants (no `<?php` inside a `//` line comment, no DB / SMTP side-effect calls) are preserved; the "no HTTP" check from v1.7.2.2 is removed because the v1.7.2.3 probe legitimately does outbound HTTP. Check #4 (the `//` line comment check) was refined from a blanket "no `<?php` after line 1" to a per-line regex `'#//.*<\?php#'` so it no longer false-positives on legitimate procedural-PHP code that mixes PHP and HTML with inline `<?php ... ?>` blocks.
- `docs/RELEASE_PROCESS.md` — new "step 5.6 — cPanel hPanel sanity check (issue-030 v1.7.2.3)" inserted between the existing step 5.5 (prod sanity probe) and step 6 (smoke-test the About popup). The step requires the dev to: (1) verify hPanel → Websites → `project.lorwongam.com` → Manage → Redirects has no `Source = /` → `Destination contains login.php` rule (and delete one if found); (2) verify hPanel → Files → Directory Privacy (or Advanced → Index Manager) for `public_html/` has `index.php` in the default index list (and add it if missing); (3) hard-reload `/` in a private browser window and confirm the public landing form (not the owner login form) renders; (4) paste hPanel screenshots into the GitHub release notes. A "What this catches" sub-section explains the v1.7.2.2 prod root cause and why the dev-side test in step 5 cannot catch a vhost-level redirect on its own.
- `config.php` and `config.template.php` — bump `APP_VERSION` from `'v1.7.2.2'` to `'v1.7.2.3'`. Both files stay byte-identical, asserted by the layer-1 drift guard.
- `install.php` — bump `$current_version` from `'v1.7.2.2'` to `'v1.7.2.3'`. The writer line that emits `var_export($current_version, true)` is unchanged.
- `README.md` — updates the "Latest release" banner to v1.7.2.3 with a one-paragraph summary of the prod hotfix (replaces the v1.7.2.2 summary) so a casual reader sees this is the v1.7.2.3 prod fix, not a separate feature release. The v1.7.2.2 paragraph is preserved as a historical entry below.
- `database/about_popup_test.php` — bumps the `define('APP_VERSION', 'v1.7.2.2')` test fixture and the corresponding helper-output assertion from `'v1.7.2.2'` to `'v1.7.2.3'`. The drift-guard in layer 1 is updated to the new version string everywhere it appears.
- `database/install_wizard_test.php` — bumps the 14th expected constant from `'APP_VERSION' => "'v1.7.2.2'"` to `'APP_VERSION' => "'v1.7.2.3'"` and updates the matching parsed-config assertion.

### Why the fix is in the hPanel UI, not the repo

A v1.7.2.2-only deploy with no code change (just the v1.7.2.2 hotfix files) would still hit the bug, because the cPanel hPanel setting was applied at subdomain-creation time (likely a Hostinger onboarding default) and is independent of the repo. The cPanel per-vhost config runs at the `mod_dir` / `mod_alias` layer, **before** the request reaches Apache's `.htaccess` processor, so no `.htaccess` directive — including `DirectoryIndex`, `Redirect`, or `RewriteRule` — can override it. The dev (or user) must remove the hPanel setting in the browser. The new "step 5.6" is the durable prevention: every future release is gated on a hPanel sanity check, so the same class of bug cannot recur without the dev seeing it.

### Upgrade notes

- The user (or dev) opens the Hostinger hPanel for the `lorwongam.com` account (`hpanel.hostinger.com`) and follows the new step 5.6 in `docs/RELEASE_PROCESS.md`: check Redirects, check Directory Privacy / Index Manager, hard-reload `/`, paste screenshots. The hPanel fix is the only step that **must** be done in the browser; the rest is in the repo.
- Pull the latest code, then re-upload **3 files** to prod: `.htaccess` (the new `DirectoryIndex` line), the updated `_diag.php` (with the new §3.5 sub-section), and the updated `database/probe_diag_test.php` is **dev-side only** (no need to upload to prod). The 5 files in the v1.7.2.2 md5 list (`config.php`, `client/dashboard.php`, `client/lang.php`, `includes/header.php`, `includes/functions.php`) are unchanged, so the prod sanity probe (step 5.5) will continue to show all 5 md5s as OK without a re-upload.
- From the prod shell: `php /home/u162912405/public_html/_diag.php`. Confirm the new "Landing page (`/`)" sub-section shows 4 green ✓ sub-checks and the overall banner is "ALL CHECKS PASSED" (green).
- Hard-reload `https://project.lorwongam.com/` in a private browser window. The browser should now show the public landing form (the code-entry field, the "Owner sign in" link, the about popup). The URL bar should stay at `/` (no redirect to `/login.php`).
- Hard-reload `https://project.lorwongam.com/login.php` directly. The browser should still show the owner login form (the `/` redirect was a bug; the `/login.php` page itself is correct and must continue to work).
- If the new diag sub-section shows a red ✗ on any of the 4 sub-checks: sub-check 1 (HTTP 200) failing usually means the hPanel Redirects rule is still in place — re-check step 2a of issue-030 §6.1. Sub-checks 2 / 3 / 4 failing usually mean the Index Manager still has `login.php` in the default index list — re-check step 2b. The diag prints the captured body (first 2000 chars) on each sub-check failure, which is the fastest way to see what the prod is actually serving.

## v1.7.2.2 — 2026-10-17

**Prod hotfix for the v1.7.2 500 on `client/dashboard.php` + Cloudflare Web Analytics beacon CSP warning.** Closes [issue-029](docs/issues/issue-029.md): after the v1.7.2 ship to `https://project.lorwongam.com` (Cloudflare-fronted shared hosting, Apache mod_php or PHP-FPM under a non-standard path), the public client portal at `client/dashboard.php?code=…&lang=en` started returning **HTTP 500** in the browser, and the Cloudflare Web Analytics beacon (`https://static.cloudflareinsights.com/beacon.min.js/…`, auto-injected at the edge when the zone has Web Analytics enabled) was producing a **CSP violation** in the browser console on every page of both the internal app and the client portal. The 500 is fixed defensively in `client/dashboard.php` (regardless of the actual root cause found via the new diag probe): an `ob_start()` at the top of the file, a `headers_sent()` guard around the empty-code `header('Location: index.php')` redirect (with a meta-refresh fallback), a re-ordering of the `client/lang.php` `require_once` to be ABOVE the `sendClientPortalSecurityHeaders()` call (so the `t()` helper is defined before any future `t()` call during header rendering), and a one-line `error_log('[v1.7.2.2 deploy] …')` marker so the dev can confirm the new file actually ran (vs an opcache-served old version) by grepping the prod error log. The CSP violation is fixed by adding `https://static.cloudflareinsights.com` to the `script-src` directive in **both** the internal-app and client-portal CSPs in `includes/security_headers.php` (Path B in the spec — the project does not use Cloudflare Web Analytics today so Path A "disable Web Analytics on the zone" is a dashboard-only change that the dev may flip independently). The same edit is mirrored in the regression test `database/security_headers_test.php` so a future dev who removes the new host is caught at test time. A new read-only prod sanity probe at `_diag.php` (deployed to `/home/u162912405/public_html/_diag.php`, blocked from public HTTP by the new `.htaccess` `<FilesMatch "^_diag\.php$"> Require all denied </FilesMatch>` rule) lets the dev compare the md5sum of the 5 v1.7.2.2-touched files on prod against the v1.7.2.2-shipped versions, confirm the 6 `about.*` lang keys are present in both `en` and `zh` catalogs, and see the actual CSP the app is sending. The probe is the durable fix for the "partial deploy" class of bug that caused the v1.7.2 500 in the first place: the new `database/probe_diag_test.php` test asserts the 5 expected md5 placeholders are filled in at release time and that the probe has no DB / SMTP / HTTP side effects. A new "step 5.5 — Prod sanity probe" in `docs/RELEASE_PROCESS.md` makes running the probe a mandatory post-deploy gate. No schema, no public-API change.

### Files changed

- `client/dashboard.php` — defensive `ob_start()` after `sendClientPortalSecurityHeaders()`, `require_once __DIR__ . '/lang.php';` reordered to be ABOVE the headers emission (so `t()` is defined before any future header-rendering `t()` call), one-line `error_log('[v1.7.2.2 deploy] client/dashboard.php loaded from ' . __FILE__);` marker right after the `ob_start()` (so the dev can grep the prod error log to confirm the new file actually ran), and a `headers_sent()` guard around the empty-code `header('Location: index.php')` redirect with a meta-refresh fallback (so a stray echo or whitespace before this point can no longer turn the page into a 500).
- `includes/security_headers.php` — adds `https://static.cloudflareinsights.com` to `script-src` in **both** the internal-app `sendSecurityHeaders()` (now `script-src 'self' 'unsafe-inline' https://cdnjs.cloudflare.com https://static.cloudflareinsights.com`) and the client-portal `sendClientPortalSecurityHeaders()` (now `script-src 'self' https://cdnjs.cloudflare.com https://static.cloudflareinsights.com`). The `'unsafe-inline'` drop on the client portal (issue-020 invariant) is preserved. Both new hosts are explicitly commented in the source so a future dev knows what they are for.
- `database/security_headers_test.php` — new assertion (Path B) that **both** helpers' `script-src` contains `https://static.cloudflareinsights.com`. The existing `strpos` / `preg_match` checks for `'unsafe-inline'` and `default-src 'self'` are unchanged.
- `.htaccess` — new 3-line `<FilesMatch "^_diag\.php$"> Require all denied </FilesMatch>` block, added to the same FilesMatch group that already denies `config*`, `composer*`, `package*`. The dev can still reach the probe from a browser by SSHing in (the `Require all denied` only blocks public-facing Apache contexts) and from the shell by `php /home/u162912405/public_html/_diag.php`.
- `_diag.php` — **new file** at the repo root (~165 lines, deployable as-is to `/home/u162912405/public_html/_diag.php`). Read-only, no DB calls, no SMTP calls, no HTTP calls. Prints: PHP runtime (version, SAPI, `memory_limit`, `error_log` path), opcache status (`opcache.enable`, `opcache.revalidate_freq`), md5 + size + mtime of the 5 v1.7.2.2-touched files (with a STATUS column that flags MISMATCH), about-popup `about.*` key presence in `en` + `zh` catalogs, the actual CSP the app sends (internal + client portal), and an "ALL CHECKS PASSED" / "N CHECKS FAILED" banner at the top. The 5 expected-md5 placeholders are filled in with the v1.7.2.2-shipped md5sums and will be re-bumped at every future release per the new step 5.5 in the release process.
- `database/probe_diag_test.php` — **new file** (~80 lines, dev-side). Asserts: `_diag.php` exists, no `FILL_IN_AT_RELEASE_TIME` literal remains anywhere in the file, the 5 md5 values in the `$expected_md5` array match the actual md5sums of the 5 source files in the repo HEAD, the file has no `<?php` open tag after line 1 (mirroring the index.php bug from issue-028), the file contains no DB / SMTP / HTTP side-effect calls (`$db->`, `Database::`, `$mailer`, `SMTP_`, `curl_init`, `file_get_contents('http`). The read-only invariant is enforced.
- `docs/RELEASE_PROCESS.md` — new "step 5.5 — Prod sanity probe (issue-029)" between step 5 (run the drift-guard test) and step 6 (smoke-test the popup in the browser). The step requires the dev to SSH into the prod box and run `php /home/u162912405/public_html/_diag.php` after every prod deploy, confirm the "ALL CHECKS PASSED" banner, then from a public connection `curl -I https://<host>/_diag.php` and confirm **403** (the `.htaccess` deny rule is in place). A "What this catches" sub-section explains the v1.7.2 partial-deploy root cause and how the probe prevents the same class of bug from coming back.
- `config.php` and `config.template.php` — bump `APP_VERSION` from `'v1.7.2.1'` to `'v1.7.2.2'`. Both files stay byte-identical, asserted by the layer-1 drift guard.
- `install.php` — bump `$current_version` from `'v1.7.2.1'` to `'v1.7.2.2'`. The writer line that emits `var_export($current_version, true)` is unchanged.
- `README.md` — updates the "Latest release" banner to v1.7.2.2 with a one-paragraph summary of the prod hotfix (replaces the v1.7.2.1 summary) so a casual reader sees this is the v1.7.2 prod fix, not a separate feature release.
- `database/about_popup_test.php` — bumps the `define('APP_VERSION', 'v1.7.2.1')` test fixture and the corresponding helper-output assertion from `'v1.7.2.1'` to `'v1.7.2.2'`. The drift-guard in layer 1 is updated to the new version string everywhere it appears.
- `database/install_wizard_test.php` — bumps the 14th expected constant from `'APP_VERSION' => "'v1.7.2.1'"` to `'APP_VERSION' => "'v1.7.2.2'"` and updates the matching parsed-config assertion.

### Why both Path A and Path B

The spec recommends Path A (disable Cloudflare Web Analytics on the `lorwongam.com` zone — a 30-second Cloudflare dashboard click). Path B (allow the beacon host in `script-src` — a 2-line code change) is implemented as a **belt-and-braces** measure so the CSP does not break if a future operator re-enables Web Analytics. The dev may now flip Path A from the Cloudflare dashboard at any time without needing another code release; the CSP will still be valid because Path B is in place. Both fixes are independent: Path A turns off the injection, Path B allows it. Having both means the browser console stays clean regardless of the Cloudflare zone config.

### Upgrade notes

- Pull the latest code, then re-upload **6 files** to prod: `client/dashboard.php`, `includes/security_headers.php`, `.htaccess` (the `<FilesMatch>` addition), and the **new** `_diag.php` + the updated `config.php` + `includes/header.php` + `includes/functions.php` (if md5s in the new diag mismatched those).
- From the prod shell: `php /home/u162912405/public_html/_diag.php`. Confirm the "ALL CHECKS PASSED" banner is at the top.
- From a public internet connection: `curl -I https://project.lorwongam.com/_diag.php` — expect **403** (the `.htaccess` deny rule is in place).
- Hard-reload `https://project.lorwongam.com/login.php` in a browser. DevTools console should be clean (no CSP violation for the Cloudflare beacon). Repeat on `https://project.lorwongam.com/client/dashboard.php?code=…&lang=en`. Both pages should return **200**.
- `tail` the prod PHP error log (the path is printed in `_diag.php` row 4). The line `[v1.7.2.2 deploy] client/dashboard.php loaded from /home/u162912405/public_html/client/dashboard.php` should be present.
- If a diag row shows `MISMATCH` or `NOT readable`, re-upload the file. If the `about.*` lang keys are missing, re-upload `client/lang.php`. If the diag banner says "PLACEHOLDER" (the `_diag.php` itself still has the `FILL_IN_AT_RELEASE_TIME` literal), re-upload `_diag.php`.

## v1.7.2.1 — 2026-10-17

**Hotfix for the v1.7.2 "?" About popup (tester-note-002, 3 browser bugs).** Closes the regressions in [docs/issues/issue-028/tester-note-002.md](docs/issues/issue-028/tester-note-002.md): bug 5 (parse error from a `//` comment that embedded a PHP open tag — already self-fixed in the v1.7.2 rewrite, this release adds a regression net so the pattern cannot reappear), bug 6 (the Close button click bubbled up to the native `<details>` toggle and immediately re-opened the popup, breaking the user dropdown on every page), and bug 7 (the "View full changelog" link pointed to `/RELEASE.md`, which the issue-020 nginx hardening correctly returns 404 for). The fix for bug 7 is the tester's recommended **Option B**: parse the top entry of `RELEASE.md` server-side and render it inline in the popup (the link is removed entirely, so the nginx block is no longer relevant). The new `getLatestReleaseSummary()` helper in `includes/functions.php` returns `"vX.Y.Z — YYYY-MM-DD — <short title>"`, prefers text inside the first `**...**` pair of the release body, falls back to the first sentence, and falls back further to the bare `APP_VERSION` constant if `RELEASE.md` is missing or unparsable. Both Close buttons (navbar + floating variant) now call `event.stopPropagation()` before closing so the click no longer re-toggles the parent `<details>`. The `//` comment in `index.php` that previously embedded a PHP open tag was rewritten to use prose only; a new source-grep assertion plus a `php -l` linter check catch the pattern. Three new regression nets in section 3c of `database/about_popup_test.php` cover all three bugs (now 38 assertions total). The v1.7.2 entry below is preserved unchanged for history; this hotfix is purely a bug-fix release and does not add new features or change the public API.

### Files changed

- `includes/functions.php` — adds `getLatestReleaseSummary(int $max_summary_chars = 140): string` next to the other version / URL helpers (after `buildClientShareUrl`). Static cache so the file is read at most once per request. Strips leading and trailing `*` from the bold title, truncates to 140 chars with an ellipsis if needed. Falls back to the bare `APP_VERSION` if `RELEASE.md` is missing or unparsable.
- `includes/header.php` — both About popups (navbar lines ~36-52 and floating lines ~68-90) replace the broken `<a href="RELEASE.md">View full changelog →</a>` link with `<p class="app-about-release"><?= e(getLatestReleaseSummary()) ?></p>`, and both Close buttons change from `onclick="this.closest('details').open=false"` to `onclick="event.stopPropagation(); this.closest('details').open=false"` so the click no longer bubbles to the parent `<details>` toggle.
- `index.php` — re-adds the `$code = '';` and `$error = '';` function-scope initializers that the v1.7.2 rewrite inadvertently dropped (the plain-GET branch leaves both variables unset, and the template reads them in the form render — without the initializers, PHP 8.3 emits "Undefined variable" warnings into the response body). The accompanying comment is rewritten in prose only (no PHP open tag inside a `//` line comment, per the new regression net).
- `assets/css/style.css` — adds a `.app-about-panel .app-about-release` block (subtle top-border separator, smaller font, secondary text colour) so the inline release summary reads as "what's new" rather than as a fourth row of metadata.
- `config.php` and `config.template.php` — bump `APP_VERSION` from `'v1.7.2'` to `'v1.7.2.1'`. Both files stay byte-identical, asserted by the layer-1 drift guard.
- `install.php` — bump `$current_version` from `'v1.7.2'` to `'v1.7.2.1'`. The writer line that emits `var_export($current_version, true)` is unchanged.
- `database/about_popup_test.php` — adds section 3c with 9 new assertions covering: no `<?php` inside any `//` line comment in `index.php`, `php -l` exits 0 for `index.php`, both Close buttons in `includes/header.php` have `event.stopPropagation()`, both popups invoke `getLatestReleaseSummary()` (≥ 2 occurrences), no `.md` / `RELEASE.md` / `README.md` href anywhere in `includes/header.php`, and a runtime check that the helper itself returns a non-empty string starting with `APP_VERSION` without literal `**` markdown asterisks. Total assertions: 38 (was 30 after tester-note-001).
- `database/install_wizard_test.php` — bumps the 14th expected constant from `'APP_VERSION' => "'v1.7.2'"` to `'APP_VERSION' => "'v1.7.2.1'"` and updates the matching parsed-config assertion. Layer-2 of the drift guard stays green.
- `README.md` — updates the "Latest release" banner to v1.7.2.1 with a one-line summary of the hotfix so a casual reader sees this is a v1.7.2 follow-up, not a separate feature release.

## v1.7.2 — 2026-10-17

**Application "About" / Version popup + Release-process checklist.** Closes [issue-028](docs/issues/issue-028.md): until now the platform had no in-product way for a user (owner or client) to see what build they were looking at, and no written checklist for bumping the version. v1.7.2 introduces a single source of truth — the new `APP_VERSION` constant in `config.php` and `config.template.php` (kept byte-identical, asserted by the regression test) — and surfaces it through a zero-JS `<details>`/`<summary>` "?" disclosure in the navbar on the internal app, in the public client-portal header, and on the domain-root landing page, plus a floating variant on the logged-out surfaces (login / register / forgot / reset). The disclosure is CSP-safe on the tightened client portal (no inline JS — issue-020 invariant preserved), shows the version, the dev attribution (`Built by: Me` / `Vibe coding with AI agent (MiniMaxM3)`), and a "View full changelog" link on the internal app only (omitted on the public client portal to avoid leaking internal milestones). The `install.php` writer was extended to emit the new constant via `var_export($current_version, true)` (matches the existing writer style from issue-021 §2). A new 6-step release process is documented in [docs/RELEASE_PROCESS.md](docs/RELEASE_PROCESS.md) and cross-referenced from the README and the bottom of this file. A new 4-layer regression test (`database/about_popup_test.php`) enforces the version-drift guard, the install.php writer contract, the markup contract on `includes/header.php`, and the existence of the new release-process doc.

### Files changed

- `config.php` — new `const APP_VERSION = 'v1.7.2';` line between `APP_NAME` and `APP_URL`. Aligned column style.
- `config.template.php` — identical new line, kept byte-identical to `config.php`. Drift between these two files is caught by the layer-1 assertion in the new test.
- `install.php` — hardcoded `$current_version = 'v1.7.2';` (per issue-028 §6.2, not pulled from `RELEASE.md` to keep the writer deterministic) and a new writer line that emits `const APP_VERSION = ` followed by `var_export($current_version, true)`, in the same style as the other constants emitted by the writer.
- `includes/header.php` — adds an `<details class="app-about">` block inside the existing `.nav-menu` (between the Contacts link and the user dropdown) for logged-in users, and a separate `<details class="app-about app-about--floating">` block anchored bottom-right for logged-out visitors (per issue-028 §4.1 the trigger must still be visible on login / register / forgot / reset pages where the navbar is hidden). Uses `e(APP_VERSION)` to escape the version. The internal-app variant includes a "View full changelog" link and a `Close` button wired with the existing `onclick="this.closest('details').open=false"` pattern (CSP allows inline JS on the internal app; the client portal is unaffected).
- `client/dashboard.php` — adds an `<details class="app-about">` block inside `.client-header-right` (before the language switch) for the public client portal. All strings routed through the existing `t('about.*')` localization. The Close button and the changelog link are deliberately omitted on this surface — the client portal CSP forbids inline JS, and the changelog would leak internal milestones.
- `index.php` (domain root landing page) — adds the same client-portal disclosure inside the existing `.client-foot` paragraph so the trigger is reachable from the code-entry form. Uses the foot-anchored CSS variant.
- `client/lang.php` — adds six new keys (`about.trigger_aria`, `about.title`, `about.version`, `about.built_by`, `about.attribution`, `about.foot_note`) to BOTH the `en` and `zh` catalogs. The `attribution` value is the same in both locales per the issue's NG6 non-goal (no extra localization for the dev attribution).
- `assets/css/style.css` — adds a ~60-line `.app-about` block (trigger, panel, floating variant) at the bottom of the navbar section. Trigger is a 32×32 pill that opens a 280 px white panel anchored to the right of the navbar; the floating variant is bottom-right anchored.
- `client/assets/client.css` — adds a ~30-line `.app-about` block scoped to `.client-header-right` and a ~15-line foot-anchored variant for `index.php`. Same visual treatment, sized down to the client header.
- `docs/RELEASE_PROCESS.md` — **new file**. 6-step release checklist (bump constant → changelog → README banner → docs/attribution → run tests → smoke-test popup). Invoked from this file's footer and from the README "About this build" section.
- `RELEASE.md` — this entry plus a "Release process (issue-028)" section at the bottom linking to the new process doc.
- `README.md` — adds an "About this build" section at the bottom linking to the new process doc, so anyone reading the repo top-level knows where the version comes from.
- `database/install_wizard_test.php` — adds the 14th expected constant (`APP_VERSION => "'v1.7.2'"`) and a new source-grep assertion that `install.php` uses `var_export()` for `APP_VERSION` (layer-2 of the new test, surfaced in the existing test so a single install regression surfaces all drift paths at once).
- `database/about_popup_test.php` — **new file**. Four layers: APP_VERSION drift guard across `config.php` / `config.template.php` / `RELEASE.md` top entry / `README.md:3` banner; `install.php` `var_export()` writer; `<details class="app-about">` markup in `includes/header.php` (with `aria-label="About this application"` and the floating variant for logged-out pages); existence of `docs/RELEASE_PROCESS.md` with the 6 step headings.

## v1.7.1.2 — 2026-10-16

**Extract the client-portal sub-tasks toggle into an external JS file to satisfy the issue-020 CSP tightening.** Closes [issue-027](docs/issues/issue-027.md): the per-device "Show all sub-tasks" toggle on `client/dashboard.php` used to ship as an inline `<script>(function () { … })();</script>` block at the end of `<body>`. The v1.7.0 [issue-020](docs/issues/issue-020.md) CSP tightening on the public client portal drops `'unsafe-inline'` from `script-src`, so the browser refused to execute the controller and the toggle silently no-op'd on a fresh page load. v1.7.1.2 moves the controller into a new static ES5-compatible file at `client/assets/js/subtask-toggle.js` and loads it via `<script src="assets/js/subtask-toggle.js" defer></script>`. The translation strings + the per-project share code are now passed into the controller through three new `data-*` attributes on the toggle button (`data-share-code`, `data-label-show-all`, `data-label-show-top`), so the JS file is fully static and has no per-page PHP interpolation. Behaviour is identical: default-collapsed, click flips `data-subtasks` / `data-state` / `aria-pressed` / button label / icon class / `aria-hidden` on nested `ul.task-tree:not(.root)`, persisted to `localStorage['client_dash.subtasks_visible.' + shareCode]` as `"0"` / `"1"`. The :not(.root) scoping and the per-project key isolation are preserved. The root `<ul class="task-tree root">` stays visible either way.

### Files changed

- `client/assets/js/subtask-toggle.js` — **new file**. IIFE wrapper, hand-written ES5-compatible JS (var only, no arrow, no block-scoped declarations, no template literals — matches the style of `assets/js/script.js`). Reads the three new `data-*` attributes off the toggle button, runs the first-paint read before the click handler is attached (so stored preferences are honoured without a flash), wraps every `localStorage` access in try/catch so a sandboxed iframe or Safari private mode does not throw. No `<?= … ?>` inside the file — the controller is the same for every project; the per-project state lives entirely in the share code passed through `data-share-code`.
- `client/dashboard.php` — toggle button gains three `data-*` attributes (`data-share-code="<?= e($project['share_code']) ?>"`, `data-label-show-all="<?= e(t('dash.subtasks_show_all')) ?>"`, `data-label-show-top="<?= e(t('dash.subtasks_show_top')) ?>"`); the ~60-line inline `<script>(function () { … })();</script>` block at the end of `<body>` is replaced by a single `<script src="assets/js/subtask-toggle.js" defer></script>` tag, still inside `<?php if ($has_subtasks): ?>` so projects with no sub-tasks do not pay the script load. `e()` is used to escape all three data attributes. The server-rendered default state, the section id, the button id, the `aria-*` attributes, the label / icon markup, and the per-project share-code leakage guard are all unchanged.
- `client/toggle_subtasks_test.php` — section 1 replaces the 5 inline-script assertions (`addEventListener('click'`, `localStorage.setItem`, `localStorage.getItem`, `data-subtasks`, the `var shareCode = "…"` assignment) with a new external-file contract: the file exists at `client/assets/js/subtask-toggle.js`, the dashboard emits `<script src="assets/js/subtask-toggle.js" defer></script>`, the dashboard does **not** contain any inline `<script>(function () { … })();` block, the toggle button carries all three new `data-*` attributes with the expected values, the external controller reads all three attributes via `getAttribute(…)`, the controller is ES5-only (no `\bconst\b`, no `\blet\b`, no `=>`, no backticks), and the controller file contains no `<?= … ?>` / `<?php` tags. The "no-subs" and "no-tasks" branches in section 2 now assert the absence of `src="assets/js/subtask-toggle.js"` instead of the inline `addEventListener('click'`. Sections 3-7 (translation keys, CSS rule, state-machine parity, per-project key isolation, cleanup) are unchanged. Docblock updated to match.
- `database/security_headers_test.php` — line 307-309 comment updated. The old comment said the only JS was `/assets/js/subtask-manager.js`; the new comment correctly identifies the controller as `/client/assets/js/subtask-toggle.js`, served as an external file via `<script src=…> defer` (the issue-020 CSP invariant the test enforces is unchanged).

### Why external (and not just a nonce)

The issue-020 CSP on the public client portal is `default-src 'self'; script-src 'self'; style-src 'self' 'unsafe-inline'`. A `'nonce-…'`-based inline script would also work, but it would force every page render to mint a fresh nonce, force the controller to live in a server-rendered string in a place the CSP can see, and re-introduce the very pattern the tightening was designed to discourage (per-page script body in HTML). An external file served from the same origin under `script-src 'self'` is the simpler and more robust fix, and it lets us unit-test the controller in isolation (see below).

### Why ES5

The other front-end JS in the project (`assets/js/script.js`, `assets/js/subtask-manager.js`) is hand-written ES5 to maximise browser compatibility and to ship with no build step. The new controller follows the same convention. The regression test enforces this with explicit `preg_match` checks for `\bconst\b`, `\blet\b`, `=>`, and backticks against the file body, and additionally asserts the file contains no `<?= … ?>` / `<?php` tags so a future "let me just inline the share code here" change is caught at test time.

### Regression coverage

- `client/toggle_subtasks_test.php` — 5 inline-script markers removed, 9 new external-file / data-* / ES5 / static-file markers added. The two new first-class invariants are "page has no inline IIFE" and "the external controller reads all three data-* attributes"; both are checked via `strpos` / `preg_match` against the live-rendered dashboard and the file body.
- The 9 pre-existing regression tests are unchanged in number and still pass.
- 3 self-tests were run during development: (a) removing the `data-share-code` attribute causes the new attribute-contract assertion to fire; (b) re-introducing an inline `<script>(function () { … })();</script>` block causes the "CSP tightening forbids this" assertion to fire; (c) deleting `client/assets/js/subtask-toggle.js` causes 8 distinct assertions to fire. In all three cases restoring the file or markup made the test pass again.

### Upgrade notes

- No database migration. No PHP / nginx / CSS / translation change. Pull the latest code and reload `client/dashboard.php`; the new external script is served on the next request, the toggle is functional again. Users who had previously collapsed or expanded a project keep their preference (the `localStorage` key format is unchanged: `client_dash.subtasks_visible.<share_code>` with values `"0"` / `"1"`).
- The `<script src=…>` is only emitted on dashboards that actually have sub-tasks (`<?php if ($has_subtasks): ?>`), so projects with no sub-trees do not pay the extra HTTP request.

---

## v1.7.1.1 — 2026-10-16

**Align parent status with parent completion percentage.**
Closes [issue-026](docs/issues/issue-026.md): the parent row
in `tasks` now derives its `status` from its own computed
`completion_percentage` rather than from the sub-task
`status` mix. A new pure helper
`deriveParentStatusFromCompletion(?string $currentStatus, float $percentage): string`
lives next to the existing `deriveParentStatus()` in
`includes/functions.php` and is used by
`syncProjectTaskCompletions()`. The strict `all-completed`
rule from [v1.7.1](#v171--2026-10-16) /
[issue-025](docs/issues/issue-025.md) is preserved at the
leaf level and at the user-driven resume path in
`ajax/parent_hold.php` (which still calls
`deriveParentStatus()`). The two helpers are siblings, not
replacements.

### Rule

| Current `status` | `completion_percentage` | Derived `status`   |
| ---------------- | ----------------------- | ------------------ |
| `on_hold`        | any                     | `on_hold` (sticky) |
| any other        | `>= 99.5`               | `completed`        |
| any other        | `> 0` and `< 99.5`      | `in_progress`      |
| any other        | `0`                     | `not_started`      |

The 99.5 threshold matches the `round(x, 2)` granularity
`syncProjectTaskCompletions()` uses when persisting the
parent `completion_percentage`, so a parent stored as
`100.00` (after rounding) always classifies as `completed`.

### Why a new helper

The old `deriveParentStatus()` consumed the sub-task
`status` mix and answered the question "should the parent
be promoted because the children look complete?" The new
helper consumes the parent `completion_percentage` (the
same value being written to the row) and answers the
question "what status should this row carry so that
`status` and `completion_percentage` agree?" Using the
same source of truth for both fields prevents the
UAT2 regression where a parent at 1.6% progress was
classified as `not_started`.

### Files changed

- `includes/functions.php` — new helper
  `deriveParentStatusFromCompletion()` with full docblock
  referencing issue-025 and issue-026; the call site in
  `syncProjectTaskCompletions()` now passes
  `(float)$storedCompletion` to the new helper instead
  of `$subtaskStatuses` to the old one. The
  `$setCompletionDate` block, the `WHERE … AND
  (completion_percentage <> ? OR status <> ?)` guard,
  and the rest of the function body are unchanged.
- `database/task_parent_status_derived_test.php` — new
  layer-1b block with the 12 spec fixtures for
  `deriveParentStatusFromCompletion()` plus explicit
  boundary stress for `99.49` and `99.50` and an
  exhaustive `current × percentage` matrix; live-DB
  sub-test 2a extended to assert
  `completion_percentage === 0.0`; live-DB sub-test 2g
  added for the UAT2 regression (parent with 3
  sub-tasks, one at 5% and `not_started`, two at 0%
  and `not_started` → parent `in_progress` with
  percentage `> 0`); live-DB fixtures 2b and 2d
  updated to give the promoted child a non-zero
  `completion_percentage` so the parent percentage is
  also non-zero under the new contract; new layer-4
  source-grep check that the call site in
  `syncProjectTaskCompletions()` is exactly
  `deriveParentStatusFromCompletion($currentStatus, (float)$storedCompletion)`.
- `ajax/parent_hold.php`, `task_detail.php`,
  `task_edit.php`, `tasks.php`, `project_detail.php`,
  `client/dashboard.php` — **unchanged**. They all
  read `tasks.status` after the rewrite; none derive
  parent status themselves. The `resume` branch in
  `ajax/parent_hold.php` keeps calling
  `deriveParentStatus()` so the strict `all-completed`
  rule from issue-025 still applies to the user-driven
  promotion path.

### Upgrade notes

- Pure function-body + 1-line call-site change. No
  database migration. No JS / CSS / translation change.
  Pull the latest code and reload the page; no
  restart required. A parent whose `status` is now
  re-classified by the helper (e.g. `in_progress` →
  `not_started` because the recomputed percentage
  rounded to `0.00`) is updated on the next user
  interaction that triggers
  `syncProjectTaskCompletions()` (any sub-task edit,
  hold, resume, complete, or progress update). No
  back-fill script is required.
- The pre-existing 5 regression tests are unchanged
  in number and still pass; the parent-status test
  grows by 16 assertions (12 spec fixtures, 2
  boundary, 2 from the matrix stress) and 1 new
  live-DB sub-test (2g).

---

## v1.7.1 — 2026-10-16

**Parent-task status rule tightened.** Closes
[issue-025](docs/issues/issue-025.md): the
`deriveParentStatus()` helper in `includes/functions.php` now
requires **every** sub-task to be `completed` before the parent
promotes to `completed`. The previous "any sub-task completed"
rule caused the project overview to mark a parent as done while
a third of the work was still untouched (e.g. sub-tasks
`['completed', 'not_started']` previously classified the parent
as `completed`; it is now `not_started`).

No DB / schema / AJAX / JS / CSS / translation changes. The fix
is a single PHP function body and a corresponding update to the
regression-test fixtures in
`database/task_parent_status_derived_test.php`. A parent that
was previously classified as `completed` under the loose rule
and still has at least one `not_started` sub-task is
re-classified to `not_started` automatically on the next
`syncProjectTaskCompletions()` call (any sub-task edit, hold,
resume, complete, or progress update). No back-fill script is
required.

### Behavioural diff

| Sub-task statuses                        | v1.7.0 (loose) | **v1.7.1 (strict, this release)** |
| ---------------------------------------- | -------------- | --------------------------------- |
| `['completed', 'completed']`             | `completed`    | `completed` (unchanged)           |
| `['completed', 'not_started']`           | `completed`    | **`not_started`** (fixed)         |
| `['completed', 'on_hold']`               | `completed`    | **`not_started`** (fixed)         |
| `['completed', 'completed', 'not_started']` | `completed` | **`not_started`** (fixed)         |
| `['in_progress', 'not_started']`         | `in_progress`  | `in_progress` (unchanged)         |
| `['in_progress', 'completed']`           | `in_progress`  | `in_progress` (unchanged)         |
| `['not_started', 'not_started']`         | `not_started`  | `not_started` (unchanged)         |
| `[]` (no sub-tasks)                      | `not_started`  | `not_started` (unchanged)         |
| current = `on_hold`, any children         | `on_hold`      | `on_hold` (sticky, unchanged)     |

### Files changed

- `includes/functions.php` — `deriveParentStatus()` body
  changed from the loose "any child completed" rule to the
  strict "all children completed" rule. The `on_hold`
  stickiness, the `in_progress` rule, the empty-children
  rule, and the valid-enum guarantee are all unchanged.
  Docblock updated to reference both issue-012 and
  issue-025.
- `database/task_parent_status_derived_test.php` — four
  pure-function fixtures updated to codify the strict rule
  (including the two new "mixed" cases
  `['completed', 'on_hold']` and
  `['completed', 'completed', 'not_started']`); the 2f
  live-DB assertion updated to expect `'not_started'`
  instead of `'completed'` when one child remains
  `not_started`. The exhaustive 4×4 matrix block and the
  source-grep block are unchanged.

### Upgrade notes

- Pure function-body change. No database migration. No JS /
  CSS / translation change. Pull the latest code and reload
  the page; no restart required. A parent that was previously
  classified as `completed` under the loose rule and still
  has at least one `not_started` sub-task is automatically
  re-classified to `not_started` on the next user interaction
  that triggers `syncProjectTaskCompletions()` (any sub-task
  edit, hold, resume, complete, or progress update).

---

## v1.7.0 — 2026-10-16

**Security + bugfix bundle.** Closes the v1.6.0 security audit
([issue-015](docs/issues/issue-015.md)) and the password-reset
redirect loop ([issue-024](docs/issues/issue-024.md)). No new
features. Pull, rebuild the `app` container
(`docker compose up -d --build app`), reload — no DB rebuild
required. Two new additive migrations ship and apply on first
DB access (idempotent guards).

### Security hardening (issue-015 umbrella, 016–023)

A full read-through audit of every PHP source found thirteen
distinct findings, triaged into the seven split-out issues
below. The umbrella [issue-015](docs/issues/issue-015.md) is the
authoritative index; the split-out files own the per-finding
fix.

#### CSRF on every mutating internal endpoint — [issue-016](docs/issues/issue-016.md) (High)

`contact_edit.php`, `task_edit.php`, and `profile.php` now
require a CSRF token on every POST, mirroring the pattern that
was already in `project_edit.php` / `project_delete.php`. The
token is rendered via `getCsrfToken()` in
`includes/functions.php` and validated with
`validateCsrfToken()` at the very top of the POST branch,
before any other input is read. Both the create and change-password
forms in `profile.php` carry the hidden input.

In the same commit, three adjacent defects ride along:

- **Raw PDO error messages no longer echo to the user** in
  `contact_edit.php`, `task_edit.php`, or any of the four
  `ajax/*.php` endpoints. The verbose message still lands in
  `error_log` for the developer; the user sees a generic
  "Failed to save. Please try again." string.
- **`EmailService.php` HTML- and plaintext-escapes `$name`** in
  both the welcome and the password-reset email templates
  (HTML: `htmlspecialchars`; plaintext: control-character strip
  + truncation). No more reflected content in user-controlled
  email fields.

The intentional no-CSRF endpoints (`login.php`, `register.php`,
`forgot_password.php`) are not changed; a CSRF on
`register.php` would be indistinguishable from the victim
registering themselves.

#### `install.php` hardening + `_smtp_test.php` web-root removal — [issue-021](docs/issues/issue-021.md) (High on first run)

- The `db_name` field is now strictly validated against
  `/^[A-Za-z0-9_]+$/` before any DB or file work.
- `config.php` is written via `var_export()` instead of
  `addslashes()`, eliminating the fragile single-quoted-PHP-
  string-literal path that was safe only by accident.
- `APP_URL` is derived from `$_SERVER['SERVER_NAME']` (set by
  Nginx from `server_name`, not user-controllable) instead of
  the attacker-controllable `HTTP_HOST` header.
- The wizard has a CSRF token at every step, a two-stage gate
  (`config.php` exists **or** the `users` table is present →
  redirect to `index.php`), and a `session_start()` at the
  top of the file so the token persists across the three
  install steps.
- `_smtp_test.php` is moved out of the web root to
  `docker/_smtp_test.php`. The Nginx deny regex entry for the
  file is no longer needed and is removed. Invoke via
  `docker exec -it pmp-app php
  /var/www/html/docker/_smtp_test.php` from the CLI only.

#### `task_edit.php` IDOR re-validation — [issue-022](docs/issues/issue-022.md) (Medium)

The POST handler in `task_edit.php` now re-validates
`responsible_person_id`, `contact_person_id`, and `project_id`
against the current user's project set instead of trusting the
posted value's existence alone. The dropdown rendering matches
the new policy so the UI never offers a value the server will
reject. Closes the "any user / any contact" assignment hole.

#### Password-reset brute-force protection — [issue-018](docs/issues/issue-018.md) (Medium)

- New `password_reset_attempts` table (additive migration
  `20261015_add_password_reset_attempts.sql`).
- New helpers in `includes/functions.php`:
  `recordPasswordResetAttempt()` and
  `isPasswordResetRateLimited()`. Thresholds: 5 requests per
  15 min per (IP, action) tuple, with a hard lockout on
  overflow. The "success" record clears the per-(IP, action)
  counter immediately.
- `reset_password.php` rotates the *consumed* token on every
  successful POST via the existing
  `invalidateAllPasswordResetTokensForUser` helper (so a
  bookmarked URL stops working the moment a new password is
  set), and exposes the same generic "Invalid or expired reset
  link" copy for rate-limited, invalid, and expired tokens
  (indistinguishable to a network-position attacker).
- `forgot_password.php` and `reset_password.php` both call the
  new helpers; `PASSWORD_RESET_REQUEST_LIMIT`,
  `PASSWORD_RESET_REQUEST_WINDOW`,
  `PASSWORD_RESET_CONSUME_LIMIT`, and
  `PASSWORD_RESET_CONSUME_WINDOW` are tunable via `config.php`.
- The `consume` logic is extracted into a pure helper
  (`consumePasswordResetToken()`) for testability.

#### Login + registration brute-force / enumeration — [issue-019](docs/issues/issue-019.md) (Medium)

- New `login_attempts` table (additive migration
  `20261015_add_login_attempts.sql`).
- New helpers: `recordLoginAttempt()` and
  `isLoginRateLimited()`. Thresholds: 5 failures per 15 min
  per (IP, username) tuple; 30 failures per 15 min per IP
  alone. A successful login clears the per-(IP, username)
  counter.
- `register.php` no longer echoes the "Username or email
  already exists" string on the duplicate branch. It renders
  the **same** success card as a fresh registration, with no
  email sent and no DB row written. The two response bodies
  are byte-identical (modulo the CSRF token), so account
  enumeration via the registration form is closed.
- Tunable via `config.php`: `LOGIN_FAILURE_LIMIT`,
  `LOGIN_FAILURE_WINDOW`, `LOGIN_IP_LIMIT`, `LOGIN_IP_WINDOW`.

#### Response security headers + session hardening — [issue-020](docs/issues/issue-020.md) (Medium)

- New `includes/security_headers.php` helper, required from
  `includes/header.php` (internal app) and from
  `client/dashboard.php` + `index.php` (public portal). It
  sends `Content-Security-Policy`, `X-Frame-Options: DENY`,
  `X-Content-Type-Options: nosniff`, `Referrer-Policy:
  strict-origin-when-cross-origin`, and a minimal
  `Permissions-Policy` on every response. The internal-app CSP
  permits `'unsafe-inline'` for `script-src` and `style-src`
  pending the v2.0 nonce migration; the public portal's CSP
  omits `'unsafe-inline'` for `script-src`.
- `startSession()` in `includes/functions.php` now pins
  `session.cookie_*` via `session_set_cookie_params()` with
  `HttpOnly=true` and `SameSite=Lax`. `Secure` is set
  dynamically from `$_SERVER['HTTPS']`.
- `session_regenerate_id(true)` is called on every successful
  `login.php` POST and on every successful
  `reset_password.php` POST, closing the session-fixation
  vector.
- `docker/nginx.conf` adds the three simple headers
  (`X-Frame-Options`, `X-Content-Type-Options`,
  `Referrer-Policy`) at the server level so static-asset
  responses are covered too.

#### Client share-code brute-force protection — [issue-023](docs/issues/issue-023.md) (Medium)

- New `client_share_code_attempts` table (additive migration
  `20261016_add_client_share_code_attempts.sql`).
- New `share_code_rotated_at TIMESTAMP NULL` column on
  `projects` (additive migration
  `20261016_add_projects_share_code_rotated_at.sql`).
  Backfill populates `NOW()` for every existing row with a
  non-null `share_code`.
- New helpers: `recordShareCodeAttempt()` and
  `isShareCodeRateLimited()`. Thresholds: 20 attempts per
  5 min per IP; 5 distinct failed codes per 5 min per IP; a
  success clears the per-IP counter.
- `client/dashboard.php` applies the limit at the top of the
  page (429 + "Too many requests" copy on overflow).
- `index.php` "Find by Code" form records the attempt and
  applies the same limit before redirecting to
  `client/dashboard.php?code=…`.
- `project_edit.php` now shows the
  `share_code_rotated_at` value and a "Rotate code" button
  that regenerates the share code and bumps the rotated-at
  timestamp. The previous URL returns 404 on the next visit;
  a confirmation dialog warns the manager before the
  rotation. The CSRF token from issue-016 covers the action.
- An "open count (last 30 days)" badge on the same page
  surfaces how many successful reads the code has had, with
  no PII (no IP, no UA) leaked to the manager.

### Bug fix — password-reset redirect loop (issue-024)

[issue-024](docs/issues/issue-024.md) ships in the same bundle
as the security work because the regression-test fixture
(`database/password_reset_attempts_test.php` §8) directly
encodes the broken behavior as a negative assertion. Shipping
the two together means the test net is self-consistent.

**The fix:** the `rotatePasswordResetToken()` + 302 pattern
that was added in v1.8.0 to defend against URL leakage is
removed. The pattern was tautological — the rotation function
always returns a fresh token, so the redirect always fired,
and the next request rotated again, etc. The user experienced
this as either `ERR_TOO_MANY_REDIRECTS` (Chrome / Firefox) or
a cached "Invalid Reset Link" page (Safari).

The correct defense against URL leakage is **bulk
invalidation on the successful POST**, which the existing
`invalidateAllPasswordResetTokensForUser` helper already
provides. The token in the URL is now stable for the entire
form session; the user submits a new password → all live
tokens for the user are deleted; a bookmarked URL no longer
works.

The rate-limit check on the GET stays in place. It reuses the
same "Invalid or expired reset link" string so a network-
position attacker cannot distinguish "rate limited" from
"bad token" from "expired token".

`database/reset_password_redirect_loop_test.php` is new: 12
sections, 37 assertions, including a "would have caught the
v1.8.0 bug" assertion that 11 of the 37 fail if the old
rotation code is restored.

### Files changed

**New files**

- `includes/security_headers.php` — `sendSecurityHeaders()`
  helper.
- `database/migrations/20261015_add_password_reset_attempts.sql`
- `database/migrations/20261015_add_login_attempts.sql`
- `database/migrations/20261016_add_client_share_code_attempts.sql`
- `database/migrations/20261016_add_projects_share_code_rotated_at.sql`
- `database/security_issue016_test.php` — CSRF + raw-PDO +
  email-HTML regression.
- `database/password_reset_attempts_test.php` — issue-018
  rate-limit + token-rotation regression.
- `database/login_register_protection_test.php` — issue-019
  rate-limit + enumeration regression.
- `database/security_headers_test.php` — issue-020 source-grep
  + live-curl regression.
- `database/install_wizard_test.php` — issue-021 strict-name
  regex + `var_export` + `_smtp_test.php` relocation regression.
- `database/task_edit_idor_test.php` — issue-022 IDOR
  regression.
- `database/client_share_code_protection_test.php` — issue-023
  rate-limit + `share_code_rotated_at` backfill regression.
- `database/reset_password_redirect_loop_test.php` — issue-024
  redirect-loop regression (12 sections, 37 assertions).
- `docker/_smtp_test.php` — moved out of the web root; the
  original `_smtp_test.php` is deleted.

**Modified files**

- `contact_edit.php`, `task_edit.php`, `profile.php` —
  CSRF gate + hidden input + generic DB-error copy
  (issue-016).
- `ajax/complete_task.php`, `ajax/update_task_status.php`,
  `ajax/update_task_progress.php`,
  `ajax/get_project_contacts.php` — generic error copy
  (issue-016).
- `includes/EmailService.php` — `htmlspecialchars` /
  control-char-strip on `$name` in both templates + AltBody
  (issue-016).
- `install.php` — strict `db_name` regex, `var_export` config
  writer, `SERVER_NAME`-based URL, CSRF, two-stage gate
  (issue-021).
- `reset_password.php` — remove rotate-on-render + 302;
  rate-limit check stays; bulk-invalidation on success stays
  (issue-018 + issue-024).
- `forgot_password.php` — rate-limit gate (issue-018).
- `login.php` — rate-limit gate + `session_regenerate_id(true)`
  (issue-019 + issue-020).
- `register.php` — enumeration fix: "already exists" branch
  renders the same success card with no DB write / no email
  (issue-019).
- `includes/functions.php` — `startSession()` cookie flags;
  `recordPasswordResetAttempt` / `isPasswordResetRateLimited`;
  `consumePasswordResetToken`; `recordLoginAttempt` /
  `isLoginRateLimited`; `recordShareCodeAttempt` /
  `isShareCodeRateLimited`.
- `client/dashboard.php` — rate-limit gate at the top.
- `index.php` — rate-limit gate on the "Find by Code" POST.
- `project_edit.php` — `share_code_rotated_at` display +
  "Rotate code" action.
- `includes/header.php` — `require_once security_headers.php`
  and call `sendSecurityHeaders()`.
- `docker/nginx.conf` — three complement headers
  (`X-Frame-Options`, `X-Content-Type-Options`,
  `Referrer-Policy`).
- `config.php` — twelve new constants
  (`PASSWORD_RESET_REQUEST_LIMIT`,
  `PASSWORD_RESET_REQUEST_WINDOW`,
  `PASSWORD_RESET_CONSUME_LIMIT`,
  `PASSWORD_RESET_CONSUME_WINDOW`, `LOGIN_FAILURE_LIMIT`,
  `LOGIN_FAILURE_WINDOW`, `LOGIN_IP_LIMIT`,
  `LOGIN_IP_WINDOW`, `SHARE_CODE_IP_LIMIT`,
  `SHARE_CODE_IP_WINDOW`, `SHARE_CODE_DISTINCT_LIMIT`).
- `database/schema.sql` — add the four new tables / columns
  for fresh installs.

**Removed**

- `_smtp_test.php` — relocated to `docker/_smtp_test.php`.

### Upgrade notes

- **Pull and rebuild the `app` container** (`docker compose
  up -d --build app`). The two new migrations are
  idempotent (guarded via `information_schema`) and apply
  on the first `Database` connect — no manual SQL to run.
- **No DB rebuild required.** Existing rows are preserved;
  the `share_code_rotated_at` backfill is part of the
  migration.
- **Existing sessions are unaffected.** The session-cookie
  flag change in `startSession()` applies on the next
  session start; no one is logged out by the upgrade.
- **No app restart required** beyond the `app` container
  rebuild, which is the standard PHP-FPM reload.
- **HTTPS deployments only** get the `Secure` cookie flag
  automatically (derived from `$_SERVER['HTTPS']`). HTTP
  local-dev is unchanged.

### Reference

- [docs/issues/issue-015.md](docs/issues/issue-015.md) — the
  audit + triage.
- [docs/issues/issue-016.md](docs/issues/issue-016.md) — CSRF
  + raw PDO + email HTML.
- [docs/issues/issue-018.md](docs/issues/issue-018.md) —
  password-reset rate limiting.
- [docs/issues/issue-019.md](docs/issues/issue-019.md) — login
  + registration rate limiting / enumeration.
- [docs/issues/issue-020.md](docs/issues/issue-020.md) — CSP +
  cookie flags + session hardening.
- [docs/issues/issue-021.md](docs/issues/issue-021.md) —
  `install.php` + `_smtp_test.php`.
- [docs/issues/issue-022.md](docs/issues/issue-022.md) —
  `task_edit.php` IDOR.
- [docs/issues/issue-023.md](docs/issues/issue-023.md) —
  client share-code brute-force.
- [docs/issues/issue-024.md](docs/issues/issue-024.md) —
  password-reset redirect loop.

---

## v1.5.1 — 2026-10-13

UI layout fixes for the v1.5.0 `task_edit.php` / `task_detail.php`
surface, tracked as `docs/issues/issue-013.md` (revised 2026-10-12).
No DB / schema / JS / translation changes. Two CSS bugs fixed,
plus three earlier structural items confirmed already in place:

**Bugs fixed (revised scope, 2026-10-12)**

- **`task_detail.php` "Put on Hold" / "Resume" button falls onto
  its own row.** `.page-actions` had no rule in
  `assets/css/style.css`, so it defaulted to `display: block` and
  the Hold / Resume `<form class="inline-form">` (which was
  correctly nested inside `<div class="page-actions">`) wrapped
  below the other action buttons. Added a `.page-actions` rule
  (`display: flex; flex-wrap: wrap; align-items: center; gap: 10px;
  justify-content: flex-end`) so all action controls sit on one
  row. Also added a `.page-header` rule (flex row with
  `justify-content: space-between`) so the page title and the
  action row lay out correctly on the same baseline.
- **`task_edit.php` "Use sum of sub-task hours" checkbox and
  its text label render on separate lines.** `.checkbox-label`
  had no rule in `assets/css/style.css`, so the default block-flow
  put the input and the `<span>` on two separate lines. Added a
  `.checkbox-label` rule (`display: flex; align-items: center;
  gap: 8px; cursor: pointer;`) and pinned the touch target to
  `18px × 18px` on `.checkbox-label input[type="checkbox"]` for
  WCAG 2.5.5 (target size, sufficient).
- **Mobile breakpoint extended.** The existing
  `@media (max-width: 768px)` block now also collapses
  `.page-header` to a column (`flex-direction: column;
  align-items: stretch;`) and stretches the action buttons to
  full width, so the action row remains reachable with a thumb
  on a phone.

**Background (already in place from the original 2026-10-12 spec,
unchanged in this revised scope)**

- `task_edit.php` two-column `.form-grid` layout defined in
  `assets/css/style.css`, with the Dates section marked
  `class="form-section full-row"` so it spans both columns.
- `task_edit.php` top-level `$effective_inherit = false`
  initialisation silences the "Undefined variable" PHP warning
  on a brand-new task.
- `task_detail.php` Hold / Resume `<form class="inline-form">`
  is rendered inside the `<div class="page-actions">` row
  (the structural position was already correct; the v1.5.1 fix
  is the new `.page-actions` flex rule above).

### Regression coverage

- `database/task_layout_issue013_test.php` — a structural
  regression test that source-greps the new CSS rules in
  `assets/css/style.css` (`.form-grid`, `.form-section`,
  `.estimate-input`, `.page-header`, `.page-actions`,
  `.checkbox-label` + 18x18 touch target, plus the
  `@media (max-width: 768px)` mobile collapse for
  `.page-header` and `.form-grid`), asserts that the
  `$effective_inherit` initialisation appears before the form
  HTML in `task_edit.php`, asserts the
  `class="form-section full-row"` is present on the Dates
  section, asserts the Hold / Resume form is positioned inside
  `<div class="page-actions">` in `task_detail.php`, and lints
  both modified PHP files. Includes a self-check that the test
  catches a regression by re-introducing the bug (the
  `.checkbox-label` rules are stripped, the test fails, the
  rules are restored, the test passes again).

### Files changed

- `assets/css/style.css` — added `.form-grid`,
  `.form-grid .form-section`, `.form-grid .form-section.full-row`,
  `.form-grid .form-section h3`, `.estimate-input` rules
  (original scope) plus `.page-header`, `.page-header .page-title`,
  `.page-header .page-actions`, `.page-actions`,
  `.checkbox-label`, `.checkbox-label input[type="checkbox"]`,
  `.checkbox-label > span` rules and a matching mobile
  breakpoint (revised scope).
- `task_edit.php` — top-level `$effective_inherit = false`
  initialisation, re-derivation inside the editing branch,
  `class="form-section full-row"` on the Dates section,
  `<label class="checkbox-label" for="inherit_subtask_hours">`
  on the inherit-mode checkbox.
- `database/task_layout_issue013_test.php` — new regression
  test, extended for the revised scope.

### Upgrade notes

- Pure markup / CSS change. No database migration. No JS file
  change. Pull the latest code and reload the page; no restart
  required.

---

## v1.5.0 — 2026-10-12

Parent-task model upgrade. Adds an **inherit mode** for a parent
task's effort so the user no longer has to keep the parent in sync
with the sum of its sub-tasks' estimates by hand, and makes a
parent's **status** derive from its sub-tasks so the project
rollup, the task list, and the client portal all agree on what a
parent is doing. The two changes share the same parent surface
(`task_edit.php` / `task_detail.php`) and are released together so
the validation rules and the display wiring land in a single
coordinated change. One small additive migration. No breaking API
changes.

### v1.5.0 — Tester follow-ups (added 2026-10-12)

Two tester rounds (issue-010 and issue-011) returned with bug
reports against the v1.4.1 / v1.5.0 surface. All reported bugs are
fixed in this release; the fixes do not change the public
behaviour of the inherited mode, but they do tighten the
display contract so the user always sees a meaningful label
rather than an empty cell.

- **Issue-011 tester-note-001 bug 1 — "Not estimated (no sub-task
  estimates)" copy on all four display surfaces.** When a parent
  is in inherit mode and **all** of its sub-task estimates are
  `NULL` / `0` / negative, all four display surfaces now render
  the literal "Not estimated (no sub-task estimates)" label in
  place of the previously hidden / mismatched copy:
  - `task_detail.php` (parent block): the previous yellow
    warning banner that conflated "no estimate" with
    "completion will use equal-average fallback" has been
    removed; the same span is now reused with the spec
    literal.
  - `tasks.php` and `project_detail.php`: the previously
    hidden `Total Time` row now renders the spec literal in
    the same `<span class="task-estimate">` element so the
    row's column alignment is preserved.
  - `client/dashboard.php` (read-only public surface): the
    spec literal is rendered via the new translation key
    `dash.not_estimated_parent`, defined in both EN and ZH
    in `client/lang.php`. EN value matches the internal-app
    literal verbatim; ZH value: "暂未估算(子任务均未填写工时)".
  - Regression test extension: `database/task_inherit_subtask_hours_test.php`
    now source-greps all four surfaces for the spec literal /
    the `t('dash.not_estimated_parent')` call, source-greps
    `client/lang.php` for the new key in both EN and ZH
    dictionaries, and live-renders the unauthenticated client
    dashboard to confirm the translated string reaches the
    served HTML.
- **Issue-010 tester-note-001 — three small bugs in the sub-tasks
  toggle.** All three were fixed against the v1.4.1 surface and
  are listed here so the v1.5.0 changelog captures them.
  - **Bug 1 (a11y):** `client/dashboard.php` `applyState()`
    selector was `section.querySelectorAll('ul.task-tree')`,
    which matched the root `<ul class="task-tree root">` and
    flipped its `aria-hidden`, hiding the top-level workstream
    names from screen readers in the default "hidden" state.
    Selector scoped to `ul.task-tree:not(.root)` to mirror the
    CSS rule.
  - **Bug 2 (test coverage):** `client/toggle_subtasks_test.php`
    only asserted the literal `aria-hidden` substring and
    silently accepted the buggy selector. New assertion
    `strpos($with_subs_html, 'ul.task-tree:not(.root)')` locks
    the fix in.
  - **Bug 3 (dead code):** the test's cleanup section
    attempted to restore the old `subtask_functions.sql`
    triggers via `shell_exec('mariadb … < $tmp')`. The
    `pmp-app` container has no `mariadb` client, so the call
    was a silent no-op. The triggers are also no longer used
    by the app (parent completion is now computed in PHP via
    `calculateTaskCompletionMap()`), so the entire restore
    block was removed and replaced with a `SELECT 1` DB
    connectivity sanity check.

### Highlights

- **Parent effort inherit mode (issue-011)**
  - New nullable column `tasks.inherit_subtask_hours TINYINT(1) NULL`
    (default `NULL`; existing rows are unchanged). The flag is only
    surfaced on the edit form when the task already has at least
    one sub-task — the parent-must-be-created-first invariant from
    the original issue brief is preserved.
  - New helper `resolveTaskEstimateHours(?array $task, array $childTasks)`
    in `includes/functions.php` is the **single source of truth**
    consumed by all four display surfaces:
    - When `inherit_subtask_hours = 1`, the function returns the
      sum of the children's positive `estimated_hours` (zero and
      NULL child estimates are excluded), or `NULL` if no child has
      a positive estimate.
    - When the flag is `0` or `NULL`, the function returns the
      stored `estimated_hours` (with the existing null / zero /
      negative guards) so manual-mode parents behave exactly as
      they did before this release.
  - The task edit form (`task_edit.php`) renders a new checkbox
    **"Use sum of sub-task hours as total task time"** (EN) /
    inline equivalent on the internal app. When ticked, the
    `Total Task Time` numeric input is disabled, an inline script
    toggles the disabled state on click, and a read-only hint
    shows the current live sum. A **server-side defence** forces
    `estimated_hours = NULL` on save whenever the flag is `1`, so
    even a hand-crafted POST cannot leave a stale manual value in
    the row.
  - Validation: editing a parent that has sub-tasks with the flag
    off and an empty / zero / non-positive `Total Task Time` now
    fails with the existing error-pattern:
    *"Tasks with sub-tasks must specify a non-zero total task time,
    or check 'Use sum of sub-task hours'."* — the user is
    steered to either pick a number or tick the box.
  - All four display surfaces now agree on the number:
    - `task_detail.php` (parent block)
    - `tasks.php` (list view, N+1-safe — child estimates are
      loaded in a single follow-up query for parents with the
      flag set, not row-by-row)
    - `project_detail.php` (the project-level task table now
      shows a `Total Time` cell that was previously missing
      entirely)
    - `client/dashboard.php` (read-only client portal, the
      number alone — no inherit-mode indicator on the public
      surface per the issue spec)
  - Inherit-mode rows render a small `fa-link` icon next to the
    number in the internal console so the manager can see at a
    glance that the value is computed, not typed. If a parent
    is in inherit mode but none of its children have positive
    estimates, `task_detail.php` shows an `alert alert-warning`
    banner so the manager is not silently left with a missing
    number.
  - Migration: `database/migrations/20261012_add_task_inherit_subtask_hours.sql`
    (idempotent, guarded by `information_schema.COLUMNS`). Fresh
    installs are covered by the matching `database/schema.sql`
    update. Existing rows are untouched (the column is `NULL`
    by default and `NULL` is treated as "manual mode").
  - Regression test: `database/task_inherit_subtask_hours_test.php`
    asserts the column exists in the right slot of the table,
    exercises `resolveTaskEstimateHours()` against twelve pure-function
    cases (inherit, manual, mixed-child, rounding, zero / null /
    negative children), and runs a live DB round-trip that
    toggles the flag and confirms the stored `estimated_hours`
    is forced `NULL` on save. Source-grep checks confirm all
    four display surfaces call the helper, the form binds the
    field, the migration declares the column with the
    information_schema guard, and `schema.sql` declares the
    column for fresh installs.

- **Parent status derived from sub-tasks (issue-012)**
  - `deriveParentStatus(?string $currentStatus, array $childStatuses)`
    in `includes/functions.php` is the **single source of truth**
    for parent → child status rollup, consumed by both the
    server-side `syncProjectTaskCompletions()` extension and the
    new `ajax/parent_hold.php` endpoint:
    - **on_hold is sticky** — once a parent is held, subsequent
      sub-task changes do **not** auto-resume it; the manager
      must explicitly press *Resume* on the task detail page.
    - Otherwise, the derived status is: any child `in_progress`
      → `in_progress`; otherwise any child `completed` →
      `completed`; otherwise `not_started`. (The implementation
      follows the exact spec the issue brief documents; the rule
      is documented and unit-tested.)
  - `syncProjectTaskCompletions()` now also re-derives the
    parent's `status` and aligns `completion_date`:
    - On transition to `completed`, `completion_date` is set to
      `COALESCE(completion_date, NOW())` so a completed parent
      never has a missing completion date.
    - On demotion **from** `completed`, `completion_date` is
      cleared to `NULL` so a re-opened parent does not keep
      stale "completed" history.
    - A `WHERE completion_percentage <> ? OR status <> ?` guard
      keeps `updated_at` honest when the helper is a no-op.
  - `task_detail.php` shows a small **"Auto"** pill (`fa-magic`,
    `.derived-pill`) next to the status badge for any task that
    has sub-tasks — visual cue that the value is derived, not
    typed. The pill is suppressed on the client portal.
  - `task_edit.php` now renders the **status** field as a
    **read-only** badge for any task that has sub-tasks (the
    dropdown is replaced with the current status label and an
    explanation text: *"This task has sub-tasks, so its status
    is derived automatically. To put it on hold, use the 'On
    Hold' action on the task detail page."*). The submitted
    `status` value is **ignored** for parents with sub-tasks
    (server-side defence) so hand-crafted POSTs cannot desync
    the rollup.
  - New endpoint `ajax/parent_hold.php` is the only legitimate
    path that mutates a parent's status while it has children:
    - `action=hold` sets `tasks.status = 'on_hold'`.
    - `action=resume` re-derives the status from the current
      sub-task statuses via `deriveParentStatus(null, …)` and
      updates `completion_date` in the same way
      `syncProjectTaskCompletions()` does.
    - Both paths call `syncProjectTaskCompletions($db, $projectId)`
      at the end so the project's rollup, the task list, and the
      client portal are all updated atomically.
    - CSRF, login, ownership (project owner), and parent-with-
      children constraints are enforced. Errors return
      `400` / `403` / `404` / `405` as appropriate. The
      endpoint detects `X-Requested-With: XMLHttpRequest` and
      returns JSON; otherwise it redirects back to the task
      detail page so the buttons can be plain form submits.
  - `ajax/complete_task.php` now calls `syncProjectTaskCompletions()`
    directly in both branches (sub-task and top-level). The
    helper extension makes the old local `updateParentTaskCompletion()`
    wrapper redundant, so it was removed in the same change to
    keep a single source of truth.
  - Regression test: `database/task_parent_status_derived_test.php`
    exercises `deriveParentStatus()` against twelve pure-function
    cases plus an exhaustive 4×4 current × child matrix, runs a
    live DB round-trip that exercises: (a) all-not-started →
    not_started, (b) one in_progress child → in_progress, (c)
    all children completed → completed with `completion_date`
    set, (d) demotion to in_progress clears `completion_date`,
    (e) on_hold stickiness across `syncProjectTaskCompletions()`,
    (f) resume derivation. Source-grep checks confirm
    `ajax/parent_hold.php` calls the right helpers,
    `task_detail.php` renders the pill and the buttons, and
    `includes/functions.php` exports both new helpers.

## v1.4.1 — 2026-10-12

Client-portal-only feature release. Adds a sub-tasks toggle to the
public client dashboard so a stakeholder can collapse or expand the
nested sub-trees under each top-level workstream without scrolling
through a wall of sub-tasks. No DB changes, no migrations, no new
endpoints. The state is stored per device in `localStorage`.

### Highlights

- **Sub-tasks toggle on the client dashboard (issue-010)**
  - Inline button rendered next to the "Tasks & Progress" heading on
    `client/dashboard.php`. Label flips between **"Show all
    sub-tasks"** (EN) / **"显示所有子任务"** (中文) and **"Show
    top-level only"** (EN) / **"仅显示顶级任务"** (中文); the icon
    flips between `fa-eye` and `fa-eye-slash`. The control is hidden
    entirely on projects that have no sub-tasks — nothing to
    collapse, so the control would be misleading.
  - **Default state is "top-level only"** for first-time visitors and
    for any project that has sub-tasks. The nested sub-trees are
    visually hidden via a scoped CSS rule, not by re-rendering
    server-side; the page HTML is the same on every load and only
    the section's `data-subtasks` attribute + the button's
    `aria-pressed` differ.
  - The user's choice is persisted in `localStorage` under
    `client_dash.subtasks_visible.<share_code>`. Returning visitors
    who previously chose "expanded" see the full tree on first
    paint with no flash of collapsed content (the inline script
    runs at the end of `<body>`, before the browser commits the
    first frame). Each project has its own key, so two projects
    keep two separate preferences.
  - When `localStorage` is unavailable (private mode, sandboxed
    iframe, JS disabled), the page still defaults to "top-level
    only" and the toggle remains visible but its click is a safe
    no-op.
  - Accessibility: the control is a real `<button type="button">`
    with `aria-pressed`, `aria-controls="tasks-progress"`, and an
    `aria-label`; nested `<ul class="task-tree">` carry
    `aria-hidden="true"` while collapsed so screen readers skip
    the subtree. The icon + text combination means there is no
    reliance on colour alone.
  - Purely client-side: no new DB columns, no new SQL, no new
    endpoints. The existing `render_task_tree()` is untouched;
    only the wrapper section's id, the new button, the localised
    labels, the inline script, and one scoped CSS rule
    (`#tasks-progress[data-subtasks="hidden"] ul.task-tree:not(.root)`)
    are added.
  - Share code is interpolated into the inline script via
    `json_encode(..., JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS |
    JSON_HEX_QUOT)` so a future change to the share-code character
    set cannot break the page.

### Localisation

Three new keys added to `client_translations()` in `client/lang.php`
for both English and Simplified Chinese:

| Key                          | EN                       | 中文                   |
| ---------------------------- | ------------------------ | ---------------------- |
| `dash.subtasks_show_all`     | `Show all sub-tasks`     | `显示所有子任务`        |
| `dash.subtasks_show_top`     | `Show top-level only`    | `仅显示顶级任务`        |
| `dash.subtasks_toggle_aria`  | `Toggle sub-tasks visibility` | `切换子任务显示`   |

### Upgrade notes

This release has **no database change** and **no migration**. To
deploy:

1. Pull the latest code. The change is server-rendered HTML + one
   inline `<script>` + one CSS rule, so no rebuild is needed.
2. (Optional) Run the new regression test inside the app container:
   ```bash
   docker compose exec app php client/toggle_subtasks_test.php
   ```
3. Clear any CDN / reverse-proxy cache for `client/dashboard.php`
   and `client/assets/client.css` so visitors see the new control
   on the next page load.

### Out of scope

- Per-task accordion (collapsing individual sub-trees independently).
- An "Expand all / Collapse all" pair of controls. The single toggle
  is sufficient for the use case.
- Persisting the choice across devices or browsers. This is a
  per-device preference, like the existing `client_lang` cookie.
- Surfacing the toggle on the internal console's task pages.
- A server-side query-parameter override (e.g. `?subtasks=1`).

---

## v1.2.1 — 2026-10-06

Bundles the planned **v1.3**, **v1.3.1**, and **v1.4** work-items into a
single **v1.2.1** release (the next patch slot after v1.2.0) because
they all touch the same project + contact surface and the same DB
shape.

- Originally planned as **v1.3**: optional **Project Start Date**
  (issue-008).
- Originally planned as **v1.3.1**: a small patch on top —
  **dashboard "New Project" CTA target** + **CSRF hardening** on the
  project create / edit POST handler (QA findings against the v1.3
  draft, documented in
  `docs/issues/issue-008/tester-note-001.md`).
- Originally planned as **v1.4**: ships the missing `contacts`
  columns (**company**, **position**, **address**) so the contact
  create / edit form stops crashing, and makes the underlying PDO
  error visible in the next schema-drift bug (issue-009).

All three shipped together under the **v1.2.1** tag.

### Highlights

- **Project Start Date (issue-008, originally planned as v1.3)**
  - New optional `projects.start_date DATE` column, distinct from
    `created_at`. The manager decides when work on the project
    actually began; the database row's insertion time is no longer
    mis-reported as the project's start date.
  - The project create / edit form gains a third date field next to
    `Expected Completion Date` and `Actual Completion Date`. The form
    pre-fills from the stored value, parses dates with the existing
    `parseProjectDate` helper, and surfaces a **non-blocking** warning
    if `start_date` is later than `expected_completion_date` or
    `completion_date` (historical imports stay importable).
  - The internal project detail page shows `Started: <date>` in the
    project-meta strip (conditional on the column being non-null).
  - The internal projects list shows `Started: <date>` as a small
    meta line under each project card.
  - The client portal dashboard now renders the user-supplied
    `start_date` in its `Started` row. When the column is `NULL` the
    row renders as `Not set` (matching the `Expected Completion Date`
    fallback) rather than echoing the audit `created_at`.
  - `created_at` continues to be used for sort order on the projects
    list and for any audit context — the two fields have different
    semantics and are no longer conflated.
  - Migration: `database/migrations/20261006_add_project_start_date.sql`
    (idempotent, guarded by `information_schema`). Existing rows stay
    `NULL` and continue to render correctly.
  - Regression test: `database/project_start_date_test.php` asserts
    that, given a `start_date` different from `created_at`, the client
    dashboard and the project detail page both display the
    user-supplied value, not `created_at`. The form's date parser is
    exercised for valid / leap-year / malformed / non-calendar / empty
    inputs.

- **Contact write path no longer crashes — missing `contacts` columns
  (issue-009, originally planned as v1.4)**
  - Adds `contacts.company VARCHAR(255) NULL`,
    `contacts.position VARCHAR(255) NULL`, and
    `contacts.address TEXT NULL`. The contact write path
    (`contact_edit.php`), the read view (`contact_detail.php`), and
    the contacts search (`contacts.php`) have all been referencing
    these columns; without the schema the create / edit form failed on
    every submit with MariaDB error
    `1054 (42S22): Unknown column 'company' in 'INSERT INTO'` and the
    user saw the generic `"Error saving contact: Database execute
    failed"` message. The same drift class was previously fixed for
    `phone` in issue-001 / v1.1.0.
  - Migration:
    `database/migrations/20261006_add_contacts_company_position_address.sql`
    (idempotent, guarded by `information_schema`). Existing rows stay
    `NULL` and continue to render with the existing "Not set" fallbacks.
  - `Database::execute()` and `Database::query()` now prepend the
    underlying PDO error message to the thrown `Exception`, so the
    next schema-drift bug surfaces directly to the form instead of
    being hidden as `"Database execute failed"`.
  - Regression test: `database/contacts_columns_test.php` performs the
    exact failing `INSERT` from the bug report (name `张总`, wechat
    `YADEPAN18`), then `UPDATE`s the new columns and re-reads them; it
    also exercises the search-by-company query and the new
    error-message wrapper, then cleans up after itself.

- **Dashboard "New Project" CTA — bug 2 from `tester-note-001`
  (originally planned as v1.3.1)**
  - `dashboard.php` "Recent Projects" → "+ New Project" button now
    routes to `project_edit.php` (the create form), not to
    `projects.php` (the list). Matches the label and reduces clicks to
    one. The same CTA on `projects.php` already routed correctly.

- **CSRF protection on project create / edit POST — bug 4 from
  `tester-note-001` (originally planned as v1.3.1)**
  - `project_edit.php` now allocates the CSRF token for both create
    and edit (was previously only allocated when editing an existing
    project).
  - The main create / edit form embeds a hidden `csrf_token` input,
    and the POST handler calls `validateCsrfToken()` before
    processing any other field. A cross-site request without a valid
    token is rejected with
    `"Invalid form token. Please reload the page and submit again."`
    and does not mutate `projects` (including `start_date`,
    `expected_completion_date`, `completion_date`, `status`).
  - The inline delete dialog continues to validate its own CSRF token
    via `project_delete.php` (unchanged).
  - The `project_start_date_test.php` regression test was extended to
    assert the dashboard CTA target and the form CSRF inputs.
  - Out of scope for this release: same CSRF hardening should be
    applied to other write handlers (`task_edit.php`,
    `contact_edit.php`, `register.php`, `forgot_password.php`,
    `reset_password.php`, AJAX endpoints). Track under a separate
    ticket.

### Upgrade notes

Apply this from any prior release (including a fresh `v1.2.0` install):

1. Pull the latest code.
2. Re-run the v1.2.1 migrations (both are idempotent):
   ```bash
   docker compose exec db mariadb -u"$DB_USER" -p"$DB_PASSWORD" "$DB_NAME" \
     < database/migrations/20261006_add_project_start_date.sql
   docker compose exec db mariadb -u"$DB_USER" -p"$DB_PASSWORD" "$DB_NAME" \
     < database/migrations/20261006_add_contacts_company_position_address.sql
   ```
3. (Recommended) Run the regression tests inside the app container:
   ```bash
   docker compose exec app php database/project_start_date_test.php
   docker compose exec app php database/contacts_columns_test.php
   docker compose exec app php database/task_completion_test.php
   ```
4. For fresh installs, point `docker-compose.yml` at a clean checkout
   and run `docker compose up -d`. The installer is reachable at
   `http://localhost:${APP_PORT}/install.php`. Fresh databases include
   the new columns via the updated `database/schema.sql`.

### Refusals / non-changes

The QA report on issue-008 also flagged a third bullet about
`fa-flag` on the dashboard "Recent Projects" item. v1.2.1 does **not**
change that icon because `docs/issues/issue-008.md:102` explicitly
specifies `fa-flag` for that exact entry. Reviewed: a later
"themed icons" pass is the right place to revisit the entire icon set
together.

---

## v1.2.0 — 2026-10-05

This release hardens the deployment, consolidates the public entry point,
makes project deletion require an explicit Project Code, and introduces
task effort estimates with effort-weighted completion rollups.

### Highlights

- **Deployment security — Nginx deny rules (issue-003)**
  - `docker/nginx.conf` now explicitly blocks HTTP access to dotfiles,
    configuration files (`config.php`, `config.template.php`,
    `.htaccess`), the diagnostic `_smtp_test.php`, and the
    `docs/`, `database/`, `vendor/`, and `includes/` directories.
  - The intentionally public entry points (`install.php`, the
    authentication pages, the project / task / contact CRUD pages, AJAX
    endpoints, `index.php`, and the `client/` portal) and the static
    asset paths continue to work.
  - Defense in depth: secrets and non-public source files are still
    kept outside the document root where the deployment allows.
- **Public entry point at `/` (issue-004)**
  - The domain root now hosts the share-code form. Clients and project
    managers share a single landing page with a clearly visible "Owner
    sign in" link that opens the existing `/login.php` flow.
  - New `buildClientShareUrl()` output targets the root; existing
    `/client/` URLs are preserved as 302 redirects to `/` (see v1.1.0).
  - Invalid / unknown / malformed codes show a useful error and never
    confirm whether a project exists.
- **Project-Code-gated deletion (issue-005)**
  - The delete action now opens an accessible confirmation dialog that
    shows the project's Project Code (`projects.share_code`) and refuses
    to submit until the manager types that exact value.
  - Deletion is submitted as `POST` with the application's standard
    CSRF token. The server re-checks session authentication, project
    ownership, the CSRF token, and the typed Project Code before
    deleting. A missing Project Code, a mismatched value, or a
    `GET` request is non-destructive.
  - Projects without a Project Code cannot be deleted through this flow;
    the user is told the database ID is not an acceptable substitute.
- **Task time estimates (issue-006)**
  - Optional per-task and per-subtask total-time estimate, entered in
    **hours** or **mandays** (1 manday = 8 hours). Stored canonically
    in `tasks.estimated_hours` (`DECIMAL(12,4)`, max `99,999,999.9999`).
  - Empty means "not estimated"; zero is rejected as not a usable
    weight. Existing tasks and subtasks remain unestimated; their
    completion values are not touched by the migration.
  - Migration: `database/migrations/20261005_add_task_estimated_hours.sql`
    (idempotent, guarded by `information_schema`).
- **Effort-weighted completion (issue-007)**
  - When every sibling in a group has a positive estimate, completion
    is reported as the effort-weighted average
    `Σ(estimated_hours × completion%) / Σ(estimated_hours)`.
  - If any sibling is unestimated or has a zero estimate, the group
    falls back to the existing equal-average so legacy and
    partially-estimated work is not silently dropped.
  - Applies to:
    - Parent-task completion derived from subtasks (computed in the
      application when rendering task and dashboard pages).
    - Project completion derived from top-level tasks, via the
      updated `project_stats` view.
    - The client portal's read-only project dashboard.
  - The same server-side formula is used everywhere; no client-side
    computation is introduced.
  - Migration: `database/migrations/20261005_weight_project_completion.sql`
    (idempotent `CREATE OR REPLACE VIEW`).

### Bug fixes / behavior changes

- The auto-subtask trigger (`database/subtask_functions.sql`) is no
  longer needed for the displayed completion rollup: v1.2.0 computes
  parent-task completion from the subtask tree in the application, so
  the recursive trigger MariaDB ≥ 10.5 rejects is replaced. The file is
  retained as reference.
- Existing projects / tasks are unaffected: tasks without an estimate
  continue to use the equal-average rule, and completion values already
  stored are not changed.

### Improvements

- Project delete dialog is keyboard accessible and works at narrow /
  mobile viewport sizes.
- Task and subtask create / edit forms show the time estimate and unit
  selector; task list and task / subtask detail views show the saved
  estimate in the user-selected unit.
- `formatMoney()` continues to handle JPY (no decimals) correctly, now
  alongside the new `formatEstimatedTime()` helper used for task
  estimates.
- The Nginx deny rules are documented in the README under
  *Web access boundaries*, and the README's quick-start now lists a
  smoke test that confirms each private file returns `404`.

### Known gaps (not fixed in v1.2.0)

- Internal app pages remain English-only. The client portal is fully
  i18n (EN / 简体中文); extending the catalog to the internal console
  is tracked for a future release.
- The Dockerfile still pulls base images from `docker.m.daocloud.io`
  mirrors because `registry-1.docker.io` is not reachable from some
  networks. Adjust the `image:` lines in `docker-compose.yml` if you
  have direct Docker Hub access.

> The first "Known gaps" bullet — missing `contacts.company`,
> `position`, `address` columns — was the top-of-stack item in v1.2.0
> and is **resolved in v1.2.1** (see issue-009 below).

### Upgrade notes

1. Pull the latest code.
2. Re-run the v1.2.0 migrations (both are idempotent):
   ```bash
   docker compose exec db mariadb -u"$DB_USER" -p"$DB_PASSWORD" "$DB_NAME" \
     < database/migrations/20261005_add_task_estimated_hours.sql
   docker compose exec db mariadb -u"$DB_USER" -p"$DB_PASSWORD" "$DB_NAME" \
     < database/migrations/20261005_weight_project_completion.sql
   ```
3. Reload Nginx so the new deny rules take effect:
   ```bash
   docker compose exec web nginx -s reload
   ```
4. (Recommended) Smoke test that the protected paths return `404`:
   ```sh
   for path in .env .env.example .htaccess config.php config.template.php \
     _smtp_test.php docs/requirements.md database/schema.sql \
     includes/Database.php vendor/autoload.php; do
       code=$(curl -sS -o /dev/null -w '%{http_code}' "http://localhost/$path")
       test "$code" = 404 || { echo "$path returned $code" >&2; exit 1; }
   done
   ```
5. For fresh installs, point `docker-compose.yml` at a clean checkout
   and run `docker compose up -d`; the installer is reachable at
   `http://localhost:${APP_PORT}/install.php`.

---

## v1.1.1 — 2026-10-04

This patch release fixes the profile page layout and brings it in line with
the rest of the internal console.

### Improvements

- Restored the logged-in navigation on the profile page.
- Applied the shared page container, heading, and responsive spacing styles
  used by the other internal pages.

No database migration is required.

## v1.1.0 — 2026-10-03

This release introduces a public client portal with shareable read-only project
views, full English / Simplified Chinese localization of that portal, the
canonical Docker compose stack, and a number of bug fixes.

### Highlights

- **Client share portal** (entry at `/`, dashboard at `/client/dashboard.php`)
  - New public, unauthenticated entry point. Anyone holding a project's
    `share_code` (a 5-5-5 alphanumeric code such as `SZ9GM-K2S3C-AWU6P`) can
    see a read-only snapshot of the project.
  - Landing page at `/` (was `/client/`) with auto-formatting input —
    uppercase and dashes inserted automatically as the user types.
    `/client/` 302-redirects to `/` while preserving the query string.
  - Read-only dashboard (`client/dashboard.php`) showing:
    - Project info: name, description, status, manager, start / expected /
      completion dates.
    - Overall completion (average of top-level task percentages).
    - Project scope (one bullet per line).
    - Budget in the project's chosen currency (THB, USD, CNY, JPY, SGD, EUR).
    - Recursive task tree with status, due date, completion %, and responsible
      person for every task and subtask.
  - Share codes are generated with `generateShareCode()` (excludes
    confusable `0/O/1/I/L`) and rendered into the share URL by
    `buildClientShareUrl()`.
- **Localization (ENG / 中文) for the client portal**
  - `client/lang.php` — translation catalog and helpers (`t()`, `t_status()`,
    `client_current_lang()`, `client_set_lang_cookie()`,
    `client_lang_url()`, `client_other_langs()`).
  - Locale resolution order: `?lang=` query parameter → `client_lang`
    cookie → `Accept-Language` header → `en` default.
  - Choice persists in a 1-year, root-path cookie.
  - A language switcher is rendered on both the landing page (centered, at
    the bottom of the card) and the dashboard (inline in the header).
- **Docker-first installation**
  - `docker-compose.yml`, `Dockerfile`, and `docker/entrypoint.sh` for a
    reproducible three-container stack: `pmp-app` (php-fpm 8.3),
    `pmp-web` (nginx), `pmp-db` (MariaDB 10.11).
  - `config.template.php` and `.env.example` document all configuration
    knobs (`APP_NAME`, `APP_PORT`, `APP_URL`, `DB_*`).
- **Database migrations** (`database/migrations/`)
  - New `20261003_add_contacts_phone.sql` adds the `phone` column to the
    `contacts` table. Idempotent — safe to re-run.
  - `database/schema.sql` updated so fresh installs include the same
    columns and the v1.1 client-share columns (`projects.scope`,
    `projects.budget_amount`, `projects.currency`, `projects.share_code`,
    `projects.expected_completion_date`).

### Bug fixes

- **`contacts.phone` column missing — task create / view crashed (issue-001)**
  - `task_detail.php` and `task_edit.php` referenced `c.phone` in joins
    against `contacts`, but the column had never been added to the schema.
  - Fixed by the new `20261003_add_contacts_phone.sql` migration.
  - Symptom: `Fatal error: Uncaught Exception: Database query failed … on
    dashboard click → Tasks → a task → create / view`.
- **Contacts page direct access (issue-002)**
  - `Dashboard → Contacts` link now resolves cleanly via the column added
    above.
  - Search query remains sensitive to other missing columns
    (`company`, `position`, `address`) — see *Known gaps* below.

### Improvements

- `client/index.php` and `client/dashboard.php` use `e()` (the project's
  shared HTML-escape helper) on every translated string and dynamic value.
- `includes/functions.php` gained `generateShareCode()` and
  `buildClientShareUrl()` helpers used by the new portal.
- `formatMoney()` now handles JPY (no decimals) correctly.

### Known gaps (not fixed in v1.1.0, scheduled for v1.2)

- `contacts` table is still missing `company`, `position`, `address` columns
  referenced by `contact_edit.php` (write path) and `contacts.php?search=…`
  (search path). Direct visits to `/contacts.php` work; search and create
  fail until those columns are added. *(Not resolved in v1.2.0 — still
  tracked for a follow-up release.)*
- The auto-generated subtask trigger (defined in
  `database/subtask_functions.sql`) is rejected by MariaDB ≥ 10.5 because
  stored functions may not be recursive. Subtasks therefore do not
  auto-roll-up completion percentages; managers must update subtasks
  manually. A migration to replace the trigger with application-level
  rollups is tracked for v1.2. *(Resolved in v1.2.0 — application-level
  rollups are used everywhere; the trigger file is kept for reference
  only.)*
- The internal app (`/`, `/projects.php`, `/tasks.php`, …) is **English
  only** in this release. v1.2 will extend the `lang.php` catalog to the
  internal surface as well. *(Not resolved in v1.2.0; the client portal
  remains the only fully localized surface.)*

### Upgrade notes

- Pull the latest code, then re-run any database migrations:
  ```bash
  docker compose exec db mariadb -udbuser -p"$DB_PASSWORD" dbname \
    < database/migrations/20261003_add_contacts_phone.sql
  ```
  (The script is idempotent — re-running it on an already-migrated
  database is a no-op.)
- For fresh installs, just point `docker-compose.yml` at a clean checkout
  and run `docker compose up -d`. The installer is reachable at
  `http://localhost:${APP_PORT}/install.php`.

---

## v1.0.0 — Initial release

- Internal project, task, contact, and user management.
- Login / logout / password reset flows.
- Email service via PHPMailer (SMTP).

---

## Release process (issue-028)

Every release — from the smallest patch to a major bump — follows the
checklist in [docs/RELEASE_PROCESS.md](docs/RELEASE_PROCESS.md). The
checklist is the contract enforced by the layer-1 drift-guard assertion in
`database/about_popup_test.php`: if any of the four sites
(`config.php`, `config.template.php`, `RELEASE.md` top entry, `README.md:3`
banner) disagree on the version string, the regression test fails with the
exact mismatch. There are no exceptions, no skip-this-one branches, and no
"we'll fix it in the next release" exits.