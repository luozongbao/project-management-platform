# Contact Create/Edit Fails Because `contacts` Is Missing `company`, `position`, `address`

**Status:** Open
**Type:** Bug — schema/code drift (same class as issue-001)
**Priority:** High — every contact create/edit fails until the schema catches up
**Affected surface:** `contact_edit.php` (create + edit), `contact_detail.php`, `contacts.php`, `ajax/get_project_contacts.php` (search box already references `c.company`)

## Bug report (verbatim)

> When I create a new contact with Contact Name `张总` and WeChat ID `YADEPAN18`
> and tick one project's "Assign to Project" box, the form returns
> **"Error saving contact: Database execute failed"** and no contact is
> saved. Only the Contact Name field is required; all other fields are
> optional.

The form submits successfully and validation passes (Contact Name is
non-empty, the WeChat ID is just a string, the project checkbox posts
its value), so the failure is on the database write — not on input
validation.

## Root cause

`contact_edit.php` reads `$_POST['company']`, `$_POST['position']`, and
`$_POST['address']`, then binds them to both the create `INSERT INTO
contacts (...)` and the edit `UPDATE contacts SET ...`:

```php
// contact_edit.php (create path)
$db->execute(
    "INSERT INTO contacts (
        name, description, email, phone, mobile, company, position, address,
        wechat, line_id, facebook, linkedin, created_at, updated_at
     ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())",
    [
        $name, $description, $email, $phone, $mobile, $company, $position, $address,
        $wechat, $line_id, $facebook, $linkedin
    ]
);
```

But the actual `contacts` table shipped by `database/schema.sql` (and the
table in the running database) has **only**:

```
id, name, description, mobile, email, phone, wechat, line_id,
facebook, linkedin, created_at, updated_at
```

`company`, `position`, and `address` are **not present**. So every
prepared `INSERT`/`UPDATE` against `contacts` fails with MariaDB error
`1054 (42S22): Unknown column 'company' in 'INSERT INTO'`. The
`Database::execute()` wrapper catches the `PDOException` and rethrows it
with the generic message `"Database execute failed"`, which is what
surfaces to the form:

```
Error saving contact: Database execute failed
```

The user's specific input (`张总` / `YADEPAN18`) does not matter — the
failure happens regardless of which optional fields are filled in,
because `company`, `position`, and `address` are bound unconditionally.

### Reproduction (live DB)

```bash
docker compose exec -T db mariadb -udbuser -p"$DB_PASSWORD" "$DB_NAME" \
    --default-character-set=utf8mb4 -e "
INSERT INTO contacts
  (name, description, email, phone, mobile, company, position, address,
   wechat, line_id, facebook, linkedin, created_at, updated_at)
VALUES
  ('张总', '', NULL, NULL, NULL, NULL, NULL, NULL, 'YADEPAN18',
   NULL, NULL, NULL, NOW(), NOW());"
```

returns:

```
ERROR 1054 (42S22) at line 2: Unknown column 'company' in 'INSERT INTO'
```

which matches the wrapped "Database execute failed" message the user
sees in the browser.

### Schema drift history

The same drift class was already documented and fixed for `phone` in
[issue-001](./issue-001.md) via migration
`20261003_add_contacts_phone.sql`. That fix added the missing
`contacts.phone` column but did **not** add `company`, `position`, or
`address`. Meanwhile, the contact form (`contact_edit.php` ~lines 233,
234, 235 and ~244–247), the read view (`contact_detail.php` ~lines
126–130 and the contact card section), and the list/search
(`contacts.php` line 24: `c.company LIKE ?`) all reference these fields.
Three pieces of code, no schema, no migration.

## Goals

- Make every contact create / edit submission actually persist by
  bringing the `contacts` table into agreement with the code.
- Add the missing columns in a way that is safe on databases that
  already have contact rows (existing data must not be lost; new columns
  default to `NULL`).
- Match the column types the form already implies:
  - `company` — `VARCHAR(255) NULL`
  - `position` — `VARCHAR(255) NULL`
  - `address` — `TEXT NULL` (the field is rendered as a multi-line
    textarea; the existing `description` column is `TEXT`).
- Update `database/schema.sql` so fresh installs match the migrated
  shape; otherwise the next person who reinstalls hits the same bug.
- Surface the underlying PDO error in dev/logs (do not silently keep
  the generic wrapper message) so the next drift bug is obvious.

## Proposed migration

New file: `database/migrations/20261006_add_contacts_company_position_address.sql`.

Modeled on `20261003_add_contacts_phone.sql` — guarded with
`information_schema.COLUMNS` so the migration is idempotent and safe
to re-run.

