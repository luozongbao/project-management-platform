<?php
require_once 'config.php';
require_once 'includes/Database.php';
require_once 'includes/functions.php';

requireLogin();

$db = Database::getInstance();

// Get dashboard statistics
$user_id = getCurrentUserId();

// Overall statistics
$total_projects = $db->fetchOne(
    "SELECT COUNT(*) as count FROM projects WHERE responsible_person_id = ?",
    [$user_id]
)['count'] ?? 0;

$completed_projects = $db->fetchOne(
    "SELECT COUNT(*) as count FROM projects WHERE responsible_person_id = ? AND status = 'completed'",
    [$user_id]
)['count'] ?? 0;

$total_tasks = $db->fetchOne(
    "SELECT COUNT(*) as count FROM tasks t 
     JOIN projects p ON t.project_id = p.id 
     WHERE p.responsible_person_id = ?",
    [$user_id]
)['count'] ?? 0;

$uncompleted_tasks = $db->fetchOne(
    "SELECT COUNT(*) as count FROM tasks t 
     JOIN projects p ON t.project_id = p.id 
     WHERE p.responsible_person_id = ? AND t.status != 'completed'",
    [$user_id]
)['count'] ?? 0;

// Issue-034 (v1.8.0): count "delayed" tasks across all projects owned by
// this user. "Delayed" = expected_completion_date IS NOT NULL AND
// expected_completion_date < CURDATE() AND status != 'completed'. We
// keep this as a SQL-side computation rather than a per-row PHP scan
// because the dashboard cost must stay O(projects) for a workspace
// with thousands of tasks. The backfill banner below uses the same
// WHERE clause plus `delay_reason IS NULL` to surface the
// "unexplained delay" set in one pass.
$delayed_tasks_count = $db->fetchOne(
    "SELECT COUNT(*) as count FROM tasks t 
     JOIN projects p ON t.project_id = p.id 
     WHERE p.responsible_person_id = ?
       AND t.expected_completion_date IS NOT NULL
       AND t.expected_completion_date < CURDATE()
       AND t.status != 'completed'",
    [$user_id]
)['count'] ?? 0;

$on_hold_tasks_count = $db->fetchOne(
    "SELECT COUNT(*) as count FROM tasks t 
     JOIN projects p ON t.project_id = p.id 
     WHERE p.responsible_person_id = ? AND t.status = 'on_hold'",
    [$user_id]
)['count'] ?? 0;

// Issue-034 backfill banner: count tasks that are delayed/on-hold
// WITHOUT a reason recorded. These are the one-off cases the project
// manager should resolve first; the link goes to the pre-filtered
// tasks list (tasks.php?status=delayed&missing_reason=1).
$missing_delay_reason_count = $db->fetchOne(
    "SELECT COUNT(*) as count FROM tasks t 
     JOIN projects p ON t.project_id = p.id 
     WHERE p.responsible_person_id = ?
       AND t.expected_completion_date IS NOT NULL
       AND t.expected_completion_date < CURDATE()
       AND t.status != 'completed'
       AND (t.delay_reason IS NULL OR TRIM(t.delay_reason) = '')",
    [$user_id]
)['count'] ?? 0;

$missing_on_hold_reason_count = $db->fetchOne(
    "SELECT COUNT(*) as count FROM tasks t 
     JOIN projects p ON t.project_id = p.id 
     WHERE p.responsible_person_id = ?
       AND t.status = 'on_hold'
       AND (t.on_hold_reason IS NULL OR TRIM(t.on_hold_reason) = '')",
    [$user_id]
)['count'] ?? 0;

$missing_reason_total = (int)$missing_delay_reason_count + (int)$missing_on_hold_reason_count;

// Calculate each project's weighted task completion, then retain the existing
// equal average across projects for the account-wide dashboard statistic.
$user_project_ids = $db->fetchAll(
    "SELECT id FROM projects WHERE responsible_person_id = ?",
    [$user_id]
);
$completionByProject = [];
foreach ($user_project_ids as $userProject) {
    $projectCompletion = getProjectTaskCompletionMap($db, $userProject['id']);
    $completionByProject[(int)$userProject['id']] = $projectCompletion;
}
$overall_completion = count($completionByProject) > 0
    ? array_sum(array_column($completionByProject, 'project_completion')) / count($completionByProject)
    : 0;

