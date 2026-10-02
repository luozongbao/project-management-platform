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

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name = trim($_POST['name'] ?? '');
    $description = trim($_POST['description'] ?? '');
    $status = $_POST['status'] ?? 'not_started';
    $completion_percentage = floatval($_POST['completion_percentage'] ?? 0);
    $expected_completion_date = $_POST['expected_completion_date'] ?? null;
    $completion_date = $_POST['completion_date'] ?? null;
    $responsible_person_id = $_POST['responsible_person_id'] ?? ($task['responsible_person_id'] ?? $user_id);
    $contact_person_id = $_POST['contact_person_id'] ?? null;
    $form_project_id = $_POST['project_id'] ?? $project_id;
    
    $errors = [];
    
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
                // Update existing task
                $db->execute(
                    "UPDATE tasks SET 
                        name = ?, description = ?, status = ?, completion_percentage = ?,
                        expected_completion_date = ?, completion_date = ?,
                        responsible_person_id = ?, contact_person_id = ?, updated_at = NOW()
                     WHERE id = ?",
                    [
                        $name, $description, $status, $completion_percentage,
                        $expected_completion_date, $completion_date,
                        $responsible_person_id, $contact_person_id, $task_id
                    ]
                );
                
                redirect("task_detail.php?id=$task_id", 'Task updated successfully!', 'success');
            } else {
                // Create new task
                $final_project_id = $is_subtask ? $parent_task['project_id'] : $form_project_id;
                
                $db->execute(
                    "INSERT INTO tasks (
                        name, description, status, completion_percentage,
                        expected_completion_date, completion_date,
                        responsible_person_id, contact_person_id,
                        project_id, parent_task_id, created_at, updated_at
                     ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())",
                    [
                        $name, $description, $status, $completion_percentage,
                        $expected_completion_date, $completion_date,
                        $responsible_person_id, $contact_person_id,
                        $final_project_id, $parent_id
                    ]
                );
                
                $new_task_id = $db->lastInsertId();
                
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
                    
                    <div class="form-group">
                        <label for="status">Status:</label>
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
                <div class="form-section">
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
.page-container {
    max-width: 1000px;
    margin: 0 auto;
    padding: 20px;
}

.breadcrumb {
    font-size: 0.9rem;
    color: #666;
    margin-bottom: 10px;
}

.breadcrumb a {
    color: #007bff;
    text-decoration: none;
}

.breadcrumb a:hover {
    text-decoration: underline;
}

.breadcrumb span {
    margin: 0 8px;
}

.form-layout {
    background: white;
    border-radius: 12px;
    box-shadow: 0 2px 12px rgba(0,0,0,0.08);
    padding: 30px;
    margin-top: 20px;
}

.form-grid {
    display: grid;
    grid-template-columns: repeat(2, 1fr);
    gap: 30px;
    margin-bottom: 30px;
}

.form-section h3 {
    margin: 0 0 20px 0;
    color: #333;
    font-size: 1.2rem;
    border-bottom: 2px solid #f1f3f4;
    padding-bottom: 10px;
}

.form-group {
    margin-bottom: 20px;
}

.form-group label {
    display: block;
    font-weight: 500;
    color: #333;
    margin-bottom: 8px;
    font-size: 0.95rem;
}

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

.field-help {
    font-size: 0.85rem;
    color: #666;
    margin: 5px 0 0 0;
    font-style: italic;
}

.form-actions {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding-top: 20px;
    border-top: 1px solid #f1f3f4;
}

.form-actions-left {
    display: flex;
    gap: 15px;
}

.form-actions-right {
    display: flex;
    gap: 15px;
}

.form-actions .btn {
    padding: 12px 24px;
    font-weight: 500;
}

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

.alert ul {
    margin: 0;
    padding-left: 20px;
}

.alert li {
    margin-bottom: 5px;
}

.alert li:last-child {
    margin-bottom: 0;
}

@media (max-width: 768px) {
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