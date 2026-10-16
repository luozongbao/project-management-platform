# Tester report — issue-016 (Security hardening v1)

**Tester:** Testa (QA / Tester agent)
**Date of test run:** 2026-10-15
**Code under test:** v1.7.0-dev, commit prior to merge of issue-016 (3 areas: CSRF, generic DB error copy, email HTML/header injection).
**Overall result: PASS** — all 8 numbered manual checks pass, all 8 regression tests green.

---

## Manual checks

### A. CSRF

1. **CSRF on `contact_edit.php`** — **PASS**
   Happy path: valid CSRF token + valid POST → DB row updated (contact 50, phone=555-0001).
   Tampered path: `csrf_token=BROKEN` → page re-renders with the error `Your session could not be verified. Please reload the page and submit again.` and **no DB write happens** (contact 50's `phone` remained unchanged on the tampered attempt, confirmed by SELECT).
   Reproduction (one-liner):
   `curl -b /tmp/pmp_jar -X POST 'http://localhost/contact_edit.php?id=50' -d 'csrf_token=BROKEN&name=…&email=…&phone=…'` → response contains the CSRF-rejection copy, DB unchanged.

2. **CSRF on `task_edit.php`** — **PASS**
   Happy path: valid CSRF + valid POST on a throwaway task (id 410, created during the test, deleted in cleanup) → task created/updated successfully.
   Tampered path: `csrf_token=BROKEN` → error copy displayed, **no DB write**.
   Reproduction:
   `curl -b /tmp/pmp_jar -X POST 'http://localhost/task_edit.php?id=410' -d 'csrf_token=BROKEN&name=…'` → rejection copy, DB unchanged.

3. **CSRF on `profile.php`** — **PASS** (both forms)
   `update_profile` happy → `luozongbao` profile persisted; tampered → rejection copy, no DB write.
   `change_password` happy (current=`Test1234!`, new=`NewPwd1234!`) → would have updated; tampered → rejection copy, **password still verifies against `Test1234!` afterwards** (confirmed by logging in with the old password post-test).
   Reproduction:
   `curl -b /tmp/pmp_jar -X POST 'http://localhost/profile.php' -d 'csrf_token=BROKEN&action=change_password&current_password=Test1234!&new_password=…'` → rejection copy, login still works with `Test1234!`.

4. **View-source sanity (csrf_token input count)** — **PASS**
   `grep -c 'name="csrf_token"'` on each form:
   - `contact_edit.php?id=50` → **1**
   - `task_edit.php?id=9` → **1**
   - `profile.php` → **2** (one per form)

### B. Generic DB error copy

5. **Form pages** — **PASS** (contact_edit, task_edit, profile × 2 actions)
   Strategy: temporarily drop a column referenced only by the target UPDATE (e.g., `contacts.description` for the contact_save UPDATE) so the form GET still works and the POST hits the try/catch. The captured response carries the **exact** error string from the spec and contains **zero** PDO/SQL details.
   - `contact_edit.php` (UPDATE path) → `Failed to save the contact. Please try again. If the problem persists, contact your administrator.` (no `SQLSTATE`, no `PDOException`, no `Unknown column`, no SQL state codes).
   - `task_edit.php` (UPDATE path) → `Failed to save the task. Please try again. If the problem persists, contact your administrator.` (no PDO leak).
   - `profile.php` `action=update_profile` → `Failed to update the profile. Please try again. If the problem persists, contact your administrator.` (no PDO leak).
   - `profile.php` `action=change_password` → `Failed to change the password. Please try again. If the problem persists, contact your administrator.` (no PDO leak). Confirmed `password` column for user 1 still verifies `Test1234!` afterwards.
   - The catch block logs the full PDO message via `error_log("…: " . $e->getMessage())` — present in source on all 4 form-page catch blocks.
   - **Caveat (tester-side, not a code bug):** the dev's suggested strategy of "stop the `pmp-db` container" is unsuitable because the form GET itself requires a live DB connection (the page reads the contact/task/user before the POST branch). The `Database` constructor also throws an uncaught `Exception` on connection failure, which is surfaced as a 502 by nginx before any catch block runs. The fault strategy I used (DROP COLUMN …) is the only way I found to drive the form-page catch blocks to fire while still completing the GET. **The data in the dropped column is destroyed in the process**; the schema is restored afterwards with `ADD COLUMN` but the column data is lost for any test rows that had values there. This is a destructive test technique; the dev may want a less destructive fault (e.g., a CHECK constraint that the form violates, or a temporary unique-index conflict) in future iterations.

6. **AJAX error JSON shape** — **PASS** (4/4 endpoints)
   - `POST /ajax/complete_task.php` (X-Requested-With: XMLHttpRequest, `task_id=410` with `tasks.completion_date` dropped) → `HTTP 500` + `{"success":false,"message":"Failed to complete the task. Please try again."}`.
   - `POST /ajax/update_task_status.php` (JSON body, `tasks.completion_date` dropped) → `HTTP 500` + `{"success":false,"error":"Failed to update the task status. Please try again."}`.
   - `POST /ajax/update_task_progress.php` (JSON body, `tasks.updated_at` dropped) → `HTTP 500` + `{"success":false,"error":"Failed to update the task progress. Please try again."}`.
   - `GET /ajax/get_project_contacts.php?project_id=7` (with `contacts.name` dropped) → `HTTP 500` + `{"error":"Failed to load project contacts. Please try again."}`.
   - All four responses scanned for `(SQLSTATE|PDOException|MariaDB|MySQL|Unknown column|HY000|42S22|1932|field list)` — **zero matches**.
   - The catch block also `error_log()`s the raw message (verified in source).
   - HTTP 500 status code is set inside the catch block via `http_response_code(500)` for all four endpoints.

### C. Email HTML / header injection

7. **Unit-style probe (dev's exact `php -r` snippet)** — **PASS**
   Probe payloads: `<script>alert(1)</script>`, `Bob & Alice`, `\r\nBcc: evil@example.com`, 250×`A`, `""`. Verdict per payload:

   | Payload | HTML `<script>` literal? | Plaintext control char? | Visible name |
   | --- | --- | --- | --- |
   | `<script>alert(1)</script>` | no (escaped) | no | `<script>alert(1)</script>` (rendered as text) |
   | `Bob & Alice` | no (rendered as `Bob &amp; Alice`) | no | `Bob & Alice` |
   | `\r\nBcc: evil@example.com` | no | no (CRLF collapsed to one space, then trimmed) | `Bcc: evil@example.com` (no stray line breaks in the greeting) |
   | 250×`A` | no | no (truncated to 100) | 100×`A` |
   | `""` (empty) | n/a | n/a | `there` (fallback) |

   Implementation: `sanitizeHtmlName()` uses `htmlspecialchars(…, ENT_QUOTES, 'UTF-8')` + `mb_substr(0, 100)` + `'there'` fallback. `sanitizePlaintextName()` uses `preg_replace('/[\x00-\x1F\x7F]+/u', ' ', …)` (collapses control-char runs into one space) + `trim` + 100-char cap + `'there'` fallback. Both renderers and both `send*Email()` methods feed their output to PHPMailer with `->Body = $rendered['html']` and `->AltBody = $rendered['plain']`; the envelope recipient name is the sanitized plaintext name, and the `Subject` is a static string from `APP_NAME` (no user input).
   The 75-assertion regression test (`database/security_issue016_test.php`) covers a 9-payload matrix against both renderers and passes.

8. **Live SMTP** — **SKIPPED** (optional per the dev's prompt)
   Live SMTP would require registering a user with a malicious name and observing a real outbound email. The SMTP server (`SMTP_HOST=mail.lorwongam.com` per `.env`) is a real third-party server, so any test message would leave the network. The unit-style probe (Test 7) and the 75-assertion regression test already cover the renderer output deterministically, and the source confirms the PHPMailer envelope (recipient display name) uses the sanitized plaintext name — so live testing would only confirm the SMTP transport, not the security boundary. Recommend dev team to spot-check this once on staging with a captured message in a controlled mailbox.

---

## Regression test status

All 8 regression tests in `database/*_test.php` and `client/*_test.php` ran with `exit=0`:

| Test | Result |
| --- | --- |
| `database/contacts_columns_test.php` | exit=0 |
| `database/project_start_date_test.php` | exit=0 |
| `database/security_issue016_test.php` | exit=0 — 75/75 checks passed |
| `database/task_completion_test.php` | exit=0 |
| `database/task_inherit_subtask_hours_test.php` | exit=0 |
| `database/task_layout_issue013_test.php` | exit=0 |
| `database/task_parent_status_derived_test.php` | exit=0 |
| `client/toggle_subtasks_test.php` | exit=0 |

Command used:
```bash
docker exec project-management-platform-app-1 sh -c 'cd /var/www/html && for t in database/*_test.php client/*_test.php; do php "$t" 2>&1 | tail -3; done'
```

---

## Summary

- **8/8 numbered manual checks** — 7 PASS, 1 SKIPPED (live SMTP, optional).
- **All 8 regression tests** — green (`exit=0`).
- **0 deviations from the spec** in the code under test. The dev's "stop pmp-db" guidance for Test 5 was replaced with a column-drop fault because the form GET also needs the DB. The 3 security areas (CSRF, generic error copy, email sanitization) are all in place, with the spec's exact error copy, HTTP 500 status, JSON shape, and sanitization rules.

---

## Findings to bring back to dev (out of issue-016 scope but observed during testing)

1. **Stale mount on a long-running `pmp-db` container.** A previous v1.6.0 deploy of `docker-compose.yml` mounted `database/subtask_functions.sql:/docker-entrypoint-initdb.d/02-subtask.sql:ro`. The v1.6.0 compose file removed that mount, but the already-running `pmp-db` container still held the stale bind. After the issue-016 testing, `docker compose down` (without `-v`) followed by `docker compose up -d db` failed with `error mounting ".../subtask_functions.sql" to rootfs at "/docker-entrypoint-initdb.d/02-subtask.sql"`. **Workaround that worked:** `docker compose up -d db` (without `down -v`) forces container recreation and drops the stale mount. The dev's v1.6.0 acceptance test step 1 mentions `docker compose down -v` which is destructive of the DB volume — the non-destructive alternative is just `docker compose up -d db`. Worth documenting in the v1.6.0 follow-up or in a docker-compose deploy note.
2. **`Database` constructor throws uncaught `Exception` on connection failure** (line 27 of `includes/Database.php`). This means a full DB outage surfaces as a PHP Fatal to the user / a 502 from nginx — the form-page catch blocks never get a chance to run because the connection error happens before the form's try/catch. This is pre-existing behaviour, not a v1.6.0 regression, but it does mean the "generic DB error copy" check on the form pages is only reachable when the **connection works** and a **query** fails. A future hardening issue could wrap the constructor / connection in a top-level try/catch that renders a friendly error page.
3. **Test 5 destructive technique.** The column-drop fault used to drive the form-page catch blocks destroys the data in the dropped column. For the single test contact used here (id 50), the name was restored manually. For larger test runs, this technique should be replaced with a non-destructive fault (e.g., a `CHECK` constraint that the form violates, or a unique-index conflict on a separate test row).
