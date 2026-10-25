<?php
require_once 'config.php';
require_once 'includes/Database.php';
require_once 'includes/functions.php';
require_once 'includes/task_edit_auth.php';

requireLogin();

$db = Database::getInstance();
$user_id = getCurrentUserId();
$task_id = $_GET['id'] ?? null;
$parent_id = $_GET['parent_id'] ?? null;
$project_id = $_GET['project_id'] ?? null;

$is_editing = $task_id !== null;
$is_subtask = $parent_id !== null;

// If editing, get the existing task
$task = null;
$parent_task = null;
if ($is_editing) {
    $task = $db->fetchOne(
        "SELECT t.*, p.name as project_name, p.id as project_id
         FROM tasks t
         JOIN projects p ON t.project_id = p.id
         WHERE t.id = ? AND p.responsible_person_id = ?",
        [$task_id, $user_id]
    );
    
    if (!$task) {
        redirect('/tasks');
    }
    $project_id = $task['project_id'] ?? null;
} elseif ($is_subtask) {
    // Get parent task information
    $parent_task = $db->fetchOne(
        "SELECT t.*, p.name as project_name, p.id as project_id
         FROM tasks t
         JOIN projects p ON t.project_id = p.id
         WHERE t.id = ? AND p.responsible_person_id = ?",
        [$parent_id, $user_id]
    );
    
    if (!$parent_task) {
        redirect('/tasks');
    }
    $project_id = $parent_task['project_id'];
}

// Get user's projects for dropdown
$user_projects = $db->fetchAll(
    "SELECT id, name FROM projects WHERE responsible_person_id = ? ORDER BY name",
    [$user_id]
);

// Get users for responsible person assignment.
// Issue-022 (IDOR): the dropdown must show the same set the POST handler
// will accept — i.e. the current user, plus other project owners. Showing
// every user in the database was an IDOR vector: a logged-in user could
// POST a forged responsible_person_id pointing to a non-owner and the
// existence-only check would accept it. The same rule is enforced in
// includes/task_edit_auth.php::taskEditCanAssignResponsible() so the
// GET-time and POST-time views match.
$users = $db->fetchAll(
    "SELECT id, name, email FROM users
     WHERE id = ?
        OR id IN (
            SELECT responsible_person_id FROM projects
            WHERE responsible_person_id IS NOT NULL
        )
     ORDER BY name",
    [$user_id]
);

// Get contacts for the project (if project is selected)
$contacts = [];
if ($project_id) {
    $contacts = $db->fetchAll(
        "SELECT DISTINCT c.* FROM contacts c
         JOIN project_contacts pc ON c.id = pc.contact_id
         WHERE pc.project_id = ?
         ORDER BY c.name",
        [$project_id]
    );
}