// Recent projects
$recent_projects = $db->fetchAll(
    "SELECT ps.*, p.slug, u.name as responsible_person_name
     FROM project_stats ps
     JOIN projects p ON ps.id = p.id
     JOIN users u ON p.responsible_person_id = u.id
     WHERE p.responsible_person_id = ? 
     ORDER BY p.created_at DESC 
     LIMIT 5",
    [$user_id]
);
foreach ($recent_projects as &$project) {
    $project['avg_completion_percentage'] =
        $completionByProject[(int)$project['id']]['project_completion'] ?? 0;
}
unset($project);

// Upcoming deadlines
$upcoming_deadlines = $db->fetchAll(
    "SELECT p.id, p.name, p.slug, p.expected_completion_date, ps.avg_completion_percentage, p.status
     FROM projects p
     LEFT JOIN project_stats ps ON p.id = ps.id
     WHERE p.responsible_person_id = ? 
       AND p.status != 'completed'
       AND p.expected_completion_date IS NOT NULL
     ORDER BY p.expected_completion_date ASC
     LIMIT 5",
    [$user_id]
);
foreach ($upcoming_deadlines as &$deadline) {
    if (!isset($completionByProject[(int)$deadline['id']])) {
        $completionByProject[(int)$deadline['id']] = getProjectTaskCompletionMap($db, $deadline['id']);
    }
    $deadline['avg_completion_percentage'] =
        $completionByProject[(int)$deadline['id']]['project_completion'];
    // v2.0: the mockup colours the due date by urgency and shows a
    // relative label beside it. Derived here rather than in SQL so the
    // pluralisation stays in one place.
    $__dd_days = (int)floor(
        (strtotime((string)$deadline['expected_completion_date']) - strtotime(date('Y-m-d'))) / 86400
    );
    if ($__dd_days < 0) {
        $deadline['urgency']      = 'overdue';
        $deadline['urgency_label'] = abs($__dd_days) . ' day' . (abs($__dd_days) === 1 ? '' : 's') . ' overdue';
    } elseif ($__dd_days <= 7) {
        $deadline['urgency']      = 'due_soon';
        $deadline['urgency_label'] = $__dd_days === 0 ? 'due today' : 'due soon';
    } else {
        $deadline['urgency']      = 'on_track';
        $deadline['urgency_label'] = 'on track';
    }
}
unset($deadline);

// Recent tasks
$recent_tasks = $db->fetchAll(
    "SELECT t.id, t.project_id, t.name, t.slug, t.status, t.completion_percentage, p.name as project_name, p.slug as project_slug,
            t.expected_completion_date, u.name as responsible_person_name
     FROM tasks t
     JOIN projects p ON t.project_id = p.id
     LEFT JOIN users u ON t.responsible_person_id = u.id
     WHERE p.responsible_person_id = ?
     ORDER BY t.updated_at DESC
     LIMIT 5",
    [$user_id]
);
foreach ($recent_tasks as &$task) {
    $taskProjectId = (int)$task['project_id'];
    if (!isset($completionByProject[$taskProjectId])) {
        $completionByProject[$taskProjectId] = getProjectTaskCompletionMap($db, $taskProjectId);
    }
    $task['completion_percentage'] =
        $completionByProject[$taskProjectId]['task_completion'][(int)$task['id']] ?? 0;
}
unset($task);

$title = "Dashboard";
$show_nav = true;
// v2.0: opt into the v2 shell (Phase 1.3) and load the page stylesheet.
// Placed before the header include so header.php sees the flag.
$use_v2_shell = true;
?>

<?php include 'includes/header.php'; ?>
<link rel="stylesheet" href="/assets/css/pages/dashboard.css">

<div class="page-header">
    <div class="page-header__text">
        <h1><i class="fas fa-gauge-high"></i> Dashboard</h1>
        <p class="page-header__sub">Welcome back, <?= e(getCurrentUser()['name']) ?>!</p>
    </div>
    <div class="page-actions">
        <a class="btn btn--secondary" href="/projects"><i class="fas fa-folder-open"></i> Projects</a>
        <a class="btn btn--primary" href="/projects/new"><i class="fas fa-plus"></i> New Project</a>
    </div>
