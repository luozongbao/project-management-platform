<?php
require_once 'config.php';
require_once 'includes/Database.php';
require_once 'includes/functions.php';

requireLogin();

$db = Database::getInstance();
$user_id = getCurrentUserId();

// v2.0: search + status filter. New functionality, matching the filter bar
// in docs/design/2.0/projects.html and mirroring the server-side GET
// approach tasks.php already uses, so both list pages behave identically.
// projects.status is a plain ENUM('not_started','in_progress','completed',
// 'on_hold'), so unlike tasks there is no computed pseudo-status to
// special-case and the filter is a simple equality.
$search = trim($_GET['search'] ?? '');
$status_filter = $_GET['status'] ?? 'all';

$where_conditions = ["p.responsible_person_id = ?"];
$params = [$user_id];

if ($search !== '') {
    $where_conditions[] = "(p.name LIKE ? OR p.description LIKE ?)";
    $search_param = '%' . $search . '%';
    $params[] = $search_param;
    $params[] = $search_param;
}

// Guard against an arbitrary ?status= being used as a probe: only the four
// enum values are accepted, anything else falls back to showing all.
$valid_statuses = ['not_started', 'in_progress', 'completed', 'on_hold'];
if ($status_filter !== 'all' && in_array($status_filter, $valid_statuses, true)) {
    $where_conditions[] = "p.status = ?";
    $params[] = $status_filter;
} else {
    $status_filter = 'all';
}

$where_clause = 'WHERE ' . implode(' AND ', $where_conditions);

// True when a filter is narrowing the list, which selects between the two
// distinct empty states below.
$filters_active = ($search !== '' || $status_filter !== 'all');

// Get projects with statistics
$projects = $db->fetchAll(
    "SELECT ps.*, p.name, p.status, p.description, p.created_at, p.start_date, u.name as responsible_person_name
     FROM project_stats ps
     JOIN projects p ON ps.id = p.id
     JOIN users u ON p.responsible_person_id = u.id
     $where_clause
     ORDER BY p.created_at DESC",
    $params
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
// "4 projects · 1 completed" line. This must describe the user's projects
// as a whole, not the current result set: filtering down to one project
// would otherwise make the header claim the account owns one project.
// Counted with one cheap query rather than derived from $projects.
$__proj_row = $db->fetchOne(
    "SELECT COUNT(*) AS total,
            SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) AS completed
     FROM projects
     WHERE responsible_person_id = ?",
    [$user_id]
);
$__proj_total = (int)($__proj_row['total'] ?? 0);
$__proj_completed = (int)($__proj_row['completed'] ?? 0);
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
                <?php if ($filters_active): ?>
                    &middot; <?= count($projects) ?> matching
                <?php endif; ?>
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

<!-- Filter bar: shared .filter-bar component, matching tasks.php and the
     mockup's projects.html. Plain GET form, so it works with JS disabled
     and the query string stays bookmarkable. Hidden when the account owns
     no projects at all -- a filter over nothing is just noise. -->
<?php if ($__proj_total > 0): ?>
    <form method="GET" class="filter-bar" data-filter-form>
        <div class="field">
            <label for="search">Search</label>
            <input class="input" type="search" id="search" name="search"
                   value="<?= e($search) ?>"
                   placeholder="Project name or description...">
        </div>

        <div class="field">
            <label for="status_filter">Status</label>
            <select class="select" id="status_filter" name="status">
                <option value="all" <?= $status_filter === 'all' ? 'selected' : '' ?>>All Status</option>
                <option value="not_started" <?= $status_filter === 'not_started' ? 'selected' : '' ?>>Not Started</option>
                <option value="in_progress" <?= $status_filter === 'in_progress' ? 'selected' : '' ?>>In Progress</option>
                <option value="completed" <?= $status_filter === 'completed' ? 'selected' : '' ?>>Completed</option>
                <option value="on_hold" <?= $status_filter === 'on_hold' ? 'selected' : '' ?>>On Hold</option>
            </select>
        </div>

        <div class="filter-bar__actions">
            <button type="submit" class="btn btn--primary">
                <i class="fas fa-filter"></i>
                Apply
            </button>
            <a href="/projects" class="btn btn--secondary">
                <i class="fas fa-rotate-left"></i>
                Reset
            </a>
        </div>
    </form>
<?php endif; ?>

