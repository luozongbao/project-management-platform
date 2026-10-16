# CSRF on `contact_edit.php`, `task_edit.php`, and `profile.php`

**Status:** Merged into [issue-016](issue-016.md)
**Type:** Security
**Priority:** High

This issue was filed separately during the v1.6 audit but
has been folded into [issue-016](issue-016.md) §A because
the three endpoints, the `ajax/*.php` error-echo fix, and
the `EmailService` HTML injection fix share a single
file-touch set and a single regression test. Closing this
file with a pointer.