</div>

<?php // Issue-034 v1.8.0 — backfill banner. One row, no per-task iteration.
      // Single inline alert pointing at the pre-filtered task list. ?>
<?php if ($missing_reason_total > 0): ?>
    <div class="dash-backfill">
        <div>
            <i class="fas fa-triangle-exclamation" aria-hidden="true"></i>
            <strong><?= (int)$missing_reason_total ?></strong> task<?= $missing_reason_total === 1 ? '' : 's' ?>
            need a status reason
            <?php if ($missing_delay_reason_count > 0 && $missing_on_hold_reason_count > 0): ?>
                (<?= (int)$missing_delay_reason_count ?> delayed, <?= (int)$missing_on_hold_reason_count ?> on hold).
            <?php elseif ($missing_delay_reason_count > 0): ?>
                (all delayed).
            <?php else: ?>
                (all on hold).
            <?php endif; ?>
        </div>
        <a class="btn btn--sm btn--warning" href="/tasks?status=delayed&amp;missing_reason=1">Review &rarr;</a>
    </div>
<?php endif; ?>

<!-- Statistics Cards -->
<div class="stat-grid">
    <div class="stat">
        <div class="stat__icon"><i class="fas fa-chart-pie"></i></div>
        <div>
            <div class="stat__value"><?= number_format($overall_completion, 1) ?>%</div>
            <div class="stat__label">Overall Completion</div>
        </div>
    </div>

    <div class="stat">
        <div class="stat__icon"><i class="fas fa-folder-open"></i></div>
        <div>
            <div class="stat__value"><?= $total_projects ?></div>
            <div class="stat__label">Total Projects</div>
            <div class="stat__meta"><?= $completed_projects ?> completed</div>
        </div>
    </div>

    <div class="stat">
        <div class="stat__icon"><i class="fas fa-list-check"></i></div>
        <div>
            <div class="stat__value"><?= $uncompleted_tasks ?></div>
            <div class="stat__label">Pending Tasks</div>
            <div class="stat__meta"><?= $total_tasks ?> total</div>
        </div>
    </div>

    <?php // Issue-034 v1.8.0 — Delayed Tasks stat card. ?>
    <div class="stat stat--delayed">
        <div class="stat__icon"><i class="fas fa-clock-rotate-left"></i></div>
        <div>
            <div class="stat__value"><?= (int)$delayed_tasks_count ?></div>
            <div class="stat__label">Delayed Tasks</div>
            <div class="stat__meta">past expected completion date</div>
        </div>
    </div>

    <?php // Issue-034 v1.8.0 — On Hold Tasks stat card. ?>
    <div class="stat stat--on-hold">
        <div class="stat__icon"><i class="fas fa-pause"></i></div>
        <div>
            <div class="stat__value"><?= (int)$on_hold_tasks_count ?></div>
            <div class="stat__label">On Hold Tasks</div>
            <div class="stat__meta">status set to on hold</div>
        </div>
    </div>

    <div class="stat stat--info">
        <div class="stat__icon"><i class="fas fa-calendar-day"></i></div>
        <div>
            <div class="stat__value"><?= count($upcoming_deadlines) ?></div>
            <div class="stat__label">Upcoming Deadlines</div>
        </div>
    </div>
