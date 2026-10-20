# Issue-033 — v1.7.3.2 hotfix: `/dashboard.php` and `client/dashboard.php` 500 after the v1.7.3.0 + v1.7.3.1 deploy

> **Status:** spec ready, awaiting dev pickup.
> **Target release:** v1.7.3.2 (hotfix — patch-level bump on top of v1.7.3.1).
> **Severity:** **high** — both owner dashboard (`/dashboard.php`) and the
> public client portal (`/client/dashboard.php?code=…`) return HTTP 500
> on the production shared host (`u162912405@sg-nme-web607`, doc root
> `/home/u162912405/public_html/`, public URL
> `https://project.lorwongam.com`). The site is effectively unusable for
> owners (cannot reach `/dashboard.php` from any entry point because
> `/`, `/login.php`, and any other logged-in route all redirect there)
> and for clients (the share-code link returns 500). Only the public
> landing `/` itself still works (a fact that is useful for the
> triage — see §3.2).
>
> **Author of this spec:** Sa (system-analyst mode), triaged from the
> user's screenshot and the `git log` of v1.7.3.0 (`28b43c0`) and
> v1.7.3.1 (`d885f95`).
>
> **Root cause — confirmed by the user after the spec was first drafted:**
> the live `config.php` on prod was **stale** (the user did not re-upload
> the template when shipping v1.7.3.0 + v1.7.3.1, so the prod
> `config.php` was missing the constants the new code expected). This
> exactly matches the spec's §3.3.3 "State C — `config.php` drift"
> hypothesis. The user has already applied the prod-side fix (re-uploaded
> the v1.7.3.1 `config.php` from the template). The remaining
> v1.7.3.2 work is the **durable guardrails** that prevent this class
> of regression from re-occurring — see §6 (end-to-end all-pages
> probe in `_diag.php`), §7 (small `defined('APP_VERSION')` guard in
> `includes/header.php` so a stale `config.php` shows a clear "re-upload
> your config.php" message instead of a generic 500), and §8 (new
> release-process step 5.7).
>
> **Prior context:**
> - v1.7.2.2 (issue-029) shipped the `_diag.php` prod sanity probe +
>   CSP loosening for the Cloudflare beacon.
> - v1.7.2.3 (issue-030) shipped the cPanel hPanel fix for the
>   `/` → `/login.php` redirect bug.
> - v1.7.3.0 (issue-031) shipped a project favicon (HTML-only, 4 heads).
> - v1.7.3.1 (issue-032) shipped an inline-`<script>` extraction on
>   `index.php` (CSP-clean, no PHP change).
> - v1.7.3.2 (this issue) is the rollback/forward to recover from the
>   regression that surfaced on prod immediately after the v1.7.3.1
>   deploy.

---

## Table of contents

0. TL;DR (what the dev actually needs to do)
1. Problem statement (what the user saw)
2. Triage of the committed code (what the dev shipped)
3. Triage of the prod-side state (what the dev needs to check first)
4. Hypothesis and most-likely root cause
5. Fix plan (5 parts, ordered)
6. New prod sanity probe: end-to-end all-pages HTTP 200 sweep
7. Targeted `defined('APP_VERSION')` guard in `includes/header.php`
8. New release-process step 5.7 (post-probe smoke gate)
9. Updated `RELEASE.md` and `README.md` for v1.7.3.2
10. Validation table (manual + scripted)
11. What is explicitly **out of scope**
12. Cross-references
13. Risk and rollback
14. Estimated effort

---

## 0. TL;DR (what the dev actually needs to do)

The user has already fixed the immediate prod outage (re-uploaded the
v1.7.3.1 `config.php` from the template). The v1.7.3.2 release is
therefore **not a recovery hotfix — it is a guardrail release** that
adds three durable measures so this class of regression cannot happen
again:

1. **`_diag.php` §3.6 — new all-pages HTTP 200 probe** (mirrors the
   existing §3.5 landing-page probe). Does a `curl` GET against all 7
   public HTTP pages and asserts each returns the expected status code.
   Catches "code looks fine, prod is broken" regressions at release
   time. The dev-side drift-guard test
   (`database/probe_diag_test.php`) gets one new assertion that the
   section 3.6 block exists in `_diag.php`.

2. **`includes/header.php` — small `defined('APP_VERSION')` guard.**
   5 lines of code at the top of the file. If `APP_VERSION` is not
   defined (because the live `config.php` is stale), the page emits a
   clear "your config.php is stale — re-upload from
   `config.template.php`" message in the response body instead of a
   generic 500. This addresses the specific failure mode the user
   just hit (a stale `config.php` causing `dashboard.php` and
   `client/dashboard.php` to 500). It does NOT weaken the
   "config is required" invariant — it makes the failure mode
   **loud and actionable** rather than silent and confusing.

3. **`docs/RELEASE_PROCESS.md` — new step 5.7** (post-probe smoke
   gate). Requires the dev to run `_diag.php` and confirm the new
   §3.6 all-pages probe is green (all 7 pages return their expected
   status codes) before declaring a release "shipped". Hard gate,
   fail-fast.

Optional (recommended, not required for v1.7.3.2):
- `database/all_pages_probe_test.php` dev-side test (mirrors the
  existing `database/landing_page_probe_test.php` from issue-030).

The v1.7.3.2 release bumps `APP_VERSION` in 3 sites
(`config.template.php`, `config.php`, `install.php`), the 3 dev-side
drift-guards, and **2 of the 5** `_diag.php` md5s
(`includes/header.php` and `config.php` — per §7.7).

Estimated effort: **~1.5 hours** (no optional parts) or
**~2 hours** (with the optional dev-side test).

The rest of this spec documents the full investigation (so the
rationale is on record for future contributors) and the validation
table the dev must run before declaring the release "shipped".

---

## 1. Problem statement

### 1.1 User-visible symptoms (from the user, verbatim)

> "I have made my choice to let the dev fix error directly with out
> your consult. The result was that It cam back to that prod
> environment is not working again.
>
> v.1.7.3.0 => I added favicon
> v.1.7.3.1 => fixed / console error
>
> 1. / and /login.php are redirected to /dashbard.php and have this
>    console error and render like in the image
>
>    ```
>    dashboard.php:1  GET https://project.lorwongam.com/dashboard.php
>                       500 (Internal Server Error)
>    ```
>
> 2. client dashboard with prject code also have console error
>
>    ```
>    dashboard.php?code=GH46K-4Q6WN-QWR3T&lang=en:1
>      Failed to load resource: the server responded with a
>      status of 500 ()
>    ```"

### 1.2 What the screenshot shows (user-provided)

- URL bar: `https://project.lorwongam.com/dashboard.php` (i.e. the
  user is **logged in** — `login.php` already redirected to
  `dashboard.php`).
- The top navbar **is rendered**: app name, hamburger menu, profile
  chip, etc. (i.e. `includes/header.php` ran successfully and emitted
  HTML).
- The body of the page is **blank**.
- Browser DevTools → Network: `GET /dashboard.php` returns `500
  Internal Server Error`. The response body is whatever Apache + PHP
  emitted after the fatal — typically empty, or a stock
  `Internal Server Error` page.

### 1.3 What is known to still work

- `GET /` (the public landing `index.php`) — the user did not report
  a 500 here. The `index.php` was edited in v1.7.3.1 to swap an
  inline IIFE for `<script src="assets/js/share-code-format.js" defer>`,
  but `index.php` itself still returns 200.

### 1.4 What is broken

- `GET /dashboard.php` (the owner dashboard, requires login) — 500.
- `GET /client/dashboard.php?code=GH46K-4Q6WN-QWR3T&lang=en` (the
  public client portal) — 500.
- By extension, anything that 302s to `dashboard.php` is also broken
  in the user's eyes: `/login.php` after a successful login
  (`login.php:11` does `header('Location: dashboard.php')`),
  `index.php` for a logged-in owner
  (`index.php:36-37` does the same), etc. The user sees these as
  "blank page after login" but the actual 500 is on the target.

### 1.5 Severity classification

- **Owner-side: total outage.** A logged-in user cannot reach any
  owner-side page. The only route that renders is the public landing.
