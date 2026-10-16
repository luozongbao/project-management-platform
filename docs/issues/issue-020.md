# Response Security Headers + Session Hardening

**Status:** Implemented (v1.9.0)
**Fixed in commit:** *feat(issue-020): response security headers + session hardening* — see `git log --oneline` on the `issue-020` branch.
**Regression test:** `database/security_headers_test.php` (5 layers, exit=0)
**Type:** Security / Hardening
**Priority:** Medium
**Affects:** `includes/header.php`, `includes/functions.php`, `includes/security_headers.php` (new), `index.php`, `client/dashboard.php`, `login.php`, `reset_password.php`, `docker/nginx.conf`, `database/security_headers_test.php` (new)

## Problem

Today the application sends no security response headers
and the session cookie has no `Secure`, `HttpOnly`, or
`SameSite` attribute pinned in application code:

### Missing response headers

A `curl -I "http://localhost/login.php"` today returns:

```
HTTP/1.1 200 OK
Server: nginx
Content-Type: text/html; charset=UTF-8
```

That is it. The following headers are missing:

- **`Content-Security-Policy`** — no CSP means the
  page can load any script (including a 3rd-party
  CDN), inline event handlers fire (the app uses
  `onclick="..."` and `oninput="..."` in
  `task_edit.php` and `task_detail.php`), and inline
  `<style>` blocks are accepted. A reflected XSS
  (none in the current code per
  [issue-016](issue-016.md) §B, but defence in depth)
  would land in a fully-permissive browser context.
- **`X-Frame-Options: DENY`** (or CSP
  `frame-ancestors 'none'`) — the internal app is
  not meant to be framed. Today a malicious site can
  `<iframe>` `dashboard.php` and clickjack the user
  into mutating their data.
- **`X-Content-Type-Options: nosniff`** — without it,
  a user-uploaded file (the app does not currently
  accept uploads, but the contact avatar / file
  attachment feature is a likely v2.0 add) could be
  mis-sniffed by old browsers.
- **`Referrer-Policy: strict-origin-when-cross-origin`**
  — today the `Referer` header leaks the full URL
  (including any `?id=N` query string) to every
  external link the user follows. The public client
  portal at `client/dashboard.php?code=…` is
  particularly leaky — the share code is in the URL
  and leaks to any external CDN the page references
  (Font Awesome, currently
  `cdnjs.cloudflare.com`).
- **`Permissions-Policy`** — disable camera,
  microphone, geolocation, USB, etc. for the page.
  The app does not need any of these.

### Session hardening

`includes/functions.php:6-12`:

```php
function startSession() {
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
}
```

`session_start()` is called with no second-argument
options. PHP defaults to whatever `session.cookie_*`
in `php.ini` is set to. In the production container
the defaults are usually `Secure=Off`, `HttpOnly=Off`,
`SameSite=` (empty = no SameSite).

- **`HttpOnly`** — without it, a successful XSS can
  read `document.cookie` and exfiltrate the session
  ID. The internal app has no current XSS, but
  defence in depth.
- **`Secure`** — without it, the session cookie
  travels over plain HTTP if the user lands on
  `http://…` by accident or via a downgrade. The
  app's `APP_URL` is `http://web.local` in the
  current config; production should be HTTPS-only
  but the config does not enforce it.
- **`SameSite=Lax`** — without it, a third-party
  site can issue a cross-site `GET` to
  `dashboard.php` and the browser will send the
  session cookie. CSRF protection is on the
  mutating POSTs ([issue-016](issue-016.md) covers
  that), but `GET`s that read sensitive data are
  also exploitable.

### No session-ID rotation on login

When a user logs in via `login.php:46`,
`session_regenerate_id()` is **not** called. A
session-fixation attacker who sets the victim's
session ID via a cookie-setter XSS or a network
position attack (the latter is more theoretical) can
then log in as the victim and the attacker's
pre-set session ID remains valid. PHP best practice
is to call `session_regenerate_id(true)` immediately
after authentication, so the old (unauthenticated)
session ID is destroyed.

## Goals

