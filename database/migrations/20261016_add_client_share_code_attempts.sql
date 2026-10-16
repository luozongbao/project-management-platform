-- Migration: add client_share_code_attempts table for issue-023
-- (Client Share-Code Brute-Force Protection). Idempotent: safe to re-run.
--
-- The table records every public client-portal code attempt by client IP,
-- the submitted code, the matched project (NULL for unknown / malformed
-- codes), and the outcome. The application uses the history to enforce
-- a per-IP attempt cap and a per-IP distinct-codes cap.
--
-- Policy (issue-023):
--   1. Per-IP attempt cap: at most SHARE_CODE_IP_LIMIT attempts (default
--      20) within SHARE_CODE_IP_WINDOW seconds (default 5 min). The
--      threshold is higher than login.php's 5-in-15 because the client
--      portal is anonymous and a misbehaving corporate proxy may issue
--      many requests under the same IP.
--   2. Per-IP distinct-codes cap: at most SHARE_CODE_DISTINCT_LIMIT
--      DISTINCT failed codes from the same IP in the window. Defends
--      against the "I have a list of leaked codes and want to validate
--      them all" attack.
--
-- `code_attempted` is the SUBMITTED value (not a projects FK) so the
-- application can record malformed inputs (regex failures, typos) and
-- the brute-force history of any one code. `project_id` is set to NULL
-- for unknown / malformed codes.
--
-- The ON DELETE CASCADE on project_id is intentionally absent: attempts
-- against a deleted project are useful forensic data. The cleanup path
-- is a periodic TRUNCATE / DELETE based on `attempted_at` age.

CREATE TABLE IF NOT EXISTS client_share_code_attempts (
    id INT PRIMARY KEY AUTO_INCREMENT,
    ip_address VARCHAR(45) NOT NULL,         -- IPv6-safe
    code_attempted VARCHAR(20) NOT NULL,     -- the submitted code, not a FK
    project_id INT NULL,                     -- NULL for unknown / malformed codes
    success TINYINT(1) NOT NULL DEFAULT 0,
    attempted_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_csca_ip_time (ip_address, attempted_at),
    INDEX idx_csca_project_time (project_id, attempted_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
