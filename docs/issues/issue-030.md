# Fix Domain Root (`/`) Redirecting to `/login.php` on Prod

**Status:** Proposed
**Type:** Bug / Configuration / Hotfix
**Priority:** **Medium** (the redirect makes the public landing page unreachable on the live URL, but `index.php?code=…` direct-link flow still works, and a `?code=` email still resolves to the right page, so the bug is "viral discovery" rather than "service down")
**Target Release:** v1.7.2.3
**Affects:** `.htaccess` (defensive `DirectoryIndex`), `_diag.php` (new live-URL probe), `database/probe_diag_test.php` (2 new assertions for the probe), `docs/RELEASE_PROCESS.md` (new step 5.6 — cPanel hPanel sanity check), plus **a one-time cPanel hPanel fix the user (or the dev) must do on the prod host** (see §6.1). **No change to `index.php`, `login.php`, `client/dashboard.php`, or any other page-level PHP file.**

> **Why this is a hotfix and not part of v1.7.2.2.** The v1.7.2.2 hotfix
> (issue-029) shipped a working `client/dashboard.php` and a clean CSP
> for `https://project.lorwongam.com/`. But hitting the domain root —
> `https://project.lorwongam.com/` — now redirects to
> `https://project.lorwongam.com/login.php` instead of rendering the
> public code-entry form (`index.php`). The redirect is **not in the
> code or in the repo's `.htaccess`** — the cause is cPanel hPanel
> configuration on the prod host. This issue ships a defensive
> `.htaccess` change, an automated probe in `_diag.php` that catches
> the regression, and a release-process step that prevents the class
> of bug from coming back. v1.7.2.3 bundles these with the cPanel-side
> fix so the user can hand one issue to the dev.

---

## 1. Problem (verbatim from the user's report)

> nice work, now it works on prod, but there is still issue. the url root (/) should be the landing page for client to enter their code (in case they have code don't have links) is always redirected to /login.php which is a wrong behavior.

When a user (typically a client) visits `https://project.lorwongam.com/` in a browser, the browser is redirected to `https://project.lorwongam.com/login.php`. The user expected the **public code-entry landing page** (`index.php`, which renders the share-code input form and a small "Owner sign in" link to `/login.php`) to be served at `/`.

The user expects the flow to be:

```
Browser → https://project.lorwongam.com/
       → index.php (the public landing form)
       → "have a code? type it here. are you an owner? click 'Owner sign in'."
```

The flow the user is currently seeing:

```
Browser → https://project.lorwongam.com/
       → 301/302 redirect → https://project.lorwongam.com/login.php
       → login form (the owner login page)
```

This affects **only the URL `/`** (the domain root). All other URLs work correctly:
- `?code=GH46K-4Q6WN-QWR3T&lang=en` (direct link from email) → `index.php` validates the code → redirects to `client/dashboard.php?code=…` → 200. ✅
- `/login.php` → owner login form. ✅ (and this is the correct behaviour; the bug is the **silent redirect** from `/`, not the page itself).
- `/client/dashboard.php?code=…` → 200 (was 500 in v1.7.2, fixed in v1.7.2.2). ✅
- `/dashboard.php` (owner dashboard) → 200 (after login). ✅
- `/?lang=zh` → Chinese landing form. ✅ (when reached directly, not via the `/` redirect).

### 1.1. Why the rest of the site works and `/` doesn't

The other URLs are explicit files (`login.php`, `dashboard.php`, `client/dashboard.php`) so Apache's `mod_rewrite` (via `.htaccess` line 7) leaves them alone (the `!-f` condition is false → no rewrite). The URL `/` is **not** an explicit file — it relies on Apache's `mod_dir` (or equivalent) to resolve it via the `DirectoryIndex` list. The default `DirectoryIndex` is `index.html index.cgi index.pl index.php index.xhtml index.htm`, which would serve `index.php` correctly.

**But the prod host is cPanel on Hostinger, and cPanel has two ways to override the default `DirectoryIndex` that the `.htaccess` cannot override:**

1. **cPanel "Redirects" UI** (`hPanel → Domains → Redirects` or `hPanel → Subdomains → project.lorwongam.com → Manage → Redirects`) — lets the user set a per-domain or per-subdomain HTTP redirect. If a rule like `Source = /`, `Destination = /login.php`, `Type = Permanent (301)` exists, the browser sees the 301 first, before Apache even consults the `.htaccess`.
2. **cPanel "Index Manager"** (`hPanel → Advanced → Index Manager` or `hPanel → Files → Directory Privacy`) — lets the user override which file Apache serves when a directory is requested. If `project.lorwongam.com/` has its Index Manager set to `login.php`, then `/` → `login.php` happens before any `.htaccess` `DirectoryIndex` directive runs.

**Both of these are web-UI settings on the prod host. They are not in the repo, they are not in the `.htaccess` we ship, and the dev has no way to know they exist from the codebase alone.**

The reason this only surfaced now (and not before the v1.7.2 ship) is that **the user likely clicked the "I want a login portal" template in hPanel when the subdomain was first created** — a common Hostinger onboarding default for new subdomains. The setting has been quietly redirecting `/` to `/login.php` since day one, but the user only noticed after they were told (by issue-029) to use the URL `/` to reach the landing page.

### 1.2. Why dev works and prod does not

- **Dev** is a Docker stack on `web.local` (`pmp-app` + `pmp-web` containers). No cPanel, no hPanel, no Index Manager override. `nginx.conf` line 4 sets `index index.php index.html;` and line 90's `try_files $uri $uri/ /index.php?$query_string` resolves `/` → `index.php` correctly. The dev never sees the bug.
- **Prod** is a cPanel/Hostinger shared host (`u162912405@sg-nme-web607`) serving `https://project.lorwongam.com` over Apache. The cPanel hPanel has a setting (Redirects or Index Manager, see §1.1) that overrides the `.htaccess` `DirectoryIndex` and silently redirects `/` to `/login.php`. **Prod exposes the bug.**

---

## 2. Goals

In priority order:

- **G1. `/` serves `index.php` (the public landing form) on prod.** No more redirect to `/login.php`. Direct navigation, hard-reload, and bookmark to `https://project.lorwongam.com/` all resolve to the form. Tested in EN (`/`) and zh (`/?lang=zh`).
- **G2. `_diag.php` gets a new live-URL probe** that does `curl -sSI https://project.lorwongam.com/` and asserts: (a) the response is HTTP 200, (b) the response body contains a `<form method="POST"` element (the landing form), (c) the response is **not** a redirect to `/login.php`. If the assertion fails, the diag prints a clear actionable message: **"Likely cause: cPanel hPanel Redirects rule or Index Manager set to `login.php`. Go to hPanel → Subdomains → `project.lorwongam.com` → Manage → remove any Redirect rule, then Advanced → Index Manager → set to `index.php`."**
- **G3. `RELEASE_PROCESS.md` step 5.5 gains a new sub-step (5.6) that requires the dev to verify the cPanel hPanel settings on every prod deploy.** Concretely: after the file upload, before the "ALL CHECKS PASSED" gate, the dev opens hPanel in a browser and confirms (a) no per-subdomain Redirect rule, (b) Index Manager for `project.lorwongam.com/` is set to `index.php`. The dev pastes a screenshot of the hPanel page into the release notes. This is the durable prevention.
- **G4. A 1-line defensive `DirectoryIndex index.php` is added to the prod `.htaccess`** so that if the user later disables cPanel's Index Manager (or migrates to a host that doesn't have one), the `.htaccess` alone is sufficient to make `/` resolve to `index.php`. This is a belt-and-braces change — it does **not** fix the bug by itself, because cPanel's per-vhost Index Manager takes precedence over `.htaccess`, but it ensures the next hosting migration doesn't re-introduce the same bug.
- **G5. The `database/probe_diag_test.php` regression test is extended** with 2 new assertions: (a) the diag template (`_diag.php` source) contains the new live-URL probe section, (b) the probe asserts the response is not a redirect to `/login.php`. The dev-side test catches a future dev who removes the probe or weakens its assertion.

