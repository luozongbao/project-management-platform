<?php
require_once 'config.php';
require_once 'includes/Database.php';
require_once 'includes/functions.php';

requireLogin();

$db = Database::getInstance();
$user_id = getCurrentUserId();
$project_id = $_GET['id'] ?? null;

if (!$project_id) {
    redirect('projects.php', 'Invalid project ID.', 'danger');
}

// Get project details with statistics
$project = $db->fetchOne(
    "SELECT p.*, ps.total_tasks, ps.completed_tasks, ps.uncompleted_tasks, 
            ps.avg_completion_percentage, ps.contact_count, u.name as responsible_person_name
     FROM projects p
     LEFT JOIN project_stats ps ON p.id = ps.id
     LEFT JOIN users u ON p.responsible_person_id = u.id
     WHERE p.id = ? AND p.responsible_person_id = ?",
    [$project_id, $user_id]
);

if (!$project) {
    redirect('projects.php', 'Project not found or access denied.', 'danger');
}

$taskCompletion = getProjectTaskCompletionMap($db, $project_id);
$project['avg_completion_percentage'] = $taskCompletion['project_completion'];

// Get top-level tasks (no parent)
$tasks = $db->fetchAll(
    "SELECT t.*, u.name as responsible_person_name, c.name as contact_person_name,
            (SELECT COUNT(*) FROM tasks st WHERE st.parent_task_id = t.id) as subtask_count
     FROM tasks t
     LEFT JOIN users u ON t.responsible_person_id = u.id
     LEFT JOIN contacts c ON t.contact_person_id = c.id
     WHERE t.project_id = ? AND t.parent_task_id IS NULL
     ORDER BY t.created_at DESC",
    [$project_id]
);
foreach ($tasks as &$task) {
    $task['completion_percentage'] = $taskCompletion['task_completion'][(int)$task['id']] ?? 0;
}
unset($task);

// Get project contacts
$project_contacts = $db->fetchAll(
    "SELECT c.*, pc.created_at as assigned_date
     FROM contacts c
     JOIN project_contacts pc ON c.id = pc.contact_id
     WHERE pc.project_id = ?
     ORDER BY c.name ASC",
    [$project_id]
);

// Get recent activity (last 10 task updates)
$recent_activity = $db->fetchAll(
    "SELECT t.id, t.name, t.status, t.updated_at, u.name as responsible_person_name
     FROM tasks t
     LEFT JOIN users u ON t.responsible_person_id = u.id
     WHERE t.project_id = ?
     ORDER BY t.updated_at DESC
     LIMIT 10",
    [$project_id]
);

$title = "Project: " . $project['name'];
$show_nav = true;
?>

<?php include 'includes/header.php'; ?>

