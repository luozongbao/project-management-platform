# GET /reset-password returns 404 (slug mapped but unreachable)

**Status:** Open
**Type:** Bug / Routing
**Depends on:** [issue-035](./issue-035.md)
**Found during:** Phase 1.4a (v2.0 visual redesign)

## Problem

The slug route `/reset-password` is declared in `includes/Router.php` (line 41)
and maps to `reset_password.php` with params `reset` / `token`. The route also
has a param extractor at line 290 for `/reset-password/<token>`.

Despite the mapping, both forms return **HTTP 404** from nginx:

```
/reset-password             404
/reset-password/abc123      404
```

This is **specific to `reset-password`**. Every other slug resolves correctly:

```
/forgot-password   200
/login              200
/dashboard         302   (auth redirect -- correct)
/projects          302   (auth redirect -- correct)
```

The legacy non-slug path still 301s to the slug, so a user following a
password-reset email link lands on a 404 and cannot reset their password at
all. The reset flow is effectively broken in production.

## Evidence

- `includes/Router.php:41` — `'/reset-password' => ['*', 'reset_password.php', 'reset', 'token']`
- `docker/nginx.conf:84` — the PHP-redirect location lists the **snake_case**
  filenames (`reset_password`), not the slugs. Suspected interaction point, but
  the root cause is not yet established: it is not confirmed whether the 404
  comes from nginx failing to hand the slug to `index.php`, or from
  `index.php`/the router declining to dispatch the path.
- Reproduces at commit `66b1f7b` (verified by stashing all local changes), so
  it is **pre-existing and unrelated to the Phase 1.4a restyle**.

## Impact

- **User-facing:** every password-reset link emailed to a user is dead. This
  is the account-recovery path.
- **Test-suite:** this is the sole cause of the long-standing
  `reset_password_redirect_loop_test.php` failure, which expects
  `/reset_password.php?token=...` to return 200 but observes the 301 to
  `/reset-password?token=...` followed by this 404.

## Requirements

- `GET /reset-password` and `GET /reset-password/<token>` must both resolve to
  `reset_password.php` and return 200, matching every other slug.
- Must not weaken the token validation performed by `reset_password.php`: an
  absent, malformed or expired token must still render the invalid-token
  branch, never the reset form.
- Must not alter the `/reset-password/<token>` param extractor at
  `includes/Router.php:290`.
- Fix must not require nginx changes if the cause turns out to be in
  application routing; if an nginx change is genuinely required, document it
  in the commit message and re-verify the sensitive-path deny rules at
  `docker/nginx.conf:19-56` still hold.

## Verification

- `curl -o /dev/null -w "%{http_code}" http://pmp-web/reset-password` -> 200
- `curl .../reset-password/<valid-token>` -> 200, renders the reset form
- `curl .../reset-password/<bogus-token>` -> 200, renders the invalid-token
  branch, does **not** leak account data
- `database/reset_password_redirect_loop_test.php` -> PASS
- Full regression sweep unchanged otherwise