# issue-039 — Add an "Allow Registration" flag to disable public sign-up

## Summary

The application always allows anyone to create an account via `/register`. For a
private or client-facing deployment we need the ability to **close public
registration entirely** without touching code — a single configuration flag.

When the flag is off:

- the login page must not offer a "Create an account" button,
- the forgot-password page must not offer a "Don't have an account? Register" link,
- and — most importantly — `POST /register` must be **rejected server-side**,
  so the UI is not the only thing standing between the internet and a new user.

The UI changes are cosmetic. **The server-side check is the actual fix.** A
front-end-only change would leave `/register` open to a direct `POST` and would
not satisfy the requirement at all.

| | flag on (default) | flag off |
|---|---|---|
| `GET /register` | registration form | 403 page, no form |
| `POST /register` | creates the user | 403, **no user created** |
| `/login` "Create an account" button | shown | **hidden** |
| `/forgot-password` "Register" link | shown | **hidden** |
| existing users logging in | unaffected | unaffected |

### Primary use case

The flag exists for a specific, two-stage lifecycle:

1. **Stage 1 — open.** The deployment is set up and shared so that people can
   register their own accounts. Registration is allowed.
2. **Stage 2 — private.** The owner decides the installation is now for
   personal use only, and no further accounts should be created. The owner
   flips **one line in `config.php`** and registration closes.

The change must take effect **immediately** — no container restart, no
`docker compose` invocation, no `.env` edit. Editing `config.php` is the whole
action.

> This use case is the reason the flag lives in `config.php` and not `.env`.
> See "Design decision: where the flag lives" below.

---

## Design decision: where the flag lives

**`config.php`, and only `config.php`.** One constant, one file, one source of
truth.

```php
// config.php
const APP_ALLOW_REGISTRATION = false;   // true = open, false = closed
```

### Why not `.env`

The project also runs on **non-Docker hosting** (Hostinger / cPanel), where
there is no `docker-compose` and no `docker/entrypoint.sh`. On that target:

- `.env` is **inert** — nothing reads it. PHP has no dotenv library, and
  `docker-compose` is not in the picture.
- `config.php` is the **hand-maintained** config file, and is the only file
  that is guaranteed to exist and to be edited directly on every deployment.

So `config.php` is the one location that behaves identically on **both**
targets. Putting the flag there means the owner makes the same one-line edit
regardless of how the application happens to be hosted.

### Why this works on the Docker target too

`.env` is read by `docker-compose`, not by PHP. The full chain for an `.env`
value would be:

```
.env
  └─ docker-compose.yml   environment: APP_ALLOW_REGISTRATION: ${APP_ALLOW_REGISTRATION}
       └─ container env    getenv('APP_ALLOW_REGISTRATION')
            └─ docker/entrypoint.sh   writes const APP_ALLOW_REGISTRATION into config.php
                 └─ includes/functions.php   reads the constant
```

That chain is **not needed**, because `config.php` is bind-mounted into the
container (`./:/var/www/html` in `docker-compose.yml`). Editing `config.php` on
the host is immediately visible inside the container.

> **Critical — do not change the entrypoint's file-exists guard.**
> `docker/entrypoint.sh` only writes `config.php` when the file **does not
> exist**:
>
> ````sh
> if [ ! -f "$CONFIG_FILE" ] && [ -n "$DB_NAME" ]; then
> ````
>
> The file is therefore generated once at first boot and never regenerated
> afterwards, and a hand-edit is **safe from being clobbered**. If that guard
> were ever changed to always rewrite `config.php`, every hand-edit of the flag
> would be silently reverted on the next container start and this feature
> would appear to "not stick". This is the single most likely way the feature
> ships broken.

### Recommended approach: one constant, one resolver

Put the flag behind **one helper function** in `includes/functions.php`:

1. read the `APP_ALLOW_REGISTRATION` constant from `config.php`,
2. defaulting to **enabled** (`true`) when the constant is absent.

That is the whole resolution. No environment variable, no `.env` parsing, no
second source.

> **The default must be `true`.** If the constant is absent or misspelled,
> people must still be able to register. A typo in a config key must never
> silently lock every new user out of the system.

### Suggested constant name

`APP_ALLOW_REGISTRATION` — boolean. `true` = registration allowed.

