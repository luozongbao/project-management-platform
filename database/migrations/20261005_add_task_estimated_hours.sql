-- Adds optional task effort estimates, stored canonically in hours.
-- Existing tasks remain unestimated (NULL); completion values are untouched.
-- Safe to re-run: idempotent via information_schema guard.
--   docker compose exec -T db mariadb -u"$DB_USER" -p"$DB_PASSWORD" "$DB_NAME" < database/migrations/20261005_add_task_estimated_hours.sql
--
-- MariaDB DDL implicitly commits; do not wrap this migration in a transaction.

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
