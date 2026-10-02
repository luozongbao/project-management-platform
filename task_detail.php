<?php
require_once 'config.php';
require_once 'includes/Database.php';
require_once 'includes/functions.php';

requireLogin();

$db = Database::getInstance();
$user_id = getCurrentUserId();
$task_id = $_GET['id'] ?? null;

if (!$task_id) {
    redirect('tasks.php');
}

// Get task details with project and user information
$task = $db->fetchOne(
    "SELECT t.*, p.name as project_name, p.id as project_id, u.name as responsible_person_name, 
            c.name as contact_person_name, c.email as contact_email, c.phone as contact_phone,
            parent.name as parent_task_name
     FROM tasks t
     JOIN projects p ON t.project_id = p.id
     LEFT JOIN users u ON t.responsible_person_id = u.id
     LEFT JOIN contacts c ON t.contact_person_id = c.id
     LEFT JOIN tasks parent ON t.parent_task_id = parent.id
     WHERE t.id = ? AND p.responsible_person_id = ?",
    [$task_id, $user_id]
);

if (!$task) {
    redirect('tasks.php');
}

// Get subtasks
$subtasks = $db->fetchAll(
    "SELECT t.*, u.name as responsible_person_name, c.name as contact_person_name
     FROM tasks t
     LEFT JOIN users u ON t.responsible_person_id = u.id
     LEFT JOIN contacts c ON t.contact_person_id = c.id
     WHERE t.parent_task_id = ?
     ORDER BY t.created_at ASC",
    [$task_id]
);

// Calculate completion percentage based on subtasks if they exist
if (!empty($subtasks)) {
    $total_subtasks = count($subtasks);
    $completed_subtasks = array_reduce($subtasks, function($carry, $subtask) {
        return $carry + ($subtask['completion_percentage'] / 100);
    }, 0);
    $calculated_percentage = ($completed_subtasks / $total_subtasks) * 100;
} else {
    $calculated_percentage = $task['completion_percentage'];
}

// Get task activity/comments (you can extend this for more detailed activity tracking)
$activities = $db->fetchAll(
    "SELECT 'created' as type, created_at as date, 'Task created' as description
     FROM tasks WHERE id = ?
     UNION ALL
     SELECT 'updated' as type, updated_at as date, 'Task updated' as description
     FROM tasks WHERE id = ? AND updated_at != created_at
     ORDER BY date DESC
     LIMIT 10",
    [$task_id, $task_id]
);

$title = "Task: " . $task['name'];
$show_nav = true;
?>

<?php include 'includes/header.php'; ?>

