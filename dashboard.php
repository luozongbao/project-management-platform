<?php
require_once 'config.php';
require_once 'includes/Database.php';
require_once 'includes/functions.php';

requireLogin();

$db = Database::getInstance();

// Get dashboard statistics
$user_id = getCurrentUserId();

// Overall statistics
$total_projects = $db->fetchOne(
    "SELECT COUNT(*) as count FROM projects WHERE responsible_person_id = ?",
    [$user_id]
)['count'] ?? 0;

$completed_projects = $db->fetchOne(
    "SELECT COUNT(*) as count FROM projects WHERE responsible_person_id = ? AND status = 'completed'",
    [$user_id]
)['count'] ?? 0;

$total_tasks = $db->fetchOne(
    "SELECT COUNT(*) as count FROM tasks t 
     JOIN projects p ON t.project_id = p.id 
     WHERE p.responsible_person_id = ?",
    [$user_id]
)['count'] ?? 0;

$uncompleted_tasks = $db->fetchOne(
    "SELECT COUNT(*) as count FROM tasks t 
     JOIN projects p ON t.project_id = p.id 
     WHERE p.responsible_person_id = ? AND t.status != 'completed'",
    [$user_id]
)['count'] ?? 0;

// Overall completion percentage (average of all project completion percentages)
$overall_completion = $db->fetchOne(
    "SELECT AVG(avg_completion_percentage) as avg_completion 
     FROM project_stats 
     WHERE id IN (SELECT id FROM projects WHERE responsible_person_id = ?)",
    [$user_id]
)['avg_completion'] ?? 0;

// Recent projects
$recent_projects = $db->fetchAll(
    "SELECT ps.*, u.name as responsible_person_name 
     FROM project_stats ps
     JOIN projects p ON ps.id = p.id
     JOIN users u ON p.responsible_person_id = u.id
     WHERE p.responsible_person_id = ? 
     ORDER BY p.created_at DESC 
     LIMIT 5",
    [$user_id]
);

// Upcoming deadlines
$upcoming_deadlines = $db->fetchAll(
    "SELECT p.id, p.name, p.expected_completion_date, ps.avg_completion_percentage, p.status
     FROM projects p
     LEFT JOIN project_stats ps ON p.id = ps.id
     WHERE p.responsible_person_id = ? 
       AND p.status != 'completed'
       AND p.expected_completion_date IS NOT NULL
       AND p.expected_completion_date >= CURDATE()
     ORDER BY p.expected_completion_date ASC
     LIMIT 5",
    [$user_id]
);

// Recent tasks
$recent_tasks = $db->fetchAll(
    "SELECT t.id, t.name, t.status, t.completion_percentage, p.name as project_name,
            t.expected_completion_date, u.name as responsible_person_name
     FROM tasks t
     JOIN projects p ON t.project_id = p.id
     LEFT JOIN users u ON t.responsible_person_id = u.id
     WHERE p.responsible_person_id = ?
     ORDER BY t.updated_at DESC
     LIMIT 5",
    [$user_id]
);

$title = "Dashboard";
$show_nav = true;
?>

<?php include 'includes/header.php'; ?>