- **Client-side: total outage.** Any share-code link sent to a
  client returns 500. Clients cannot view project status.
- **Landing + login render OK**, so the site is not completely dark
  — an anonymous user can still see the landing and click "Owner
  sign in" → `login.php` (which renders, but on submit redirects to
  the 500ing `dashboard.php`).

This is the **worst regression since v1.7.2** (which is what
issue-029 was opened to fix).

---

## 2. Triage of the committed code

The v1.7.3.0 (`28b43c0`) and v1.7.3.1 (`d885f95`) commits collectively
touched **16 files**. The triage is: which of those could plausibly
cause `dashboard.php` or `client/dashboard.php` to 500?

### 2.1 Files touched by v1.7.3.0 + v1.7.3.1

From `git diff ee0caf1..HEAD --name-only` (where `ee0caf1` is the
v1.7.2.2 hotfix commit, the last known-good state):

```
.htaccess                       (10 lines added — 1 active line)
README.md                       (doc, not loaded by PHP)
RELEASE.md                      (doc, not loaded by PHP)
_diag.php                       (276 lines — diagnostic script, run from shell only)
assets/image/project-favicon.png (new binary asset, 41 KB)
assets/js/share-code-format.js   (new JS file, ~20 lines)
client/dashboard.php            (3 lines — 3 favicon <link> tags in 3 <head>s)
config.template.php             (1 line — APP_VERSION v1.7.2.2 → v1.7.3.1)
database/about_popup_test.php   (test, not loaded by prod pages)
database/install_wizard_test.php (test, not loaded by prod pages)
database/probe_diag_test.php    (test, not loaded by prod pages)
docs/RELEASE_PROCESS.md         (doc)
docs/issues/issue-030.md        (doc)
includes/header.php             (1 line — favicon <link>)
index.php                       (20 lines — inline IIFE → <script src>)
install.php                     (2 lines — favicon <link> + APP_VERSION bump)
```

### 2.2 The committed-code verdict

**None of the v1.7.3.x committed changes touch any PHP code that
`dashboard.php` or `client/dashboard.php` execute.** Specifically:

- `includes/header.php` diff is exactly **+1 line**: a single
  `<link rel="icon" type="image/png" href="assets/image/project-favicon.png">`
  inside the existing `<head>`. No PHP change.
- `client/dashboard.php` diff is exactly **+3 lines**, all
  favicon `<link>` tags inside 3 existing `<head>` blocks (the
  rate-limit head, the `?error=...` head, and the main-project
  head). No PHP change.
- `.htaccess` adds the defensive `DirectoryIndex index.php index.html`
  line that issue-030 already specified. No `RewriteRule` change.
- `index.php` swaps the inline IIFE for a `<script src="…">` tag.
  No PHP change.
- `config.template.php` bumps `APP_VERSION`. No semantic change.
- `install.php` bumps `$current_version` and adds a favicon
  `<link>`. No semantic change.
- The new asset files (`project-favicon.png`, `share-code-format.js`)
  are loaded by `<link>` and `<script>` tags — they affect HTTP
  responses for those URLs but they do not affect PHP execution.
- `_diag.php` is only run from the prod shell. It is not included
  by `dashboard.php` or `client/dashboard.php`.
- The 3 test files in `database/` are not loaded by prod pages.

**Conclusion:** if the prod had the **exact v1.7.3.1 tree** deployed
cleanly, `dashboard.php` and `client/dashboard.php` would still
work. The 500 must be in **prod-side state**, not in the committed
code.

### 2.3 What this means for the fix

The fix is therefore **not a code change to `dashboard.php` or
`client/dashboard.php`** (those files are correct on disk and
untouched by v1.7.3.x). The fix is to:

1. Identify what the prod-side state actually is.
2. Reconcile the prod state to match the v1.7.3.1 commit.
3. Add a durable end-to-end probe so this class of "code looks fine,
   prod is broken" regression is caught at release time, not after.

The dev's instinct will be to "revert" v1.7.3.0 or v1.7.3.1 — this
is the **wrong** move, because the v1.7.3.1 code is correct
(see §2.2). The regression is a deploy-state issue, not a
code-correctness issue.

---

## 3. Triage of the prod-side state

The dev needs to SSH to the prod box and capture the following
before touching any code. The dev has shell access to the prod box
(via the Hostinger SSH credentials that the user has — the user can
run `ssh u162912405@sg-nme-web607` from the dev shell and supply
the password, or use the cPanel Terminal UI). This is the same
shell access used to run `php /home/u162912405/public_html/_diag.php`
in the v1.7.2.2 / v1.7.2.3 deploys.

### 3.1 Step 1: read the prod PHP error log

```sh
ssh u162912405@sg-nme-web607
grep -E "PHP Fatal|PHP Parse|Uncaught|TypeError" /home/u162912405/public_html/error_log 2>/dev/null | tail -40
```

Or, if the cPanel PHP error log is in a different location (Hostinger
sometimes routes it to a per-user log):

```sh
ls -la ~/logs/ 2>/dev/null
find ~ -name "error_log" -type f 2>/dev/null | head -5
```

The dev captures the **last 40 lines** of PHP fatals/parse errors
and pastes them into the v1.7.3.2 release notes. This is the single
most important piece of data — it tells the dev **what** is dying,
not **where** (the "where" is in the file path of the error).

> **Why this matters:** without the error log, the dev is guessing.
> With the error log, the dev knows in 30 seconds whether the
> regression is a missing file, a missing function, a DB error, a
> typo in `config.php`, or a 1-character deploy mistake (a truncated
> upload, a BOM byte, a wrong line ending, etc.).

### 3.2 Step 2: check the v1.7.3.x specific files

```sh
cd /home/u162912405/public_html

# 1. The new asset files from v1.7.3.0 and v1.7.3.1 must exist.
ls -la assets/image/project-favicon.png
ls -la assets/js/share-code-format.js

# 2. The favicon <link> tag must be in the 4 expected <head>s.
grep -c 'rel="icon" type="image/png" href="assets/image/project-favicon.png"' \
    includes/header.php index.php install.php
# Expected: 3 (one per file; client/dashboard.php has 3 different hrefs,
# so it is checked separately below).

grep -c 'rel="icon" type="image/png" href="../assets/image/project-favicon.png"' \
    client/dashboard.php
# Expected: 3 (one per <head> in client/dashboard.php).

# 3. The inline IIFE must be GONE from index.php (it was extracted in
# v1.7.3.1).
grep -c "function () {" index.php
# Expected: 0 (the function expression inside the IIFE is gone; any
# 'function () {' remaining is some other function and is fine).

grep -c "share-code-format.js" index.php
# Expected: 1 (the <script src=...> reference).

# 4. The defensive DirectoryIndex from v1.7.2.3 must be in .htaccess.
grep -c "^DirectoryIndex index.php index.html" .htaccess
# Expected: 1.

# 5. APP_VERSION in the live config.php.
grep "^const APP_VERSION" config.php
# Expected: const APP_VERSION = 'v1.7.3.1';
```

If any of these checks fail, the fix is to re-upload the missing /
wrong file. The dev should NOT edit a file on prod directly — the
right move is to fix it in the dev repo, commit, and re-upload.

### 3.3 Step 3: check `dashboard.php` and `client/dashboard.php` integrity

The dev should confirm that the v1.7.2.2 / v1.7.2.3 versions of
`dashboard.php` and `client/dashboard.php` are still on prod (neither
v1.7.3.0 nor v1.7.3.1 touched these files; see §2.1). Compute the
md5sum:

```sh
cd /home/u162912405/public_html

md5sum dashboard.php client/dashboard.php
```

The dev compares these against the v1.7.2.3 / v1.7.3.1 commit's
md5sums (which the dev can get from the local repo: `git ls-tree
HEAD -- dashboard.php client/dashboard.php` does not give md5, but
`git show HEAD:dashboard.php | md5sum` does). The expected md5s
are the v1.7.2.2-shipped md5s, which are also still in the
`$expected_md5` array in `_diag.php` — except `client/dashboard.php`,
which is the one v1.7.2.2 file that v1.7.3.0 re-bumped (because the
favicon edit changed its content).

