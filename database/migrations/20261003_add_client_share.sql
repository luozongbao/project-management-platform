-- 20261003_add_client_share.sql
-- Adds the client-sharing columns to the `projects` table:
--   scope         TEXT                — list of project requirements (one per line)
--   budget_amount DECIMAL(15,2) NULL  — budget in the chosen currency
--   currency      CHAR(3) NULL        — ISO-style 3-letter code; restricted by CHECK
--   share_code    CHAR(17) NULL UNIQUE — public 5-5-5 alpha-numeric code
--
-- Safe to re-run: each statement is idempotent (uses IF NOT EXISTS /
-- duplicate-column guards via information_schema). Run on an existing DB with:
--   docker compose exec -T db mariadb -u root -p"$MYSQL_ROOT_PASSWORD" pmp < database/migrations/20261003_add_client_share.sql
--
-- Note on MariaDB 10.11:
--   * DDL implicitly commits; do NOT wrap these statements in a transaction.
--   * `ADD CONSTRAINT ... CHECK` cannot be re-added if it already exists, hence the
--     information_schema guard at the bottom.

-- 1. scope
SET @col_exists := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'projects' AND COLUMN_NAME = 'scope'
);
SET @ddl := IF(@col_exists = 0,
    'ALTER TABLE projects ADD COLUMN scope TEXT NULL AFTER description',
    'SELECT 1');
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- 2. budget_amount
SET @col_exists := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'projects' AND COLUMN_NAME = 'budget_amount'
);
SET @ddl := IF(@col_exists = 0,
    'ALTER TABLE projects ADD COLUMN budget_amount DECIMAL(15,2) NULL AFTER scope',
    'SELECT 1');
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- 3. currency
SET @col_exists := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'projects' AND COLUMN_NAME = 'currency'
);
SET @ddl := IF(@col_exists = 0,
    "ALTER TABLE projects ADD COLUMN currency CHAR(3) NULL AFTER budget_amount",
    'SELECT 1');
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- 4. share_code (CHAR(17) to fit 5-5-5 + 2 dashes)
SET @col_exists := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'projects' AND COLUMN_NAME = 'share_code'
);
SET @ddl := IF(@col_exists = 0,
    'ALTER TABLE projects ADD COLUMN share_code CHAR(17) NULL AFTER currency',
    'SELECT 1');
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- 5. UNIQUE index on share_code (only if missing)
SET @idx_exists := (
    SELECT COUNT(*) FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'projects' AND INDEX_NAME = 'uq_projects_share_code'
);
SET @ddl := IF(@idx_exists = 0,
    'ALTER TABLE projects ADD UNIQUE KEY uq_projects_share_code (share_code)',
    'SELECT 1');
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- 6. CHECK constraint on currency (only if missing)
SET @chk_exists := (
    SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'projects'
      AND CONSTRAINT_NAME = 'chk_projects_currency'
);
SET @ddl := IF(@chk_exists = 0,
    "ALTER TABLE projects ADD CONSTRAINT chk_projects_currency CHECK (currency IS NULL OR currency IN ('THB','USD','CNY','JPY','SGD','EUR'))",
    'SELECT 1');
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- 7. Helper index on share_code lookups (only if missing)
SET @idx_exists := (
    SELECT COUNT(*) FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'projects' AND INDEX_NAME = 'idx_projects_share_code'
);
SET @ddl := IF(@idx_exists = 0,
    'ALTER TABLE projects ADD INDEX idx_projects_share_code (share_code)',
    'SELECT 1');
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;