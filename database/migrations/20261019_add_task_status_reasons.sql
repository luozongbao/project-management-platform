-- Adds two free-text "reason" columns to `tasks` so that the v1.8.0
-- backlog has a place to record *why* a task is on hold or delayed.
-- (issue-034)
--
-- Before this migration, the application could only set status='on_hold'
-- or surface "expected < CURDATE()" via the `.deadline.overdue` class on
-- the task list. There was no place to capture the human reason, which
-- meant the project manager had no defence against a vague
-- "task overdue, no narrative" situation at the standup, and the client
-- portal could not explain to the client *why* the work stopped.
--
-- Semantics:
--   * Both columns are TEXT NULL — the absence of a reason is
--     distinguishable from a literal empty string. (The validation in
--     task_edit.php / ajax/* requires a non-empty trimmed value when
--     the status demands one, and stores that trimmed value as-is.)
--   * Independent of `status` — the application enforces the
--     "status=on_hold implies on_hold_reason IS NOT NULL" invariant
--     on the way in. (See §5.1 / §5.2 / §5.3 of the spec; without that
--     enforcement the columns are just a free-form audit field that
--     does not have to agree with the status enum.)
--   * Independent of `expected_completion_date` — the "delayed" status
--     is computed by the application from the date + status columns, and
--     the `delay_reason` column is purely a free-text narrative.
--
-- Safe to re-run: idempotent via information_schema guard.
--   docker compose exec -T db mariadb -u"$DB_USER" -p"$DB_PASSWORD" "$DB_NAME" < database/migrations/20261019_add_task_status_reasons.sql
--
-- MariaDB DDL implicitly commits; do not wrap this migration in a
-- transaction.

SET @on_hold_reason_exists := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'tasks'
      AND COLUMN_NAME = 'on_hold_reason'
);
SET @ddl_on_hold := IF(@on_hold_reason_exists = 0,
    'ALTER TABLE tasks ADD COLUMN on_hold_reason TEXT NULL AFTER status',
    'SELECT 1');
PREPARE stmt FROM @ddl_on_hold; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @delay_reason_exists := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'tasks'
      AND COLUMN_NAME = 'delay_reason'
);
SET @ddl_delay := IF(@delay_reason_exists = 0,
    'ALTER TABLE tasks ADD COLUMN delay_reason TEXT NULL AFTER on_hold_reason',
    'SELECT 1');
PREPARE stmt FROM @ddl_delay; EXECUTE stmt; DEALLOCATE PREPARE stmt;
