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
    "SELECT ps.*, u.name as responsible_person_name
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
    "SELECT p.id, p.name, p.expected_completion_date, ps.avg_completion_percentage, p.status
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
    "SELECT t.id, t.project_id, t.name, t.status, t.completion_percentage, p.name as project_name,
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

    <!-- The three sections below stack FULL-WIDTH, in this order: Recent
         Projects, Recent Tasks, Upcoming Deadlines. This is a deliberate
         divergence from docs/design/2.0/dashboard.html, which wraps the
         first two in .grid-sidebar--wide and places Upcoming Deadlines in a
         400px sidebar. Chosen after review: at 1440px the wide pair reads
         better given a full 1440px, and the deadline table's three columns
         were cramped at 400px. Do not "restore" the sidebar without asking.

         The tables are also allowed to exceed the inherited 1280px min-width
         at full width; a 1280px floor in a 655px track is what made the
         first attempt overflow. -->
    <!-- Recent Projects: PROJECT / STATUS / PROGRESS / TASKS / DUE -->
    <div class="card">
        <div class="card__header">
            <h2 class="card__title-icon"><i class="fas fa-folder-open"></i> Recent Projects</h2>
            <div class="card__actions">
                <a class="btn btn--sm btn--ghost" href="/projects">View all <i class="fas fa-arrow-right"></i></a>
            </div>
        </div>

        <?php if (empty($recent_projects)): ?>
            <div class="card__body">
                <div class="empty-state">
                    <div class="empty-state__icon"><i class="fas fa-folder-plus"></i></div>
                    <p>No projects yet. <a href="/projects/new">Create your first project</a></p>
                </div>
            </div>
        <?php else: ?>
            <div class="card__body card__body--flush">
                <div class="table-wrap">
                    <table class="table">
                        <thead>
                            <tr>
                                <th>Project</th>
                                <th>Status</th>
                                <th>Progress</th>
                                <th>Tasks</th>
                                <th>Due</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($recent_projects as $project): ?>
                            <?php $__p_total = (int)($project['total_tasks'] ?? 0);
                                  $__p_done  = (int)($project['completed_tasks'] ?? 0); ?>
                            <tr>
                                <td>
                                    <a href="/projects/<?= (int)$project['id'] ?>"><strong><?= e($project['name']) ?></strong></a>
                                </td>
                                <td>
                                    <span class="status status--<?= $project['status'] ?>">
                                        <?= ucfirst(str_replace('_', ' ', $project['status'])) ?>
                                    </span>
                                </td>
                                <td>
                                    <div class="progress">
                                        <div class="progress__fill" style="width: <?= $project['avg_completion_percentage'] ?>%"></div>
                                    </div>
                                </td>
                                <td class="text-sm text-muted nowrap"><?= $__p_done ?> / <?= $__p_total ?> tasks</td>
                                <td class="text-sm nowrap">
                                    <?= $project['expected_completion_date']
                                        ? e(formatDateTime($project['expected_completion_date'], 'M j, Y'))
                                        : '&mdash;' ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        <?php endif; ?>
    </div>

    <!-- Recent Tasks: TASK / PROJECT / STATUS / PROGRESS / OWNER -->
    <div class="card">
        <div class="card__header">
            <h2 class="card__title-icon"><i class="fas fa-clock-rotate-left"></i> Recent Tasks</h2>
            <div class="card__actions">
                <a class="btn btn--sm btn--ghost" href="/tasks">View all <i class="fas fa-arrow-right"></i></a>
            </div>
        </div>

        <?php if (empty($recent_tasks)): ?>
            <div class="card__body">
                <div class="empty-state">
                    <div class="empty-state__icon"><i class="fas fa-tasks"></i></div>
                    <p>No tasks yet</p>
                </div>
            </div>
        <?php else: ?>
            <div class="card__body card__body--flush">
                <div class="table-wrap">
                    <table class="table">
                        <thead>
                            <tr>
                                <th>Task</th>
                                <th>Project</th>
                                <th>Status</th>
                                <th>Progress</th>
                                <th>Owner</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($recent_tasks as $task): ?>
                            <tr>
                                <td>
                                    <a href="/tasks/<?= (int)$task['id'] ?>"><strong><?= e($task['name']) ?></strong></a>
                                </td>
                                <td class="text-sm text-muted">
                                    <?php // v2.0: the query now selects p.slug, so
                                          // the mockup's project link renders. ?>
                                    <a href="/projects/<?= (int)$task['project_id'] ?>"><?= e($task['project_name']) ?></a>
                                </td>
                                <td>
                                    <span class="status status--<?= $task['status'] ?>">
                                        <?= ucfirst(str_replace('_', ' ', $task['status'])) ?>
                                    </span>
                                </td>
                                <td>
                                    <div class="progress">
                                        <div class="progress__fill" style="width: <?= $task['completion_percentage'] ?>%"></div>
                                    </div>
                                </td>
                                <td class="text-sm nowrap"><?= $task['responsible_person_name'] ? e($task['responsible_person_name']) : '&mdash;' ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        <?php endif; ?>
    </div>

    <!-- Upcoming Deadlines: no <thead> in the mockup; 4 columns, date
         coloured by urgency and a relative label beside it.

         ⚠ COLUMN 2 DIVERGES DELIBERATELY. The mockup's column 1 is a
         milestone/task name ("Site handover drawings") and column 2 its
         project ("Harbour Point Renovation") -- i.e. it lists TASKS
         nearing their due date. $upcoming_deadlines queries the PROJECTS
         table, so there is no milestone name to show and no second
         identifier to disambiguate with. Rather than print the project
         name twice, or invent a milestone source that does not exist,
         this table renders the 3 columns the data actually supports:
         project / due date / urgency label. The date colouring and the
         relative label -- the parts the mockup's urgency logic exists to
         drive -- are reproduced exactly. -->
    <div class="card">
        <div class="card__header">
            <h2 class="card__title-icon"><i class="fas fa-calendar-day"></i> Upcoming Deadlines</h2>
        </div>

        <?php if (empty($upcoming_deadlines)): ?>
            <div class="card__body">
                <div class="empty-state">
                    <div class="empty-state__icon"><i class="fas fa-calendar-check"></i></div>
                    <p>No upcoming deadlines</p>
                </div>
            </div>
        <?php else: ?>
            <div class="card__body card__body--flush">
                <div class="table-wrap">
                    <table class="table table--compact">
                        <tbody>
                        <?php foreach ($upcoming_deadlines as $deadline): ?>
                            <tr>
                                <td>
                                    <a href="/projects/<?= (int)$deadline['id'] ?>"><strong><?= e($deadline['name']) ?></strong></a>
                                </td>
                                <td class="text-sm nowrap <?= $deadline['urgency'] === 'overdue' ? 'text-danger' : ($deadline['urgency'] === 'due_soon' ? 'text-warning' : 'text-muted') ?>">
                                    <?= e(formatDateTime($deadline['expected_completion_date'], 'M j, Y')) ?>
                                </td>
                                <td class="text-xs text-muted nowrap"><?= e($deadline['urgency_label']) ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        <?php endif; ?>
    </div>

<?php include 'includes/footer.php'; ?>