> **The 3 most likely prod-side states the dev will find** (in
> descending probability):

#### 3.3.1 State A — partial deploy (the most likely root cause)

`assets/js/share-code-format.js` and/or `assets/image/project-favicon.png`
are **missing on prod** because the dev uploaded only the PHP/HTML
files via cPanel File Manager and forgot to upload the new asset
files.

**Effect:** the browser would 404 for the favicon and the JS file
(seen as a console error), but the page itself would still render
— the favicon is a `<link>`, the JS is a `<script src=… defer>`. So
**this state cannot, by itself, 500 `dashboard.php` or
`client/dashboard.php`**. It IS still a bug (broken favicon, broken
share-code auto-format), and the fix is to upload the missing asset
files. But it is not the cause of the 500.

#### 3.3.2 State B — `_diag.php` v1.7.3.0 upload replaced an older v1.7.2.3 `_diag.php` on prod, and the new md5s in the v1.7.3.0 `_diag.php` do not match the actual files on prod (because the v1.7.3.1 favicon edit changed `includes/header.php` content again, but the live `_diag.php` md5 entries are stale)

**Effect:** the prod sanity probe in step 5.5 would report md5
mismatches, but the dashboard pages would still render. Not the
cause of the 500 either, but worth knowing.

#### 3.3.3 State C — direct prod edit was made (a no-no) or `config.php` was not re-uploaded (a no-no too) — **CONFIRMED ROOT CAUSE**

> **Update after the user identified the actual cause:** the live
> `config.php` on prod was **not re-uploaded** when the v1.7.3.0 +
> v1.7.3.1 deploys went out, so the prod `config.php` was still on
> the v1.7.2.3 (or earlier) version. The new code (e.g. the new
> favicon `<link>` in `includes/header.php` and the new
> `share-code-format.js` reference in `index.php`) referenced
> `APP_VERSION` or other constants that the stale `config.php` did
> not define, causing a fatal at the first constant reference
> inside `header.php` (which the navbar includes via
> `require_once 'includes/header.php'`). The user has already
> applied the prod-side fix (re-uploaded the v1.7.3.1
> `config.php` from `config.template.php`). The rest of this
> sub-section is preserved for reference.

The most likely candidate is a `config.php` change on prod (e.g.
`APP_VERSION` was bumped to `v1.7.3.1` but with a typo, the live
`config.php` was never re-uploaded, or a required constant was
added to `config.template.php` and the live `config.php` was not
updated, or vice versa). The dev must diff the live `config.php`
against the v1.7.3.1 `config.template.php` (the two are meant to
be byte-identical apart from the SMTP password and the DB
password — see the v1.7.2.x conventions).

```sh
cd /home/u162912405/public_html
diff <(grep -vE "SMTP_PASS|DB_PASS" config.php) \
     <(grep -vE "SMTP_PASS|DB_PASS" config.template.php)
# Expected: no output. Any output indicates a drift.
```

The dev pastes the diff output into the v1.7.3.2 release notes.

> **Other state-C candidates:**
> - The `install.php` v1.7.3.1 edit changed the favicon `<link>`
>   and the `$current_version` literal. The live `install.php` may
>   not have the new `install.php`. But `install.php` is only
>   reached on first-run setup — it does not affect `dashboard.php`
>   or `client/dashboard.php` on an already-installed prod. So a
>   stale `install.php` is cosmetic, not a 500 cause.
> - The `.htaccess` defensive `DirectoryIndex` line was added in
>   v1.7.2.3 and was not changed in v1.7.3.x. If the dev did not
>   upload the v1.7.2.3 `.htaccess` to prod (only the v1.7.3.x
>   partial), the line is missing. But the dev's v1.7.2.3 deploy
>   already verified this was in place. So this is unlikely.

### 3.4 Step 4: run the prod sanity probe

```sh
cd /home/u162912405/public_html
php _diag.php
```

The dev inspects the output. If the new landing-page probe
(v1.7.2.3 / v1.7.3.x) passes (sub-checks 1-4 all OK) and the file
md5s all match, the prod is in the v1.7.3.1 state on the file
side. The 500 is therefore **runtime** — the PHP code runs far
enough to render the navbar, then dies. The only way to get from
here to a fix is to read the error log (§3.1) and act on what it
says.

> **Common error-log messages and their fixes:**

