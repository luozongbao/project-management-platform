-- Migration: add login_attempts table for issue-019 (login & registration
-- brute-force / enumeration protection). Idempotent: safe to re-run.
--
-- The table records every login attempt by client IP, submitted username/email,
-- and outcome (success or failure). The application enforces two thresholds:
--
--   1. Per-(IP, username_attempted): at most LOGIN_FAILURE_LIMIT failed attempts
--      within LOGIN_FAILURE_WINDOW seconds. After that, the user is soft-locked
--      from the page renders the same "Invalid username or password" message.
--   2. Per-IP across all usernames: at most LOGIN_IP_LIMIT failed attempts
--      within LOGIN_IP_WINDOW seconds. Defends against a distributed dictionary
--      attack that rotates usernames.
--
-- `username_attempted` is the SUBMITTED value (not a users FK) so a credential-
-- stuffing attack that rotates usernames is fully recorded, and a successful
-- login for one user does not clear the failure count for another user from
-- the same IP.

CREATE TABLE IF NOT EXISTS login_attempts (
    id INT PRIMARY KEY AUTO_INCREMENT,
    ip_address VARCHAR(45) NOT NULL,
    username_attempted VARCHAR(255) NOT NULL,
    success TINYINT(1) NOT NULL DEFAULT 0,
    attempted_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_la_user_time (username_attempted, attempted_at),
    INDEX idx_la_ip_time (ip_address, attempted_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
