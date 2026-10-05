# Move Client Project Access to the Root Landing Page

**Status:** Open  
**Type:** Feature / Routing

## Problem

Clients currently enter a project share code at `/client/`, while `/` sends
visitors directly to the owner dashboard or login page. Owners and clients need
a clear shared entry point at the domain root.

## Requirements

- Make `/` the public landing page with a field for entering a project access
  code and a submit action that opens the existing read-only client project
  view.
- Add a clearly visible owner login link on the landing page that opens
  `/login.php`.
- Update generated client share links and application navigation so they no
  longer send clients to `/client/` as the entry page.
- Retain the existing random `share_code` as the client access credential.
  Do not allow a sequential/internal project ID to grant access to project
  details. A readable slug may be used as a display/routing alias only; it is
  not a substitute for authorization or an unguessable access code.
- If a project is not found or the submitted code is malformed, show a useful
  error without disclosing other projects or confirming sensitive details.
- Preserve the existing client dashboard's read-only behavior and localization.
- Define the legacy `/client/` behavior during implementation (redirect it to
  the root entry or retire it) so old bookmarks and shared links have a
  deliberate outcome.

## Acceptance Criteria

- Visiting `/` while logged out shows the client access-code form and owner
  login link instead of automatically redirecting to login.
- Submitting a valid share code opens only that project's existing read-only
  dashboard; a project ID or invalid/unknown code does not expose project
  details.
- The login link reaches the existing owner login flow, and logging in retains
  the current owner dashboard behavior.
- Newly generated client links use the root entry URL and continue to open the
  intended project.
- The chosen behavior for legacy `/client/` URLs is tested and documented.