// Issue-011: For an existing parent task, fetch its sub-task count + estimates
// so we can render the "Use sum of sub-task hours" checkbox and the live
// "Computed total" hint. A task with no sub-tasks never gets the checkbox.
$subtask_count = 0;
$subtask_estimates = [];
$inherit_from_post = null;
// Issue-013: initialise the effective inherit flag up-front so the
// "Total Task Time" disable check on a brand-new task (where the
// inherit checkbox is never rendered) does not produce a PHP
// "Undefined variable" warning. Reassigned inside the editing
// branch below once the user has sub-tasks to inherit from.
$effective_inherit = false;
if ($is_editing) {
    $subtask_rows = $db->fetchAll(
        "SELECT id, estimated_hours FROM tasks WHERE parent_task_id = ?",
        [$task_id]
    );
    $subtask_count = count($subtask_rows);
    foreach ($subtask_rows as $row) {
        $subtask_estimates[] = (float)$row['estimated_hours'];
    }
    $inherit_from_post = $task['inherit_subtask_hours'] ?? null;
    if ($subtask_count > 0) {
        $effective_inherit = $inherit_from_post !== null
            ? ((int)$inherit_from_post === 1)
            : !empty($task['inherit_subtask_hours']);
    }
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // The error bag must exist before anything can append to it — the
    // CSRF branch below and the parseFormDate() calls further down both
    // write into it, and appending to an undefined variable silently
    // creates a non-array.
    $errors = [];

    // Issue-016 (CSRF): reject any request that does not carry a valid
    // CSRF token. The check sits BEFORE any input read so a missing /
    // invalid token cannot leak DB error messages or partial state.
    if (!validateCsrfToken($_POST['csrf_token'] ?? null)) {
        $errors[] = 'Your session could not be verified. '
                  . 'Please reload the page and submit again.';
    } else {
    $name = trim($_POST['name'] ?? '');
    $description = trim($_POST['description'] ?? '');
    $status = $_POST['status'] ?? 'not_started';
    $completion_percentage = floatval($_POST['completion_percentage'] ?? 0);
    $expected_completion_date = $_POST['expected_completion_date'] ?? null;
    $completion_date = $_POST['completion_date'] ?? null;
    // Issue-055: task dates are calendar dates (SQL DATE), matching the
    // schema and the project form. parseFormDate() validates AND
    // normalises in one step, and the return value is what gets stored —
    // the old DateTime::createFromFormat() blocks validated a value they
    // then threw away, so the raw POST string reached the DB unchecked
    // (and an impossible date such as 2026-02-31 was never caught).
    //
    // This runs early on purpose: the delay-reason checks below compare the
    // expected date against today, and an unparsed value (e.g. "2026-13-01")
    // would make strtotime() return false and manufacture a bogus
    // "delay reason required" error on top of the real date error.
    //
    // $errors must exist BEFORE the first parseFormDate() call: the
    // helper appends to it by reference, and passing an undefined
    // (null) variable to an `array &$errors` parameter is a TypeError.
    // (It is initialised at the top of this POST block.)
    $expected_completion_date = parseFormDate(
        $_POST['expected_completion_date'] ?? '',
        'Expected completion date',
        $errors
    );
    $completion_date = parseFormDate(
        $_POST['completion_date'] ?? '',
        'Completion date',
        $errors
    );
    $total_time = $_POST['total_time'] ?? '';
    $total_time_unit = $_POST['total_time_unit'] ?? 'hours';
    $responsible_person_id = $_POST['responsible_person_id'] ?? ($task['responsible_person_id'] ?? $user_id);
    $contact_person_id = $_POST['contact_person_id'] ?? null;
    $form_project_id = $_POST['project_id'] ?? $project_id;

    // Issue-034 v1.8.0 — status reasons. Free-text strings, both
    // optional at the form level. Trimming happens here so the
    // validator below can compare against an empty string. Stored
    // NULL when the reason is empty AND the matching condition does
    // not hold (status != on_hold for on_hold_reason; not delayed
    // for delay_reason); stored trimmed when set.
    $on_hold_reason_input = $_POST['on_hold_reason'] ?? '';
    $delay_reason_input   = $_POST['delay_reason']   ?? '';
    if (is_string($on_hold_reason_input)) {
        $on_hold_reason_input = trim($on_hold_reason_input);
    } else {
        $on_hold_reason_input = '';
    }
    if (is_string($delay_reason_input)) {
        $delay_reason_input = trim($delay_reason_input);
    } else {
        $delay_reason_input = '';
    }

    // Issue-011: inherit_subtask_hours is the literal string '1' or anything else
    // (treated as false). Survive validation failure via the helper.
    $posted_inherit = $_POST['inherit_subtask_hours'] ?? null;
    $inherit_from_post = ($posted_inherit === '1') ? 1 : 0;

    $estimated_hours = null;
    
    // Validation
    if (empty($name)) {
        $errors[] = "Task name is required and cannot be empty.";
    } elseif (strlen($name) > 255) {
        $errors[] = "Task name must be 255 characters or less.";
    }
    
    if (!empty($description) && strlen($description) > 2000) {
        $errors[] = "Task description must be 2000 characters or less.";
    }
    
    if (!$form_project_id && !$is_subtask) {
        $errors[] = "Project selection is required for main tasks.";
    } elseif ($form_project_id && !taskEditCanUseProject($db, $user_id, $form_project_id)) {
        // Issue-022 (IDOR): the submitted project_id must be one of the
        // current user's projects. The dropdown is already scoped
        // ($user_projects above), so a normal browser flow never hits this
        // branch — it only fires on a forged POST that names a project the
        // attacker does not own. For sub-tasks, $form_project_id is null
        // because the form does not post a project_id; the project is
        // derived from $parent_task which was already validated when the
        // page was loaded.
        $errors[] = "Selected project is not one of your projects.";
        $form_project_id = null;
    }
    
    if (empty($responsible_person_id)) {
        $errors[] = "Responsible person must be assigned to the task.";
    } elseif (!taskEditCanAssignResponsible($db, $user_id, $responsible_person_id)) {
        // Issue-022 (IDOR): the responsible person must be the current
        // user OR another user who is the responsible person of at least
        // one project. The previous existence-only check let any
        // logged-in attacker reassign a task to a non-owner (e.g. an
        // ex-employee account that was never deleted). See
        // includes/task_edit_auth.php for the rule.
        $errors[] = "Selected responsible person is not a user "
                  . "you can assign a task to.";
    }

    if (!empty($contact_person_id) && !taskEditCanAssignContact($db, $user_id, $contact_person_id)) {
        // Issue-022 (IDOR): the contact must be linked to at least one
        // of the current user's projects. The previous existence-only
        // check let any logged-in attacker link a task to a contact on
        // another user's project, which then surfaces in that contact's
        // owner's contact_detail.php view.
        $errors[] = "Selected contact is not on any of your projects.";
    }
    
    if (!in_array($status, ['not_started', 'in_progress', 'completed', 'on_hold'])) {
        $errors[] = "Invalid task status selected.";
    }

    // Issue-034 v1.8.0: when the form posts status='on_hold' we MUST
    // get a non-empty trimmed reason. Parents with sub-tasks have
    // status forced back to the row value further down — at that
    // point we skip this validation because the user cannot change
    // status from this form (issue-012 invariant). The check below
    // runs BEFORE that override so the admin who manually sets
    // status='on_hold' on a leaf task cannot bypass the reason.
    if ($status === 'on_hold' && $on_hold_reason_input === '') {
        $errors[] = "An on-hold reason is required when status is On Hold.";
    }

    // Same rule for delay_reason, but the condition is *computed*
    // (expected_completion_date < today AND status != completed).
    // We use the POSTed expected_completion_date on the way in
    // (falling back to the row's existing value if not posted),
    // matching the same input that drives the rest of the form.
    $expected_for_delay_check = !empty($expected_completion_date)
        ? (string)$expected_completion_date
        : (string)($task['expected_completion_date'] ?? '');
    $is_delayed_for_post = $status !== 'completed'
        && $expected_for_delay_check !== ''
        && strtotime($expected_for_delay_check) < strtotime(date('Y-m-d'));
    if ($is_delayed_for_post && $delay_reason_input === '') {
        $errors[] = "A delay reason is required when the task is past its expected completion date.";
    }
    // Length cap to mirror the TEXT column practical limit. We pick
    // 2000 characters (the same cap as task description) to keep
    // form UX predictable.
    if (strlen($on_hold_reason_input) > 2000) {
        $errors[] = "On-hold reason must be 2000 characters or less.";
    }
    if (strlen($delay_reason_input) > 2000) {
        $errors[] = "Delay reason must be 2000 characters or less.";
    }

    if (!in_array($total_time_unit, ['hours', 'mandays'], true)) {
        $errors[] = "Choose hours or mandays for the total task time.";
    }

    if (!is_string($total_time) && !is_numeric($total_time)) {
        $errors[] = "Total task time must be a valid non-negative number.";
    } else {
        $total_time = trim((string)$total_time);
        if ($total_time !== '') {
            if (!is_numeric($total_time) || !is_finite((float)$total_time) || (float)$total_time < 0) {
                $errors[] = "Total task time must be a finite, non-negative number.";
            } else {
                $hours_value = (float)$total_time * ($total_time_unit === 'mandays' ? 8 : 1);
                $hours_value = round($hours_value, 4);
                if (!is_finite($hours_value) || $hours_value > 99999999.9999) {
                    $errors[] = "Total task time exceeds the maximum supported estimate.";
                } else {
                    $estimated_hours = number_format($hours_value, 4, '.', '');
                }
            }
        }
    }

    // Issue-011: server-side defence — when the inherit flag is on, ignore
    // the (potentially stale, browser-disabled) total_time value entirely.
    if ($is_editing && $inherit_from_post === 1) {
        $estimated_hours = null;
    }

    // Issue-011: a parent with at least one sub-task must have either a
    // non-zero total_time OR inherit mode on. Empty/zero is rejected when
    // inherit mode is off and the task has children.
    if ($is_editing && $subtask_count > 0 && $inherit_from_post !== 1) {
        if ($total_time === '' || (float)$total_time <= 0) {
            $errors[] = "Tasks with sub-tasks must specify a non-zero total task time, or check 'Use sum of sub-task hours'.";
        }
    }

    // Issue-012: server-side defence — for a parent with children, ignore
    // any submitted status value (the on-hold / resume button on the detail
    // page is the only legitimate path). The completion_date and 100%
    // auto-derivation for non-parent tasks still applies below.
    if ($is_editing && $task['parent_task_id'] === null && $subtask_count > 0) {
        $status = $task['status'] ?? 'not_started';
    }

    // Issue-034 v1.8.0: normalize reasons for storage.
    //   * status != on_hold  => on_hold_reason must be NULL (don't carry
    //     stale text from a previous hold).
    //   * not delayed        => delay_reason must be NULL.
    //   * otherwise          => store the trimmed value (validation
    //     above guarantees non-empty when the condition holds).
    // Re-evaluate "is delayed" against the POSTed/normalised status so
    // a just-set completed task (with status now completed, see the
    // 100% block below) does NOT keep its old delay_reason.
    $stored_on_hold_reason = ($status === 'on_hold') ? ($on_hold_reason_input === '' ? null : $on_hold_reason_input) : null;
    $expected_after_status = $expected_completion_date ?: ($task['expected_completion_date'] ?? null);
    $is_delayed_for_store = $status !== 'completed'
        && $expected_after_status
        && strtotime((string)$expected_after_status) < strtotime(date('Y-m-d'));
    $stored_delay_reason = $is_delayed_for_store ? ($delay_reason_input === '' ? null : $delay_reason_input) : null;
    
    if ($completion_percentage < 0 || $completion_percentage > 100) {
        $errors[] = "Completion percentage must be between 0 and 100.";
    }
    
    // Issue-055: task dates are parsed and normalised up-front (see the
    // assignment block near the top of the POST handler), so $expected_date
    // and $completion_date already hold the canonical Y-m-d values that are
    // written to the database below.
    
    // If status is completed, ensure completion percentage is 100 and completion date is set
    if ($status === 'completed') {
        $completion_percentage = 100;
        if (empty($completion_date)) {
            // Day precision — the column is DATE; a timestamp here would be
            // silently truncated by MariaDB (issue-055).
            $completion_date = date('Y-m-d');
        }
    } elseif ($status !== 'completed') {
        $completion_date = null; // Clear completion date if not completed
    }
    
    // Convert empty strings to null for database
    $expected_completion_date = $expected_completion_date ?: null;
    $completion_date = $completion_date ?: null;
    $responsible_person_id = $responsible_person_id ?: null;
    $contact_person_id = $contact_person_id ?: null;
    
    if (empty($errors)) {
        try {
            if ($is_editing) {
                // Issue-011: persist the inherit flag. For a task with no sub-tasks
                // the column is forced to 0 regardless of what the form posted.
                $stored_inherit = ($subtask_count > 0 && $inherit_from_post === 1) ? 1 : 0;

                // Update existing task
                $db->execute(
                    "UPDATE tasks SET
                        name = ?, description = ?, status = ?, completion_percentage = ?,
                        expected_completion_date = ?, completion_date = ?,
                        estimated_hours = ?, inherit_subtask_hours = ?,
                        on_hold_reason = ?, delay_reason = ?,
                        responsible_person_id = ?, contact_person_id = ?, updated_at = NOW()
                     WHERE id = ?",
                    [
                        $name, $description, $status, $completion_percentage,
                        $expected_completion_date, $completion_date, $estimated_hours,
                        $stored_inherit,
                        $stored_on_hold_reason, $stored_delay_reason,
                        $responsible_person_id, $contact_person_id, $task_id
                    ]
                );
                syncProjectTaskCompletions($db, $task['project_id']);

                redirect('/tasks/' . $task_id, 'Task updated successfully!', 'success');
            } else {
                // Create new task
                $final_project_id = $is_subtask ? $parent_task['project_id'] : $form_project_id;

                $db->execute(
                    "INSERT INTO tasks (
                        name, slug, description, status, completion_percentage,
                        expected_completion_date, completion_date, estimated_hours,
                        inherit_subtask_hours,
                        on_hold_reason, delay_reason,
                        responsible_person_id, contact_person_id,
                        project_id, parent_task_id, created_at, updated_at
                     ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())",
                    [
                        $name, generateUniqueSlug($name, 'tasks'),
                        $description, $status, $completion_percentage,
                        $expected_completion_date, $completion_date, $estimated_hours,
                        0, // new tasks can never start in inherit mode (no children yet)
                        $stored_on_hold_reason, $stored_delay_reason,
                        $responsible_person_id, $contact_person_id,
                        $final_project_id, $parent_id
                    ]
                );

                $new_task_id = $db->lastInsertId();
                syncProjectTaskCompletions($db, $final_project_id);

                redirect('/tasks/' . $new_task_id, 'Task created successfully!', 'success');
            }
        } catch (Exception $e) {
            // Issue-016: log the underlying PDO message server-side but
            // do NOT echo it to the user (info disclosure).
            error_log("Task save failed: " . $e->getMessage());
            $errors[] = "Failed to save the task. Please try again. "
                      . "If the problem persists, contact your administrator.";
        }
    }
    } // end else (!validateCsrfToken failed)
}

