<?php
require_once 'config.php';
require_once 'includes/Database.php';
require_once 'includes/functions.php';

requireLogin();

$db = Database::getInstance();
$user_id = getCurrentUserId();
$task_id = $_GET['id'] ?? null;

if (!$task_id) {
    redirect('/tasks');
}

// Get task details with project and user information
$task = $db->fetchOne(
    "SELECT t.*, p.name as project_name, p.id as project_id, u.name as responsible_person_name,
            c.name as contact_person_name, c.email as contact_email, c.phone as contact_phone,
            parent.name as parent_task_name, parent.id as parent_task_id
     FROM tasks t
     JOIN projects p ON t.project_id = p.id
     LEFT JOIN users u ON t.responsible_person_id = u.id
     LEFT JOIN contacts c ON t.contact_person_id = c.id
     LEFT JOIN tasks parent ON t.parent_task_id = parent.id
     WHERE t.id = ? AND p.responsible_person_id = ?",
    [$task_id, $user_id]
);

if (!$task) {
    redirect('/tasks');
}

// Get subtasks
$subtasks = $db->fetchAll(
    "SELECT t.*, u.name as responsible_person_name, c.name as contact_person_name,
            (SELECT COUNT(*) FROM tasks child WHERE child.parent_task_id = t.id) AS subtask_count
     FROM tasks t
     LEFT JOIN users u ON t.responsible_person_id = u.id
     LEFT JOIN contacts c ON t.contact_person_id = c.id
     WHERE t.parent_task_id = ?
     ORDER BY t.created_at ASC",
    [$task_id]
);

// Resolve nested task progress with the same weighted/fallback rule as projects.
$taskCompletion = getProjectTaskCompletionMap($db, $task['project_id']);
$calculated_percentage = $taskCompletion['task_completion'][(int)$task_id] ?? 0;
foreach ($subtasks as &$subtask) {
    $subtask['completion_percentage'] = $taskCompletion['task_completion'][(int)$subtask['id']] ?? 0;
}
unset($subtask);

// Issue-011: resolve the task's Total Task Time once, up front, so every
// surface on this page (Task Overview, Task Details, the Time card and the
// client portal) agrees on the same value. The Task Overview card renders
// before the Task Details card, so these must be computed here rather than
// inline further down the template.
$resolved_estimate = resolveTaskEstimateHours($task, $subtasks);
$inherit_active = $task['parent_task_id'] === null
    && !empty($task['inherit_subtask_hours'])
    && count($subtasks) > 0;
$has_positive_child_estimates = false;
foreach ($subtasks as $_sub) {
    if ($_sub['estimated_hours'] !== null && (float)$_sub['estimated_hours'] > 0) {
        $has_positive_child_estimates = true; break;
    }
}
unset($_sub);

// The delayed / on-hold flags drive the Status Reason card, the "Delayed"
// chip inside the Task Overview status row and the header badge.
$detail_on_hold = trim((string)($task['on_hold_reason'] ?? ''));
$detail_delay   = trim((string)($task['delay_reason']   ?? ''));
$detail_is_on_hold = ($task['status'] ?? '') === 'on_hold';
$detail_is_delayed = ($task['status'] ?? '') !== 'completed'
    && !empty($task['expected_completion_date'])
    && strtotime((string)$task['expected_completion_date']) < strtotime(date('Y-m-d'));

// Get task activity/comments (you can extend this for more detailed activity tracking)
$activities = $db->fetchAll(
    "SELECT 'created' as type, created_at as date, 'Task created' as description
     FROM tasks WHERE id = ?
     UNION ALL
     SELECT 'updated' as type, updated_at as date, 'Task updated' as description
     FROM tasks WHERE id = ? AND updated_at != created_at
     ORDER BY date DESC
     LIMIT 10",
    [$task_id, $task_id]
);

$title = "Task: " . $task['name'];
$show_nav = true;
// v2.0: this page was missing $use_v2_shell, so header.php skipped the
// .app-shell/.sidebar wrapper and the page rendered unstyled and
// full-bleed. Every other v2 page sets this. See header.php line ~94.
$use_v2_shell = true;
?>

<?php include 'includes/header.php'; ?>
<link rel="stylesheet" href="<?= e(asset_url('/assets/css/pages/task_detail.css')) ?>">