Use a **boolean**, not the strings `"true"`/`"false"`. PHP's
`filter_var(..., FILTER_VALIDATE_BOOLEAN)` accepts `true/false/1/0/yes/no/on/off`
and returns `null` for anything else, which makes a typo detectable instead of
silently falsy.

### Files that must declare the constant

The constant is **optional** at runtime (the resolver defaults to `true` if it
is missing), but it should be **declared** in every place that generates a
`config.php`, so the knob is discoverable to whoever maintains the deployment:

| file | when it matters |
|---|---|
| `config.php` | the live deployment — hand-edited; **this is the control point** |
| `config.template.php` | manual setup — tells the operator the knob exists |
| `docker/entrypoint.sh` | first boot on Docker — writes the initial value |
| `install.php` | first-run wizard — writes the initial value |

All four write the **default** `true`. The owner changes it later, by hand, in
`config.php`.

---

## Implementation

### 1. `includes/functions.php` — the resolver

Add one helper. Everything else in this issue calls it.

````php
/**
 * Whether public registration is currently allowed.
 *
 * The flag lives in config.php as a single constant, so the same edit works
 * on Docker and on plain shared hosting.
 *
 *   const APP_ALLOW_REGISTRATION = true;   // registration open
 *   const APP_ALLOW_REGISTRATION = false;  // registration closed
 *
 * The default is permissive on purpose. A missing or misspelled constant must
 * never lock every new user out of the system.
 */
