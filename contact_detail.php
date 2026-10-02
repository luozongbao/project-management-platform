<?php
require_once 'config.php';
require_once 'includes/Database.php';
require_once 'includes/functions.php';

requireLogin();

$db = Database::getInstance();
$user_id = getCurrentUserId();
$contact_id = $_GET['id'] ?? null;

if (!$contact_id) {
    redirect('contacts.php');
}

// Get contact details with project information
$contact = $db->fetchOne(
    "SELECT DISTINCT c.*
     FROM contacts c
     JOIN project_contacts pc ON c.id = pc.contact_id
     JOIN projects p ON pc.project_id = p.id
     WHERE c.id = ? AND p.responsible_person_id = ?",
    [$contact_id, $user_id]
);

if (!$contact) {
    redirect('contacts.php');
}

// Get projects associated with this contact
$projects = $db->fetchAll(
    "SELECT DISTINCT p.id, p.name, p.status, p.created_at
     FROM projects p
     JOIN project_contacts pc ON p.id = pc.project_id
     WHERE pc.contact_id = ? AND p.responsible_person_id = ?
     ORDER BY p.name",
    [$contact_id, $user_id]
);

// Get tasks associated with this contact
$tasks = $db->fetchAll(
    "SELECT t.id, t.name, t.status, t.completion_percentage, t.created_at, p.name as project_name, p.id as project_id
     FROM tasks t
     JOIN projects p ON t.project_id = p.id
     WHERE t.contact_person_id = ? AND p.responsible_person_id = ?
     ORDER BY t.created_at DESC
     LIMIT 20",
    [$contact_id, $user_id]
);

// Get contact statistics
$contact_stats = $db->fetchOne(
    "SELECT 
        COUNT(DISTINCT pc.project_id) as project_count,
        COUNT(DISTINCT t.id) as task_count,
        COUNT(DISTINCT CASE WHEN t.status = 'completed' THEN t.id END) as completed_tasks
     FROM project_contacts pc
     JOIN projects p ON pc.project_id = p.id
     LEFT JOIN tasks t ON t.contact_person_id = pc.contact_id
     WHERE pc.contact_id = ? AND p.responsible_person_id = ?",
    [$contact_id, $user_id]
);

$title = "Contact: " . $contact['name'];
$show_nav = true;
?>

<?php include 'includes/header.php'; ?>

