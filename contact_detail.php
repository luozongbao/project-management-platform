<?php
require_once 'config.php';
require_once 'includes/Database.php';
require_once 'includes/functions.php';

requireLogin();

$db = Database::getInstance();
$user_id = getCurrentUserId();
$contact_id = $_GET['id'] ?? null;

if (!$contact_id) {
    redirect('/contacts');
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
    redirect('/contacts');
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

// Per-project completion percentage, from the same source project_detail.php
// uses. There is no stored percentage column on `projects` -- the figure is
// derived from that project's tasks -- so it must be computed per project
// rather than read alongside the row above.
$projectCompletion = [];
foreach ($projects as $project) {
    $projectCompletion[(int)$project['id']]
        = getProjectTaskCompletionMap($db, (int)$project['id'])['project_completion'];
}

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
foreach ($tasks as &$task) {
    $taskCompletion = getProjectTaskCompletionMap($db, $task['project_id']);
    $task['completion_percentage'] =
        $taskCompletion['task_completion'][(int)$task['id']] ?? 0;
}
unset($task);

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
// v2.0: this page was missing $use_v2_shell, so header.php skipped the
// .app-shell/.sidebar wrapper and the page rendered unstyled and
// full-bleed. Every other v2 page sets this. See header.php line ~94.
$use_v2_shell = true;
?>

<?php include 'includes/header.php'; ?>
<link rel="stylesheet" href="/assets/css/pages/contact_detail.css">

<div class="content">
    <div class="breadcrumb">
        <a href="/dashboard">Dashboard</a>
        <span>/</span>
        <a href="/contacts">Contacts</a>
        <span>/</span>
        <span><?= e($contact['name']) ?></span>
    </div>

    <div class="page-header">
        <div class="page-header__text">
            <h1>
                <i class="fas fa-user"></i>
                <?= e($contact['name']) ?>
            </h1>
            <?php /* Position and company ride in the header as chips, matching
                     the mockup's page-header pattern -- they are the two facts
                     that identify a contact at a glance, so they belong beside
                     the name rather than buried in the Contact Information card. */ ?>
            <?php if (!empty($contact['position']) || !empty($contact['company'])): ?>
                <div class="chip-row" style="margin-top:.35rem;">
                    <?php if (!empty($contact['position'])): ?>
                        <span class="badge badge--outline">
                            <i class="fas fa-briefcase"></i>
                            <?= e($contact['position']) ?>
                        </span>
                    <?php endif; ?>
                    <?php if (!empty($contact['company'])): ?>
                        <span class="badge badge--outline">
                            <i class="fas fa-building"></i>
                            <?= e($contact['company']) ?>
                        </span>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        </div>
        <div class="page-actions">
            <a href="/contacts/<?= (int)$contact['id'] ?>/edit" class="btn btn--secondary">
                <i class="fas fa-edit"></i>
                Edit Contact
            </a>
            <?php if ($contact['email']): ?>
                <a href="mailto:<?= e($contact['email']) ?>" class="btn btn--secondary">
                    <i class="fas fa-envelope"></i>
                    Send Email
                </a>
            <?php endif; ?>
            <?php if ($contact['phone']): ?>
                <a href="tel:<?= e($contact['phone']) ?>" class="btn btn--secondary">
                    <i class="fas fa-phone"></i>
                    Call
                </a>
            <?php endif; ?>
        </div>
    </div>

    <div class="grid-sidebar">
        <div>
            <!-- Contact statistics -->
            <div class="stat-grid cd-stats">
                <div class="stat">
                    <div class="stat__icon">
                        <i class="fas fa-folder"></i>
                    </div>
                    <div class="stat__text">
                        <div class="stat__value"><?= (int)($contact_stats['project_count'] ?? 0) ?></div>
                        <div class="stat__label">Projects</div>
                    </div>
                </div>

                <div class="stat">
                    <div class="stat__icon">
                        <i class="fas fa-list-check"></i>
                    </div>
                    <div class="stat__text">
                        <div class="stat__value"><?= (int)($contact_stats['task_count'] ?? 0) ?></div>
                        <div class="stat__label">Total Tasks</div>
                    </div>
                </div>

                <div class="stat stat--success">
                    <div class="stat__icon">
                        <i class="fas fa-circle-check"></i>
                    </div>
                    <div class="stat__text">
                        <div class="stat__value"><?= (int)($contact_stats['completed_tasks'] ?? 0) ?></div>
                        <div class="stat__label">Completed</div>
                    </div>
                </div>
            </div>

            <!-- Recent tasks -->
            <?php if (!empty($tasks)): ?>
                <div class="card">
                    <div class="card__header">
                        <h2 class="card__title-icon"><i class="fas fa-list-check"></i> Recent Tasks</h2>
                    </div>
                    <div class="card__body card__body--flush">
                        <div class="table-wrap">
                            <table class="table table--compact table--fit">
                                <thead>
                                    <tr>
                                        <th>Task</th>
                                        <th>Project</th>
                                        <th>Status</th>
                                        <th>Progress</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($tasks as $task): ?>
                                        <tr>
                                            <td>
                                                <a href="/tasks/<?= (int)$task['id'] ?>"><strong><?= e($task['name']) ?></strong></a>
                                            </td>
                                            <td class="text-sm text-muted">
                                                <a href="/projects/<?= (int)$task['project_id'] ?>"><?= e($task['project_name']) ?></a>
                                            </td>
                                            <td>
                                                <span class="status status--<?= e($task['status']) ?>">
                                                    <?= e(ucfirst(str_replace('_', ' ', $task['status']))) ?>
                                                </span>
                                            </td>
                                            <td style="min-width:110px;">
                                                <div class="progress">
                                                    <div class="progress__fill" style="width: <?= (float)$task['completion_percentage'] ?>%"></div>
                                                </div>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            <?php endif; ?>
            <div class="card">
                <div class="card__header">
                    <h2 class="card__title-icon"><i class="fas fa-address-card"></i> Contact Information</h2>
                </div>
                <div class="card__body">
                    <div class="detail-list">
                        <?php if ($contact['email']): ?>
                            <div class="detail-row">
                                <div class="detail-row__k">Email</div>
                                <div class="detail-row__v">
                                    <a href="mailto:<?= e($contact['email']) ?>" class="cd-link">
                                        <i class="fas fa-envelope"></i>
                                        <?= e($contact['email']) ?>
                                    </a>
                                </div>
                            </div>
                        <?php endif; ?>

                        <?php if ($contact['phone']): ?>
                            <div class="detail-row">
                                <div class="detail-row__k">Phone</div>
                                <div class="detail-row__v">
                                    <a href="tel:<?= e($contact['phone']) ?>" class="cd-link">
                                        <i class="fas fa-phone"></i>
                                        <?= e($contact['phone']) ?>
                                    </a>
                                </div>
                            </div>
                        <?php endif; ?>

                        <?php if ($contact['mobile']): ?>
                            <div class="detail-row">
                                <div class="detail-row__k">Mobile</div>
                                <div class="detail-row__v">
                                    <a href="tel:<?= e($contact['mobile']) ?>" class="cd-link">
                                        <i class="fas fa-mobile-alt"></i>
                                        <?= e($contact['mobile']) ?>
                                    </a>
                                </div>
                            </div>
                        <?php endif; ?>

                        <?php if ($contact['address']): ?>
                            <div class="detail-row detail-row--stacked">
                                <div class="detail-row__k">Address</div>
                                <div class="detail-row__v">
                                    <i class="fas fa-map-marker-alt"></i>
                                    <?= nl2br(e($contact['address'])) ?>
                                </div>
                            </div>
                        <?php endif; ?>

                        <div class="detail-row">
                            <div class="detail-row__k">Added</div>
                            <div class="detail-row__v">
                                <?= formatDateTime($contact['created_at']) ?>
                            </div>
                        </div>

                        <?php if ($contact['updated_at'] != $contact['created_at']): ?>
                            <div class="detail-row">
                                <div class="detail-row__k">Updated</div>
                                <div class="detail-row__v">
                                    <?= formatDateTime($contact['updated_at']) ?>
                                </div>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- Social media -->
            <?php if ($contact['wechat'] || $contact['line_id'] || $contact['facebook'] || $contact['linkedin']): ?>

                <div class="card">
                    <div class="card__header">
                        <h2 class="card__title-icon"><i class="fas fa-share-nodes"></i> Social Media</h2>
                    </div>
                    <div class="card__body">
                        <div class="detail-list">
                            <?php if ($contact['wechat']): ?>
                                <div class="detail-row">
                                    <div class="detail-row__k">WeChat</div>
                                    <div class="detail-row__v">
                                        <span class="cd-link">
                                            <i class="fab fa-weixin"></i>
                                            <?= e($contact['wechat']) ?>
                                        </span>
                                    </div>
                                </div>
                            <?php endif; ?>

                            <?php if ($contact['line_id']): ?>
                                <div class="detail-row">
                                    <div class="detail-row__k">LINE</div>
                                    <div class="detail-row__v">
                                        <span class="cd-link">
                                            <i class="fab fa-line"></i>
                                            <?= e($contact['line_id']) ?>
                                        </span>
                                    </div>
                                </div>
                            <?php endif; ?>

                            <?php if ($contact['facebook']): ?>
                                <div class="detail-row">
                                    <div class="detail-row__k">Facebook</div>
                                    <div class="detail-row__v">
                                        <a href="<?= e($contact['facebook']) ?>" class="cd-link" target="_blank" rel="noopener">
                                            <i class="fab fa-facebook"></i>
                                            View Profile
                                        </a>
                                    </div>
                                </div>
                            <?php endif; ?>

                            <?php if ($contact['linkedin']): ?>
                                <div class="detail-row">
                                    <div class="detail-row__k">LinkedIn</div>
                                    <div class="detail-row__v">
                                        <a href="<?= e($contact['linkedin']) ?>" class="cd-link" target="_blank" rel="noopener">
                                            <i class="fab fa-linkedin"></i>
                                            View Profile
                                        </a>
                                    </div>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            <?php endif; ?>

            <!-- Associated projects -->

        </div>

        <div>
            <!-- Contact information -->
            <div class="card">
                <div class="card__header">
                    <h2 class="card__title-icon"><i class="fas fa-sitemap"></i> Projects (<?= count($projects) ?>)</h2>
                </div>
                <div class="card__body card__body--flush">
                    <?php if (!empty($projects)): ?>
                        <div class="table-wrap">
                            <table class="table table--compact table--fit">
                                <thead>
                                    <tr>
                                        <th>Project</th>
                                        <th>Status</th>
                                        <th>%</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($projects as $project): ?>
                                        <tr>
                                            <td>
                                                <a href="/projects/<?= (int)$project['id'] ?>"><strong><?= e($project['name']) ?></strong></a>
                                            </td>
                                            <td>
                                                <span class="status status--<?= e($project['status']) ?>">
                                                    <?= e(ucfirst(str_replace('_', ' ', $project['status']))) ?>
                                                </span>
                                            </td>
                                            <td style="min-width:110px;">
                                                <div class="progress">
                                                    <div class="progress__fill" style="width: <?= (float)($projectCompletion[(int)$project['id']] ?? 0) ?>%"></div>
                                                </div>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php else: ?>
                        <div class="empty-state">
                            <i class="fas fa-folder-open empty-state__icon"></i>
                            <p>No projects associated with this contact.</p>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

                <!-- Contact overview -->
                <div class="card">
                    <div class="card__header">
                        <h2 class="card__title-icon"><i class="fas fa-circle-info"></i> Overview</h2>
                    </div>
                    <div class="card__body">
                        <div class="cd-identity">
                            <div class="cd-avatar">
                                <i class="fas fa-user"></i>
                            </div>
                            <div class="cd-identity__text">
                                <h3 class="cd-identity__name"><?= e($contact['name']) ?></h3>
                                <?php if ($contact['position']): ?>
                                    <p class="cd-identity__position"><?= e($contact['position']) ?></p>
                                <?php endif; ?>
                                <?php if ($contact['company']): ?>
                                    <p class="cd-identity__company">
                                        <i class="fas fa-building"></i>
                                        <?= e($contact['company']) ?>
                                    </p>
                                <?php endif; ?>
                            </div>
                        </div>

                        <?php if ($contact['description']): ?>
                            <div class="cd-description">
                                <h4>Description</h4>
                                <div class="cd-description__body">
                                    <?= nl2br(e($contact['description'])) ?>
                                </div>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
        </div>
    </div>
</div>

<?php include 'includes/footer.php'; ?>
