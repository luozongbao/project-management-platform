# Contact Create/Edit Fails Because `contacts` Is Missing `company`, `position`, `address`

**Status:** Implemented — Shipped in **v1.2.1** (bundled with issue-008 + the
CSRF hardening originally planned as v1.3.1)
**Type:** Bug — schema/code drift (same class as issue-001)
**Priority:** High — every contact create/edit fails until the schema catches up
**Affects:** `contacts` table, `contact_edit.php` (create + edit), `contact_detail.php`, `contacts.php`, `ajax/get_project_contacts.php`, `includes/Database.php`, `database/schema.sql`, new `database/migrations/20261006_add_contacts_company_position_address.sql`, new `database/contacts_columns_test.php`

## Problem (original report — verbatim)

> When I create a new contact with Contact Name `张总` and WeChat ID `YADEPAN18`
> and tick one project's "Assign to Project" box, the form returns
> **"Error saving contact: Database execute failed"** and no contact is
> saved. Only the Contact Name field is required; all other fields are
> optional.

The form submits successfully and validation passes, so the failure is
on the database write — not on input validation.

## Root cause (original — historical)

`contact_edit.php` binds `$_POST['company']`, `$_POST['position']`, and
`$_POST['address']` into both the create `INSERT INTO contacts (...)` and
the edit `UPDATE contacts SET ...`. The `contacts` table shipped by
`database/schema.sql` (and the running database) originally had only:

```
id, name, description, mobile, email, phone, wechat, line_id,
facebook, linkedin, created_at, updated_at
```

`company`, `position`, and `address` were **not present**. Every prepared
`INSERT`/`UPDATE` against `contacts` failed with MariaDB error
`1054 (42S22): Unknown column 'company' in 'INSERT INTO'`. The
`Database::execute()` wrapper caught the `PDOException` and rethrew it
with the generic message `"Database execute failed"`, which is what
surfaced to the form.

The user's specific input (`张总` / `YADEPAN18`) did not matter — the
failure happened regardless of which optional fields were filled in,
because `company`, `position`, and `address` were bound unconditionally.

### Schema drift history (background)

The same drift class was already documented and fixed for `phone` in
[issue-001](./issue-001.md) via migration
`20261003_add_contacts_phone.sql`. That fix added the missing
`contacts.phone` column but did **not** add `company`, `position`, or
`address`. Three pieces of code, no schema, no migration.

## Goals (recap from original spec)

- Make every contact create / edit submission actually persist by
  bringing the `contacts` table into agreement with the code.
- Add the missing columns in a way that is safe on databases that
  already have contact rows (existing data must not be lost; new columns
  default to `NULL`).
- Match the column types the form already implies:
  - `company` — `VARCHAR(255) NULL`
  - `position` — `VARCHAR(255) NULL`
  - `address` — `TEXT NULL` (rendered as a multi-line textarea; the
    existing `description` column is `TEXT`).
- Update `database/schema.sql` so fresh installs match the migrated
  shape; otherwise the next person who reinstalls hits the same bug.
- Surface the underlying PDO error in dev/logs (do not silently keep
  the generic wrapper message) so the next drift bug is obvious.

## Implementation summary

### 1. Migration — `database/migrations/20261006_add_contacts_company_position_address.sql`

Idempotent, three-statement migration, each guarded by an
`information_schema.COLUMNS` check (same shape as
`20261003_add_contacts_phone.sql`):

- `contacts.company` — `VARCHAR(255) NULL`, placed `AFTER phone`.
- `contacts.position` — `VARCHAR(255) NULL`, placed `AFTER company`.
- `contacts.address` — `TEXT NULL`, placed `AFTER description`.

DDL implicitly commits in MariaDB; the file is **not** wrapped in a
transaction (deliberate; the comment at the top of the file calls this
out so a future dev does not "fix" it by adding `START TRANSACTION; …;
COMMIT;`).

### 2. Fresh-install schema — `database/schema.sql`

The `CREATE TABLE contacts (...)` definition now lists the three
columns in the same order the migration adds them, so a fresh
`install.php` run produces a schema identical to a migrated database.
The new columns carry inline `-- issue-009` comments for grep-ability.

### 3. `Database::execute()` and `Database::query()` — surface PDO error message

The generic wrapper message used to swallow the underlying
`PDOException` text. Both methods now concatenate the PDO message onto
the thrown `Exception` message:

````php
// filepath: includes/Database.php
public function execute($sql, $params = []) {
    try {
        $stmt = $this->pdo->prepare($sql);
        return $stmt->execute($params);
    } catch (PDOException $e) {
        error_log("Database execute failed: " . $e->getMessage());
        // Surface the underlying PDO message so schema-drift bugs
        // (Unknown column …, Unknown table …) are visible to callers
        // without requiring a log tail. The wrapper Exception still
        // starts with the generic prefix that older callers (and test
        // suites) match on.
        throw new Exception("Database execute failed: " . $e->getMessage(), 0, $e);
    }
}
````