<div class="content">
    <div class="breadcrumb">
        <a href="/dashboard">Dashboard</a>
        <span>/</span>
        <a href="/tasks">Tasks</a>
        <span>/</span>
        <a href="/projects/<?= (int)$task['project_id'] ?>"><?= e($task['project_name']) ?></a>
        <span>/</span>
        <span><?= e($task['name']) ?></span>
    </div>

    <div class="page-header">
        <div class="page-header__text">
            <h1>
                <i class="fas fa-<?= $task['parent_task_id'] ? 'level-down-alt' : 'tasks' ?>"></i>
                <?= e($task['name']) ?>
            </h1>
            <?php /* Status, subtask count and assignee as header chips. The
                     status chip is the mockup's primary signal on this page;
                     subtasks previously only appeared inside the Subtasks card
                     header, which is below the fold on a long task. */ ?>
            <div class="chip-row" style="margin-top:.35rem;">
                <span class="status status--<?= e($task['status']) ?>">
                    <?= e(ucfirst(str_replace('_', ' ', $task['status']))) ?>
                </span>
                <?php if ($task['parent_task_id']): ?>
                    <span class="badge badge--outline">
                        <i class="fas fa-diagram-project"></i>
                        Subtask
                    </span>
                <?php endif; ?>
                <?php if (count($subtasks) > 0): ?>
                    <span class="badge badge--outline">
                        <i class="fas fa-diagram-project"></i>
                        <?= count($subtasks) ?> subtask<?= count($subtasks) === 1 ? '' : 's' ?>
                    </span>
                <?php endif; ?>
                <?php if (!empty($task['responsible_person_name'])): ?>
                    <span class="badge badge--outline">
                        <i class="fas fa-user"></i>
                        <?= e($task['responsible_person_name']) ?>
                    </span>
                <?php endif; ?>
            </div>
        </div>
        <div class="page-actions">
            <?php if ($task['status'] !== 'completed' && empty($subtasks)): ?>
                <button class="btn btn--success quick-complete-btn"
                        data-task-id="<?= $task_id ?>"
                        title="Mark as Completed">
                    <i class="fas fa-check"></i>
                    Complete Task
                </button>
            <?php endif; ?>
            <?php // Issue-012: On Hold / Resume button for parents with sub-tasks.
                  // Issue-034 v1.8.0: the on-hold path requires a non-empty
                  // reason. Rather than opening a modal, we just render a
                  // lightweight stacked form: an inline text input followed
                  // by the submit button. The page is always server-rendered
                  // (no JS framework), so this is the simplest affordance. ?>
            <?php if ($task['parent_task_id'] === null && count($subtasks) > 0): ?>
                <?php if ($task['status'] !== 'on_hold'): ?>
                    <form method="POST" action="ajax/parent_hold.php" class="inline-form parent-hold-form">
                        <input type="hidden" name="csrf_token" value="<?= e(getCsrfToken()) ?>">
                        <input type="hidden" name="task_id" value="<?= (int)$task_id ?>">
                        <input type="hidden" name="action" value="hold">
                        <input type="text" name="on_hold_reason" required maxlength="2000"
                               placeholder="On-hold reason (required)"
                               class="parent-hold-reason-input"
                               aria-label="On-hold reason">
                        <button class="btn btn--ghost" type="submit"
                                onclick="return confirm('Put this parent task on hold? Sub-tasks are not affected.')">
                            <i class="fas fa-pause"></i> Put on Hold
                        </button>
                    </form>
                <?php else: ?>
                    <form method="POST" action="ajax/parent_hold.php" class="inline-form">
                        <input type="hidden" name="csrf_token" value="<?= e(getCsrfToken()) ?>">
                        <input type="hidden" name="task_id" value="<?= (int)$task_id ?>">
                        <input type="hidden" name="action" value="resume">
                        <button class="btn btn--secondary" type="submit">
                            <i class="fas fa-play"></i> Resume
                        </button>
                    </form>
                <?php endif; ?>
            <?php endif; ?>
            <a href="/tasks/<?= (int)$task['id'] ?>/edit" class="btn btn--primary">
                <i class="fas fa-edit"></i>
                Edit Task
            </a>
            <?php if (!$task['parent_task_id']): ?>
                <a href="/tasks/new?parent_id=<?= $task_id ?>" class="btn btn--primary">
                    <i class="fas fa-plus"></i>
                    Add Subtask
                </a>
            <?php endif; ?>
        </div>
    </div>

    <div class="grid-sidebar">
        <div>
            <!-- Task Overview -->
            <div class="card">
                <div class="card__header">
                    <h2 class="card__title-icon"><i class="fas fa-list-check"></i> Task Overview</h2>
                    <div class="card__actions">
                        <span class="status status--<?= $task['status'] ?>">
                            <?= ucfirst(str_replace('_', ' ', $task['status'])) ?>
                            <?php // Issue-034 v1.8.0 — superscript "reason missing"
                                  // mark on the Task Overview status badge so the
                                  // affordance is visible at the very top of the
                                  // page (it pairs with the detail card just below). ?>
                            <?php if (($task['status'] ?? '') === 'on_hold' && trim((string)($task['on_hold_reason'] ?? '')) === ''): ?>
                                <i class="fas fa-exclamation-circle td-reason-flag"
                                   title="On-hold reason missing"
                                   aria-label="On-hold reason missing"></i>
                            <?php elseif (($task['status'] ?? '') !== 'completed'
                                          && !empty($task['expected_completion_date'])
                                          && strtotime((string)$task['expected_completion_date']) < strtotime(date('Y-m-d'))
                                          && trim((string)($task['delay_reason'] ?? '')) === ''): ?>
                                <i class="fas fa-exclamation-circle td-reason-flag"
                                   title="Delay reason missing"
                                   aria-label="Delay reason missing"></i>
                            <?php endif; ?>
                        </span>
                        <?php if (!empty($subtasks)): ?>
                            <!-- derived-pill: kept as a stable marker class.
                                 task_parent_status_derived_test.php pins this
                                 name to identify the issue-012/025/026
                                 "status derived from sub-tasks" affordance, so
                                 the hook outlives the v1 styling. -->
                            <span class="badge badge--outline derived-pill" title="Status follows sub-tasks automatically">
                                <i class="fas fa-magic"></i> Auto
                            </span>
                        <?php endif; ?>
                    </div>
                </div>
                <div class="card__body">
                    <?php if ($task['description']): ?>
                        <p class="text-sm"><?= nl2br(e($task['description'])) ?></p>
                    <?php endif; ?>
                    <div class="detail-list" style="margin-top:1rem;">
                        <div class="detail-row">
                            <span class="detail-row__k">Project</span>
                            <span class="detail-row__v">
                                <a href="/projects/<?= (int)$task['project_id'] ?>">
                                    <?= e($task['project_name']) ?>
                                </a>
                            </span>
                        </div>
                        <div class="detail-row">
                            <span class="detail-row__k">Status</span>
                            <span class="detail-row__v">
                                <span class="status status--<?= e($task['status']) ?>">
                                    <?= e(ucfirst(str_replace('_', ' ', $task['status']))) ?>
                                </span>
                                <?php if ($detail_is_delayed): ?>
                                    <span class="status status--delayed">
                                        <i class="fas fa-clock-rotate-left"></i> Delayed
                                    </span>
                                <?php endif; ?>
                            </span>
                        </div>
                        <div class="detail-row">
                            <span class="detail-row__k">Responsible person</span>
                            <span class="detail-row__v"><?= e($task['responsible_person_name']) ?></span>
                        </div>
                        <?php if (!empty($task['contact_person_name'])): ?>
                            <div class="detail-row">
                                <span class="detail-row__k">Contact person</span>
                                <span class="detail-row__v td-contact">
                                    <span><?= e($task['contact_person_name']) ?></span>
                                    <?php if ($task['contact_email']): ?>
                                        <a href="mailto:<?= e($task['contact_email']) ?>" title="Send Email">
                                            <i class="fas fa-envelope"></i>
                                        </a>
                                    <?php endif; ?>
                                    <?php if ($task['contact_phone']): ?>
                                        <a href="tel:<?= e($task['contact_phone']) ?>" title="Call">
                                            <i class="fas fa-phone"></i>
                                        </a>
                                    <?php endif; ?>
                                </span>
                            </div>
                        <?php endif; ?>
                        <?php if ($resolved_estimate !== null): ?>
                            <div class="detail-row">
                                <span class="detail-row__k">Total task time</span>
                                <span class="detail-row__v">
                                    <?= e(formatTaskEstimate($resolved_estimate)) ?>
                                    <?php if ($inherit_active): ?>
                                        <span class="text-muted">(inherited from subtasks)</span>
                                    <?php endif; ?>
                                </span>
                            </div>
                        <?php elseif ($inherit_active): ?>
                            <div class="detail-row">
                                <span class="detail-row__k">Total task time</span>
                                <span class="detail-row__v not-estimated">Not estimated (no sub-task estimates)</span>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- Parent Task (if this is a subtask) -->
            <?php if ($task['parent_task_id']): ?>
                <div class="card">
                    <div class="card__header">
                        <h2 class="card__title-icon"><i class="fas fa-diagram-project"></i> Parent Task</h2>
                    </div>
                    <div class="card__body">
                        <div class="td-parent-link">
                            <i class="fas fa-arrow-up"></i>
                            <a href="/tasks/<?= (int)$task['parent_task_id'] ?>">
                                <?= e($task['parent_task_name']) ?>
                            </a>
                        </div>
                    </div>
                </div>
            <?php endif; ?>

            <!-- Subtasks -->
            <?php if (!empty($subtasks)): ?>
                <div class="card">
                    <div class="card__header">
                        <h2 class="card__title-icon"><i class="fas fa-diagram-project"></i> Subtasks</h2>
                        <div class="card__actions">
                            <span class="badge badge--neutral"><?= count($subtasks) ?></span>
                            <a href="/tasks/new?parent_id=<?= $task_id ?>" class="btn btn--sm btn--primary">
                                <i class="fas fa-plus"></i>
                                Add Subtask
                            </a>
                        </div>
                    </div>
                    <div class="card__body">
                        <?php /* The mockup renders the sub-task list as a .task-tree
                             * of .task-node entries -- the same components the
                             * project_detail sub-task tree uses. That vocabulary
                             * already exists in v2-components.css, so this card
                             * uses it instead of a bespoke td-subtask block. */ ?>
                        <ul class="task-tree">
                            <?php foreach ($subtasks as $subtask): ?>
                                <?php
                                // Issue-011: subtasks may themselves be parents
                                // with their own sub-tasks, in which case their
                                // estimate is derived. We don't have the grand-
                                // children loaded here; the resolved value still
                                // matches the saved column for non-inherit subtasks
                                // and falls back to the manual value otherwise.
                                $sub_estimate = resolveTaskEstimateHours($subtask, []);
                                ?>
                                <li class="task-node<?= $subtask['status'] === 'completed' ? ' task-node--completed' : '' ?>">
                                    <div class="task-node__head">
                                        <span class="task-node__name<?= $subtask['status'] === 'completed' ? ' task-node__name--done' : '' ?>">
                                            <a href="/tasks/<?= (int)$subtask['id'] ?>"><?= e($subtask['name']) ?></a>
                                        </span>
                                        <span class="status status--<?= e($subtask['status']) ?>">
                                            <?= e(ucfirst(str_replace('_', ' ', $subtask['status']))) ?>
                                        </span>
                                    </div>
                                    <div class="task-node__meta">
                                        <?php if (!empty($subtask['responsible_person_name'])): ?>
                                            <span>
                                                <i class="fas fa-user"></i>
                                                <?= e($subtask['responsible_person_name']) ?>
                                            </span>
                                        <?php endif; ?>
                                        <?php if (!empty($subtask['expected_completion_date'])): ?>
                                            <span>
                                                <i class="fas fa-calendar"></i>
                                                Due <?= e(formatDateTime($subtask['expected_completion_date'], 'M d, Y')) ?>
                                            </span>
                                        <?php endif; ?>
                                        <?php if ($sub_estimate !== null): ?>
                                            <span>
                                                <i class="fas fa-clock"></i>
                                                Total Time: <?= e(formatTaskEstimate($sub_estimate)) ?>
                                            </span>
                                        <?php endif; ?>
                                    </div>
                                    <div class="task-node__progress">
                                        <div class="progress">
                                            <div class="progress__fill" style="width: <?= (float)$subtask['completion_percentage'] ?>%"></div>
                                        </div>
                                        <span class="task-node__pct"><?= number_format((float)$subtask['completion_percentage'], 0) ?>%</span>
                                    </div>
                                    <div class="task-node__actions">
                                        <?php if ($subtask['status'] !== 'completed' && (int)$subtask['subtask_count'] === 0): ?>
                                            <button class="btn btn--sm btn--success btn--icon quick-complete-btn"
                                                    data-task-id="<?= (int)$subtask['id'] ?>"
                                                    title="Mark as Completed">
                                                <i class="fas fa-check"></i>
                                            </button>
                                        <?php endif; ?>
                                        <a href="/tasks/<?= (int)$subtask['id'] ?>/edit" class="btn btn--sm btn--ghost btn--icon">
                                            <i class="fas fa-edit"></i>
                                        </a>
                                    </div>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                </div>
            <?php elseif (!$task['parent_task_id']): ?>
                <div class="card">
                    <div class="card__header">
                        <h2 class="card__title-icon"><i class="fas fa-diagram-project"></i> Subtasks</h2>
                        <div class="card__actions">
                            <a href="/tasks/new?parent_id=<?= $task_id ?>" class="btn btn--sm btn--primary">
                                <i class="fas fa-plus"></i>
                                Add First Subtask
                            </a>
                        </div>
                    </div>
                    <div class="card__body">
                        <div class="empty-state">
                            <div class="empty-state__icon">
                                <i class="fas fa-sitemap"></i>
                            </div>
                            <p>No subtasks created yet. Break this task down into smaller, manageable pieces.</p>
                        </div>
                    </div>
                </div>
            <?php endif; ?>

        <!-- Task Details -->
        <div class="card">
            <div class="card__header">
                <h2 class="card__title-icon"><i class="fas fa-info-circle"></i> Task Details</h2>
            </div>
            <div class="card__body">
                <div class="detail-list">
                        <div class="detail-row">
                        <span class="detail-row__k">Created</span>
                        <span class="detail-row__v"><?= formatDateTime($task['created_at']) ?></span>
                    </div>

                    <?php if ($task['expected_completion_date']): ?>
                        <div class="detail-row">
                            <span class="detail-row__k">Expected Completion</span>
                            <span class="detail-row__v <?= strtotime($task['expected_completion_date']) < time() && $task['status'] != 'completed' ? 'td-overdue' : '' ?>">
                                <?= formatDateTime($task['expected_completion_date'], 'M j, Y') ?>
                            </span>
                        </div>
                    <?php endif; ?>

                    <?php if ($task['completion_date']): ?>
                        <div class="detail-row">
                            <span class="detail-row__k">Completed</span>
                            <span class="detail-row__v td-completed"><?= formatDateTime($task['completion_date'], 'M j, Y') ?></span>
                        </div>
                    <?php endif; ?>

                    <div class="detail-row">
                        <span class="detail-row__k">Completion</span>
                        <span class="detail-row__v" style="min-width:160px;">
                            <div class="progress progress--<?= $calculated_percentage >= 100 ? 'success' : ($calculated_percentage > 0 ? 'warning' : 'danger') ?>">
                                <div class="progress__fill" style="width: <?= (float)$calculated_percentage ?>%"></div>
                            </div>
                            <span class="text-xs text-muted">
                                <?= number_format($calculated_percentage, 1) ?>%<?= !empty($subtasks) ? ' (calculated from ' . count($subtasks) . ' subtasks)' : '' ?>
                            </span>
                        </span>
                    </div>
                </div>
            </div>
        </div>

        <?php // Issue-034 v1.8.0 — Status Reason card. Renders the
              // current on-hold / delay reason (populated, missing,
              // or absent) and an inline-edit <details> form so the
              // manager can fill in a missing reason without leaving
              // the page. The card only appears when at least one of
              // the two reasons could be relevant (status=on_hold OR
              // task is delayed) so it stays out of the sidebar on
              // healthy tasks. ?>
        <?php if ($detail_is_on_hold || $detail_is_delayed): ?>
            <!-- task-reason-card: kept as a stable marker class. The v2
                 conversion dropped every other bespoke v1 class name, but
                 task_status_reasons_test.php pins this one because it
                 identifies the issue-034 Status Reason feature (on-hold /
                 delay reason + inline edit). Renaming it silently retires
                 that guard, so the hook outlives the styling. -->
            <div class="card task-reason-card">
                <div class="card__header">
                    <h2 class="card__title-icon"><i class="fas fa-circle-info"></i> Status Reason</h2>
                </div>
                <div class="card__body">
                    <?php if ($detail_is_on_hold): ?>
                        <div class="td-reason">
                            <span class="td-reason__label">On-hold reason</span>
                            <?php if ($detail_on_hold !== ''): ?>
                                <p class="td-reason__text td-reason__text--hold"><?= e($detail_on_hold) ?></p>
                                <details class="reason-inline-edit">
                                    <summary>Edit</summary>
                                    <textarea data-update-reason
                                              data-task-id="<?= (int)$task_id ?>"
                                              data-field="on_hold_reason"
                                              rows="2" maxlength="2000"><?= e($detail_on_hold) ?></textarea>
                                    <button type="button"
                                            data-save-reason
                                            data-task-id="<?= (int)$task_id ?>"
                                            data-field="on_hold_reason">Save</button>
                                    <span data-save-status aria-live="polite"></span>
                                </details>
                            <?php else: ?>
                                <p class="td-reason-required">
                                    <i class="fas fa-exclamation-circle"></i>
                                    Missing — fill in to explain the hold to the client.
                                </p>
                                <details class="reason-inline-edit" open>
                                    <summary>Add reason</summary>
                                    <textarea data-update-reason
                                              data-task-id="<?= (int)$task_id ?>"
                                              data-field="on_hold_reason"
                                              rows="2" maxlength="2000"
                                              placeholder="Why is this task on hold?"></textarea>
                                    <button type="button"
                                            data-save-reason
                                            data-task-id="<?= (int)$task_id ?>"
                                            data-field="on_hold_reason">Save</button>
                                    <span data-save-status aria-live="polite"></span>
                                </details>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>
                    <?php if ($detail_is_delayed): ?>
                        <div class="td-reason">
                            <span class="td-reason__label">Delay reason</span>
                            <?php if ($detail_delay !== ''): ?>
                                <p class="td-reason__text td-reason__text--delay"><?= e($detail_delay) ?></p>
                                <details class="reason-inline-edit">
                                    <summary>Edit</summary>
                                    <textarea data-update-reason
                                              data-task-id="<?= (int)$task_id ?>"
                                              data-field="delay_reason"
                                              rows="2" maxlength="2000"><?= e($detail_delay) ?></textarea>
                                    <button type="button"
                                            data-save-reason
                                            data-task-id="<?= (int)$task_id ?>"
                                            data-field="delay_reason">Save</button>
                                    <span data-save-status aria-live="polite"></span>
                                </details>
                            <?php else: ?>
                                <p class="td-reason-required">
                                    <i class="fas fa-exclamation-circle"></i>
                                    Missing — fill in to explain the slip to the client.
                                </p>
                                <details class="reason-inline-edit" open>
                                    <summary>Add reason</summary>
                                    <textarea data-update-reason
                                              data-task-id="<?= (int)$task_id ?>"
                                              data-field="delay_reason"
                                              rows="2" maxlength="2000"
                                              placeholder="Why is this task past its expected completion date?"></textarea>
                                    <button type="button"
                                            data-save-reason
                                            data-task-id="<?= (int)$task_id ?>"
                                            data-field="delay_reason">Save</button>
                                    <span data-save-status aria-live="polite"></span>
                                </details>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        <?php endif; ?>

        <!-- Recent Activity -->
        <?php if (!empty($activities)): ?>
            <div class="card">
                <div class="card__header">
                    <h2 class="card__title-icon"><i class="fas fa-clock-rotate-left"></i> Recent Activity</h2>
                </div>
                <div class="card__body">
                    <ul class="timeline">
                        <?php foreach ($activities as $activity): ?>
                            <li>
                                <div class="timeline__icon">
                                    <i class="fas fa-<?= $activity['type'] == 'created' ? 'plus' : 'edit' ?>"></i>
                                </div>
                                <div class="timeline__body">
                                    <p><?= e($activity['description']) ?></p>
                                    <span class="timeline__meta"><?= formatDateTime($activity['date']) ?></span>
                                </div>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            </div>
        <?php endif; ?>
        </div>

        <!-- Sidebar -->
        <div>
            <!-- Completion / Subtasks summary.
                 The mockup opens the task sidebar with a two-up stat strip
                 (Completion %, Subtasks count) above the Time card. The
                 completion figure is the same weighted roll-up the Task
                 Details card renders as a progress bar, so the two agree. -->
            <div class="stat-grid td-stats">
                <div class="stat stat--success">
                    <div class="stat__icon">
                        <i class="fas fa-chart-pie"></i>
                    </div>
                    <div>
                        <div class="stat__value"><?= number_format($calculated_percentage, 0) ?>%</div>
                        <div class="stat__label">Completion</div>
                    </div>
                </div>
                <div class="stat">
                    <div class="stat__icon">
                        <i class="fas fa-diagram-project"></i>
                    </div>
                    <div>
                        <div class="stat__value"><?= count($subtasks) ?></div>
                        <div class="stat__label">Subtasks</div>
                    </div>
                </div>
            </div>

            <!-- Time -->
            <div class="card">
                <div class="card__header">
                    <h2 class="card__title-icon"><i class="fas fa-clock"></i> Time</h2>
                </div>
                <div class="card__body">
                    <div class="detail-list">
                        <?php /* Issue-011: the mockup shows the effort
                             * rollup in its own sidebar card, separate from
                             * the Task Details card. The value and its
                             * "inherited from subtasks" provenance come from
                             * the shared helper, so this surface agrees
                             * with tasks.php, project_detail.php and the
                             * client portal. */ ?>
                        <?php $td_time_hours = resolveTaskEstimateHours($task, $subtasks); ?>
                        <?php $td_time_inherited = !empty($task['inherit_subtask_hours']) && count($subtasks) > 0; ?>
                        <div class="detail-row">
                            <span class="detail-row__k">Total time</span>
                            <span class="detail-row__v">
                                <?php if ($td_time_hours !== null): ?>
                                    <?= e(formatTaskEstimate($td_time_hours)) ?>
                                <?php else: ?>
                                    <span class="text-muted">Not estimated</span>
                                <?php endif; ?>
                            </span>
                        </div>
                        <div class="detail-row">
                            <span class="detail-row__k">Source</span>
                            <span class="detail-row__v">
                                <?= $td_time_inherited ? 'Inherited from subtasks' : 'Set on this task' ?>
                            </span>
                        </div>
                        <div class="detail-row">
                            <span class="detail-row__k">Unit</span>
                            <span class="detail-row__v">Hours</span>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    // Quick complete functionality
    document.querySelectorAll('.quick-complete-btn').forEach(button => {
        button.addEventListener('click', function() {
            const taskId = this.getAttribute('data-task-id');
            const isMainTask = this.closest('.page-actions') !== null;
            const taskElement = isMainTask ? document : this.closest('.subtask-card');
            
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
                        if (isMainTask) {
                            // For main task, reload the page to show updated status
                            showMessage('Task marked as completed successfully!', 'success');
                            setTimeout(() => {
                                location.reload();
                            }, 1000);
                        } else {
                            // For subtasks, update UI in place
                            const statusSpan = taskElement.querySelector('.status');
                            if (statusSpan) {
                                statusSpan.textContent = 'Completed';
                                statusSpan.className = 'status status-completed';
                            }
                            
                            // Update progress bar
                            const progressBar = taskElement.querySelector('.progress');
                            if (progressBar) {
                                progressBar.style.width = '100%';
                            }
                            
                            const progressText = taskElement.querySelector('.progress-text');
                            if (progressText) {
                                progressText.textContent = '100%';
                            }
                            
                            // Remove the quick complete button
                            this.remove();
                            
                            // Show success message
                            showMessage('Subtask marked as completed successfully!', 'success');
                            
                            // Refresh subtask progress on the main page after a delay
                            setTimeout(() => {
                                location.reload();
                            }, 2000);
                        }
                    } else {
                        showMessage(data.message || 'Error completing task', 'error');
                        // Restore button
                        this.disabled = false;
                        this.innerHTML = isMainTask ? '<i class="fas fa-check"></i> Complete Task' : '<i class="fas fa-check"></i>';
                    }
                })
                .catch(error => {
                    showMessage('Error completing task', 'error');
                    // Restore button
                    this.disabled = false;
                    this.innerHTML = isMainTask ? '<i class="fas fa-check"></i> Complete Task' : '<i class="fas fa-check"></i>';
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
