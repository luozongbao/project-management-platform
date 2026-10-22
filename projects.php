<?php
require_once 'config.php';
require_once 'includes/Database.php';
require_once 'includes/functions.php';

requireLogin();

$db = Database::getInstance();
$user_id = getCurrentUserId();

// Get projects with statistics
$projects = $db->fetchAll(
    "SELECT ps.*, p.name, p.slug, p.status, p.description, p.created_at, p.start_date, u.name as responsible_person_name
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
// v2.0: opt into the v2 shell (Phase 1.3) before the header include.
$use_v2_shell = true;
// Counts for the page-header subtitle, mirroring the mockup's
// "4 projects · 1 completed" line. Derived from the already-fetched set
// so it costs no extra query.
$__proj_completed = 0;
foreach ($projects as $__p) {
    if (($__p['status'] ?? '') === 'completed') $__proj_completed++;
}
$__proj_total = count($projects);
?>

<?php include 'includes/header.php'; ?>
<link rel="stylesheet" href="/assets/css/pages/projects.css">

<div class="page-header">
    <div class="page-header__text">
        <h1><i class="fas fa-folder-open"></i> Projects</h1>
        <p class="page-header__sub">
            <?php if ($__proj_total > 0): ?>
                <?= $__proj_total ?> project<?= $__proj_total === 1 ? '' : 's' ?>
                &middot; <?= $__proj_completed ?> completed
            <?php else: ?>
                Manage your projects and track their progress
            <?php endif; ?>
        </p>
    </div>
    <div class="page-actions">
        <a href="/projects/new" class="btn btn--primary">
            <i class="fas fa-plus"></i>
            New Project
        </a>
    </div>
</div>

<?php if (empty($projects)): ?>
    <div class="empty-state proj-empty">
        <div class="empty-state__icon">
            <i class="fas fa-folder-plus"></i>
        </div>
        <h3>No Projects Yet</h3>
        <p>Create your first project to get started with managing your work.</p>
        <a href="/projects/new" class="btn btn--primary">
            <i class="fas fa-plus"></i>
            Create First Project
        </a>
    </div>
<?php else: ?>
    <div class="proj-grid">
        <?php foreach ($projects as $project): ?>
            <div class="proj-card">
                <div class="proj-card__head">
                    <div>
                        <h3 class="proj-card__title">
                            <a href="/projects/<?= e($project['slug']) ?>"><?= e($project['name']) ?></a>
                        </h3>
                        <div class="proj-card__status">
                            <span class="status status--<?= $project['status'] ?>">
                                <?= ucfirst(str_replace('_', ' ', $project['status'])) ?>
                            </span>
                        </div>
                    </div>
                    <div class="proj-card__edit">
                        <a href="/projects/<?= e($project['slug']) ?>/edit" class="btn btn--sm btn--ghost btn--icon" title="Edit" aria-label="Edit project">
                            <i class="fas fa-pen"></i>
                        </a>
                    </div>
                </div>

                <div class="proj-card__desc">
                    <?php if ($project['description']): ?>
                        <p><?= e(substr($project['description'], 0, 150)) ?><?= strlen($project['description']) > 150 ? '...' : '' ?></p>
                    <?php else: ?>
                        <p class="proj-card__desc--empty">No description provided</p>
                    <?php endif; ?>
                </div>

                <div class="proj-card__stats">
                    <div class="proj-card__stat">
                        <i class="fas fa-tasks"></i>
                        <span><?= $project['total_tasks'] ?> tasks</span>
                    </div>
                    <div class="proj-card__stat">
                        <i class="fas fa-address-book"></i>
                        <span><?= $project['contact_count'] ?> contacts</span>
                    </div>
                    <?php if ($project['uncompleted_tasks'] > 0): ?>
                        <div class="proj-card__stat proj-card__stat--urgent">
                            <i class="fas fa-triangle-exclamation"></i>
                            <span><?= $project['uncompleted_tasks'] ?> pending</span>
                        </div>
                    <?php endif; ?>
                </div>

                <div class="proj-card__progress-head">
                    <span class="proj-card__progress-label">Progress</span>
                    <span class="proj-card__progress-value"><?= number_format($project['avg_completion_percentage'], 1) ?>%</span>
                </div>
                <div class="progress">
                    <div class="progress__fill" style="width: <?= $project['avg_completion_percentage'] ?>%"></div>
                </div>

                <div class="proj-card__foot">
                    <div class="proj-card__dates">
                        <?php if (!empty($project['start_date'])): ?>
                            <div class="proj-card__date">
                                <i class="fas fa-flag"></i>
                                <span>Started: <?= formatDateTime($project['start_date'], 'M j, Y') ?></span>
                            </div>
                        <?php endif; ?>

                        <?php if ($project['expected_completion_date']): ?>
                            <div class="proj-card__date">
                                <i class="fas fa-calendar"></i>
                                <span>Due: <?= formatDateTime($project['expected_completion_date'], 'M j, Y') ?></span>
                            </div>
                        <?php endif; ?>

                        <?php if ($project['completion_date']): ?>
                            <div class="proj-card__date proj-card__date--done">
                                <i class="fas fa-circle-check"></i>
                                <span>Completed: <?= formatDateTime($project['completion_date'], 'M j, Y') ?></span>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
<?php endif; ?>


<?php include 'includes/footer.php'; ?>