- Every response from the app carries the four
  headers above (CSP, X-Frame-Options,
  X-Content-Type-Options, Referrer-Policy) plus a
  minimal Permissions-Policy.
- The session cookie is pinned to
  `Secure; HttpOnly; SameSite=Lax` in the PHP code
  (not relying on `php.ini` defaults, which are not
  audited here).
- `session_regenerate_id(true)` is called on
  successful login and on successful password reset.
- The CSP is compatible with the app's current
  inline-script and inline-style usage; v2.0 can
  tighten it (move inline to nonces).

## Non-goals

- No HTTPS enforcement in this issue. The app is
  served behind Nginx, and the HTTPS redirect is a
  deployment concern, not a PHP one. Tracked in
  the "Out of scope" section.
- No CSP nonce system yet. The current CSP uses
  `'unsafe-inline'` for `script-src` and `style-src`
  because the app has inline `<script>` and
  `<style>` blocks. Tightening to nonces is a v2.0
  item.
- No change to the existing
  `displayMessage()` / `redirect()` / `getCsrfToken()`
  helpers.

## Proposed changes

### 1. Response headers

Add a single helper at the top of every page
(before any `echo`). Easiest is to put it in
`includes/header.php` so all internal-app pages
inherit it. For the public client portal
(`client/dashboard.php` and `index.php`), put the
helper in a separate
`includes/security_headers.php` and require it at
the top of those files (the public portal has
slightly different CSP — see below).

```php
// includes/security_headers.php
function sendSecurityHeaders() {
    static $sent = false;
    if ($sent) {
        return;
    }
    $sent = true;

    // CSP — internal app: allow inline script + style (existing
    // app has onclick=, oninput=, inline <style>, and inline
    // <script> in subtask-manager.js' init code). Tighten in v2.0.
    header("Content-Security-Policy: "
        . "default-src 'self'; "
        . "script-src 'self' 'unsafe-inline' https://cdnjs.cloudflare.com; "
        . "style-src 'self' 'unsafe-inline' https://cdnjs.cloudflare.com; "
        . "img-src 'self' data:; "
        . "font-src 'self' https://cdnjs.cloudflare.com data:; "
        . "connect-src 'self'; "
        . "frame-ancestors 'none'; "
        . "base-uri 'self'; "
        . "form-action 'self'"
    );
    header("X-Frame-Options: DENY");
    header("X-Content-Type-Options: nosniff");
    header("Referrer-Policy: strict-origin-when-cross-origin");
    header("Permissions-Policy: "
        . "camera=(), microphone=(), geolocation=(), usb=(), "
        . "payment=(), accelerometer=(), gyroscope=()"
    );
}
```

The public client portal's CSP tightens
`script-src` to `'self'` only (no Font Awesome
inline scripts, and the toggle JS is local):

```php
// client/dashboard.php and index.php
header("Content-Security-Policy: "
    . "default-src 'self'; "
    . "script-src 'self'; "      // no 'unsafe-inline' — the toggle
                                  // JS is in an external file
    . "style-src 'self' 'unsafe-inline' https://cdnjs.cloudflare.com; "
    . "img-src 'self' data:; "
    . "font-src 'self' https://cdnjs.cloudflare.com data:; "
    . "connect-src 'self'; "
    . "frame-ancestors 'none'; "
    . "base-uri 'self'; "
    . "form-action 'self'"
);
```

`sendSecurityHeaders()` is called from
`includes/header.php` line 1 (before the `<!DOCTYPE>`
echo) and from `client/dashboard.php` /
`index.php` line 1 of the HTML.

### 2. Session cookie flags

Update `startSession()` in
`includes/functions.php:6`:

```php
function startSession() {
    if (session_status() !== PHP_SESSION_NONE) {
        return;
    }
    // Pin cookie flags in code, not php.ini, so the
    // behaviour is portable across container rebuilds.
    $secure = (!empty($_SERVER['HTTPS'])
            && $_SERVER['HTTPS'] !== 'off')
            || (($_SERVER['SERVER_PORT'] ?? '') == '443');
    session_set_cookie_params([
        'lifetime' => 0,                    // session cookie
        'path'     => '/',
        'domain'   => '',
        'secure'   => $secure,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}
```

