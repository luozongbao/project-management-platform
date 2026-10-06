<?php
require_once 'config.php';
require_once 'includes/Database.php';
require_once 'includes/functions.php';

requireLogin();

$db = Database::getInstance();
$user_id = getCurrentUserId();

// Get projects with statistics
$projects = $db->fetchAll(
    "SELECT ps.*, p.description, p.created_at, p.start_date, u.name as responsible_person_name
     FROM project_stats ps
     JOIN projects p ON ps.id = p.id
     JOIN users u ON p.responsible_person_id = u.id
     WHERE p.responsible_person_id = ?
     ORDER BY p.created_at DESC",
    [$user_id]
);

foreach ($projects as &$project) {
    $completion = getProjectTaskCompletionMap($db, $project['id']);
    $project['avg_completion_percentage'] = $completion['project_completion'];
}
unset($project);

$title = "Projects";
$show_nav = true;
?>

<?php include 'includes/header.php'; ?>

<div class="page-container">
    <div class="page-header">
        <div class="page-title">
            <h1>
                <i class="fas fa-folder-open"></i>
                Projects
            </h1>
            <p>Manage your projects and track their progress</p>
        </div>
        <div class="page-actions">
            <a href="project_edit.php" class="btn btn-primary">
                <i class="fas fa-plus"></i>
                New Project
            </a>
        </div>
    </div>

    <?php if (empty($projects)): ?>
        <div class="empty-state">
            <div class="empty-icon">
                <i class="fas fa-folder-plus"></i>
            </div>
            <h3>No Projects Yet</h3>
            <p>Create your first project to get started with managing your work.</p>
            <a href="project_edit.php" class="btn btn-primary">
                <i class="fas fa-plus"></i>
                Create First Project
            </a>
        </div>
    <?php else: ?>
        <div class="projects-grid">
            <?php foreach ($projects as $project): ?>
                <div class="project-card">
                    <div class="project-header">
                        <h3>
                            <a href="project_detail.php?id=<?= $project['id'] ?>">
                                <?= e($project['name']) ?>
                            </a>
                        </h3>
                        <div class="project-actions">
                            <a href="project_edit.php?id=<?= $project['id'] ?>" class="btn btn-sm btn-outline" title="Edit">
                                <i class="fas fa-edit"></i>
                            </a>
                        </div>
                    </div>

                    <div class="project-description">
                        <?php if ($project['description']): ?>
                            <p><?= e(substr($project['description'], 0, 150)) ?><?= strlen($project['description']) > 150 ? '...' : '' ?></p>
                        <?php else: ?>
                            <p class="no-description">No description provided</p>
                        <?php endif; ?>
                    </div>

                    <div class="project-stats">
                        <div class="stat-item">
                            <i class="fas fa-tasks"></i>
                            <span><?= $project['total_tasks'] ?> tasks</span>
                        </div>
                        <div class="stat-item">
                            <i class="fas fa-address-book"></i>
                            <span><?= $project['contact_count'] ?> contacts</span>
                        </div>
                        <?php if ($project['uncompleted_tasks'] > 0): ?>
                            <div class="stat-item urgent">
                                <i class="fas fa-exclamation-triangle"></i>
                                <span><?= $project['uncompleted_tasks'] ?> pending</span>
                            </div>
                        <?php endif; ?>
                    </div>

                    <div class="project-progress">
                        <div class="progress-info">
                            <span class="progress-label">Progress</span>
                            <span class="progress-percentage"><?= number_format($project['avg_completion_percentage'], 1) ?>%</span>
                        </div>
                        <div class="progress-bar">
                            <div class="progress-fill" style="width: <?= $project['avg_completion_percentage'] ?>%"></div>
                        </div>
                    </div>

                    <div class="project-meta">
                        <div class="project-status">
                            <span class="status status-<?= $project['status'] ?>">
                                <?= ucfirst(str_replace('_', ' ', $project['status'])) ?>
                            </span>
                        </div>

                        <div class="project-dates">
                            <?php if (!empty($project['start_date'])): ?>
                                <div class="date-item started">
                                    <i class="fas fa-flag"></i>
                                    <span>Started: <?= formatDateTime($project['start_date'], 'M j, Y') ?></span>
                                </div>
                            <?php endif; ?>

                            <?php if ($project['expected_completion_date']): ?>
                                <div class="date-item">
                                    <i class="fas fa-calendar"></i>
                                    <span>Due: <?= formatDateTime($project['expected_completion_date'], 'M j, Y') ?></span>
                                </div>
                            <?php endif; ?>

                            <?php if ($project['completion_date']): ?>
                                <div class="date-item completed">
                                    <i class="fas fa-check-circle"></i>
                                    <span>Completed: <?= formatDateTime($project['completion_date'], 'M j, Y') ?></span>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>

<style>
.page-container {
    max-width: 1200px;
    margin: 0 auto;
    padding: 20px;
}

.page-header {
    display: flex;
    justify-content: space-between;
    align-items: flex-end;
    margin-bottom: 30px;
    padding-bottom: 20px;
    border-bottom: 2px solid #f1f3f4;
}