function isRegistrationAllowed(): bool
{
    static $allowed = null;
    if ($allowed !== null) {
        return $allowed;
    }

    if (!defined('APP_ALLOW_REGISTRATION')) {
        // Not declared - default to open.
        return $allowed = true;
    }

    $raw = APP_ALLOW_REGISTRATION;

    if (is_bool($raw)) {
        // The normal case: the constant is a real PHP boolean.
        return $allowed = $raw;
    }

    // Tolerate a quoted string ("true" / "false") in case a hand-edit adds
    // quotes, but treat anything unrecognised as permissive and log it so a
    // typo is discoverable rather than invisible.
    $parsed = filter_var($raw, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
    if ($parsed === null) {
        error_log(
            'APP_ALLOW_REGISTRATION: unrecognised value "' . var_export($raw, true) .
            '" - defaulting to allowed.'
        );
        return $allowed = true;
    }

    return $allowed = $parsed;
}
````

> The `static` variable caches the value **for the lifetime of one request
> only**. It is not a cross-request cache, so a change to `config.php` takes
> effect on the very next page load — there is nothing to clear and no
> restart to perform.

### 2. `register.php` — the server-side gate (the important one)

The check must happen **before** any input processing, and must cover **both**
`GET` and `POST`.

Place it immediately after `startSession()` and the existing "already logged
in" redirect:

````php
// Public registration can be closed by configuration (issue-039).
// This must be checked before any user is created, and for both GET and
// POST, so a direct POST cannot bypass the UI.
if (!isRegistrationAllowed()) {
    http_response_code(403);
    $title = 'Registration Closed';
    $use_v2_shell = true;
    $content_bleed = true;
    // render a "registration is closed" page and exit
    include 'includes/header.php';
    // ... message + link back to login ...
    include 'includes/footer.php';
    exit();
}
````

The important detail is that the guard sits **above** the `if ($_POST) { ... }`
block. A check placed inside the POST branch would still allow a `GET` to render
the form, and would allow any future code path that creates a user without
passing through that branch.

> **Rendering the 403 page.** `register.php` is an auth page, so it does not
> use the standard app shell — it sets `$use_v2_shell = true` and wraps its
> content in the bespoke `.auth-shell` / `.auth-aside` / `.auth-card`
> structure (`register.php:94-118`). The 403 page should follow the same
> pattern rather than rendering a bare page, so it looks like the rest of the
> auth flow:
>
> ````php
> if (!isRegistrationAllowed()) {
>     http_response_code(403);
>     $title = 'Registration Closed';
>     $use_v2_shell = true;
>     $content_bleed = true;
>     include 'includes/header.php';
>     ?>
>     <div class="auth-shell">
>         <aside class="auth-aside"> ...same as register.php... </aside>
>         <main class="auth-main">
>             <div class="auth-card">
>                 <h1>Registration is closed</h1>
>                 <p class="auth-card__sub">
>                     New accounts cannot be created on this installation.
>                 </p>
>                 <div class="auth-card__foot">
>                     <a href="/login"><i class="fas fa-arrow-left"></i> Back to Login</a>
>                 </div>
>             </div>
>         </main>
>     </div>
>     <?php
>     include 'includes/footer.php';
>     exit();
> }
> ````
>
> The `<aside class="auth-aside">` block can be copied verbatim from
> `register.php:102-117` so the two pages are visually identical.

> **Do not** rely on the UI to prevent this. Hiding the button and adding the
> server check are two separate requirements; both are listed below.

### 3. `login.php` — hide the "Create an account" button

The button currently sits at [login.php:140](login.php#L140):

````php
<a class="btn btn--secondary btn--block" href="/register">
    <i class="fas fa-user-plus"></i>
    Create an account
</a>
````

Wrap it:

````php
<?php if (isRegistrationAllowed()): ?>
    <a class="btn btn--secondary btn--block" href="/register">
        <i class="fas fa-user-plus"></i>
        Create an account
    </a>
<?php endif; ?>
````

### 4. `forgot_password.php` — hide the "Register" link

The link is at [forgot_password.php:164](forgot_password.php#L164):

````php
<a href="/register">Don't have an account? Register</a>
````

Wrap it in the same conditional. Both links are in a shared
`.auth-links` row, so the row still renders with the "Back to Login" link —
no layout change is needed.

### 5. `config.php` — the control point

Add the constant to the live `config.php`, in the application-configuration
block alongside the other settings:

````php
// Public registration (issue-039). false = /register is closed (403),
// the login page and the forgot-password page stop offering a registration
// link. Existing users are unaffected.
const APP_ALLOW_REGISTRATION = true;
````

`config.php` is **gitignored** (`.gitignore:2`), which is correct: this is a
per-deployment setting, not a versioned one. A `git pull` will not overwrite
it.

### 6. `config.template.php`

Add the same constant with the same comment, so a manual production setup
knows the knob exists:

````php
// Public registration (issue-039). false = /register is closed (403).
const APP_ALLOW_REGISTRATION = true;
````

### 7. `docker/entrypoint.sh` — write the initial value

Add to the generated `config.php` heredoc:

````sh
const APP_ALLOW_REGISTRATION = ${APP_ALLOW_REGISTRATION:-true};
````

Two constraints:

- The heredoc interpolates shell variables directly into PHP source. The value
  must be a bare `true`/`false` literal, not a quoted string, because the
  helper treats the constant as a boolean. If the value arrives as the string
  `"true"`, `filter_var()` still resolves it correctly — but the constant must
  not end up as an unquoted invalid PHP token.
- **Leave the `if [ ! -f "$CONFIG_FILE" ]` guard alone.** The heredoc only
  runs on first boot. If it ran on every start, it would overwrite the
  owner's hand-edit of `config.php` and the flag would silently revert.
  See the callout in "Design decision: where the flag lives".

### 8. `install.php` — the wizard must write the constant too

`install.php` writes `config.php` itself (around `install.php:184-211`). If the
constant is not written there, a freshly-installed instance falls back to the
default and the knob is invisible to whoever maintains the deployment.

Add to the generated config content:

````php
. "const APP_ALLOW_REGISTRATION = true;\n"
````

> **Do not** add `APP_ALLOW_REGISTRATION` to `docker-compose.yml`'s
> `environment:` block or to `.env`. The flag is set in `config.php` on every
> target. Adding an env mapping would create a second source of truth that
> only applies to Docker, which is exactly the confusion this design is
> meant to remove.

---

## Changing the setting later

This is the operation the feature exists for. It is a one-line edit:

````diff
- const APP_ALLOW_REGISTRATION = true;
+ const APP_ALLOW_REGISTRATION = false;
````

After saving:

| | result |
|---|---|
| Docker | takes effect on the next request — the file is bind-mounted, no restart, no `docker compose` |
| cPanel / shared hosting | takes effect on the next request — no restart, no PHP-FPM reload |
| existing users | can still log in, unaffected |
| password reset | still works, unaffected |

To re-open registration, edit the same line back to `true`.

---

## Files touched

| File | Change |
|---|---|
| `includes/functions.php` | new `isRegistrationAllowed()` resolver |
| `register.php` | server-side 403 guard above the POST branch |
| `login.php` | conditional "Create an account" button **and** its "New here?" divider |
| `forgot_password.php` | conditional "Register" link |
| `config.php` | declare the constant (production — the control point) |
| `config.template.php` | declare the constant |
| `docker/entrypoint.sh` | emit `const APP_ALLOW_REGISTRATION` into `config.php` (first boot only) |
| `install.php` | emit the constant when the wizard writes `config.php` |

### Not touched, deliberately

| File | Why not |
|---|---|
| `.env` / `.env.example` | the flag is not an environment variable — see "Why not `.env`" |
| `docker-compose.yml` | no `APP_ALLOW_REGISTRATION` mapping — a second source of truth for one target only |

---

## Acceptance criteria

**Flag off — `const APP_ALLOW_REGISTRATION = false;`**

1. `GET /register` returns **403** and renders no registration form.
2. `POST /register` with valid details returns **403** and creates **no user**.
   Verify with `SELECT COUNT(*) FROM users` before and after.
3. No welcome email is sent for a blocked registration.
4. `/login` shows **no** "Create an account" button — and no orphaned
   "New here?" divider.
5. `/forgot-password` shows **no** "Don't have an account? Register" link.
6. An existing user can still log in and work normally.
7. The password-reset flow still works end to end.

**Flag on — `const APP_ALLOW_REGISTRATION = true;`**

8. `/register` renders the form; a successful registration creates the user and
   sends the welcome email — i.e. **no regression** to today's behaviour.
9. The "Create an account" button and the "Register" link are both visible again.

**Default behaviour**

10. With the constant absent from `config.php`, registration is **allowed** and
    nothing is logged as an error.
11. With the constant set to a typo (e.g. `yes-ish`), registration is still
    allowed and an entry is written to the error log.
12. With the constant set to a quoted string (`"false"`), registration is
    **closed** — `filter_var()` resolves it correctly.

**The primary use case — this is the important group**

13. On a Docker deployment: edit `config.php` to `false`, save, and reload
    `/register` **without** running `docker compose up`, `docker compose
    restart`, or any other container command. The 403 appears immediately.
14. On a Docker deployment: after step 13, run `docker compose restart app`
    (or recreate the container) and confirm the flag is **still** `false` —
    i.e. the entrypoint did not overwrite the hand-edit.
15. On a cPanel / shared-hosting deployment: edit `config.php` to `false` and
    reload `/register` with no restart of any kind. The 403 appears.
16. Edit the constant back to `true` on either target and confirm `/register`
    works again — the flag is reversible, not one-way.

---

## Notes and risks

- **Default is permissive.** Verified by criterion 10. A locked-out system with
  no way to register is far worse than an unexpectedly-open registration form.
- **The server check is the requirement.** The UI changes are explicitly
  secondary and are listed separately so neither is silently dropped.
- **Existing accounts are unaffected.** The flag governs *new* self-service
  registration only. Admins can still create users by any other means.
- **The landing page `/` is the client share portal** and is unaffected — it
  never offered registration.
- **Do not add an env-var mapping.** A second source of truth for one target
  is the failure mode this design removes. `config.php` alone, everywhere.
- **`config.php` is gitignored.** A deployment that regenerates `config.php`
  from scratch (rather than editing it) would reset the flag to its default.
  The entrypoint's file-exists guard prevents this on Docker; on shared
  hosting, confirm the deploy process does not overwrite the file.

---

## Related issues

| issue | relationship |
|---|---|
| `issue-055` | Browser-found bugs (copy button, task dates, client language switch, forgot password) — also in the v2.0 release |
| `issue-043` | House-style exemplar for this document |

Both `issue-039` and `issue-055` are scheduled for the **v2.0 release**.
Neither requires a production code change to be merged — both are
specifications for the developer.

> **Note:** `issue-055` bug 4 also modifies `forgot_password.php` — but in a
> **different region** of the file:
>
> | issue | region of `forgot_password.php` |
> |---|---|
> | `issue-039` step 4 | the `.auth-links` row, `:162-166` — hides the "Register" link |
> | `issue-055` bug 4 | the form and alerts, `:135-161` — required marker, `autocomplete`, `autofocus`, button margin, error placement |
>
> The two edits do not overlap and can be applied in either order. The one
> thing to watch: `issue-055` moves the error/success alert block **above**
> the `<h1>`. That reordering must not accidentally move the `.auth-links`
> row, which stays at the bottom of the card.

