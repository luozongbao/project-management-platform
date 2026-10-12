-- Adds a per-task flag for "total time is the sum of sub-task estimates" (issue-011).
--
-- Before this column, a parent task's `estimated_hours` was either manually
-- maintained or left NULL. When a parent task is broken into sub-tasks whose
-- totals are already known, the manager wants the parent's effort rollup to
-- follow the sub-tasks automatically — both to avoid double bookkeeping and
-- to keep the project's weighted completion (issue-007) consistent with the
-- number a reader sees.
--
-- Semantics:
--   * TINYINT(1) NULL: the absence-of-decision (NULL) is distinguishable from
--     a literal "no, do not inherit". Existing rows default to NULL, which the
--     application treats as "manual mode" — the v1.2.1 behaviour.
--   * Independent of `estimated_hours`. The application forces the two to be
--     consistent on save (inherit = estimated_hours NULL, manual = filled),
--     but the schema does not enforce it; that gives us a clean rollback path.
--   * Independent of `parent_task_id`. The schema does not need to know that
--     a task has children; the application enforces the "checkbox only on
--     parents with children" rule on the way in.
--
-- Safe to re-run: idempotent via information_schema guard.
--   docker compose exec -T db mariadb -u"$DB_USER" -p"$DB_PASSWORD" "$DB_NAME" < database/migrations/20261012_add_task_inherit_subtask_hours.sql
--
-- MariaDB DDL implicitly commits; do not wrap this migration in a transaction.

SET @column_exists := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'tasks'
      AND COLUMN_NAME = 'inherit_subtask_hours'
);
SET @ddl := IF(@column_exists = 0,
    'ALTER TABLE tasks ADD COLUMN inherit_subtask_hours TINYINT(1) NULL DEFAULT NULL AFTER estimated_hours',
    'SELECT 1');
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;