.page-title h1 {
    margin: 0 0 8px 0;
    color: #333;
    font-size: 2rem;
}

.page-title p {
    margin: 0;
    color: #666;
    font-size: 1rem;
}

.projects-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(350px, 1fr));
    gap: 25px;
}

.project-card {
    background: white;
    border-radius: 12px;
    box-shadow: 0 2px 12px rgba(0,0,0,0.08);
    padding: 25px;
    transition: all 0.3s ease;
    border: 1px solid #f1f3f4;
}

.project-card:hover {
    transform: translateY(-2px);
    box-shadow: 0 8px 25px rgba(0,0,0,0.12);
}

.project-header {
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    margin-bottom: 15px;
}

.project-header h3 {
    margin: 0;
    font-size: 1.25rem;
    line-height: 1.4;
}

.project-header h3 a {
    color: #333;
    text-decoration: none;
}

.project-header h3 a:hover {
    color: #007bff;
}

.project-actions .btn {
    padding: 8px 12px;
    font-size: 14px;
}

.project-description {
    margin-bottom: 20px;
}

.project-description p {
    margin: 0;
    color: #666;
    line-height: 1.5;
}

.project-description .no-description {
    font-style: italic;
    color: #999;
}

.project-stats {
    display: flex;
    gap: 20px;
    margin-bottom: 20px;
    padding: 15px;
    background: #f8f9fa;
    border-radius: 8px;
}

.stat-item {
    display: flex;
    align-items: center;
    gap: 6px;
    font-size: 0.875rem;
    color: #666;
}

.stat-item.urgent {
    color: #dc3545;
}

.stat-item i {
    width: 16px;
    text-align: center;
}

.project-progress {
    margin-bottom: 20px;
}

.progress-info {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 8px;
}

.progress-label {
    font-weight: 500;
    color: #333;
}

.progress-percentage {
    font-weight: 600;
    color: #007bff;
}

.progress-bar {
    height: 8px;
    background: #e9ecef;
    border-radius: 4px;
    overflow: hidden;
}

.progress-fill {
    height: 100%;
    background: linear-gradient(90deg, #007bff, #0056b3);
    border-radius: 4px;
    transition: width 0.3s ease;
}

.project-meta {
    padding-top: 20px;
    border-top: 1px solid #f1f3f4;
}

.project-status {
    margin-bottom: 15px;
}

.status {
    padding: 6px 12px;
    border-radius: 20px;
    font-size: 0.75rem;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 0.5px;
}

.status-not_started { 
    background: #f8f9fa; 
    color: #495057;
    border: 1px solid #dee2e6;
}

.status-in_progress { 
    background: #fff3cd; 
    color: #856404;
    border: 1px solid #ffeaa7;
}

.status-completed { 
    background: #d4edda; 
    color: #155724;
    border: 1px solid #c3e6cb;
}

.status-on_hold { 
    background: #f8d7da; 
    color: #721c24;
    border: 1px solid #f5c6cb;
}

.project-dates {
    display: flex;
    flex-direction: column;
    gap: 8px;
}

.date-item {
    display: flex;
    align-items: center;
    gap: 8px;
    font-size: 0.875rem;
    color: #666;
}

.date-item.completed {
    color: #28a745;
}

.date-item i {
    width: 14px;
    text-align: center;
}

.empty-state {
    text-align: center;
    padding: 80px 20px;
    background: white;
    border-radius: 12px;
    box-shadow: 0 2px 12px rgba(0,0,0,0.08);
}

.empty-icon {
    margin-bottom: 25px;
}

.empty-icon i {
    font-size: 4rem;
    color: #dee2e6;
}

.empty-state h3 {
    margin: 0 0 15px 0;
    color: #333;
    font-size: 1.5rem;
}

.empty-state p {
    margin: 0 0 25px 0;
    color: #666;
    font-size: 1rem;
}

.btn {
    display: inline-block;
    padding: 12px 24px;
    background: #007bff;
    color: white;
    text-decoration: none;
    border-radius: 6px;
    border: none;
    cursor: pointer;
    font-size: 14px;
    font-weight: 500;
    transition: all 0.3s;
    text-align: center;
}

.btn:hover {
    background: #0056b3;
    transform: translateY(-1px);
}

.btn-primary {
    background: #007bff;
}

.btn-primary:hover {
    background: #0056b3;
}

.btn-outline {
    background: transparent;
    color: #6c757d;
    border: 1px solid #dee2e6;
}

.btn-outline:hover {
    background: #f8f9fa;
    color: #495057;
}

.btn-sm {
    padding: 6px 12px;
    font-size: 12px;
}

@media (max-width: 768px) {
    .page-container {
        padding: 15px;
    }
    
    .page-header {
        flex-direction: column;
        align-items: stretch;
        gap: 15px;
    }
    
    .projects-grid {
        grid-template-columns: 1fr;
        gap: 20px;
    }
    
    .project-stats {
        flex-direction: column;
        gap: 10px;
    }
    
    .project-header {
        flex-direction: column;
        gap: 10px;
        align-items: stretch;
    }
}
</style>

<?php include 'includes/footer.php'; ?>