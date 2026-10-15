<?php
require_once 'config.php';
require_once 'includes/Database.php';
require_once 'includes/functions.php';

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

// Get users for responsible person assignment
$users = $db->fetchAll("SELECT id, name, email FROM users ORDER BY name");

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
    }
    
    if (empty($responsible_person_id)) {
        $errors[] = "Responsible person must be assigned to the task.";
    } else {
        // Validate that the responsible person exists
        $person_exists = $db->fetchOne("SELECT id FROM users WHERE id = ?", [$responsible_person_id]);
        if (!$person_exists) {
            $errors[] = "Selected responsible person does not exist.";
        }
    }
    
    if (!empty($contact_person_id)) {
        // Validate that the contact person exists
        $contact_exists = $db->fetchOne("SELECT id FROM contacts WHERE id = ?", [$contact_person_id]);
        if (!$contact_exists) {
            $errors[] = "Selected contact person does not exist.";
        }
    }
    
    if (!in_array($status, ['not_started', 'in_progress', 'completed', 'on_hold'])) {
        $errors[] = "Invalid task status selected.";
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
                        responsible_person_id = ?, contact_person_id = ?, updated_at = NOW()
                     WHERE id = ?",
                    [
                        $name, $description, $status, $completion_percentage,
                        $expected_completion_date, $completion_date, $estimated_hours,
                        $stored_inherit,
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
                        responsible_person_id, contact_person_id,
                        project_id, parent_task_id, created_at, updated_at
                     ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())",
                    [
                        $name, $description, $status, $completion_percentage,
                        $expected_completion_date, $completion_date, $estimated_hours,
                        0, // new tasks can never start in inherit mode (no children yet)
                        $responsible_person_id, $contact_person_id,
                        $final_project_id, $parent_id
                    ]
                );

                $new_task_id = $db->lastInsertId();
                syncProjectTaskCompletions($db, $final_project_id);

                redirect("task_detail.php?id=$new_task_id", 'Task created successfully!', 'success');
            }
        } catch (Exception $e) {
            $errors[] = "Error saving task: " . $e->getMessage();
        }
    }
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