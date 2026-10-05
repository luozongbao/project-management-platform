# Require Project Code to Confirm Deletion

**Status:** Implemented
**Type:** Feature / Security

## Problem

The project edit page currently uses a browser confirmation prompt and then
deletes the project by navigating to `project_delete.php?id=...`. A single
confirmation click is easy to trigger accidentally, and the deletion endpoint
performs a destructive action through a GET request. Confirmation must use the
human-facing Project Code shown in the application, not the internal numeric
database ID.

## Requirements

- Replace the simple browser confirmation with an accessible confirmation
  dialog that clearly states project deletion is permanent and may remove
  associated tasks and contacts.
- Show the project's Project Code in the dialog and require the project manager
  to type that exact code before enabling or accepting the confirmation action.
  The Project Code is the existing `projects.share_code` value.
- Continue using the internal project ID only to identify the target record;
  never present it as the confirmation phrase.
- Submit deletion using POST, not GET, and include the application's standard
  CSRF protection.
- On the server, require an authenticated project owner/manager, verify
  ownership of the target project, and compare the submitted confirmation
  code to that project's stored `share_code` before deleting. Never rely on
  client-side checks alone.
- If a legacy project has no `share_code`, do not fall back to its database ID;
  keep deletion non-destructive and explain that a Project Code is required.
- Keep cancellation, a mismatched ID, missing/invalid CSRF token, or missing
  authorization non-destructive and provide clear feedback.
- Preserve the existing success/failure messaging and database cascade
  behavior.

## Acceptance Criteria

- Opening the delete action displays the project-specific warning and requires
  the exact Project Code; the dialog cannot submit while the value is empty or
  mismatched.
- Canceling or submitting a mismatched ID leaves the project and its related
  data unchanged.
- A valid confirmation from the authorized owner via a valid POST deletes the
  project and preserves the existing related-record behavior.
- Direct GET requests to the deletion endpoint do not delete anything.
- Requests without authentication, ownership, or a valid CSRF token cannot
  delete the project.
- A project with no Project Code cannot be deleted through this flow, and the
  user is told that the database ID is not an acceptable substitute.
- The dialog is keyboard accessible and works at narrow/mobile viewport sizes.

## Security Notes

The Project Code is a confirmation phrase, not a secret or authorization
credential. Session authentication, ownership checks, CSRF validation, and
server-side comparison are still required. The hidden project ID identifies
the record being deleted but is not the confirmation phrase.