<div class="page-container">
    <div class="page-header">
        <div class="page-title">
            <div class="breadcrumb">
                <a href="dashboard.php">Dashboard</a>
                <span>/</span>
                <a href="tasks.php">Tasks</a>
                <span>/</span>
                <a href="project_detail.php?id=<?= $task['project_id'] ?>"><?= e($task['project_name']) ?></a>
                <span>/</span>
                <span><?= e($task['name']) ?></span>
            </div>
            <h1>
                <i class="fas fa-<?= $task['parent_task_id'] ? 'level-down-alt' : 'tasks' ?>"></i>
                <?= e($task['name']) ?>
                <?php if ($task['parent_task_id']): ?>
                    <span class="subtask-indicator">Subtask</span>
                <?php endif; ?>
            </h1>
        </div>
        <div class="page-actions">
            <?php if ($task['status'] !== 'completed'): ?>
                <button class="btn btn-success quick-complete-btn" 
                        data-task-id="<?= $task_id ?>" 
                        title="Mark as Completed">
                    <i class="fas fa-check"></i>
                    Complete Task
                </button>
            <?php endif; ?>
            <a href="task_edit.php?id=<?= $task_id ?>" class="btn btn-primary">
                <i class="fas fa-edit"></i>
                Edit Task
            </a>
            <?php if (!$task['parent_task_id']): ?>
                <a href="task_edit.php?parent_id=<?= $task_id ?>" class="btn btn-secondary">
                    <i class="fas fa-plus"></i>
                    Add Subtask
                </a>
            <?php endif; ?>
        </div>
    </div>

    <div class="task-detail-layout">
        <!-- Main Task Information -->
        <div class="main-content">
            <!-- Task Status Card -->
            <div class="card task-status-card">
                <div class="card-header">
                    <h3>Task Overview</h3>
                    <span class="status status-<?= $task['status'] ?>">
                        <?= ucfirst(str_replace('_', ' ', $task['status'])) ?>
                    </span>
                </div>
                <div class="card-body">
                    <div class="progress-section">
                        <div class="progress-info">
                            <span class="progress-label">Overall Progress</span>
                            <span class="progress-percentage"><?= number_format($calculated_percentage, 1) ?>%</span>
                        </div>
                        <div class="progress-bar-large">
                            <div class="progress-fill" style="width: <?= $calculated_percentage ?>%"></div>
                        </div>
                        <?php if (!empty($subtasks)): ?>
                            <p class="progress-note">
                                <i class="fas fa-info-circle"></i>
                                Progress calculated from <?= count($subtasks) ?> subtasks
                            </p>
                        <?php endif; ?>
                    </div>
                    
                    <?php if ($task['description']): ?>
                        <div class="description-section">
                            <h4>Description</h4>
                            <div class="description-content">
                                <?= nl2br(e($task['description'])) ?>
                            </div>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Parent Task (if this is a subtask) -->
            <?php if ($task['parent_task_id']): ?>
                <div class="card">
                    <div class="card-header">
                        <h3>Parent Task</h3>
                    </div>
                    <div class="card-body">
                        <div class="parent-task-link">
                            <i class="fas fa-arrow-up"></i>
                            <a href="task_detail.php?id=<?= $task['parent_task_id'] ?>">
                                <?= e($task['parent_task_name']) ?>
                            </a>
                        </div>
                    </div>
                </div>
            <?php endif; ?>

            <!-- Subtasks -->
            <?php if (!empty($subtasks)): ?>
                <div class="card subtasks-card">
                    <div class="card-header">
                        <h3>
                            Subtasks 
                            <span class="subtask-count">(<?= count($subtasks) ?>)</span>
                        </h3>
                        <a href="task_edit.php?parent_id=<?= $task_id ?>" class="btn btn-sm btn-primary">
                            <i class="fas fa-plus"></i>
                            Add Subtask
                        </a>
                    </div>
                    <div class="card-body">
                        <div class="subtasks-list">
                            <?php foreach ($subtasks as $subtask): ?>
                                <div class="subtask-item">
                                    <div class="subtask-header">
                                        <div class="subtask-title">
                                            <h4>
                                                <a href="task_detail.php?id=<?= $subtask['id'] ?>">
                                                    <?= e($subtask['name']) ?>
                                                </a>
                                            </h4>
                                            <div class="subtask-meta">
                                                <span class="status status-<?= $subtask['status'] ?>">
                                                    <?= ucfirst(str_replace('_', ' ', $subtask['status'])) ?>
                                                </span>
                                                <?php if ($subtask['responsible_person_name']): ?>
                                                    <span class="assigned-to">
                                                        <i class="fas fa-user"></i>
                                                        <?= e($subtask['responsible_person_name']) ?>
                                                    </span>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                        <div class="subtask-actions">
                                            <?php if ($subtask['status'] !== 'completed'): ?>
                                                <button class="btn btn-sm btn-success quick-complete-btn" 
                                                        data-task-id="<?= $subtask['id'] ?>" 
                                                        title="Mark as Completed">
                                                    <i class="fas fa-check"></i>
                                                </button>
                                            <?php endif; ?>
                                            <a href="task_edit.php?id=<?= $subtask['id'] ?>" class="btn btn-sm btn-outline">
                                                <i class="fas fa-edit"></i>
                                            </a>
                                        </div>
                                    </div>
                                    
                                    <?php if ($subtask['description']): ?>
                                        <div class="subtask-description">
                                            <p><?= e(substr($subtask['description'], 0, 150)) ?><?= strlen($subtask['description']) > 150 ? '...' : '' ?></p>
                                        </div>
                                    <?php endif; ?>
                                    
                                    <div class="subtask-progress">
                                        <div class="progress-info">
                                            <span class="progress-label">Progress</span>
                                            <span class="progress-percentage"><?= number_format($subtask['completion_percentage'], 0) ?>%</span>
                                        </div>
                                        <div class="progress-bar">
                                            <div class="progress-fill" style="width: <?= $subtask['completion_percentage'] ?>%"></div>
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>
            <?php elseif (!$task['parent_task_id']): ?>
                <div class="card">
                    <div class="card-header">
                        <h3>Subtasks</h3>
                        <a href="task_edit.php?parent_id=<?= $task_id ?>" class="btn btn-sm btn-primary">
                            <i class="fas fa-plus"></i>
                            Add First Subtask
                        </a>
                    </div>
                    <div class="card-body">
                        <div class="empty-subtasks">
                            <div class="empty-icon">
                                <i class="fas fa-sitemap"></i>
                            </div>
                            <p>No subtasks created yet. Break this task down into smaller, manageable pieces.</p>
                        </div>
                    </div>
                </div>
            <?php endif; ?>
        </div>

        <!-- Sidebar -->
        <div class="sidebar">
            <!-- Task Details -->
            <div class="card">
                <div class="card-header">
                    <h3>Task Details</h3>
                </div>
                <div class="card-body">
                    <div class="detail-item">
                        <label>Project:</label>
                        <a href="project_detail.php?id=<?= $task['project_id'] ?>">
                            <?= e($task['project_name']) ?>
                        </a>
                    </div>
                    
                    <div class="detail-item">
                        <label>Responsible Person:</label>
                        <span><?= e($task['responsible_person_name']) ?></span>
                    </div>
                    
                    <?php if ($task['contact_person_name']): ?>
                        <div class="detail-item">
                            <label>Contact Person:</label>
                            <div class="contact-info">
                                <span><?= e($task['contact_person_name']) ?></span>
                                <?php if ($task['contact_email']): ?>
                                    <a href="mailto:<?= e($task['contact_email']) ?>" title="Send Email">
                                        <i class="fas fa-envelope"></i>
                                    </a>
                                <?php endif; ?>
                                <?php if ($task['contact_phone']): ?>
                                    <a href="tel:<?= e($task['contact_phone']) ?>" title="Call">
                                        <i class="fas fa-phone"></i>
                                    </a>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endif; ?>
                    
                    <div class="detail-item">
                        <label>Created:</label>
                        <span><?= formatDateTime($task['created_at']) ?></span>
                    </div>
                    
                    <?php if ($task['expected_completion_date']): ?>
                        <div class="detail-item">
                            <label>Expected Completion:</label>
                            <span class="<?= strtotime($task['expected_completion_date']) < time() && $task['status'] != 'completed' ? 'overdue' : '' ?>">
                                <?= formatDateTime($task['expected_completion_date'], 'M j, Y') ?>
                            </span>
                        </div>
                    <?php endif; ?>
                    
                    <?php if ($task['completion_date']): ?>
                        <div class="detail-item">
                            <label>Completed:</label>
                            <span class="completed"><?= formatDateTime($task['completion_date']) ?></span>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Recent Activity -->
            <?php if (!empty($activities)): ?>
                <div class="card">
                    <div class="card-header">
                        <h3>Recent Activity</h3>
                    </div>
                    <div class="card-body">
                        <div class="activity-list">
                            <?php foreach ($activities as $activity): ?>
                                <div class="activity-item">
                                    <div class="activity-icon">
                                        <i class="fas fa-<?= $activity['type'] == 'created' ? 'plus' : 'edit' ?>"></i>
                                    </div>
                                    <div class="activity-content">
                                        <p><?= e($activity['description']) ?></p>
                                        <span class="activity-date"><?= formatDateTime($activity['date']) ?></span>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<style>
