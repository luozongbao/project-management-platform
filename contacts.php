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
// v2.0 shell (issue-035 Phase 1.4b).
$use_v2_shell = true;
?>

<?php include 'includes/header.php'; ?>
<link rel="stylesheet" href="/assets/css/pages/contacts.css">

<div class="page-header">
    <div class="page-header__text">
        <h1>
            <i class="fas fa-address-book"></i>
            Contacts
            <?php if ($project_id): ?>
                <?php 
                $project_name = $db->fetchOne("SELECT name FROM projects WHERE id = ?", [$project_id])['name'];
                ?>
                <span class="ct-context">- <?= e($project_name) ?></span>
            <?php endif; ?>
        </h1>
        <?php if (!$project_id && $contact_stats): ?>
            <p class="page-header__sub"><?= (int)$contact_stats['total_contacts'] ?> contacts across <?= (int)$contact_stats['projects_with_contacts'] ?> projects</p>
        <?php elseif ($project_id): ?>
            <p class="page-header__sub">Manage your project contacts and communication</p>
        <?php else: ?>
            <p class="page-header__sub">Manage your project contacts and communication</p>
        <?php endif; ?>
    </div>
    <div class="page-actions">
        <a href="/contacts/new<?= $project_id ? '?project_id=' . $project_id : '' ?>" class="btn btn--primary">
            <i class="fas fa-plus"></i>
            New Contact
        </a>
    </div>
</div>

<!-- Contact Statistics -->
<div class="stat-grid">
    <div class="stat">
        <div class="stat__icon"><i class="fas fa-users"></i></div>
        <div>
            <div class="stat__value"><?= $contact_stats['total_contacts'] ?? 0 ?></div>
            <div class="stat__label">Total Contacts</div>
        </div>
    </div>

    <div class="stat stat--success">
        <div class="stat__icon"><i class="fas fa-envelope"></i></div>
        <div>
            <div class="stat__value"><?= $contact_stats['contacts_with_email'] ?? 0 ?></div>
            <div class="stat__label">With Email</div>
        </div>
    </div>

    <div class="stat stat--info">
        <div class="stat__icon"><i class="fas fa-phone"></i></div>
        <div>
            <div class="stat__value"><?= $contact_stats['contacts_with_phone'] ?? 0 ?></div>
            <div class="stat__label">With Phone</div>
        </div>
    </div>

    <div class="stat stat--on-hold">
        <div class="stat__icon"><i class="fas fa-folder"></i></div>
        <div>
            <div class="stat__value"><?= $contact_stats['projects_with_contacts'] ?? 0 ?></div>
            <div class="stat__label">Active Projects</div>
        </div>
    </div>
</div>

    <!-- Filters -->
