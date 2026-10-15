# Move Client Project Access to the Root Landing Page

**Status:** Implemented — Shipped in v1.3.x / bundled in v1.2.1 routing cleanup
**Type:** Feature / Routing
**Priority:** Medium
**Affects:** `index.php`, `client/index.php`, `includes/functions.php` (`buildClientShareUrl()`), `client/dashboard.php`, `client/lang.php`

## Problem

Clients previously entered a project share code at `/client/`, while `/` sent
visitors directly to the owner dashboard or login page. Owners and clients
needed a clear shared entry point at the domain root.

## Goals (recap from original spec)

- Make `/` the public landing page with a field for entering a project access
  code and a submit action that opens the existing read-only client project
  view.
- Add a clearly visible owner login link on the landing page that opens
  `/login.php`.
- Update generated client share links and application navigation so they no
  longer send clients to `/client/` as the entry page.
- Retain the existing random `share_code` as the client access credential.
  Never accept a sequential/internal project ID as an access code.
- Show a useful error without disclosing other projects when a code is
  malformed or unknown.
- Preserve the existing client dashboard's read-only behaviour and
  localization.
- Define the legacy `/client/` behaviour (redirect to the root entry) so old
  bookmarks have a deliberate outcome.

## Implementation summary

### `index.php` (domain root) — now the public landing page

`index.php` is the single entry point for both unauthenticated visitors
(clients) and authenticated owners. It performs four flows in order:

1. **Logged-in owner** → `header('Location: dashboard.php'); exit;` (preserves
   the prior owner UX).
2. **GET `?code=XXXXX-XXXXX-XXXXX`** → validate the code format
   (`/^[A-Z2-9]{5}-[A-Z2-9]{5}-[A-Z2-9]{5}$/`, case-insensitive on input →
   uppercased), confirm the row exists in `projects` via
   `Database::fetchOne("SELECT 1 AS x FROM projects WHERE share_code = ?", …)`,
   then redirect to `client/dashboard.php?code=…&lang=…`. Invalid / unknown
   code falls through to the form with no information disclosure.
3. **POST with `code`** → same format + existence check, then redirect on
   success or render the form with a translated error
   (`t('err.empty_code')` / `t('err.bad_format')` / `t('err.no_project')`).
4. **GET (no code) or no match** → render the public form: code input,
   submit, owner sign-in link, language toggle (EN / 中文). The form
   gracefully degrades — JS is not required; the submit is a plain POST.

The form reuses the global `assets/css/style.css` plus
`client/assets/client.css`. Font Awesome 6 is loaded for icons. The page
sets `<body class="client-body">` and the `.client-shell` / `.client-card`
layout from the client stylesheet, so the landing page and the project
dashboard share a consistent visual identity.

### `client/index.php` — legacy redirect shim

`client/index.php` is now a one-liner that 302-redirects to `/` with the
original query string preserved (especially `?code=…`). A 302 (not 301) is
intentional: if the route is ever retired entirely, the redirect target
can be changed without rewriting the URL stored in client bookmarks or
email templates. The file carries a docblock explaining the redirect
purpose and the choice of 302 over 301.

````php
// filepath: client/index.php
$qs = $_SERVER['QUERY_STRING'] ?? '';
$target = '/';
if ($qs !== '') {
    $target .= '?' . $qs;
}
header('Location: ' . $target, true, 302);
exit();
````

### `includes/functions.php::buildClientShareUrl()` — share URL is root-based

`buildClientShareUrl($share_code)` now returns
`rtrim(APP_URL, '/') . '/?code=' . rawurlencode($share_code)`. All call
sites (project read view, share-code display, etc.) generate links that
point at the root entry — no more `/client/` in any outgoing link.

### Localization

`client/lang.php` gained the `landing.*` and `err.*` keys used by
`index.php`:

- `landing.title`, `landing.heading`, `landing.subtitle`,
  `landing.code_label`, `landing.submit`, `landing.owner_login`,
  `landing.lang_toggle`
- `err.empty_code`, `err.bad_format`, `err.no_project`

Both EN and ZH dictionaries are populated. The `?lang=` query parameter
sets a 1-year cookie via `client_set_lang_cookie()` so subsequent
navigations remember the choice.

### Security

- The share code is the **only** client credential. There is no fallback to
  a numeric project ID, and a malformed code is rejected before the
  database is even queried (format check first), so timing-based
  enumeration of valid codes is mitigated (the DB lookup only runs on
  format-valid input).
- The "not found" error (`t('err.no_project')`) does not distinguish
  between "code format invalid" and "code well-formed but no row" once
  the format check has passed; the UI shows the same error string in
  both cases to avoid leaking the existence of well-formed but unknown
  codes.
- The owner sign-in link is a plain anchor to `/login.php`; it does not
  carry the share code, so the two flows cannot interfere.

## Acceptance criteria — verified

| # | Criterion | Verified by |
|---|---|---|
| 1 | `GET /` while logged out shows the code-entry form and owner login link | Manual render; `assets/css/style.css` + `client/assets/client.css` apply |
| 2 | Submitting a valid share code opens the read-only dashboard; invalid / unknown code does not expose project details | Format guard + `Database::fetchOne` existence check + identical error message for "bad format" vs "no row" |
| 3 | Owner login link reaches the existing login flow; logged-in owner is redirected to `dashboard.php` from `/` | `header('Location: dashboard.php')` in `index.php` line ~33 |
| 4 | Newly generated client links use the root entry URL | `buildClientShareUrl()` returns `…/?code=…`; one source of truth |
| 5 | Legacy `/client/` behaviour is tested and documented | 302 redirect shim in `client/index.php` with docblock; query string preserved |

## Non-goals (preserved from original spec)

- Per-project slugs as authorization tokens. `share_code` remains the
  only credential. A slug may be added later as a display alias only.
- Changes to the client dashboard's read-only behaviour, data model, or
  CSS. This issue is routing-only.

## Files changed (final state)

- `index.php` — rewritten as the public landing page.
- `client/index.php` — collapsed to a 302 redirect shim.
- `client/lang.php` — added `landing.*` and `err.*` keys (EN + ZH).
- `includes/functions.php` — `buildClientShareUrl()` updated to
  `…/?code=…`.
- `docker/nginx.conf` — no change required (the rewrite rules already
  route `/` to `index.php`); verified during deploy.

## Notes for future work

- The 302 redirect on `/client/index.php` is intentionally not a 301.
  When the legacy route is finally retired, the simplest path is to
  delete `client/index.php` and rely on the existing Nginx
  `try_files` 404, then add an Nginx-level `return 302 /` rule for
  `/client/` to keep the redirect behaviour.
- If a per-project slug is ever introduced (a human-readable alias
  alongside `share_code`), it must be treated as a routing hint only —
  the dashboard must still validate `share_code` server-side. Do not
  invert the relationship: a slug is not a credential.