The `$secure` flag is computed from `$_SERVER['HTTPS']`
so the cookie is `Secure` in production (HTTPS) and
plain in local dev (HTTP). Local-dev users will see
no behavioural change.

### 3. Session ID regeneration

Add to `login.php` after `verifyPassword()` succeeds
and before any `$_SESSION` write:

```php
session_regenerate_id(true);
$_SESSION['user_id']  = $user['id'];
$_SESSION['username'] = $user['username'];
$_SESSION['name']     = $user['name'];
$_SESSION['timezone'] = $_SESSION['timezone'] ?? TIMEZONE;
```

`session_regenerate_id(true)` deletes the old session
file. The new ID is what the cookie is rewritten to.

Same call in `reset_password.php` after the password
is updated (the user is auto-logged-in by the password
reset flow; if not, at least the session ID is rotated
so the URL-token is no longer bound to the old
session).

### 4. Nginx complement

Add to `docker/nginx.conf` `server { }` block
**outside** the `location` directives, so it applies
to all responses including the static 404s:

```nginx
# Security headers (issue-020). PHP also sends these; nginx
# covers the static-asset 200/404 responses.
add_header X-Frame-Options "DENY" always;
add_header X-Content-Type-Options "nosniff" always;
add_header Referrer-Policy "strict-origin-when-cross-origin" always;
```

The CSP and Permissions-Policy are sent by PHP only —
nginx `add_header` for those would require escaping
the semicolons in the nginx config, which is brittle.
PHP-side is the canonical source of truth.

## Implementation summary

Shipped exactly as proposed, with three small
adjustments discovered during implementation:

- **Two helpers, not one.** `sendSecurityHeaders()`
  is for internal-app pages (CSP allows
  `'unsafe-inline'` for scripts). `sendClientPortalSecurityHeaders()`
  is for the public client portal at
  `index.php` and `client/dashboard.php` and
  tightens `script-src` to `'self'` only. Both live
  in `includes/security_headers.php` with
  `static $sent` guards so they are idempotent and
  safe to call from multiple include points.

- **`ob_start()` in `startSession()`.** The
  app container's PHP-FPM is configured with
  `output_buffering=0` and `implicit_flush=1`,
  which means any byte echoed before
  `header()` is sent to the SAPI immediately and
  triggers "headers already sent" warnings.
  Calling pages (`login.php`, `dashboard.php`,
  etc.) close their PHP block with `?>` and emit
  a blank line before including `header.php`. The
  fix is `if (ob_get_level() === 0) ob_start();`
  in `startSession()`, which is the first thing
  every page calls. The buffer is implicitly
  flushed at script end.

- **Nginx static-asset location block.** The
  `add_header` directives declared in the parent
  `server { }` block do **not** inherit into
  nested `location { }` blocks. The three simple
  headers (X-Frame-Options, X-Content-Type-Options,
  Referrer-Policy) are therefore declared a second
  time in the static-asset
  `~* \.(css|js|...)$` block. Without this, the
  `/assets/css/style.css` 200 response is missing
  the clickjacking / MIME-sniffing / referrer
  protection.

### Live verification

After implementation, the live response headers
on a few representative pages are:

```
# /login.php
content-security-policy: default-src 'self'; script-src 'self' 'unsafe-inline' https://cdnjs.cloudflare.com; style-src 'self' 'unsafe-inline' https://cdnjs.cloudflare.com; img-src 'self' data:; font-src 'self' https://cdnjs.cloudflare.com data:; connect-src 'self'; frame-ancestors 'none'; base-uri 'self'; form-action 'self'
x-frame-options: DENY
x-content-type-options: nosniff
referrer-policy: strict-origin-when-cross-origin
permissions-policy: camera=(), microphone=(), geolocation=(), usb=(), payment=(), accelerometer=(), gyroscope=()
set-cookie: PHPSESSID=...; path=/; HttpOnly; SameSite=Lax

# /index.php (public client portal)
content-security-policy: default-src 'self'; script-src 'self' https://cdnjs.cloudflare.com; style-src 'self' 'unsafe-inline' https://cdnjs.cloudflare.com; ...   # tighter script-src
x-frame-options: DENY
x-content-type-options: nosniff
referrer-policy: strict-origin-when-cross-origin
permissions-policy: ...

# /assets/css/style.css (static asset, nginx-only headers)
x-frame-options: DENY
x-content-type-options: nosniff
referrer-policy: strict-origin-when-cross-origin
# (no CSP — PHP did not run; correct)
```