---

## 3. Non-Goals

- **NG1. No change to `index.php`.** The file is the correct public landing page (per issue-004). The bug is not here.
- **NG2. No change to `login.php`.** The file is the correct owner login page. The bug is not here.
- **NG3. No change to the `.htaccess` catch-all `RewriteRule`.** The rule (`RewriteCond %{REQUEST_FILENAME} !-f; RewriteCond %{REQUEST_FILENAME} !-d; RewriteRule ^(.*)$ index.php [QSA,L]`) is correct and is not the cause of the redirect.
- **NG4. No new schema, no new table, no new column.**
- **NG5. No new AJAX endpoint, no new route.** The diag probe is a `curl` inside a PHP script — no new HTTP surface.
- **NG6. No migration off shared hosting.** The cPanel-side fix is one toggle in the hPanel UI; the cost/benefit of moving the prod off cPanel is not justified by this bug.
- **NG7. No change to the Cloudflare zone.** The bug is not at the edge; Cloudflare proxies the request and gets the same 301/302 from cPanel that the browser would.

---

## 4. UX

**No new user-facing surface.** The user-facing change is **subtractive**: the wrong `/` → `/login.php` redirect is gone, and the user now sees the public landing form at `/`. From the visitor's perspective the v1.7.2.3 release is the first time the site works the way the issue-004 spec said it should.

The one new dev-facing surface is the **sub-section "Landing page (`/`)" in `_diag.php`** (between the existing "PHP runtime" / "File checks" / "About-popup lang keys" / "Expected CSP" sections and the existing "What to do if a check fails" footer). The sub-section:

- Does a `curl -sSI https://project.lorwongam.com/` from inside the script (using PHP's `curl` extension or `get_headers()` + `file_get_contents()` — the spec picks `curl` because the script may need to follow redirects, and `get_headers()` is fiddly with redirects).
- Asserts: response code is 200, body contains `<form method="POST"`, body does **not** contain `<form method="POST" action="login.php"` (a simple substring check that the form is the landing form, not the login form).
- If the body contains `<form method="POST" action="login.php"` (the owner login form), or if the response is a 301/302 to `/login.php`, the probe prints a red ✗ and the actionable hPanel message from §2 G2.
- If the body contains `<form method="POST" action=""` (the landing form, per `index.php` line 159), the probe prints a green ✓ and a one-liner "Landing form is served at `/`".

The probe is **additive** to the existing checks. It does not change the "ALL CHECKS PASSED" / "N CHECKS FAILED" banner logic. If the new probe fails, the banner flips to red and the new sub-section explains the fix.

---

## 5. Data Flow / Logic

This issue is read-only on the data side. The only "logic" is the new probe inside `_diag.php`. The 3 file changes and 1 cPanel-side change are detailed in §6.

### 5.1. Why the `.htaccess` catch-all is not the bug

The existing rule (lines 2-7 of `.htaccess`):

```apache
RewriteEngine On

# Redirect to index.php if file doesn't exist
RewriteCond %{REQUEST_FILENAME} !-f
RewriteCond %{REQUEST_FILENAME} !-d
RewriteRule ^(.*)$ index.php [QSA,L]
```

This rule says: "if the request is not a real file and not a real directory, rewrite to `index.php`." The URL `/` matches `^(.*)$`, but:
- `!-f /` is **true** (no file at `/`).
- `!-d /` is **true** (no directory at `/` — Apache's `mod_dir` hasn't run yet to resolve it to `index.php`).
- So the rule rewrites `/` → `index.php`.

**This is correct.** The rule's `!-f` check means it does NOT rewrite `/login.php` (which is a real file) — `login.php` is served directly, no rewrite. The user can verify this by checking: navigating to `/login.php` directly still works (the user said the page itself is fine; only the redirect from `/` is the problem).

### 5.2. The cPanel precedence

The Apache request lifecycle on a cPanel shared host is, in order:

1. **cPanel per-vhost config** (in `httpd.conf` for the user's account, generated by cPanel when the subdomain was created or last edited). This is what hosts any **Index Manager** or **Redirects** UI setting. It runs **before** the `.htaccess` and cannot be overridden by `.htaccess` `DirectoryIndex` or `Redirect` directives (mod_rewrite can sometimes win, but cPanel's Index Manager is processed by `mod_dir` and `mod_alias` earlier in the request lifecycle than `mod_rewrite`).
2. **`.htaccess` `DirectoryIndex`** — runs at `mod_dir` time, but cPanel's Index Manager already ran in step 1.
3. **`.htaccess` `mod_rewrite` rules** — runs after `mod_dir`.
4. **PHP-FPM / mod_php** — runs last.

So the cPanel hPanel setting wins.

### 5.3. The new `_diag.php` probe

The probe lives in `_diag.php` between the existing "About-popup lang keys" section (§2) and the "Expected CSP" section (§3). It does the following 5 sub-checks, in order, and prints a single line per sub-check:

| # | Sub-check | What it asserts | Pass condition |
| - | --- | --- | --- |
| 1 | `curl -sSI https://project.lorwongam.com/` from the dev's prod shell | The response status line is `HTTP/1.1 200 OK` (or `HTTP/2 200`, depending on Cloudflare's edge protocol). | Status code is exactly `200`. |
| 2 | Same request, follow redirects (`-L`) | The final URL is `https://project.lorwongam.com/` (no redirect happened). | The final URL equals the request URL. |
| 3 | `curl -sS https://project.lorwongam.com/` from the dev's prod shell | The response body contains the substring `<form method="POST" action=""` (the landing form per `index.php` line 159). | The substring is present. |
| 4 | Same body | The response body does **not** contain `<form method="POST" action="login.php"` (which would mean the login form was served instead of the landing form). | The substring is absent. |
| 5 | Inspect the `Location` header on sub-check 1 | The `Location` header is not `/login.php` (or any URL ending in `/login.php`). | The header is absent or points to a URL other than `/login.php`. |

