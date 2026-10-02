-- 20261003_add_contacts_phone.sql
-- Adds the missing `phone` column to the `contacts` table.
--
-- Background: docs/issues/issue-001.md — task_detail.php crashed with
--   "Unknown column 'c.phone' in 'SELECT'" because `contacts` was missing
--   the `phone` column. Several PHP files already SELECT from / INSERT into
--   `phone` (task_detail.php, contacts.php, contact_edit.php, contact_detail.php,
--   ajax/get_project_contacts.php) and the contact-edit form already binds
--   `$_POST['phone']`. The DB just never had the column.
--
-- Decision: ADD `phone` rather than rename `mobile` -> `phone`, because the
-- form distinguishes the two fields ("Phone" = landline, "Mobile" = cell)
-- and the developer clearly intended both.
--
-- Safe to re-run: idempotent via information_schema guard.
--   docker compose exec -T db mariadb -u"$DB_USER" -p"$DB_PASSWORD" "$DB_NAME" < database/migrations/20261003_add_contacts_phone.sql
--
-- Note on MariaDB 10.11: DDL implicitly commits; do NOT wrap in a transaction.

-- 1. phone
SET @col_exists := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'contacts' AND COLUMN_NAME = 'phone'
);
SET @ddl := IF(@col_exists = 0,
    'ALTER TABLE contacts ADD COLUMN phone VARCHAR(20) NULL AFTER email',
    'SELECT 1');
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;