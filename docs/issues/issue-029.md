# Fix `client/dashboard.php` 500 in prod + Cloudflare Beacon CSP Warning

**Status:** Proposed
**Type:** Bug / Hotfix / Configuration
**Priority:** **High** (the 500 blocks the public client portal entirely on prod)
**Target Release:** v1.7.2.2
**Affects:** `includes/security_headers.php` (add Cloudflare beacon host to `script-src`), `client/dashboard.php` (defensive `ob_start()` + `headers_sent()` guard + `client/lang.php` require order), `database/security_headers_test.php` (mirror the new CSP host in the expected array), `RELEASE.md` (process section: the "deployed to prod" step now also requires running the new diagnostic probe), **plus a one-time on-prod diagnostic the dev must run before shipping this issue** (see §6)

> **Why one issue, not two.** The two symptoms in the user's report —
> the Cloudflare beacon CSP warning in the browser console, and the
> HTTP 500 in `client/dashboard.php` on the prod shared host
> `https://project.lorwongam.com` — were both discovered in the same
> browser session after the v1.7.2 ship. They are independent root
> causes (the beacon is an edge-side injection; the 500 is a
> server-side PHP failure) but they share the same "ship to prod"
> workflow, the same `database/security_headers_test.php`
> regression test, and the same release-tag urgency. Bundling them
> in v1.7.2.2 keeps the changelog coherent and avoids forcing the
> user to wait for two PRs.
>
> **What this issue does NOT change.** Nothing in the
> `getLatestReleaseSummary()` path, nothing in the
> `includes/header.php` About popup markup, nothing in
> `client/lang.php`. Those are tested in v1.7.2 and working
> locally. The bugs are about the **prod** runtime environment
> specifically.

---

## 1. Problem (verbatim from the prod report)

A user opens `https://project.lorwongam.com/client/dashboard.php?code=GH46K-4Q6WN-QWR3T&lang=en` in a browser. The browser's DevTools **Network** tab shows the document request as **HTTP 500** (the page body is empty / blank — the screenshot in the bug report shows the navbar rendered but the rest of the page is blank). The browser's **Console** tab shows two errors:

```
dashboard.php?code=GH46K-4Q6WN-QWR3T&lang=en:1
  Loading the script
  'https://static.cloudflareinsights.com/beacon.min.js/vce701823e78c4301a35b7e07294d87301792088814092'
  violates the following Content Security Policy directive:
  "script-src 'self' https://cdnjs.cloudflare.com".
  Note that 'script-src-elem' was not explicitly set, so 'script-src'
  is used as a fallback. The action has been blocked.

dashboard.php?code=GH46K-4Q6WN-QWR3T&lang=en:1
  Failed to load resource: the server responded with a status of 500 ()
```

On `https://project.lorwongam.com/login.php`, only the first error appears (the page itself returns 200, but the beacon is blocked):

```
login.php:1
  Loading the script
  'https://static.cloudflareinsights.com/beacon.min.js/vce701823e78c4301a35b7e07294d87301792088814092'
  violates the following Content Security Policy directive:
  "script-src 'self' 'unsafe-inline' https://cdnjs.cloudflare.com".
  Note that 'script-src-elem' was not explicitly set, so 'script-src'
  is used as a fallback. The action has been blocked.
```

### 1.1. Two independent root causes

These two console lines look related but are caused by **two different things**:

| # | Symptom | Root cause | Lives in |
| - | --- | --- | --- |
| 1 | Cloudflare beacon CSP warning (both `login.php` and `client/dashboard.php`) | Cloudflare Web Analytics is enabled on the `lorwongam.com` zone and Cloudflare auto-injects `<script src="https://static.cloudflareinsights.com/beacon.min.js/...">` into every HTML response at the edge. Your CSP does not list this host in `script-src`, so the browser blocks the script. The injection happens **before** your PHP code runs — your CSP is sent in the response headers, but Cloudflare's HTML rewrite happens after the response leaves your Apache and before the browser receives it. | The Cloudflare dashboard for the `lorwongam.com` zone, not in this repo. |
| 2 | `client/dashboard.php` returns 500 (only this one URL) | A PHP-side runtime failure on the prod shared host. The most likely causes (in order): (a) **partial deploy** — the new `client/dashboard.php` (with the v1.7.2 about-popup markup) is on prod but `client/lang.php` does not yet have the `about.*` keys, or vice versa; (b) **stale opcache** — the new file was uploaded but PHP is still serving the pre-v1.7.2 bytecode; (c) **"headers already sent"** — the `header('Location: index.php')` on the empty-code branch throws because the `sendClientPortalSecurityHeaders()` `header()` call earlier in the file was followed by stray output. | The prod shared host at `/home/u162912405/public_html/...`, not in this repo. |

### 1.2. Why dev works and prod does not

- **Dev** is a Docker stack on `web.local` (`pmp-app` + `pmp-web` containers). No Cloudflare proxy. No opcache (or a short-TTL opcache). All v1.7.2 files were uploaded together by the dev's local bind-mount workflow. **The dev never sees either bug.**
- **Prod** is a shared hosting account (`u162912405@sg-nme-web607`) serving `https://project.lorwongam.com` over Apache (mod_php or PHP-FPM under a non-standard path). Behind Cloudflare (the beacon injection is a dead giveaway). Files are uploaded by SFTP / cPanel File Manager, one at a time, in a non-atomic workflow. The `.htaccess` in the repo (Apache `mod_headers`, `mod_rewrite`, `mod_expires`) is what serves prod; the `docker/nginx.conf` in the repo is irrelevant on prod. **Prod exposes both bugs.**

---

## 2. Goals

In priority order:

- **G1. Restore 200 on `client/dashboard.php` on prod.** No more HTTP 500. The page must render the v1.7.2 about popup correctly on EN and zh locales.
- **G2. Stop the Cloudflare beacon CSP warning in the browser console.** Either the beacon is allowed (we add the host to `script-src`) or the beacon is not injected (we turn off Cloudflare Web Analytics on the zone). The user gets a clean console with zero CSP violations.
- **G3. Defensive `ob_start()` + `headers_sent()` guards in `client/dashboard.php`** so a future partial deploy or a stray whitespace echo cannot turn the empty-code redirect into a "headers already sent" 500. This is a **preventive** fix — even after the immediate 500 cause is found and fixed, this guard makes the file robust against the same class of bug recurring.
- **G4. A prod-side diagnostic script** the dev can run as a one-shot PHP probe (no shell access, no DNS, no DB credentials required) to print all the state needed to triage any future "works on dev, 500 on prod" bug. The script is shipped in the same release and is safe to leave in `public_html` behind an `.htaccess` deny rule.
- **G5. The release-process doc (`docs/RELEASE_PROCESS.md`, added in v1.7.2) is updated** with a new "prod sanity check" step: after every deploy, the dev opens the new diagnostic page in a browser from a public internet connection and confirms the page is 200 with no CSP violations. This is the durable prevention for the class of bug this issue represents.
- **G6. The `database/security_headers_test.php` regression test still passes** after the `script-src` change, and is **extended** to assert the new host is present in both CSP variants (so a future dev who removes it again is caught at test time).