<?php if (empty($projects)): ?>
    <?php if ($filters_active): ?>
        <!-- No results, but the account does own projects: this is a filter
             miss, so it must not reuse the "No Projects Yet" state below,
             which would wrongly suggest there is nothing to track. -->
        <div class="empty-state proj-empty">
            <div class="empty-state__icon">
                <i class="fas fa-filter-circle-xmark"></i>
            </div>
            <h3>No Matching Projects</h3>
            <p>No projects match the current search or status filter.</p>
            <a href="/projects" class="btn btn--secondary">
                <i class="fas fa-rotate-left"></i>
                Clear Filters
            </a>
        </div>
    <?php else: ?>
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
    <?php endif; ?>
<?php else: ?>
    <div class="project-grid">
        <?php foreach ($projects as $project): ?>
            <div class="project-card">
                <div class="project-card__head">
                    <div class="project-card__id">
                        <h3 class="project-card__title">
                            <a href="/projects/<?= (int)$project['id'] ?>"><?= e($project['name']) ?></a>
                        </h3>
                        <div class="project-card__meta">
                            <?php if (!empty($project['expected_completion_date'])): ?>
                                <span>
                                    <i class="fas fa-calendar"></i>
                                    Due <?= formatDateTime($project['expected_completion_date'], 'M j, Y') ?>
                                </span>
                            <?php endif; ?>
                            <span>
                                <i class="fas fa-list-check"></i>
                                <?= (int)$project['total_tasks'] - (int)$project['uncompleted_tasks'] ?> / <?= (int)$project['total_tasks'] ?> tasks
                            </span>
                        </div>
                    </div>
                    <span class="status status--<?= e($project['status']) ?>">
                        <?= ucfirst(str_replace('_', ' ', $project['status'])) ?>
                    </span>
                </div>

                <p class="project-card__desc">
                    <?php if ($project['description']): ?>
                        <?= e(substr($project['description'], 0, 150)) ?><?= strlen($project['description']) > 150 ? '...' : '' ?>
                    <?php else: ?>
                        <span class="project-card__desc--empty">No description provided</span>
                    <?php endif; ?>
                </p>

                <div class="progress">
                    <div class="progress__fill" style="width: <?= $project['avg_completion_percentage'] ?>%"></div>
                </div>

                <!-- Mockup stats block: label above value, not the icon row the
                     real page used. Progress is a percentage here rather than a
                     separate labelled bar, and Pending keeps the urgent tone.

                     Contacts is NOT in the mockup, which shows only Progress and
                     Pending. It was reinstated here by request: the count is
                     already selected via project_stats.* so it costs no extra
                     query. Rendered unconditionally, including at zero, so the
                     card shape does not change between projects. -->
                <div class="project-card__stats">
                    <div>
                        <div class="project-card__stat-label">Progress</div>
                        <div class="project-card__stat-value"><?= number_format($project['avg_completion_percentage'], 0) ?>%</div>
                    </div>
                    <div>
                        <div class="project-card__stat-label">Pending</div>
                        <?php /* The urgent tone is conditional, as it was on the
                                 old icon-row stat: a project with nothing pending
                                 should not render "0 pending" in red. */ ?>
                        <div class="project-card__stat-value<?= (int)$project['uncompleted_tasks'] > 0 ? ' project-card__stat-value--urgent' : '' ?>">
                            <?= (int)$project['uncompleted_tasks'] ?> pending
                        </div>
                    </div>
                    <div>
                        <div class="project-card__stat-label">Contacts</div>
                        <div class="project-card__stat-value">
                            <?= (int)$project['contact_count'] ?>
                        </div>
                    </div>
                </div>

                <!-- No delete button: project_delete.php requires POST + CSRF +
                     the project share_code typed as confirmation, and that flow
                     lives on project_edit.php. The mockup's trash icon would
                     imply a one-click delete this app deliberately does not
                     offer. See the note in assets/css/pages/projects.css. -->
                <div class="project-card__foot">
                    <a href="/projects/<?= (int)$project['id'] ?>" class="btn btn--sm btn--primary">
                        <i class="fas fa-eye"></i>
                        Open
                    </a>
                    <a href="/projects/<?= (int)$project['id'] ?>/edit" class="btn btn--sm btn--secondary">
                        <i class="fas fa-pen"></i>
                        Edit
                    </a>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
<?php endif; ?>


<?php include 'includes/footer.php'; ?>