.page-container {
    max-width: 1200px;
    margin: 0 auto;
    padding: 20px;
}

.breadcrumb {
    font-size: 0.9rem;
    color: #666;
    margin-bottom: 10px;
}

.breadcrumb a {
    color: #007bff;
    text-decoration: none;
}

.breadcrumb a:hover {
    text-decoration: underline;
}

.breadcrumb span {
    margin: 0 8px;
}

.subtask-indicator {
    background: #007bff;
    color: white;
    padding: 4px 8px;
    border-radius: 4px;
    font-size: 0.8rem;
    font-weight: normal;
    margin-left: 10px;
}

.task-detail-layout {
    display: grid;
    grid-template-columns: 2fr 1fr;
    gap: 30px;
    margin-top: 20px;
}

.task-status-card .card-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
}

.progress-section {
    margin-bottom: 30px;
}

.progress-info {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 10px;
}

.progress-label {
    font-weight: 600;
    color: #333;
    font-size: 1.1rem;
}

.progress-percentage {
    font-weight: 700;
    color: #007bff;
    font-size: 1.2rem;
}

.progress-bar-large {
    height: 12px;
    background: #e9ecef;
    border-radius: 6px;
    overflow: hidden;
    margin-bottom: 10px;
}

.progress-bar-large .progress-fill {
    height: 100%;
    background: linear-gradient(90deg, #007bff, #0056b3);
    border-radius: 6px;
    transition: width 0.3s ease;
}

.progress-note {
    font-size: 0.9rem;
    color: #666;
    margin: 0;
    display: flex;
    align-items: center;
    gap: 5px;
}

.description-section h4 {
    margin: 0 0 15px 0;
    color: #333;
    font-size: 1.1rem;
}

.description-content {
    background: #f8f9fa;
    padding: 20px;
    border-radius: 8px;
    border-left: 4px solid #007bff;
    line-height: 1.6;
    color: #333;
}

.parent-task-link {
    display: flex;
    align-items: center;
    gap: 10px;
    font-size: 1.1rem;
}

.parent-task-link a {
    color: #007bff;
    text-decoration: none;
    font-weight: 500;
}

.parent-task-link a:hover {
    text-decoration: underline;
}

.subtasks-card .card-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
}

