-- 20261015_add_password_reset_attempts.sql
--
-- Issue-018: rate-limit / brute-force protection for forgot_password.php
-- and reset_password.php. The application records every password-reset
-- attempt (by client IP, action, user_id, and success/failure) and uses
-- the history to enforce per-IP request/consume thresholds.
--
-- Idempotent: the IF NOT EXISTS guard makes this safe to re-apply.

CREATE TABLE IF NOT EXISTS password_reset_attempts (
    id INT PRIMARY KEY AUTO_INCREMENT,
    ip_address VARCHAR(45) NOT NULL,        -- IPv6-safe
    action ENUM('request', 'consume') NOT NULL,
    user_id INT NULL,                       -- NULL for unknown emails
    success TINYINT(1) NOT NULL DEFAULT 0,
    attempted_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_pra_ip_action_time (ip_address, action, attempted_at),
    INDEX idx_pra_user_time (user_id, attempted_at),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
