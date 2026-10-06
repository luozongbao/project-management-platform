-- Project Management System Database Schema

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

-- Projects table
CREATE TABLE projects (
    id INT PRIMARY KEY AUTO_INCREMENT,
    name VARCHAR(255) NOT NULL,
    description TEXT,
    scope TEXT,                                       -- Project scope / list of requirements (one per line)
    budget_amount DECIMAL(15,2) NULL,                 -- Project budget in user-defined currency
    currency CHAR(3) NULL,                            -- THB, USD, CNY, JPY, SGD, EUR
    share_code CHAR(17) NULL,                         -- Public client share code, format AAAA0-AAAA0-AAAA0 (e.g. AX498-99ZB2-92C3J)
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
    mobile VARCHAR(20),
    email VARCHAR(255),
    phone VARCHAR(20),
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