<div class="project-detail-container">
    <!-- Project Header -->
    <div class="project-header">
        <div class="project-info">
            <div class="project-breadcrumb">
                <a href="projects.php"><i class="fas fa-folder-open"></i> Projects</a>
                <span>/</span>
                <span><?= e($project['name']) ?></span>
            </div>
            <h1><?= e($project['name']) ?></h1>
            <?php if ($project['description']): ?>
                <p class="project-description"><?= e($project['description']) ?></p>
            <?php endif; ?>
            
            <div class="project-meta">
                <span class="status status-<?= $project['status'] ?>">
                    <?= ucfirst(str_replace('_', ' ', $project['status'])) ?>
                </span>
                <span class="responsible-person">
                    <i class="fas fa-user"></i>
                    <?= e($project['responsible_person_name']) ?>
                </span>
                <?php if (!empty($project['start_date'])): ?>
                    <span class="start-date">
                        <i class="fas fa-flag"></i>
                        Started: <?= formatDateTime($project['start_date'], 'M j, Y') ?>
                    </span>
                <?php endif; ?>
                <?php if ($project['expected_completion_date']): ?>
                    <span class="expected-date">
                        <i class="fas fa-calendar"></i>
                        Due: <?= formatDateTime($project['expected_completion_date'], 'M j, Y') ?>
                    </span>
                <?php endif; ?>
            </div>
        </div>
        
        <div class="project-actions">
            <a href="project_edit.php?id=<?= $project['id'] ?>" class="btn btn-primary">
                <i class="fas fa-edit"></i>
                Edit Project
            </a>
        </div>
    </div>

    <!-- Client Sharing + Scope/Budget info (added 2026-10-03) -->
    <div class="card mb-3">
        <div class="card-body">
            <div class="project-info-grid">
                <div>
                    <h3 class="info-heading"><i class="fas fa-list-check"></i> Project Scope</h3>
                    <?php if (!empty($project['scope'])): ?>
                        <ul class="scope-list">
                            <?php foreach (preg_split('/\r\n|\r|\n/', (string)$project['scope']) as $line):
                                $line = trim($line);
                                if ($line === '') continue; ?>
                                <li><?= e($line) ?></li>
                            <?php endforeach; ?>
                        </ul>
                    <?php else: ?>
                        <p class="text-muted"><em>No scope / requirements defined yet. Add some on the edit page.</em></p>
                    <?php endif; ?>
                </div>
                <div>
                    <h3 class="info-heading"><i class="fas fa-coins"></i> Budget</h3>
                    <?php if ($project['budget_amount'] !== null && $project['currency']): ?>
                        <p class="budget-amount"><?= e(formatMoney($project['budget_amount'], $project['currency'])) ?></p>
                    <?php else: ?>
                        <p class="text-muted"><em>No budget set.</em></p>
                    <?php endif; ?>
                </div>
                <div>
                    <h3 class="info-heading"><i class="fas fa-share-nodes"></i> Client Share</h3>
                    <?php if (!empty($project['share_code'])):
                        $share_url = buildClientShareUrl($project['share_code']); ?>
                        <p class="share-code-label">Project Code</p>
                        <p class="share-code"><code id="shareCode"><?= e($project['share_code']) ?></code></p>
                        <p class="share-url-label">Direct Link</p>
                        <div class="share-url-row">
                            <input type="text" id="shareUrl" readonly value="<?= e($share_url) ?>" class="share-input">
                            <button type="button" class="btn btn-secondary btn-sm" onclick="copyShareUrl()" title="Copy link">
                                <i class="fas fa-copy"></i> Copy
                            </button>
                        </div>
                        <p class="share-hint text-muted"><small>Anyone with this link can view a read-only progress dashboard for this project.</small></p>
                    <?php else: ?>
                        <p class="text-muted"><em>No share code (older project). Recreate the project to generate one.</em></p>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <!-- Project Statistics -->
    <div class="project-stats-grid">
        <div class="stat-card">
            <div class="stat-icon bg-primary">
                <i class="fas fa-chart-pie"></i>
            </div>
            <div class="stat-content">
                <h3><?= number_format($project['avg_completion_percentage'] ?? 0, 1) ?>%</h3>
                <p>Completion</p>
            </div>
        </div>
        
        <div class="stat-card">
            <div class="stat-icon bg-success">
                <i class="fas fa-tasks"></i>
            </div>
            <div class="stat-content">
                <h3><?= $project['total_tasks'] ?? 0 ?></h3>
                <p>Total Tasks</p>
                <?php if ($project['completed_tasks'] > 0): ?>
                    <small><?= $project['completed_tasks'] ?> completed</small>
                <?php endif; ?>
            </div>
        </div>
        
        <div class="stat-card">
            <div class="stat-icon bg-warning">
                <i class="fas fa-clock"></i>
            </div>
            <div class="stat-content">
                <h3><?= $project['uncompleted_tasks'] ?? 0 ?></h3>
                <p>Pending Tasks</p>
            </div>
        </div>
        
        <div class="stat-card">
            <div class="stat-icon bg-info">
                <i class="fas fa-address-book"></i>
            </div>
            <div class="stat-content">
                <h3><?= $project['contact_count'] ?? 0 ?></h3>
                <p>Contacts</p>
            </div>
        </div>
    </div>

    <div class="project-content-grid">
        <!-- Tasks Section -->
        <div class="content-section">
            <div class="section-header">
                <h2>
                    <i class="fas fa-tasks"></i>
                    Tasks
                    <span class="count">(<?= count($tasks) ?>)</span>
                </h2>
                <div class="section-actions">
                    <a href="task_edit.php?project_id=<?= $project['id'] ?>" class="btn btn-primary btn-sm">
                        <i class="fas fa-plus"></i>
                        Add Task
                    </a>
                    <a href="tasks.php?project_id=<?= $project['id'] ?>" class="btn btn-secondary btn-sm">
                        <i class="fas fa-list"></i>
                        View All
                    </a>
                </div>
            </div>
            
            <div class="tasks-list">
                <?php if (empty($tasks)): ?>
                    <div class="empty-state">
                        <i class="fas fa-tasks"></i>
                        <p>No tasks yet. <a href="task_edit.php?project_id=<?= $project['id'] ?>">Create your first task</a></p>
                    </div>
                <?php else: ?>
                    <?php foreach ($tasks as $task): ?>
                        <div class="task-item">
                            <div class="task-info">
                                <h4>
                                    <a href="task_detail.php?id=<?= $task['id'] ?>">
                                        <?= e($task['name']) ?>
                                    </a>
                                </h4>
                                <div class="task-meta">
                                    <span class="status status-<?= $task['status'] ?>">
                                        <?= ucfirst(str_replace('_', ' ', $task['status'])) ?>
                                    </span>
                                    <span class="responsible">
                                        <i class="fas fa-user"></i>
                                        <?= e($task['responsible_person_name']) ?>
                                    </span>
                                    <?php if ($task['contact_person_name']): ?>
                                        <span class="contact">
                                            <i class="fas fa-address-card"></i>
                                            <?= e($task['contact_person_name']) ?>
                                        </span>
                                    <?php endif; ?>
                                    <?php if ($task['expected_completion_date']): ?>
                                        <span class="deadline">
                                            <i class="fas fa-calendar"></i>
                                            <?= formatDateTime($task['expected_completion_date'], 'M j, Y') ?>
                                        </span>
                                    <?php endif; ?>
                                </div>
                                <?php if ($task['subtask_count'] > 0): ?>
                                    <div class="subtask-info">
                                        <i class="fas fa-sitemap"></i>
                                        <?= $task['subtask_count'] ?> subtasks
                                    </div>
                                <?php endif; ?>
                            </div>
                            <div class="task-progress">
                                <div class="progress-circle" data-percentage="<?= $task['completion_percentage'] ?>">
                                    <span><?= number_format($task['completion_percentage'], 0) ?>%</span>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>

        <!-- Contacts Section -->
        <div class="content-section">
            <div class="section-header">
                <h2>
                    <i class="fas fa-address-book"></i>
                    Project Contacts
                    <span class="count">(<?= count($project_contacts) ?>)</span>
                </h2>
                <div class="section-actions">
                    <a href="contact_edit.php?project_id=<?= $project['id'] ?>" class="btn btn-primary btn-sm">
                        <i class="fas fa-plus"></i>
                        Add Contact
                    </a>
                    <a href="contacts.php?project_id=<?= $project['id'] ?>" class="btn btn-secondary btn-sm">
                        <i class="fas fa-list"></i>
                        View All
                    </a>
                </div>
            </div>
            
            <div class="contacts-list">
                <?php if (empty($project_contacts)): ?>
                    <div class="empty-state">
                        <i class="fas fa-address-book"></i>
                        <p>No contacts assigned. <a href="project_contact_edit.php?project_id=<?= $project['id'] ?>">Add a contact</a></p>
                    </div>
                <?php else: ?>
                    <?php foreach ($project_contacts as $contact): ?>
                        <div class="contact-item">
                            <div class="contact-avatar">
                                <i class="fas fa-user"></i>
                            </div>
                            <div class="contact-info">
                                <h5>
                                    <a href="contact_detail.php?id=<?= $contact['id'] ?>">
                                        <?= e($contact['name']) ?>
                                    </a>
                                </h5>
                                <?php if ($contact['description']): ?>
                                    <p class="contact-description"><?= e(substr($contact['description'], 0, 100)) ?><?= strlen($contact['description']) > 100 ? '...' : '' ?></p>
                                <?php endif; ?>
                                <div class="contact-methods">
                                    <?php if ($contact['email']): ?>
                                        <a href="mailto:<?= e($contact['email']) ?>" class="contact-method" title="Email">
                                            <i class="fas fa-envelope"></i>
                                        </a>
                                    <?php endif; ?>
                                    <?php if ($contact['mobile']): ?>
                                        <a href="tel:<?= e($contact['mobile']) ?>" class="contact-method" title="Phone">
                                            <i class="fas fa-phone"></i>
                                        </a>
                                    <?php endif; ?>
                                    <?php if ($contact['wechat']): ?>
                                        <span class="contact-method" title="WeChat: <?= e($contact['wechat']) ?>">
                                            <i class="fab fa-weixin"></i>
                                        </span>
                                    <?php endif; ?>
                                    <?php if ($contact['linkedin']): ?>
                                        <a href="<?= e($contact['linkedin']) ?>" class="contact-method" title="LinkedIn" target="_blank">
                                            <i class="fab fa-linkedin"></i>
                                        </a>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>

        <!-- Recent Activity -->
        <div class="content-section full-width">
            <div class="section-header">
                <h2>
                    <i class="fas fa-history"></i>
                    Recent Activity
                </h2>
            </div>
            
            <div class="activity-list">
                <?php if (empty($recent_activity)): ?>
                    <div class="empty-state">
                        <i class="fas fa-history"></i>
                        <p>No recent activity</p>
                    </div>
                <?php else: ?>
                    <?php foreach ($recent_activity as $activity): ?>
                        <div class="activity-item">
                            <div class="activity-icon">
                                <i class="fas fa-edit"></i>
                            </div>
                            <div class="activity-content">
                                <p>
                                    <strong><?= e($activity['responsible_person_name']) ?></strong>
                                    updated task
                                    <a href="task_detail.php?id=<?= $activity['id'] ?>"><?= e($activity['name']) ?></a>
                                    to <span class="status status-<?= $activity['status'] ?>"><?= ucfirst(str_replace('_', ' ', $activity['status'])) ?></span>
                                </p>
                                <time><?= formatDateTime($activity['updated_at'], 'M j, Y g:i A') ?></time>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<style>