$title = $is_editing ? "Edit Task" : ($is_subtask ? "New Subtask" : "New Task");
$show_nav = true;
// v2.0: this page was missing $use_v2_shell, so header.php skipped the
// .app-shell/.sidebar wrapper and the page rendered unstyled and
// full-bleed. Every other v2 page sets this. See header.php line ~94.
$use_v2_shell = true;
?>

<?php include 'includes/header.php'; ?>
<link rel="stylesheet" href="<?= e(asset_url('/assets/css/pages/forms.css')) ?>">
<link rel="stylesheet" href="<?= e(asset_url('/assets/css/pages/task_edit.css')) ?>">

<div class="content">
    <nav class="breadcrumb" aria-label="Breadcrumb">
        <a href="/dashboard">Dashboard</a>
        <span aria-hidden="true">/</span>
        <a href="/tasks">Tasks</a>
        <?php if ($is_editing): ?>
            <span aria-hidden="true">/</span>
            <a href="/tasks/<?= (int)$task['id'] ?>"><?= e($task['name']) ?></a>
            <span aria-hidden="true">/</span>
            <span>Edit</span>
        <?php elseif ($is_subtask): ?>
            <span aria-hidden="true">/</span>
            <a href="/tasks/<?= (int)$parent_task['id'] ?>"><?= e($parent_task['name']) ?></a>
            <span aria-hidden="true">/</span>
            <span>New Subtask</span>
        <?php else: ?>
            <span aria-hidden="true">/</span>
            <span>New Task</span>
        <?php endif; ?>
    </nav>

    <div class="page-header">
        <div class="page-header__text">
            <h1><i class="fas fa-<?= $is_subtask ? 'level-down-alt' : ($is_editing ? 'list-check' : 'plus') ?>"></i> <?= e($title) ?></h1>
            <?php if ($is_editing): ?>
                <p class="page-header__sub"><?= e($task['name']) ?> &middot; <?= e($task['project_name'] ?? '') ?></p>
            <?php elseif ($is_subtask): ?>
                <p class="page-header__sub">Creating subtask under <?= e($parent_task['name']) ?></p>
            <?php endif; ?>
        </div>
        <div class="page-actions">
            <a class="btn btn--secondary" href="<?= $is_editing ? '/tasks/' . (int)$task['id'] : ($is_subtask ? '/tasks/' . (int)$parent_task['id'] : '/tasks') ?>">Cancel</a>
            <?php if ($is_editing && !($task['parent_task_id'] ?? null)): ?>
                <a href="/tasks/new?parent_id=<?= (int)$task_id ?>" class="btn btn--secondary">
                    <i class="fas fa-plus"></i> Add Subtask
                </a>
            <?php endif; ?>
        </div>
    </div>

    <?php if (!empty($errors)): ?>
        <div class="alert alert--danger">
            <i class="fas fa-triangle-exclamation"></i>
            <div class="alert__body">
                <div class="alert__title">Please fix the following before saving</div>
                <ul>
                    <?php foreach ($errors as $error): ?>
                        <li><?= e($error) ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
        </div>
    <?php endif; ?>

    <div class="alert alert--info">
        <i class="fas fa-circle-info"></i>
        <div class="alert__body">
            <div class="alert__title">Delayed and on-hold tasks must carry a reason</div>
            The reason fields below become required when the status matches, and are shown to the
            client on the shared project portal.
        </div>
    </div>

    <form class="card" method="POST" action="">
        <input type="hidden" name="csrf_token" value="<?= e(getCsrfToken()) ?>">
        <div class="card__header">
            <h2 class="card__title-icon"><i class="fas fa-circle-info"></i> Task information</h2>
        </div>
        <div class="card__body">
            <div class="form-grid form-grid--2">

                <div class="field form-section">
                    <label for="name">Task Name <span class="req" aria-hidden="true">*</span></label>
                    <input class="input" type="text" id="name" name="name"
                           value="<?= e($_POST['name'] ?? $task['name'] ?? '') ?>"
                           required maxlength="255">
                </div>

                <div class="field">
                    <label for="project_id">Project <span class="req" aria-hidden="true">*</span></label>
                    <?php if (!$is_subtask): ?>
                        <select class="select" id="project_id" name="project_id" required>
                            <option value="">Select Project</option>
                            <?php foreach ($user_projects as $project): ?>
                                <option value="<?= (int)$project['id'] ?>"
                                        <?= ($_POST['project_id'] ?? $project_id) == $project['id'] ? 'selected' : '' ?>>
                                    <?= e($project['name']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    <?php else: ?>
                        <input class="input" type="text" id="project_display"
                               value="<?= e($parent_task['project_name']) ?>" readonly>
                        <input type="hidden" name="project_id" value="<?= (int)$parent_task['project_id'] ?>">
                        <span class="hint">Read-only &mdash; a subtask always belongs to its parent task's project.</span>
                    <?php endif; ?>
                </div>

                <div class="field form-section">
                    <label for="description">Description</label>
                    <textarea class="textarea" id="description" name="description" rows="4"
                              placeholder="Detailed description of the task..."><?= e($_POST['description'] ?? $task['description'] ?? '') ?></textarea>
                </div>

                <?php
                // Issue-012: for a parent with sub-tasks the status is derived from
                // the children, so the field becomes a read-only display. The
                // on-hold / resume action on the task detail page is the only path
                // that mutates the status of such a parent.
                $is_parent_with_subs = $is_editing && ($task['parent_task_id'] ?? null) === null && $subtask_count > 0;
                $status_label = ucfirst(str_replace('_', ' ', $task['status'] ?? 'not_started'));
                $current_status = $_POST['status'] ?? ($task['status'] ?? 'not_started');
                ?>
                <div class="field">
                    <label for="status_display">Current Status</label>
                    <input class="input" type="text" id="status_display"
                           value="<?= e(ucfirst(str_replace('_', ' ', $current_status))) ?>" readonly>
                    <?php if ($is_parent_with_subs): ?>
                        <span class="hint">Read-only &mdash; this task has <?= (int)$subtask_count ?> sub-task(s), so its status follows the sub-tasks automatically. To put this parent on hold, use the &ldquo;On Hold&rdquo; action on the task detail page.</span>
                    <?php else: ?>
                        <span class="hint">Read-only &mdash; change the status below.</span>
                    <?php endif; ?>
                </div>

                <div class="field">
                    <label for="status">Status <span class="req" aria-hidden="true">*</span></label>
                    <?php if ($is_parent_with_subs): ?>
                        <input type="hidden" name="status" value="<?= e($task['status']) ?>">
                        <input class="input" type="text" id="status"
                               value="<?= e($status_label) ?>" disabled aria-describedby="status_help">
                    <?php else: ?>
                        <select class="select" id="status" name="status" data-status-select>
                            <option value="not_started" <?= $current_status === 'not_started' ? 'selected' : '' ?>>Not Started</option>
                            <option value="in_progress" <?= $current_status === 'in_progress' ? 'selected' : '' ?>>In Progress</option>
                            <option value="completed" <?= $current_status === 'completed' ? 'selected' : '' ?>>Completed</option>
                            <option value="on_hold" <?= $current_status === 'on_hold' ? 'selected' : '' ?>>On Hold</option>
                        </select>
                    <?php endif; ?>
                </div>

                <div class="field form-section">
                    <label for="completion_percentage">Completion Percentage</label>
                    <div class="range-row">
                        <input type="range" id="completion_slider" min="0" max="100" step="5"
                               value="<?= (int)($_POST['completion_percentage'] ?? $task['completion_percentage'] ?? 0) ?>"
                               data-range-sync="#completion_percentage" data-range-out="#completion_pct">
                        <input class="input" type="number" id="completion_percentage" name="completion_percentage"
                               min="0" max="100" step="0.1"
                               value="<?= e($_POST['completion_percentage'] ?? $task['completion_percentage'] ?? 0) ?>"
                               style="width:90px;">
                        <span class="range-value" id="completion_pct"><?= (int)($_POST['completion_percentage'] ?? $task['completion_percentage'] ?? 0) ?>%</span>
                    </div>
                </div>

                <?php
                // Issue-034 v1.8.0 &mdash; Status reason textareas. Each one is shown
                // only when its condition already holds on the row (or is being posted
                // in the same request, e.g. turning a task to on_hold). The PHP
                // normalizer re-evaluates the condition at save time, so even a stale
                // DOM render is safe &mdash; the values just won't survive into the
                // UPDATE.
                $rendered_on_hold = $_POST['on_hold_reason'] ?? ($task['on_hold_reason'] ?? '');
                $rendered_delay   = $_POST['delay_reason']   ?? ($task['delay_reason']   ?? '');
                if (!is_string($rendered_on_hold)) { $rendered_on_hold = ''; }
                if (!is_string($rendered_delay))   { $rendered_delay   = ''; }

                $expected_for_render = $_POST['expected_completion_date'] ?? ($task['expected_completion_date'] ?? null);
                $show_delay_reason = $current_status !== 'completed'
                    && !empty($expected_for_render)
                    && strtotime((string)$expected_for_render) < strtotime(date('Y-m-d'));

                // issue-056: the two reasons are INDEPENDENT. A task can be
                // on hold AND past its due date at the same time, and the
                // database stores the two explanations in two separate
                // nullable columns. The delay panel's visibility is
                // therefore driven by the DUE DATE, not by the status --
                // hiding it merely because the status is on_hold produced a
                // dead-end form (hidden + disabled textarea => not submitted
                // => the server rejected the save for a missing delay reason).
                //
                // PHP stays the single source of truth for the delayed
                // predicate and publishes the answer to the JS as
                // data-reason-show. The JS reads that attribute instead of
                // re-deriving the date rule a third time, and only rewrites
                // it when the user actually changes the due date.
                $show_on_hold_reason = ($current_status === 'on_hold');
                ?>
                <div class="field form-section" data-reason-panel="on_hold"
                     data-reason-show="<?= $show_on_hold_reason ? '1' : '0' ?>"
                     <?= $show_on_hold_reason ? '' : 'hidden' ?>>
                    <label for="on_hold_reason">On-hold reason <span class="req" aria-hidden="true">*</span></label>
                    <textarea class="textarea" id="on_hold_reason" name="on_hold_reason" rows="3"
                              maxlength="2000"
                              placeholder="Why is this task on hold? (Required when status is On Hold.)"><?= e($rendered_on_hold) ?></textarea>
                    <span class="hint">Mandatory while the status is On Hold. Max 2000 characters.</span>
                </div>

                <div class="field form-section" data-reason-panel="delayed"
                     data-reason-show="<?= $show_delay_reason ? '1' : '0' ?>"
                     <?= $show_delay_reason ? '' : 'hidden' ?>>
                    <label for="delay_reason">Delay reason <span class="req" aria-hidden="true">*</span></label>
                    <textarea class="textarea" id="delay_reason" name="delay_reason" rows="3"
                              maxlength="2000"
                              placeholder="Why is this task past its expected completion date? (Required when the task is delayed.)"><?= e($rendered_delay) ?></textarea>
                    <span class="hint">Mandatory when the expected completion date has passed. Max 2000 characters.</span>
                </div>

                <div class="field form-section" data-delay-hint
                     <?= $show_delay_reason ? '' : 'hidden' ?>>
                    <div class="alert alert--warning" style="margin:0;">
                        <i class="fas fa-triangle-exclamation"></i>
                        <div class="alert__body">
                            <div class="alert__title">This task is past its expected completion date</div>
                            Add a delay reason above, or update the expected completion date.
                        </div>
                    </div>
                </div>

                <?php
                // Issue-011: the inherit checkbox is only offered for a parent task
                // that has at least one sub-task. The live sum and the count of
                // positive estimates feed the hint below.
                $inherit_checked = $is_editing && $subtask_count > 0 && !empty($task['inherit_subtask_hours']);
                $inherit_positive = 0;
                $inherit_sum = 0.0;
                foreach ($subtask_estimates as $_est) {
                    if ($_est !== null && is_numeric($_est) && (float)$_est > 0) {
                        $inherit_positive++;
                        $inherit_sum += (float)$_est;
                    }
                }
                unset($_est);

                // On validation failure, prefer the user's last POST over the saved
                // DB value so a failed save doesn't silently clear the flag.
                // ($effective_inherit is already computed in the PHP block at the
                // top of the file — see the $is_editing branch — so it is only
                // READ here, never re-assigned.)

                // When in inherit mode the disabled field keeps showing the resolved
                // sum so the user sees what will be saved.
                $posted_total_time = $_POST['total_time'] ?? null;
                if ($is_editing && $subtask_count > 0 && !empty($task['inherit_subtask_hours'])) {
                    $total_time_value = $inherit_positive > 0
                        ? rtrim(rtrim(number_format($inherit_sum, 4, '.', ''), '0'), '.')
                        : '';
                } elseif (is_scalar($posted_total_time)) {
                    $total_time_value = (string)$posted_total_time;
                } elseif (isset($task['estimated_hours']) && $task['estimated_hours'] !== null) {
                    $total_time_value = rtrim(rtrim(number_format((float)$task['estimated_hours'], 4, '.', ''), '0'), '.');
                } else {
                    $total_time_value = '';
                }
                $selected_time_unit = $_POST['total_time_unit'] ?? 'hours';
                $total_time_disabled = $effective_inherit;
                ?>
                <div class="field form-section">
                    <label for="total_time">Total Task Time</label>
                    <div class="range-row" style="gap:.5rem;">
                        <input class="input" type="number" id="total_time" name="total_time"
                               min="0" step="any" style="width:110px;"
                               value="<?= e($total_time_value) ?>"
                               placeholder="Not estimated"
                               <?= $total_time_disabled ? 'disabled aria-disabled="true"' : '' ?>>
                        <select class="select" id="total_time_unit" name="total_time_unit"
                                style="width:140px;"
                                <?= $total_time_disabled ? 'disabled aria-disabled="true"' : '' ?>>
                            <option value="hours" <?= $selected_time_unit === 'hours' ? 'selected' : '' ?>>Hours</option>
                            <option value="mandays" <?= $selected_time_unit === 'mandays' ? 'selected' : '' ?>>Mandays</option>
                        </select>
                    </div>
                    <?php if ($is_editing && $subtask_count > 0): ?>
                        <label class="checkbox" for="inherit_subtask_hours" style="margin-top:.6rem;">
                            <input type="checkbox"
                                   id="inherit_subtask_hours"
                                   name="inherit_subtask_hours"
                                   value="1"
                                   <?= $effective_inherit ? 'checked' : '' ?>
                                   data-inherit-toggle>
                            <span>Use sum of sub-task hours as total task time</span>
                        </label>
                        <span class="hint" id="inherit-hint" <?= $inherit_checked ? '' : 'hidden' ?>>
                            Computed total: <strong><span id="inherit-sum"><?= number_format($inherit_sum, 4, '.', '') ?></span></strong>
                            hours (from <span id="inherit-count"><?= (int)$inherit_positive ?></span> sub-task(s) with positive estimates).
                        </span>
                        <span class="hint" id="inherit-empty-hint" <?= ($inherit_checked && $inherit_positive === 0) ? '' : 'hidden' ?>>
                            None of the sub-tasks currently have a positive estimate. The parent's
                            completion will use the equal-average fallback until at least one
                            sub-task has a value.
                        </span>
                    <?php endif; ?>
                    <span class="hint">One manday equals 8 hours. Leave blank if the task has no estimate; zero is saved but is not a usable completion weight.</span>
                </div>

                <div class="field">
                    <label for="responsible_person_id">Responsible Person</label>
                    <select class="select" id="responsible_person_id" name="responsible_person_id">
                        <option value="">Select Person</option>
                        <?php foreach ($users as $user): ?>
                            <option value="<?= (int)$user['id'] ?>"
                                    <?= ($_POST['responsible_person_id'] ?? $task['responsible_person_id'] ?? '') == $user['id'] ? 'selected' : '' ?>>
                                <?= e($user['name']) ?> (<?= e($user['email']) ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="field">
                    <label for="contact_person_id">Contact Person</label>
                    <select class="select" id="contact_person_id" name="contact_person_id">
                        <option value="">No Contact Person</option>
                        <?php foreach ($contacts as $contact): ?>
                            <option value="<?= (int)$contact['id'] ?>"
                                    <?= ($_POST['contact_person_id'] ?? $task['contact_person_id'] ?? '') == $contact['id'] ? 'selected' : '' ?>>
                                <?= e($contact['name']) ?>
                                <?php if ($contact['email']): ?>
                                    (<?= e($contact['email']) ?>)
                                <?php endif; ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <span class="hint">Select a contact person from the project contacts</span>
                </div>

                <div class="field">
                    <label for="expected_completion_date">Expected Completion</label>
                    <input class="input" type="date" id="expected_completion_date" name="expected_completion_date"
                           value="<?= e($_POST['expected_completion_date'] ?? (isset($task['expected_completion_date']) && $task['expected_completion_date'] ? date('Y-m-d', strtotime($task['expected_completion_date'])) : '')) ?>">
                </div>

                <div class="field">
                    <label for="completion_date">Completion Date</label>
                    <input class="input" type="date" id="completion_date" name="completion_date"
                           value="<?= e($_POST['completion_date'] ?? (isset($task['completion_date']) && $task['completion_date'] ? date('Y-m-d', strtotime($task['completion_date'])) : '')) ?>">
                    <span class="hint">Set when the task is actually finished.</span>
                </div>

            </div>
        </div>
        <div class="card__footer">
            <button class="btn btn--primary" type="submit"><i class="fas fa-floppy-disk"></i> Save Task</button>
            <a class="btn btn--secondary" href="<?= $is_editing ? '/tasks/' . (int)$task['id'] : ($is_subtask ? '/tasks/' . (int)$parent_task['id'] : '/tasks') ?>">Cancel</a>
        </div>
    </form>

    <?php if ($is_editing): ?>
        <?php
        // The mockup shows a task code in the confirmation dialog. The tasks table
        // has no code column, so the code is derived from the row id: TSK-0007.
        $task_code = 'TSK-' . str_pad((string)(int)$task['id'], 4, '0', STR_PAD_LEFT);
        $delete_subtask_count = (int)$subtask_count;
        ?>
        <div class="card danger-zone" style="margin-top:var(--sp-5);">
            <div class="card__header">
                <h2 class="card__title-icon"><i class="fas fa-triangle-exclamation"></i> Danger zone</h2>
            </div>
            <div class="card__body">
                <p class="text-sm text-muted">
                    Deleting removes this task<?= $delete_subtask_count > 0 ? ', its ' . $delete_subtask_count . ' subtask' . ($delete_subtask_count === 1 ? '' : 's') : '' ?>
                    and every time entry logged against <?= $delete_subtask_count > 0 ? 'them' : 'it' ?>. This cannot be undone.
                </p>
                <button class="btn btn--danger btn--block" type="button" style="margin-top:1rem;"
                        data-modal-open="confirm-delete-task">
                    <i class="fas fa-trash"></i> Delete Task
                </button>
            </div>
        </div>

        <div class="modal-backdrop" id="confirm-delete-task" hidden>
            <div class="modal" role="dialog" aria-modal="true" aria-labelledby="cdt-title">
                <div class="modal__header">
                    <div>
                        <h3 id="cdt-title">Confirm task deletion</h3>
                        <p>Type the task name exactly as shown to confirm.</p>
                    </div>
                    <button class="modal__close" type="button" data-modal-close aria-label="Close"><i class="fas fa-xmark"></i></button>
                </div>
                <div class="modal__body">
                    <div class="alert alert--danger">
                        <i class="fas fa-triangle-exclamation"></i>
                        <div class="alert__body">
                            <div class="alert__title"><?= e($task['name']) ?></div>
                            This task<?= $delete_subtask_count > 0 ? ', its ' . $delete_subtask_count . ' subtask' . ($delete_subtask_count === 1 ? '' : 's') : '' ?>
                            and all logged time entries will be permanently removed.
                        </div>
                    </div>
                    <form method="POST" action="/tasks/<?= (int)$task['id'] ?>/delete">
                        <input type="hidden" name="csrf_token" value="<?= e(getCsrfToken()) ?>">
                        <input type="hidden" name="task_id" value="<?= (int)$task['id'] ?>">
                        <div class="field">
                            <label for="delete-task-confirmation">Task Name <span class="req" aria-hidden="true">*</span></label>
                            <input class="input text-mono" type="text" id="delete-task-confirmation"
                                   name="confirmation_name" placeholder="<?= e($task['name']) ?>">
                            <span class="hint">Must exactly match <strong><?= e($task['name']) ?></strong>.</span>
                        </div>
                        <div class="modal__footer">
                            <button class="btn btn--secondary" type="button" data-modal-close>Cancel</button>
                            <button class="btn btn--danger" type="submit">
                                <i class="fas fa-trash"></i> Delete permanently
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    <?php endif; ?>
</div>

<?php include 'includes/footer.php'; ?>