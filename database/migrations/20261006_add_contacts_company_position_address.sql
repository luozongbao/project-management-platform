-- 20261006_add_contacts_company_position_address.sql
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
-- Same drift class as issue-001 (which added `phone` via
-- 20261003_add_contacts_phone.sql); that fix did not cover these
-- three fields.
--
-- Column choices:
--   company   VARCHAR(255) NULL   -- single-line free text
--   position  VARCHAR(255) NULL   -- single-line free text
--   address   TEXT NULL           -- multi-line textarea in the form
--
-- Safe to re-run: each ALTER is guarded by an information_schema check.
--   docker compose exec -T db mariadb -u"$DB_USER" -p"$DB_PASSWORD" "$DB_NAME" \
--     < database/migrations/20261006_add_contacts_company_position_address.sql
--
-- Note on MariaDB 10.11: DDL implicitly commits; do NOT wrap this migration
-- in a transaction.

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