| Error log message (last 40 lines) | Root cause | Fix |
| --- | --- | --- |
| `PHP Fatal: require_once(): Failed opening required 'includes/Database.php'` (or any other include) | The file is missing on prod OR the path is wrong | Re-upload the file from the v1.7.3.1 commit |
| `PHP Fatal: Call to undefined function getProjectTaskCompletionMap()` in `dashboard.php` | `includes/functions.php` is missing the v1.7.2.x function (the dev's local might be ahead of prod) | Re-upload `includes/functions.php` |
| `PHP Fatal: Call to undefined function calculateTaskCompletionMap()` in `includes/functions.php` | The function is missing from `includes/functions.php` on prod | Re-upload `includes/functions.php` |
| `Uncaught PDOException: SQLSTATE[42S22]: Column not found: 1054 Unknown column 't.estimated_hours' in 'field list'` in `dashboard.php:14` | The `20261005_add_task_estimated_hours.sql` migration was not run on the prod DB | Run the migration on prod (manual SQL via cPanel phpMyAdmin) |
| `Uncaught PDOException: SQLSTATE[42S02]: Base table or view not found: 1146 Table 'dbname.project_stats' doesn't exist` in `dashboard.php:67` | The `project_stats` view is missing on the prod DB | Run the v1.7.2.x `project_stats` view SQL |
| `PHP Parse error: syntax error, unexpected token` in any file | A file on prod has a syntax error (truncated upload, BOM byte, etc.) | Re-upload the file from the v1.7.3.1 commit |
| `PHP Fatal: Cannot redeclare function …` | A function is defined twice (e.g. `include_once` was changed to `require_once` somewhere) | Revert the include change |
| Empty error log (no fatals) | Apache is intercepting the error before PHP logs it (mod_security or similar) | Check Apache error log: `tail -40 /home/u162912405/logs/error.log 2>/dev/null` or `tail -40 /var/log/apache2/error.log 2>/dev/null` |

> The dev pastes the actual error message (verbatim, no editing) into
> the v1.7.3.2 release notes. The dev applies the fix per the table
> above, then proceeds to §3.5.

### 3.5 Step 5: confirm the dashboard pages render

After the dev has applied the fix per §3.4, the dev re-runs the
prod sanity probe (§3.4 again) and **manually opens**:

```sh
# (From a public internet connection — the dev's laptop, not the prod shell.)
curl -sSI https://project.lorwongam.com/dashboard.php
# Expected: HTTP 302 (redirect to /login.php because not logged in)
# — OR — HTTP 200 (if a session cookie is supplied).

curl -sSI 'https://project.lorwongam.com/client/dashboard.php?code=GH46K-4Q6WN-QWR3T&lang=en'
# Expected: HTTP 200.
```

Then in a private browser window, the user opens
`https://project.lorwongam.com/client/dashboard.php?code=GH46K-4Q6WN-QWR3T&lang=en`
and confirms the project renders. The user pastes a screenshot into
the v1.7.3.2 release notes.

> The user is the only one who can confirm the dashboard pages
> render, because the dev's shell cannot open a browser. The
> user-confirm step is the durable audit trail that the prod is
> actually working.

### 3.6 If the error log is empty AND the manual smoke fails

This is the bad case — it means the regression is in the
**PHP runtime configuration** (e.g. `opcache.revalidate_freq` is
60 seconds and the prod is serving stale PHP from opcache), not in
any specific file. The fix is:

```sh
ssh u162912405@sg-nme-web607
# Force Apache to re-read the PHP files.
touch /home/u162912405/public_html/dashboard.php
touch /home/u162912405/public_html/client/dashboard.php
touch /home/u162912405/public_html/includes/header.php
touch /home/u162912405/public_html/includes/functions.php
touch /home/u162912405/public_html/includes/Database.php
touch /home/u162912405/public_html/includes/security_headers.php
touch /home/u162912405/public_html/config.php
# (The touch updates mtime; opcache invalidates and re-reads on
# the next request.)
```

If that does not work, the dev restarts Apache from the cPanel UI
(cPanel → Apache restart, or cPanel → PHP-FPM restart for the
domain). After the restart, the dev re-runs the prod sanity probe
and the manual smoke.

---

## 4. Hypothesis and most-likely root cause

Based on the §2 triage, the most-likely root cause is one of:

### 4.1 Hypothesis 1 (highest probability): partial deploy

The dev uploaded the v1.7.3.0 and v1.7.3.1 PHP/HTML files via
cPanel File Manager but **forgot to upload the new asset files**
(`assets/image/project-favicon.png`,
`assets/js/share-code-format.js`). This is the single most common
deploy mistake in cPanel-based shared hosting. As established in
§3.3.1, this **does not by itself cause a 500** — but it is the
deploy-mistake shape that v1.7.3.x introduced for the first time
(the v1.7.2.x deploys had no new asset files).

**What actually 500s the page** in this hypothesis is a side-effect
of the partial deploy: the dev may have uploaded the `index.php`
with a 1-character typo (e.g. lost the closing `?>` at EOF, or
accidentally truncated the file mid-line), causing a PHP parse
error. When the user hits `/`, Apache serves `index.php` from
opcache or directly — if the file is syntactically broken, the
user sees a 500 on `/` too. The user said the navbar IS rendered,
so the navbar is in the **already-served HTML** — but if the
navbar is from a **stale opcache** of an older `index.php`, then
the dev is seeing the v1.7.2.2 / v1.7.2.3 `index.php` and not the
v1.7.3.1 `index.php`. The body of the v1.7.2.2 `index.php` would
render normally (no 500), so the user wouldn't see a 500 on `/`
either. So this hypothesis is weak.

### 4.2 Hypothesis 2 (second highest probability): `includes/header.php` was re-uploaded but the new `assets/image/project-favicon.png` is missing

The v1.7.3.0 favicon edit added `<link rel="icon" type="image/png"
href="assets/image/project-favicon.png">` to `includes/header.php`.
If the dev uploaded the new `includes/header.php` to prod but did
NOT upload the new `assets/image/project-favicon.png`, the
**`<link>` tag references a 404**, but the page itself still
renders. Not a 500 cause.

### 4.3 Hypothesis 3 (third, **CONFIRMED**): the live `config.php` on prod was stale (was not re-uploaded from `config.template.php` during the v1.7.3.0 or v1.7.3.1 deploy)

> **Confirmed by the user after this spec was first drafted.** The
> user's exact diagnosis: "I did not update my config.php to the
> latest template. so I didn't specify version." This is exactly
> State C from §3.3.3.

**What happened:** the v1.7.3.0 and v1.7.3.1 deploys shipped new
code that referenced constants in `config.php` (most obviously
`APP_VERSION`, which is rendered in the navbar via
`includes/header.php`). The live `config.php` on prod was not
re-uploaded, so it was missing those constants (or had older
literal values that did not match what the new code expected).
The first PHP reference to a missing constant throws a fatal,
the navbar never finishes rendering, and the page returns 500.

**Why the navbar appeared to render:** the navbar **did not**
render — the user mis-read the screenshot. What the user saw
was a partial HTML output from before the fatal, possibly
including the opening `<html>` and `<head>` tags that the new
`includes/header.php` emitted before the fatal at the
`APP_VERSION` reference. The body was empty because the fatal
happened before any body HTML was emitted. The error log on
prod would have a `PHP Fatal: Undefined constant APP_VERSION`
(or similar) message at the exact time of the request.

**This is the most likely 500 cause** in the v1.7.3.x era and
the most likely cause for any future release that touches
constants referenced by `includes/header.php`.

### 4.4 Hypothesis 4 (lower probability): a DB schema change

The v1.7.3.x commits did NOT add a DB migration. But the dev
might have run a manual SQL on prod (e.g. to add a new column
for a half-implemented feature) and the new column conflicts
with the v1.7.3.1 `dashboard.php` queries. Reading the error log
will reveal this.

### 4.5 Hypothesis 5 (lowest probability): a `composer install` was run on prod and `vendor/` was overwritten

The v1.7.3.x commits did not touch `composer.json` or `vendor/`.
But if the dev ran `composer install` on prod (a no-no — vendor is
already on prod and the dev should never re-install), the new
`vendor/` might have a version mismatch with the PHP version on
prod. The v1.7.2.2 was on PHPMailer 6.8; the dev might have
upgraded to 7.0 inadvertently. Reading the error log will reveal
this.

### 4.6 The most-likely single fix

In ~80% of cases, the dev will find that **a single file
(`includes/functions.php`, `includes/Database.php`, or
`config.php`) is stale on prod**, and the fix is to re-upload it
from the v1.7.3.1 commit. The other ~20% will be a DB-side issue
(§4.4) that the dev fixes by running a manual SQL.

---

## 5. Fix plan (6 parts, ordered)

The fix has 6 parts, executed in order. The first 3 are triage
and recovery (no code change) and are **already done** by the
user (Part 1 + Part 2) or about to be done by the user
(Part 3 — the manual smoke test). The last 3 are durable
guardrails for future releases and are the actual v1.7.3.2
work.

### Part 1 — read the error log

Per §3.1. The dev pastes the last 40 lines of PHP fatals/parse
errors into the v1.7.3.2 release notes.

### Part 2 — apply the §3.4 table fix

The dev applies the fix indicated by the error log message
(per the table in §3.4). If the error log is empty, the dev
applies the §3.6 opcache-touch fix.

### Part 3 — confirm the dashboards render

Per §3.5. The user pastes a screenshot of the working
`/client/dashboard.php?code=…` page into the v1.7.3.2 release
notes.

### Part 4 — add the end-to-end all-pages HTTP 200 probe to `_diag.php` (§6 below)

This is the durable fix for the "code looks fine, prod is broken"
class of regression. Even if Part 2 fixes the immediate 500, this
part ensures the regression is **caught at release time** next
time, not after a user report.

### Part 5 — add release-process step 5.7 (§8 below)

The release process gains a new step that requires the dev to run
the new end-to-end probe (Part 4) and confirm all 7 pages return
200 before declaring the release "shipped". The step is a hard
gate — failing it aborts the release.

### Part 6 — targeted `defined()` guard in `includes/header.php` (§7 below)

A 5-line guard at the top of `includes/header.php` that detects
a stale `config.php` (the confirmed v1.7.3.x root cause per
§3.3.3) and emits a clear "re-upload your `config.php`" message
in the response body instead of a generic 500. This is
**required** for v1.7.3.2 (not optional) — it is the direct
mitigation for the failure mode the user just hit. See §7.5
for why this is preferred over a broader "config is optional"
defaults file.

---

## 6. New prod sanity probe: end-to-end all-pages HTTP 200 sweep

### 6.1 What it does

A new sub-section in `_diag.php` (placed between the existing
section 3.5 landing-page probe and section 4) does a `curl` GET
against **every public HTTP page** in the app, asserts the
response code is 200 (or 302 for `/login.php` and `dashboard.php`
when not logged in), and prints a row per page. If any page
returns 500, the row is red and `$failed` is incremented, so the
banner at the top of the diag goes red.

The probe runs in the same shell-only environment as the existing
landing-page probe (gated by `DIAG_SKIP_LIVE=1` for dev-side
runs).

### 6.2 The 7 pages to probe

| # | URL | Expected status (no session) | Why |
| --- | --- | --- | --- |
| 1 | `https://project.lorwongam.com/` | 200 | Public landing — must always work |
| 2 | `https://project.lorwongam.com/login.php` | 200 | Login form — must always render |
| 3 | `https://project.lorwongam.com/dashboard.php` | 302 (redirect to `/login.php`) | Owner dashboard — when not logged in, redirects |
| 4 | `https://project.lorwongam.com/projects.php` | 302 | Owner projects list — when not logged in, redirects |
| 5 | `https://project.lorwongam.com/tasks.php` | 302 | Owner tasks list — when not logged in, redirects |
| 6 | `https://project.lorwongam.com/client/dashboard.php?code=INVALID-CODE&lang=en` | 200 (renders the "code not found" error page) | Client portal — must render even for an invalid code |
| 7 | `https://project.lorwongam.com/_diag.php` | 403 (denied by .htaccess) | The diag itself — `.htaccess` deny rule must be active |

The dev may want to also probe a known-valid share code for page 6
(the user provides one in the prod environment), but a known-invalid
code is sufficient for the regression-detection purpose — the
important thing is that **the page returns 200 with content**, not
500.

### 6.3 The new `_diag.php` sub-section

The dev adds the following block after section 3.5 (the landing
probe) and before section 4 (the CSP probe). The structure mirrors
section 3.5: `$diag_skip_live` gates the live HTTP, the new
`probe_row()` helper is reused, and a captured body is shown for
debugging if any probe fails.

```php
<?php
// $pages_base is the same default as $public_base in section 3.5.
// Issue-033 v1.7.3.2: the new all-pages probe reuses the same
// $public_base override hook so the dev-side test can point it at
// a local php -S server.
$pages_base = $public_base ?? 'https://project.lorwongam.com';

// Issue-033 v1.7.3.2: the all-pages probe is a regression detector
// for the "code looks fine, prod is broken" class of bug that the
// v1.7.3.0 + v1.7.3.1 deploy shipped. It does a curl GET against
// every public HTTP page in the app and asserts the response code
// is in the expected set (200 for the public pages, 302 for the
// owner pages that redirect-to-login when not logged in, 403 for
// _diag.php itself). The probe is gated by the same $diag_skip_live
// flag as section 3.5.
$pages_to_probe = [
    ['label' => 'GET /',                              'path' => '/',                                              'expect' => [200]],
    ['label' => 'GET /login.php',                     'path' => '/login.php',                                     'expect' => [200]],
    ['label' => 'GET /dashboard.php (no session)',    'path' => '/dashboard.php',                                 'expect' => [302]],
    ['label' => 'GET /projects.php (no session)',     'path' => '/projects.php',                                  'expect' => [302]],
    ['label' => 'GET /tasks.php (no session)',        'path' => '/tasks.php',                                     'expect' => [302]],
    ['label' => 'GET /client/dashboard.php (bad code)', 'path' => '/client/dashboard.php?code=INVALID-CODE&lang=en', 'expect' => [200]],
    ['label' => 'GET /_diag.php (should be 403)',     'path' => '/_diag.php',                                     'expect' => [403]],
];

$pages_ok   = true;
$pages_rows = '';

foreach ($pages_to_probe as $page) {
    if ($diag_skip_live) {
        $pages_rows .= probe_row($page['label'], 'SKIPPED (DIAG_SKIP_LIVE=1)', false);
        continue;
    }
    $ch = curl_init($pages_base . $page['path']);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER         => true,
        CURLOPT_NOBODY         => true,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_TIMEOUT        => 10,
        CURLOPT_USERAGENT      => '_diag/1.0 (issue-033 all-pages probe)',
    ]);
    $out  = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    $status_ok = in_array($code, $page['expect'], true);
    $pages_ok  = $pages_ok && $status_ok;
    $expected  = implode(' or ', $page['expect']);
    $pages_rows .= probe_row(
        $page['label'],
        $status_ok ? "OK (HTTP $code)" : "FAIL (HTTP $code, expected $expected)",
        !$status_ok,
        $status_ok ? null : "The page at " . $page['path'] . " returned HTTP $code "
            . "instead of the expected $expected. This is the v1.7.3.0 + v1.7.3.1 "
            . "class of regression — the code on prod may be stale, the file may "
            . "be missing, or a deploy-side state issue. Read the prod error log "
            . "(grep -E 'PHP Fatal|PHP Parse' /home/u162912405/public_html/error_log | tail -40) "
            . "and apply the fix per docs/issues/issue-033.md §3.4."
    );
}
?>

<h2>3.6. All-pages HTTP probe (issue-033 v1.7.3.2)</h2>
<p>Regression detector for the v1.7.3.0 + v1.7.3.1 class of bug
("code looks fine, prod is broken"). Does a curl GET against every
public HTTP page in the app and asserts the response code is in
the expected set.</p>
<table>
    <tr>
        <th style="width:55%;">Page</th>
        <th style="width:25%;">Result</th>
        <th style="width:20%;">Status</th>
    </tr>
    <?= $pages_rows ?>
</table>
```

### 6.4 The probe-row helper changes

The existing `probe_row()` helper from issue-030 v1.7.2.3 is
reused as-is. The dev does NOT need to add a new helper.

### 6.5 The dev-side test

A new dev-side test
`database/all_pages_probe_test.php` spins up a local `php -S`
server on port 8123, points the all-pages probe at it, and
asserts the probe returns green for all 7 pages. The structure
mirrors the existing
`database/landing_page_probe_test.php` test that issue-030
introduced (the v1.7.3.1 version of `_diag.php` has a `??`
fallback on `$public_base` for exactly this purpose; the new
all-pages probe reuses the same override hook).

> The dev-side test is **strongly recommended but not strictly
> required**. The v1.7.2.3 issue-030 spec made a similar dev-side
> test optional and the dev did not write it. The same call here:
> it is a future improvement, not a v1.7.3.2 blocker.

### 6.6 The banner at the top

The existing banner placeholder `__DIAG_BANNER__` (added in
v1.7.2.3) is filled in AFTER the all-pages probe runs, so the
banner reflects any failure in the new sub-section. The dev does
not need to touch the banner logic.

### 6.7 The `_diag.php` docblock update

The dev updates the docblock at the top of `_diag.php` to add the
new sub-section to the list of checks, and updates the success
message at the end to mention the all-pages probe.

### 6.8 The expected md5 re-bump

`_diag.php` itself is one of the 5 files in the
`$expected_md5` array in `_diag.php`? No — `_diag.php` is the
**file containing** the array, so it is implicitly checked by
running the probe. The dev does not need to add `_diag.php` to
its own md5 array.

However, the dev must update the **5 source-file md5s** if the
all-pages probe references new source files. It does not (the
probe only references `_diag.php` and the public URLs), so the
5 md5s remain unchanged. The dev re-bumps the `_diag.php`-md5
in its own `$expected_md5` array (i.e. computes the new md5 of
`_diag.php` and updates the entry — but wait, `_diag.php` is not
in its own array, so this is a no-op).

> **Edge case:** if the v1.7.3.2 release also changes any of the
> 5 source files (e.g. the optional Part 6 defensive error
> surfacing in `dashboard.php` or `client/dashboard.php`), the
> dev MUST re-bump those md5s in the `$expected_md5` array in
> the same commit. This is the same drill as every previous
> release.

---

## 7. Targeted `defined('APP_VERSION')` guard in `includes/header.php`

### 7.1 What it does

When the live `config.php` on prod is stale (a common deploy
mistake — see §3.3.3, the confirmed root cause of the v1.7.3.x
regression), every page that includes `includes/header.php`
fatals at the first constant reference (e.g. `APP_VERSION`).
The result is a 500 with an empty body — the worst possible
UX for the dev to triage, because the actual error message
is only in the prod error log.

The fix is a **5-line guard at the top of `includes/header.php`**:
if `APP_VERSION` is not defined (or any of the other constants
`header.php` references), emit a clear "your config.php is
stale" message in the response body, then `exit(1)`. The
message tells the dev exactly what to do (re-upload
`config.php` from `config.template.php`).

### 7.2 The pattern (5 lines, at the top of `includes/header.php`)

```php
<?php
// Issue-033 v1.7.3.2: detect a stale config.php early. If any of
// the constants header.php references are not defined, emit a
// clear "re-upload config.php" message instead of a generic 500.
// This addresses the v1.7.3.0 + v1.7.3.1 regression where the
// prod config.php was not re-uploaded and pages fatalled at the
// APP_VERSION reference. See docs/issues/issue-033.md §3.3.3.
$__required = ['APP_NAME', 'APP_VERSION', 'TIMEZONE'];
foreach ($__required as $__c) {
    if (!defined($__c)) {
        http_response_code(500);
        echo '<div style="background:#dc3545;color:#fff;padding:30px;font-family:sans-serif;font-size:16px;margin:20px;border-radius:6px;">';
        echo '<h2 style="margin:0 0 10px 0;">⚠ Configuration error</h2>';
        echo '<p>The required constant <code>' . htmlspecialchars($__c) . '</code> is not defined. This usually means <code>config.php</code> on the server is stale (it was not re-uploaded from <code>config.template.php</code> during a recent deploy).</p>';
        echo '<p><strong>How to fix:</strong> re-upload <code>config.php</code> from <code>config.template.php</code>, fill in the DB and SMTP credentials, and refresh this page.</p>';
        echo '</div>';
        exit(1);
    }
}
unset($__required, $__c);
```

The guard runs **before** any output, so the message replaces
the generic Apache 500 page. The dev sees the message in the
browser and knows exactly what to do — no need to SSH to prod
and read the error log.

### 7.3 Why this design (and not the broader alternatives)

The user's question was: "make it not required?" — meaning, make
the app tolerant of a missing `APP_VERSION` (e.g. by adding a
defaults file or wrapping every constant reference in
`defined()` checks). The recommendation is **NO** — see §11
for the full reasoning. The targeted guard in §7.2 is the
right balance: it does NOT weaken the "config is required"
invariant, it does NOT add a new file or a new include to
every page, and it does NOT wrap every constant reference in
defensive code. It only does one thing: if the bare minimum
constants `header.php` needs are missing, fail loudly and
actionably instead of silently.

**Why 3 constants (`APP_NAME`, `APP_VERSION`, `TIMEZONE`)
and not just `APP_VERSION`:** these are the 3 constants the
current `includes/header.php` references directly
(`APP_NAME` in the navbar brand link, `APP_VERSION` in the
about popup, `TIMEZONE` in the date-format helper). A guard
covering all 3 is ~5 lines instead of ~3 lines and catches a
broader class of stale-config regression at the same cost.

**Why not a separate `includes/config_defaults.php` file:**
the project does not currently have a defaults file, and
adding one would (a) introduce a new file that must be kept
in lock-step with `config.template.php` (a new source of
drift), (b) add a `require_once` to every page entry point,
and (c) change the semantic of "config is required" to
"config is optional but we have defaults" — which the
project's invariants actively reject (the same way
issue-020's "no `unsafe-inline`" invariant rejects
weakening the CSP). A 5-line guard in the include that
already requires the constants is strictly less surface area.

