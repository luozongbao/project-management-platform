# CSRF on Mutating Endpoints + Raw PDO Error Echo + Email HTML Injection

**Status:** Proposed
**Type:** Security / Hardening
**Priority:** High
**Affects:** `contact_edit.php`, `task_edit.php`, `profile.php`, `ajax/complete_task.php`, `ajax/update_task_status.php`, `ajax/update_task_progress.php`, `ajax/get_project_contacts.php`, `includes/EmailService.php`

## Problem

Three independent but adjacent issues, all in the same files:

### A. Missing CSRF on three internal mutating endpoints

A cross-site request forgery (CSRF) attack lets a malicious
site submit a form to a logged-in PMP manager and have it
accepted as the manager's own action. Today the following
POST endpoints have no CSRF token check:

- **`contact_edit.php`** — creates, updates, and *re-binds*
  contacts to projects. A CSRF can:
  - Create a contact owned by the victim (the contact is global
    — it joins the `contacts` table, not the project). This is
    mostly noise, but the next bullet is not.
  - **Add the victim's contact to the attacker's project.** The
    attacker can craft a CSRF that POSTs
    `projects[]=<attacker's project id>` to
    `contact_edit.php` while the manager is logged in. The
    manager's `user_id` is fetched server-side and becomes the
    `responsible_person_id` of the *project the contact is being
    added to*; the contact itself is not owned. Effect: the
    attacker's project now has the manager's contact list
    cross-referenced. (Today the contact_edit form is gated on
    `requireLogin()` and the new `INSERT INTO project_contacts`
    uses the manager's session user; so the practical impact is
    "the attacker's project_id lands in the project_contacts
    table for a contact the manager just edited". The contact
    itself is global, so the attack can be retargeted.)
  - **Modify the victim's contact** (name, email, phone). The
    contact is global; the next time the victim edits it the
    changes are visible.
- **`task_edit.php`** — creates, updates, and *re-parents*
  tasks. A CSRF can:
  - Change a task's `responsible_person_id` (the user the task
    is assigned to).
  - Change a task's `project_id` to one of the victim's
    projects — the task is then stolen (see issue-022 for the
    related IDOR).
  - Change the task's `status` to `completed` (100% completion
    via the completion-percentage slider), mutating a
    weighted rollup that affects the victim's project stats.
- **`profile.php`** — two actions: `update_profile` (name,
  username, email) and `change_password`. A CSRF can:
  - Change the victim's email to one the attacker controls,
    then trigger a password reset from `forgot_password.php`
    → account takeover.
  - Change the victim's password to a known value, locking
    them out (annoyance, not data loss, but still a hostile
    action).
  - Change the victim's username (low impact).

**Why this is high-severity and not a "nice-to-have":**
`project_edit.php` and `project_delete.php` both have CSRF
protection today. The same protection is one helper call
(`validateCsrfToken()` + a hidden input) away for the three
endpoints above. The asymmetry is the security issue — the
endpoint boundary is the *form*, not the *file*, and a future
refactor that moves contact/task creation into a new file
without remembering the token is a regression.

### B. Raw PDO error messages echoed to the user

`contact_edit.php:166` and `task_edit.php:307` do:

```php
$errors[] = "Error saving contact: " . $e->getMessage();
$errors[] = "Error saving task: " . $e->getMessage();
```