If any of sub-checks 1, 3, 4, or 5 fails, the probe prints a red ✗ and a one-line fix message:

```
✗ Sub-check 1: expected HTTP 200, got HTTP 301 to /login.php
  → cPanel hPanel Redirects rule is set. Fix:
    1. hPanel → Domains → project.lorwongam.com → Manage → Redirects
    2. Delete any rule where Source = "/" and Destination contains "login.php"
    3. Re-run this probe.

✗ Sub-check 4: response body contains the OWNER login form, not the public landing form
  → cPanel hPanel Index Manager for project.lorwongam.com is set to "login.php"
    instead of "index.php". Fix:
    1. hPanel → Files → Directory Privacy (or Advanced → Index Manager)
    2. Navigate to public_html/ (the project.lorwongam.com doc root)
    3. Set the default index to "index.php" (remove "login.php" from the list)
    4. Re-run this probe.
```

The probe is **non-fatal** to the script — it logs the failure and continues to the other checks. The "ALL CHECKS PASSED" / "N CHECKS FAILED" banner is updated to include the new failures.

The probe uses `curl` (PHP's cURL extension) rather than `file_get_contents()` because:
- `file_get_contents()` does not follow redirects by default in older PHP versions; on modern PHP it does but with a `follow_location` stream context that the dev has to remember to set.
- `curl` lets the script inspect the redirect chain (`CURLINFO_REDIRECT_COUNT`, `CURLINFO_REDIRECT_URL`) which is what sub-check 2 needs.
- The script already uses `curl` for the existing security-headers test (`database/security_headers_test.php`); using it here is consistent.

### 5.4. The cPanel hPanel fix (user or dev does this in the browser)

The actual fix to the prod redirect is one of two hPanel actions, depending on which setting is causing the redirect:

**If hPanel → Subdomains → `project.lorwongam.com` → Manage → Redirects has a rule:**

1. Click the **trash** icon next to the rule.
2. Confirm the deletion in the modal.
3. Hard-reload `https://project.lorwongam.com/` in a browser. The redirect is gone. ✅

**If hPanel → Advanced → Index Manager (or Files → Directory Privacy) for `public_html/` has `login.php` in the default-index list:**

1. Open the Index Manager, navigate to `public_html/`.
2. Remove `login.php` from the "default system index files" list, OR add `index.php` to the top of the list.
3. Save. Apache reloads the vhost (1-2 seconds).
4. Hard-reload `https://project.lorwongam.com/` in a browser. The redirect is gone. ✅

If both settings exist, both must be fixed. (Most commonly only one is set; the hPanel UI is forgiving and won't allow both to apply to the same URL at the same time.)

### 5.5. The `RELEASE_PROCESS.md` step 5.6

The new step is appended to step 5.5 (the v1.7.2.2 prod sanity probe) as a sub-section. Full text is in §6.4.

---

## 6. Implementation Notes (file-by-file)

### 6.1. cPanel hPanel — the one-time prod fix (USER or DEV does this in the browser)

This is the only step that **must** be done on the prod host's web UI for the bug to be fixed. The dev cannot do this from the code alone.

**Step 1.** Log in to the Hostinger hPanel for the `lorwongam.com` account (`hpanel.hostinger.com`, the user knows the credentials).

**Step 2a.** Check **hPanel → Websites → `project.lorwongam.com` → Manage → Redirects**:
- If there is a rule with **Source = `/`**, **Destination contains `login.php`**, **Type = Permanent (301) or Temporary (302)** → **click the trash icon** to delete it.
- If there is no such rule → no fix needed in this section.

**Step 2b.** Check **hPanel → Files → Directory Privacy** (or **hPanel → Advanced → Index Manager**):
- Navigate to `public_html/` (the `project.lorwongam.com` doc root).
- If the "default system index files" list contains `login.php` and does **not** contain `index.php` → **add `index.php` to the list** (and optionally remove `login.php` from the list). Click Save.
- If `index.php` is already in the list and `login.php` is not → no fix needed in this section.

**Step 3.** Hard-reload `https://project.lorwongam.com/` in a private browser window (Ctrl+Shift+R or Cmd+Shift+R to bypass cache). The browser should now show the public landing form (the code-entry field, the "Owner sign in" link, the about popup). If it still shows the login form, one of step 2a / step 2b did not apply — re-check.

**Step 4.** Run `php /home/u162912405/public_html/_diag.php` from the prod shell. The new "Landing page (`/`)" sub-section should show a green ✓ on all 5 sub-checks, and the overall banner should be "ALL CHECKS PASSED".

**Step 5.** Paste a screenshot of the hPanel Redirects page (post-fix) and the hPanel Index Manager page (post-fix) into the v1.7.2.3 release notes on GitHub. This is the durable audit trail.

### 6.2. `.htaccess` — 1-line defensive `DirectoryIndex`

Add immediately before the existing `RewriteEngine On` line (currently line 2 of `.htaccess`):

```apache
# Issue-030 v1.7.2.3: defensive DirectoryIndex so a future
# cPanel-Index-Manager removal or a hosting migration does not
# re-introduce the / → /login.php redirect. Note: this does NOT
# override an active cPanel Index Manager (which has higher
# precedence than .htaccess); the real fix for the v1.7.2.3 bug
# is the hPanel change in §6.1. This line is belt-and-braces.
DirectoryIndex index.php index.html
```

(The `index.html` is added as a safety net — if a future dev renames `index.php` to `index.html` (e.g. for a static landing page experiment), Apache still serves it at `/`.)

The dev updates **both** the repo `.htaccess` (so future deploys carry the line) and the **prod** `.htaccess` (so the current prod benefits from the safety net immediately, even though cPanel's active setting wins). The two `.htaccess` files diverge on this one line until the next deploy cycle; after the next full deploy they re-sync.

### 6.3. `_diag.php` — new "Landing page (`/`)" sub-section

Inserted between the existing "About-popup lang keys (v1.7.2)" section and the "Expected Content-Security-Policy" section. Full source:

````php
<h2>3.5. Landing page at <code>/</code> (issue-030 v1.7.2.3)</h2>
<?php
$public_base = 'https://project.lorwongam.com';
$landing_ok  = true;
$landing_rows = '';

// Sub-check 1: HTTP 200 (no redirect) on GET /
$ch = curl_init($public_base . '/');
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HEADER         => true,
    CURLOPT_FOLLOWLOCATION => false,
    CURLOPT_NOBODY         => true,
    CURLOPT_TIMEOUT        => 10,
    CURLOPT_USERAGENT      => '_diag/1.0 (issue-030 probe)',
]);
$out = curl_exec($ch);
$code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$location = '';
if (preg_match('/^Location:\s*(.+)$/mi', $out, $m)) {
    $location = trim($m[1]);
}
curl_close($ch);

$status_ok = ($code === 200);
$landing_ok = $landing_ok && $status_ok;
$landing_rows .= probe_row(
    'Sub-check 1: GET / returns 200',
    $status_ok ? 'OK (HTTP 200)' : "FAIL (HTTP $code, Location: $location)",
    !$status_ok,
    $status_ok ? null : "The root URL is not returning 200. Likely cause: a cPanel hPanel "
        . "Redirects rule is set (hPanel -> Websites -> project.lorwongam.com -> Manage -> "
        . "Redirects), or the Index Manager is set to 'login.php' (hPanel -> Files -> "
        . "Directory Privacy, or Advanced -> Index Manager). Fix the hPanel setting and "
        . "re-run this probe. See docs/issues/issue-030.md for the step-by-step."
);

// Sub-check 2: not a redirect to /login.php
$no_login_redirect = ($location === '' || stripos($location, '/login.php') === false);
$landing_ok = $landing_ok && $no_login_redirect;
$landing_rows .= probe_row(
    'Sub-check 2: Location header is not /login.php',
    $no_login_redirect ? 'OK' : "FAIL (Location: $location)",
    !$no_login_redirect,
    $no_login_redirect ? null : "The root URL is being redirected to /login.php. Same fix as "
        . "sub-check 1: cPanel hPanel Redirects rule or Index Manager is set wrong. Delete "
        . "the Redirects rule OR set the Index Manager to 'index.php'."
);

// Sub-check 3 and 4: GET / body content
$ch = curl_init($public_base . '/');
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_FOLLOWLOCATION => true,
    CURLOPT_TIMEOUT        => 10,
    CURLOPT_USERAGENT      => '_diag/1.0 (issue-030 probe)',
]);
$body = curl_exec($ch);
$final_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$final_url  = curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
curl_close($ch);