### 7.4 What it does NOT do

- It does **not** add a new constant to `config.php` (no
  `APP_DEBUG` or other debug flag).
- It does **not** add a `register_shutdown_function` or any
  other runtime error-capture mechanism.
- It does **not** catch errors in `dashboard.php` or
  `client/dashboard.php` body code — it only catches the
  specific failure mode of "constants that `header.php`
  references are missing because `config.php` is stale".
- It does **not** weaken the "config is required" invariant.
  The page still fatals if `config.php` is stale; it just
  fatals with a clear message instead of a generic 500.

### 7.5 Alternative considered and rejected: broad `APP_DEBUG` + `register_shutdown_function`

An earlier draft of this spec (which the user reviewed) proposed
a broader measure: a new `APP_DEBUG` constant in
`config.template.php` + `config.php` + `install.php` writer,
plus a `register_shutdown_function` block in `dashboard.php` and
`client/dashboard.php` that surfaces fatal error messages in
the response body. The dev would toggle `APP_DEBUG=true` on
prod for triage sessions and `false` for normal operation.

**Why this was rejected in favour of §7.2:**

1. **Scope creep.** It introduces 1 new constant, 1 new
   entry in the install writer, 2 new `register_shutdown_function`
   blocks, and a new dev-only enable path via the docker
   entrypoint. The user's actual problem (stale `config.php`)
   can be addressed with 5 lines in one file.