<form method="GET" class="ct-filter">
    <?php if ($project_id): ?>
        <input type="hidden" name="project_id" value="<?= $project_id ?>">
    <?php endif; ?>

    <div class="field">
        <label for="search">Search Contacts</label>
        <input class="input" type="search" id="search" name="search"
               value="<?= e($search) ?>"
               placeholder="Search by name, email, phone, or company...">
    </div>

    <?php if (!$project_id): ?>
        <div class="field">
            <label for="project_filter">Project</label>
            <select class="select" id="project_filter" name="project_id">
                <option value="">All Projects</option>
                <?php foreach ($user_projects as $project): ?>
                    <option value="<?= $project['id'] ?>" <?= ($_GET['project_id'] ?? '') == $project['id'] ? 'selected' : '' ?>>
                        <?= e($project['name']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
    <?php else: ?>
        <!-- The project dropdown is hidden when already filtered to one
             project, but the grid must not collapse to two columns. The
             actions cell keeps the third slot. -->
        <div></div>
    <?php endif; ?>

    <div class="ct-filter__actions">
        <button type="submit" class="btn btn--primary">
            <i class="fas fa-filter"></i>
            Apply
        </button>
        <a href="/contacts<?= $project_id ? '?project_id=' . $project_id : '' ?>" class="btn btn--secondary">
            <i class="fas fa-times"></i>
            Clear
        </a>
    </div>
</form>

<!-- Contacts List -->
<?php if (empty($contacts)): ?>
    <div class="ct-empty">
        <div class="ct-empty__icon">
            <i class="fas fa-address-book"></i>
        </div>
        <h3 class="ct-empty__title">No Contacts Found</h3>
        <p class="ct-empty__text">
            <?php if ($search || $project_id): ?>
                No contacts match your current filters. Try adjusting your search criteria.
            <?php else: ?>
                You haven't added any contacts yet. Create your first contact to get started!
            <?php endif; ?>
        </p>
        <a href="/contacts/new<?= $project_id ? '?project_id=' . $project_id : '' ?>" class="btn btn--primary">
            <i class="fas fa-plus"></i>
            Add First Contact
        </a>
    </div>
<?php else: ?>
    <div class="ct-grid">
        <?php foreach ($contacts as $contact): ?>
            <div class="ct-card">
                <div class="ct-card__head">
                    <span class="ct-avatar"><i class="fas fa-user"></i></span>
                    <div class="ct-head__text">
                        <h3 class="ct-card__title">
                            <a href="/contacts/<?= (int)$contact['id'] ?>">
                                <?= e($contact['name']) ?>
                            </a>
                        </h3>
                        <?php if ($contact['position']): ?>
                            <p class="ct-card__position"><?= e($contact['position']) ?></p>
                        <?php endif; ?>
                        <?php if ($contact['company']): ?>
                            <p class="ct-card__company">
                                <i class="fas fa-building"></i>
                                <?= e($contact['company']) ?>
                            </p>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="ct-details">
                    <?php if ($contact['email']): ?>
                        <div class="ct-detail">
                            <span class="ct-detail__k">Email</span>
                            <span class="ct-detail__v">
                                <a href="mailto:<?= e($contact['email']) ?>"><?= e($contact['email']) ?></a>
                            </span>
                        </div>
                    <?php endif; ?>

                    <?php if ($contact['phone']): ?>
                        <div class="ct-detail">
                            <span class="ct-detail__k">Phone</span>
                            <span class="ct-detail__v">
                                <a href="tel:<?= e($contact['phone']) ?>"><?= e($contact['phone']) ?></a>
                            </span>
                        </div>
                    <?php endif; ?>

                    <?php if ($contact['address']): ?>
                        <div class="ct-detail">
                            <span class="ct-detail__k">Address</span>
                            <span class="ct-detail__v ct-detail__v--muted"><?= e($contact['address']) ?></span>
                        </div>
                    <?php endif; ?>
                </div>

                <div class="ct-meta">
                    <div class="ct-meta__item">
                        <i class="fas fa-folder"></i>
                        <span><?= $contact['project_count'] ?> project<?= $contact['project_count'] != 1 ? 's' : '' ?></span>
                    </div>

                    <div class="ct-meta__item">
                        <i class="fas fa-tasks"></i>
                        <span><?= $contact['task_count'] ?> task<?= $contact['task_count'] != 1 ? 's' : '' ?></span>
                    </div>

                    <div class="ct-meta__item">
                        <i class="fas fa-calendar"></i>
                        <span>Added <?= formatDateTime($contact['created_at'], 'M j, Y') ?></span>
                    </div>
                </div>

                <?php if (!$project_id && $contact['project_names']): ?>
                    <div class="ct-projects">
                        <p class="ct-projects__label">Projects</p>
                        <div class="ct-tags">
                            <?php 
                            $project_list = explode(', ', $contact['project_names']);
                            foreach (array_slice($project_list, 0, 3) as $project_name): 
                            ?>
                                <span class="ct-tag"><?= e($project_name) ?></span>
                            <?php endforeach; ?>
                            <?php if (count($project_list) > 3): ?>
                                <span class="ct-tag ct-tag--more">+<?= count($project_list) - 3 ?> more</span>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endif; ?>

                <div class="ct-foot">
                    <a href="/contacts/<?= (int)$contact['id'] ?>" class="btn btn--sm btn--primary">
                        <i class="fas fa-eye"></i> Open
                    </a>
                    <a href="/contacts/<?= (int)$contact['id'] ?>/edit" class="btn btn--sm btn--secondary">
                        <i class="fas fa-pen"></i> Edit
                    </a>
                    <?php if ($contact['email']): ?>
                        <a href="mailto:<?= e($contact['email']) ?>" class="btn btn--sm btn--secondary btn--icon" title="Send Email" aria-label="Send email to <?= e($contact['name']) ?>">
                            <i class="fas fa-envelope"></i>
                        </a>
                    <?php endif; ?>
                    <?php if ($contact['phone']): ?>
                        <a href="tel:<?= e($contact['phone']) ?>" class="btn btn--sm btn--secondary btn--icon" title="Call" aria-label="Call <?= e($contact['name']) ?>">
                            <i class="fas fa-phone"></i>
                        </a>
                    <?php endif; ?>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
<?php endif; ?>


<?php include 'includes/footer.php'; ?>