<div class="page-container">
    <div class="page-header">
        <div class="page-title">
            <div class="breadcrumb">
                <a href="dashboard.php">Dashboard</a>
                <span>/</span>
                <a href="contacts.php">Contacts</a>
                <span>/</span>
                <span><?= e($contact['name']) ?></span>
            </div>
            <h1>
                <i class="fas fa-user"></i>
                <?= e($contact['name']) ?>
            </h1>
        </div>
        <div class="page-actions">
            <a href="contact_edit.php?id=<?= $contact_id ?>" class="btn btn-primary">
                <i class="fas fa-edit"></i>
                Edit Contact
            </a>
            <?php if ($contact['email']): ?>
                <a href="mailto:<?= e($contact['email']) ?>" class="btn btn-secondary">
                    <i class="fas fa-envelope"></i>
                    Send Email
                </a>
            <?php endif; ?>
            <?php if ($contact['phone']): ?>
                <a href="tel:<?= e($contact['phone']) ?>" class="btn btn-secondary">
                    <i class="fas fa-phone"></i>
                    Call
                </a>
            <?php endif; ?>
        </div>
    </div>

    <div class="contact-detail-layout">
        <!-- Main Contact Information -->
        <div class="main-content">
            <!-- Contact Overview Card -->
            <div class="card contact-overview-card">
                <div class="card-header">
                    <h3>Contact Overview</h3>
                </div>
                <div class="card-body">
                    <div class="contact-avatar-section">
                        <div class="contact-avatar-large">
                            <i class="fas fa-user"></i>
                        </div>
                        <div class="contact-basic-info">
                            <h2><?= e($contact['name']) ?></h2>
                            <?php if ($contact['position']): ?>
                                <p class="position"><?= e($contact['position']) ?></p>
                            <?php endif; ?>
                            <?php if ($contact['company']): ?>
                                <p class="company">
                                    <i class="fas fa-building"></i>
                                    <?= e($contact['company']) ?>
                                </p>
                            <?php endif; ?>
                        </div>
                    </div>
                    
                    <?php if ($contact['description']): ?>
                        <div class="description-section">
                            <h4>Description</h4>
                            <div class="description-content">
                                <?= nl2br(e($contact['description'])) ?>
                            </div>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Contact Statistics -->
            <div class="contact-stats-grid">
                <div class="stat-card">
                    <div class="stat-icon bg-primary">
                        <i class="fas fa-folder"></i>
                    </div>
                    <div class="stat-content">
                        <h3><?= $contact_stats['project_count'] ?? 0 ?></h3>
                        <p>Projects</p>
                    </div>
                </div>
                
                <div class="stat-card">
                    <div class="stat-icon bg-info">
                        <i class="fas fa-tasks"></i>
                    </div>
                    <div class="stat-content">
                        <h3><?= $contact_stats['task_count'] ?? 0 ?></h3>
                        <p>Total Tasks</p>
                    </div>
                </div>
                
                <div class="stat-card">
                    <div class="stat-icon bg-success">
                        <i class="fas fa-check-circle"></i>
                    </div>
                    <div class="stat-content">
                        <h3><?= $contact_stats['completed_tasks'] ?? 0 ?></h3>
                        <p>Completed</p>
                    </div>
                </div>
            </div>

            <!-- Recent Tasks -->
            <?php if (!empty($tasks)): ?>
                <div class="card">
                    <div class="card-header">
                        <h3>Recent Tasks</h3>
                    </div>
                    <div class="card-body">
                        <div class="tasks-list">
                            <?php foreach ($tasks as $task): ?>
                                <div class="task-item">
                                    <div class="task-header">
                                        <div class="task-info">
                                            <h4>
                                                <a href="task_detail.php?id=<?= $task['id'] ?>">
                                                    <?= e($task['name']) ?>
                                                </a>
                                            </h4>
                                            <div class="task-meta">
                                                <span class="project-name">
                                                    <i class="fas fa-folder"></i>
                                                    <a href="project_detail.php?id=<?= $task['project_id'] ?>">
                                                        <?= e($task['project_name']) ?>
                                                    </a>
                                                </span>
                                                <span class="task-date">
                                                    <i class="fas fa-calendar"></i>
                                                    <?= formatDateTime($task['created_at'], 'M j, Y') ?>
                                                </span>
                                            </div>
                                        </div>
                                        <div class="task-status">
                                            <span class="status status-<?= $task['status'] ?>">
                                                <?= ucfirst(str_replace('_', ' ', $task['status'])) ?>
                                            </span>
                                            <div class="progress-mini">
                                                <div class="progress-bar">
                                                    <div class="progress-fill" style="width: <?= $task['completion_percentage'] ?>%"></div>
                                                </div>
                                                <span class="progress-text"><?= number_format($task['completion_percentage'], 0) ?>%</span>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                        
                        <?php if (count($tasks) >= 20): ?>
                            <div class="view-all-link">
                                <a href="tasks.php?contact_id=<?= $contact_id ?>" class="btn btn-outline">
                                    <i class="fas fa-list"></i>
                                    View All Tasks
                                </a>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endif; ?>
        </div>

        <!-- Sidebar -->
        <div class="sidebar">
            <!-- Contact Details -->
            <div class="card">
                <div class="card-header">
                    <h3>Contact Information</h3>
                </div>
                <div class="card-body">
                    <?php if ($contact['email']): ?>
                        <div class="detail-item">
                            <label>Email:</label>
                            <a href="mailto:<?= e($contact['email']) ?>" class="contact-link">
                                <i class="fas fa-envelope"></i>
                                <?= e($contact['email']) ?>
                            </a>
                        </div>
                    <?php endif; ?>
                    
                    <?php if ($contact['phone']): ?>
                        <div class="detail-item">
                            <label>Phone:</label>
                            <a href="tel:<?= e($contact['phone']) ?>" class="contact-link">
                                <i class="fas fa-phone"></i>
                                <?= e($contact['phone']) ?>
                            </a>
                        </div>
                    <?php endif; ?>
                    
                    <?php if ($contact['mobile']): ?>
                        <div class="detail-item">
                            <label>Mobile:</label>
                            <a href="tel:<?= e($contact['mobile']) ?>" class="contact-link">
                                <i class="fas fa-mobile-alt"></i>
                                <?= e($contact['mobile']) ?>
                            </a>
                        </div>
                    <?php endif; ?>
                    
                    <?php if ($contact['address']): ?>
                        <div class="detail-item">
                            <label>Address:</label>
                            <div class="address-content">
                                <i class="fas fa-map-marker-alt"></i>
                                <?= nl2br(e($contact['address'])) ?>
                            </div>
                        </div>
                    <?php endif; ?>
                    
                    <div class="detail-item">
                        <label>Added:</label>
                        <span><?= formatDateTime($contact['created_at']) ?></span>
                    </div>
                    
                    <?php if ($contact['updated_at'] != $contact['created_at']): ?>
                        <div class="detail-item">
                            <label>Updated:</label>
                            <span><?= formatDateTime($contact['updated_at']) ?></span>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Social Media -->
            <?php if ($contact['wechat'] || $contact['line_id'] || $contact['facebook'] || $contact['linkedin']): ?>
                <div class="card">
                    <div class="card-header">
                        <h3>Social Media</h3>
                    </div>
                    <div class="card-body">
                        <?php if ($contact['wechat']): ?>
                            <div class="detail-item">
                                <label>WeChat:</label>
                                <span class="social-link">
                                    <i class="fab fa-weixin"></i>
                                    <?= e($contact['wechat']) ?>
                                </span>
                            </div>
                        <?php endif; ?>
                        
                        <?php if ($contact['line_id']): ?>
                            <div class="detail-item">
                                <label>LINE:</label>
                                <span class="social-link">
                                    <i class="fab fa-line"></i>
                                    <?= e($contact['line_id']) ?>
                                </span>
                            </div>
                        <?php endif; ?>
                        
                        <?php if ($contact['facebook']): ?>
                            <div class="detail-item">
                                <label>Facebook:</label>
                                <a href="<?= e($contact['facebook']) ?>" class="social-link" target="_blank">
                                    <i class="fab fa-facebook"></i>
                                    View Profile
                                </a>
                            </div>
                        <?php endif; ?>
                        
                        <?php if ($contact['linkedin']): ?>
                            <div class="detail-item">
                                <label>LinkedIn:</label>
                                <a href="<?= e($contact['linkedin']) ?>" class="social-link" target="_blank">
                                    <i class="fab fa-linkedin"></i>
                                    View Profile
                                </a>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endif; ?>

            <!-- Associated Projects -->
            <div class="card">
                <div class="card-header">
                    <h3>Projects (<?= count($projects) ?>)</h3>
                </div>
                <div class="card-body">
                    <?php if (!empty($projects)): ?>
                        <div class="projects-list">
                            <?php foreach ($projects as $project): ?>
                                <div class="project-item">
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
                                            <span class="project-date">
                                                <i class="fas fa-calendar"></i>
                                                <?= formatDateTime($project['created_at'], 'M j, Y') ?>
                                            </span>
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php else: ?>
                        <p class="no-projects">No projects associated with this contact.</p>
                    <?php endif; ?>
                </div>
            </div>
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