<div class="dashboard-container">
    <div class="dashboard-header">
        <h1>
            <i class="fas fa-tachometer-alt"></i>
            Dashboard
        </h1>
        <p>Welcome back, <?= e(getCurrentUser()['name']) ?>!</p>
    </div>

    <!-- Statistics Cards -->
    <div class="stats-grid">
        <div class="stat-card">
            <div class="stat-icon">
                <i class="fas fa-chart-pie"></i>
            </div>
            <div class="stat-content">
                <h3><?= number_format($overall_completion, 1) ?>%</h3>
                <p>Overall Completion</p>
            </div>
        </div>

        <div class="stat-card">
            <div class="stat-icon">
                <i class="fas fa-folder-open"></i>
            </div>
            <div class="stat-content">
                <h3><?= $total_projects ?></h3>
                <p>Total Projects</p>
                <small><?= $completed_projects ?> completed</small>
            </div>
        </div>

        <div class="stat-card">
            <div class="stat-icon">
                <i class="fas fa-tasks"></i>
            </div>
            <div class="stat-content">
                <h3><?= $uncompleted_tasks ?></h3>
                <p>Pending Tasks</p>
                <small><?= $total_tasks ?> total</small>
            </div>
        </div>

        <div class="stat-card">
            <div class="stat-icon">
                <i class="fas fa-clock"></i>
            </div>
            <div class="stat-content">
                <h3><?= count($upcoming_deadlines) ?></h3>
                <p>Upcoming Deadlines</p>
            </div>
        </div>
    </div>

    <div class="dashboard-grid">
        <!-- Recent Projects -->
        <div class="dashboard-section">
            <div class="section-header">
                <h2>
                    <i class="fas fa-folder-open"></i>
                    Recent Projects
                </h2>
                <a href="projects.php" class="btn btn-primary">
                    <i class="fas fa-plus"></i>
                    New Project
                </a>
            </div>

            <div class="projects-list">
                <?php if (empty($recent_projects)): ?>
                    <div class="empty-state">
                        <i class="fas fa-folder-plus"></i>
                        <p>No projects yet. <a href="project_edit.php">Create your first project</a></p>
                    </div>
                <?php else: ?>
                    <?php foreach ($recent_projects as $project): ?>
                        <div class="project-card">
                            <div class="project-info">
                                <h4>
                                    <a href="project_detail.php?id=<?= $project['id'] ?>">
                                        <?= e($project['name']) ?>
                                    </a>
                                </h4>
                                <div class="project-meta">
                                    <span class="status status-<?= $project['status'] ?>">
                                        <?= ucfirst(str_replace('_', ' ', $project['status'])) ?>
                                    </span>
                                    <?php if ($project['expected_completion_date']): ?>
                                        <span class="deadline">
                                            <i class="fas fa-calendar"></i>
                                            <?= formatDateTime($project['expected_completion_date'], 'M j, Y') ?>
                                        </span>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <div class="project-progress">
                                <div class="progress-bar">
                                    <div class="progress-fill" style="width: <?= $project['avg_completion_percentage'] ?>%"></div>
                                </div>
                                <span class="progress-text"><?= number_format($project['avg_completion_percentage'], 1) ?>%</span>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>

            <?php if (!empty($recent_projects)): ?>
                <div class="section-footer">
                    <a href="projects.php">View all projects</a>
                </div>
            <?php endif; ?>
        </div>

        <!-- Upcoming Deadlines -->
        <div class="dashboard-section">
            <div class="section-header">
                <h2>
                    <i class="fas fa-clock"></i>
                    Upcoming Deadlines
                </h2>
            </div>

            <div class="deadlines-list">
                <?php if (empty($upcoming_deadlines)): ?>
                    <div class="empty-state">
                        <i class="fas fa-calendar-check"></i>
                        <p>No upcoming deadlines</p>
                    </div>
                <?php else: ?>
                    <?php foreach ($upcoming_deadlines as $deadline): ?>
                        <div class="deadline-item">
                            <div class="deadline-info">
                                <h5>
                                    <a href="project_detail.php?id=<?= $deadline['id'] ?>">
                                        <?= e($deadline['name']) ?>
                                    </a>
                                </h5>
                                <p class="deadline-date">
                                    <i class="fas fa-calendar"></i>
                                    <?= formatDateTime($deadline['expected_completion_date'], 'M j, Y') ?>
                                </p>
                            </div>
                            <div class="deadline-progress">
                                <div class="progress-circle">
                                    <span><?= number_format($deadline['avg_completion_percentage'], 0) ?>%</span>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>

        <!-- Recent Tasks -->
        <div class="dashboard-section">
            <div class="section-header">
                <h2>
                    <i class="fas fa-tasks"></i>
                    Recent Tasks
                </h2>
                <a href="tasks.php" class="btn btn-secondary">View All</a>
            </div>

            <div class="tasks-list">
                <?php if (empty($recent_tasks)): ?>
                    <div class="empty-state">
                        <i class="fas fa-tasks"></i>
                        <p>No tasks yet</p>
                    </div>
                <?php else: ?>
                    <?php foreach ($recent_tasks as $task): ?>
                        <div class="task-item">
                            <div class="task-info">
                                <h6>
                                    <a href="task_detail.php?id=<?= $task['id'] ?>">
                                        <?= e($task['name']) ?>
                                    </a>
                                </h6>
                                <p class="task-meta">
                                    <span class="project-name"><?= e($task['project_name']) ?></span>
                                    <?php if ($task['expected_completion_date']): ?>
                                        <span class="task-deadline">
                                            <i class="fas fa-calendar"></i>
                                            <?= formatDateTime($task['expected_completion_date'], 'M j') ?>
                                        </span>
                                    <?php endif; ?>
                                </p>
                            </div>
                            <div class="task-status">
                                <span class="status status-<?= $task['status'] ?>">
                                    <?= ucfirst(str_replace('_', ' ', $task['status'])) ?>
                                </span>
                                <span class="completion"><?= number_format($task['completion_percentage'], 0) ?>%</span>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<style>