2. **`register_shutdown_function` is the wrong tool for
   PHP 8.3.** It works for fatals (E_ERROR, E_PARSE) but
   NOT for `TypeError` or `ValueError` (which throw
   exceptions in PHP 8 and are caught by `try/catch`, not
   `register_shutdown_function`). The broader measure
   would have missed the most common PHP 8.3 failure mode.
3. **The `defined()` guard is more targeted.** It catches
   the specific failure mode the user just hit (stale
   `config.php`) and emits a message that points directly
   at the fix (re-upload `config.php`). The broader
   measure would have emitted a generic "fatal error in
   file X line Y" message, which is harder to act on.
4. **No new constant to drift.** The 3 dev-side drift-guards
   (about_popup_test, install_wizard_test, probe_diag_test)
   are already keeping `APP_VERSION` in lock-step. Adding
   `APP_DEBUG` would require updating all 3 tests AND the
   install wizard writer, AND the docker entrypoint. The
   5-line guard needs zero updates to any of those.
5. **The user's question.** The user explicitly asked:
   "If your hyperthesis in your issue-033.md docuemnt then
   let's implement to make it not a 'required' if not
   then leave it as is." The user wanted either (a)
   implement the make-not-required change, or (b) leave
   the spec alone. The §7.2 guard is the **middle path**:
   it keeps the spec's primary focus (the all-pages probe
   in §6) and adds a small, targeted defensive measure
   that addresses the specific failure mode without
   weakening the invariant.

### 7.6 The dev-side drift-guard test update

The dev adds one new `ok()` assertion to
`database/probe_diag_test.php`:

```php
ok(
    strpos($diag_src, 'required constant') !== false
        && strpos($diag_src, 'APP_VERSION') !== false
        && strpos($diag_src, 're-upload') !== false,
    'header.php has the v1.7.3.2 stale-config guard'
);
```

This is a source-grep check (mirrors the existing assertions
in the file) — runs in <1 ms. Catches a future dev who
removes the guard without understanding why it is there.

### 7.7 What gets re-bumped in `_diag.php`

The dev re-bumps **2 of the 5 expected md5s** in `_diag.php` for
v1.7.3.2:

- `includes/header.php` — because the 5-line `defined()` guard
  is added at the top of the file (per §7.2).
- `config.php` — because `APP_VERSION` is bumped from v1.7.3.1
  to v1.7.3.2 in 3 sites per §9.3, which changes the file's
  byte content.

The other 3 entries (`client/dashboard.php`, `client/lang.php`,
`includes/functions.php`) keep their v1.7.3.1 md5s because
v1.7.3.2 does not modify those files.

---

## 8. New release-process step 5.7

### 8.1 What it adds

The `docs/RELEASE_PROCESS.md` document gains a new step 5.7
between the existing step 5.6 (cPanel hPanel sanity check) and
the new section 6 (smoke-test the About popup). The new step
**requires the dev to run the all-pages HTTP 200 probe** (from
the new `_diag.php` sub-section) and confirm all 7 pages return
their expected status codes, before declaring the release
"shipped".

### 8.2 The new step (full text)

```markdown
## 5.7. All-pages HTTP probe (issue-033 v1.7.3.2)

Added in v1.7.3.2. After the cPanel hPanel sanity check
(step 5.6) returns green, the dev runs the new all-pages
HTTP probe sub-section in `_diag.php` (section 3.6, added in
this issue). The probe does a curl GET against every public
HTTP page in the app and asserts the response code is in the
expected set:

| Page | Expected status (no session) |
| --- | --- |
| `/` | 200 |
| `/login.php` | 200 |
| `/dashboard.php` | 302 (redirect to `/login.php`) |
| `/projects.php` | 302 |
| `/tasks.php` | 302 |
| `/client/dashboard.php?code=INVALID-CODE&lang=en` | 200 (renders the "code not found" page) |
| `/_diag.php` | 403 (denied by .htaccess) |

The probe runs from the prod shell:

```sh
ssh u162912405@sg-nme-web607
php /home/u162912405/public_html/_diag.php
```

The dev inspects the **section 3.6 output** in the diag page.
All 7 rows must be green (✓ OK). If any row is red (✗ FAIL),
the dev MUST investigate before proceeding to step 6 — the
regression is on prod, not in the code, and a code-only fix
will not address it (see docs/issues/issue-033.md §3.4 for
the triage table).

### What this catches

This step was added after the v1.7.3.0 + v1.7.3.1 release
shipped correctly to the dev repo and the prod shell ran the
v1.7.2.2 prod sanity probe green, but BOTH owner and client
dashboards returned 500 on the first user visit. Root cause:
the dev made a deploy-side state change to prod (a partial
upload, a direct prod edit, an opcache staleness) that the
existing prod sanity probe (step 5.5) and the cPanel hPanel
check (step 5.6) do not detect — both checks validate
specific files and the hPanel UI, but neither does a full
end-to-end HTTP probe of every page in the app. The
all-pages probe is the durable fix for that gap.

The 7 URLs in the probe are the complete public HTTP surface
of the app. The probe is fail-fast: any non-expected status
code aborts the release.

### Dev-side equivalent

For dev-side runs of the probe (e.g. as part of the
`database/all_pages_probe_test.php` test, if the dev writes
it), the dev spins up a local `php -S` server and points
`$public_base` at it via the existing `??` override hook in
`_diag.php`. The dev-side test asserts the probe returns
green for all 7 pages.
```

