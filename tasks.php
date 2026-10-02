<?php
require_once 'config.php';
require_once 'includes/Database.php';
require_once 'includes/functions.php';

requireLogin();

$db = Database::getInstance();
$user_id = getCurrentUserId();
$project_id = $_GET['project_id'] ?? null;

// Build WHERE conditions based on filters
$where_conditions = ["p.responsible_person_id = ?"];
$params = [$user_id];

if ($project_id) {
    $where_conditions[] = "t.project_id = ?";
    $params[] = $project_id;
}

// Filter by status
$status_filter = $_GET['status'] ?? '';
if ($status_filter && $status_filter !== 'all') {
    $where_conditions[] = "t.status = ?";
    $params[] = $status_filter;
}

// Search functionality
$search = $_GET['search'] ?? '';
if ($search) {
    $where_conditions[] = "(t.name LIKE ? OR t.description LIKE ?)";
    $search_param = '%' . $search . '%';
    $params[] = $search_param;
    $params[] = $search_param;
}

$where_clause = "WHERE " . implode(" AND ", $where_conditions);

// Get tasks with project and user information
$tasks = $db->fetchAll(
    "SELECT t.*, p.name as project_name, u.name as responsible_person_name, 
            c.name as contact_person_name,
            (SELECT COUNT(*) FROM tasks st WHERE st.parent_task_id = t.id) as subtask_count,
            CASE 
                WHEN t.parent_task_id IS NOT NULL THEN 'subtask'
                ELSE 'task'
            END as task_type
     FROM tasks t
     JOIN projects p ON t.project_id = p.id
     LEFT JOIN users u ON t.responsible_person_id = u.id
     LEFT JOIN contacts c ON t.contact_person_id = c.id
     $where_clause
     ORDER BY t.created_at DESC",
    $params
);

// Get projects for filter dropdown
$user_projects = $db->fetchAll(
    "SELECT id, name FROM projects WHERE responsible_person_id = ? ORDER BY name",
    [$user_id]
);

// Get task statistics
$task_stats = $db->fetchOne(
    "SELECT 
        COUNT(*) as total_tasks,
        COUNT(CASE WHEN t.status = 'completed' THEN 1 END) as completed_tasks,
        COUNT(CASE WHEN t.status != 'completed' THEN 1 END) as pending_tasks,
        AVG(t.completion_percentage) as avg_completion
     FROM tasks t
     JOIN projects p ON t.project_id = p.id
     WHERE p.responsible_person_id = ?",
    [$user_id]
);

$title = "Tasks";
$show_nav = true;
?>

<?php include 'includes/header.php'; ?>

