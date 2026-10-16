-- Project Management System Database Schema
--
-- Parent-task completion rollups (and, as of v1.5.0, parent-task status
-- derivation) are computed in PHP via `calculateTaskCompletionMap()`
-- and `resolveTaskEstimateHours()` in `includes/functions.php`. The
-- earlier MariaDB trigger-based approach is intentionally not used;
-- `subtask_functions.sql.disabled` is kept as historical reference only.

-- Users table
CREATE TABLE users (
    id INT PRIMARY KEY AUTO_INCREMENT,
    name VARCHAR(255) NOT NULL,
    username VARCHAR(100) NOT NULL UNIQUE,
    email VARCHAR(255) NOT NULL UNIQUE,
    password VARCHAR(255) NOT NULL,
    timezone VARCHAR(50) DEFAULT 'UTC',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

-- Password reset tokens table
CREATE TABLE password_reset_tokens (
    id INT PRIMARY KEY AUTO_INCREMENT,
    user_id INT NOT NULL,
    token VARCHAR(255) NOT NULL UNIQUE,
    expires_at TIMESTAMP NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);

-- Password reset attempts table (issue-018: rate-limit / brute-force protection)
-- Records every password-reset attempt (by client IP, action, user_id, outcome)
-- so the application can enforce per-IP request/consume thresholds.
CREATE TABLE password_reset_attempts (
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

-- Login attempts table (issue-019: login / register brute-force & enumeration protection)
-- Records every login attempt (by client IP, submitted username/email, outcome) so the
-- application can enforce per-(IP, username) and per-IP rate limits. The
-- `username_attempted` column is the SUBMITTED value (not a users FK) so a credential
-- stuffing attack that rotates usernames is fully recorded — and a successful login for
-- one user does not clear the failure count for another user from the same IP.
CREATE TABLE login_attempts (
    id INT PRIMARY KEY AUTO_INCREMENT,
    ip_address VARCHAR(45) NOT NULL,         -- IPv6-safe
    username_attempted VARCHAR(255) NOT NULL,
    success TINYINT(1) NOT NULL DEFAULT 0,
    attempted_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_la_user_time (username_attempted, attempted_at),
    INDEX idx_la_ip_time (ip_address, attempted_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Client share-code attempts table (issue-023: client share-code brute-force protection)
-- Records every public client-portal code attempt by client IP, the submitted code,
-- the matched project (NULL for unknown / malformed codes), and the outcome. The
-- application uses the history to enforce a per-IP attempt cap (20 in 5 min) and a
-- per-IP distinct-codes cap (5 distinct failed codes in 5 min) on both
-- `client/dashboard.php` GETs and the `index.php` "Find by Code" POST.
CREATE TABLE client_share_code_attempts (
    id INT PRIMARY KEY AUTO_INCREMENT,
    ip_address VARCHAR(45) NOT NULL,         -- IPv6-safe
    code_attempted VARCHAR(20) NOT NULL,     -- the submitted code, not a FK
    project_id INT NULL,                     -- NULL for unknown / malformed codes
    success TINYINT(1) NOT NULL DEFAULT 0,
    attempted_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_csca_ip_time (ip_address, attempted_at),
    INDEX idx_csca_project_time (project_id, attempted_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Projects table
CREATE TABLE projects (
    id INT PRIMARY KEY AUTO_INCREMENT,
    name VARCHAR(255) NOT NULL,
    description TEXT,
    scope TEXT,                                       -- Project scope / list of requirements (one per line)
    budget_amount DECIMAL(15,2) NULL,                 -- Project budget in user-defined currency
    currency CHAR(3) NULL,                            -- THB, USD, CNY, JPY, SGD, EUR
    share_code CHAR(17) NULL,                         -- Public client share code, format AAAA0-AAAA0-AAAA0 (e.g. AX498-99ZB2-92C3J)
    share_code_rotated_at TIMESTAMP NULL DEFAULT NULL, -- issue-023: when the current share_code was last generated/rotated
    responsible_person_id INT NOT NULL,
    expected_completion_date DATE,
    start_date DATE,                                -- User-settable Project Start Date (issue-008); NULL means "not recorded"
    completion_date DATE,
    status ENUM('not_started', 'in_progress', 'completed', 'on_hold') DEFAULT 'not_started',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (responsible_person_id) REFERENCES users(id),
    UNIQUE KEY uq_projects_share_code (share_code),
    CONSTRAINT chk_projects_currency CHECK (currency IS NULL OR currency IN ('THB','USD','CNY','JPY','SGD','EUR'))
);

-- Global contacts table (for all contacts across projects)
CREATE TABLE contacts (
    id INT PRIMARY KEY AUTO_INCREMENT,
    name VARCHAR(255) NOT NULL,
    description TEXT,
    address TEXT,                                       -- Free-form postal address (issue-009)
    mobile VARCHAR(20),
    email VARCHAR(255),
    phone VARCHAR(20),
    company VARCHAR(255),                               -- Company / organization (issue-009)
    position VARCHAR(255),                              -- Job title / role (issue-009)
    wechat VARCHAR(100),
    line_id VARCHAR(100),
    facebook VARCHAR(255),
    linkedin VARCHAR(255),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

-- Project-specific contacts (links projects to contacts)
CREATE TABLE project_contacts (
    id INT PRIMARY KEY AUTO_INCREMENT,
    project_id INT NOT NULL,
    contact_id INT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE CASCADE,
    FOREIGN KEY (contact_id) REFERENCES contacts(id) ON DELETE CASCADE,
    UNIQUE KEY unique_project_contact (project_id, contact_id)
);

-- Tasks table (with recursive structure for subtasks)
CREATE TABLE tasks (
    id INT PRIMARY KEY AUTO_INCREMENT,
    project_id INT NOT NULL,
    parent_task_id INT NULL, -- NULL for top-level tasks
    name VARCHAR(255) NOT NULL,
    description TEXT,
    responsible_person_id INT NOT NULL,
    contact_person_id INT NULL,
    expected_completion_date DATE,
    completion_date DATE,
    estimated_hours DECIMAL(12,4) NULL,
    inherit_subtask_hours TINYINT(1) NULL DEFAULT NULL,  -- issue-011: when 1, parent's effort is sum of sub-task estimates
    completion_percentage DECIMAL(5,2) DEFAULT 0.00,
    status ENUM('not_started', 'in_progress', 'completed', 'on_hold') DEFAULT 'not_started',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE CASCADE,
    FOREIGN KEY (parent_task_id) REFERENCES tasks(id) ON DELETE CASCADE,
    FOREIGN KEY (responsible_person_id) REFERENCES users(id),
    FOREIGN KEY (contact_person_id) REFERENCES contacts(id)
);

-- Indexes for better performance
CREATE INDEX idx_users_email ON users(email);
CREATE INDEX idx_users_username ON users(username);
CREATE INDEX idx_password_reset_tokens_token ON password_reset_tokens(token);
CREATE INDEX idx_password_reset_tokens_expires ON password_reset_tokens(expires_at);
CREATE INDEX idx_projects_responsible ON projects(responsible_person_id);
CREATE INDEX idx_projects_status ON projects(status);
CREATE INDEX idx_projects_share_code ON projects(share_code);
CREATE INDEX idx_project_contacts_project ON project_contacts(project_id);
CREATE INDEX idx_project_contacts_contact ON project_contacts(contact_id);
CREATE INDEX idx_tasks_project ON tasks(project_id);
CREATE INDEX idx_tasks_parent ON tasks(parent_task_id);
CREATE INDEX idx_tasks_responsible ON tasks(responsible_person_id);
CREATE INDEX idx_tasks_contact ON tasks(contact_person_id);
CREATE INDEX idx_tasks_status ON tasks(status);

-- Views for easier data retrieval

-- View for project statistics
CREATE VIEW project_stats AS
SELECT 
    p.id,
    p.name,
    p.status,
    p.expected_completion_date,
    p.completion_date,
    COUNT(DISTINCT t.id) as total_tasks,
    COUNT(DISTINCT CASE WHEN t.status = 'completed' THEN t.id END) as completed_tasks,
    COUNT(DISTINCT CASE WHEN t.status != 'completed' THEN t.id END) as uncompleted_tasks,
    COALESCE((
        SELECT CASE
            WHEN COUNT(*) = 0 THEN 0
            WHEN SUM(CASE WHEN top_task.estimated_hours IS NULL OR top_task.estimated_hours <= 0 THEN 1 ELSE 0 END) = 0
                THEN SUM(top_task.estimated_hours * top_task.completion_percentage) / NULLIF(SUM(top_task.estimated_hours), 0)
            ELSE AVG(top_task.completion_percentage)
        END
        FROM tasks top_task
        WHERE top_task.project_id = p.id
          AND top_task.parent_task_id IS NULL
    ), 0) as avg_completion_percentage,
    COUNT(DISTINCT pc.contact_id) as contact_count
FROM projects p
LEFT JOIN tasks t ON p.id = t.project_id
LEFT JOIN project_contacts pc ON p.id = pc.project_id
GROUP BY p.id, p.name, p.status, p.expected_completion_date, p.completion_date;

-- View for task hierarchy
CREATE VIEW task_hierarchy AS
SELECT 
    t.id,
    t.project_id,
    t.parent_task_id,
    t.name,
    t.description,
    t.responsible_person_id,
    u.name as responsible_person_name,
    t.contact_person_id,
    c.name as contact_person_name,
    t.expected_completion_date,
    t.completion_date,
    t.estimated_hours,
    t.completion_percentage,
    t.status,
    t.created_at,
    t.updated_at,
    (SELECT COUNT(*) FROM tasks st WHERE st.parent_task_id = t.id) as subtask_count
FROM tasks t
LEFT JOIN users u ON t.responsible_person_id = u.id
LEFT JOIN contacts c ON t.contact_person_id = c.id;