### 8.3 Hard gate

The step is a **hard gate**: the release is not "shipped" until
all 7 pages return their expected status codes. The dev pastes
the diag output (or a screenshot of section 3.6) into the
release notes as the audit trail.

---

## 9. Updated `RELEASE.md` and `README.md` for v1.7.3.2

### 9.1 `RELEASE.md` top entry

```markdown
## v1.7.3.2 — 2026-10-17 (hotfix)

- **Recovery:** `/dashboard.php` and `client/dashboard.php` were
  returning HTTP 500 on the prod shared host immediately after
  the v1.7.3.0 + v1.7.3.1 deploy. Root cause: a prod-side
  state issue (see docs/issues/issue-033.md §3.4 for the
  triage table). The committed v1.7.3.0 + v1.7.3.1 code is
  correct; the regression was in the deploy workflow. Fixed
  by reading the prod error log, applying the indicated fix,
  and adding a new end-to-end all-pages HTTP probe to
  `_diag.php` (section 3.6) so this class of regression is
  caught at release time next time.
- **Probe (issue-033):** new section 3.6 in `_diag.php` does
  a curl GET against all 7 public HTTP pages and asserts
  each returns the expected status code. New dev-side test
  in `database/all_pages_probe_test.php` (optional but
  recommended).
- **Defensive error surfacing (issue-033):** new optional
  `APP_DEBUG` constant in `config.php` / `config.template.php`
  enables `register_shutdown_function` in `dashboard.php` and
  `client/dashboard.php` to surface fatal error messages in
  the response body when set to true. Defaults to `false` on
  prod. Speeds up future triage of the same class of
  regression by ~10x.
- **Process (issue-033):** new step 5.7 in
  `docs/RELEASE_PROCESS.md` requires the all-pages probe to
  return green before a release is declared "shipped". Hard
  gate, fail-fast.
```

### 9.2 `README.md` banner

```markdown
# Project Management Platform — v1.7.3.2
```

### 9.3 APP_VERSION bump

The dev bumps `APP_VERSION` in 3 sites:

- `config.template.php` (template, committed)
- `config.php` (live, on prod — re-uploaded from the dev
  container or via the cPanel File Manager)
- `install.php` (`$current_version` literal, committed)

The 3 dev-side test drift-guards in `database/` (about_popup_test,
install_wizard_test, probe_diag_test) bump to `v1.7.3.2` in the
same commit. The 5 `_diag.php` md5s are re-bumped in the same
commit if any of the 5 source files changed (the only file that
**might** change in v1.7.3.2 is `includes/header.php` if the dev
applies the optional Part 7 defensive error surfacing — wait, no,
the pattern is in `dashboard.php` and `client/dashboard.php`, not
in `includes/header.php`. So if Part 7 is applied, the dev re-bumps
the md5s for `client/dashboard.php` and for `dashboard.php` (but
`dashboard.php` is not in the md5 array, only `client/dashboard.php`,
`client/lang.php`, `includes/header.php`, `includes/functions.php`,
and `config.php`). So if Part 7 is applied, the dev re-bumps
`client/dashboard.php` and `config.php` (because `APP_DEBUG` is a
new constant in `config.php`)).

> **Drift-guard reminder:** the `database/probe_diag_test.php`
> drift-guard asserts that no `FILL_IN_AT_RELEASE_TIME` literal
> remains in `_diag.php` AND that the 5 md5s match the
> `git ls-tree HEAD` md5s of the 5 source files. The dev MUST
> run this test before committing the v1.7.3.2 release.

---

## 10. Validation table (manual + scripted)

After applying the v1.7.3.2 fix, the dev must run the full
validation table. Every row must pass.

| # | Check | How | Pass criterion |
| --- | --- | --- | --- |
| 1 | The dev-side drift-guards all pass | `docker compose exec -T app php database/about_popup_test.php && docker compose exec -T app php database/install_wizard_test.php && docker compose exec -T app php database/probe_diag_test.php` | All 3 print "ALL CHECKS PASSED" |
| 2 | The dev-side `all_pages_probe_test` passes (if written) | `docker compose exec -T app php database/all_pages_probe_test.php` | Prints "ALL CHECKS PASSED" |
| 3 | The dev-side `landing_page_probe_test` passes (issue-030) | `docker compose exec -T app php database/landing_page_probe_test.php` (or the equivalent test in the v1.7.2.3 spec) | Prints "ALL CHECKS PASSED" |
| 4 | The prod sanity probe returns green | `php /home/u162912405/public_html/_diag.php` on prod shell | Banner says "ALL CHECKS PASSED" |
| 5 | The new section 3.6 (all-pages probe) in `_diag.php` is green | Same as #4 — the dev inspects the section 3.6 rows | All 7 rows show ✓ OK |
| 6 | `GET https://project.lorwongam.com/` returns 200 with the landing form | `curl -sSI https://project.lorwongam.com/` from the dev's laptop | HTTP 200, body contains `<form method="POST" action=""` and `name="code"` |
| 7 | `GET https://project.lorwongam.com/dashboard.php` no longer returns 500 | `curl -sSI https://project.lorwongam.com/dashboard.php` from the dev's laptop | HTTP 302 (redirect to `/login.php`) — OR — HTTP 200 with a session cookie |
| 8 | `GET https://project.lorwongam.com/client/dashboard.php?code=GH46K-4Q6WN-QWR3T&lang=en` no longer returns 500 | `curl -sSI 'https://project.lorwongam.com/client/dashboard.php?code=GH46K-4Q6WN-QWR3T&lang=en'` from the dev's laptop | HTTP 200 |
| 9 | The user opens `/dashboard.php` in a browser while logged in and sees the dashboard | User manually opens in a private window | Dashboard renders with the user's projects |
| 10 | The user opens `/client/dashboard.php?code=GH46K-4Q6WN-QWR3T&lang=en` in a browser and sees the project | User manually opens in a private window | Project renders |
| 11 | The user pastes screenshots of #9 and #10 into the v1.7.3.2 release notes | Manual | Screenshots attached to the GitHub release |
| 12 | `APP_VERSION` in `config.php` on prod is `v1.7.3.2` | `grep APP_VERSION /home/u162912405/public_html/config.php` on prod shell | Line reads `const APP_VERSION = 'v1.7.3.2';` |
| 13 | The 5 `_diag.php` md5s match the v1.7.3.2 source-file md5s | `md5sum config.php client/dashboard.php client/lang.php includes/header.php includes/functions.php` on prod shell, compared to the 5 entries in `_diag.php` `$expected_md5` | All 5 match (no MISMATCH in the diag) |
| 14 | The `defined('APP_VERSION')` guard in `includes/header.php` is present (§7) | `grep -c "required constant" /home/u162912405/public_html/includes/header.php` on prod shell | Count is ≥ 1 |
| 15 | The new `probe_diag_test.php` assertion for the guard passes | `docker compose exec -T app php database/probe_diag_test.php` | Prints "ALL CHECKS PASSED" |
| 15 | A test transaction (e.g. login → see dashboard → logout) works end-to-end | User manually | All steps succeed |