<div class="page-container">
    <div class="page-header">
        <div class="page-title">
            <h1>
                <i class="fas fa-tasks"></i>
                Tasks
                <?php if ($project_id): ?>
                    <?php 
                    $project_name = $db->fetchOne("SELECT name FROM projects WHERE id = ?", [$project_id])['name'];
                    ?>
                    <span class="project-context">- <?= e($project_name) ?></span>
                <?php endif; ?>
            </h1>
            <p>Manage and track your project tasks</p>
        </div>
        <div class="page-actions">
            <a href="task_edit.php<?= $project_id ? '?project_id=' . $project_id : '' ?>" class="btn btn-primary">
                <i class="fas fa-plus"></i>
                New Task
            </a>
        </div>
    </div>

    <!-- Task Statistics -->
    <div class="task-stats-grid">
        <div class="stat-card">
            <div class="stat-icon bg-primary">
                <i class="fas fa-list"></i>
            </div>
            <div class="stat-content">
                <h3><?= $task_stats['total_tasks'] ?? 0 ?></h3>
                <p>Total Tasks</p>
            </div>
        </div>
        
        <div class="stat-card">
            <div class="stat-icon bg-success">
                <i class="fas fa-check-circle"></i>
            </div>
            <div class="stat-content">
                <h3><?= $task_stats['completed_tasks'] ?? 0 ?></h3>
                <p>Completed</p>
            </div>
        </div>
        
        <div class="stat-card">
            <div class="stat-icon bg-warning">
                <i class="fas fa-clock"></i>
            </div>
            <div class="stat-content">
                <h3><?= $task_stats['pending_tasks'] ?? 0 ?></h3>
                <p>Pending</p>
            </div>
        </div>
        
        <div class="stat-card">
            <div class="stat-icon bg-info">
                <i class="fas fa-chart-pie"></i>
            </div>
            <div class="stat-content">
                <h3><?= number_format($task_stats['avg_completion'] ?? 0, 1) ?>%</h3>
                <p>Avg Progress</p>
            </div>
        </div>
    </div>

    <!-- Filters -->
    <div class="filters-section">
        <div class="card">
            <div class="card-body">
                <form method="GET" class="filters-form">
                    <?php if ($project_id): ?>
                        <input type="hidden" name="project_id" value="<?= $project_id ?>">
                    <?php endif; ?>
                    
                    <div class="filter-group">
                        <label for="search">Search Tasks:</label>
                        <input type="text" id="search" name="search" 
                               value="<?= e($search) ?>" 
                               placeholder="Search by task name or description...">
                    </div>
                    
                    <?php if (!$project_id): ?>
                        <div class="filter-group">
                            <label for="project_filter">Project:</label>
                            <select id="project_filter" name="project_id">
                                <option value="">All Projects</option>
                                <?php foreach ($user_projects as $project): ?>
                                    <option value="<?= $project['id'] ?>" <?= ($_GET['project_id'] ?? '') == $project['id'] ? 'selected' : '' ?>>
                                        <?= e($project['name']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    <?php endif; ?>
                    
                    <div class="filter-group">
                        <label for="status_filter">Status:</label>
                        <select id="status_filter" name="status">
                            <option value="all" <?= $status_filter == 'all' ? 'selected' : '' ?>>All Status</option>
                            <option value="not_started" <?= $status_filter == 'not_started' ? 'selected' : '' ?>>Not Started</option>
                            <option value="in_progress" <?= $status_filter == 'in_progress' ? 'selected' : '' ?>>In Progress</option>
                            <option value="completed" <?= $status_filter == 'completed' ? 'selected' : '' ?>>Completed</option>
                            <option value="on_hold" <?= $status_filter == 'on_hold' ? 'selected' : '' ?>>On Hold</option>
                        </select>
                    </div>
                    
                    <div class="filter-actions">
                        <button type="submit" class="btn btn-primary btn-sm">
                            <i class="fas fa-search"></i>
                            Filter
                        </button>
                        <a href="tasks.php<?= $project_id ? '?project_id=' . $project_id : '' ?>" class="btn btn-secondary btn-sm">
                            <i class="fas fa-times"></i>
                            Clear
                        </a>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Tasks List -->
    <div class="tasks-container">
        <?php if (empty($tasks)): ?>
            <div class="empty-state">
                <div class="empty-icon">
                    <i class="fas fa-tasks"></i>
                </div>
                <h3>No Tasks Found</h3>
                <p>
                    <?php if ($search || $status_filter): ?>
                        No tasks match your current filters. Try adjusting your search criteria.
                    <?php else: ?>
                        You haven't created any tasks yet. Create your first task to get started!
                    <?php endif; ?>
                </p>
                <a href="task_edit.php<?= $project_id ? '?project_id=' . $project_id : '' ?>" class="btn btn-primary">
                    <i class="fas fa-plus"></i>
                    Create First Task
                </a>
            </div>
        <?php else: ?>
            <div class="tasks-list">
                <?php foreach ($tasks as $task): ?>
                    <div class="task-card <?= $task['task_type'] ?>">
                        <div class="task-header">
                            <div class="task-type-indicator">
                                <?php if ($task['task_type'] === 'subtask'): ?>
                                    <i class="fas fa-level-down-alt" title="Subtask"></i>
                                <?php else: ?>
                                    <i class="fas fa-circle" title="Main Task"></i>
                                <?php endif; ?>
                            </div>
                            <div class="task-title">
                                <h3>
                                    <a href="task_detail.php?id=<?= $task['id'] ?>">
                                        <?= e($task['name']) ?>
                                    </a>
                                </h3>
                                <?php if (!$project_id): ?>
                                    <div class="project-tag">
                                        <i class="fas fa-folder"></i>
                                        <a href="project_detail.php?id=<?= $task['project_id'] ?>">
                                            <?= e($task['project_name']) ?>
                                        </a>
                                    </div>
                                <?php endif; ?>
                            </div>
                            <div class="task-actions">
                                <?php if ($task['status'] !== 'completed'): ?>
                                    <button class="btn btn-sm btn-success quick-complete-btn" 
                                            data-task-id="<?= $task['id'] ?>" 
                                            title="Mark as Completed">
                                        <i class="fas fa-check"></i>
                                    </button>
                                <?php endif; ?>
                                <a href="task_edit.php?id=<?= $task['id'] ?>" class="btn btn-sm btn-outline" title="Edit Task">
                                    <i class="fas fa-edit"></i>
                                </a>
                            </div>
                        </div>
                        
                        <?php if ($task['description']): ?>
                            <div class="task-description">
                                <p><?= e(substr($task['description'], 0, 200)) ?><?= strlen($task['description']) > 200 ? '...' : '' ?></p>
                            </div>
                        <?php endif; ?>
                        
                        <div class="task-meta">
                            <div class="meta-group">
                                <span class="status status-<?= $task['status'] ?>">
                                    <?= ucfirst(str_replace('_', ' ', $task['status'])) ?>
                                </span>
                                
                                <span class="responsible-person">
                                    <i class="fas fa-user"></i>
                                    <?= e($task['responsible_person_name']) ?>
                                </span>
                                
                                <?php if ($task['contact_person_name']): ?>
                                    <span class="contact-person">
                                        <i class="fas fa-address-card"></i>
                                        <?= e($task['contact_person_name']) ?>
                                    </span>
                                <?php endif; ?>
                            </div>
                            
                            <div class="meta-group">
                                <?php if ($task['expected_completion_date']): ?>
                                    <span class="deadline <?= strtotime($task['expected_completion_date']) < time() && $task['status'] != 'completed' ? 'overdue' : '' ?>">
                                        <i class="fas fa-calendar"></i>
                                        Due: <?= formatDateTime($task['expected_completion_date'], 'M j, Y') ?>
                                    </span>
                                <?php endif; ?>
                                
                                <?php if ($task['completion_date']): ?>
                                    <span class="completed-date">
                                        <i class="fas fa-check-circle"></i>
                                        Completed: <?= formatDateTime($task['completion_date'], 'M j, Y') ?>
                                    </span>
                                <?php endif; ?>
                                
                                <?php if ($task['subtask_count'] > 0): ?>
                                    <span class="subtask-count">
                                        <i class="fas fa-sitemap"></i>
                                        <?= $task['subtask_count'] ?> subtasks
                                    </span>
                                <?php endif; ?>
                            </div>
                        </div>
                        
                        <div class="task-progress">
                            <div class="progress-info">
                                <span class="progress-label">Progress</span>
                                <span class="progress-percentage"><?= number_format($task['completion_percentage'], 0) ?>%</span>
                            </div>
                            <div class="progress-bar">
                                <div class="progress-fill" style="width: <?= $task['completion_percentage'] ?>%"></div>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
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

.page-actions {
    flex-shrink: 0;
}

.project-context {
    color: #666;
    font-weight: normal;
    font-size: 1.5rem;
}

.task-stats-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
    gap: 20px;
    margin-bottom: 30px;
}