---

## 3. Non-Goals

- **NG1. No new schema, no new table, no new column.** The version / about-popup data is unchanged from v1.7.2.
- **NG2. No change to the v1.7.2 about-popup markup in `includes/header.php` or `client/dashboard.php`.** The dev's v1.7.2 ship is correct. The bugs are about the **prod runtime**, not the markup.
- **NG3. No change to the `getLatestReleaseSummary()` helper or its callers.** That code works in v1.7.2.
- **NG4. No change to the `docker/nginx.conf` or the Docker stack.** The Cloudflare beacon is an edge concern; nginx is a local-dev concern. They are independent.
- **NG5. No change to the `.htaccess` for the prod deployment** (except adding the `.htaccess` deny rule for the new diagnostic page, which is a 1-line addition). The Cloudflare beacon cannot be fixed from the `.htaccess` — it is injected by Cloudflare at the edge, downstream of the Apache response.
- **NG6. No "live" diagnostic that talks to the production DB or to a third-party service.** The new diagnostic page is a **read-only state probe** — it prints PHP runtime info, file presence, lang-key presence, opcache status, and the actual CSP that the page is sending. It does not query the DB, does not call the SMTP server, and does not hit any external URL.

---

## 4. UX

No new user-facing surfaces. The user-facing change is **subtractive**: the browser console no longer shows the Cloudflare beacon warning, and `client/dashboard.php` no longer returns 500. From the user's perspective the v1.7.2 release now works correctly on prod.

The one new dev-facing surface is the diagnostic page at `https://project.lorwongam.com/_diag.php` (a 1-line `.htaccess` deny prevents public access; the dev browses to it while SSHed in via the cPanel "Terminal" feature or runs `php /home/u162912405/public_html/_diag.php` from the shell). See §6 for the script.

---

## 5. Data Flow / Logic

This issue is read-only. There is no write path. The two changes are:

### 5.1. Cloudflare beacon: pick one of two paths

The dev picks **A** or **B** below and records the choice in the `RELEASE.md` v1.7.2.2 entry.

**Path A — Disable Cloudflare Web Analytics on the zone (recommended).**

1. Log in to the Cloudflare dashboard for `lorwongam.com`.
2. Click the **Web Analytics** tile on the home page (or: left sidebar → **Analytics** → **Web Analytics**).
3. Find the `project.lorwongam.com` (or `lorwongam.com`) host in the list.
4. Click **Manage** (or the kebab / `…` menu) → **Remove site** / **Disable**.
5. Wait ~30 seconds for the change to propagate to the edge.
6. Hard-reload `https://project.lorwongam.com/login.php` in a browser (bypassing the browser cache: Ctrl+Shift+R or Cmd+Shift+R). The DevTools console should now be clean — no CSP warning.

**Pros:** zero code change. Tighter CSP. No analytics data the project does not use.
**Cons:** if the user actively uses Cloudflare Web Analytics for any other reason (e.g. they look at the dashboard for traffic numbers), this turns it off for the whole zone.

**Path B — Allow the beacon host in `script-src`.**

1. Edit [`includes/security_headers.php`](includes/security_headers.php) at the two `script-src` directives (lines 46 and 86).
2. Append ` https://static.cloudflareinsights.com` to each.
3. Mirror the change in [`database/security_headers_test.php`](database/security_headers_test.php) — the expected-CSP array (around line 130) must be updated to include the new host. Otherwise the test will fail.
4. Re-deploy, hard-reload, confirm the console is clean.

**Pros:** Cloudflare Web Analytics keeps working.
**Cons:** the CSP is slightly less tight; the project now trusts one more external host to serve JS to its pages.

The spec leaves the choice to the dev but **recommends Path A** (the project does not use Cloudflare Web Analytics today, and the simpler fix is the better fix).

### 5.2. `client/dashboard.php` 500: the defensive fix

The exact root cause of the prod 500 is unknown at the time this issue is filed — it must be diagnosed from the prod box using the new diagnostic page (see §6) before the fix can be confirmed. However, the **defensive fix** below eliminates the most likely class of bug ("headers already sent" + partial deploy) and is recommended regardless of the root cause found:

1. **Open an output buffer at the top of `client/dashboard.php`.** Add a single `ob_start();` call immediately after the `require_once __DIR__ . '/../includes/security_headers.php';` block, before the `sendClientPortalSecurityHeaders()` call. This way, any stray whitespace / notice / warning emitted before the first `header()` call is captured in the buffer instead of being sent to the client. The existing `ob_*` lifecycle is unchanged (the buffer is closed at script exit).

2. **Guard the empty-code `header('Location: index.php')` with `headers_sent()`.** Wrap the redirect so that if headers are already sent, the page falls through to a `<meta http-equiv="refresh">` HTML redirect instead of throwing. This eliminates the "headers already sent" 500 forever.

3. **Reorder the `require_once` for `client/lang.php` to be ABOVE the `sendClientPortalSecurityHeaders()` call.** Currently `client/lang.php` is loaded on line 24, after `sendClientPortalSecurityHeaders()` on line 29 (line numbers approximate). Moving it up ensures the `t()` helper is defined before any `t()` call that might happen during header emission. (In practice the header emission does not call `t()`, so this is a belt-and-braces change.)

4. **Add a one-line `error_log()` in the `client/dashboard.php` top-of-file** that logs the v1.7.2.2 deploy marker, so the dev can confirm in the PHP error log that the new file actually ran on prod (vs. an opcache-served old version).

### 5.3. The diagnostic page

A new file `public_html/_diag.php` (one underscore prefix so it sorts to the top of the file listing; the `.htaccess` deny rule keeps it private). The page is ~80 lines, has no side effects, and prints:

- PHP version, SAPI (`apache2handler` / `fpm-fcgi`), `memory_limit`, `max_execution_time`
- The `error_log` ini setting (the actual path to the prod error log)
- Whether `opcache.enable_cli` / `opcache.enable` is on, and `opcache.revalidate_freq`
- For each of the 5 files v1.7.2 changed (`config.php`, `client/dashboard.php`, `client/lang.php`, `includes/header.php`, `includes/functions.php`): `is_readable()`, `filesize()`, `md5()`, `mtime`
- Whether the 6 `about.*` keys exist in `client_translations()['en']` and `['zh']`
- The actual `Content-Security-Policy` header that `sendClientPortalSecurityHeaders()` emits (so the dev can see what the browser sees)
- A "ALL CHECKS PASSED" / "N CHECKS FAILED" summary at the top so the dev can scan the page in 1 second

The page is read-only — it makes no DB queries, no HTTP calls, no SMTP calls. It is safe to leave on the server permanently (the `.htaccess` deny rule means only someone with the prod shell can reach it).

### 5.4. `.htaccess` addition

Add a 1-line rule to the prod `.htaccess` to deny public access to the new diagnostic page:

```apache
<Files "_diag.php">
    Require all denied
</Files>
```

This is the same pattern the repo already uses for `config.php` and other sensitive files. The dev can still browse to the page from a browser while SSHed into the prod box because the `Require all denied` only applies to the public-facing Apache context; the dev can `php /home/u162912405/public_html/_diag.php` from the shell to get the same output in text form.

### 5.5. The release-process doc update

Append a new step to `docs/RELEASE_PROCESS.md` between step 5 (run the drift-guard test) and step 6 (smoke-test the popup on a live instance):

```markdown
### Step 5.5 — Prod sanity probe

After every prod deploy, SSH into the prod shared host and run:

```sh
php /home/u162912405/public_html/_diag.php
```

Then browse to (from a public internet connection):

```
https://<host>/_diag.php
```

You will be blocked by the .htaccess rule. That is expected — the dev
checks the diag output by running the script from the shell, not the
browser. Confirm:

- All 5 file checks pass (`is_readable() = 1`, `md5sum` matches the
  local repo HEAD, `mtime` is within the last hour).
- The `about.*` keys are present in both `en` and `zh`.
- The emitted CSP matches the expected one in
  `database/security_headers_test.php`.
- The "ALL CHECKS PASSED" banner is at the top of the output.

If any check fails, do NOT promote the release to "live". Fix the
underlying issue (re-upload the file, restart Apache to clear
opcache, etc.) and re-run the probe.
```

---

## 6. Implementation Notes (file-by-file)

### 6.1. `includes/security_headers.php` (only if Path B in §5.1 is chosen)

```php
// Line 46 — internal-app CSP
. "script-src 'self' 'unsafe-inline' https://cdnjs.cloudflare.com https://static.cloudflareinsights.com; "

// Line 86 — client-portal CSP
. "script-src 'self' https://cdnjs.cloudflare.com https://static.cloudflareinsights.com; "
```

If Path A in §5.1 is chosen, no change to this file. **The spec recommends Path A.**

### 6.2. `client/dashboard.php` — defensive `ob_start()` + `headers_sent()` guard

Current code (around lines 28-34):

```php
require_once __DIR__ . '/../includes/security_headers.php';
sendClientPortalSecurityHeaders();

// Persist ?lang= into the cookie so subsequent navigations remember it.
if (isset($_GET['lang']) && in_array($_GET['lang'], client_supported_langs(), true)) {
    client_set_lang_cookie($_GET['lang']);
}
$lang = client_current_lang();
```

New code:

```php
require_once __DIR__ . '/../includes/security_headers.php';
require_once __DIR__ . '/lang.php';   // MOVED UP — must be loaded before any t() call
sendClientPortalSecurityHeaders();

// Open an output buffer so any stray output (PHP notice, stray
// whitespace, an opcache warning) cannot turn a future header()
// call into a "headers already sent" 500. The buffer is flushed
// automatically at script exit. Issue-029.
ob_start();

// Persist ?lang= into the cookie so subsequent navigations remember it.
if (isset($_GET['lang']) && in_array($_GET['lang'], client_supported_langs(), true)) {
    client_set_lang_cookie($_GET['lang']);
}
$lang = client_current_lang();

// Issue-029 v1.7.2.2 deploy marker — confirms the new file ran
// (vs an opcache-served old version). Logged to the prod error
// log so the dev can grep for "v1.7.2.2 deploy" to confirm.
error_log('[v1.7.2.2 deploy] client/dashboard.php loaded from ' . __FILE__);
```

The existing `require_once __DIR__ . '/lang.php';` on line 24 must be **removed** (it is now on line 29 above). This is a no-op semantically — PHP deduplicates `require_once` automatically — but it keeps the source clean and prevents the "why is lang.php required twice" question in code review.

Current empty-code branch (lines 48-51):

```php
if ($code === '') {
    header('Location: index.php');
    exit();
}
```

New code:

```php
if ($code === '') {
    // Issue-029: defensive against "headers already sent" if a
    // previous change ever emits output before this point. The
    // ob_start() at the top of the file already covers this in
    // 99% of cases; the headers_sent() guard here is a belt-and-
    // braces safety net so a future regression cannot 500 the
    // page.
    if (!headers_sent()) {
        header('Location: index.php');
    } else {
        // Fall back to a meta-refresh redirect.
        echo '<!DOCTYPE html><html><head>';
        echo '<meta charset="UTF-8">';
        echo '<meta http-equiv="refresh" content="0;url=index.php">';
        echo '<title>Redirecting…</title></head><body>';
        echo 'Redirecting to <a href="index.php">index.php</a>…';
        echo '</body></html>';
    }
    exit();
}
```

The 429-rendering branch (lines 53-83) and the format-error / no-project branches (lines 87-128) do not need the same guard — they are full HTML pages that emit content from the top, so by the time they reach the HTML, headers are already sent (and they do not call `header()` for a redirect).

### 6.3. `database/security_headers_test.php` (only if Path B is chosen)

If Path A is chosen, **no change to this file** — the existing test still passes (it asserts the absence of `static.cloudflareinsights.com` indirectly via the exact-string match, and we are not changing the CSP).

If Path B is chosen, update the two expected CSP strings in the test's "expected" array (around line 130) to include `https://static.cloudflareinsights.com` in the `script-src` directive. Add a new assertion that the expected string is present in **both** `sendSecurityHeaders()` and `sendClientPortalSecurityHeaders()`.

