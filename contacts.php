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
    $where_conditions[] = "pc.project_id = ?";
    $params[] = $project_id;
}

// Search functionality
$search = $_GET['search'] ?? '';
if ($search) {
    $where_conditions[] = "(c.name LIKE ? OR c.email LIKE ? OR c.phone LIKE ? OR c.company LIKE ?)";
    $search_param = '%' . $search . '%';
    $params[] = $search_param;
    $params[] = $search_param;
    $params[] = $search_param;
    $params[] = $search_param;
}

$where_clause = "WHERE " . implode(" AND ", $where_conditions);

// Get contacts with project information
$contacts = $db->fetchAll(
    "SELECT DISTINCT c.*, 
            COUNT(DISTINCT pc.project_id) as project_count,
            GROUP_CONCAT(DISTINCT p.name SEPARATOR ', ') as project_names,
            COUNT(DISTINCT t.id) as task_count
     FROM contacts c
     JOIN project_contacts pc ON c.id = pc.contact_id
     JOIN projects p ON pc.project_id = p.id
     LEFT JOIN tasks t ON c.id = t.contact_person_id
     $where_clause
     GROUP BY c.id
     ORDER BY c.name ASC",
    $params
);

// Get projects for filter dropdown
$user_projects = $db->fetchAll(
    "SELECT id, name FROM projects WHERE responsible_person_id = ? ORDER BY name",
    [$user_id]
);

// Get contact statistics
$contact_stats = $db->fetchOne(
    "SELECT 
        COUNT(DISTINCT c.id) as total_contacts,
        COUNT(DISTINCT CASE WHEN c.email != '' THEN c.id END) as contacts_with_email,
        COUNT(DISTINCT CASE WHEN c.phone != '' THEN c.id END) as contacts_with_phone,
        COUNT(DISTINCT pc.project_id) as projects_with_contacts
     FROM contacts c
     JOIN project_contacts pc ON c.id = pc.contact_id
     JOIN projects p ON pc.project_id = p.id
     WHERE p.responsible_person_id = ?",
    [$user_id]
);

$title = "Contacts";
$show_nav = true;
?>

<?php include 'includes/header.php'; ?>

