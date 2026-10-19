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
        redirect('tasks.php');
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
        redirect('tasks.php');
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

    $errors = [];
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
    
    if (!empty($expected_completion_date)) {
        $expected_date = DateTime::createFromFormat('Y-m-d', $expected_completion_date);
        if (!$expected_date) {
            $errors[] = "Invalid expected completion date format.";
        }
    }
    
    if (!empty($completion_date)) {
        $comp_date = DateTime::createFromFormat('Y-m-d\TH:i', $completion_date);
        if (!$comp_date) {
            $errors[] = "Invalid completion date format.";
        }
    }
    
    // If status is completed, ensure completion percentage is 100 and completion date is set
    if ($status === 'completed') {
        $completion_percentage = 100;
        if (empty($completion_date)) {
            $completion_date = date('Y-m-d H:i:s');
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

                redirect("task_detail.php?id=$task_id", 'Task updated successfully!', 'success');
            } else {
                // Create new task
                $final_project_id = $is_subtask ? $parent_task['project_id'] : $form_project_id;

                $db->execute(
                    "INSERT INTO tasks (
                        name, description, status, completion_percentage,
                        expected_completion_date, completion_date, estimated_hours,
                        inherit_subtask_hours,
                        on_hold_reason, delay_reason,
                        responsible_person_id, contact_person_id,
                        project_id, parent_task_id, created_at, updated_at
                     ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())",
                    [
                        $name, $description, $status, $completion_percentage,
                        $expected_completion_date, $completion_date, $estimated_hours,
                        0, // new tasks can never start in inherit mode (no children yet)
                        $stored_on_hold_reason, $stored_delay_reason,
                        $responsible_person_id, $contact_person_id,
                        $final_project_id, $parent_id
                    ]
                );

                $new_task_id = $db->lastInsertId();
                syncProjectTaskCompletions($db, $final_project_id);

                redirect("task_detail.php?id=$new_task_id", 'Task created successfully!', 'success');
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
?>

<?php include 'includes/header.php'; ?>

<div class="page-container">
    <div class="page-header">
        <div class="page-title">
            <div class="breadcrumb">
                <a href="dashboard.php">Dashboard</a>
                <span>/</span>
                <a href="tasks.php">Tasks</a>
                <?php if ($is_editing): ?>
                    <span>/</span>
                    <a href="task_detail.php?id=<?= $task_id ?>"><?= e($task['name']) ?></a>
                    <span>/</span>
                    <span>Edit</span>
                <?php elseif ($is_subtask): ?>
                    <span>/</span>
                    <a href="task_detail.php?id=<?= $parent_id ?>"><?= e($parent_task['name']) ?></a>
                    <span>/</span>
                    <span>New Subtask</span>
                <?php else: ?>
                    <span>/</span>
                    <span>New Task</span>
                <?php endif; ?>
            </div>
            <h1>
                <i class="fas fa-<?= $is_subtask ? 'level-down-alt' : ($is_editing ? 'edit' : 'plus') ?>"></i>
                <?= $title ?>
            </h1>
            <?php if ($is_subtask): ?>
                <p>Creating subtask under: <strong><?= e($parent_task['name']) ?></strong></p>
            <?php endif; ?>
        </div>
    </div>

    <?php if (!empty($errors)): ?>
        <div class="alert alert-danger">
            <ul>
                <?php foreach ($errors as $error): ?>
                    <li><?= e($error) ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
    <?php endif; ?>

    <div class="form-layout">
        <form method="POST" class="task-form">
            <input type="hidden" name="csrf_token" value="<?= e(getCsrfToken()) ?>">
            <div class="form-grid">
                <!-- Basic Information -->
                <div class="form-section">
                    <h3>Basic Information</h3>
                    
                    <div class="form-group">
                        <label for="name" class="required">Task Name:</label>
                        <input type="text" id="name" name="name" 
                               value="<?= e($_POST['name'] ?? $task['name'] ?? '') ?>" 
                               required maxlength="255">
                    </div>
                    
                    <div class="form-group">
                        <label for="description">Description:</label>
                        <textarea id="description" name="description" rows="4" 
                                  placeholder="Detailed description of the task..."><?= e($_POST['description'] ?? $task['description'] ?? '') ?></textarea>
                    </div>
                    
                    <?php if (!$is_subtask): ?>
                        <div class="form-group">
                            <label for="project_id" class="required">Project:</label>
                            <select id="project_id" name="project_id" required>
                                <option value="">Select Project</option>
                                <?php foreach ($user_projects as $project): ?>
                                    <option value="<?= $project['id'] ?>" 
                                            <?= ($_POST['project_id'] ?? $project_id) == $project['id'] ? 'selected' : '' ?>>
                                        <?= e($project['name']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    <?php else: ?>
                        <div class="form-group">
                            <label>Project:</label>
                            <div class="readonly-field">
                                <i class="fas fa-folder"></i>
                                <?= e($parent_task['project_name']) ?>
                            </div>
                        </div>
                    <?php endif; ?>
                </div>

                <!-- Status & Progress -->
                <div class="form-section">
                    <h3>Status & Progress</h3>

                    <?php
                    // Issue-012: for a parent with sub-tasks, the status field is
                    // a read-only display. The on-hold / resume button on the task
                    // detail page is the only path that mutates status.
                    $is_parent_with_subs = $is_editing && ($task['parent_task_id'] ?? null) === null && $subtask_count > 0;
                    $status_label = ucfirst(str_replace('_', ' ', $task['status'] ?? 'not_started'));
                    ?>
                    <div class="form-group">
                        <label for="status">Status:</label>
                        <?php if ($is_parent_with_subs): ?>
                            <input type="text" id="status_display" name="status_display"
                                   value="<?= e($status_label) ?>"
                                   disabled aria-describedby="status_help">
                            <input type="hidden" name="status" value="<?= e($task['status']) ?>">
                            <p class="field-help" id="status_help">
                                <i class="fas fa-magic"></i>
                                This task has <?= (int)$subtask_count ?> sub-task(s), so its
                                status follows the sub-tasks automatically:
                                <strong>completed</strong> if all sub-tasks are completed,
                                <strong>in progress</strong> if any are in progress, and
                                <strong>not started</strong> otherwise. To put this parent
                                on hold, use the “On Hold” action on the task detail page.
                            </p>
                        <?php else: ?>
                            <select id="status" name="status" onchange="handleStatusChange(this.value)">
                                <option value="not_started" <?= ($_POST['status'] ?? $task['status'] ?? 'not_started') === 'not_started' ? 'selected' : '' ?>>
                                    Not Started
                                </option>
                                <option value="in_progress" <?= ($_POST['status'] ?? $task['status'] ?? '') === 'in_progress' ? 'selected' : '' ?>>
                                    In Progress
                                </option>
                                <option value="completed" <?= ($_POST['status'] ?? $task['status'] ?? '') === 'completed' ? 'selected' : '' ?>>
                                    Completed
                                </option>
                                <option value="on_hold" <?= ($_POST['status'] ?? $task['status'] ?? '') === 'on_hold' ? 'selected' : '' ?>>
                                    On Hold
                                </option>
                            </select>
                        <?php endif; ?>
                    </div>

                    <div class="form-group">
                        <label for="completion_percentage">Completion Percentage:</label>
                        <div class="percentage-input">
                            <input type="range" id="completion_slider" min="0" max="100" step="5"
                                   value="<?= $_POST['completion_percentage'] ?? $task['completion_percentage'] ?? 0 ?>"
                                   oninput="updatePercentageInput(this.value)">
                            <input type="number" id="completion_percentage" name="completion_percentage"
                                   min="0" max="100" step="0.1"
                                   value="<?= $_POST['completion_percentage'] ?? $task['completion_percentage'] ?? 0 ?>"
                                   oninput="updatePercentageSlider(this.value)">
                            <span class="percentage-symbol">%</span>
                        </div>
                    </div>

                    <?php // Issue-034 v1.8.0 — Status reason textareas. Each one is
                          // shown only when its condition already holds on the row
                          // (or is being posted in the same request, e.g. turning a
                          // task to on_hold). The PHP normalizer (above) re-evaluates
                          // the condition at save time, so even a stale DOM render is
                          // safe — the values just won't survive into the UPDATE. ?>
                    <?php
                        // Prefer the value the user is in the middle of posting
                        // (so failed validation does not blank the field); fall back
                        // to the row value.
                        $rendered_on_hold = $_POST['on_hold_reason'] ?? ($task['on_hold_reason'] ?? '');
                        $rendered_delay   = $_POST['delay_reason']   ?? ($task['delay_reason']   ?? '');
                        if (!is_string($rendered_on_hold)) { $rendered_on_hold = ''; }
                        if (!is_string($rendered_delay))   { $rendered_delay   = ''; }

                        // The on-hold reason is relevant any time status==='on_hold',
                        // including for parents (where the field cannot drive a
                        // status change BUT can edit the existing reason on an
                        // already-on-hold parent — server normalizer will null it
                        // out on save unless the status is unchanged to on_hold).
                        $show_on_hold_reason = ($_POST['status'] ?? ($task['status'] ?? 'not_started')) === 'on_hold';

                        // The delay reason is shown when (a) the row is delayed now,
                        // or (b) the POST would make it delayed (date entered
                        // programmatically during this submission).
                        $expected_for_render = $_POST['expected_completion_date'] ?? ($task['expected_completion_date'] ?? null);
                        $show_delay_reason = ($_POST['status'] ?? ($task['status'] ?? 'not_started')) !== 'completed'
                            && !empty($expected_for_render)
                            && strtotime((string)$expected_for_render) < strtotime(date('Y-m-d'));
                    ?>
                    <?php if ($show_on_hold_reason): ?>
                        <div class="form-group">
                            <label for="on_hold_reason" class="required">On-hold reason:</label>
                            <textarea id="on_hold_reason" name="on_hold_reason" rows="3"
                                      maxlength="2000" required
                                      placeholder="Why is this task on hold? (Required when status is On Hold.)"><?= e($rendered_on_hold) ?></textarea>
                            <p class="field-help">
                                <i class="fas fa-info-circle"></i>
                                The reason is shown on the task list and on the client
                                portal so the manager and the client can scan it at a glance.
                            </p>
                        </div>
                    <?php endif; ?>
                    <?php if ($show_delay_reason): ?>
                        <div class="form-group">
                            <label for="delay_reason" class="required">Delay reason:</label>
                            <textarea id="delay_reason" name="delay_reason" rows="3"
                                      maxlength="2000" required
                                      placeholder="Why is this task past its expected completion date? (Required when the task is delayed.)"><?= e($rendered_delay) ?></textarea>
                            <p class="field-help">
                                <i class="fas fa-info-circle"></i>
                                Required whenever the task is past its expected completion
                                date. Leave the field empty after moving the date forward,
                                or vice-versa; the field is cleared automatically when the
                                task is no longer delayed.
                            </p>
                        </div>
                    <?php endif; ?>
                </div>

                <div class="form-section">
                    <h3>Effort Estimate</h3>
                    <?php
                    // Issue-011: render the inherit checkbox only for a parent
                    // task that has at least one sub-task. Compute the live sum
                    // and positive-estimate count for the hint.
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
                    ?>
                    <?php if ($is_editing && $subtask_count > 0): ?>
                        <?php
                        // On validation failure, prefer the user's last POST over the
                        // saved DB value so a failed save doesn't silently clear the flag.
                        $effective_inherit = $inherit_from_post !== null
                            ? ((int)$inherit_from_post === 1)
                            : $inherit_checked;
                        ?>
                        <div class="form-group inherit-group">
                            <label class="checkbox-label" for="inherit_subtask_hours">
                                <input type="checkbox"
                                       id="inherit_subtask_hours"
                                       name="inherit_subtask_hours"
                                       value="1"
                                       <?= $effective_inherit ? 'checked' : '' ?>
                                       data-inherit-toggle
                                       aria-describedby="inherit-hint">
                                <span>Use sum of sub-task hours as total task time</span>
                            </label>
                            <p class="field-help">When checked, the total below is locked to the sum of this task's sub-task estimates. Leave unchecked to enter a manual total.</p>
                            <p class="field-help" id="inherit-hint" <?= $inherit_checked ? '' : 'hidden' ?>>
                                Computed total: <strong><span id="inherit-sum"><?= number_format($inherit_sum, 4, '.', '') ?></span></strong>
                                hours (from <span id="inherit-count"><?= (int)$inherit_positive ?></span> sub-task(s) with
                                positive estimates).
                            </p>
                            <p class="field-help" id="inherit-empty-hint" <?= ($inherit_checked && $inherit_positive === 0) ? '' : 'hidden' ?>>
                                None of the sub-tasks currently have a positive estimate. The
                                parent's completion will use the equal-average fallback until
                                at least one sub-task has a value.
                            </p>
                        </div>
                    <?php endif; ?>

                    <div class="form-group">
                        <label for="total_time">Total Task Time:</label>
                        <div class="estimate-input">
                            <?php
                            // Issue-011: when in inherit mode the disabled field keeps
                            // showing the resolved sum so the user sees what will be saved.
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
                            <input
                                type="number"
                                id="total_time"
                                name="total_time"
                                min="0"
                                step="any"
                                value="<?= e($total_time_value) ?>"
                                placeholder="Not estimated"
                                <?= $total_time_disabled ? 'disabled aria-disabled="true"' : '' ?>>
                            <select id="total_time_unit" name="total_time_unit"
                                    aria-label="Total task time unit"
                                    <?= $total_time_disabled ? 'disabled aria-disabled="true"' : '' ?>>
                                <option value="hours" <?= $selected_time_unit === 'hours' ? 'selected' : '' ?>>Hours</option>
                                <option value="mandays" <?= $selected_time_unit === 'mandays' ? 'selected' : '' ?>>Mandays</option>
                            </select>
                        </div>
                        <p class="field-help">One manday equals 8 hours. Leave blank if the task has no estimate; zero is saved but is not a usable completion weight.</p>
                    </div>
                </div>

                <!-- Assignment -->
                <div class="form-section">
                    <h3>Assignment</h3>
                    
                    <div class="form-group">
                        <label for="responsible_person_id">Responsible Person:</label>
                        <select id="responsible_person_id" name="responsible_person_id">
                            <option value="">Select Person</option>
                            <?php foreach ($users as $user): ?>
                                <option value="<?= $user['id'] ?>" 
                                        <?= ($_POST['responsible_person_id'] ?? $task['responsible_person_id'] ?? '') == $user['id'] ? 'selected' : '' ?>>
                                    <?= e($user['name']) ?> (<?= e($user['email']) ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div class="form-group">
                        <label for="contact_person_id">Contact Person:</label>
                        <select id="contact_person_id" name="contact_person_id">
                            <option value="">No Contact Person</option>
                            <?php foreach ($contacts as $contact): ?>
                                <option value="<?= $contact['id'] ?>" 
                                        <?= ($_POST['contact_person_id'] ?? $task['contact_person_id'] ?? '') == $contact['id'] ? 'selected' : '' ?>>
                                    <?= e($contact['name']) ?>
                                    <?php if ($contact['email']): ?>
                                        (<?= e($contact['email']) ?>)
                                    <?php endif; ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <p class="field-help">Select a contact person from the project contacts</p>
                    </div>
                </div>

                <!-- Dates -->
                <div class="form-section full-row">
                    <h3>Dates</h3>
                    
                    <div class="form-group">
                        <label for="expected_completion_date">Expected Completion:</label>
                        <input type="date" id="expected_completion_date" name="expected_completion_date" 
                               value="<?= $_POST['expected_completion_date'] ?? (isset($task['expected_completion_date']) && $task['expected_completion_date'] ? date('Y-m-d', strtotime($task['expected_completion_date'])) : '') ?>">
                    </div>
                    
                    <div class="form-group" id="completion_date_group" style="display: none;">
                        <label for="completion_date">Completion Date:</label>
                        <input type="datetime-local" id="completion_date" name="completion_date" 
                               value="<?= $_POST['completion_date'] ?? (isset($task['completion_date']) && $task['completion_date'] ? date('Y-m-d\TH:i', strtotime($task['completion_date'])) : '') ?>">
                    </div>
                </div>
            </div>

            <div class="form-actions">
                <div class="form-actions-left">
                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-save"></i>
                        <?= $is_editing ? 'Update Task' : 'Create Task' ?>
                    </button>
                    <a href="<?= $is_editing ? "task_detail.php?id=$task_id" : ($is_subtask ? "task_detail.php?id=$parent_id" : 'tasks.php') ?>" 
                       class="btn btn-secondary">
                        <i class="fas fa-times"></i>
                        Cancel
                    </a>
                    <?php if ($is_editing && !($task['parent_task_id'] ?? null)): ?>
                        <a href="task_edit.php?parent_id=<?= $task_id ?>" class="btn btn-outline">
                            <i class="fas fa-plus"></i>
                            Add Subtask
                        </a>
                    <?php endif; ?>
                </div>
                <?php if ($is_editing): ?>
                    <div class="form-actions-right">
                        <button type="button" class="btn btn-danger" onclick="confirmDelete()">
                            <i class="fas fa-trash"></i>
                            Delete Task
                        </button>
                    </div>
                <?php endif; ?>
            </div>
        </form>
    </div>
</div>

<script>
// Initialize form on page load
document.addEventListener('DOMContentLoaded', function() {
    const status = document.getElementById('status').value;
    handleStatusChange(status);
});

// Handle status change
function handleStatusChange(status) {
    const completionDateGroup = document.getElementById('completion_date_group');
    const completionPercentage = document.getElementById('completion_percentage');
    const completionSlider = document.getElementById('completion_slider');
    
    if (status === 'completed') {
        completionDateGroup.style.display = 'block';
        completionPercentage.value = 100;
        completionSlider.value = 100;
        
        // Set completion date to now if empty
        const completionDateInput = document.getElementById('completion_date');
        if (!completionDateInput.value) {
            const now = new Date();
            const offset = now.getTimezoneOffset();
            const adjustedDate = new Date(now.getTime() - (offset * 60 * 1000));
            completionDateInput.value = adjustedDate.toISOString().slice(0, 16);
        }
    } else {
        completionDateGroup.style.display = 'none';
        document.getElementById('completion_date').value = '';
    }
}

// Sync percentage slider and input
function updatePercentageInput(value) {
    document.getElementById('completion_percentage').value = value;
}

function updatePercentageSlider(value) {
    document.getElementById('completion_slider').value = value;
}

// Issue-011: when the inherit checkbox is toggled, lock/unlock the
// total_time field. The server is the source of truth; this is a UX
// nicety so the field visually disables without a round-trip.
document.addEventListener('DOMContentLoaded', function () {
    var inheritBox = document.getElementById('inherit_subtask_hours');
    if (!inheritBox) { return; }
    var total = document.getElementById('total_time');
    var unit = document.getElementById('total_time_unit');
    var hint = document.getElementById('inherit-hint');
    var emptyHint = document.getElementById('inherit-empty-hint');
    var sumEl = document.getElementById('inherit-sum');
    var countEl = document.getElementById('inherit-count');

    function apply() {
        var on = inheritBox.checked;
        if (total) { total.disabled = on; total.setAttribute('aria-disabled', on ? 'true' : 'false'); }
        if (unit)  { unit.disabled  = on; unit.setAttribute('aria-disabled',  on ? 'true' : 'false'); }
        if (hint)  { hint.hidden = !on; }
        if (emptyHint) { emptyHint.hidden = !(on && countEl && countEl.textContent === '0'); }
    }
    inheritBox.addEventListener('change', apply);
    apply();
});

// Load contacts for selected project
function loadProjectContacts(projectId) {
    const contactSelect = document.getElementById('contact_person_id');
    
    if (!projectId) {
        contactSelect.innerHTML = '<option value="">No Contact Person</option>';
        return;
    }
    
    fetch(`ajax/get_project_contacts.php?project_id=${projectId}`)
        .then(response => response.json())
        .then(data => {
            contactSelect.innerHTML = '<option value="">No Contact Person</option>';
            data.contacts.forEach(contact => {
                const option = document.createElement('option');
                option.value = contact.id;
                option.textContent = contact.name + (contact.email ? ` (${contact.email})` : '');
                contactSelect.appendChild(option);
            });
        })
        .catch(error => {
            console.error('Error loading contacts:', error);
        });
}
</script>

<style>
/* Per-page CSS for task_edit.php.
 *
 * As of v1.6.0 (issue-014), the global rules in assets/css/style.css
 * provide the baseline (page-container, breadcrumb, form-layout,
 * form-grid, form-section, form-group, btn, alert, page-header,
 * page-actions, checkbox-label, estimate-input, field-help,
 * form-actions, form-actions-left/right, and the @media (max-width:
 * 768px) collapse). Rules below are EITHER page-unique to this form
 * OR explicit per-page overrides of a global rule (the divergence is
 * intentional; comments tag them).
 */

/* Per-page override of .form-grid in style.css: this form prefers
 * wider gaps (30px) and an extra bottom margin to separate sections
 * visually. The 2-column structure itself matches the global rule. */
.form-grid {
    display: grid;
    grid-template-columns: repeat(2, 1fr);
    gap: 30px;
    margin-bottom: 30px;
}

/* Per-page override of .form-grid .form-section h3 in style.css:
 * heavier border-bottom (2px vs 1px) and larger font (1.2rem vs 1rem). */
.form-section h3 {
    margin: 0 0 20px 0;
    color: #333;
    font-size: 1.2rem;
    border-bottom: 2px solid #f1f3f4;
    padding-bottom: 10px;
}

/* Per-page override of .form-group in style.css: this form keeps a
 * tighter 1px input border (vs the global 2px) and a 0.95rem font size. */
.form-group label.required::after {
    content: ' *';
    color: #dc3545;
}

.form-group input,
.form-group select,
.form-group textarea {
    width: 100%;
    padding: 12px;
    border: 1px solid #ddd;
    border-radius: 6px;
    font-size: 0.95rem;
    transition: border-color 0.3s ease, box-shadow 0.3s ease;
}

.form-group input:focus,
.form-group select:focus,
.form-group textarea:focus {
    outline: none;
    border-color: #007bff;
    box-shadow: 0 0 0 3px rgba(0, 123, 255, 0.1);
}

.form-group textarea {
    resize: vertical;
    min-height: 100px;
}

.readonly-field {
    padding: 12px;
    background: #f8f9fa;
    border: 1px solid #e9ecef;
    border-radius: 6px;
    color: #666;
    display: flex;
    align-items: center;
    gap: 8px;
}

.percentage-input {
    display: flex;
    align-items: center;
    gap: 15px;
}

.percentage-input input[type="range"] {
    flex: 1;
    width: auto;
}

.percentage-input input[type="number"] {
    width: 80px;
    flex-shrink: 0;
}

.percentage-symbol {
    color: #666;
    font-weight: 500;
}

/* Per-page override of .estimate-input in style.css: identical layout
 * but this file ships the rule inline so the form renders correctly
 * even if a future change to style.css drops it. */
.estimate-input {
    display: flex;
    gap: 10px;
}

.estimate-input input {
    min-width: 0;
    flex: 1;
}

.estimate-input select {
    width: auto;
    min-width: 120px;
}

/* Per-page override of .alert / .alert-danger in style.css: this form
 * uses an all-around 1px border (vs the global 4px border-left). */
.alert {
    padding: 15px;
    border-radius: 8px;
    margin-bottom: 20px;
}

.alert-danger {
    background: #f8d7da;
    border: 1px solid #f5c6cb;
    color: #721c24;
}

@media (max-width: 768px) {
    /* Per-page mobile overrides. The baseline mobile collapse for
     * .form-grid and .estimate-input now lives in style.css; only the
     * page-specific mobile tweaks remain here. */
    .page-container {
        padding: 15px;
    }

    .form-layout {
        padding: 20px;
    }

    .form-grid {
        grid-template-columns: 1fr;
        gap: 20px;
    }

    .form-actions {
        flex-direction: column;
        gap: 15px;
    }

    .form-actions-left,
    .form-actions-right {
        flex-direction: column;
        width: 100%;
    }

    .form-actions .btn {
        text-align: center;
    }

    .percentage-input {
        flex-direction: column;
        align-items: stretch;
    }

    .percentage-input input[type="number"] {
        width: 100%;
    }
}
</style>

<script>
const totalTimeInput = document.getElementById('total_time');
const totalTimeUnit = document.getElementById('total_time_unit');

totalTimeUnit.addEventListener('change', function() {
    const amount = Number(totalTimeInput.value);
    if (totalTimeInput.value.trim() === '' || !Number.isFinite(amount)) {
        totalTimeUnit.dataset.previousUnit = this.value;
        return;
    }

    const hours = totalTimeUnit.dataset.previousUnit === 'mandays' ? amount * 8 : amount;
    const newAmount = this.value === 'mandays' ? hours / 8 : hours;
    totalTimeInput.value = String(newAmount);
    totalTimeUnit.dataset.previousUnit = this.value;
});

totalTimeUnit.dataset.previousUnit = totalTimeUnit.value;
</script>

<?php if ($is_editing): ?>
<script>
function confirmDelete() {
    if (confirm('Are you sure you want to delete this task? This action cannot be undone.')) {
        window.location.href = 'task_delete.php?id=<?= $task_id ?>';
    }
}
</script>
<?php endif; ?>

<?php include 'includes/footer.php'; ?>