</div>

    <div class="dash-grid">
        <!-- Recent Projects -->
        <div class="card">
            <div class="dash-section__header">
                <h2><i class="fas fa-folder-open"></i> Recent Projects</h2>
                <div class="card__actions">
                    <a class="btn btn--sm btn--ghost" href="/projects">View all <i class="fas fa-arrow-right"></i></a>
                </div>
            </div>

            <div class="card__body card__body--flush">
                <div class="dash-list">
                    <?php if (empty($recent_projects)): ?>
                        <div class="empty-state">
                            <div class="empty-state__icon"><i class="fas fa-folder-plus"></i></div>
                            <p>No projects yet. <a href="/projects/new">Create your first project</a></p>
                        </div>
                    <?php else: ?>
                        <?php foreach ($recent_projects as $project): ?>
                            <div class="dash-row">
                                <div>
                                    <h3 class="dash-row__title">
                                        <a href="/projects/<?= e($project['slug']) ?>"><?= e($project['name']) ?></a>
                                    </h3>
                                    <div class="dash-row__meta">
                                        <span class="status status--<?= $project['status'] ?>">
                                            <?= ucfirst(str_replace('_', ' ', $project['status'])) ?>
                                        </span>
                                        <?php if ($project['expected_completion_date']): ?>
                                            <span>
                                                <i class="fas fa-calendar"></i>
                                                <?= formatDateTime($project['expected_completion_date'], 'M j, Y') ?>
                                            </span>
                                        <?php endif; ?>
                                    </div>
                                </div>
                                <div class="dash-row__aside">
                                    <div class="progress">
                                        <div class="progress__fill" style="width: <?= $project['avg_completion_percentage'] ?>%"></div>
                                    </div>
                                    <span class="dash-row__pct"><?= number_format($project['avg_completion_percentage'], 1) ?>%</span>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- Upcoming Deadlines -->
        <div class="card">
            <div class="dash-section__header">
                <h2><i class="fas fa-clock-rotate-left"></i> Upcoming Deadlines</h2>
            </div>

            <div class="card__body card__body--flush">
                <div class="dash-list">
                    <?php if (empty($upcoming_deadlines)): ?>
                        <div class="empty-state">
                            <div class="empty-state__icon"><i class="fas fa-calendar-check"></i></div>
                            <p>No upcoming deadlines</p>
                        </div>
                    <?php else: ?>
                        <?php foreach ($upcoming_deadlines as $deadline): ?>
                            <div class="dash-row">
                                <div>
                                    <h3 class="dash-row__title">
                                        <a href="/projects/<?= e($deadline['slug']) ?>"><?= e($deadline['name']) ?></a>
                                    </h3>
                                    <div class="dash-row__meta">
                                        <span>
                                            <i class="fas fa-calendar"></i>
                                            <?= formatDateTime($deadline['expected_completion_date'], 'M j, Y') ?>
                                        </span>
                                    </div>
                                </div>
                                <div class="dash-row__aside">
                                    <div class="dash-ring"><?= number_format($deadline['avg_completion_percentage'], 0) ?>%</div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- Recent Tasks -->
        <div class="card">
            <div class="dash-section__header">
                <h2><i class="fas fa-tasks"></i> Recent Tasks</h2>
                <div class="card__actions">
                    <a class="btn btn--sm btn--ghost" href="/tasks">View all <i class="fas fa-arrow-right"></i></a>
                </div>
            </div>

            <div class="card__body card__body--flush">
                <div class="dash-list">
                    <?php if (empty($recent_tasks)): ?>
                        <div class="empty-state">
                            <div class="empty-state__icon"><i class="fas fa-tasks"></i></div>
                            <p>No tasks yet</p>
                        </div>
                    <?php else: ?>
                        <?php foreach ($recent_tasks as $task): ?>
                            <div class="dash-row">
                                <div>
                                    <h3 class="dash-row__title">
                                        <a href="/tasks/<?= e($task['slug']) ?>"><?= e($task['name']) ?></a>
                                    </h3>
                                    <div class="dash-row__meta">
                                        <?php // Plain text, not a link: the recent_tasks
                                              // query selects p.name as project_name but
                                              // NOT p.slug, so there is no href to build
                                              // here. Adding one would require changing the
                                              // SQL, which is out of scope for a restyle. ?>
                                        <span class="dash-row__project"><?= e($task['project_name']) ?></span>
                                        <?php if ($task['expected_completion_date']): ?>
                                            <span>
                                                <i class="fas fa-calendar"></i>
                                                <?= formatDateTime($task['expected_completion_date'], 'M j') ?>
                                            </span>
                                        <?php endif; ?>
                                    </div>
                                </div>
                                <div class="dash-row__aside dash-row__aside--stack">
                                    <span class="status status--<?= $task['status'] ?>">
                                        <?= ucfirst(str_replace('_', ' ', $task['status'])) ?>
                                    </span>
                                    <div class="progress">
                                        <div class="progress__fill" style="width: <?= $task['completion_percentage'] ?>%"></div>
                                    </div>
                                    <span class="dash-row__pct"><?= number_format($task['completion_percentage'], 0) ?>%</span>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>


<?php include 'includes/footer.php'; ?>