.dashboard-container {
    max-width: 1200px;
    margin: 0 auto;
    padding: 20px;
}

.dashboard-header {
    margin-bottom: 30px;
}

.dashboard-header h1 {
    margin: 0 0 10px 0;
    color: #333;
    font-size: 2rem;
}

.dashboard-header p {
    margin: 0;
    color: #666;
    font-size: 1.1rem;
}

.stats-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
    gap: 20px;
    margin-bottom: 40px;
}

.stat-card {
    background: white;
    padding: 25px;
    border-radius: 10px;
    box-shadow: 0 2px 10px rgba(0,0,0,0.1);
    display: flex;
    align-items: center;
    gap: 20px;
}

.stat-icon {
    width: 60px;
    height: 60px;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 24px;
    color: white;
}

.stat-card:nth-child(1) .stat-icon { background: linear-gradient(45deg, #007bff, #0056b3); }
.stat-card:nth-child(2) .stat-icon { background: linear-gradient(45deg, #28a745, #1e7e34); }
.stat-card:nth-child(3) .stat-icon { background: linear-gradient(45deg, #ffc107, #e0a800); }
.stat-card:nth-child(4) .stat-icon { background: linear-gradient(45deg, #dc3545, #c82333); }

.stat-content h3 {
    margin: 0 0 5px 0;
    font-size: 2rem;
    font-weight: 700;
    color: #333;
}

.stat-content p {
    margin: 0;
    font-weight: 500;
    color: #666;
}

.stat-content small {
    display: block;
    margin-top: 5px;
    color: #888;
    font-size: 0.85rem;
}

.dashboard-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(350px, 1fr));
    gap: 30px;
}

.dashboard-section {
    background: white;
    border-radius: 10px;
    box-shadow: 0 2px 10px rgba(0,0,0,0.1);
    overflow: hidden;
}

.section-header {
    padding: 20px 25px;
    background: #f8f9fa;
    border-bottom: 1px solid #dee2e6;
    display: flex;
    justify-content: space-between;
    align-items: center;
}

.section-header h2 {
    margin: 0;
    font-size: 1.25rem;
    color: #333;
}

.section-header .btn {
    padding: 8px 16px;
    font-size: 0.875rem;
}

.projects-list, .deadlines-list, .tasks-list {
    padding: 0;
}

.project-card, .deadline-item, .task-item {
    padding: 20px 25px;
    border-bottom: 1px solid #eee;
    display: flex;
    justify-content: space-between;
    align-items: center;
}

.project-card:last-child, .deadline-item:last-child, .task-item:last-child {
    border-bottom: none;
}

.project-info h4, .task-info h6 {
    margin: 0 0 8px 0;
}

.project-info h4 a, .task-info h6 a, .deadline-info h5 a {
    color: #333;
    text-decoration: none;
}

.project-info h4 a:hover, .task-info h6 a:hover, .deadline-info h5 a:hover {
    color: #007bff;
}

.project-meta, .task-meta {
    display: flex;
    gap: 15px;
    align-items: center;
}

.status {
    padding: 4px 8px;
    border-radius: 4px;
    font-size: 0.75rem;
    font-weight: 500;
    text-transform: uppercase;
}

.status-not_started { background: #e9ecef; color: #495057; }
.status-in_progress { background: #fff3cd; color: #856404; }
.status-completed { background: #d4edda; color: #155724; }
.status-on_hold { background: #f8d7da; color: #721c24; }

.deadline, .task-deadline {
    color: #666;
    font-size: 0.875rem;
}

.project-progress, .deadline-progress {
    display: flex;
    align-items: center;
    gap: 10px;
}

.progress-bar {
    width: 100px;
    height: 6px;
    background: #e9ecef;
    border-radius: 3px;
    overflow: hidden;
}

.progress-fill {
    height: 100%;
    background: linear-gradient(90deg, #28a745, #20c997);
    transition: width 0.3s ease;
}

.progress-text {
    font-size: 0.875rem;
    font-weight: 500;
    color: #666;
}

.progress-circle {
    width: 40px;
    height: 40px;
    border-radius: 50%;
    background: #f8f9fa;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 0.75rem;
    font-weight: 600;
    color: #007bff;
    border: 2px solid #007bff;
}

.empty-state {
    padding: 40px 25px;
    text-align: center;
    color: #666;
}

.empty-state i {
    font-size: 48px;
    margin-bottom: 15px;
    color: #ccc;
}

.empty-state p {
    margin: 0;
}

.empty-state a {
    color: #007bff;
    text-decoration: none;
}

.empty-state a:hover {
    text-decoration: underline;
}

.section-footer {
    padding: 15px 25px;
    text-align: right;
    background: #f8f9fa;
    border-top: 1px solid #dee2e6;
}

.section-footer a {
    color: #007bff;
    text-decoration: none;
    font-weight: 500;
}

.section-footer a:hover {
    text-decoration: underline;
}

.task-status {
    display: flex;
    flex-direction: column;
    align-items: flex-end;
    gap: 5px;
}

.completion {
    font-size: 0.875rem;
    font-weight: 500;
    color: #666;
}

.project-name {
    color: #007bff;
    font-weight: 500;
}

.btn {
    display: inline-block;
    padding: 10px 20px;
    background: #007bff;
    color: white;
    text-decoration: none;
    border-radius: 5px;
    border: none;
    cursor: pointer;
    font-size: 14px;
    transition: all 0.3s;
}

.btn:hover {
    background: #0056b3;
}

.btn-secondary {
    background: #6c757d;
}

.btn-secondary:hover {
    background: #545b62;
}

@media (max-width: 768px) {
    .dashboard-container {
        padding: 15px;
    }
    
    .stats-grid {
        grid-template-columns: 1fr;
        gap: 15px;
    }
    
    .dashboard-grid {
        grid-template-columns: 1fr;
        gap: 20px;
    }
    
    .project-card, .deadline-item, .task-item {
        flex-direction: column;
        align-items: flex-start;
        gap: 15px;
    }
    
    .section-header {
        flex-direction: column;
        align-items: flex-start;
        gap: 15px;
    }
    
    .section-header .btn {
        align-self: flex-end;
    }
    
    .project-progress, .deadline-progress, .task-status {
        align-self: stretch;
    }
}
</style>

<?php include 'includes/footer.php'; ?>