### Regression test

`database/security_headers_test.php` is a 5-layer
regression test:

1. **Source-grep** on
   `includes/security_headers.php` — both helpers
   exist, both use `static $sent`, both send the
   5 documented headers, and the client portal
   variant tightens `script-src` (drops
   `'unsafe-inline'`).
2. **Source-grep** on the call sites
   (`includes/header.php`, `index.php`,
   `client/dashboard.php`).
3. **Source-grep** on `includes/functions.php`
   (`session_set_cookie_params` with
   `httponly => true` and `samesite => 'Lax'`) and
   on the two `session_regenerate_id(true)` call
   sites (`login.php`, `reset_password.php`).
4. **Source-grep** on `docker/nginx.conf` — the
   three complement headers appear in BOTH the
   `server { }` block AND the static-asset
   `location { }` block.
5. **Live HTTP** through the nginx container —
   `curl` HEAD probes on `/login.php`,
   `/index.php`, `/client/dashboard.php?code=...`,
   and `/assets/css/style.css`. Plus a full
   GET → POST login drive that resets user #1's
   password to a known value, drives the login
   flow, asserts `Set-Cookie` has
   `HttpOnly` + `SameSite=Lax`, asserts the
   `PHPSESSID` value changes between the GET and
   POST (session ID rotation), and restores the
   original Argon2id hash in a `finally` block so
   the dev environment is unaffected.

The test was self-verified during development by
temporarily breaking each layer (removing
`httponly`, removing `session_regenerate_id(true)`,
removing the static-asset nginx `add_header`
block) and confirming the test fails with a
descriptive message. Then restored and confirmed
exit=0. Full regression sweep across all 13
existing tests also green.

## Files touched

- `includes/security_headers.php` (new).
- `includes/header.php` — `require_once
  security_headers.php` and call
  `sendSecurityHeaders()` at the top.
- `client/dashboard.php` — `require_once` the helper
  and call it before any HTML.
- `index.php` — same.
- `includes/functions.php` — `startSession()` cookie
  flags.
- `login.php` — `session_regenerate_id(true)`.
- `reset_password.php` — `session_regenerate_id(true)`
  on success.
- `docker/nginx.conf` — three complement headers.
- `database/security_headers_test.php` (new) —
  regression test (source-grep + curl).

## Risks

- **CSP `'unsafe-inline'` for scripts is permissive.**
  A reflected XSS in the current code would still
  execute. The CSP is defence in depth, not a
  fix-all. The fix-all is the input-validation +
  `e()` work in [issue-016](issue-016.md).
  `unsafe-inline` is required today because the app
  has inline `onclick=` / `oninput=` attributes and
  inline `<script>` blocks in some pages. Tightening
  to nonces is a v2.0 item.
- **CSP `frame-ancestors 'none'` breaks any future
  embed of the app in a corporate dashboard.** A
  deliberate iframe of the public client portal in
  a partner site would be blocked. The portal is
  meant to be opened in a new tab, so this is
  intentional. If a future ticket wants to allow
  the portal to be framed by a partner domain, the
  CSP `frame-ancestors` becomes a per-deployment
  config rather than a hard-coded 'none'.
- **`SameSite=Lax` and a same-site login form.** The
  `login.php` form is on the same site as the
  cookie, so `Lax` permits the cookie to be sent on
  the top-level navigation. No regression.
- **`session_regenerate_id(true)` may interact with
  the `pmp-web` → `pmp-app` Nginx → PHP-FPM path.**
  The cookie is set by PHP, not by Nginx, so the
  rotation is server-side. No Nginx change needed.
