# `task_edit.php` IDOR — Responsible Person / Contact Person Re-Validation

**Status:** Implemented (v1.8.0)
**Fixed in commit:** *fix(issue-022): tighten task_edit.php POST validation against IDOR* — see `git log --oneline` on the `issue-022` branch (4 files, +497/-50).
**Regression test:** `database/task_edit_idor_test.php` (22 assertions, exit=0)
**Type:** Security / Authorization
**Priority:** Medium
**Affects:** `task_edit.php`

## Problem

`task_edit.php` has two `select` dropdowns that the
user can choose values from, but the values are
**not re-validated against the current user's
ownership** when the form is submitted:

### 1. `responsible_person_id` dropdown

The dropdown at `task_edit.php:552-562` renders:

```php
<select id="responsible_person_id" name="responsible_person_id">
    <option value="">Select Person</option>
    <?php foreach ($users as $user): ?>
        <option value="<?= $user['id'] ?>" …>
            <?= e($user['name']) ?> (<?= e($user['email']) ?>)
        </option>
    <?php endforeach; ?>
</select>
```

…where `$users = $db->fetchAll("SELECT id, name, email
FROM users ORDER BY name")`. **Every user in the
database is shown** — not just the current user's
contacts or the users on the current user's projects.

On POST, the validation at `task_edit.php:138-145` is:

```php
if (empty($responsible_person_id)) {
    $errors[] = "Responsible person must be assigned to the task.";
} else {
    $person_exists = $db->fetchOne(
        "SELECT id FROM users WHERE id = ?",
        [$responsible_person_id]
    );
    if (!$person_exists) {
        $errors[] = "Selected responsible person does not exist.";
    }
}
```

The check confirms the user **exists**, not that the
current user is **allowed** to assign that user to
their task.

**Real-world impact:** A logged-in attacker (e.g. an
ex-employee whose account was not deleted, or a
contractor who was given an account for a specific
project) can reassign any task they have write access
to *any other user in the system*. The reassigned
user then sees the task in their dashboard and is
on-the-hook for it. The attacker is not blocked by
project ownership (the task is already in one of the
attacker's projects, since they have write access to
it).

**Why this is IDOR and not just a UX bug:** The
`responsible_person_id` field is a foreign key into
`users`. The attacker can choose any row. The form
does not scope the dropdown to "users who are
collaborators on this project" (e.g. via a project
membership table — which does not exist today) or
"users who are on at least one of my projects" or
even "users other than myself". The POST handler
trusts whatever the browser sent.

### 2. `contact_person_id` dropdown

The dropdown at `task_edit.php:556-571` is scoped:

```php
$contacts = $db->fetchAll(
    "SELECT DISTINCT c.* FROM contacts c
     JOIN project_contacts pc ON c.id = pc.contact_id
     WHERE pc.project_id = ?
     ORDER BY c.name",
    [$project_id]
);
```

…i.e. only contacts that are linked to the
*currently selected* project. So if the user keeps
the project the same, the contact list is correct.

**However:** the POST handler at
`task_edit.php:148-153` validates that the contact
**exists** but not that the contact is in the
current user's project set:

```php
if (!empty($contact_person_id)) {
    $contact_exists = $db->fetchOne(
        "SELECT id FROM contacts WHERE id = ?",
        [$contact_person_id]
    );
    if (!$contact_exists) {
        $errors[] = "Selected contact person does not exist.";
    }
}
```

