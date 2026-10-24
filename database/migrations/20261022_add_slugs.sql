-- 20261022_add_slugs.sql
-- issue-035 v2.0 / Appendix C — adds the `slug` column to `projects`,
-- `tasks` and `contacts` so v2.0 pages can be addressed by slug.
--
-- WHY a stored column and not a derived one (Appendix C.2 R15/R17):
--   A slug derived from `name` at render time would change the moment the
--   name is edited, 404-ing every bookmark and every URL already sent to a
--   client. The slug is assigned ONCE (here, at insert time) and never
--   regenerated (C.5: "Record name edited → slug is NOT regenerated").
--
-- BYTE-IDENTICAL SLUGS (C.4):
--   The backfill below MUST produce exactly the same bytes as
--   `slugify()` in includes/functions.php, otherwise existing rows and
--   newly-inserted rows would disagree. That rules out iconv:
--   `iconv('UTF-8','ASCII//TRANSLIT', …)` is glibc/locale-dependent
--   (verified on this stack: 'Café' → "Caf\'e", 'naïve' → 'na"ive', and it
--   returns FALSE for CJK input), so it can never be mirrored in SQL.
--   Instead both sides use the SAME explicit Latin transliteration map
--   (see slugify_translit_map() in includes/functions.php and
--   `slug_translit` below), then the same collapse/trim/cap pipeline.
--   T4/T6 in database/slug_routing_test.php assert the two agree.
--
-- DETERMINISM / IDEMPOTENCY (C.2 "Schema changes this appendix requires"):
--   Rows are walked in `ORDER BY id` and the `-2`/`-3` suffix counter is
--   compared against slugs already assigned *earlier in that same ordered
--   walk*, so re-running the migration reproduces the same slugs instead
--   of renumbering them. Re-running is a no-op because the backfill is
--   guarded by `slug IS NULL` (see below).
--
-- Note on MariaDB 10.11:
--   * DDL implicitly commits; do NOT wrap these statements in a transaction.
--   * A stored procedure cannot be created inside a prepared statement, so
--     the backfill uses one DELIMITER-based procedure per table and DROPs it.
--   * REGEXP_REPLACE must run on a utf8mb4_bin collation: with the default
--     utf8mb4_unicode_ci (case-insensitive) collation the pattern '[^a-z0-9]'
--     does not match upper-case ASCII, so "North Riverside" would not be
--     lowercased. We LOWER() first and compare case-sensitively.
--
-- Run on an existing DB with:
--   docker compose exec -T db mariadb -u root -p"$MYSQL_ROOT_PASSWORD" pmp \
--     --default-character-set=utf8mb4 < database/migrations/20261022_add_slugs.sql

-- ===========================================================================
-- 1. Add the three nullable columns (step 1 of the required order).
--    Nullable first so the backfill can run before NOT NULL is enforced.
-- ===========================================================================

SET @col_exists := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'projects' AND COLUMN_NAME = 'slug'
);
SET @ddl := IF(@col_exists = 0,
    'ALTER TABLE projects ADD COLUMN slug VARCHAR(80) NULL AFTER name',
    'SELECT 1');
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col_exists := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tasks' AND COLUMN_NAME = 'slug'
);
SET @ddl := IF(@col_exists = 0,
    'ALTER TABLE tasks ADD COLUMN slug VARCHAR(80) NULL AFTER name',
    'SELECT 1');
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col_exists := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'contacts' AND COLUMN_NAME = 'slug'
);
SET @ddl := IF(@col_exists = 0,
    'ALTER TABLE contacts ADD COLUMN slug VARCHAR(80) NULL AFTER name',
    'SELECT 1');
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ===========================================================================
-- 2. Build slug_translit / slug_base — the SQL mirror of slugify() in
--    includes/functions.php. Created once, reused by all three backfills,
--    dropped at the end of this script.
--
--    Order mirrors C.4 exactly:
--      (a) LOWER() the name
--      (b) transliterate via the explicit map
--      (c) replace every run of non-[a-z0-9] with a single '-'
--      (d) trim leading/trailing '-'
--      (e) truncate to 60 chars, then trim a trailing '-' again
--    The `item-<id>` empty fallback (C.4 step 6) is applied by the caller
--    because it needs the row id, which the function does not see.
-- ===========================================================================

