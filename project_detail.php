<?php
require_once 'config.php';
require_once 'includes/Database.php';
require_once 'includes/functions.php';

requireLogin();

$db = Database::getInstance();
$user_id = getCurrentUserId();
$project_id = $_GET['id'] ?? null;

if (!$project_id) {
    redirect('/projects', 'Invalid project ID.', 'danger');
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
    redirect('/projects', 'Project not found or access denied.', 'danger');
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

// Issue-011: build a parent → child estimates map for the rows on this page
// so we can render inherit-mode estimates without N+1 queries.
$childEstimatesByParent = [];
$parentIds = [];
foreach ($tasks as $task) {
    if (!empty($task['inherit_subtask_hours']) && (int)$task['subtask_count'] > 0) {
        $parentIds[] = (int)$task['id'];
    }
}
if (!empty($parentIds)) {
    $placeholders = implode(',', array_fill(0, count($parentIds), '?'));
    $child_rows = $db->fetchAll(
        "SELECT parent_task_id, estimated_hours FROM tasks WHERE parent_task_id IN ($placeholders)",
        $parentIds
    );
    foreach ($child_rows as $row) {
        $childEstimatesByParent[(int)$row['parent_task_id']][] = $row;
    }
}

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
// v2.0: without this the page rendered outside the .app-shell, so it had no
// sidebar and every v2 component below lost its layout context. Every other v2
// page (dashboard, projects, tasks, contacts) sets it; this page did not,
// which is why it looked unstyled. See includes/header.php line ~94.
$use_v2_shell = true;
?>

<?php include 'includes/header.php'; ?>
<link rel="stylesheet" href="/assets/css/pages/project_detail.css">

<nav class="breadcrumb" aria-label="Breadcrumb">
    <a href="/dashboard">Dashboard</a>
    <span aria-hidden="true">/</span>
    <a href="/projects">Projects</a>
    <span aria-hidden="true">/</span>
    <span><?= e($project['name']) ?></span>
</nav>

<div class="page-header">
    <div class="page-header__text">
        <h1><i class="fas fa-folder-open"></i> <?= e($project['name']) ?></h1>
        <?php if ($project['description']): ?>
            <p class="page-header__sub"><?= e($project['description']) ?></p>
        <?php endif; ?>
        <div class="chip-row">
            <span class="status status--<?= e($project['status']) ?>"><?= ucfirst(str_replace('_', ' ', $project['status'])) ?></span>
            <?php if (!empty($project['share_code'])): ?>
                <span class="badge badge--outline">Code <?= e($project['share_code']) ?></span>
            <?php endif; ?>
            <?php if (!empty($project['expected_completion_date'])): ?>
                <span class="badge badge--outline">
                    <i class="fas fa-calendar"></i>
                    Due <?= formatDateTime($project['expected_completion_date'], 'M j, Y') ?>
                </span>
            <?php endif; ?>
        </div>
    </div>
    <div class="page-actions">
        <a href="/projects/<?= (int)$project['id'] ?>/edit" class="btn btn--secondary">
            <i class="fas fa-pen"></i> Edit Project
        </a>
        <a href="/tasks/new?project_id=<?= (int)$project['id'] ?>" class="btn btn--primary">
            <i class="fas fa-plus"></i> Add Task
        </a>
    </div>
</div>

<div class="grid-sidebar">
    <div>
        <!-- Client share code -->
        <div class="card">
            <div class="card__header">
                <h2 class="card__title-icon"><i class="fas fa-share-nodes"></i> Client share code</h2>
            </div>
            <div class="card__body">
                <?php if (!empty($project['share_code'])):
                    $share_url = buildClientShareUrl($project['share_code']); ?>
                    <div class="share-box">
                        <code id="shareCode"
                              data-share-url="<?= e($share_url) ?>"><?= e($project['share_code']) ?></code>
                        <button type="button" class="btn btn--sm btn--secondary" onclick="copyShareUrl(this)" title="Copy link">
                            <i class="far fa-copy"></i> Copy link
                        </button>
                    </div>
                    <p class="text-xs text-muted pd-share-hint">
                        <i class="fas fa-globe"></i>
                        Client view:
                        <a href="<?= e($share_url) ?>" class="text-mono"><?= e($share_url) ?></a>
                    </p>
                    <p class="text-xs text-muted pd-share-note">
                        Anyone with this link can view a read-only progress dashboard for this project.
                        Rotating the code invalidates the current link &mdash; that action lives on the
                        <a href="/projects/<?= (int)$project['id'] ?>/edit">edit page</a>.
                    </p>
                <?php else: ?>
                    <div class="empty-state">
                        <i class="fas fa-share-nodes"></i>
                        <p>No share code (older project). Recreate the project to generate one.</p>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Project tasks -->
        <div class="card">
            <div class="card__header">
                <h2 class="card__title-icon">
                    <i class="fas fa-list-check"></i> Project Tasks
                    <span class="badge badge--neutral"><?= count($tasks) ?></span>
                </h2>
                <div class="card__actions">
                    <a href="/tasks?project_id=<?= (int)$project['id'] ?>" class="btn btn--sm btn--ghost">
                        View all <i class="fas fa-arrow-right"></i>
                    </a>
                    <a href="/tasks/new?project_id=<?= (int)$project['id'] ?>" class="btn btn--sm btn--primary">
                        <i class="fas fa-plus"></i> Add Task
                    </a>
                </div>
            </div>
            <div class="card__body card__body--flush">
                <?php if (empty($tasks)): ?>
                    <div class="empty-state">
                        <i class="fas fa-list-check"></i>
                        <p>No tasks yet. <a href="/tasks/new?project_id=<?= (int)$project['id'] ?>">Create your first task</a></p>
                    </div>
                <?php else: ?>
                    <div class="table-wrap">
                        <table class="table">
                            <thead>
                                <tr>
                                    <th>Task</th>
                                    <th>Status</th>
                                    <th>Progress</th>
                                    <th>Owner</th>
                                    <th class="text-right">%</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($tasks as $task): ?>
                                    <?php
                                    // Issue-011: render Total Time using the shared helper
                                    // so the project surface agrees with task detail + tasks + client portal.
                                    $child_estimates_for_task = $childEstimatesByParent[(int)$task['id']] ?? [];
                                    $resolved_total = resolveTaskEstimateHours($task, $child_estimates_for_task);
                                    $inherit_active = !empty($task['inherit_subtask_hours']) && (int)$task['subtask_count'] > 0;
                                    $pct = (int)round((float)$task['completion_percentage']);
                                    ?>
                                    <tr>
                                        <td>
                                            <a href="/tasks/<?= (int)$task['id'] ?>"><strong><?= e($task['name']) ?></strong></a>
                                            <?php if ($task['contact_person_name'] || $task['expected_completion_date'] || $resolved_total !== null || $inherit_active || (int)$task['subtask_count'] > 0): ?>
                                                <div class="pd-task-sub">
                                                    <?php if ($task['contact_person_name']): ?>
                                                        <span class="text-xs text-muted">
                                                            <i class="fas fa-address-card"></i> <?= e($task['contact_person_name']) ?>
                                                        </span>
                                                    <?php endif; ?>
                                                    <?php if ($task['expected_completion_date']): ?>
                                                        <span class="text-xs text-muted">
                                                            <i class="fas fa-calendar"></i>
                                                            <?= formatDateTime($task['expected_completion_date'], 'M j, Y') ?>
                                                        </span>
                                                    <?php endif; ?>
                                                    <?php if ($resolved_total !== null): ?>
                                                        <span class="text-xs text-muted">
                                                            <i class="fas fa-clock"></i> <?= e(formatTaskEstimate($resolved_total)) ?>
                                                            <?php if ($inherit_active): ?>
                                                                <i class="fas fa-link inherit-indicator"
                                                                   title="Auto-calculated from sub-tasks"
                                                                   aria-label="Auto-calculated from sub-tasks"></i>
                                                            <?php endif; ?>
                                                        </span>
                                                    <?php elseif ($inherit_active): ?>
                                                        <?php // Spec issue-011 § "Validation edge cases":
                                                              // inherit-on with no positive child estimates
                                                              // must render the literal "Not estimated
                                                              // (no sub-task estimates)" copy
                                                              // (issue-011 tester-note-001). ?>
                                                        <span class="text-xs not-estimated">
                                                            <i class="fas fa-clock"></i> Not estimated (no sub-task estimates)
                                                        </span>
                                                    <?php endif; ?>
                                                    <?php if ((int)$task['subtask_count'] > 0): ?>
                                                        <span class="text-xs text-muted">
                                                            <i class="fas fa-sitemap"></i> <?= (int)$task['subtask_count'] ?> subtasks
                                                        </span>
                                                    <?php endif; ?>
                                                </div>
                                            <?php endif; ?>
                                        </td>
                                        <td><span class="status status--<?= e($task['status']) ?>"><?= ucfirst(str_replace('_', ' ', $task['status'])) ?></span></td>
                                        <td style="min-width:120px;">
                                            <?php
                                            // Tone is derived from the value in CSS via the modifier
                                            // classes, so no JS reads or rewrites a percentage here.
                                            $tone = $pct >= 80 ? 'success' : ($pct >= 50 ? 'warning' : 'danger');
                                            ?>
                                            <div class="progress progress--<?= $tone ?>">
                                                <div class="progress__fill" style="width:<?= $pct ?>"></div>
                                            </div>
                                        </td>
                                        <td class="text-sm nowrap"><?= e($task['responsible_person_name']) ?></td>
                                        <td class="text-sm nowrap text-right"><?= $pct ?>%</td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Project contacts -->
        <div class="card">
            <div class="card__header">
                <h2 class="card__title-icon">
                    <i class="fas fa-address-book"></i> Project Contacts
                    <span class="badge badge--neutral"><?= count($project_contacts) ?></span>
                </h2>
                <div class="card__actions">
                    <a href="/contacts?project_id=<?= (int)$project['id'] ?>" class="btn btn--sm btn--ghost">
                        View all <i class="fas fa-arrow-right"></i>
                    </a>
                    <a href="/contacts/new?project_id=<?= (int)$project['id'] ?>" class="btn btn--sm btn--primary">
                        <i class="fas fa-plus"></i> Add contact
                    </a>
                </div>
            </div>
            <div class="card__body card__body--flush">
                <?php if (empty($project_contacts)): ?>
                    <div class="empty-state">
                        <i class="fas fa-address-book"></i>
                        <p>No contacts assigned. <a href="/contacts/new?project_id=<?= (int)$project['id'] ?>">Add a contact</a></p>
                    </div>
                <?php else: ?>
                    <div class="table-wrap">
                        <table class="table">
                            <thead>
                                <tr>
                                    <th>Name</th>
                                    <th>Position</th>
                                    <th>Company</th>
                                    <th>Email</th>
                                    <th>Phone</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($project_contacts as $contact): ?>
                                    <tr>
                                        <td><a href="/contacts/<?= (int)$contact['id'] ?>"><strong><?= e($contact['name']) ?></strong></a></td>
                                        <td class="text-sm text-muted"><?= e($contact['position'] ?: '—') ?></td>
                                        <td class="text-sm text-muted"><?= e($contact['company'] ?: '—') ?></td>
                                        <td>
                                            <?php if ($contact['email']): ?>
                                                <a href="mailto:<?= e($contact['email']) ?>"><i class="fas fa-envelope"></i> <?= e($contact['email']) ?></a>
                                            <?php else: ?><span class="text-muted">—</span><?php endif; ?>
                                        </td>
                                        <td class="text-sm nowrap">
                                            <?php if ($contact['mobile']): ?>
                                                <a href="tel:<?= e($contact['mobile']) ?>"><i class="fas fa-phone"></i> <?= e($contact['mobile']) ?></a>
                                            <?php else: ?><span class="text-muted">—</span><?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Recent activity -->
        <div class="card">
            <div class="card__header">
                <h2 class="card__title-icon"><i class="fas fa-history"></i> Recent Activity</h2>
            </div>
            <div class="card__body">
                <?php if (empty($recent_activity)): ?>
                    <div class="empty-state">
                        <i class="fas fa-history"></i>
                        <p>No recent activity</p>
                    </div>
                <?php else: ?>
                    <ul class="timeline">
                        <?php foreach ($recent_activity as $activity): ?>
                            <li>
                                <span class="timeline__icon"><i class="fas fa-edit"></i></span>
                                <div class="timeline__body">
                                    <div>
                                        <strong><?= e($activity['responsible_person_name']) ?></strong>
                                        updated task
                                        <a href="/tasks/<?= (int)$activity['id'] ?>"><?= e($activity['name']) ?></a>
                                        to <span class="status status--<?= e($activity['status']) ?>"><?= ucfirst(str_replace('_', ' ', $activity['status'])) ?></span>
                                    </div>
                                    <div class="timeline__meta"><?= formatDateTime($activity['updated_at'], 'M j, Y g:i A') ?></div>
                                </div>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Sidebar -->
    <div>
        <div class="stat-grid pd-stats">
            <div class="stat">
                <div class="stat__icon"><i class="fas fa-chart-pie"></i></div>
                <div>
                    <div class="stat__value"><?= number_format($project['avg_completion_percentage'] ?? 0, 1) ?>%</div>
                    <div class="stat__label">Avg Completion</div>
                </div>
            </div>
            <div class="stat">
                <div class="stat__icon"><i class="fas fa-list-check"></i></div>
                <div>
                    <div class="stat__value"><?= (int)($project['total_tasks'] ?? 0) ?></div>
                    <div class="stat__label">Total Tasks</div>
                    <?php if ((int)($project['completed_tasks'] ?? 0) > 0): ?>
                        <div class="stat__meta"><?= (int)$project['completed_tasks'] ?> completed</div>
                    <?php endif; ?>
                </div>
            </div>
            <div class="stat<?= (int)($project['uncompleted_tasks'] ?? 0) > 0 ? ' stat--on-hold' : '' ?>">
                <div class="stat__icon"><i class="fas fa-hourglass-half"></i></div>
                <div>
                    <div class="stat__value"><?= (int)($project['uncompleted_tasks'] ?? 0) ?></div>
                    <div class="stat__label">Uncompleted</div>
                </div>
            </div>
            <div class="stat">
                <div class="stat__icon"><i class="fas fa-address-book"></i></div>
                <div>
                    <div class="stat__value"><?= (int)($project['contact_count'] ?? 0) ?></div>
                    <div class="stat__label">Contacts</div>
                </div>
            </div>
        </div>

        <div class="card">
            <div class="card__header">
                <h2 class="card__title-icon"><i class="fas fa-sitemap"></i> Project details</h2>
            </div>
            <div class="card__body">
                <div class="detail-list">
                    <div class="detail-row">
                        <span class="detail-row__k">Status</span>
                        <span class="detail-row__v"><span class="status status--<?= e($project['status']) ?>"><?= ucfirst(str_replace('_', ' ', $project['status'])) ?></span></span>
                    </div>
                    <?php if (!empty($project['scope'])): ?>
                        <div class="detail-row detail-row--stacked">
                            <span class="detail-row__k">Scope</span>
                            <span class="detail-row__v">
                                <ul class="pd-scope-list">
                                    <?php foreach (preg_split('/\r\n|\r|\n/', (string)$project['scope']) as $line):
                                        $line = trim($line);
                                        if ($line === '') continue; ?>
                                        <li><?= e($line) ?></li>
                                    <?php endforeach; ?>
                                </ul>
                            </span>
                        </div>
                    <?php endif; ?>
                    <div class="detail-row">
                        <span class="detail-row__k">Budget</span>
                        <span class="detail-row__v">
                            <?php if ($project['budget_amount'] !== null && $project['currency']): ?>
                                <?= e(formatMoney($project['budget_amount'], $project['currency'])) ?>
                            <?php else: ?>
                                <span class="text-muted">Not set</span>
                            <?php endif; ?>
                        </span>
                    </div>
                    <div class="detail-row">
                        <span class="detail-row__k">Start date</span>
                        <span class="detail-row__v">
                            <?php if (!empty($project['start_date'])): ?>
                                <?= formatDateTime($project['start_date'], 'M j, Y') ?>
                            <?php else: ?>
                                <span class="text-muted">Not set</span>
                            <?php endif; ?>
                        </span>
                    </div>
                    <div class="detail-row">
                        <span class="detail-row__k">Due date</span>
                        <span class="detail-row__v">
                            <?php if (!empty($project['expected_completion_date'])): ?>
                                <?= formatDateTime($project['expected_completion_date'], 'M j, Y') ?>
                            <?php else: ?>
                                <span class="text-muted">Not set</span>
                            <?php endif; ?>
                        </span>
                    </div>
                    <div class="detail-row">
                        <span class="detail-row__k">Owner</span>
                        <span class="detail-row__v"><?= e($project['responsible_person_name']) ?></span>
                    </div>
                    <div class="detail-row">
                        <span class="detail-row__k">Created</span>
                        <span class="detail-row__v"><?= formatDateTime($project['created_at'], 'M j, Y') ?></span>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
// Copy the client share URL. Kept as a named function because the button calls
// it inline; nothing else on this page needs JS. The v1.8.0 progress-circle
// script is gone — the bar's width and tone are plain CSS now.
function copyShareUrl(btn) {
    var code = document.getElementById('shareCode');
    if (!code) return;
    // The URL is no longer rendered in an <input>, so it lives on the code
    // element as data-share-url. One source of truth, nothing to keep in sync.
    var text = code.getAttribute('data-share-url') || code.textContent.trim();
    if (!text) return;

    var done = false;
    try { done = document.execCommand('copy'); } catch (e) {}
    if (navigator.clipboard && !done) {
        navigator.clipboard.writeText(text).catch(function(){});
    }

    // Feedback must not depend on focus: the old version read
    // document.activeElement, which is not the button after a click on some
    // browsers, so the "Copied" confirmation silently never appeared.
    if (!btn) return;
    var original = btn.innerHTML;
    btn.innerHTML = '<i class="far fa-check"></i> Copied';
    btn.disabled = true;
    setTimeout(function() { btn.innerHTML = original; btn.disabled = false; }, 1500);
}
</script>


<?php include 'includes/footer.php'; ?>