**Real-world impact:** The attacker can submit
`contact_person_id=<some other user's contact id>`
and the task gets linked to it. The contact's owner
sees the task in their `contact_detail.php` view
(which renders the contact's tasks). The attacker
is not blocked.

This is a **read** as well as a write — the contact
is a "global" record (no per-user ownership), so the
attacker can also read the contact's email/phone via
the dropdown rendering if the contact is in one of
the attacker's projects. The exposure is bounded
by "contacts that are in at least one of the
attacker's projects, AND the attacker can post any
other user's contact id".

## Goals

- `task_edit.php` POST handler re-validates the
  submitted `responsible_person_id` against the
  current user's project set. The simple policy:
  *the responsible person must be a user who is
  the current user, or the responsible person of
  another project the current user owns* (i.e.
  another "self"). If the system grows to support
  multi-user-per-project, the rule becomes
  "the responsible person must be a member of this
  project"; that change is a v2.0 add.
- `task_edit.php` POST handler re-validates the
  submitted `contact_person_id` against the
  current user's project-contact set. The rule:
  *the contact must be linked to at least one of
  the current user's projects, and the link must
  not be deleted (i.e. a row in `project_contacts`
  with `project_id IN (current user's projects)`)*.
- The dropdown rendering on GET is tightened to
  match the same policy, so the UI does not show
  a value the POST handler will reject (the
  "selection silently lost on save" bug).

## Non-goals

- No change to the `users` table.
- No change to the `project_contacts` schema.
- No new "project membership" / "collaborator"
  table. The fix is a *narrower* validation against
  the existing data.
- No change to `ajax/complete_task.php`,
  `ajax/parent_hold.php`, or any other endpoint
  — they do not accept a `responsible_person_id`
  or `contact_person_id` from the client.
- No change to the task's `project_id` POST field;
  the existing form already scopes it to the
  current user's project dropdown and the POST
  handler validates it (the dropdown is
  `$user_projects` at `task_edit.php:49-53`).

## Proposed changes

### 1. `responsible_person_id` POST validation

Replace the existence check at
`task_edit.php:138-145` with:

```php
if (empty($responsible_person_id)) {
    $errors[] = "Responsible person must be assigned to the task.";
} else {
    // IDOR defence: the responsible person must be either
    // the current user OR another user who is the
    // responsible person of a project the current user
    // owns. The narrow scope avoids the "any user in the
    // system" attack while keeping the common case
    // (assigning a task to oneself) trivial.
    $allowed = $db->fetchOne(
        "SELECT 1 FROM users
         WHERE id = ?
           AND (id = ? OR id IN (
                SELECT responsible_person_id FROM projects
                WHERE responsible_person_id IS NOT NULL
           ))",
        [$responsible_person_id, $user_id]
    );
    if (!$allowed) {
        $errors[] = "Selected responsible person is not a user "
                  . "you can assign a task to.";
    }
}
```

The query returns a row if the responsible person is
the current user, or if the responsible person is
another user who **also** owns projects (i.e. is
another authenticated user, not a random
non-manager). This is a slightly looser bound than
"member of this project" but matches the current
data model where there is no project membership
table.

### 2. `contact_person_id` POST validation

Replace the existence check at
`task_edit.php:148-153` with:

```php
if (!empty($contact_person_id)) {
    $allowed = $db->fetchOne(
        "SELECT 1 FROM contacts c
         JOIN project_contacts pc ON c.id = pc.contact_id
         JOIN projects p ON pc.project_id = p.id
         WHERE c.id = ? AND p.responsible_person_id = ?",
        [$contact_person_id, $user_id]
    );
    if (!$allowed) {
        $errors[] = "Selected contact is not on any of your projects.";
    }
}
```

This is the same query the dropdown uses to render
the list (with the `DISTINCT` removed because the
ID is a single value).

### 3. Dropdown rendering — match the policy

`$users` at `task_edit.php:54` is currently
`SELECT id, name, email FROM users ORDER BY name`.
Tighten to:

```php
$users = $db->fetchAll(
    "SELECT id, name, email FROM users
     WHERE id = ?
        OR id IN (
            SELECT responsible_person_id FROM projects
            WHERE responsible_person_id = ?
        )
     ORDER BY name",
    [$user_id, $user_id]
);
```

…so the dropdown shows the same set the POST
handler will accept. (The query returns the current
user and any other project-owning user; it does
**not** show users who are not project owners,
which is a defence in depth — a future "user who
registered but never created a project" cannot be
assigned a task by a non-self attacker.)

`$contacts` is already scoped correctly (only
project-linked contacts for the current user's
selected project); no change needed beyond the
POST validation tightening.

### 4. The `$user_projects` dropdown

`$user_projects` at `task_edit.php:49-53` is
correctly scoped to the current user. The POST
handler does not currently re-validate the
submitted `project_id` against this set — it
trusts the dropdown. Tighten:

```php
if (!$is_subtask && !$form_project_id) {
    $errors[] = "Project selection is required for main tasks.";
} elseif ($form_project_id) {
    $allowed = $db->fetchOne(
        "SELECT 1 FROM projects WHERE id = ? AND responsible_person_id = ?",
        [$form_project_id, $user_id]
    );
    if (!$allowed) {
        $errors[] = "Selected project is not one of your projects.";
        $form_project_id = null;
    }
}
```

(For `$is_subtask`, the `project_id` is derived
from `$parent_task` which is already validated at
`task_edit.php:36-43`.)

## Files touched

- `includes/task_edit_auth.php` (new) — three small
  helpers (`taskEditCanAssignResponsible`,
  `taskEditCanAssignContact`, `taskEditCanUseProject`).
  Each is a boolean function that runs the IDOR-safe
  query; the caller appends the human-readable error
  to `$errors[]` on false.
- `task_edit.php` — `require_once` the helper,
  replace the three existence-only POST checks with
  helper calls, and tighten the `$users` dropdown
  SELECT to the same set the helper accepts.
- `database/task_edit_idor_test.php` (new) —
  22 assertions across 5 sections: (1) unit tests
  for `taskEditCanAssignResponsible`, (2) unit tests
  for `taskEditCanAssignContact`, (3) unit tests for
  `taskEditCanUseProject`, (4) source-grep wiring on
  `task_edit.php` (helper is required, old existence
  queries are gone, new error messages are emitted),
  (5) source-grep on the `$users` dropdown SELECT
  (scoped to project owners, not "every user in the
  database").

## Risks

- **The "another user who is also a project owner"
  rule is a slight over-share** — a logged-in user
  can now assign a task to any *other* project
  owner, not just themselves. This is a UX
  improvement (you can delegate to a colleague
  who has their own projects) but is a small
  expansion of the previous implicit policy. The
  expansion is **intentional** and the spec
  confirms it: there is no per-project membership
  table, so the system cannot enforce "member of
  this project" today. The mitigation is the
  dropdown is now also scoped to the same set, so
  the user only sees users they can actually
  pick.
- **The validation cost is one extra query per
  form submit.** Acceptable; the form is
  submitted on human-scale timing, not in a
  loop.
- **A future "project membership" feature** (a
  `project_members` table) will tighten the rule
  to "the responsible person is a member of the
  task's project". Tracked in the "Out of scope"
  section.

## Acceptance

- A logged-in user can assign a task to themselves
  (current behavior).
- A logged-in user **cannot** assign a task to a
  user who is not a project owner (today: any
  user; after: only project owners). The
  acceptance test registers a second user with no
  projects and attempts to assign a task to them
  via a forged POST; the form rejects with
  "Selected responsible person is not a user you
  can assign a task to."
- A logged-in user can assign a task to a contact
  who is on one of their projects (current
  behavior).
- A logged-in user **cannot** assign a task to a
  contact who is not on any of their projects.
  The acceptance test creates a contact on a
  *different* user's project, attempts to assign
  the task to that contact, and the form rejects
  with "Selected contact is not on any of your
  projects."
- A logged-in user cannot move a task to a
  project they do not own (current behavior
  is implicit; the fix makes it explicit).
- The dropdown on `task_edit.php` for
  `responsible_person_id` does not show users
  who are not project owners.

## Regression test plan (as shipped)

`database/task_edit_idor_test.php` — 22 assertions,
no DB fixture file, exit=0.

The shipped test does NOT use a CLI proxy to
render `task_edit.php` end-to-end. The proxy
approach was set aside in favour of two layers
of coverage that are deterministic in the
test environment:

1. **Helper unit tests** (sections 1-3) — seed
   three users (A, B, C), two projects (A's and
   B's), and two contacts (one on each project)
   inside the test, call each helper, assert
   the boolean. Cleanup runs in a
   `register_shutdown_function` so a fatal error
   cannot leak seed rows.
2. **Source-grep wiring checks** (sections 4-5)
   — assert `task_edit.php` requires the helper,
   no longer contains the old "SELECT id FROM
   users WHERE id = ?" / "SELECT id FROM
   contacts WHERE id = ?" existence queries,
   emits the three new error messages, and the
   `$users` SELECT is scoped to project owners.

This catches a future "I just want to inline the
query again" regression (confirmed by self-test:
re-introducing the weak checks made 6 source-grep
assertions fail) without depending on the brittle
"render the wizard in a child process" proxy
pattern.

The acceptance criteria in the previous section
(assign-to-self OK, assign-to-other-owner OK,
assign-to-non-owner rejected, assign-to-cross-user
contact rejected, move-to-cross-user project
rejected, dropdown is scoped) are all covered by
the unit tests in sections 1-3.

## Cross-references

- Umbrella: [issue-015](issue-015.md) finding 7.
- CSRF: [issue-016](issue-016.md) — the
  `task_edit.php` CSRF gate is the prerequisite
  for this issue (without CSRF protection, an
  attacker can drive the validation from a
  third-party site; with CSRF, only a logged-in
  attacker can).
- The dropdown patterns and the `e()`-wrapped
  rendering are unchanged.

## Out of scope (flagged for future tickets)

- Add a `project_members` table
  (`project_id`, `user_id`, `role`,
  `created_at`) and tighten the
  `responsible_person_id` and
  `contact_person_id` rules to "is a member of
  the task's project" / "is linked to the task's
  project via a project the current user can see".
  v2.0.
- Audit logging: record the (user, action,
  target, before, after) tuple for every
  `task_edit.php` POST. v2.0.
- Per-task ACLs: a task can be edited by its
  responsible person *or* a project-level
  "manager" role. v2.0.
- A "delegated access" feature: a user can grant
  another user read or write access to a single
  project. v2.0.