.project-detail-container {
    max-width: 1200px;
    margin: 0 auto;
    padding: 20px;
}

.project-header {
    background: white;
    padding: 30px;
    border-radius: 12px;
    box-shadow: 0 2px 12px rgba(0,0,0,0.08);
    margin-bottom: 30px;
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
}

.project-breadcrumb {
    margin-bottom: 15px;
    color: #666;
    font-size: 14px;
}

.project-breadcrumb a {
    color: #007bff;
    text-decoration: none;
}

.project-breadcrumb span {
    margin: 0 8px;
    color: #ccc;
}

.project-header h1 {
    margin: 0 0 10px 0;
    color: #333;
    font-size: 2rem;
}

.project-description {
    margin: 0 0 20px 0;
    color: #666;
    font-size: 1.1rem;
    line-height: 1.6;
}

.project-meta {
    display: flex;
    gap: 20px;
    align-items: center;
    flex-wrap: wrap;
}

.project-meta > span {
    display: flex;
    align-items: center;
    gap: 6px;
    font-size: 0.9rem;
}

.project-stats-grid {
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

.stat-content small {
    display: block;
    margin-top: 5px;
    color: #999;
    font-size: 0.8rem;
}

.project-content-grid {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 30px;
}

.content-section.full-width {
    grid-column: 1 / -1;
}

.content-section {
    background: white;
    border-radius: 12px;
    box-shadow: 0 2px 12px rgba(0,0,0,0.08);
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
    display: flex;
    align-items: center;
    gap: 10px;
}

.count {
    font-size: 0.9rem;
    color: #666;
    font-weight: normal;
}

.section-actions {
    display: flex;
    gap: 10px;
}

.tasks-list, .contacts-list, .activity-list {
    padding: 0;
}

.task-item, .contact-item, .activity-item {
    padding: 20px 25px;
    border-bottom: 1px solid #f1f3f4;
    display: flex;
    justify-content: space-between;
    align-items: center;
}

.task-item:last-child, .contact-item:last-child, .activity-item:last-child {
    border-bottom: none;
}

.task-info h4 {
    margin: 0 0 10px 0;
    font-size: 1.1rem;
}

.task-info h4 a {
    color: #333;
    text-decoration: none;
}

.task-info h4 a:hover {
    color: #007bff;
}

.task-meta, .contact-methods {
    display: flex;
    gap: 15px;
    align-items: center;
    margin-top: 8px;
}

.task-meta > span, .contact-methods > span, .contact-methods > a {
    display: flex;
    align-items: center;
    gap: 5px;
    font-size: 0.85rem;
    color: #666;
}

.subtask-info {
    margin-top: 8px;
    color: #666;
    font-size: 0.85rem;
}

.progress-circle {
    width: 50px;
    height: 50px;
    border-radius: 50%;
    border: 3px solid #e9ecef;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 0.8rem;
    font-weight: 600;
    color: #007bff;
    position: relative;
}

.progress-circle[data-percentage]:before {
    content: '';
    position: absolute;
    top: -3px;
    left: -3px;
    width: 50px;
    height: 50px;
    border-radius: 50%;
    border: 3px solid transparent;
    border-top-color: #007bff;
    transform: rotate(calc(var(--percentage, 0) * 3.6deg));
}

.contact-item {
    align-items: flex-start;
}

.contact-avatar {
    width: 40px;
    height: 40px;
    border-radius: 50%;
    background: #f8f9fa;
    display: flex;
    align-items: center;
    justify-content: center;
    margin-right: 15px;
    color: #666;
}

.contact-info {
    flex: 1;
}

.contact-info h5 {
    margin: 0 0 8px 0;
    font-size: 1rem;
}

.contact-info h5 a {
    color: #333;
    text-decoration: none;
}

.contact-info h5 a:hover {
    color: #007bff;
}

.contact-description {
    margin: 0 0 10px 0;
    color: #666;
    font-size: 0.9rem;
    line-height: 1.4;
}

.contact-method {
    width: 30px;
    height: 30px;
    border-radius: 50%;
    background: #f8f9fa;
    display: flex;
    align-items: center;
    justify-content: center;
    color: #666;
    text-decoration: none;
    transition: all 0.3s;
}

.contact-method:hover {
    background: #007bff;
    color: white;
}

.activity-item {
    align-items: flex-start;
}

.activity-icon {
    width: 35px;
    height: 35px;
    border-radius: 50%;
    background: #f8f9fa;
    display: flex;
    align-items: center;
    justify-content: center;
    margin-right: 15px;
    color: #666;
    font-size: 14px;
}

.activity-content {
    flex: 1;
}

.activity-content p {
    margin: 0 0 5px 0;
    line-height: 1.4;
}

.activity-content time {
    color: #999;
    font-size: 0.85rem;
}

.empty-state {
    padding: 40px 25px;
    text-align: center;
    color: #666;
}

.empty-state i {
    font-size: 2.5rem;
    margin-bottom: 15px;
    color: #dee2e6;
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

@media (max-width: 768px) {
    .project-detail-container {
        padding: 15px;
    }
    
    .project-header {
        flex-direction: column;
        gap: 20px;
        align-items: stretch;
    }
    
    .project-stats-grid {
        grid-template-columns: repeat(2, 1fr);
        gap: 15px;
    }
    
    .project-content-grid {
        grid-template-columns: 1fr;
        gap: 20px;
    }
    
    .section-header {
        flex-direction: column;
        gap: 15px;
        align-items: stretch;
    }
    
    .task-item, .contact-item {
        flex-direction: column;
        align-items: flex-start;
        gap: 15px;
    }
    
    .progress-circle {
        align-self: flex-end;
    }
}
</style>

<style>
/* Client sharing section (added 2026-10-03) */
.project-info-grid {
    display: grid;
    grid-template-columns: 1fr 1fr 1fr;
    gap: 30px;
}
.info-heading {
    margin: 0 0 12px 0;
    color: #333;
    font-size: 1.05rem;
    border-bottom: 2px solid #f1f3f5;
    padding-bottom: 8px;
}
.info-heading i {
    color: #007bff;
    margin-right: 8px;
}
.scope-list {
    margin: 0;
    padding-left: 18px;
    color: #444;
}
.scope-list li {
    margin: 4px 0;
    line-height: 1.5;
}
.budget-amount {
    margin: 0;
    font-size: 1.4rem;
    font-weight: 600;
    color: #28a745;
}
.share-code-label, .share-url-label {
    margin: 0 0 4px 0;
    font-size: 0.85rem;
    color: #666;
    text-transform: uppercase;
    letter-spacing: 0.04em;
}
.share-code {
    margin: 0 0 14px 0;
}
.share-code code {
    font-size: 1.15rem;
    background: #f1f3f5;
    padding: 6px 12px;
    border-radius: 6px;
    letter-spacing: 0.04em;
    color: #007bff;
    font-weight: 600;
}
.share-url-row {
    display: flex;
    gap: 8px;
    margin-bottom: 8px;
}
.share-input {
    flex: 1;
    font-family: monospace;
    font-size: 0.85rem;
    padding: 8px 10px;
    border: 1px solid #ced4da;
    border-radius: 6px;
    background: #f8f9fa;
}
.share-hint {
    margin: 0;
}
@media (max-width: 768px) {
    .project-info-grid {
        grid-template-columns: 1fr;
        gap: 20px;
    }
}
</style>

<script>
// Set progress circle percentages
document.addEventListener('DOMContentLoaded', function() {
    const progressCircles = document.querySelectorAll('.progress-circle[data-percentage]');

    progressCircles.forEach(circle => {
        const percentage = circle.getAttribute('data-percentage');
        circle.style.setProperty('--percentage', percentage);

        // Add color based on percentage
        if (percentage >= 80) {
            circle.style.borderColor = '#28a745';
            circle.style.color = '#28a745';
        } else if (percentage >= 50) {
            circle.style.borderColor = '#ffc107';
            circle.style.color = '#ffc107';
        } else {
            circle.style.borderColor = '#dc3545';
            circle.style.color = '#dc3545';
        }
    });
});

// Copy the client share URL (added 2026-10-03)
function copyShareUrl() {
    var input = document.getElementById('shareUrl');
    if (!input) return;
    input.select();
    input.setSelectionRange(0, 99999);
    var ok = false;
    try { ok = document.execCommand('copy'); } catch (e) {}
    if (navigator.clipboard && !ok) {
        navigator.clipboard.writeText(input.value).catch(function(){});
    }
    // Tiny visual feedback
    var btn = document.activeElement;
    if (btn && btn.tagName === 'BUTTON') {
        var original = btn.innerHTML;
        btn.innerHTML = '<i class="fas fa-check"></i> Copied';
        setTimeout(function() { btn.innerHTML = original; }, 1500);
    }
}
</script>

<?php include 'includes/footer.php'; ?>