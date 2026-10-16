-- Migration: add share_code_rotated_at column to projects for issue-023
-- (Client Share-Code Brute-Force Protection). Idempotent: safe to re-run.
--
-- `share_code_rotated_at` is the wall-clock time the project's current
-- share code was last rotated. A NULL value means "never rotated" (or
-- "no share code"). The column lets:
--
--   - The manager UI show "Last rotated: N days ago" so a stale code
--     is visible at a glance.
--   - The "Rotate code" action set the new value to NOW() in the same
--     transaction as the share_code UPDATE.
--   - A future v2.0 signed-URL feature issue a code with an
--     `exp = share_code_rotated_at + 30 days` window.
--
-- The information_schema guard makes the column add idempotent. The
-- backfill UPDATE is also idempotent: a second pass sets the same
-- value to the same NOW() so the column is populated for every row
-- that has a non-null share_code but a NULL rotated_at. Rows with
-- no share_code (older projects) get NULL, which is the correct
-- "not applicable" state.

SET @col := (SELECT COUNT(*) FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = 'projects'
               AND COLUMN_NAME = 'share_code_rotated_at');
SET @sql := IF(@col = 0,
    'ALTER TABLE projects
     ADD COLUMN share_code_rotated_at TIMESTAMP NULL DEFAULT NULL
         AFTER share_code',
    'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- Backfill: every project with a non-null share_code gets NOW() as
-- its rotated_at. The WHERE clause is intentionally narrow so a
-- re-run is a no-op (a row that already has a value is left alone).
UPDATE projects
SET share_code_rotated_at = NOW()
WHERE share_code_rotated_at IS NULL
  AND share_code IS NOT NULL;