After the v1.2.1 follow-up that surfaced the underlying PDO
message in the wrapped `Exception` (so the next "Unknown
column 'X'" bug is visible to the developer), this is also
visible to the **end user**. The user sees literal SQL state
codes and column names, which is information disclosure. The
`ajax/complete_task.php:84`, `ajax/update_task_status.php:73`,
`ajax/update_task_progress.php:71`, and `ajax/get_project_contacts.php:39`
endpoints all do the same in the JSON `error` field.

The fix is to log the message server-side and surface a
generic "Failed to save …" copy. The developer keeps the
verbose message in `error_log`.

### C. Email HTML injection via user-controlled `$name`

`includes/EmailService.php:83, 104` (and the templates at
`getPasswordResetTemplate()` / `getWelcomeTemplate()`)
interpolate `$name` straight into both the HTML and the
plaintext bodies:

```php
$this->mailer->Body = $this->getPasswordResetTemplate($to_name, $reset_url);
// inside the template:
<p>Hello <strong>$name</strong>,</p>
```

`$to_name` is the `users.name` column, set by
`register.php:49` (`$name = sanitize($_POST['name'] ?? '');`)
without further validation. `sanitize()` only does
`htmlspecialchars(..., ENT_QUOTES, 'UTF-8')` — so a name
like `<script>alert(1)</script>` is stored as the encoded
form in the DB and round-trips through `e()` on the read
path (safe), but in the email body it is **double-encoded**
and the user's mail client renders the encoded form
literally, not the script. Wait — let me re-check that
chain. `sanitize()` → encoded for HTML; the email
template is also HTML. So `<script>` becomes
`&lt;script&gt;` in storage and `&lt;script&gt;` in the
email; the mail client renders `<script>` (the visible
characters) but the browser does not interpret it as a
script tag — it is plain text. So in practice this is
**not** an HTML-injection vulnerability for modern mail
clients (which render the email in a sandboxed HTML view
that treats entity-encoded text as text).

**However:** the *plaintext* alt body at
`EmailService.php:64-65` and `EmailService.php:95-96`
interpolates `$to_name` raw into a plain string:

```php
$this->mailer->AltBody = "Hello $to_name,\n\nYou requested …";
```

A user with a name like `Foo\nBcc: attacker@evil.com`
can inject SMTP headers into the plaintext part of the
email. PHPMailer separates header construction from body
construction, so a header-injection here is not the same
as RFC 5322 header injection, but the result is still
that an attacker-controlled name lands in unexpected
places in the body.

The fix is to:
1. `htmlspecialchars($name, ENT_QUOTES, 'UTF-8')` in the
   HTML template, not the raw `$name`.
2. `strip_control_characters($name)` (or
   `preg_replace('/[\x00-\x1F\x7F]/', '', $name)`) before
   the plaintext AltBody.
3. Cap name length in the email body (truncate at, say,
   100 chars).

## Goals

- Every internal mutating endpoint validates a CSRF token on
  POST. Three endpoints ship missing CSRF; this issue closes
  that gap.
- Every caught `Exception` from `Database::execute()` /
  `Database::query()` is logged server-side with the
  underlying PDO message; the user-visible copy is a
  generic, non-revealing sentence.
- `EmailService` escapes `$name` in the HTML body and
  strips control characters in the plaintext body.

## Non-goals

- No new framework. The existing
  `getCsrfToken()` / `validateCsrfToken()` helpers in
  `includes/functions.php` are reused verbatim.
- No new session lifecycle changes. Regenerating the
  session ID on login is a separate ticket (issue-020).
- No change to the public client portal.
- No change to the JSON response shape of the four
  `ajax/*.php` endpoints. The `error` field stays; it just
  stops echoing raw PDO messages.

## Proposed changes

### A. CSRF

For each of the three endpoints, in this exact order:

1. Add the CSRF check at the very top of the POST branch,
   before any other input is read, so a missing/invalid
   token cannot leak DB error messages or partial state.

   ```php
   if ($_SERVER['REQUEST_METHOD'] === 'POST') {
       if (!validateCsrfToken($_POST['csrf_token'] ?? null)) {
           $errors[] = 'Your session could not be verified. '
                     . 'Please reload the page and submit again.';
           // Render the form with $errors and do NOT continue.
       } else {
           // existing input-read + validation + DB write
       }
   }
   ```

2. In the form, render the token via the existing helper:

   ```php
   <input type="hidden" name="csrf_token"
          value="<?= e(getCsrfToken()) ?>">
   ```

   (The pattern in `task_detail.php:105,115` and
   `project_edit.php:198,362` is the canonical example —
   copy it.)

3. **Do not** change the HTTP response code to 4xx; keep
   the form re-render so the user can retry. (A JSON 403
   is appropriate for AJAX endpoints; the three targets here
   are server-rendered forms.)

**Endpoints in detail:**

- **`contact_edit.php`** — the POST branch starts at
  `contact_edit.php:36`. Add the CSRF gate before line 39
  (`$name = trim($_POST['name'] ?? '');`). Render
  `<input type="hidden" name="csrf_token"
  value="<?= e(getCsrfToken()) ?>">` inside the
  `<form method="POST" action="">` at the bottom of the
  file.
- **`task_edit.php`** — the POST branch starts at
  `task_edit.php:95`. Add the CSRF gate before line 96
  (`$name = trim($_POST['name'] ?? '');`). Render the
  hidden input inside the `<form class="task-form">` at
  the bottom of the file.
- **`profile.php`** — the POST branch starts at
  `profile.php:21`. There are two `action=` values:
  `update_profile` and `change_password`. Add the CSRF
  gate at the very top of the POST branch (before
  `if ($action === 'update_profile')`). Render the
  hidden input inside **both** forms in the page (search
  for `<form method="POST"` — there are two). The
  registration email should be a transactional flow, so
  changing email here does not require a re-confirmation
  step in this issue; flag as a v2.0 follow-up.

### B. Generic DB error copy

For every `$errors[] = "Error … : " . $e->getMessage()`
in the three files and the four AJAX endpoints, replace
with:

```php
error_log("Save failed: " . $e->getMessage());
$errors[] = "Failed to save. Please try again. "
          . "If the problem persists, contact your administrator.";
```

The full PDO message stays in `error_log` for the developer.

The four AJAX endpoints wrap the same pattern in their
`catch (Exception $e)` branches. Same fix.

For the four AJAX endpoints, also add a `success: false`
with a generic `error` field; do **not** echo `$e->getMessage()`.
The HTTP status stays 500.

### C. Email HTML injection

In `EmailService.php`:

1. **HTML body** — replace the raw `$name` interpolation
   in both templates with `htmlspecialchars($name, ENT_QUOTES, 'UTF-8')`.
   Easiest is to do the escape at the top of the template
   function:

   ```php
   private function getPasswordResetTemplate($name, $reset_url) {
       $safe_name = htmlspecialchars((string)$name, ENT_QUOTES, 'UTF-8');
       return "<html>… <p>Hello <strong>$safe_name</strong>, …</html>";
   }
   ```

   Same for `getWelcomeTemplate()`.

2. **Plaintext AltBody** — strip control characters
   before interpolating. Add a small helper in
   `EmailService` (or reuse the one in
   `includes/functions.php` if added there — see the
   cross-reference below):

   ```php
   $safe_name_plain = preg_replace('/[\x00-\x1F\x7F]+/u', ' ', (string)$name);
   $safe_name_plain = trim($safe_name_plain);
   if ($safe_name_plain === '') {
       $safe_name_plain = 'there';
   }
   $this->mailer->AltBody = "Hello $safe_name_plain,\n\n…";
   ```

3. **Truncate** to 100 chars in both bodies to keep
   the email from becoming a wall of text if someone
   registers with a 1KB name:

   ```php
   $safe_name = mb_substr($safe_name, 0, 100);
   ```

4. The reset URL itself (`$reset_url = APP_URL . "/reset_password.php?token=" . $reset_token;`)
   embeds the *raw* token in the query string. The token
   comes from `generateToken()` (32 hex chars = 128 bits)
   and is stored in `password_reset_tokens.token` with a
   UNIQUE index, so guessing is infeasible. **However**,
   the link is sent over email — if the receiver's mail
   provider indexes the URL (Outlook Safe Links, Google
   Inline Link Previewing), the token is logged. The
   mitigation in this issue is to add a note to
   `EmailService.php` saying the token is single-use and
   expires in 1 hour (already documented on the email
   itself). The "signed URL with expiry" hardening is in
   the "Out of scope" section of the umbrella
   [issue-015](issue-015.md).

## Files touched

- `contact_edit.php` — CSRF gate, hidden input, generic error copy.
- `task_edit.php` — CSRF gate, hidden input, generic error copy.
- `profile.php` — CSRF gate, two hidden inputs (one per form),
  generic error copy.
- `ajax/complete_task.php` — generic error copy.
- `ajax/update_task_status.php` — generic error copy.
- `ajax/update_task_progress.php` — generic error copy.
- `ajax/get_project_contacts.php` — generic error copy.
- `includes/EmailService.php` — HTML escape + control-char
  strip on `$name` in both templates and the AltBody.

## Risks

- **Adding CSRF to a POST endpoint breaks any existing
  client that submits to it without a token.** Today the
  three endpoints are only called from their own
  server-rendered forms, so the change is a one-commit
  per-file fix. There is no external API consumer.
- **Email HTML escaping changes visible text.** A user
  with a name like `Bob & Alice` previously saw `Bob &
  Alice` in the email (because `$name` was raw). After
  the fix, they will see `Bob &amp; Alice` in the HTML
  body — but no modern mail client renders the entity;
  the displayed text is `Bob & Alice`. The plaintext body
  was already `Bob & Alice` and is unchanged. No
  user-visible regression.
- **Truncating the name at 100 chars changes the email
  greeting** for users with very long names. Acceptable;
  the spec says `users.name VARCHAR(255)` and the form
  caps at 100 (`profile.php:54`), so this only affects
  pre-existing rows from before that cap was added.
- **PHPMailer `clearAddresses()` and the reuse of a
  single `mailer` instance per request** is fine for
  this app (each HTTP request creates a new
  `EmailService`), but the body-template change
  preserves that contract.

## Acceptance

- `grep -n "validateCsrfToken" contact_edit.php task_edit.php profile.php`
  returns one match per file (the gate). The hidden
  `<input type="hidden" name="csrf_token"` appears once
  per `<form method="POST">` in each of the three files
  (one in `contact_edit.php` and `task_edit.php`, two in
  `profile.php`).
- `grep -n 'e->getMessage()' contact_edit.php task_edit.php profile.php ajax/*.php`
  returns zero matches inside an `$errors[]` or a JSON
  `error` field. (The `error_log(...)` calls are allowed;
  grep with the exact pattern above will return only the
  `$errors[]` / `error:` literals.)
- The four AJAX endpoints, on a deliberate failure
  (e.g. a temporarily-broken DB), return JSON
  `{"success": false, "error": "Failed to …"}` and
  `http_response_code(500)`.
- `EmailService::getPasswordResetTemplate('Bob & Alice', 'http://x/?token=ABC')`
  returns HTML where the greeting is the entity-encoded
  form; the rendered output (via `html_entity_decode`)
  is `Hello Bob & Alice,`.
- `EmailService::sendPasswordResetEmail('bob@x', "Bob\nBcc: x", 'tok')` produces
  an AltBody where the name is `Bob Bcc: x` (newline
  stripped) — verified by capturing the message via
  PHPMailer's `preSend` debug hook in a test.

## Regression test plan

A new structural test
`database/security_issue016_test.php` that:

1. Boots PDO against the live DB (no schema changes needed).
2. Asserts via `file_get_contents` + regex that each of
   the three files contains the CSRF gate at the top of
   the POST branch.
3. Asserts via regex that each form in those files
   embeds a hidden `csrf_token` input.
4. Asserts via regex that none of the three files nor
   the four AJAX endpoints echo `$e->getMessage()` in
   user-facing copy.
5. Calls a static helper in `EmailService.php` (new)
   that takes a name and returns both the HTML and
   plaintext bodies, then asserts:
   - `html_entity_decode(strip_tags($body))` does **not**
     contain `<script>` or `javascript:` for the inputs
     `"<script>alert(1)</script>"`, `"Bob & Alice"`, and
     `"a]b[c"`.
   - The plaintext body never contains ASCII control
     characters in the name portion.

The test does not require a DB write; it is a
file-grep + unit-style test of the email template
helper.

The existing seven regression tests
(`task_inherit_subtask_hours_test`,
`task_parent_status_derived_test`,
`task_completion_test`, `contacts_columns_test`,
`project_start_date_test`,
`client/toggle_subtasks_test`,
`task_layout_issue013_test`) must continue to pass
unchanged. None of them POST to `contact_edit.php` /
`task_edit.php` / `profile.php` / `ajax/*.php`.

## Cross-references

- Umbrella: [issue-015](issue-015.md) finding 1, 8, 10.
- Reused helpers: `getCsrfToken()` and
  `validateCsrfToken()` in `includes/functions.php:48,56`.
- The form-CSRF pattern was first introduced in
  `project_edit.php:14-15, 164, 198, 362` and
  `project_delete.php:31`; mirror that pattern in the
  three endpoints above.
- The "log the verbose message, show a generic copy"
  pattern is already used in `register.php:78` —
  `error_log("Registration error: " . $e->getMessage());`
  followed by a generic user-facing error string.

## Out of scope (flagged for future tickets)

- Add a `Content-Security-Policy` header that bans
  inline event handlers (the per-page `<style>` blocks
  and inline `onclick=` / `oninput=` attributes are not
  yet CSP-compatible). Tracked in [issue-020](issue-020.md).
- Re-confirm the email address when the user changes
  it from `profile.php`. Today a manager can be CSRF'd
  into changing their email, and then the attacker
  triggers a password reset. Adding email re-confirmation
  closes the loop, but requires a new column and a
  confirmation-email flow. v2.0.
- Add `SameSite=Lax` to the session cookie. Today
  `php.ini`'s default applies; the next config sweep
  should pin it. Tracked in [issue-020](issue-020.md).