<div class="page-container">
    <div class="page-header">
        <div class="page-title">
            <h1>
                <i class="fas fa-address-book"></i>
                Contacts
                <?php if ($project_id): ?>
                    <?php 
                    $project_name = $db->fetchOne("SELECT name FROM projects WHERE id = ?", [$project_id])['name'];
                    ?>
                    <span class="project-context">- <?= e($project_name) ?></span>
                <?php endif; ?>
            </h1>
            <p>Manage your project contacts and communication</p>
        </div>
        <div class="page-actions">
            <a href="contact_edit.php<?= $project_id ? '?project_id=' . $project_id : '' ?>" class="btn btn-primary">
                <i class="fas fa-plus"></i>
                New Contact
            </a>
        </div>
    </div>

    <!-- Contact Statistics -->
    <div class="contact-stats-grid">
        <div class="stat-card">
            <div class="stat-icon bg-primary">
                <i class="fas fa-users"></i>
            </div>
            <div class="stat-content">
                <h3><?= $contact_stats['total_contacts'] ?? 0 ?></h3>
                <p>Total Contacts</p>
            </div>
        </div>
        
        <div class="stat-card">
            <div class="stat-icon bg-success">
                <i class="fas fa-envelope"></i>
            </div>
            <div class="stat-content">
                <h3><?= $contact_stats['contacts_with_email'] ?? 0 ?></h3>
                <p>With Email</p>
            </div>
        </div>
        
        <div class="stat-card">
            <div class="stat-icon bg-info">
                <i class="fas fa-phone"></i>
            </div>
            <div class="stat-content">
                <h3><?= $contact_stats['contacts_with_phone'] ?? 0 ?></h3>
                <p>With Phone</p>
            </div>
        </div>
        
        <div class="stat-card">
            <div class="stat-icon bg-warning">
                <i class="fas fa-folder"></i>
            </div>
            <div class="stat-content">
                <h3><?= $contact_stats['projects_with_contacts'] ?? 0 ?></h3>
                <p>Active Projects</p>
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
                        <label for="search">Search Contacts:</label>
                        <input type="text" id="search" name="search" 
                               value="<?= e($search) ?>" 
                               placeholder="Search by name, email, phone, or company...">
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
                    
                    <div class="filter-actions">
                        <button type="submit" class="btn btn-primary btn-sm">
                            <i class="fas fa-search"></i>
                            Filter
                        </button>
                        <a href="contacts.php<?= $project_id ? '?project_id=' . $project_id : '' ?>" class="btn btn-secondary btn-sm">
                            <i class="fas fa-times"></i>
                            Clear
                        </a>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Contacts List -->
    <div class="contacts-container">
        <?php if (empty($contacts)): ?>
            <div class="empty-state">
                <div class="empty-icon">
                    <i class="fas fa-address-book"></i>
                </div>
                <h3>No Contacts Found</h3>
                <p>
                    <?php if ($search || $project_id): ?>
                        No contacts match your current filters. Try adjusting your search criteria.
                    <?php else: ?>
                        You haven't added any contacts yet. Create your first contact to get started!
                    <?php endif; ?>
                </p>
                <a href="contact_edit.php<?= $project_id ? '?project_id=' . $project_id : '' ?>" class="btn btn-primary">
                    <i class="fas fa-plus"></i>
                    Add First Contact
                </a>
            </div>
        <?php else: ?>
            <div class="contacts-grid">
                <?php foreach ($contacts as $contact): ?>
                    <div class="contact-card">
                        <div class="contact-header">
                            <div class="contact-avatar">
                                <i class="fas fa-user"></i>
                            </div>
                            <div class="contact-info">
                                <h3>
                                    <a href="contact_detail.php?id=<?= $contact['id'] ?>">
                                        <?= e($contact['name']) ?>
                                    </a>
                                </h3>
                                <?php if ($contact['position']): ?>
                                    <p class="contact-position"><?= e($contact['position']) ?></p>
                                <?php endif; ?>
                                <?php if ($contact['company']): ?>
                                    <p class="contact-company">
                                        <i class="fas fa-building"></i>
                                        <?= e($contact['company']) ?>
                                    </p>
                                <?php endif; ?>
                            </div>
                            <div class="contact-actions">
                                <div class="action-buttons">
                                    <?php if ($contact['email']): ?>
                                        <a href="mailto:<?= e($contact['email']) ?>" class="btn btn-sm btn-outline" title="Send Email">
                                            <i class="fas fa-envelope"></i>
                                        </a>
                                    <?php endif; ?>
                                    <?php if ($contact['phone']): ?>
                                        <a href="tel:<?= e($contact['phone']) ?>" class="btn btn-sm btn-outline" title="Call">
                                            <i class="fas fa-phone"></i>
                                        </a>
                                    <?php endif; ?>
                                    <a href="contact_edit.php?id=<?= $contact['id'] ?>" class="btn btn-sm btn-outline" title="Edit">
                                        <i class="fas fa-edit"></i>
                                    </a>
                                </div>
                            </div>
                        </div>
                        
                        <div class="contact-details">
                            <div class="detail-row">
                                <?php if ($contact['email']): ?>
                                    <span class="detail-item">
                                        <i class="fas fa-envelope"></i>
                                        <a href="mailto:<?= e($contact['email']) ?>"><?= e($contact['email']) ?></a>
                                    </span>
                                <?php endif; ?>
                                
                                <?php if ($contact['phone']): ?>
                                    <span class="detail-item">
                                        <i class="fas fa-phone"></i>
                                        <a href="tel:<?= e($contact['phone']) ?>"><?= e($contact['phone']) ?></a>
                                    </span>
                                <?php endif; ?>
                            </div>
                            
                            <?php if ($contact['address']): ?>
                                <div class="detail-row">
                                    <span class="detail-item address">
                                        <i class="fas fa-map-marker-alt"></i>
                                        <?= e($contact['address']) ?>
                                    </span>
                                </div>
                            <?php endif; ?>
                        </div>
                        
                        <div class="contact-meta">
                            <div class="meta-item">
                                <i class="fas fa-folder"></i>
                                <span><?= $contact['project_count'] ?> project<?= $contact['project_count'] != 1 ? 's' : '' ?></span>
                            </div>
                            
                            <div class="meta-item">
                                <i class="fas fa-tasks"></i>
                                <span><?= $contact['task_count'] ?> task<?= $contact['task_count'] != 1 ? 's' : '' ?></span>
                            </div>
                            
                            <div class="meta-item">
                                <i class="fas fa-calendar"></i>
                                <span>Added <?= formatDateTime($contact['created_at'], 'M j, Y') ?></span>
                            </div>
                        </div>
                        
                        <?php if (!$project_id && $contact['project_names']): ?>
                            <div class="contact-projects">
                                <h4>Projects:</h4>
                                <div class="project-tags">
                                    <?php 
                                    $project_list = explode(', ', $contact['project_names']);
                                    foreach (array_slice($project_list, 0, 3) as $project_name): 
                                    ?>
                                        <span class="project-tag"><?= e($project_name) ?></span>
                                    <?php endforeach; ?>
                                    <?php if (count($project_list) > 3): ?>
                                        <span class="project-tag more">+<?= count($project_list) - 3 ?> more</span>
                                    <?php endif; ?>
                                </div>
                            </div>
                        <?php endif; ?>
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

