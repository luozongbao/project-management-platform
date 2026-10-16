# Tester prompt — issue-016 (Security hardening v1)

## What was changed

Issue-016 closed three security gaps (see `docs/issues/issue-016.md` for the full spec):

| Area | Files |
| --- | --- |
| **A. CSRF on mutating endpoints** | `contact_edit.php` (committed in `ed7877c`), `task_edit.php`, `profile.php` — each now wraps its POST branch in `validateCsrfToken()` and renders a hidden `csrf_token` input on every form. |
| **B. Generic, non-leaking DB error copy** | `task_edit.php`, `profile.php` (both catches), `ajax/complete_task.php`, `ajax/update_task_status.php`, `ajax/update_task_progress.php`, `ajax/get_project_contacts.php` — `catch` blocks now `error_log()` the raw PDO message and surface a fixed "Failed to … Please try again. If the problem persists, contact your administrator." (or a JSON equivalent for the AJAX endpoints). |
| **C. Email HTML / header injection** | `includes/EmailService.php` — new public static `renderPasswordResetBody($name, $reset_url)` and `renderWelcomeBody($name, $reset_url)` returning `['html' => …, 'plain' => …]`. Both escape the name with `htmlspecialchars` and run the plaintext side through a control-character stripper (`preg_replace('/[\x00-\x1F\x7F]+/u', ' ', …)`) + `trim` + 100-char cap + fallback `'there'`. `sendPasswordResetEmail()` / `sendWelcomeEmail()` now use these renderers. The old private `getPasswordResetTemplate()` / `getWelcomeTemplate()` are gone. |
| **Regression test** | `database/security_issue016_test.php` — 75 assertions covering (1) CSRF gate / hidden input presence in all 3 pages, (2) generic error copy presence in all 7 files, (3) the 9-payload injection matrix (script tag, attribute escape, ampersand, brackets, CRLF header injection, null bytes, over-length, empty) against both renderers. |

## How to run the regression test

```bash
docker exec project-management-platform-app-1 php /var/www/html/database/security_issue016_test.php
```

Expected: `Issue-016 security regression: 75/75 checks passed.` and `exit=0`.

The other 7 pre-existing regression tests should still pass:

```bash
docker exec project-management-platform-app-1 sh -c 'cd /var/www/html && for t in database/*_test.php client/*_test.php; do echo "$t"; php "$t" 2>&1 | tail -2; done'
```

## What to test manually (acceptance criteria)

For each item below, exercise the live UI in a browser and report PASS/FAIL with a one-line reproduction.

### A. CSRF

1. **CSRF on contact_edit.php**
   - Save a contact normally → still works.
   - In DevTools, edit the rendered `<input name="csrf_token" value="…">` to `BROKEN` and resubmit → page reloads with the error "Your session could not be verified. Please reload the page and submit again." and **no DB write happens**.

2. **CSRF on task_edit.php** — repeat the same drill (edit a task, then resubmit with a tampered token).

3. **CSRF on profile.php** — repeat for **both** forms (Edit Profile and Change Password). They each get their own hidden `csrf_token` input.

4. **Sanity**: `view-source` on each of the three pages and confirm exactly one `<input type="hidden" name="csrf_token" …>` per form. `profile.php` should have two.

### B. Generic DB error copy

Temporarily break the DB to force a catch block (easiest way: stop the `pmp-db` container, attempt to save, then start it again).

5. Saving a contact / task / profile change / password change while the DB is unreachable should:
   - NOT include any PDO string in the user-visible error.
   - Show the exact text: `Failed to save …. Please try again. If the problem persists, contact your administrator.` (or the JSON equivalent for the AJAX endpoints below).
   - The full PDO message must appear **only** in the PHP error log (run `docker exec project-management-platform-app-1 tail -50 /var/log/php/error.log` or wherever PHP is configured to log).

6. **AJAX error JSON**:
   - In DevTools Network, complete a task / change a task status / change a task progress percentage / open the "Add existing contact" picker while the DB is down.
   - The response body should look like `{"success":false,"message":"Failed to complete the task. Please try again."}` (or the equivalent for the other three endpoints — see `database/security_issue016_test.php` for the exact strings).
   - HTTP status must remain `500`.
   - The raw PDO/exception text must not appear in the response.

### C. Email HTML / header injection

Two flavours of test — prefer the unit-style tests because they're deterministic; the SMTP tests below are optional smoke checks.

7. **Unit-style** (no SMTP needed):
   ```bash
   docker exec project-management-platform-app-1 php -r '
   require "includes/EmailService.php";
   define("APP_NAME", "TestApp");
   foreach (["<script>alert(1)</script>", "Bob & Alice", "\r\nBcc: evil@example.com", str_repeat("A", 250), ""] as $p) {
     $r = EmailService::renderPasswordResetBody($p, "https://x.test/r?token=t");
     $w = EmailService::renderWelcomeBody($p, "https://x.test/r?token=t");
     echo "--- payload=", json_encode($p), "\n";
     echo "  html script literal? ", (stripos($r["html"], "<script") === false && stripos($w["html"], "<script") === false ? "no" : "YES"), "\n";
     echo "  plain has control?  ", (preg_match("/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/", $r["plain"]) ? "YES" : "no"), "\n";
     echo "  visible name:       ", html_entity_decode(strip_tags($r["html"])), "\n";
   }
   '
   ```
   For the empty payload, the visible greeting must be `there`. For the `\r\nBcc:` payload, the plaintext body must not contain any newline other than the ones the template itself adds.

8. **Live SMTP** (only if the dev SMTP is configured — see `_smtp_test.php`):
   - Register a new user whose name is `<img src=x onerror=alert(1)>`.
   - Open the captured welcome email and confirm the name renders as visible text, **not** as an HTML image tag.
   - Confirm there is no second `Bcc:` header in the raw RFC822 message.

## What is OUT of scope (per the issue)

- Rate limiting on the password-reset / login endpoints (separate issue).
- CSRF on `GET` endpoints (not needed; GETs are read-only by spec).
- Re-architecting the email layer to use a templating engine — the goal is just to neutralise the name.

## Reporting

Please file findings under `docs/issues/issue-016/tester-report.md` (or extend the report you started). For each numbered check above, write one line of either:

```
1. CSRF contact_edit tamper: PASS — page shows "Your session could not be verified", no DB row.
```

or

```
5. Generic DB error (task save): FAIL — reproduced: <paste the failing user-visible message>.
```

At the end, summarise:
- Which of the 8 numbered checks passed.
- Whether the regression test runs green.
- Any deviations from the spec (with rationale).
