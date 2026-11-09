---
description: "Use when writing a SQL migration in database/migrations/, editing database/schema.sql, or changing the PDO wrapper in includes/Database.php. Covers the idempotency guard pattern, the four-step schema-change checklist, the three DB initialisation paths, and the PDO pitfalls specific to this project."
applyTo: ["database/migrations/**", "database/schema.sql", "includes/Database.php"]
---

# Database conventions

MariaDB 10.11, utf8mb4, accessed through the PDO singleton in `includes/Database.php`.

## `Database` API

| Method | Returns |
|---|---|
| `query($sql, $params = [])` | `PDOStatement` |
| `fetchAll($sql, $params = [])` | array of assoc rows |
| `fetchOne($sql, $params = [])` | one assoc row or `false` |
| `execute($sql, $params = [])` | **bool — not a row count** |
| `lastInsertId()` | int |
| `getConnection()` | the raw `PDO` handle |

Connection settings: `ERRMODE_EXCEPTION`, `FETCH_ASSOC`, **`EMULATE_PREPARES = false`**, and
`SET time_zone = '+00:00'` — the DB stores UTC, so convert in PHP with `toUTC()` /
`formatDateTime()`.

## Migration pattern

Every file in `database/migrations/` follows the same shape:

1. A header comment stating the purpose, the issue reference, and the **exact run command**.
2. An idempotency guard — the file must be safe to re-run:

   ````sql
   SET @column_exists := (
       SELECT COUNT(*) FROM information_schema.COLUMNS
       WHERE TABLE_SCHEMA = DATABASE()
         AND TABLE_NAME = 'tasks'
         AND COLUMN_NAME = 'estimated_hours'
   );
   SET @ddl := IF(@column_exists = 0,
       'ALTER TABLE tasks ADD COLUMN estimated_hours DECIMAL(12,4) NULL AFTER completion_date',
       'SELECT 1');
   PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;
   ````

3. **No `START TRANSACTION`** — MariaDB DDL implicitly commits. Say so in the header.
4. Backfills are deterministic (`ORDER BY id`) so a re-run reproduces identical values.
5. Nullable-first, `NOT NULL` later, when a backfill is required.

## Schema-change checklist

Follow all four steps, in order:

1. Add the migration in `database/migrations/YYYYMMDD_short_name.sql`.
2. **Mirror the column in `database/schema.sql`** — fresh installs read that file.
3. Add a DB-backed regression test that seeds via `Database::execute()` and cleans up in
   `try { } finally { }`.
4. Update column-specific tests only if you are renaming or dropping.

## Three DB initialisation paths — know which one you are in

| Path | When it runs | Target |
|---|---|---|
| `database/schema.sql` mounted at `/docker-entrypoint-initdb.d/01-schema.sql` | Fresh volume only (`docker compose down -v` re-seeds) | **Dev only** |
| `install.php` step 2 (`CREATE DATABASE IF NOT EXISTS` + loads `schema.sql` via `$pdo->exec()`) | The web install wizard | **Universal** — this is the production path |
| `database/migrations/*.sql` applied by hand | Every upgrade — **not automatic** | Both |

`docker/entrypoint.sh` does **not** create or seed the database.

A schema change must work on **all** paths: a fresh Docker volume, a fresh
`install.php` wizard run, and a hand-applied migration on an existing prod database.
`database/schema.sql` is the single source of truth for the first two; the migration file
covers the third.

## PDO pitfalls

- `execute()` returns **bool**. Use `$db->execute(...)` then `(int)$db->lastInsertId()`.
- `EMULATE_PREPARES = false` means **native prepares** → **one statement per call**. An
  `INSERT …; SELECT LAST_INSERT_ID();` pair silently fails.
- To apply a **multi-statement** migration file from PHP, `Database::execute()` will not work.
  Either split on `;` (breaks the `SET @x := …; PREPARE …; EXECUTE` idiom) or reach the raw
  PDO handle by reflection and call `exec()`.
- The only string interpolation into SQL is a hard-coded table allow-list
  (`projects`, `tasks`, `contacts`).
- Enum values are exact: `projects.status` and `tasks.status` are
  `('not_started','in_progress','completed','on_hold')`. There is no `'active'`.

## Schema facts worth not re-deriving

- `users` — `username` and `email` are `NOT NULL UNIQUE`.
- `projects.responsible_person_id → users.id` — **this is the entire authorization model**.
- `tasks.project_id → projects.id` `ON DELETE CASCADE`; `tasks.parent_task_id → tasks.id`
  `ON DELETE CASCADE` (the sub-task tree).
- `tasks.contact_person_id → contacts.id` is nullable but the FK is `RESTRICT` — clear task
  assignments before deleting a contact.
- `project_contacts` is the N:M junction between projects and contacts.
- `project_stats` and `task_hierarchy` are **VIEWs** — not updatable.
- There are **no triggers**. Parent completion rollups are computed in PHP by
  `calculateTaskCompletionMap()` / `syncProjectTaskCompletions()`;
  `database/subtask_functions.sql.disabled` is historical reference only.

## Slugs

`slugify()` in `includes/slug.php` and the SQL transliteration map in
`database/migrations/20261022_add_slugs.sql` must stay **byte-identical** — both sides use an
explicit transliteration map rather than `iconv()`, which is locale-dependent.
`database/slug_routing_test.php` asserts this. Slugs are **immutable**: assigned once on
insert, never regenerated on rename.