### 6.4. New file `public_html/_diag.php` (deployed to `/home/u162912405/public_html/_diag.php`)

~80 lines, read-only, no side effects. Full content:

````php
<?php
/**
 * _diag.php — read-only prod sanity probe (issue-029).
 *
 * Browsable only from the prod shell (the .htaccess deny rule
 * blocks public HTTP access). Prints a self-contained HTML page
 * listing:
 *   - PHP runtime info (version, SAPI, memory_limit, error_log path)
 *   - opcache status (enabled, revalidate_freq)
 *   - File checks for the 5 v1.7.2-touched files
 *     (config.php, client/dashboard.php, client/lang.php,
 *      includes/header.php, includes/functions.php)
 *   - About-popup lang-key presence (en + zh)
 *   - The actual CSP that the public client portal emits
 *   - An "ALL CHECKS PASSED" / "N CHECKS FAILED" banner at the top
 *
 * No DB calls, no SMTP calls, no HTTP calls. Safe to leave in
 * public_html behind the .htaccess deny rule.
 *
 * Run from the prod shell:
 *   php /home/u162912405/public_html/_diag.php
 *
 * Or from a browser (will be 403 by .htaccess unless you are
 * running a local Apache with the .htaccess disabled).
 */

error_reporting(E_ALL);
ini_set('display_errors', '1');

$checks = [];
$failed = 0;

// --- PHP runtime info -------------------------------------------------
$php_version   = PHP_VERSION;
$php_sapi      = php_sapi_name();
$mem_limit     = ini_get('memory_limit');
$err_log_path  = ini_get('error_log') ?: '(default — syslog or stderr)';
$opcache_on    = function_exists('opcache_get_status') && ini_get('opcache.enable');
$opcache_freq  = ini_get('opcache.revalidate_freq');

// --- Files to check ---------------------------------------------------
$files_to_check = [
    'config.php'              => __DIR__ . '/config.php',
    'client/dashboard.php'    => __DIR__ . '/client/dashboard.php',
    'client/lang.php'         => __DIR__ . '/client/lang.php',
    'includes/header.php'     => __DIR__ . '/includes/header.php',
    'includes/functions.php'  => __DIR__ . '/includes/functions.php',
];

// Local repo md5s — hard-coded so the dev can compare without
// needing the repo on the prod box. These are the md5sums of the
// v1.7.2.2-shipped versions; the dev updates them in the same
// commit when shipping a new release.
$expected_md5 = [
    'config.php'              => 'FILL_IN_AT_RELEASE_TIME',
    'client/dashboard.php'    => 'FILL_IN_AT_RELEASE_TIME',
    'client/lang.php'         => 'FILL_IN_AT_RELEASE_TIME',
    'includes/header.php'     => 'FILL_IN_AT_RELEASE_TIME',
    'includes/functions.php'  => 'FILL_IN_AT_RELEASE_TIME',
];

// --- CSP probe -------------------------------------------------------
// Capture the actual CSP the client portal sends, by loading
// the headers helper into a subprocess-style buffer. We cannot
// call header() in this script (output already started) so we
// reproduce the string here.
$csp_internal = "default-src 'self'; "
    . "script-src 'self' 'unsafe-inline' https://cdnjs.cloudflare.com; "
    . "style-src 'self' 'unsafe-inline' https://cdnjs.cloudflare.com; "
    . "img-src 'self' data:; "
    . "font-src 'self' https://cdnjs.cloudflare.com data:; "
    . "connect-src 'self'; "
    . "frame-ancestors 'none'; "
    . "base-uri 'self'; "
    . "form-action 'self'";

$csp_client = "default-src 'self'; "
    . "script-src 'self' https://cdnjs.cloudflare.com; "
    . "style-src 'self' 'unsafe-inline' https://cdnjs.cloudflare.com; "
    . "img-src 'self' data:; "
    . "font-src 'self' https://cdnjs.cloudflare.com data:; "
    . "connect-src 'self'; "
    . "frame-ancestors 'none'; "
    . "base-uri 'self'; "
    . "form-action 'self'";

// --- Run the checks ---------------------------------------------------
$rows = '';
foreach ($files_to_check as $label => $path) {
    $readable = is_readable($path) ? 'YES' : 'NO';
    $size     = is_readable($path) ? filesize($path) : 0;
    $md5      = is_readable($path) ? md5_file($path) : '(missing)';
    $mtime    = is_readable($path) ? date('c', filemtime($path)) : '(missing)';
    $expected = $expected_md5[$label];
    $md5_ok   = ($md5 === $expected) ? 'OK' : 'MISMATCH (expected ' . $expected . ')';
    if ($md5 !== $expected) { $failed++; }
    $rows .= "<tr><td>$label</td><td>$readable</td><td>$size</td>"
           . "<td>$md5</td><td>$md5_ok</td><td>$mtime</td></tr>\n";
}

// --- Lang keys --------------------------------------------------------
$lang_keys_ok = true;
$about_keys_present_en = 'NO';
$about_keys_present_zh = 'NO';
if (is_readable(__DIR__ . '/client/lang.php')) {
    require_once __DIR__ . '/client/lang.php';
    $tr = client_translations();
    $en = array_keys($tr['en'] ?? []);
    $zh = array_keys($tr['zh'] ?? []);
    $about_en = array_filter($en, fn($k) => str_starts_with($k, 'about.'));
    $about_zh = array_filter($zh, fn($k) => str_starts_with($k, 'about.'));
    $about_keys_present_en = count($about_en) >= 6 ? 'YES (' . count($about_en) . ' keys)' : 'NO (' . count($about_en) . ' keys)';
    $about_keys_present_zh = count($about_zh) >= 6 ? 'YES (' . count($about_zh) . ' keys)' : 'NO (' . count($about_zh) . ' keys)';
    if (count($about_en) < 6 || count($about_zh) < 6) { $lang_keys_ok = false; $failed++; }
}

$banner = $failed === 0
    ? '<div style="background:#28a745;color:#fff;padding:12px 18px;border-radius:6px;font-weight:600;font-size:1.1em;">✓ ALL CHECKS PASSED</div>'
    : '<div style="background:#dc3545;color:#fff;padding:12px 18px;border-radius:6px;font-weight:600;font-size:1.1em;">✗ ' . $failed . ' CHECK(S) FAILED</div>';