.contact-detail-layout {
    display: grid;
    grid-template-columns: 2fr 1fr;
    gap: 30px;
    margin-top: 20px;
}

.contact-overview-card .card-body {
    padding: 30px;
}

.contact-avatar-section {
    display: flex;
    gap: 20px;
    align-items: center;
    margin-bottom: 30px;
}

.contact-avatar-large {
    width: 80px;
    height: 80px;
    background: linear-gradient(45deg, #007bff, #0056b3);
    border-radius: 40px;
    display: flex;
    align-items: center;
    justify-content: center;
    color: white;
    font-size: 32px;
    flex-shrink: 0;
}

.contact-basic-info h2 {
    margin: 0 0 8px 0;
    color: #333;
    font-size: 2rem;
}

.contact-basic-info .position {
    margin: 0 0 5px 0;
    color: #666;
    font-weight: 500;
    font-size: 1.1rem;
}

.contact-basic-info .company {
    margin: 0;
    color: #666;
    display: flex;
    align-items: center;
    gap: 8px;
    font-size: 1rem;
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

.contact-stats-grid {
    display: grid;
    grid-template-columns: repeat(3, 1fr);
    gap: 20px;
    margin-bottom: 30px;
}

.stat-card {
    background: white;
    padding: 20px;
    border-radius: 12px;
    box-shadow: 0 2px 12px rgba(0,0,0,0.08);
    display: flex;
    align-items: center;
    gap: 15px;
}

.stat-icon {
    width: 45px;
    height: 45px;
    border-radius: 8px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 18px;
    color: white;
}

.stat-icon.bg-primary { background: linear-gradient(45deg, #007bff, #0056b3); }
.stat-icon.bg-success { background: linear-gradient(45deg, #28a745, #1e7e34); }
.stat-icon.bg-info { background: linear-gradient(45deg, #17a2b8, #117a8b); }

.stat-content h3 {
    margin: 0 0 5px 0;
    font-size: 1.5rem;
    font-weight: 700;
    color: #333;
}

.stat-content p {
    margin: 0;
    color: #666;
    font-weight: 500;
}

.tasks-list {
    display: flex;
    flex-direction: column;
    gap: 15px;
}

.task-item {
    padding: 15px;
    background: #f8f9fa;
    border-radius: 8px;
    border-left: 4px solid #007bff;
}

.task-header {
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    gap: 15px;
}

.task-info h4 {
    margin: 0 0 8px 0;
    font-size: 1.1rem;
}

.task-info h4 a {
    color: #333;
    text-decoration: none;
}

.task-info h4 a:hover {
    color: #007bff;
}

.task-meta {
    display: flex;
    gap: 15px;
    font-size: 0.85rem;
    color: #666;
}

.project-name a,
.task-date {
    display: flex;
    align-items: center;
    gap: 5px;
}

.project-name a {
    color: #007bff;
    text-decoration: none;
}

.project-name a:hover {
    text-decoration: underline;
}

.task-status {
    display: flex;
    flex-direction: column;
    gap: 8px;
    align-items: flex-end;
}

.progress-mini {
    display: flex;
    align-items: center;
    gap: 8px;
}

.progress-mini .progress-bar {
    width: 60px;
    height: 6px;
    background: #e9ecef;
    border-radius: 3px;
    overflow: hidden;
}

.progress-mini .progress-fill {
    height: 100%;
    background: linear-gradient(90deg, #007bff, #0056b3);
    border-radius: 3px;
}

.progress-text {
    font-size: 0.8rem;
    color: #666;
    font-weight: 500;
}

.view-all-link {
    margin-top: 20px;
    text-align: center;
    padding-top: 20px;
    border-top: 1px solid #dee2e6;
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

.contact-link {
    display: flex;
    align-items: center;
    gap: 8px;
    color: #007bff;
    text-decoration: none;
    font-size: 0.9rem;
}

.contact-link:hover {
    text-decoration: underline;
}

.social-link {
    display: flex;
    align-items: center;
    gap: 8px;
    color: #666;
    text-decoration: none;
    font-size: 0.9rem;
}

.social-link:hover {
    color: #007bff;
}

.address-content {
    display: flex;
    align-items: flex-start;
    gap: 8px;
    color: #666;
    font-size: 0.9rem;
    line-height: 1.4;
}

.projects-list {
    display: flex;
    flex-direction: column;
    gap: 15px;
}

.project-item {
    padding: 15px;
    background: #f8f9fa;
    border-radius: 8px;
    border-left: 4px solid #007bff;
}

.project-info h4 {
    margin: 0 0 8px 0;
    font-size: 1rem;
}

.project-info h4 a {
    color: #333;
    text-decoration: none;
}

.project-info h4 a:hover {
    color: #007bff;
}

.project-meta {
    display: flex;
    gap: 15px;
    align-items: center;
    font-size: 0.85rem;
    color: #666;
}

.project-date {
    display: flex;
    align-items: center;
    gap: 5px;
}

.no-projects {
    color: #666;
    font-style: italic;
    text-align: center;
    margin: 0;
}

@media (max-width: 768px) {
    .page-container {
        padding: 15px;
    }
    
    .contact-detail-layout {
        grid-template-columns: 1fr;
        gap: 20px;
    }
    
    .contact-avatar-section {
        flex-direction: column;
        text-align: center;
    }
    
    .contact-stats-grid {
        grid-template-columns: 1fr;
        gap: 15px;
    }
    
    .task-header {
        flex-direction: column;
        gap: 10px;
        align-items: stretch;
    }
    
    .task-status {
        align-items: stretch;
    }
    
    .progress-mini {
        justify-content: space-between;
    }
    
    .progress-mini .progress-bar {
        flex: 1;
        max-width: 120px;
    }
}
</style>

<?php include 'includes/footer.php'; ?>