.subtask-count {
    color: #666;
    font-weight: normal;
}

.subtasks-list {
    display: flex;
    flex-direction: column;
    gap: 20px;
}

.subtask-item {
    background: #f8f9fa;
    padding: 20px;
    border-radius: 8px;
    border-left: 4px solid #007bff;
}

.subtask-header {
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    margin-bottom: 15px;
}

.subtask-title h4 {
    margin: 0 0 8px 0;
    font-size: 1.1rem;
}

.subtask-title h4 a {
    color: #333;
    text-decoration: none;
}

.subtask-title h4 a:hover {
    color: #007bff;
}

.subtask-meta {
    display: flex;
    gap: 15px;
    align-items: center;
    font-size: 0.9rem;
}

.assigned-to {
    display: flex;
    align-items: center;
    gap: 5px;
    color: #666;
}

.subtask-actions {
    display: flex;
    gap: 8px;
}

.subtask-description p {
    margin: 0 0 15px 0;
    color: #666;
    line-height: 1.5;
}

.subtask-progress .progress-info {
    margin-bottom: 8px;
}

.subtask-progress .progress-label,
.subtask-progress .progress-percentage {
    font-size: 0.9rem;
}

.progress-bar {
    height: 6px;
    background: #e9ecef;
    border-radius: 3px;
    overflow: hidden;
}