`query()` gets the same treatment with `"Database query failed: …"`. The
third argument (`$previous`) is the original `PDOException` so stack
traces are preserved for Sentry-style error reporters.

This is a low-risk change: the wrapper still throws `Exception`,
callers still see `getMessage()` start with `"Database execute failed"`
or `"Database query failed"` (so existing regex / substring tests keep
matching), and the developer-facing message now includes the real
reason.

### 4. Regression test — `database/contacts_columns_test.php`

Three layers of verification, runs entirely inside the `pmp-app`
container via `docker compose exec -T app php …`:

1. **Schema introspection** — `DESCRIBE contacts` returns
   `company VARCHAR(255) NULL`, `position VARCHAR(255) NULL`,
   `address TEXT NULL` in addition to the pre-existing columns. Catches
   any future migration that drops a column by accident.
2. **Live DB round-trip** — INSERTs a contact with the exact input
   from the bug report (`name='张总'`, `wechat='YADEPAN18'`, one project
   assigned, all other optional fields NULL). Verifies the new columns
   default to NULL. UPDATEs them and re-reads to confirm persistence.
   This is the failure mode that would have caught issue-001 and
   issue-009 on the day they were introduced.
3. **Source-grep checks** on `contact_edit.php` and `contacts.php`:
   the form still binds `company` / `position` / `address`; the list
   view's search still matches on `c.company LIKE ?`.

The test cleans up every row it inserts (test user, test project,
test contact, project_contacts link) so it is safe to re-run.

## Acceptance criteria — verified

| # | Criterion | Verified by |
|---|---|---|
| 1 | `DESCRIBE contacts;` lists `company`, `position`, `address` with the documented types | `contacts_columns_test.php` §1 |
| 2 | The original failing INSERT (`张总` / `YADEPAN18`) succeeds; row persists with `company=position=address=NULL` | `contacts_columns_test.php` §2 |
| 3 | The contact create form (browser) succeeds and redirects with the success flash | Manual; now working post-migration |
| 4 | The contact edit form persists changes to all three columns | `contacts_columns_test.php` §2 UPDATE phase |
| 5 | `contacts.php` search box does not error on `c.company LIKE ?` | `contacts_columns_test.php` §3 source-grep |
| 6 | Migration is idempotent: re-run leaves the table unchanged | `information_schema.COLUMNS` guard |
| 7 | Fresh `install.php` produces a `contacts` table with the three columns, no migration needed | `database/schema.sql` lists them |
| 8 | `Database::execute()` thrown message includes the underlying PDO error | Implemented in `Database.php`; visible in `contact_edit.php` `catch (Exception $e) { $errors[] = "Error saving contact: " . $e->getMessage(); }` |

## Out of scope (preserved from original spec)

- Adding any of the new fields to the client read-only dashboard.
- Splitting `address` into structured fields (street, city, country,
  zip). Today the field is one free-text textarea; keep it that way.
- Adding a `NOT NULL` constraint on `company` / `position` / `address`.
  These remain optional to match the form's current behaviour — only
  `name` is required.
- Backfilling `company`, `position`, or `address` from any other
  source. Existing rows stay `NULL`.

## Files changed (final state)

- `database/migrations/20261006_add_contacts_company_position_address.sql`
  — new (idempotent, three `information_schema`-guarded `ALTER`s).
- `database/schema.sql` — `contacts` table now lists the three columns
  in migration order.
- `includes/Database.php` — `execute()` and `query()` now concatenate
  the underlying `PDOException` message onto the thrown `Exception`
  message; previous `PDOException` attached as `$previous`.
- `database/contacts_columns_test.php` — new regression test, three
  layers (schema, round-trip, source-grep).

## Lessons for future schema-drift bugs

The same class of bug bit the project twice (issue-001: missing
`phone`; issue-009: missing `company` / `position` / `address`). Both
were "code references column X, table does not have column X" drift.
Three habits to keep this from happening a third time:

1. **Every form field that round-trips to the DB is a schema
   commitment.** If a column is not yet in `schema.sql`, add it (or
   the migration that adds it) in the same change set that adds the
   form field.
2. **Migrations are append-only.** Never edit a shipped migration
   in place; if it has a bug, add a follow-up migration that
   corrects the schema.
3. **Wrap new write surfaces in a regression test the day they are
   introduced.** The issue-009 test round-trips the same input the
   bug report used; that's the test pattern for any future
   "create-X fails with `Database execute failed`" bug.