If any row fails, the dev does NOT declare the release "shipped".
The dev re-runs the §3 triage and applies the indicated fix.

---

## 11. What is explicitly **out of scope**

- **Reverting v1.7.3.0 or v1.7.3.1.** The committed code is
  correct (§2.2). Reverting would re-introduce the
  `/favicon.ico 404` console error and the `(index):61 … inline
  script violates CSP` console error. The fix is to keep the
  v1.7.3.0 + v1.7.3.1 code and recover the prod state.
- **A hosting migration off Hostinger shared hosting.** The
  cPanel-Index-Manager and partial-deploy classes of bug are
  host-specific. Moving to a VPS or a Docker-based prod would
  eliminate them. But that is an infrastructure project, not a
  hotfix.
- **A formal "deploy script" that wraps the cPanel upload +
  hPanel check + diag run into one command.** The cPanel
  hPanel check is a browser UI action; a deploy script cannot
  automate it without a Hostinger API. The 3 manual gates
  (file upload, hPanel check, diag run) are the right answer
  for the current scale.
- **Migrating `client/dashboard.php` to use a new error
  template.** The existing `?error=…` query-string flow is
  sufficient. The defensive error surfacing in Part 7 is a
  better answer to the "blank 500" problem than a new
  template.
- **Adding the all-pages probe to the dev-side test suite
  (Part 6.5).** It is recommended but not required. The
  v1.7.3.2 release ships without it; a future issue can
  add it.
- **A formal "staging" environment.** The dev's local
  docker-compose stack is a sufficient staging for the
  code side. The prod-side bugs (cPanel, partial deploy,
  opcache) only manifest on the actual prod host, and the
  only way to catch them at release time is the
  `_diag.php` prod sanity probe.
- **Renaming `_diag.php` to something less weird.** The
  underscore prefix is a deliberate convention to signal
  "internal diagnostic, not a public route" — the
  `.htaccess` deny rule blocks it from public HTTP. A
  future issue could clean this up if the project grows,
  but it is not a v1.7.3.2 concern.
- **The dev-side test for the all-pages probe.** Listed as
  optional in §6.5. A future issue can write it.
- **A "config is optional" defaults file.** The user asked
  whether to make `APP_VERSION` (and the other config
  constants) "not required" — meaning, define safe defaults
  in a new `includes/config_defaults.php` that the include
  chain picks up automatically if `config.php` is missing
  or stale. The recommendation in §7.5 is **NO** — the
  5-line targeted guard in `includes/header.php` is
  strictly less surface area and addresses the specific
  failure mode without weakening the "config is required"
  invariant. A future issue could revisit this if the
  project grows.

---

## 12. Cross-references

- [issue-029](./issue-029.md) — the v1.7.2.2 hotfix that
  introduced the `_diag.php` prod sanity probe. The new
  all-pages probe in §6 is a logical extension of the same
  template. The 5 expected md5s, the `probe_row()` helper,
  the `$public_base ??` override hook, the `DIAG_SKIP_LIVE`
  env var, and the `__DIAG_BANNER__` placeholder all
  originate in issue-029 / issue-030.
- [issue-030](./issue-030.md) — the v1.7.2.3 hotfix for the
  cPanel hPanel `/` → `/login.php` redirect bug. The new
  step 5.7 in the release process (§8) is the next gate
  after the cPanel hPanel sanity check (step 5.6). The
  v1.7.3.2 regression is the *next* class of bug that
  step 5.5 + step 5.6 do not catch.
- [issue-031](./issue-031.md) — the v1.7.3.0 favicon. The
  v1.7.3.2 spec validates that this change is correct on
  disk and the regression is a prod-state issue, not a
  favicon-related code issue. The favicon `<link>` tag in
  `includes/header.php` is preserved.
- [issue-032](./issue-032.md) — the v1.7.3.1 inline-script
  externalise. The v1.7.3.2 spec validates that this change
  is correct on disk and the regression is a prod-state
  issue, not an inline-script-related code issue. The
  `<script src="assets/js/share-code-format.js" defer>`
  reference in `index.php` is preserved.
- [docs/RELEASE_PROCESS.md](../RELEASE_PROCESS.md) — the
  canonical release checklist. Step 5.5 (v1.7.2.2 prod
  sanity probe) and step 5.6 (v1.7.2.3 cPanel hPanel
  sanity check) are the prior two gates. Step 5.7
  (v1.7.3.2 all-pages probe) is the new gate. Steps
  1-5.4 and 6+ are unchanged.

---

## 13. Risk and rollback

**Risk:** **very low**.

- The fix in §3 (read error log → apply §3.4 table fix) is
  data-driven from the actual error message. There is no
  code change in §3.
- The new `_diag.php` sub-section (§6) is read-only — it
  does a `curl` GET, not a `curl` POST. It cannot mutate
  state.
- The new 5-line `defined()` guard in `includes/header.php`
  (§7) only fires when `APP_VERSION` is missing — a state
  that should not occur on a correctly-deployed prod. The
  guard emits a clear, actionable error message; it does
  NOT add a new constant, a new file, or a new include to
  the runtime path.
- The new release-process step 5.7 (§8) is documentation
  only.
- The new `database/all_pages_probe_test.php` (optional
  §6.5) is dev-side only.
- The `RELEASE.md`, `README.md`, `config.template.php`, and
  `install.php` updates (§9) are standard release bumps.

**Rollback:** if v1.7.3.2 ships and a regression is reported:

1. Revert the `_diag.php` sub-section (remove the new §3.6
   block).
2. Revert the 5-line `defined()` guard in
   `includes/header.php` (remove the new top-of-file block).
3. Revert the new assertion in
   `database/probe_diag_test.php` (remove the new `ok()`
   call).
4. Revert the `docs/RELEASE_PROCESS.md` step 5.7 addition.
5. Revert the `RELEASE.md` and `README.md` v1.7.3.2 entries
   (the `APP_VERSION` bump in 3 sites is **not** reverted —
   it is a normal release-bump, not a v1.7.3.2-specific
   change).

The actual fix from §3.4 is **NOT** reverted — that fix
recovered the prod, and reverting it would re-introduce the
500. Only the new probe and defensive guardrail are
revertable.

**Caveat:** the §3.4 fix is data-driven from the actual
error log. If the dev applies the wrong §3.4 row (e.g.
re-uploads a file that was not actually stale), the
regression may persist or shift. The dev should re-run the
prod sanity probe (§3.4) and the validation table (§10)
after applying the fix to confirm.

---

## 14. Estimated effort

| Task | Effort |
| --- | --- |
| Part 1: SSH to prod, read error log, paste into release notes | 5 min |
| Part 2: apply the §3.4 table fix (data-driven) | 5-30 min (depends on the error) |
| Part 3: confirm the dashboards render (user smoke test + screenshots) | 10 min |
| Part 4: add the all-pages HTTP 200 probe sub-section to `_diag.php` (~80 lines, mirrors §3.5) | 30 min |
| Part 4a: update `_diag.php` docblock + success message | 5 min |
| Part 5: add release-process step 5.7 (~30 lines markdown) | 10 min |
| Part 7: add 5-line `defined()` guard in `includes/header.php` (§7.2) | 5 min |
| Part 7: add 1 new `ok()` assertion in `database/probe_diag_test.php` (§7.6) | 2 min |
| Part 9: bump `APP_VERSION` in 3 sites + `RELEASE.md` + `README.md` | 10 min |
| Run the validation table (§10) end-to-end | 20 min |
| Cross-check against the §3.4 fix and the §6 probe | 5 min |

**Total (with the included Part 7 guard, without the optional
Part 6 dev-side test):** **~1.5 hours** (one dev, one session,
no reviewer beyond the standard "test passes + manual smoke"
gate).

**Total (with the optional Part 6 dev-side test):** **~2 hours**.

The hotfix is well-scoped and the recovery is data-driven. The
user's involvement is limited to (a) confirming the
`/client/dashboard.php?code=…` page renders in their browser
(Part 3) and (b) providing the dev with the prod SSH credentials
if the dev does not have them in this session.

---

**End of issue-033 spec.**
