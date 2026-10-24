# issue-040 — use IDs instead of slugs in public URLs

## Request

> for all project/[project name] and /tasks/[task name] and /contacts/[contact name]
> Instead of [project name], [task name], and [contact name], we should use ID instead.

## Decision

Switch the canonical detail URL for all three collections from slug to numeric id,
and keep every existing slug URL working by 301-redirecting it to the new id URL.

    /projects/<slug>   ->  301  ->  /projects/<id>
    /tasks/<slug>      ->  301  ->  /tasks/<id>
    /contacts/<slug>   ->  301  ->  /contacts/<id>

Both forms are routed. The id is canonical; the slug is accepted only so old links
resolve.

## Rationale

Slugs are mutable. `generateUniqueSlug()` appends `-2` when a name collides, so
renaming a project changes its URL and breaks bookmarks, inbound links and search
results. Ids are stable for the life of the row.

The cost is readability: `/projects/858` says nothing where
`/projects/harbour-point-renovation` did. Accepted deliberately — stable URLs beat
pretty URLs for data that other people link to.

## Why redirects are kept rather than a hard cutover

Project slugs are already in clients' inboxes and bookmarks, and may be indexed.
A hard cutover would 404 all of them. A 301 preserves them, tells search engines
the id URL is canonical, and lets the slug columns eventually be dropped in a
later migration once the redirects are no longer wanted.

## Schema

`slug varchar(80) NOT NULL UNIQUE` stays on `projects`, `tasks` and `contacts`.

The columns are no longer needed for routing, but are retained so `legacy_redirects`
can still resolve an old slug to its id. Dropping them is deferred to a future
migration, deliberately not done here: it would remove the ability to resolve
historical links at all, which is the one thing this change exists to protect.

## Scope

| Route | New canonical |
|---|---|
| `/projects/<slug>` | `/projects/<id>` |
| `/projects/<slug>/edit` | `/projects/<id>/edit` |
| `/projects/<slug>/delete` (POST) | `/projects/<id>/delete` (POST) |
| `/tasks/<slug>` | `/tasks/<id>` |
| `/tasks/<slug>/edit` | `/tasks/<id>/edit` |
| `/contacts/<slug>` | `/contacts/<id>` |
| `/contacts/<slug>/edit` | `/contacts/<id>/edit` |

Unchanged: `/projects`, `/projects/new`, `/tasks`, `/tasks/new`, `/contacts`,
`/contacts/new`, and every non-slug route.

The client portal (`/client/...`) is out of scope. It is addressed by `share_code`,
never by slug, and its routes are separate. Its share-code contract is untouched.

## Implementation notes

- `includes/Router.php`: the `<slug>` route patterns become `<id>`. The slug
  branch is kept, because that is what performs the 301.
- The `<id>` branch validates with `ctype_digit` and rejects `0`, matching how the
  pages already treat a missing id. `ctype_digit` on an empty string is false, so
  `/projects//edit` falls through to 404 rather than resolving id 0.
- `/projects/new` shadowing was a hazard only because `new` could have matched a
  slug pattern. With `<id>` it is structurally impossible, since `new` is not
  numeric.
- `includes/legacy_redirects.php`: gains a slug-to-id redirect table alongside the
  existing `.php` redirects. The `.php` redirects stay — `project_detail.php?id=12`
  must still resolve, and now 301s onward to `/projects/12`.
- Link builders in `projects.php`, `tasks.php`, `contacts.php`, `dashboard.php` and
  the three detail pages switch to the id form.

## Tests

`slug_http_test.php` updated. Its V37/V38/V39 cases asserted the slug contract
directly and were the only tests to fail:

| Case | Was | Now |
|---|---|---|
| V37 | `/projects/<slug>` returns 200 after a rename | returns 301 to `/projects/<id>` |
| V38 | `project_detail.php?id=N` → `/projects/<slug>` | → `/projects/<id>` |
| V39 | `?foo=bar` survives to the slug URL | survives to the id URL |

60 assertions pass, 0 fail.

`slug_routing_test.php` needed no change: it tests `slugify()` and
`findIdBySlug()` directly, and both are still what the 301 depends on.

Tests that only *insert* rows — `task_edit_idor_test.php`, `probe_diag_test.php`,
`contacts_columns_test.php`, `task_parent_status_derived_test.php`,
`task_inherit_subtask_hours_test.php`, `client/toggle_subtasks_test.php` —
insert a slug because the column is `NOT NULL`. Those inserts stay valid.

## Verification

- Full sweep: **18 PASS / 2 FAIL**, matching the pre-existing baseline. The two
  failures (`client_share_code_protection_test.php`, `security_headers_test.php`)
  are unrelated and were failing before this change.
- All 7 pages render 200 and parse with no mismatched or unclosed tags.
- Every collection link emitted across all pages is numeric; `/new` routes intact.
- Redirect chain verified over HTTP: `project_detail.php?id=858` → 301 →
  `/projects/858`, and `?foo=bar` survives.
- `/projects/0`, `/projects/007`, `/projects/-1`, `/projects/nonexistent-slug`,
  `/projects/858/foo` all 404. A non-existent id 302s to the collection via the
  pre-existing page-level guard, which is unchanged behaviour.

## Bug found and fixed during implementation

The first pass rewrote project links that read an aliased `project_slug` column
into `$task['id']`, which is the *task's* id, not the project's — so four
"Project" links pointed at the wrong record. Fixed by selecting `project_id`
explicitly instead. Worth recording because the diff looked correct: only the
rendered output revealed it.

## Status

Implemented and verified.