```sql
-- 20261006_add_contacts_company_position_address.sql
--
-- Adds the missing company / position / address columns to contacts.
--
-- Background: docs/issues/issue-009.md. The contact form
-- (contact_edit.php) and read views (contact_detail.php, contacts.php)
-- already reference these fields and bind them in the INSERT/UPDATE.
-- The original schema shipped without them, and no migration has
-- added them. The form fails on every create / edit with
-- "Database execute failed" because MariaDB rejects the
-- INSERT/UPDATE with "Unknown column 'company' in 'INSERT INTO'".
--
-- Safe to re-run: each ALTER is guarded by an information_schema check.
-- MariaDB DDL implicitly commits; do NOT wrap this migration in a
-- transaction.

-- 1. company
SET @col_exists := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'contacts' AND COLUMN_NAME = 'company'
);
SET @ddl := IF(@col_exists = 0,
    'ALTER TABLE contacts ADD COLUMN company VARCHAR(255) NULL AFTER phone',
    'SELECT 1');
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- 2. position
SET @col_exists := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'contacts' AND COLUMN_NAME = 'position'
);
SET @ddl := IF(@col_exists = 0,
    'ALTER TABLE contacts ADD COLUMN position VARCHAR(255) NULL AFTER company',
    'SELECT 1');
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- 3. address
SET @col_exists := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'contacts' AND COLUMN_NAME = 'address'
);
SET @ddl := IF(@col_exists = 0,
    'ALTER TABLE contacts ADD COLUMN address TEXT NULL AFTER description',
    'SELECT 1');
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;
```

## Code changes

### `database/schema.sql`

Add the three columns to the `CREATE TABLE contacts (...)` definition
(in the same order the migration adds them) so fresh installs and
existing databases end up with identical schemas:

```sql
CREATE TABLE contacts (
    id INT PRIMARY KEY AUTO_INCREMENT,
    name VARCHAR(255) NOT NULL,
    description TEXT,
    address TEXT,                          -- new
    mobile VARCHAR(20),
    phone VARCHAR(20),
    company VARCHAR(255),                  -- new
    position VARCHAR(255),                 -- new
    email VARCHAR(255),
    wechat VARCHAR(100),
    line_id VARCHAR(100),
    facebook VARCHAR(255),
    linkedin VARCHAR(255),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);
```

### `Database::execute()` (better error visibility)

Right now `Database::execute()` rethrows every `PDOException` with the
generic message `"Database execute failed"` and only logs the original
to `error_log`. That is fine for production but terrible for the
developer experience — both for issue-001 and this one, the underlying
"Unknown column …" never surfaces. Add the original `PDOException`
message to the thrown exception's message (and keep `error_log` as
well), so the catch-block in `contact_edit.php` can show something
useful:

```php
public function execute($sql, $params = []) {
    try {
        $stmt = $this->pdo->prepare($sql);
        return $stmt->execute($params);
    } catch (PDOException $e) {
        error_log("Database execute failed: " . $e->getMessage());
        throw new Exception("Database execute failed: " . $e->getMessage(), 0, $e);
    }
}
```

Apply the same treatment to `Database::query()` for consistency.

This is a small, low-risk change — the wrapper still throws a generic
`Exception`, callers still see `getMessage()` start with `"Database
execute failed"`, but the developer-facing message now includes the
real reason.

## Acceptance criteria

- After running the new migration, `DESCRIBE contacts;` lists
  `company VARCHAR(255) NULL`, `position VARCHAR(255) NULL`,
  `address TEXT NULL`, in addition to the existing columns.
- The original failing INSERT (reproduced above) succeeds with the
  same input set, and the row appears in the `contacts` table with
  `name = '张总'`, `wechat = 'YADEPAN18'`, `company = NULL`,
  `position = NULL`, `address = NULL`.
- Submitting the contact create form in the browser (Name `张总`,
  WeChat `YADEPAN18`, one project checked) succeeds and redirects to
  the contact detail page with the green "Contact created successfully!"
  flash.
- Submitting the contact edit form for an existing contact succeeds
  and persists changes to all three new columns.
- `contacts.php` search box — which already includes `c.company LIKE ?`
  — does not error out when the user types in the search field.
- The migration file is idempotent: running it twice in a row leaves
  the table unchanged.
- `database/schema.sql` lists the three new columns, so a fresh
  `install.php` run no longer needs the migration.
- After this fix, the `Database::execute()` thrown message includes
  the underlying PDO error (e.g. starts with
  `"Database execute failed: Unknown column 'contacts.company' …"`), so
  the next schema-drift bug is visible without tailing PHP error logs.

## Regression test

Add an automated check (under `database/` or a dedicated
`tests/contact_write_test.php`) that:

1. Connects to the configured DB.
2. Inserts a contact with name `张总`, wechat `YADEPAN18`, one project
   assignment, and no other optional fields.
3. Reads it back and asserts `name`, `wechat`, the linked
   `project_contacts` row, and the NULL state of `company` /
   `position` / `address`.
4. Updates that contact to set `company`, `position`, `address` and
   re-reads to confirm they persisted.
5. Cleans up the inserted rows.

This test would have caught issue-001 and issue-009 the day they were
introduced.

## Out of scope

- Adding any of the new fields to the client read-only dashboard.
- Splitting `address` into structured fields (street, city, country,
  zip). Today the field is one free-text textarea; keep it that way.
- Adding a NOT NULL constraint on `company` / `position` / `address`.
  These remain optional to match the form's current behavior — only
  `name` is required.
- Backfilling `company`, `position`, or `address` from any other
  source. Existing rows stay `NULL`.