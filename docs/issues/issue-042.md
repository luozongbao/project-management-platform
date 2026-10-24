# issue-042 — Two long-failing regression tests were stale, not broken

**Status:** Fixed
**Branch:** `v.2.0`
**Tests repaired:** `database/security_headers_test.php`, `database/client_share_code_protection_test.php`
**Result:** 18 PASS / 2 FAIL → **20 PASS / 0 FAIL**

---

## Summary

Two regression tests had been failing since issue-040 landed. Before touching
any code, I verified the behaviour each test was supposed to protect. In both
cases **the protection was present and working** — the tests were asserting
against pre-issue-040 file layout and URL shapes. They had been failing long
enough to be background noise, which is exactly why it's worth checking whether
they found bugs or had been overtaken by events.

They had been overtaken by events.

---

## Test 1: `client_share_code_protection_test.php` (8 failures)

### Diagnosis

The test source-grepped `index.php` for share-code rate limiting:

```php
ok("index.php calls isShareCodeRateLimited", ...);
ok("index.php sets HTTP 429 on rate-limit hit", ...);
```

But the landing page body now lives in `includes/landing.php`;
`index.php` is a thin shim that routes to it. The grep was reading a file that
no longer contained the code it was looking for.

It also asserted:

```php
ok("config.php defines SHARE_CODE_IP_LIMIT", ...);
```

`config.php` has never defined these, and is **not supposed to**. The three
limits are read through `defined()` guards in `includes/functions.php:1087-1089`
so a deployment can tune them without shipping a modified `config.php`:

```php
$ipLimit       = defined('SHARE_CODE_IP_LIMIT')       ? SHARE_CODE_IP_LIMIT       : 20;
$ipWindow      = defined('SHARE_CODE_IP_WINDOW')      ? SHARE_CODE_IP_WINDOW      : 300;
$distinctLimit = defined('SHARE_CODE_DISTINCT_LIMIT') ? SHARE_CODE_DISTINCT_LIMIT : 5;
```

### Verification before changing anything

Probed the live rate limit rather than assuming:

- 25 bogus GETs to `/?code=` → **21× 200 + 4× 429** (cap is 20 per 5 min)
- 25 POSTs to `/?code=` → **25× 429** (already tripped by the GET run)

Both entry points are protected. The guard runs *before* the format/empty/exists
checks, so an attacker can't burn probes for free.

### Fix

1. Retargeted the six source-greps from `index.php` → `includes/landing.php`.
2. Replaced the three `config.php` constant assertions with assertions that the
   `defined()` guards exist **and that their fallback defaults are 20 / 300 / 5**.

Note on (2): the guards were *already* asserted a few lines below by the
existing section 8, so adding guard assertions again would have been
duplication. The genuinely missing coverage was the **default values** — a
guard whose default was `0` (locking every client out) or `999999` (disabling
the limit entirely) would still pass a guard-only check. So the new assertions
pin the actual fallback numbers.

---

## Test 2: `security_headers_test.php` (5 failures)

### Diagnosis

Three live probes hit legacy `.php` URLs: `$BASE/login.php` (×3) and
`$BASE/index.php`. Since issue-040 those are canonical-URL redirects:

```
$ curl -s -D - -o /dev/null http://localhost/login.php
HTTP/1.1 301 Moved Permanently
Location: /login
```

A 301 carries **no body, no `Content-Security-Policy`, and no `Set-Cookie`**.
The test was probing the redirect, not the page — so it asserted headers the
application never intended to put on a redirect. The remaining two failures
were the same stale-location problem as Test 1.

### Verification before changing anything

`GET /login` returns every header the test wanted:

| Header | Value |
|---|---|
| `Content-Security-Policy` | present, `frame-ancestors 'none'`, no `unsafe-inline` in `script-src` |
| `Permissions-Policy` | `camera=(), microphone=(), geolocation=(), usb=(), payment=(), accelerometer=(), gyroscope=()` |
| `X-Frame-Options` | `DENY` |
| `X-Content-Type-Options` | `nosniff` |
| `Referrer-Policy` | `strict-origin-when-cross-origin` |
| `Set-Cookie` | `PHPSESSID=…; path=/; HttpOnly; SameSite=Lax` |

### Fix

1. `httpHead()` gained `CURLOPT_FOLLOWLOCATION` (and `MAXREDIRS => 5`), so a
   probe hits the real page regardless of which URL it starts from.
2. Live probes retargeted to canonical URLs: `/login.php` → `/login`,
   `/index.php` → `/`.
3. Source-grep loop retargeted from `index.php` → `includes/landing.php`.
4. The POST-login assertion checked for `Location: dashboard.php`; the handler
   now redirects to `Location: /dashboard`. Updated, and verified against a
   real login before editing rather than guessing.

---

## Cleanup

- Deleted the now-empty "9. Source-grep on config.php" section rather than
  leaving a header that asserted nothing.
- Renumbered the trailing sections 10–14 → 9–13 so the list stays sequential.
- Removed the resulting unused `$cfgSrc` / `$cfgCode` variables.
- Updated the file's header docblock to name the canonical URLs actually probed.

---

## The lesson worth keeping

> **A failing test is a claim about the code, not proof of a defect.**
> Both of these had been red long enough to be ignored. Either could have been
> "fixed" by tightening the assertions back into passing, which would have
> buried the real breakage. Checking the live behaviour first — curl for the
> headers, 25 requests for the rate limit — took five minutes and established
> that the security controls were intact and the *tests* were the stale artifact.

> **After the issue-040 canonical-URL change, any test probing a legacy `.php`
> URL is now testing a bodiless 301.** Headers and cookies are absent by
> design. This will silently affect every future test that touches a URL
> literal, so new probes should use canonical paths and follow redirects.

---

## Files changed

| File | Change |
|---|---|
| `database/security_headers_test.php` | `httpHead()` follows redirects; 3 live probes → canonical URLs; source-grep → `includes/landing.php`; POST redirect assertion → `/dashboard` |
| `database/client_share_code_protection_test.php` | 6 source-greps → `includes/landing.php`; 3 config-constant assertions → default-value assertions; empty section 9 removed; sections renumbered |