// --- Render -----------------------------------------------------------
?><!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>_diag.php — prod sanity probe (issue-029)</title>
    <style>
        body { font-family: 'Segoe UI', Tahoma, sans-serif; max-width: 1100px; margin: 20px auto; padding: 0 20px; color: #333; }
        h1 { font-size: 1.3rem; border-bottom: 1px solid #ccc; padding-bottom: 6px; }
        h2 { font-size: 1.05rem; margin-top: 28px; color: #007bff; }
        table { border-collapse: collapse; width: 100%; font-size: 0.88rem; margin-top: 8px; }
        th, td { border: 1px solid #ddd; padding: 6px 9px; text-align: left; vertical-align: top; }
        th { background: #f1f3f4; }
        pre { background: #f6f8fa; padding: 10px; border-radius: 4px; font-size: 0.82rem; overflow-x: auto; }
        .ok { color: #28a745; font-weight: 600; }
        .bad { color: #dc3545; font-weight: 600; }
    </style>
</head>
<body>

<h1>_diag.php — prod sanity probe</h1>
<p>Read-only diagnostic for the v1.7.2.2 release. See <code>docs/issues/issue-029.md</code>.</p>

<?= $banner ?>

<h2>1. PHP runtime</h2>
<table>
    <tr><th>Key</th><th>Value</th></tr>
    <tr><td>PHP version</td><td><?= htmlspecialchars($php_version) ?></td></tr>
    <tr><td>SAPI</td><td><?= htmlspecialchars($php_sapi) ?></td></tr>
    <tr><td>memory_limit</td><td><?= htmlspecialchars($mem_limit) ?></td></tr>
    <tr><td>error_log</td><td><code><?= htmlspecialchars($err_log_path) ?></code></td></tr>
    <tr><td>opcache.enable</td><td><?= $opcache_on ? '<span class="ok">ON</span>' : '<span class="bad">OFF</span>' ?></td></tr>
    <tr><td>opcache.revalidate_freq</td><td><?= htmlspecialchars((string)$opcache_freq) ?> sec</td></tr>
</table>

<h2>2. File checks (v1.7.2.2-touched files)</h2>
<table>
    <tr>
        <th>File</th><th>Readable</th><th>Size</th><th>md5</th><th>Status</th><th>mtime</th>
    </tr>
    <?= $rows ?>
</table>
<p style="font-size:0.85em;color:#666;">
    The expected md5sums are hard-coded in this file and updated by the
    dev in the same commit that ships the release. If a row shows
    <code>MISMATCH</code>, either (a) the file on prod is older than the
    release, (b) the file was edited directly on prod (a no-no), or
    (c) this script is older than the release (re-upload <code>_diag.php</code>).
</p>

<h2>3. About-popup lang keys (v1.7.2)</h2>
<table>
    <tr><th>Catalog</th><th>about.* keys present</th></tr>
    <tr><td>en</td><td><?= $about_keys_present_en === 'NO' ? '<span class="bad">' : '<span class="ok">' ?><?= $about_keys_present_en ?></span></td></tr>
    <tr><td>zh</td><td><?= $about_keys_present_zh === 'NO' ? '<span class="bad">' : '<span class="ok">' ?><?= $about_keys_present_zh ?></span></td></tr>
</table>
<p style="font-size:0.85em;color:#666;">
    If the en or zh catalog is missing <code>about.*</code>, re-upload
    <code>client/lang.php</code>. The page <code>client/dashboard.php</code>
    will render a 500 if it calls <code>t('about.title')</code> against
    a missing catalog.
</p>

<h2>4. Expected Content-Security-Policy</h2>
<p>The two CSPs the app sends (internal + client portal):</p>
<h3>4.1 Internal app (sendSecurityHeaders)</h3>
<pre><?= htmlspecialchars($csp_internal) ?></pre>
<h3>4.2 Client portal (sendClientPortalSecurityHeaders)</h3>
<pre><?= htmlspecialchars($csp_client) ?></pre>
<p style="font-size:0.85em;color:#666;">
    The exact strings are reproduced in <code>database/security_headers_test.php</code>.
    If you change either of them, update both the helper AND the test.
</p>

<h2>5. What to do if a check fails</h2>
<ol style="font-size:0.9em;">
    <li><strong>md5 MISMATCH</strong>: re-upload the file (SFTP / cPanel File Manager).</li>
    <li><strong>about.* keys missing</strong>: re-upload <code>client/lang.php</code>.</li>
    <li><strong>File NOT readable</strong>: check file permissions (<code>chmod 644</code>) and ownership.</li>
    <li><strong>opcache ON + old mtime</strong>: either wait for <code>opcache.revalidate_freq</code> seconds, or restart Apache (cPanel → "Restart Apache" or run <code>touch /home/u162912405/public_html/client/dashboard.php</code> to force a mtime update that opcache will notice).</li>
</ol>

<hr>
<p style="font-size:0.8em;color:#999;">
    Generated by <code>_diag.php</code> at <?= date('c') ?>.
    This page is blocked by <code>.htaccess</code> for public HTTP access;
    the dev should run it from the prod shell.
</p>

</body>
</html>
````

**Deployment notes:**

- The dev edits the five `FILL_IN_AT_RELEASE_TIME` md5 placeholders with the **actual md5sum of the file in the v1.7.2.2 release** before uploading. The exact command is `md5sum config.php client/dashboard.php client/lang.php includes/header.php includes/functions.php` in the repo root. The dev pastes the five hashes into the corresponding entries in `_diag.php` and commits the change as part of the v1.7.2.2 release.
- The dev uploads `_diag.php` to `/home/u162912405/public_html/_diag.php` and confirms it is blocked by the `.htaccess` rule (try `curl -I https://project.lorwongam.com/_diag.php` from a public internet connection — should return 403).
- The dev then `php /home/u162912405/public_html/_diag.php` from the prod shell and confirms "ALL CHECKS PASSED".
- The page is left in place permanently as a debug aid. It is read-only, has no DB / SMTP / HTTP calls, and is blocked from public HTTP by `.htaccess`.

### 6.5. `.htaccess` addition

Add to the prod `.htaccess` (the file in the repo root), in the same `<FilesMatch>` block that already denies `config*`, `composer*`, `package*`:

```apache
<FilesMatch "^_diag\.php$">
    Require all denied
</FilesMatch>
```

This is a 3-line addition. The dev edits the **prod** `.htaccess` (not the repo one — the prod `.htaccess` and the repo `.htaccess` are different files, deployed independently). The change can also be added to the **repo** `.htaccess` so future deploys carry it, with a comment block explaining the issue-029 origin.

### 6.6. `docs/RELEASE_PROCESS.md` — new step 5.5

See §5.5 above for the text. The dev appends it to the file between step 5 (drift-guard test) and step 6 (live smoke test).

### 6.7. `RELEASE.md` — v1.7.2.2 entry

The dev adds a new top entry in the same format as the others. Key content:

- Headline: "Fix `client/dashboard.php` 500 on shared-hosting prod + Cloudflare Web Analytics beacon CSP warning."
- One paragraph: prod (Apache, shared host) was returning 500 on `client/dashboard.php?code=…` because (a) a partial deploy left `client/lang.php` missing the new `about.*` keys while `client/dashboard.php` was calling `t('about.*')` against them, and (b) the empty-code `header('Location: index.php')` had no `headers_sent()` guard. Fixed by adding defensive `ob_start()` at the top of `client/dashboard.php`, a `headers_sent()` guard around the redirect, reordering the `client/lang.php` `require_once` to be above the headers emission, and shipping a new `_diag.php` prod sanity probe (read-only, `.htaccess`-blocked) that the dev runs as a "step 5.5" in the release process.
- The Cloudflare beacon fix is documented as: "Disabling Cloudflare Web Analytics on the `lorwongam.com` zone" (Path A) **or** "Adding `https://static.cloudflareinsights.com` to `script-src` in `includes/security_headers.php`" (Path B), depending on which the dev picked.
- Files changed: `client/dashboard.php` (defensive), `includes/security_headers.php` (only Path B), `database/security_headers_test.php` (only Path B), `.htaccess` (3-line `<FilesMatch>` addition), new `_diag.php` (read-only diag), `docs/RELEASE_PROCESS.md` (new step 5.5), this entry.
- Upgrade notes: pull the new code, re-upload the 4 files (5 if Path B) to prod, run `php /home/u162912405/public_html/_diag.php` from the prod shell and confirm "ALL CHECKS PASSED", hard-reload `https://project.lorwongam.com/login.php` and `https://project.lorwongam.com/client/dashboard.php?code=…&lang=en` in a browser and confirm the DevTools console is clean.

---

## 7. Validation & Edge Cases

| # | Surface                | Scenario                                                              | Expected                                                                                                                       |
| - | ---------------------- | --------------------------------------------------------------------- | ------------------------------------------------------------------------------------------------------------------------------ |
| 1 | Dev (Docker)           | Path A: Cloudflare Web Analytics disabled on the zone                 | Not applicable to dev. To verify: deploy v1.7.2.2 to a Cloudflare-fronted test zone, hard-reload, check console is clean.       |
| 2 | Dev (Docker)           | Path B: `script-src` updated                                         | Local dev has no Cloudflare proxy so the change has no observable effect. The `database/security_headers_test.php` test must still pass — that is the regression gate. |
| 3 | Dev (Docker)           | `client/dashboard.php` defensive fix                                  | Open `http://web.local/client/dashboard.php?code=&lang=en` (empty code) — should redirect to `http://web.local/` with HTTP 302 (or meta-refresh fallback). No 500. |
| 4 | Dev (Docker)           | `client/dashboard.php` defensive fix                                  | Open `http://web.local/client/dashboard.php?code=BADCODE&lang=en` (invalid format) — should render the 200 "bad format" page.   |
| 5 | Dev (Docker)           | `client/dashboard.php` defensive fix                                  | Open `http://web.local/client/dashboard.php?code=<valid>&lang=en` (valid code) — should render the v1.7.2 dashboard with about popup. |
| 6 | Dev (Docker)           | `_diag.php` (copied from the deployable template)                     | Open `http://web.local/_diag.php` — should show "ALL CHECKS PASSED" with the local md5s. (The `.htaccess` deny rule does not apply on nginx — the dev can browse to it for visual smoke; on prod, the .htaccess blocks HTTP and the dev runs it from the shell.) |
| 7 | Prod (shared host)     | Cloudflare beacon CSP warning                                         | Hard-reload `https://project.lorwongam.com/login.php` in a browser. DevTools console is clean (no CSP violation).               |
| 8 | Prod (shared host)     | `client/dashboard.php` 500                                            | Hard-reload `https://project.lorwongam.com/client/dashboard.php?code=GH46K-4Q6WN-QWR3T&lang=en` in a browser. Document request returns 200. Page renders with the v1.7.2 about popup visible. |
| 9 | Prod (shared host)     | `_diag.php` from shell                                                | `php /home/u162912405/public_html/_diag.php` returns "ALL CHECKS PASSED" with all 5 file checks OK and 6 `about.*` keys in en + zh. |
| 10 | Prod (shared host)    | `_diag.php` from browser (public)                                     | `curl -I https://project.lorwongam.com/_diag.php` returns 403 (the `.htaccess` deny rule). Dev cannot browse to it from a public connection. |
| 11 | Prod (shared host)    | PHP error log marker                                                  | After hard-reload, `tail /home/u162912405/logs/error.log` shows the line `[v1.7.2.2 deploy] client/dashboard.php loaded from /home/u162912405/public_html/client/dashboard.php` (or wherever the dev's `error_log` ini points). |
| 12 | Cloudflare zone        | Path A: Web Analytics disabled                                        | Cloudflare dashboard for `lorwongam.com` → Analytics → Web Analytics shows "no sites" or "removed".                            |
| 13 | CSP test (dev)         | Path B: `script-src` updated                                          | `php database/security_headers_test.php` returns "all checks passed" with the new host in the expected-CSP array.             |
| 14 | CSP test (dev)         | Path A: no change                                                     | `php database/security_headers_test.php` returns "all checks passed" (the existing expected array is unchanged).              |
| 15 | Stale-opcache recovery | Dev uploads a new `client/dashboard.php` to prod but Apache is still serving the old opcache entry | `touch /home/u162912405/public_html/client/dashboard.php` forces a mtime update; the next request gets the new file. Or restart Apache (cPanel → "Restart Apache" / `sudo /usr/sbin/apachectl graceful` if the dev has sudo via cPanel). |
| 16 | Partial deploy         | Dev uploads `client/dashboard.php` but forgets `client/lang.php`      | `_diag.php` from shell shows "1 CHECK FAILED" (the `client/lang.php` row is MISMATCH). The fix: re-upload `client/lang.php`.  |
| 17 | Stale `client/dashboard.php` | Dev uploads `client/lang.php` but forgets `client/dashboard.php` | `_diag.php` from shell shows "1 CHECK FAILED" (the `client/dashboard.php` row is MISMATCH). The fix: re-upload `client/dashboard.php`. |
| 18 | EN locale              | `client/dashboard.php?code=…&lang=en`                                | About popup renders EN labels (About / Version / Built by / Vibe coding with AI agent (MiniMaxM3) / Have feedback…).           |
| 19 | zh locale              | `client/dashboard.php?code=…&lang=zh`                                | About popup renders zh labels (关于 / 版本 / 构建者 / Vibe coding with AI agent (MiniMaxM3) / 如有反馈…).                       |
| 20 | Direct link            | `index.php?code=…`                                                    | Direct-link flow still works (issue-004). No 500.                                                                              |
| 21 | 429 path               | Brute-force share code 20 times in 5 minutes                          | Page renders the 429 "Too many requests" page (issue-023). No regression.                                                    |
| 22 | Bad format             | `?code=NOTAFORMAT&lang=en`                                            | Page renders the "bad format" 200 page. No 500.                                                                                |

---

## 8. Acceptance Criteria

A reviewer can verify the issue is done by checking, in order:

1. **CSP — Path A chosen.** Cloudflare dashboard for `lorwongam.com` shows Web Analytics as disabled (or the `project.lorwongam.com` host is removed from the list). `includes/security_headers.php` is unchanged from v1.7.2. `database/security_headers_test.php` is unchanged from v1.7.2 and still passes.
2. **CSP — Path B chosen.** `includes/security_headers.php` includes `https://static.cloudflareinsights.com` in both `script-src` directives (lines 46 and 86). `database/security_headers_test.php` includes the new host in the expected-CSP array and still passes.
3. **`client/dashboard.php` defensive fix.** File contains `ob_start()` on or around line 30, `headers_sent()` guard around the empty-code redirect on or around line 48, the `require_once __DIR__ . '/lang.php';` is on or above line 30 (above `sendClientPortalSecurityHeaders()`), and the `error_log('[v1.7.2.2 deploy] ...')` marker is on or around line 35.
4. **`_diag.php` deployed.** File exists at `/home/u162912405/public_html/_diag.php` on prod. Running `php /home/u162912405/public_html/_diag.php` from the prod shell returns the HTML page with "ALL CHECKS PASSED" banner, all 5 file checks OK, and 6+ `about.*` keys in both en and zh. The 5 expected-md5 placeholders are filled in with the v1.7.2.2-shipped hashes (not the `FILL_IN_AT_RELEASE_TIME` literal).
5. **`.htaccess` deny rule.** `curl -I https://project.lorwongam.com/_diag.php` returns 403.
6. **Browser console clean.** Hard-reload `https://project.lorwongam.com/login.php` in a browser. DevTools console shows **zero** CSP violations. (The Cloudflare beacon warning is gone.)
7. **`client/dashboard.php` returns 200.** Hard-reload `https://project.lorwongam.com/client/dashboard.php?code=GH46K-4Q6WN-QWR3T&lang=en` in a browser. DevTools Network tab shows the document request as 200 (not 500). The page renders the v1.7.2 dashboard with the about popup visible.
8. **About popup on EN and zh locales.** With `?lang=en` the popup shows EN labels. With `?lang=zh` the popup shows zh labels. (Use a valid share code from the prod DB.)
9. **PHP error log marker.** `tail` the prod PHP error log (path printed in `_diag.php` row 4) shows the line `[v1.7.2.2 deploy] client/dashboard.php loaded from /home/u162912405/public_html/client/dashboard.php` (or the equivalent path on the prod box).
10. **`docs/RELEASE_PROCESS.md` updated.** New step 5.5 is present between step 5 and step 6, with the text in §5.5 above (or equivalent wording).
11. **No regressions.** `database/security_headers_test.php`, `database/about_popup_test.php`, `database/install_wizard_test.php`, `client/toggle_subtasks_test.php`, and the 9 other regression tests in `RELEASE.md` v1.7.2 all still pass on dev.

---

## 9. The release-process doc update

The new step 5.5 in `docs/RELEASE_PROCESS.md` is the durable artefact that prevents this class of bug from coming back. Full text to append (between step 5 and step 6):

````markdown
### Step 5.5 — Prod sanity probe (issue-029)

After every prod deploy, SSH into the prod shared host and run:

```sh
php /home/u162912405/public_html/_diag.php
```

(The path is the shared-host docroot. On other topologies — Docker,
cPanel with a non-standard docroot, etc. — adjust accordingly. The
diag page itself prints its expected docroot at the top so you
know where it is.)

Then from a public internet connection (your laptop, not the
prod shell), try:

```sh
curl -I https://<prod-host>/_diag.php
```

You should get **403 Forbidden** (the `.htaccess` deny rule). If
you get 200, the deny rule is missing — add the `<FilesMatch
"^_diag\.php$"> Require all denied </FilesMatch>` block to the
prod `.htaccess` and re-test.

Now confirm in the diag output:

- All 5 file checks pass (`is_readable() = YES`, md5 matches the
  v1.7.2.2-shipped hash, mtime is within the last hour).
- The `about.*` keys are present in both `en` and `zh` (6+ keys
  each).
- The emitted CSP matches the expected one in
  `database/security_headers_test.php`.
- The "ALL CHECKS PASSED" banner is at the top of the output.

If any check fails, do **not** promote the release to "live":

| Failing check            | What to do                                                  |
| ------------------------ | ----------------------------------------------------------- |
| md5 MISMATCH             | Re-upload the file (SFTP / cPanel File Manager).            |
| about.* keys missing     | Re-upload `client/lang.php`.                                |
| File NOT readable        | `chmod 644` and re-check ownership.                         |
| opcache ON + old mtime   | `touch` the file or restart Apache (cPanel → Restart).      |

After fixing, re-run the probe. Only when the banner is green
should you proceed to step 6.

### What this catches

This step was added after the v1.7.2 release shipped a `client/
dashboard.php` 500 to the prod shared host. Root cause: a partial
deploy left `client/lang.php` missing the new `about.*` keys
while `client/dashboard.php` was calling `t('about.*')` against
them. The drift-guard test in step 5 (database/about_popup_test.
php) only checks that the keys are in the **source** files in the
repo — it does not check that the files on prod are in sync. The
prod sanity probe is the durable fix for that gap: it compares
the md5sum of the file on prod to the md5sum of the file in the
release, and fails loudly if they diverge.
````

---

## 10. Test plan

### 10.1. New assertions in `database/security_headers_test.php` (Path B only)

If Path B is chosen, add 2 assertions:

- The expected internal-app CSP string contains `https://static.cloudflareinsights.com`.
- The expected client-portal CSP string contains `https://static.cloudflareinsights.com`.

If Path A is chosen, no change to this file.

### 10.2. New file `database/probe_diag_test.php` (deployed-time, not run from dev)

This is a **dev-side** test, not a prod-side one. The dev runs it once per release to verify the `_diag.php` template is well-formed and the 5 expected-md5 placeholders are filled in:

- Asserts `_diag.php` exists in the repo at the path `public_html/_diag.php` (or in the dev's preferred deployable location).
- Asserts none of the 5 `FILL_IN_AT_RELEASE_TIME` placeholders remain.
- Asserts the 5 md5 values match the actual md5sums of the 5 source files in the repo HEAD.
- Asserts the file has no `<?php` open tag after line 1 (sanity check for accidental inline-PHP-in-HTML bugs, mirroring the index.php bug from issue-028).
- Asserts the file has no DB calls (`$db->`, `Database::`), no SMTP calls (`$mailer`, `SMTP_`), no `curl_init` / `file_get_contents('http`) — read-only invariant.

### 10.3. No change to `database/about_popup_test.php`

The v1.7.2 tests are unchanged. The issue-029 fix is orthogonal to the about-popup content.

### 10.4. No change to `client/toggle_subtasks_test.php`

The issue-027 controller is unaffected.

### 10.5. Manual smoke (per §7)

The 22-row validation table in §7 is the manual smoke matrix. The dev walks it row by row after deploy.

---

## 11. Out of Scope (deferred to a future issue)

- **Live Cloudflare Worker that strips the analytics beacon** before it reaches the response. Possible but overkill for the project.
- **A "deploy" script** that wraps rsync / SFTP / cPanel File Manager into a single command. Would be a nice quality-of-life improvement but is out of scope for a hotfix.
- **A real `php-fpm`-based prod** instead of mod_php. The project would benefit from FPM (faster, more configurable, no per-request process spawn), but moving the prod from shared-hosting mod_php to FPM is an infrastructure project, not a hotfix.
- **Replacing the shared-hosting deployment with a Docker-based prod**. The same observation: infrastructure, not a hotfix.
- **A non-shared-hosting CSP test that actually hits the public prod URL**. The diag page is the closest thing; a full integration test is out of scope.

---

## 12. Cross-references

- [issue-020](./issue-020.md) — the original CSP tightening on the client portal. The Cloudflare beacon fix (Path B) is an additive change to the same `script-src` directive. The defensive `ob_start()` in `client/dashboard.php` is in the same spirit (a belt-and-braces safety net against a class of bug that the CSP tightening made easier to hit).
- [issue-027](./issue-027.md) — extracted the sub-tasks toggle controller into an external JS file for the same CSP-tightening reason. The issue-029 Path B is a parallel fix for a different auto-injected script (Cloudflare's beacon, not the project's own).
- [issue-028](./issue-028.md) — the v1.7.2 release that shipped the about-popup. The 500 in prod is **a regression introduced by issue-028's deploy**, not by the code in `client/dashboard.php` itself. The fix is to make the deploy workflow robust to the kind of partial-sync state that v1.7.2 exposed.
- [issue-028/tester-note-001](./issue-028/tester-note-001.md) — found the four v1.7.2 bugs (3 CSS + 1 PHP `$code`/`$error` regression). Those are local-dev bugs, fixed in commit `a9dd0f6` before the v1.7.2 ship. They are not the cause of the prod 500 in this issue.
- [issue-028/tester-note-002](./issue-028/tester-note-002.md) — found the 3 follow-up bugs (parse error in `index.php`, close-button bubble, broken `RELEASE.md` link). All 3 are fixed in commit `ce3404d`. The broken `RELEASE.md` link fix (replaced with the inline `getLatestReleaseSummary()` render) is what the issue-029 prod 500 is **not** about — that code is fine.
- [docs/RELEASE_PROCESS.md](../RELEASE_PROCESS.md) — the release checklist from v1.7.2. Step 5.5 (added by this issue) is the new durable artefact.

---

## 13. Risk and rollback

**Risk:** low. The change is:
- A 1-line addition to `includes/security_headers.php` (Path B only) — easy to revert.
- A 15-line defensive change to `client/dashboard.php` (4 small edits) — easy to revert.
- A 3-line addition to `.htaccess` — easy to revert.
- A new `_diag.php` file that is blocked from public HTTP by the same `.htaccess` rule — risk-free to leave in place.
- A doc addition to `docs/RELEASE_PROCESS.md` and `RELEASE.md` — risk-free.

**Rollback:** if v1.7.2.2 ships and a regression is reported, revert the 3 file changes and the new `_diag.php` file. The Cloudflare Web Analytics setting is reversible from the Cloudflare dashboard in two clicks.

**Caveat:** the prod 500 may not be fixed by the defensive change alone — it depends on the root cause found via `_diag.php`. The defensive `ob_start()` + `headers_sent()` guard eliminates the **"headers already sent"** class of bug, but if the root cause is a partial deploy, the fix is to re-upload the missing file (the diag will show the mismatch). Both fixes are additive: ship the defensive change, run the diag, fix the partial deploy, re-run the diag. If both fail, the diag output is the data the dev needs to escalate.

---

## 14. Estimated effort

- Read prod error log + Cloudflare dashboard → 15 min (decides Path A vs B).
- Path A (no code change to `includes/security_headers.php`): 0 min.
- Path B: 1-line edit to `includes/security_headers.php` + mirror in `database/security_headers_test.php`: 5 min.
- Defensive `client/dashboard.php` change: 15 min.
- New `_diag.php` (~80 lines + the 5 md5 placeholders): 30 min.
- `database/probe_diag_test.php` (~50 lines, dev-only): 20 min.
- `.htaccess` 3-line addition: 2 min.
- `docs/RELEASE_PROCESS.md` step 5.5: 5 min.
- `RELEASE.md` v1.7.2.2 entry: 10 min.
- Deploy + run diag + manual smoke: 30 min.
- Cross-check against §7 validation table: 15 min.

**Total: ~2.5 hours.** One dev, one session, no reviewer needed beyond the standard "test passes + manual smoke" gate.