$body_ok = ($body !== false && strpos($body, '<form method="POST" action=""') !== false);
$landing_ok = $landing_ok && $body_ok;
$landing_rows .= probe_row(
    'Sub-check 3: body contains the public landing form',
    $body_ok ? 'OK' : 'FAIL (no <form method="POST" action=""> in body)',
    !$body_ok,
    $body_ok ? null : "The root URL body does not contain the public landing form. The fix is "
        . "the same as sub-check 1: cPanel hPanel is sending the wrong page. The diag captured "
        . "the body (see the 'captured body' section below) so you can confirm what was served."
);

$login_form_absent = (strpos($body, '<form method="POST" action="login.php"') === false);
$landing_ok = $landing_ok && $login_form_absent;
$landing_rows .= probe_row(
    'Sub-check 4: body does NOT contain the owner login form',
    $login_form_absent ? 'OK' : 'FAIL (body contains <form method="POST" action="login.php">)',
    !$login_form_absent,
    $login_form_absent ? null : "The root URL is serving the owner login form instead of the "
        . "public landing form. This is the bug: cPanel hPanel Index Manager is set to "
        . "'login.php' (or a Redirects rule rewrites / -> /login.php). Fix per sub-check 1."
);

if (!$landing_ok && $body !== false) {
    $landing_rows .= '<tr><td colspan="3" style="background:#fff3cd;padding:8px;">'
        . '<strong>Captured body (first 2000 chars) for debugging:</strong><br>'
        . '<pre style="max-height:300px;overflow:auto;background:#f6f8fa;padding:10px;'
        . 'border-radius:4px;font-size:0.8rem;">'
        . htmlspecialchars(substr($body, 0, 2000))
        . '</pre></td></tr>';
}
?>
<table>
    <tr>
        <th style="width:55%;">Sub-check</th>
        <th style="width:25%;">Result</th>
        <th style="width:20%;">Status</th>
    </tr>
    <?= $landing_rows ?>