DROP FUNCTION IF EXISTS slug_base;
DELIMITER $$
CREATE FUNCTION slug_base(p_name VARCHAR(255))
RETURNS VARCHAR(80)
DETERMINISTIC
BEGIN
    DECLARE v VARCHAR(255);
    SET v = LOWER(COALESCE(p_name, ''));
    SET v = REPLACE(v, 'à', 'a');  SET v = REPLACE(v, 'á', 'a');  SET v = REPLACE(v, 'â', 'a');
    SET v = REPLACE(v, 'ã', 'a');  SET v = REPLACE(v, 'ä', 'a');  SET v = REPLACE(v, 'å', 'a');
    SET v = REPLACE(v, 'æ', 'ae');
    SET v = REPLACE(v, 'ç', 'c');
    SET v = REPLACE(v, 'è', 'e');  SET v = REPLACE(v, 'é', 'e');  SET v = REPLACE(v, 'ê', 'e');
    SET v = REPLACE(v, 'ë', 'e');
    SET v = REPLACE(v, 'ì', 'i');  SET v = REPLACE(v, 'í', 'i');  SET v = REPLACE(v, 'î', 'i');
    SET v = REPLACE(v, 'ï', 'i');
    SET v = REPLACE(v, 'ð', 'd');
    SET v = REPLACE(v, 'ñ', 'n');
    SET v = REPLACE(v, 'ò', 'o');  SET v = REPLACE(v, 'ó', 'o');  SET v = REPLACE(v, 'ô', 'o');
    SET v = REPLACE(v, 'õ', 'o');  SET v = REPLACE(v, 'ö', 'o');  SET v = REPLACE(v, 'ø', 'o');
    SET v = REPLACE(v, 'œ', 'oe');
    SET v = REPLACE(v, 'ù', 'u');  SET v = REPLACE(v, 'ú', 'u');  SET v = REPLACE(v, 'û', 'u');
    SET v = REPLACE(v, 'ü', 'u');
    SET v = REPLACE(v, 'ý', 'y');  SET v = REPLACE(v, 'þ', 'th'); SET v = REPLACE(v, 'ÿ', 'y');
    SET v = REPLACE(v, 'ß', 'ss');
    SET v = REPLACE(v, 'ł', 'l');  SET v = REPLACE(v, 'đ', 'd');  SET v = REPLACE(v, 'ħ', 'h');
    SET v = REPLACE(v, 'ĳ', 'ij');
    -- (c) collapse every run of characters outside [a-z0-9] into one '-'.
    --     utf8mb4_bin makes the class case-SENSITIVE, which is what we want
    --     because LOWER() already ran.
    SET v = REGEXP_REPLACE(v COLLATE utf8mb4_bin, '[^a-z0-9]+', '-');
    -- (d) trim both ends
    SET v = TRIM(BOTH '-' FROM v);
    -- (e) cap at 60 chars, then drop a trailing '-' the cut may have exposed
    SET v = LEFT(v, 60);
    SET v = TRIM(BOTH '-' FROM v);
    RETURN v;
END$$
DELIMITER ;

-- ===========================================================================
-- 3. Backfill, one table at a time, in `ORDER BY id`.
--    The suffix counter only ever sees slugs already written by THIS run
--    (or by a previous run), so the result is stable across re-runs (C.2).
-- ===========================================================================

-- ---- projects -------------------------------------------------------------
DROP PROCEDURE IF EXISTS backfill_project_slugs;
DELIMITER $$
CREATE PROCEDURE backfill_project_slugs()
BEGIN
    DECLARE v_done INT DEFAULT 0;
    DECLARE v_id INT;
    DECLARE v_name VARCHAR(255);
    DECLARE v_base VARCHAR(80);
    DECLARE v_slug VARCHAR(80);
    DECLARE v_k INT;
    DECLARE cur CURSOR FOR
        SELECT id, name FROM projects WHERE slug IS NULL OR slug = '' ORDER BY id;
    DECLARE CONTINUE HANDLER FOR NOT FOUND SET v_done = 1;

    OPEN cur;
    slug_loop: LOOP
        FETCH cur INTO v_id, v_name;
        IF v_done = 1 THEN
            LEAVE slug_loop;
        END IF;

        SET v_base = slug_base(v_name);
        -- C.4 step 6: a name made entirely of symbols has no slug.
        IF v_base = '' THEN
            SET v_base = CONCAT('item-', v_id);
        END IF;

        SET v_slug = v_base;
        SET v_k = 2;
        WHILE EXISTS (SELECT 1 FROM (SELECT slug FROM projects) AS t WHERE t.slug = v_slug) DO
            SET v_slug = CONCAT(v_base, '-', v_k);
            SET v_k = v_k + 1;
        END WHILE;

        UPDATE projects SET slug = v_slug WHERE id = v_id;
    END LOOP;
    CLOSE cur;
END$$
DELIMITER ;
CALL backfill_project_slugs();
DROP PROCEDURE IF EXISTS backfill_project_slugs;