.stat-card {
    background: white;
    padding: 25px;
    border-radius: 12px;
    box-shadow: 0 2px 12px rgba(0,0,0,0.08);
    display: flex;
    align-items: center;
    gap: 20px;
}

.stat-icon {
    width: 50px;
    height: 50px;
    border-radius: 10px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 20px;
    color: white;
}

.stat-icon.bg-primary { background: linear-gradient(45deg, #007bff, #0056b3); }
.stat-icon.bg-success { background: linear-gradient(45deg, #28a745, #1e7e34); }
.stat-icon.bg-warning { background: linear-gradient(45deg, #ffc107, #e0a800); }
.stat-icon.bg-info { background: linear-gradient(45deg, #17a2b8, #117a8b); }

.stat-content h3 {
    margin: 0 0 5px 0;
    font-size: 1.8rem;
    font-weight: 700;
    color: #333;
}

.stat-content p {
    margin: 0;
    color: #666;
    font-weight: 500;
}

.filters-section {
    margin-bottom: 30px;
}

.filters-form {
    display: grid;
    grid-template-columns: 2fr 1fr 1fr auto;
    gap: 20px;
    align-items: end;
}

.filter-group {
    display: flex;
    flex-direction: column;
    gap: 8px;
}

.filter-group label {
    font-weight: 500;
    color: #333;
    font-size: 14px;
}

.filter-actions {
    display: flex;
    gap: 10px;
}

.tasks-container {
    background: white;
    border-radius: 12px;
    box-shadow: 0 2px 12px rgba(0,0,0,0.08);
    padding: 0;
    overflow: hidden;
}

.tasks-list {
    padding: 0;
}

.task-card {
    padding: 25px;
    border-bottom: 1px solid #f1f3f4;
    transition: all 0.3s ease;
}

.task-card:last-child {
    border-bottom: none;
}

.task-card:hover {
    background: #f8f9fa;
}

.task-card.subtask {
    background: #f8f9fa;
    border-left: 4px solid #007bff;
    margin-left: 20px;
}

.task-header {
    display: flex;
    align-items: flex-start;
    gap: 15px;
    margin-bottom: 15px;
}

.task-type-indicator {
    padding-top: 5px;
    color: #666;
}

.task-title {
    flex: 1;
}

.task-title h3 {
    margin: 0 0 8px 0;
    font-size: 1.2rem;
}

.task-title h3 a {
    color: #333;
    text-decoration: none;
}

.task-title h3 a:hover {
    color: #007bff;
}

.project-tag {
    display: flex;
    align-items: center;
    gap: 6px;
    font-size: 0.9rem;
    color: #666;
}

.project-tag a {
    color: #007bff;
    text-decoration: none;
}

.project-tag a:hover {
    text-decoration: underline;
}

.task-actions {
    display: flex;
    gap: 8px;
}

.task-description {
    margin-bottom: 15px;
    padding-left: 35px;
}

.task-description p {
    margin: 0;
    color: #666;
    line-height: 1.5;
}

.task-meta {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 15px;
    padding-left: 35px;
    flex-wrap: wrap;
    gap: 15px;
}

.meta-group {
    display: flex;
    gap: 15px;
    align-items: center;
    flex-wrap: wrap;
}

.meta-group > span {
    display: flex;
    align-items: center;
    gap: 5px;
    font-size: 0.85rem;
    color: #666;
}

.deadline.overdue {
    color: #dc3545;
    font-weight: 500;
}

.completed-date {
    color: #28a745;
}

.task-progress {
    padding-left: 35px;
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
    font-size: 0.9rem;
}

.progress-percentage {
    font-weight: 600;
    color: #007bff;
    font-size: 0.9rem;
}

.progress-bar {
    height: 6px;
    background: #e9ecef;
    border-radius: 3px;
    overflow: hidden;
}

.progress-fill {
    height: 100%;
    background: linear-gradient(90deg, #007bff, #0056b3);
    border-radius: 3px;
    transition: width 0.3s ease;
}

.empty-state {
    text-align: center;
    padding: 60px 20px;
}

.empty-icon i {
    font-size: 4rem;
    color: #dee2e6;
    margin-bottom: 25px;
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
    line-height: 1.6;
}

@media (max-width: 768px) {
    .page-container {
        padding: 15px;
    }
    
    .page-header {
        flex-direction: column;
        align-items: flex-start;
        gap: 15px;
    }
    
    .page-actions {
        align-self: flex-end;
    }
    
    .task-stats-grid {
        grid-template-columns: repeat(2, 1fr);
        gap: 15px;
    }
    
    .filters-form {
        grid-template-columns: 1fr;
        gap: 15px;
    }
    
    .filter-actions {
        justify-content: stretch;
    }
    
    .filter-actions .btn {
        flex: 1;
    }
    
    .task-header {
        flex-direction: column;
        gap: 10px;
        align-items: stretch;
    }
    
    .task-description,
    .task-meta,
    .task-progress {
        padding-left: 0;
    }
    
    .task-meta {
        flex-direction: column;
        align-items: stretch;
        gap: 10px;
    }
    
    .meta-group {
        justify-content: center;
    }
    
    .task-card.subtask {
        margin-left: 10px;
    }
}
</style>

<script>
document.addEventListener('DOMContentLoaded', function() {
    // Quick complete functionality
    document.querySelectorAll('.quick-complete-btn').forEach(button => {
        button.addEventListener('click', function() {
            const taskId = this.getAttribute('data-task-id');
            const taskCard = this.closest('.task-card');
            
            if (confirm('Mark this task as completed?')) {
                // Disable button and show loading
                this.disabled = true;
                this.innerHTML = '<i class="fas fa-spinner fa-spin"></i>';
                
                fetch('ajax/complete_task.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded',
                    },
                    body: 'task_id=' + encodeURIComponent(taskId)
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        // Update task status in the UI
                        const statusSpan = taskCard.querySelector('.status');
                        if (statusSpan) {
                            statusSpan.textContent = 'Completed';
                            statusSpan.className = 'status status-completed';
                        }
                        
                        // Update progress bar
                        const progressBar = taskCard.querySelector('.progress');
                        if (progressBar) {
                            progressBar.style.width = '100%';
                        }
                        
                        const progressText = taskCard.querySelector('.progress-text');
                        if (progressText) {
                            progressText.textContent = '100%';
                        }
                        
                        // Remove the quick complete button
                        this.remove();
                        
                        // Show success message
                        showMessage('Task marked as completed successfully!', 'success');
                    } else {
                        showMessage(data.message || 'Error completing task', 'error');
                        // Restore button
                        this.disabled = false;
                        this.innerHTML = '<i class="fas fa-check"></i>';
                    }
                })
                .catch(error => {
                    showMessage('Error completing task', 'error');
                    // Restore button
                    this.disabled = false;
                    this.innerHTML = '<i class="fas fa-check"></i>';
                });
            }
        });
    });
    
    // Simple message display function
    function showMessage(message, type = 'info') {
        const alertDiv = document.createElement('div');
        alertDiv.className = `alert alert-${type}`;
        alertDiv.style.cssText = `
            position: fixed;
            top: 20px;
            right: 20px;
            z-index: 1000;
            padding: 15px;
            border-radius: 4px;
            background: ${type === 'success' ? '#d4edda' : type === 'error' ? '#f8d7da' : '#d1ecf1'};
            color: ${type === 'success' ? '#155724' : type === 'error' ? '#721c24' : '#0c5460'};
            border: 1px solid ${type === 'success' ? '#c3e6cb' : type === 'error' ? '#f5c6cb' : '#bee5eb'};
        `;
        alertDiv.textContent = message;
        
        document.body.appendChild(alertDiv);
        
        setTimeout(() => {
            alertDiv.remove();
        }, 5000);
    }
});
</script>

<?php include 'includes/footer.php'; ?>