.progress-bar .progress-fill {
    height: 100%;
    background: linear-gradient(90deg, #007bff, #0056b3);
    border-radius: 3px;
    transition: width 0.3s ease;
}

.empty-subtasks {
    text-align: center;
    padding: 40px 20px;
}

.empty-subtasks .empty-icon i {
    font-size: 3rem;
    color: #dee2e6;
    margin-bottom: 15px;
}

.empty-subtasks p {
    margin: 0;
    color: #666;
    font-size: 1rem;
}

.sidebar .card {
    margin-bottom: 20px;
}

.detail-item {
    margin-bottom: 15px;
}

.detail-item:last-child {
    margin-bottom: 0;
}

.detail-item label {
    display: block;
    font-weight: 500;
    color: #333;
    margin-bottom: 5px;
    font-size: 0.9rem;
}

.detail-item span,
.detail-item a {
    color: #666;
    font-size: 0.9rem;
}

.detail-item a {
    color: #007bff;
    text-decoration: none;
}

.detail-item a:hover {
    text-decoration: underline;
}

.contact-info {
    display: flex;
    align-items: center;
    gap: 10px;
}

.contact-info a {
    color: #007bff;
    font-size: 0.9rem;
}

.overdue {
    color: #dc3545;
    font-weight: 500;
}

.completed {
    color: #28a745;
    font-weight: 500;
}

.activity-list {
    display: flex;
    flex-direction: column;
    gap: 15px;
}

.activity-item {
    display: flex;
    gap: 10px;
}

.activity-icon {
    width: 30px;
    height: 30px;
    background: #f8f9fa;
    border-radius: 15px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 12px;
    color: #666;
    flex-shrink: 0;
}

.activity-content p {
    margin: 0 0 5px 0;
    font-size: 0.9rem;
    color: #333;
}

.activity-date {
    font-size: 0.8rem;
    color: #999;
}

@media (max-width: 768px) {
    .page-container {
        padding: 15px;
    }
    
    .task-detail-layout {
        grid-template-columns: 1fr;
        gap: 20px;
    }
    
    .page-header {
        flex-direction: column;
        gap: 15px;
        align-items: stretch;
    }
    
    .page-actions {
        display: flex;
        gap: 10px;
    }
    
    .page-actions .btn {
        flex: 1;
    }
    
    .subtask-header {
        flex-direction: column;
        gap: 10px;
        align-items: stretch;
    }
    
    .subtask-meta {
        flex-direction: column;
        gap: 8px;
        align-items: flex-start;
    }
}
</style>

<script>
document.addEventListener('DOMContentLoaded', function() {
    // Quick complete functionality
    document.querySelectorAll('.quick-complete-btn').forEach(button => {
        button.addEventListener('click', function() {
            const taskId = this.getAttribute('data-task-id');
            const isMainTask = this.closest('.page-actions') !== null;
            const taskElement = isMainTask ? document : this.closest('.subtask-card');
            
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
                        if (isMainTask) {
                            // For main task, reload the page to show updated status
                            showMessage('Task marked as completed successfully!', 'success');
                            setTimeout(() => {
                                location.reload();
                            }, 1000);
                        } else {
                            // For subtasks, update UI in place
                            const statusSpan = taskElement.querySelector('.status');
                            if (statusSpan) {
                                statusSpan.textContent = 'Completed';
                                statusSpan.className = 'status status-completed';
                            }
                            
                            // Update progress bar
                            const progressBar = taskElement.querySelector('.progress');
                            if (progressBar) {
                                progressBar.style.width = '100%';
                            }
                            
                            const progressText = taskElement.querySelector('.progress-text');
                            if (progressText) {
                                progressText.textContent = '100%';
                            }
                            
                            // Remove the quick complete button
                            this.remove();
                            
                            // Show success message
                            showMessage('Subtask marked as completed successfully!', 'success');
                            
                            // Refresh subtask progress on the main page after a delay
                            setTimeout(() => {
                                location.reload();
                            }, 2000);
                        }
                    } else {
                        showMessage(data.message || 'Error completing task', 'error');
                        // Restore button
                        this.disabled = false;
                        this.innerHTML = isMainTask ? '<i class="fas fa-check"></i> Complete Task' : '<i class="fas fa-check"></i>';
                    }
                })
                .catch(error => {
                    showMessage('Error completing task', 'error');
                    // Restore button
                    this.disabled = false;
                    this.innerHTML = isMainTask ? '<i class="fas fa-check"></i> Complete Task' : '<i class="fas fa-check"></i>';
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