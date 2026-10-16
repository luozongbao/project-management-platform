# Password-Reset Redirect Loop on Render

**Status:** Implemented (v1.11.0)
**Type:** Bug
**Priority:** High
**Affects:** `reset_password.php`, new `database/reset_password_redirect_loop_test.php`

## Problem

> "My password reset link, which I clicked immediately cannot
> be used and I got 'Invalid Reset Link' info said, that the
> link might be invalid or expired." — real-browser test 01

The user received the reset email, clicked the link inside the
1-hour validity window, and was told the link was invalid or
expired. The browser showed either a "Too many redirects" error
(on Chrome/Firefox) or a cached "Invalid Reset Link" page (on
Safari, which silently gives up on the 20th redirect and falls
back to whatever it already had).

### Root cause

`reset_password.php` was implemented (in the v1.8.0 issue-018
commit, `9705cee`) with a "rotate on render" pattern intended
to invalidate a URL leaked via browser history the moment the
user opens the page. The intent was reasonable; the
implementation was not:

```php
// OLD code (removed in v1.11.0):
$rotated_token = rotatePasswordResetToken($token);
if ($rotated_token !== $token) {
    header('Location: reset_password.php?token=' . urlencode($rotated_token));
    exit;
}
```

The problem: `rotatePasswordResetToken()` **always** returns
a fresh token. It does not check whether the existing token is
already the "current" one; it generates a new random 32-char
hex string on every call (`generateToken()` →
`bin2hex(random_bytes(16))`). So the comparison
`$rotated_token !== $token` was **always** true and the 302
**always** fired. The browser followed the next request; the
page rotated the new token into a third value; the 302 fired
again; and so on. The user never saw the form.

Two distinct failure modes followed from the same root cause:

1. **Loop exhaustion (Chrome / Firefox).** The browser
   detected the loop after 20 hops and showed "ERR_TOO_MANY_REDIRECTS".
   The user, reading the email subject line, naturally assumed
   the link had expired.
2. **Race / cache poisoning (Safari).** Safari silently
   abandons the redirect chain after the 20th hop and renders
   whatever it already has — and one of the early responses in
   the chain (when the `password_reset_tokens` table had been
   hit by another request and the row was gone) is the "Invalid
   or expired reset link" page. The user, on a fresh click,
   sees a cached stale failure page.

In both cases the **fix** to the user's experience was the
same: do not rotate on render.

### Why was this code added in the first place?

The original comment in the v1.8.0 patch said:

> // Security measure: invalidate the URL if it ends up in browser
> // history. The user will use the rotated URL going forward.

The threat model it was protecting against was a URL leaked
to browser history or to a clipboard manager. The
counter-measure did not address that threat — the user's
browser history would still record the rotated URL, and the
next visitor of the history entry would hit the same
`rotatePasswordResetToken($token)` → fresh token → 302 loop.
The rotation would also have failed open if the user opened
the link in two tabs, opened it from a different browser, or
shared it with a colleague.

The correct counter-measure is **bulk invalidation on the
successful POST** (which the existing
`invalidateAllPasswordResetTokensForUser` helper already
provides). That single call clears every live token for the
user, including the one in browser history, the one in the
other tab, and the one the attacker was sitting on. The
existing flow already invoked this helper on success — the
rotation on render was redundant and harmful.

## Fix

Remove the rotation-on-render block and the 302 redirect.
The token in the URL stays valid for the whole form session
until either the user submits a new password (which triggers
`invalidateAllPasswordResetTokensForUser`) or the 1-hour
expiry elapses.

```php
// NEW code (v1.11.0):
if ($reset_data) {
    $clientIp = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    if (isPasswordResetRateLimited($clientIp, 'consume')) {
        $errors[] = "Invalid or expired reset link";
    } else {
        $valid_token = true;
    }
    // ...POST handling unchanged...
}
```

The rate-limit check stays in place — it is the right defense
against a brute-force campaign against a known good token, and
it reuses the same "Invalid or expired reset link" string so
an attacker cannot distinguish "rate limited" from "bad token"
from "expired token".

## Files Touched

- `reset_password.php` — removed the rotation-on-render block
  and the 302 redirect. Rate-limit check preserved. POST
  handling unchanged.
- `database/reset_password_redirect_loop_test.php` (new) —
  12-section, 37-assertion regression test.
- `database/password_reset_attempts_test.php` — Section 8
  updated to assert the broken `rotatePasswordResetToken` +
  302 pattern is GONE from `reset_password.php` (the previous
  section 8 encoded the broken behavior as a positive
  assertion; that assertion would have failed if a future
  maintainer tried to "restore" the loop).

## Regression Test

`database/reset_password_redirect_loop_test.php` — 12 sections,
37 assertions. The sections are:

1. `rotatePasswordResetToken` always returns a non-null,
   non-empty hex token (the unit-level proof that the
   rotation function cannot be the "no-op on second call"
   the v1.8.0 comment claimed it was).
2. Two consecutive rotations on fresh seeds produce two
   distinct tokens (the unit-level proof that the old
   redirect loop was tautological).
3-5. Source-grep on `reset_password.php` (7 assertions): no
   `rotatePasswordResetToken(` call, no 302 to
   `reset_password.php?token=`, no `urlencode($rotated_token)`,
   and the expected helpers are still called
   (`invalidateAllPasswordResetTokensForUser`,
   `recordPasswordResetAttempt`,
   `isPasswordResetRateLimited`).
6-9. Live HTTP: GET `/reset_password.php?token=VALID` returns
   200 with a `<form>` tag (not 302); the same token called
   twice returns 200 both times; an invalid token returns 200
   with the "Invalid Reset Link" H4 block; no token returns
   200 with the same block.
10. End-to-end: GET form → POST with new password → assert
    the token is deleted, all of the user's tokens are
    deleted (bulk-invalidate), and the new password is the
    correct login credential.
11. After successful reset, the old token returns the
    "Invalid Reset Link" H4 block (defense against bookmark
    reuse).
12. Lint touched files.

The regression net is self-tested: with the v1.8.0 broken
code restored, the test fails 11 assertions (302 status on
GET, missing `<form>` tag, end-to-end POST fails because the
rotation created a 2nd token that is not the one the POST was
sent with, etc.). With the v1.11.0 fix in place, all 37
assertions pass.