-- ---- tasks ----------------------------------------------------------------
DROP PROCEDURE IF EXISTS backfill_task_slugs;
DELIMITER $$
CREATE PROCEDURE backfill_task_slugs()
BEGIN
    DECLARE v_done INT DEFAULT 0;
    DECLARE v_id INT;
    DECLARE v_name VARCHAR(255);
    DECLARE v_base VARCHAR(80);
    DECLARE v_slug VARCHAR(80);
    DECLARE v_k INT;
    DECLARE cur CURSOR FOR
        SELECT id, name FROM tasks WHERE slug IS NULL OR slug = '' ORDER BY id;
    DECLARE CONTINUE HANDLER FOR NOT FOUND SET v_done = 1;

    OPEN cur;
    slug_loop: LOOP
        FETCH cur INTO v_id, v_name;
        IF v_done = 1 THEN
            LEAVE slug_loop;
        END IF;

        SET v_base = slug_base(v_name);
        IF v_base = '' THEN
            SET v_base = CONCAT('item-', v_id);
        END IF;

        SET v_slug = v_base;
        SET v_k = 2;
        WHILE EXISTS (SELECT 1 FROM (SELECT slug FROM tasks) AS t WHERE t.slug = v_slug) DO
            SET v_slug = CONCAT(v_base, '-', v_k);
            SET v_k = v_k + 1;
        END WHILE;

        UPDATE tasks SET slug = v_slug WHERE id = v_id;
    END LOOP;
    CLOSE cur;
END$$
DELIMITER ;
CALL backfill_task_slugs();
DROP PROCEDURE IF EXISTS backfill_task_slugs;

-- ---- contacts -------------------------------------------------------------
DROP PROCEDURE IF EXISTS backfill_contact_slugs;
DELIMITER $$
CREATE PROCEDURE backfill_contact_slugs()
BEGIN
    DECLARE v_done INT DEFAULT 0;
    DECLARE v_id INT;
    DECLARE v_name VARCHAR(255);
    DECLARE v_base VARCHAR(80);
    DECLARE v_slug VARCHAR(80);
    DECLARE v_k INT;
    DECLARE cur CURSOR FOR
        SELECT id, name FROM contacts WHERE slug IS NULL OR slug = '' ORDER BY id;
    DECLARE CONTINUE HANDLER FOR NOT FOUND SET v_done = 1;

    OPEN cur;
    slug_loop: LOOP
        FETCH cur INTO v_id, v_name;
        IF v_done = 1 THEN
            LEAVE slug_loop;
        END IF;

        SET v_base = slug_base(v_name);
        IF v_base = '' THEN
            SET v_base = CONCAT('item-', v_id);
        END IF;

        SET v_slug = v_base;
        SET v_k = 2;
        WHILE EXISTS (SELECT 1 FROM (SELECT slug FROM contacts) AS t WHERE t.slug = v_slug) DO
            SET v_slug = CONCAT(v_base, '-', v_k);
            SET v_k = v_k + 1;
        END WHILE;

        UPDATE contacts SET slug = v_slug WHERE id = v_id;
    END LOOP;
    CLOSE cur;
END$$
DELIMITER ;
CALL backfill_contact_slugs();
DROP PROCEDURE IF EXISTS backfill_contact_slugs;

DROP FUNCTION IF EXISTS slug_base;

-- ===========================================================================
-- 4. Add the UNIQUE indexes (step 3 of the required order).
-- ===========================================================================

SET @idx_exists := (
    SELECT COUNT(*) FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'projects' AND INDEX_NAME = 'uq_projects_slug'
);
SET @ddl := IF(@idx_exists = 0,
    'ALTER TABLE projects ADD UNIQUE KEY uq_projects_slug (slug)',
    'SELECT 1');
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @idx_exists := (
    SELECT COUNT(*) FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tasks' AND INDEX_NAME = 'uq_tasks_slug'
);
SET @ddl := IF(@idx_exists = 0,
    'ALTER TABLE tasks ADD UNIQUE KEY uq_tasks_slug (slug)',
    'SELECT 1');
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @idx_exists := (
    SELECT COUNT(*) FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'contacts' AND INDEX_NAME = 'uq_contacts_slug'
);
SET @ddl := IF(@idx_exists = 0,
    'ALTER TABLE contacts ADD UNIQUE KEY uq_contacts_slug (slug)',
    'SELECT 1');
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ===========================================================================
-- 5. Convert to NOT NULL (step 4 of the required order).
--    Guarded so a re-run after the column is already NOT NULL is a no-op.
-- ===========================================================================

SET @is_nullable := (
    SELECT IS_NULLABLE FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'projects' AND COLUMN_NAME = 'slug'
);
SET @ddl := IF(@is_nullable = 'YES',
    'ALTER TABLE projects MODIFY COLUMN slug VARCHAR(80) NOT NULL',
    'SELECT 1');
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @is_nullable := (
    SELECT IS_NULLABLE FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tasks' AND COLUMN_NAME = 'slug'
);
SET @ddl := IF(@is_nullable = 'YES',
    'ALTER TABLE tasks MODIFY COLUMN slug VARCHAR(80) NOT NULL',
    'SELECT 1');
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @is_nullable := (
    SELECT IS_NULLABLE FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'contacts' AND COLUMN_NAME = 'slug'
);
SET @ddl := IF(@is_nullable = 'YES',
    'ALTER TABLE contacts MODIFY COLUMN slug VARCHAR(80) NOT NULL',
    'SELECT 1');
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;