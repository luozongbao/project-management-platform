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
    $where_conditions[] = "t.project_id = ?";
    $params[] = $project_id;
}

// Filter by status. Issue-034 (v1.8.0) adds a computed "delayed"
// pseudo-status: tasks whose expected_completion_date is in the past
// AND whose real status is not 'completed'. Because it is *computed*
// (not a real enum value), it gets its own WHERE clause branch instead
// of `t.status = ?`.
$status_filter = $_GET['status'] ?? '';
if ($status_filter && $status_filter !== 'all') {
    if ($status_filter === 'delayed') {
        $where_conditions[] = "t.expected_completion_date IS NOT NULL";
        $where_conditions[] = "t.expected_completion_date < CURDATE()";
        $where_conditions[] = "t.status != 'completed'";
    } else {
        $where_conditions[] = "t.status = ?";
        $params[] = $status_filter;
    }
}

// Issue-034 v1.8.0: optional `missing_reason=1` query param narrows
// the delayed list to the rows that still need a reason recorded.
// The dashboard backfill banner links here.
$missing_reason = $_GET['missing_reason'] ?? '';
if ($missing_reason === '1' && $status_filter === 'delayed') {
    $where_conditions[] = "(t.delay_reason IS NULL OR TRIM(t.delay_reason) = '')";
}

// Search functionality
$search = $_GET['search'] ?? '';
if ($search) {
    $where_conditions[] = "(t.name LIKE ? OR t.description LIKE ?)";
    $search_param = '%' . $search . '%';
    $params[] = $search_param;
    $params[] = $search_param;
}

$where_clause = "WHERE " . implode(" AND ", $where_conditions);

// Get tasks with project and user information
$tasks = $db->fetchAll(
    "SELECT t.*, p.name as project_name, p.id as project_id, u.name as responsible_person_name,
            c.name as contact_person_name,
            (SELECT COUNT(*) FROM tasks st WHERE st.parent_task_id = t.id) as subtask_count,
            CASE 
                WHEN t.parent_task_id IS NOT NULL THEN 'subtask'
                ELSE 'task'
            END as task_type
     FROM tasks t
     JOIN projects p ON t.project_id = p.id
     LEFT JOIN users u ON t.responsible_person_id = u.id
     LEFT JOIN contacts c ON t.contact_person_id = c.id
     $where_clause
     ORDER BY t.created_at DESC",
    $params
);