</table>
<p style="font-size:0.85em;color:#666;">
    This sub-section was added in v1.7.2.3 (issue-030) to detect the
    <code>/</code> &rarr; <code>/login.php</code> redirect that the
    v1.7.2 ship did not catch. The fix is in the cPanel hPanel UI,
    not in the codebase; see <code>docs/issues/issue-030.md &sect;6.1</code>
    for the step-by-step.
</p>
<?php if (!$landing_ok) { $failed++; } ?>
````

The probe uses a helper function `probe_row()` that the dev should add near the top of `_diag.php` (just after the `<?php` open tag, before the existing checks):

````php
function probe_row($label, $result, $failed, $help = null) {
    $status_icon = $failed ? '<span class="bad">&#10007;</span>' : '<span class="ok">&#10003;</span>';
    $row = "<tr><td>$label</td><td>" . htmlspecialchars($result) . "</td><td>$status_icon</td></tr>\n";
    if ($help) {
        $row .= '<tr><td colspan="3" style="background:#fff3cd;padding:8px;font-size:0.85em;">'
              . '<strong>How to fix:</strong> ' . htmlspecialchars($help) . '</td></tr>' . "\n";
    }
    return $row;
}
````

The `?php if (!$landing_ok) { $failed++; }` line at the end increments the global `$failed` counter so the "ALL CHECKS PASSED" / "N CHECKS FAILED" banner flips correctly. (The dev also updates the line in the existing `if ($failed === 0)` banner check to use the same counter — no change needed if the counter is already global.)

### 6.4. `docs/RELEASE_PROCESS.md` — new step 5.6

Append a new sub-section to step 5.5:

````markdown
### Step 5.6 — cPanel hPanel sanity check (issue-030 v1.7.2.3)

After the prod sanity probe (step 5.5) returns "ALL CHECKS PASSED",
open the Hostinger hPanel in a browser and verify two settings on
the `project.lorwongam.com` subdomain:

1. **hPanel → Websites → `project.lorwongam.com` → Manage → Redirects.**
   Confirm there is no rule with `Source = /` and `Destination`
   containing `login.php`. If such a rule exists, **delete it** and
   re-run the prod sanity probe.

2. **hPanel → Files → Directory Privacy** (or **hPanel → Advanced →
   Index Manager**). Navigate to `public_html/`. Confirm the default
   index list contains `index.php` and does **not** contain
   `login.php`. If it contains `login.php` instead of `index.php`,
   **add `index.php`** to the list, save, and re-run the prod
   sanity probe.

3. Hard-reload `https://project.lorwongam.com/` in a private
   browser window. The browser should now show the public landing
   form (the code-entry field, the "Owner sign in" link, the about
   popup). If it still shows the owner login form, one of the two
   hPanel settings above was not actually applied — re-check.

4. Paste a screenshot of (a) the hPanel Redirects page (post-fix)
   and (b) the hPanel Directory Privacy / Index Manager page
   (post-fix) into the v1.7.2.3 GitHub release notes. This is the
   durable audit trail that the prod was verified end-to-end on
   every deploy.

### What this catches

This step was added after the v1.7.2.2 release shipped correctly but
surfaced a fresh bug: hitting `https://project.lorwongam.com/`
redirects to `https://project.lorwongam.com/login.php` instead of
rendering the public landing form (`index.php`). The cause is NOT in
the code or the `.htaccess` — it is a cPanel hPanel setting (either
a Redirects rule or an Index Manager override on the
`project.lorwongam.com` subdomain). The dev-side test in step 5.5
(_diag.php) cannot catch this on its own, because the redirect
happens at the cPanel vhost level, before Apache even consults the
`.htaccess` or runs PHP. The hPanel sanity check is the only place
where the dev can verify the bug is not present on prod.
````

### 6.5. `database/probe_diag_test.php` — 2 new assertions

Add 2 new `ok()` calls to the existing test, in the same style as the others. Place them in the "Source-grep on `_diag.php`" section (around line 70 of the current file):

```php
// Issue-030 v1.7.2.3: the diag template must include the new landing-page probe
$diagHasLandingProbe = strpos($diagSource, 'Sub-check 1: GET / returns 200') !== false
    && strpos($diagSource, 'Sub-check 2: Location header is not /login.php') !== false
    && strpos($diagSource, 'Sub-check 3: body contains the public landing form') !== false
    && strpos($diagSource, 'Sub-check 4: body does NOT contain the owner login form') !== false;
ok('_diag.php has the 4 sub-checks of the landing-page probe (issue-030)', $diagHasLandingProbe);

$diagRejectsLoginRedirect = strpos($diagSource, "stripos(\$location, '/login.php') === false") !== false
    && strpos($diagSource, "<form method=\"POST\" action=\"login.php\"") !== false;
ok('_diag.php asserts the response is not a redirect to /login.php (issue-030)', $diagRejectsLoginRedirect);
```

Both assertions are dev-side (no live HTTP, no DB, no SMTP). They catch a future dev who:
- Removes the new probe (sub-check 1 assertion fails).
- Weakens the assertion so it no longer rejects `/login.php` redirects (sub-check 2 assertion fails).

### 6.6. `RELEASE.md` — v1.7.2.3 entry

The dev adds a new top entry in the same format as the others. Key content:

- Headline: "Fix `/` → `/login.php` silent redirect on shared-hosting prod (cPanel hPanel Index Manager / Redirects)."
- One paragraph: prod was redirecting every request for the domain root to `/login.php` because of a cPanel hPanel setting (either a Redirects UI rule or an Index Manager override on the `project.lorwongam.com` subdomain). Fixed by removing the Redirects rule (or adding `index.php` to the Index Manager default list) on the prod host's hPanel UI, and shipping a defensive `DirectoryIndex index.php` in `.htaccess` plus a new live-URL probe in `_diag.php` that detects the regression and prints an actionable hPanel fix message. The release-process doc gains a new step 5.6 that requires the dev to verify the hPanel settings on every prod deploy.
- Files changed: `.htaccess` (1 line), `_diag.php` (new "Landing page (`/`)" sub-section + `probe_row()` helper), `database/probe_diag_test.php` (2 new assertions), `docs/RELEASE_PROCESS.md` (new step 5.6), this entry.
- Upgrade notes: (1) the dev applies the hPanel fix in the browser (see §6.1 of issue-030), (2) the dev uploads the 3 changed repo files to prod, (3) the dev re-runs `php /home/u162912605/public_html/_diag.php` from the prod shell and confirms the new sub-section shows all 4 sub-checks as OK, (4) the dev pastes the hPanel screenshots into the GitHub release notes.

---

## 7. Validation & Edge Cases