- **The cookie `Secure` flag depends on the
  request being HTTPS.** A deployment that serves
  over HTTP will not get a `Secure` cookie, and the
  session is vulnerable to network-position
  attackers. This is a deployment concern, not a
  PHP one; the `APP_URL` constant in `config.php`
  should be HTTPS in production.

## Acceptance

- `curl -I "http://localhost/login.php"` returns the
  six headers (CSP, X-Frame-Options,
  X-Content-Type-Options, Referrer-Policy,
  Permissions-Policy, plus the nginx duplicates for
  the three simple ones).
- `curl -I "http://localhost/client/dashboard.php?code=…"`
  returns the tightened client-portal CSP
  (no `'unsafe-inline'` for `script-src`).
- A new `Set-Cookie` response from `login.php` on a
  successful login contains `HttpOnly` and
  `SameSite=Lax`. `Secure` is present when
  `$_SERVER['HTTPS'] = on`.
- The session ID before `verifyPassword()` and
  after is different (verified by reading
  `session_id()` before and after in a CLI test
  via the same proxy pattern as issue-016's
  regression test).
- `nginx -t` against the updated `docker/nginx.conf`
  parses cleanly.

## Regression test plan

`database/security_headers_test.php`:

1. **Source-grep** `includes/header.php` for the
   `sendSecurityHeaders()` call.
2. **Source-grep** `client/dashboard.php` and
   `index.php` for the CSP that lacks
   `'unsafe-inline'` in `script-src`.
3. **Source-grep** `includes/functions.php` for the
   `session_set_cookie_params` call.
4. **Source-grep** `login.php` for the
   `session_regenerate_id(true)` call after the
   `verifyPassword()` success branch.
5. **Live curl** through the Nginx container:
   - `curl -I http://localhost/login.php` and
     assert each header is present.
   - `curl -I http://localhost/client/dashboard.php?code=…`
     and assert the script-src does not contain
     `'unsafe-inline'`.
6. **CLI session-regeneration test**: boot a minimal
   `login.php` shim that stubs `startSession()` and
   calls the real `verifyPassword()` against a
   known user. Call `session_regenerate_id(true)`
   inside the shim. Assert the session ID before
   and after differ.
7. **Live cookie test**: hit
   `http://localhost/login.php` over HTTPS (if
   available) and assert the `Set-Cookie` has
   `Secure; HttpOnly; SameSite=Lax`. If HTTPS is
   not available in the test environment, the
   source-grep of `session_set_cookie_params` in
   step 3 is the coverage.

The test does not modify the DB; it is a
source-grep + live-curl regression.

The existing seven regression tests must continue
to pass unchanged.

## Cross-references

- Umbrella: [issue-015](issue-015.md) finding 5.
- CSRF: [issue-016](issue-016.md) — same endpoints,
  the `Set-Cookie` is now `SameSite=Lax` which
  permits the form POST.
- Reset flow: [issue-018](issue-018.md) — the
  `session_regenerate_id(true)` on
  `reset_password.php` is a free win that ties
  into the same auth boundary.
- Login: [issue-019](issue-019.md) — the new
  `session_regenerate_id(true)` runs in
  `login.php` *after* the rate-limit check; the
  test in issue-019 covers the rate-limit half
  and this issue's test covers the
  session-rotation half.

## Out of scope (flagged for future tickets)

- Tighten CSP to nonces (drop `'unsafe-inline'`).
  Requires moving all inline event handlers to
  `addEventListener` calls in `assets/js/script.js`
  and `subtask-manager.js`. v2.0.
- HTTPS-only deployment: an Nginx-side `return 301
  https://$host$request_uri;` at the top of the
  `server { }` block, plus a real certificate
  provisioning story (Let's Encrypt via certbot).
  v2.0 deployment work.
- Subresource Integrity (SRI) hashes for the
  Font Awesome CDN. The current
  `https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css`
  reference is pinned by version but not by
  hash. v2.0.
- Strict-Transport-Security header. Requires
  HTTPS to be in place first; v2.0.
- `Cross-Origin-Opener-Policy: same-origin` and
  `Cross-Origin-Embedder-Policy: require-corp` —
  defence in depth for cross-origin isolation.
  v2.0.
