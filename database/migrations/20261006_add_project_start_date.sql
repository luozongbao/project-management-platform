-- Adds an optional, user-settable Project Start Date (issue-008).
--
-- Until now the client dashboard rendered `Started` from projects.created_at,
-- which conflates the audit timestamp ("when the row was inserted") with a
-- planning concept ("when work on the project actually began"). This column
-- lets project managers capture the real start date independently of when the
-- project shell was created.
--
-- Semantics:
--   * DATE (not DATETIME) — consistent with expected_completion_date and
--     completion_date.
--   * NULL means "start date unknown / not recorded". Existing rows stay NULL.
--   * No default. Today is rarely the correct start date; back-filling would
--     silently invent data.
--
-- The application surfaces a non-blocking warning if start_date is set later
-- than expected_completion_date or completion_date, so this DDL stays permissive.
--
-- Safe to re-run: idempotent via information_schema guard.
--   docker compose exec -T db mariadb -u"$DB_USER" -p"$DB_PASSWORD" "$DB_NAME" < database/migrations/20261006_add_project_start_date.sql
--
-- MariaDB DDL implicitly commits; do not wrap this migration in a transaction.

SET @column_exists := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'projects'
      AND COLUMN_NAME = 'start_date'
);
SET @ddl := IF(@column_exists = 0,
    'ALTER TABLE projects ADD COLUMN start_date DATE NULL AFTER expected_completion_date',
    'SELECT 1');
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;