.contact-stats-grid {
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
.stat-icon.bg-info { background: linear-gradient(45deg, #17a2b8, #117a8b); }
.stat-icon.bg-warning { background: linear-gradient(45deg, #ffc107, #e0a800); }

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
    grid-template-columns: 2fr 1fr auto;
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

.contacts-container {
    background: white;
    border-radius: 12px;
    box-shadow: 0 2px 12px rgba(0,0,0,0.08);
    padding: 20px;
}

.contacts-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(400px, 1fr));
    gap: 25px;
}

.contact-card {
    background: #f8f9fa;
    border: 1px solid #e9ecef;
    border-radius: 12px;
    padding: 20px;
    transition: all 0.3s ease;
}

.contact-card:hover {
    border-color: #007bff;
    box-shadow: 0 4px 12px rgba(0,123,255,0.15);
}

.contact-header {
    display: flex;
    gap: 15px;
    margin-bottom: 15px;
}

.contact-avatar {
    width: 50px;
    height: 50px;
    background: linear-gradient(45deg, #007bff, #0056b3);
    border-radius: 25px;
    display: flex;
    align-items: center;
    justify-content: center;
    color: white;
    font-size: 20px;
    flex-shrink: 0;
}

.contact-info {
    flex: 1;
}

.contact-info h3 {
    margin: 0 0 5px 0;
    font-size: 1.2rem;
}

.contact-info h3 a {
    color: #333;
    text-decoration: none;
}

.contact-info h3 a:hover {
    color: #007bff;
}

.contact-position {
    margin: 0 0 5px 0;
    color: #666;
    font-weight: 500;
    font-size: 0.9rem;
}

.contact-company {
    margin: 0;
    color: #666;
    font-size: 0.9rem;
    display: flex;
    align-items: center;
    gap: 5px;
}

.contact-actions {
    align-self: flex-start;
}

.action-buttons {
    display: flex;
    gap: 5px;
}

.contact-details {
    margin-bottom: 15px;
}

.detail-row {
    display: flex;
    flex-direction: column;
    gap: 8px;
    margin-bottom: 10px;
}

.detail-row:last-child {
    margin-bottom: 0;
}

.detail-item {
    display: flex;
    align-items: center;
    gap: 8px;
    font-size: 0.9rem;
    color: #666;
}

.detail-item i {
    width: 14px;
    color: #007bff;
}

.detail-item a {
    color: #007bff;
    text-decoration: none;
}

.detail-item a:hover {
    text-decoration: underline;
}

.detail-item.address {
    align-items: flex-start;
    line-height: 1.4;
}

.contact-meta {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 15px;
    padding: 10px 0;
    border-top: 1px solid #dee2e6;
    font-size: 0.85rem;
    color: #666;
    flex-wrap: wrap;
    gap: 10px;
}

.meta-item {
    display: flex;
    align-items: center;
    gap: 5px;
}

.contact-projects {
    border-top: 1px solid #dee2e6;
    padding-top: 15px;
}

.contact-projects h4 {
    margin: 0 0 10px 0;
    font-size: 0.9rem;
    color: #333;
}

.project-tags {
    display: flex;
    gap: 5px;
    flex-wrap: wrap;
}

.project-tag {
    background: #007bff;
    color: white;
    padding: 4px 8px;
    border-radius: 12px;
    font-size: 0.8rem;
    font-weight: 500;
}

.project-tag.more {
    background: #6c757d;
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
    
    .contact-stats-grid {
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
    
    .contacts-grid {
        grid-template-columns: 1fr;
        gap: 20px;
    }
    
    .contact-header {
        flex-direction: column;
        gap: 10px;
        align-items: center;
        text-align: center;
    }
    
    .contact-actions {
        align-self: center;
    }
    
    .contact-meta {
        flex-direction: column;
        align-items: stretch;
        gap: 8px;
    }
    
    .meta-item {
        justify-content: center;
    }
}
</style>

<?php include 'includes/footer.php'; ?>