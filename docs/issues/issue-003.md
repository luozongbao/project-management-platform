# Protect Private Files and Direct Endpoints

**Status:**  [IMPLEMENTED]  
**Type:** Security

## Problem

Files such as `.env`, `.env.example`, `config.php`, `config.template.php`,
`.htaccess`, and `_smtp_test.php` should not be exposed or executable as public
web resources. Hiding links to these files is not access control: a visitor can
request a known URL directly, and an HTTP request cannot reliably prove that a
user arrived by clicking an application link.

The deployed stack uses Nginx. The existing `.htaccess` deny rules are not
applied by Nginx, and the current Nginx configuration does not explicitly block
`_smtp_test.php`.

## Requirements

- Inventory files and routes under the document root and classify each as
  intentionally public, authenticated application functionality, or private.
- Deny web access to environment files, configuration/templates, server
  configuration, source and dependency directories, documentation, database
  files, logs, backups, and development/diagnostic scripts. In particular,
  `_smtp_test.php` must not be callable in production.
- Enforce these denials in the deployed Nginx configuration. Keep equivalent
  Apache rules only if Apache remains a supported deployment.
- Ensure each intentionally public PHP endpoint performs its own required
  authentication and authorization checks. Do not use `Referer`, link
  visibility, or obscure filenames as an access-control mechanism.
- Prefer keeping secrets and non-public source files outside the web document
  root where deployment permits; retain server-level deny rules as defense in
  depth.
- Document the protected file policy and verify it against the actual Docker
  Nginx deployment.

## Acceptance Criteria

- HTTP requests for `.env`, `.env.example`, `config.php`,
  `config.template.php`, `.htaccess`, `_smtp_test.php`, and representative
  private files/directories return a denial or not-found response and disclose
  no file contents, configuration values, or diagnostic output.
- Existing public assets and intended application pages continue to work.
- Every authenticated feature remains inaccessible without the required
  session and resource authorization, even when its URL is requested directly.
- Automated or documented deployment checks cover Nginx behavior; protections
  do not depend solely on `.htaccess`.

## Security Notes

Changing filenames to slugs or hiding links does not protect a resource. Slugs
are identifiers, not credentials. Public client access must use an explicit,
high-entropy access token/code and remain limited to the intended read-only
data.