| # | Surface                  | Scenario                                                                                          | Expected                                                                                                                                                                                                                       |
| - | ------------------------ | ------------------------------------------------------------------------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------ |
| 1 | Dev (Docker)             | `http://web.local/`                                                                               | Renders the public landing form (the v1.7.2 form is unchanged). ✅ (no regression)                                                                                                                                              |
| 2 | Dev (Docker)             | `http://web.local/?code=BADCODE&lang=en`                                                          | Renders the form with the red `err.no_project` banner. ✅ (no regression)                                                                                                                                                      |
| 3 | Dev (Docker)             | `http://web.local/?code=&lang=en`                                                                 | Renders the form with the red `err.empty_code` banner. ✅ (no regression)                                                                                                                                                     |
| 4 | Dev (Docker)             | `http://web.local/?code=GOODCODE&lang=en`                                                         | 302 redirect to `client/dashboard.php?code=…`. ✅ (no regression)                                                                                                                                                              |
| 5 | Dev (Docker)             | `http://web.local/?lang=zh`                                                                       | Renders the Chinese landing form. ✅ (no regression)                                                                                                                                                                           |
| 6 | Dev (Docker)             | `php /home/zongbao/projects/project-management-platform/_diag.php` (running the prod-shape diag locally) | The "Landing page (`/`)" sub-section probes `https://project.lorwongam.com/` (not `http://web.local/`) and the assertion will FAIL on dev (the dev's machine cannot reach the public URL from inside the network). This is expected — the dev runs the diag on the prod shell, not on the dev box. The test file `database/probe_diag_test.php` is a source-grep test, not a live-URL test, so the dev-side test still passes. |
| 7 | Prod (shared host)       | `https://project.lorwongam.com/` (post-hPanel-fix)                                                | Renders the public landing form. Browser shows the code-entry field, the "Owner sign in" link, the about popup, the EN/zh toggle. **This is the bug fix — the redirect to `/login.php` is gone.**                                 |
| 8 | Prod (shared host)       | `https://project.lorwongam.com/?code=GH46K-4Q6WN-QWR3T&lang=en`                                   | 302 redirect to `client/dashboard.php?code=…&lang=en`. ✅ (no regression)                                                                                                                                                     |
| 9 | Prod (shared host)       | `https://project.lorwongam.com/?lang=zh` (post-hPanel-fix)                                        | Renders the Chinese landing form. ✅ (no regression)                                                                                                                                                                           |
| 10 | Prod (shared host)      | `https://project.lorwongam.com/login.php` (post-hPanel-fix)                                       | Renders the owner login form (unchanged from v1.7.2). ✅ (the bug was a redirect FROM `/`, not the `/login.php` page itself)                                                                                                  |
| 11 | Prod (shared host)      | `https://project.lorwongam.com/client/dashboard.php?code=…&lang=en` (post-hPanel-fix)             | 200 (was 500 in v1.7.2, fixed in v1.7.2.2). ✅ (no regression)                                                                                                                                                                |
| 12 | Prod (shared host)      | `php /home/u162912405/public_html/_diag.php` from the prod shell                                  | The new "Landing page (`/`)" sub-section shows 4 green ✓ sub-checks. The "ALL CHECKS PASSED" banner at the top of the diag is green. The 5 file checks, the `about.*` lang keys, and the CSP check from v1.7.2.2 also pass.      |
| 13 | Prod (shared host)      | `php /home/u162912405/public_html/_diag.php` from the prod shell (pre-hPanel-fix, hypothetical)  | Sub-check 1 fails: HTTP 301 to `/login.php`. Sub-check 2 fails: Location is `/login.php`. Sub-check 3 fails: body does not contain `<form method="POST" action="">`. Sub-check 4 fails: body contains `<form method="POST" action="login.php">`. The diag prints the "How to fix" guidance for each sub-check, the captured body (first 2000 chars), and the "N CHECKS FAILED" banner. |
| 14 | Prod (shared host)      | `curl -sI https://project.lorwongam.com/_diag.php` from a public internet connection (any machine) | HTTP 403. ✅ (the existing `.htaccess` deny rule for `_diag.php` from v1.7.2.2 is unchanged)                                                                                                                                  |
| 15 | cPanel hPanel           | The user (or dev) removes the Redirects UI rule and adds `index.php` to the Index Manager list.   | Apache reloads the vhost within 1-2 seconds. The next `curl -sSI https://project.lorwongam.com/` from the prod shell returns HTTP 200 with body containing `<form method="POST" action="">`.                                       |
| 16 | cPanel hPanel           | The user (or dev) fixes one of the two settings but not the other.                                | Apache returns either the original bug (if Redirects still in place and Index Manager still says `login.php`) or a partial fix (if only one was applied). The diag catches this: sub-checks 1, 2, 3, 4 fail in different combinations depending on which setting is wrong. |
| 17 | Cloudflare zone         | The bug is not Cloudflare-related, but verify: disable Cloudflare proxy temporarily, hit `/` from `https://project.lorwongam.com/` (bypasses CDN) | Same behavior — cPanel-side, not edge-side. The Cloudflare change has no effect on the bug.                                                                                                                                   |
| 18 | `.htaccess` defense     | Dev removes the new `DirectoryIndex index.php index.html` line and re-uploads to prod              | The bug returns if the cPanel Index Manager is set to `login.php`. The diag catches it. The line in `.htaccess` is a safety net for the case where cPanel has no Index Manager set; in that case, removing the `.htaccess` line would re-introduce the bug. The `database/probe_diag_test.php` test does NOT assert the `.htaccess` line is present (the test is a source-grep on `_diag.php` only), so removing the `.htaccess` line in dev would not fail the test. This is intentional — the `.htaccess` change is defensive, not load-bearing for the fix. |
| 19 | EN locale               | `https://project.lorwongam.com/` (post-fix)                                                       | Renders the EN form. The "Owner sign in" link is present. The about popup is present. The lang switch shows "English" and "中文". ✅                                                                                              |
| 20 | zh locale               | `https://project.lorwongam.com/?lang=zh` (post-fix)                                               | Renders the zh form. The "管理员登录" link is present. The about popup is present (using zh labels). ✅                                                                                                                       |
| 21 | Direct-link flow        | User clicks an email link `https://project.lorwongam.com/?code=…&lang=en`                        | 302 redirect to `client/dashboard.php?code=…&lang=en`. ✅ (the direct-link flow does not involve the `/` redirect; it works regardless of the bug)                                                                              |
| 22 | 429 path                | Brute-force share code 20 times in 5 minutes from the same IP                                     | Page renders the 429 "Too many requests" page (issue-023). No regression.                                                                                                                                                       |

---

## 8. Acceptance Criteria

A reviewer can verify the issue is done by checking, in order:

1. **hPanel fix applied.** The user (or dev) has opened hPanel → Websites → `project.lorwongam.com` → Manage → Redirects and either confirmed no redirect rule exists OR deleted the redirect rule. The user (or dev) has also opened hPanel → Files → Directory Privacy (or Advanced → Index Manager) for `public_html/` and either confirmed `index.php` is in the default-index list OR added it.
2. **`/` serves the landing form.** Hard-reload `https://project.lorwongam.com/` in a private browser window. The browser shows the code-entry form (with the v1.7.2 about popup). The URL bar stays at `/` (no redirect to `/login.php`).
3. **`/login.php` is still reachable.** Hard-reload `https://project.lorwongam.com/login.php` directly. The browser shows the owner login form. The URL bar is `/login.php`. (The `/` redirect was a bug; the `/login.php` page itself is correct and must continue to work.)
4. **`_diag.php` new sub-section passes.** Run `php /home/u162912405/public_html/_diag.php` from the prod shell. The new "Landing page (`/`)" sub-section (§3.5 in the new layout) shows 4 green ✓ rows. The "ALL CHECKS PASSED" banner is green.
5. **`.htaccess` defensive line present.** The prod `.htaccess` contains the line `DirectoryIndex index.php index.html` (added immediately before the existing `RewriteEngine On` line). The repo `.htaccess` also contains the line (so future deploys carry it).
6. **RELEASE_PROCESS.md updated.** `docs/RELEASE_PROCESS.md` has a new step 5.6 between step 5.5 and step 6, with the hPanel sanity check text from §6.4.
7. **probe_diag_test.php extended.** `php database/probe_diag_test.php` returns 0 (success). The 2 new assertions (issue-030 sub-checks and login-redirect rejection) are present and pass.
8. **No regression on v1.7.2.2 features.** Hard-reload `https://project.lorwongam.com/login.php` in a browser. DevTools console shows **no** CSP violation (the v1.7.2.2 Cloudflare beacon fix still works). Hard-reload `https://project.lorwongam.com/client/dashboard.php?code=…&lang=en`. The page is 200 (the v1.7.2.2 500 fix still works).
9. **Screenshots in release notes.** The v1.7.2.3 GitHub release page has 2 screenshots: (a) the hPanel Redirects page (post-fix, showing no redirect rule), (b) the hPanel Directory Privacy / Index Manager page (post-fix, showing `index.php` in the list).
10. **No new prod-side errors.** Hard-reload all 4 of `/`, `/?code=BADCODE&lang=en`, `/?code=&lang=en`, `/?code=GOODCODE&lang=en` in a private browser window. None return HTTP 500. None redirect to `/login.php`. All show the expected landing form / error banner / redirect to `client/dashboard.php`.

---

## 9. The release-process doc update

The full text of step 5.6 is in §6.4. The dev appends it to `docs/RELEASE_PROCESS.md` between the existing step 5.5 and the existing step 6 ("smoke-test the popup on a live instance").

---

## 10. Test plan

### 10.1. `database/probe_diag_test.php` — 2 new assertions

See §6.5 for the exact source. The test is dev-side (no live HTTP), runs as part of the existing `database/` regression suite, and exits 0 on pass.

### 10.2. New file `database/landing_page_probe_test.php` (optional, recommended)

A standalone dev-side test that exercises the new `_diag.php` landing-page probe against a local fake `index.php` (served via `php -S localhost:8765` for the test duration). The test:

1. Starts `php -S localhost:8765 -t .` in the background.
2. Overrides the `$public_base` in `_diag.php` to `http://localhost:8765` via a small wrapper that includes `_diag.php` with the override. (This requires a small refactor of `_diag.php` to accept a `$public_base` parameter from the calling scope — see §10.3 below.)
3. Asserts the 4 sub-checks pass on the local dev landing form.
4. Then creates a fake `public_html/login.php` and a fake `public_html/.htaccess` that redirects `/` → `/login.php`, restarts the test server, and asserts the 4 sub-checks fail with the expected error messages.
5. Kills the test server.

The test takes ~10 seconds to run. It catches a future dev who:
- Changes the probe to always pass (sub-check 1 fails to detect the fake `/login.php` redirect).
- Weakens the body assertion (sub-check 3 fails to detect the wrong form).
- Removes the `Location: /login.php` rejection (sub-check 2 fails to detect the redirect).

### 10.3. Small refactor of `_diag.php` — make `$public_base` overridable

The current `_diag.php` has the URL hard-coded as `https://project.lorwongam.com` (in §6.3's snippet). For the test in §10.2 to work, the URL must be overridable. The refactor is 3 lines:

```php
// At the top of the file, replace:
//   $public_base = 'https://project.lorwongam.com';
// with:
$public_base = $public_base ?? 'https://project.lorwongam.com';
```

The `??` operator lets the test wrapper inject a value (e.g. `http://localhost:8765`) by setting `$public_base` before `require_once '_diag.php'`. The prod behaviour is unchanged because the prod script does not set `$public_base` before including itself.

This refactor is **safe** — the prod script's behaviour is byte-identical to the v1.7.2.2 version. The only difference is the `??` fallback, which defaults to the same value.

### 10.4. No change to other `database/` tests

`security_headers_test.php`, `about_popup_test.php`, `install_wizard_test.php`, `client/toggle_subtasks_test.php`, and the 8 other regression tests are unchanged. The issue-030 fix is orthogonal to all of them.

### 10.5. Manual smoke (per §7)

The 22-row validation table in §7 is the manual smoke matrix. The dev walks it row by row after deploy.

---

## 11. Out of Scope (deferred to a future issue)

- **Removing the `.htaccess` catch-all `RewriteRule` entirely.** The rule is correct; it does not cause the bug. Removing it would risk breaking other URLs that depend on the catch-all (e.g. a typo'd URL like `/contats.php` is currently silently rewritten to `index.php`, which is the project's chosen 404 behaviour). Out of scope.
- **Replacing the cPanel-Index-Manager fix with a code-only `RewriteRule` in `.htaccess` that hard-overrides the Index Manager.** This is technically possible (`RewriteRule ^$ index.php [L]` in `.htaccess` can win against cPanel in some Apache versions) but it is fragile, host-specific, and would mask other bugs. The hPanel fix is the right answer; if the hPanel fix is too cumbersome, the right answer is a hosting migration, not a `.htaccess` workaround.
- **A "deploy" script that wraps the file upload + hPanel check into one command.** The hPanel check is a browser UI action; a deploy script cannot automate it. A future issue could investigate Hostinger's API (if it has one) for programmatically toggling the Index Manager — but that is a separate research project.
- **Migrating the prod off Hostinger shared hosting.** The cPanel-Index-Manager class of bug only exists because of the shared host. Moving to a VPS or a Docker-based prod would eliminate it. But that is an infrastructure project, not a hotfix.

---

## 12. Cross-references

- [issue-004](./issue-004.md) — moved the public client entry point to the domain root. The v1.7.2.3 bug is that this move is being silently undone by a cPanel hPanel setting on the prod host. The intent of issue-004 is preserved; the deploy workflow is the gap.
- [issue-028](./issue-028.md) — the v1.7.2 release that shipped the about-popup. The v1.7.2.3 bug is unrelated to the about-popup itself (the popup is correct on `index.php`); it is about the URL routing on the prod host.
- [issue-028/tester-note-001](./issue-028/tester-note-001.md) §151 — the prior dev document noted that the spec was written for `client/index.php` but shipped on `index.php` (the root). The dev chose to keep it on the root because `client/index.php` is a legacy redirect shim. Issue-030 validates that choice: the `client/index.php` redirect shim is the safest fallback if the cPanel hPanel is misconfigured (the shim redirects to `/`, which then becomes a 301 to `/login.php` on a misconfigured host — but the shim itself is correct).
- [issue-029](./issue-029.md) — the v1.7.2.2 hotfix. The new `_diag.php` probe (§6.3) is built in the same template as the v1.7.2.2 probes (file md5, lang keys, CSP). The new `database/probe_diag_test.php` assertions (§6.5) follow the same `ok()` pattern as the existing assertions in that file.
- [docs/RELEASE_PROCESS.md](../RELEASE_PROCESS.md) — step 5.5 (v1.7.2.2 prod sanity probe) gains a new sub-section 5.6 (this issue's hPanel check). Steps 1-5.5 are unchanged.

---

## 13. Risk and rollback

**Risk:** very low. The change is:

- A 1-line addition to `.htaccess` (defensive, not load-bearing) — easy to revert.
- A new sub-section in `_diag.php` (~80 lines, read-only) — easy to revert.
- A new `probe_row()` helper at the top of `_diag.php` (~8 lines) — easy to revert.
- A `??` fallback in `$public_base` (3 characters of source change) — easy to revert.
- 2 new `ok()` calls in `database/probe_diag_test.php` — easy to revert.
- A new step 5.6 in `docs/RELEASE_PROCESS.md` (a documentation change) — easy to revert.
- A cPanel hPanel change (a UI toggle) — easy to revert in the UI.

**Rollback:** if v1.7.2.3 ships and a regression is reported, revert the 3 file changes and the new `_diag.php` sub-section. The cPanel hPanel change is reversible in 2 clicks (re-add the Redirects rule, or remove `index.php` from the Index Manager list). None of the changes affect user data, sessions, or DB state.

**Caveat:** the cPanel hPanel change is the load-bearing fix. If the dev updates the code but forgets to do the hPanel change, the diag will fail and the bug will not be fixed. The new step 5.6 in `RELEASE_PROCESS.md` (§6.4) is specifically designed to make this hard to miss — it requires the dev to (a) verify the hPanel setting, (b) re-run the diag, (c) paste a screenshot into the release notes. Three independent gates.

---

## 14. Estimated effort

- Read cPanel hPanel + verify the redirect/Index-Manager state: 5 min.
- Apply the hPanel fix (delete Redirects rule OR add `index.php` to Index Manager list): 2 min.
- `.htaccess` 1-line addition: 1 min.
- `_diag.php` new sub-section + `probe_row()` helper + `??` refactor: 20 min.
- `database/probe_diag_test.php` 2 new assertions: 5 min.
- `database/landing_page_probe_test.php` (optional, recommended): 30 min.
- `docs/RELEASE_PROCESS.md` step 5.6: 10 min.
- `RELEASE.md` v1.7.2.3 entry: 10 min.
- Deploy + run diag + manual smoke + paste hPanel screenshots: 15 min.
- Cross-check against §7 validation table: 10 min.

**Total: ~1.5 hours** (without the optional test in §10.2) or **~2 hours** (with the optional test). One dev, one session, no reviewer needed beyond the standard "test passes + manual smoke" gate.