$completionByProject = [];
foreach ($tasks as &$task) {
    $taskProjectId = (int)$task['project_id'];
    if (!isset($completionByProject[$taskProjectId])) {
        $completionByProject[$taskProjectId] = getProjectTaskCompletionMap($db, $taskProjectId);
    }
    $task['completion_percentage'] =
        $completionByProject[$taskProjectId]['task_completion'][(int)$task['id']] ?? 0;
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

// Get projects for filter dropdown
$user_projects = $db->fetchAll(
    "SELECT id, name FROM projects WHERE responsible_person_id = ? ORDER BY name",
    [$user_id]
);

// Get task statistics
$task_stats = $db->fetchOne(
    "SELECT 
        COUNT(*) as total_tasks,
        COUNT(CASE WHEN t.status = 'completed' THEN 1 END) as completed_tasks,
        COUNT(CASE WHEN t.status != 'completed' THEN 1 END) as pending_tasks,
        AVG(t.completion_percentage) as avg_completion
     FROM tasks t
     JOIN projects p ON t.project_id = p.id
     WHERE p.responsible_person_id = ?",
    [$user_id]
);

$title = "Tasks";
$show_nav = true;
$use_v2_shell = true;

// Page-header subtitle (mockup). Built from data already fetched above, so
// it costs no extra query. Counts delayed/on-hold the same way the WHERE
// builder does: "delayed" is a *computed* pseudo-status, not an enum value.
$__t_total = (int)($task_stats['total_tasks'] ?? 0);
$__t_completed = (int)($task_stats['completed_tasks'] ?? 0);
$__t_pending = (int)($task_stats['pending_tasks'] ?? 0);
$__t_projects = count($user_projects);
$__t_delayed = 0;
$__t_on_hold = 0;
$__t_missing_reason = 0;
foreach ($tasks as $__t) {
    $__t_d = ($__t['status'] ?? '') !== 'completed'
        && !empty($__t['expected_completion_date'])
        && strtotime((string)$__t['expected_completion_date']) < strtotime(date('Y-m-d'));
    if ($__t_d) {
        $__t_delayed++;
        if (trim((string)($__t['delay_reason'] ?? '')) === '') {
            $__t_missing_reason++;
        }
    }
    if (($__t['status'] ?? '') === 'on_hold') {
        $__t_on_hold++;
    }
}
unset($__t);

$__t_subtitle = $__t_total . ' tasks across ' . $__t_projects . ' project'
    . ($__t_projects === 1 ? '' : 's');
if ($__t_delayed > 0) {
    $__t_subtitle .= ' · ' . $__t_delayed . ' delayed';
}
if ($__t_on_hold > 0) {
    $__t_subtitle .= ' · ' . $__t_on_hold . ' on hold';
}
?>

<?php include 'includes/header.php'; ?>
<link rel="stylesheet" href="/assets/css/pages/tasks.css">

<div class="page-header">
    <div class="page-header__text">
        <h1>
            <i class="fas fa-tasks"></i>
            Tasks
            <?php if ($project_id): ?>
                <?php
                $project_name = $db->fetchOne("SELECT name FROM projects WHERE id = ?", [$project_id])['name'];
                ?>
                <span class="tk-context">· <?= e($project_name) ?></span>
            <?php endif; ?>
        </h1>
        <p class="page-header__sub"><?= e($__t_subtitle) ?></p>
    </div>
    <div class="page-actions">
        <a href="/tasks/new<?= $project_id ? '?project_id=' . $project_id : '' ?>" class="btn btn--primary">
            <i class="fas fa-plus"></i>
            New Task
        </a>
    </div>
</div>

<?php // Issue-034 v1.8.0 — backfill banner when the user lands on the
      // delayed+missing_reason view from dashboard.php. Promoted from the
      // page-local .owner-backfill-banner to the shared v2 .alert. ?>
<?php if ($status_filter === 'delayed' && $missing_reason === '1'): ?>
    <div class="alert alert--info" role="status">
        <i class="fas fa-hourglass-half" aria-hidden="true"></i>
        <div class="alert__body">
            Showing delayed tasks still missing a reason. Edit each one or set the reason inline.
            <div style="margin-top:.6rem;">
                <a href="/tasks?status=delayed">Show all delayed &rarr;</a>
            </div>
        </div>
    </div>
<?php endif; ?>

<div class="stat-grid">
    <div class="stat">
        <div class="stat__icon"><i class="fas fa-list-check"></i></div>
        <div>
            <div class="stat__value"><?= $__t_total ?></div>
            <div class="stat__label">Total Tasks</div>
        </div>
    </div>

    <div class="stat stat--success">
        <div class="stat__icon"><i class="fas fa-circle-check"></i></div>
        <div>
            <div class="stat__value"><?= $__t_completed ?></div>
            <div class="stat__label">Completed</div>
        </div>
    </div>

    <div class="stat stat--on-hold">
        <div class="stat__icon"><i class="fas fa-hourglass-half"></i></div>
        <div>
            <div class="stat__value"><?= $__t_pending ?></div>
            <div class="stat__label">Pending</div>
        </div>
    </div>

    <div class="stat stat--info">
        <div class="stat__icon"><i class="fas fa-chart-pie"></i></div>
        <div>
            <div class="stat__value"><?= number_format($task_stats['avg_completion'] ?? 0, 1) ?>%</div>
            <div class="stat__label">Avg Progress</div>
        </div>
    </div>
</div>

<!-- Filter bar: shared .filter-bar component, matching tasks.html. This was
     previously a .card wrapper around a bespoke .tk-filter-form grid; the
     filters, their name=/id= attributes and the GET behaviour are unchanged,
     only the container and the action-wrapper class were swapped. -->
<form method="GET" class="filter-bar" data-filter-form>
    <?php if ($project_id): ?>
        <input type="hidden" name="project_id" value="<?= $project_id ?>">
    <?php endif; ?>

    <div class="field">
        <label for="search">Search Tasks</label>
        <input class="input" type="search" id="search" name="search"
               value="<?= e($search) ?>"
               placeholder="Task name or description...">
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
    <?php endif; ?>

    <div class="field">
        <label for="status_filter">Status</label>
        <select class="select" id="status_filter" name="status">
            <option value="all" <?= $status_filter == 'all' ? 'selected' : '' ?>>All Status</option>
            <option value="not_started" <?= $status_filter == 'not_started' ? 'selected' : '' ?>>Not Started</option>
            <option value="in_progress" <?= $status_filter == 'in_progress' ? 'selected' : '' ?>>In Progress</option>
            <option value="completed" <?= $status_filter == 'completed' ? 'selected' : '' ?>>Completed</option>
            <option value="on_hold" <?= $status_filter == 'on_hold' ? 'selected' : '' ?>>On Hold</option>
            <?php // Issue-034 v1.8.0 — computed "delayed" filter.
                  // The option is always rendered so users can
                  // intentionally land on it; the WHERE-clause
                  // builder special-cases the value (see top
                  // of this file). ?>
            <option value="delayed" <?= $status_filter == 'delayed' ? 'selected' : '' ?>>Delayed</option>
        </select>
    </div>

    <div class="filter-bar__actions">
        <button type="submit" class="btn btn--primary">
            <i class="fas fa-filter"></i>
            Apply
        </button>
        <a href="/tasks<?= $project_id ? '?project_id=' . $project_id : '' ?>" class="btn btn--secondary">
            <i class="fas fa-rotate-left"></i>
            Reset
        </a>
    </div>
</form>

<!-- Tasks List: 9-column table per tasks.html.
         Replaces the v1.8.0 card list. Every behaviour the cards carried is
         preserved and mapped onto the table:
           tk-card accent      -> .tk-row + .tk-row--delayed / --on-hold
           tk-desc             -> DROPPED (the mockup has no description column;
                                  tasks.php already exposes it on the detail page)
           tk-meta groups      -> discrete Project / Responsible / Contact / Due /
                                  Completed / Subtasks columns
           tk-progress bar     -> DROPPED from the row (mockup has no progress
                                  column); percentage still drives the stat tiles
           tk-reasons chips    -> a .reason-row sub-row spanning all 9 columns
           reason-missing icon -> kept, next to the status badge
           quick-complete-btn  -> kept as the first row-action, now carrying
                                  data-row-id instead of relying on .task-card
         The .reason-row pattern is NOT new: v2-components.css already ships
         .reason-row__cell / .reason-stack / the :has(+ .reason-row) hover group
         for exactly this layout. -->
    <?php if (empty($tasks)): ?>
        <div class="empty-state">
            <div class="empty-state__icon">
                <i class="fas fa-tasks"></i>
            </div>
            <h3>No Tasks Found</h3>
            <p>
                <?php if ($search || $status_filter): ?>
                    No tasks match your current filters. Try adjusting your search criteria.
                <?php else: ?>
                    You haven't created any tasks yet. Create your first task to get started!
                <?php endif; ?>
            </p>
            <a href="/tasks/new<?= $project_id ? '?project_id=' . $project_id : '' ?>" class="btn btn--primary">
                <i class="fas fa-plus"></i>
                Create First Task
            </a>
        </div>
    <?php else: ?>
        <div class="card tk-table-card">
            <div class="card__header">
                <h2 class="card__title-icon">
                    <i class="fas fa-list"></i>
                    Task list
                </h2>
                <div class="card__actions">
                    <span class="badge badge--neutral">Showing <?= count($tasks) ?> of <?= $__t_total ?></span>
                </div>
            </div>
            <div class="card__body card__body--flush">
                <div class="table-wrap">
                <table class="table tk-table">
                    <thead>
                        <tr>
                            <th>Task</th>
                            <th>Project</th>
                            <th>Status</th>
                            <th>Responsible</th>
                            <th>Contact</th>
                            <th>Due</th>
                            <th>Completed</th>
                            <th>Subtasks</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($tasks as $task): ?>
                        <?php // Issue-034 v1.8.0 — the computed "delayed"
                              // pseudo-status and the reason-presence checks.
                              // Unchanged from the card version; only the
                              // rendering target moved from a card to a row. ?>
                        <?php
                            $_is_delayed = ($task['status'] ?? '') !== 'completed'
                                && !empty($task['expected_completion_date'])
                                && strtotime((string)$task['expected_completion_date']) < strtotime(date('Y-m-d'));
                            $_is_on_hold = ($task['status'] ?? '') === 'on_hold';
                            $_delay_reason   = isset($task['delay_reason'])   ? trim((string)$task['delay_reason'])   : '';
                            $_on_hold_reason = isset($task['on_hold_reason']) ? trim((string)$task['on_hold_reason']) : '';

                            // Row modifier: on-hold wins over delayed (same
                            // precedence the card accent used).
                            $__tk_mod = '';
                            if ($_is_on_hold) {
                                $__tk_mod = ' tk-row--on-hold';
                            } elseif ($_is_delayed) {
                                $__tk_mod = ' tk-row--delayed';
                            } elseif (($task['status'] ?? '') === 'completed') {
                                $__tk_mod = ' tk-row--completed';
                            }
                            $__tk_mod .= ($task['task_type'] === 'subtask') ? ' tk-row--subtask' : '';

                            // Inline estimate resolution (issue-011).
                            $child_estimates_for_task = $childEstimatesByParent[(int)$task['id']] ?? [];
                            $resolved_total = resolveTaskEstimateHours($task, $child_estimates_for_task);
                            $inherit_active = !empty($task['inherit_subtask_hours']) && (int)$task['subtask_count'] > 0;

                            $__tk_overdue = !empty($task['expected_completion_date'])
                                && strtotime((string)$task['expected_completion_date']) < time()
                                && ($task['status'] ?? '') !== 'completed';
                        ?>
                        <tr class="tk-row<?= $__tk_mod ?>" data-task-id="<?= (int)$task['id'] ?>">

                            <!-- 1. Task -->
                            <td>
                                <div class="chip-row" style="flex-wrap:wrap;">
                                    <?php // The mockup has no type glyph — subtask-ness
                                          // is already conveyed by the row accent
                                          // (tk-row--subtask) and by the indent. A
                                          // bespoke icon diverged from it. ?>
                                    <a href="/tasks/<?= (int)$task['id'] ?>"><strong><?= e($task['name']) ?></strong></a>
                                </div>
                            </td>

                            <!-- 2. Project -->
                            <td class="text-sm text-muted nowrap">
                                <?php if ($project_id): ?>
                                    &mdash;
                                <?php else: ?>
                                    <a href="/projects/<?= (int)$task['project_id'] ?>"><?= e($task['project_name']) ?></a>
                                <?php endif; ?>
                            </td>

                            <!-- 3. Status (with the computed delayed badge) -->
                            <td>
                                <div class="chip-row">
                                    <span class="status status--<?= $task['status'] ?>">
                                        <?= ucfirst(str_replace('_', ' ', $task['status'])) ?>
                                        <?php // Superscript "reason missing" mark (issue-034). ?>
                                        <?php if ($_is_on_hold && $_on_hold_reason === ''): ?>
                                            <i class="fas fa-exclamation-circle reason-missing"
                                               title="On-hold reason missing"
                                               aria-label="On-hold reason missing"></i>
                                        <?php elseif ($_is_delayed && $_delay_reason === ''): ?>
                                            <i class="fas fa-exclamation-circle reason-missing"
                                               title="Delay reason missing"
                                               aria-label="Delay reason missing"></i>
                                        <?php endif; ?>
                                    </span>
                                    <?php if ($_is_delayed): ?>
                                        <span class="status status--delayed">Delayed</span>
                                    <?php endif; ?>
                                </div>
                            </td>

                            <!-- 4. Responsible -->
                            <td class="text-sm nowrap"><?= $task['responsible_person_name'] ? e($task['responsible_person_name']) : '&mdash;' ?></td>

                            <!-- 5. Contact -->
                            <td class="text-sm nowrap"><?= $task['contact_person_name'] ? e($task['contact_person_name']) : '&mdash;' ?></td>

                            <!-- 6. Due -->
                            <td class="text-sm nowrap <?= $__tk_overdue ? 'tk-due--overdue' : 'text-muted' ?>">
                                <?= $task['expected_completion_date']
                                    ? e(formatDateTime($task['expected_completion_date'], 'M j, Y'))
                                    : '&mdash;' ?>
                            </td>

                            <!-- 7. Completed -->
                            <td class="text-sm nowrap text-muted">
                                <?= $task['completion_date']
                                    ? e(formatDateTime($task['completion_date'], 'M j, Y'))
                                    : '&mdash;' ?>
                            </td>

                            <!-- 8. Subtasks — plus the issue-011 estimate, which
                                 the mockup has no column for. Kept here rather
                                 than dropped: inherit-mode estimates are only
                                 visible on the detail page otherwise. -->
                            <td class="text-sm nowrap text-muted">
                                <?php if ((int)$task['subtask_count'] > 0): ?>
                                    <?= (int)$task['subtask_count'] ?> subtask<?= (int)$task['subtask_count'] === 1 ? '' : 's' ?>
                                <?php else: ?>
                                    &mdash;
                                <?php endif; ?>
                                <?php if ($resolved_total !== null): ?>
                                    <div class="tk-est">
                                        <i class="fas fa-clock"></i>
                                        <?= e(formatTaskEstimate($resolved_total)) ?>
                                        <?php if ($inherit_active): ?>
                                            <i class="fas fa-link tk-inherit"
                                               title="Auto-calculated from sub-tasks"
                                               aria-label="Auto-calculated from sub-tasks"></i>
                                        <?php endif; ?>
                                    </div>
                                <?php elseif ($inherit_active): ?>
                                    <?php // issue-011 § Validation edge cases:
                                          // inherit-on with no positive child
                                          // estimates must render the literal
                                          // "Not estimated (no sub-task estimates)"
                                          // copy (tester-note-001). ?>
                                    <div class="tk-est">
                                        <i class="fas fa-clock"></i>
                                        Not estimated (no sub-task estimates)
                                    </div>
                                <?php endif; ?>
                            </td>

                            <!-- 9. Actions -->
                            <td>
                                <div class="row-actions">
                                    <?php if ($task['status'] !== 'completed' && (int)$task['subtask_count'] === 0): ?>
                                        <button class="btn btn--sm btn--success btn--icon quick-complete-btn"
                                                data-task-id="<?= $task['id'] ?>"
                                                title="Mark as Completed">
                                            <i class="fas fa-check"></i>
                                        </button>
                                    <?php endif; ?>
                                    <a href="/tasks/<?= (int)$task['id'] ?>/edit" class="btn btn--sm btn--secondary btn--icon" title="Edit Task">
                                        <i class="fas fa-pen"></i>
                                    </a>
                                    <a href="/tasks/<?= (int)$task['id'] ?>" class="btn btn--sm btn--ghost btn--icon" title="Open">
                                        <i class="fas fa-arrow-right"></i>
                                    </a>
                                </div>
                            </td>
                        </tr>

                        <?php // Issue-034 v1.8.0 — reasons render in full on a
                              // dedicated sub-row spanning all 9 columns, never
                              // hidden behind a tooltip. Same .reason-chip
                              // components the cards used. ?>
                        <?php if (($_is_on_hold && $_on_hold_reason !== '') || ($_is_delayed && $_delay_reason !== '')): ?>
                            <tr class="reason-row">
                                <td colspan="9" class="reason-row__cell">
                                    <div class="reason-stack">
                                        <?php if ($_is_on_hold && $_on_hold_reason !== ''): ?>
                                            <div class="reason-chip reason-chip--on-hold"
                                                 title="On hold: <?= e($_on_hold_reason) ?>">
                                                <i class="fas fa-pause"></i>
                                                <span><strong>On hold:</strong> <?= e($_on_hold_reason) ?></span>
                                            </div>
                                        <?php endif; ?>
                                        <?php if ($_is_delayed && $_delay_reason !== ''): ?>
                                            <div class="reason-chip reason-chip--delay"
                                                 title="Delayed: <?= e($_delay_reason) ?>">
                                                <i class="fas fa-clock-rotate-left"></i>
                                                <span><strong>Delayed:</strong> <?= e($_delay_reason) ?></span>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                </td>
                            </tr>
                        <?php endif; ?>
                    <?php endforeach; ?>
                    </tbody>
                </table>
                </div>
            </div>
        </div>
    <?php endif; ?>


<script>
document.addEventListener('DOMContentLoaded', function() {
    // Quick complete functionality
    document.querySelectorAll('.quick-complete-btn').forEach(button => {
        button.addEventListener('click', function() {
            const taskId = this.getAttribute('data-task-id');
            // Issue-034 v2 restyle: the task list is now a table. The <tr>
            // carries data-task-id plus a .tk-row--{status} modifier class.
            // The old `.task-card` selector matched nothing, so the DOM
            // update below silently no-opped while the POST still fired.
            const taskRow = this.closest('.tk-row');
            
            if (confirm('Mark this task as completed?')) {
                // Disable button and show loading
                this.disabled = true;
                this.innerHTML = '<i class="fas fa-spinner fa-spin"></i>';
                
                fetch('ajax/complete_task.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded',
                    },
                    body: 'task_id=' + encodeURIComponent(taskId)
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        // Update task status in the UI. Only the FIRST
                        // .status in the row is the real status badge; a
                        // delayed task also renders a trailing
                        // .status--delayed chip that must not be retitled.
                        const statusSpan = taskRow ? taskRow.querySelector('.status') : null;
                        if (statusSpan) {
                            // Replace the label node only -- textContent
                            // would also wipe the reason-missing icon
                            // rendered inside the badge.
                            statusSpan.childNodes[0].nodeValue = 'Completed';
                            statusSpan.className = 'status status--completed';
                        }

                        // The row accent modifier must follow the status
                        // change too, or the row keeps its delayed/on-hold tint.
                        if (taskRow) {
                            taskRow.classList.remove('tk-row--delayed', 'tk-row--on-hold');
                            taskRow.classList.add('tk-row--completed');
                            // A completed task is no longer delayed.
                            const delayedChip = taskRow.querySelector('.status--delayed');
                            if (delayedChip) delayedChip.remove();
                        }
                        
                        // Remove the quick complete button
                        this.remove();
                        
                        // Show success message
                        showMessage('Task marked as completed successfully!', 'success');
                    } else {
                        showMessage(data.message || 'Error completing task', 'error');
                        // Restore button
                        this.disabled = false;
                        this.innerHTML = '<i class="fas fa-check"></i>';
                    }
                })
                .catch(error => {
                    showMessage('Error completing task', 'error');
                    // Restore button
                    this.disabled = false;
                    this.innerHTML = '<i class="fas fa-check"></i>';
                });
            }
        });
    });
    
    // Simple message display function
    function showMessage(message, type = 'info') {
        const alertDiv = document.createElement('div');
        alertDiv.className = `alert alert-${type}`;
        alertDiv.style.cssText = `
            position: fixed;
            top: 20px;
            right: 20px;
            z-index: 1000;
            padding: 15px;
            border-radius: 4px;
            background: ${type === 'success' ? '#d4edda' : type === 'error' ? '#f8d7da' : '#d1ecf1'};
            color: ${type === 'success' ? '#155724' : type === 'error' ? '#721c24' : '#0c5460'};
            border: 1px solid ${type === 'success' ? '#c3e6cb' : type === 'error' ? '#f5c6cb' : '#bee5eb'};
        `;
        alertDiv.textContent = message;
        
        document.body.appendChild(alertDiv);
        
        setTimeout(() => {
            alertDiv.remove();
        }, 5000);
    }
});
</script>

<?php include 'includes/footer.php'; ?>