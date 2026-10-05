<?php
require_once 'config.php';
require_once 'includes/Database.php';
require_once 'includes/functions.php';

requireLogin();

$db = Database::getInstance();
$user_id = getCurrentUserId();
$project_id = $_GET['id'] ?? null;
$errors = [];

// Handle form submission for project creation/update
if ($_POST) {
    $name = sanitize($_POST['name'] ?? '');
    $description = sanitize($_POST['description'] ?? '');
    $scope = sanitize($_POST['scope'] ?? '');
    $budget_amount_raw = trim($_POST['budget_amount'] ?? '');
    $currency = strtoupper(trim($_POST['currency'] ?? ''));
    $expected_completion_date = $_POST['expected_completion_date'] ?? '';
    $completion_date = $_POST['completion_date'] ?? '';
    $status = $_POST['status'] ?? 'not_started';

    // Project dates are calendar dates (SQL DATE), not timestamps.
    $parseProjectDate = static function ($value, $label) use (&$errors) {
        if (!is_string($value)) {
            $errors[] = "$label must be a valid date.";
            return null;
        }

        $value = trim($value);
        if ($value === '') {
            return null;
        }

        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/D', $value)) {
            $errors[] = "$label must be a valid date.";
            return null;
        }

        [$year, $month, $day] = array_map('intval', explode('-', $value));
        if (!checkdate($month, $day, $year)) {
            $errors[] = "$label must be a valid date.";
            return null;
        }

        return $value;
    };
    $expected_completion_date = $parseProjectDate($expected_completion_date, 'Expected completion date');
    $completion_date = $parseProjectDate($completion_date, 'Actual completion date');

    // Budget amount parsing — store NULL when blank.
    $budget_amount = null;
    if ($budget_amount_raw !== '') {
        // Accept "1,234.56" as well as plain "1234.56".
        $normalized = str_replace([',', ' '], ['', ''], $budget_amount_raw);
        if (is_numeric($normalized) && (float)$normalized >= 0) {
            $budget_amount = round((float)$normalized, 2);
        } else {
            $errors[] = "Budget must be a positive number.";
        }
    }
    // Currency: blank OR one of the allowed codes.
    if ($currency === '') {
        $currency = null;
    } elseif (!isAllowedCurrency($currency)) {
        $errors[] = "Currency must be one of: " . implode(', ', allowedCurrencies()) . '.';
    }
    // If amount provided but currency missing (or vice-versa), warn.
    if ($budget_amount !== null && $currency === null) {
        $errors[] = "Please choose a currency for the budget, or leave the amount blank.";
    }
    if ($budget_amount === null && $currency !== null) {
        $errors[] = "Please enter a budget amount, or choose no currency.";
    }

    // Validation
    if (empty($name)) $errors[] = "Project name is required";

    if (empty($errors)) {
        try {
            if ($project_id) {
                // Update existing project (share_code is intentionally NOT regenerated)
                $db->execute(
                    "UPDATE projects SET name = ?, description = ?, scope = ?, budget_amount = ?,
                     currency = ?, expected_completion_date = ?, completion_date = ?, status = ?,
                     updated_at = NOW() WHERE id = ? AND responsible_person_id = ?",
                    [$name, $description, $scope, $budget_amount, $currency,
                     $expected_completion_date, $completion_date, $status, $project_id, $user_id]
                );
                redirect('project_detail.php?id=' . $project_id, 'Project updated successfully!', 'success');
            } else {
                // Create new project — auto-generate share_code (with one retry on
                // the astronomically-unlikely collision).
                $share_code = generateShareCode();
                try {
                    $db->execute(
                        "INSERT INTO projects (name, description, scope, budget_amount, currency,
                         share_code, responsible_person_id, expected_completion_date,
                         completion_date, status, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())",
                        [$name, $description, $scope, $budget_amount, $currency,
                         $share_code, $user_id, $expected_completion_date,
                         $completion_date, $status]
                    );
                } catch (Exception $e) {
                    $share_code = generateShareCode();
                    $db->execute(
                        "INSERT INTO projects (name, description, scope, budget_amount, currency,
                         share_code, responsible_person_id, expected_completion_date,
                         completion_date, status, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())",
                        [$name, $description, $scope, $budget_amount, $currency,
                         $share_code, $user_id, $expected_completion_date,
                         $completion_date, $status]
                    );
                }
                $new_project_id = $db->lastInsertId();
                redirect('project_detail.php?id=' . $new_project_id, 'Project created successfully!', 'success');
            }
        } catch (Exception $e) {
            $errors[] = "Failed to save project. Please try again.";
        }
    }
}

// Load existing project data
$project = null;
if ($project_id) {
    $project = $db->fetchOne(
        "SELECT * FROM projects WHERE id = ? AND responsible_person_id = ?",
        [$project_id, $user_id]
    );
    
    if (!$project) {
        redirect('projects.php', 'Project not found.', 'danger');
    }
}

$title = $project ? "Edit Project" : "New Project";
$show_nav = true;
$csrf_token = $project ? getCsrfToken() : null;
?>

<?php include 'includes/header.php'; ?>

<div class="page-container">
    <div class="page-header">
        <div class="page-title">
            <h1>
                <i class="fas fa-<?= $project ? 'edit' : 'plus' ?>"></i>
                <?= $project ? 'Edit Project' : 'New Project' ?>
            </h1>
            <p><?= $project ? 'Update your project details' : 'Create a new project to get started' ?></p>
        </div>
        <div class="page-actions">
            <a href="<?= $project ? 'project_detail.php?id=' . $project['id'] : 'projects.php' ?>" class="btn btn-secondary">
                <i class="fas fa-arrow-left"></i>
                <?= $project ? 'Back to Project' : 'Back to Projects' ?>
            </a>
        </div>
    </div>

    <div class="form-container">
        <div class="card">
            <div class="card-body">
                <?php if (!empty($errors)): ?>
                    <div class="alert alert-danger">
                        <?php foreach ($errors as $error): ?>
                            <div><?= e($error) ?></div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>

                <form method="POST" data-validate>
                    <div class="form-row">
                        <div class="form-group">
                            <label for="name">
                                <i class="fas fa-project-diagram"></i>
                                Project Name *
                            </label>
                            <input type="text" id="name" name="name" 
                                   value="<?= e($project['name'] ?? $_POST['name'] ?? '') ?>" 
                                   required maxlength="255">
                        </div>
                        
                        <div class="form-group">
                            <label for="status">
                                <i class="fas fa-flag"></i>
                                Status
                            </label>
                            <select id="status" name="status" required>
                                <option value="not_started" <?= ($project['status'] ?? $_POST['status'] ?? 'not_started') == 'not_started' ? 'selected' : '' ?>>
                                    Not Started
                                </option>
                                <option value="in_progress" <?= ($project['status'] ?? $_POST['status'] ?? '') == 'in_progress' ? 'selected' : '' ?>>
                                    In Progress
                                </option>
                                <option value="completed" <?= ($project['status'] ?? $_POST['status'] ?? '') == 'completed' ? 'selected' : '' ?>>
                                    Completed
                                </option>
                                <option value="on_hold" <?= ($project['status'] ?? $_POST['status'] ?? '') == 'on_hold' ? 'selected' : '' ?>>
                                    On Hold
                                </option>
                            </select>
                        </div>
                    </div>

                    <div class="form-group">
                        <label for="description">
                            <i class="fas fa-align-left"></i>
                            Project Description
                        </label>
                        <textarea id="description" name="description" rows="4"
                                      placeholder="Describe the project goals, scope, and requirements..."><?= e($project['description'] ?? $_POST['description'] ?? '') ?></textarea>
                    </div>

                    <div class="form-group">
                        <label for="scope">
                            <i class="fas fa-list-check"></i>
                            Project Scope (Requirements)
                        </label>
                        <textarea id="scope" name="scope" rows="5"
                                  placeholder="One requirement per line. e.g.&#10;Design landing page&#10;Build checkout flow&#10;Deploy to production"><?= e($project['scope'] ?? $_POST['scope'] ?? '') ?></textarea>
                        <small>Each line is shown as a separate item on the client dashboard.</small>
                    </div>

                    <div class="form-row">
                        <div class="form-group">
                            <label for="budget_amount">
                                <i class="fas fa-coins"></i>
                                Project Budget
                            </label>
                            <input type="number" step="0.01" min="0" id="budget_amount" name="budget_amount"
                                   value="<?= e($project['budget_amount'] ?? $_POST['budget_amount'] ?? '') ?>"
                                   placeholder="0.00">
                            <small>Leave blank for no budget.</small>
                        </div>
                        <div class="form-group">
                            <label for="currency">
                                <i class="fas fa-money-bill"></i>
                                Currency
                            </label>
                            <select id="currency" name="currency">
                                <?php $sel = $project['currency'] ?? $_POST['currency'] ?? ''; ?>
                                <option value="" <?= $sel === '' ? 'selected' : '' ?>>— No budget —</option>
                                <?php foreach (allowedCurrencies() as $code): ?>
                                    <option value="<?= $code ?>" <?= strtoupper((string)$sel) === $code ? 'selected' : '' ?>>
                                        <?= $code ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>

                    <div class="form-row">
                        <div class="form-group">
                            <label for="expected_completion_date">
                                <i class="fas fa-calendar"></i>
                                Expected Completion Date
                            </label>
                            <input type="date" id="expected_completion_date" name="expected_completion_date"
                                   value="<?= e($project['expected_completion_date'] ?? $_POST['expected_completion_date'] ?? '') ?>">
                        </div>
                        
                        <div class="form-group">
                            <label for="completion_date">
                                <i class="fas fa-calendar-check"></i>
                                Actual Completion Date
                            </label>
                            <input type="date" id="completion_date" name="completion_date"
                                   value="<?= e($project['completion_date'] ?? $_POST['completion_date'] ?? '') ?>">
                        </div>
                    </div>

                    <div class="form-actions">
                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-save"></i>
                            <?= $project ? 'Update Project' : 'Create Project' ?>
                        </button>
                        
                        <a href="<?= $project ? 'project_detail.php?id=' . $project['id'] : 'projects.php' ?>" class="btn btn-secondary">
                            <i class="fas fa-times"></i>
                            Cancel
                        </a>
                        
                        <?php if ($project): ?>
                            <button type="button" class="btn btn-danger" onclick="openDeleteProjectDialog()" style="margin-left: auto;">
                                <i class="fas fa-trash"></i>
                                Delete Project
                            </button>
                        <?php endif; ?>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<?php if ($project): ?>
<dialog id="delete-project-dialog" class="delete-project-dialog" aria-labelledby="delete-project-title" aria-describedby="delete-project-warning">
    <form method="POST" action="project_delete.php" class="delete-project-form">
        <h2 id="delete-project-title">Delete project?</h2>
        <p id="delete-project-warning">
            This permanently deletes this project and may also delete its associated tasks and contacts.
            This action cannot be undone.
        </p>
        <p>
            To confirm, type Project Code
            <?php if (!empty($project['share_code'])): ?>
                <strong><?= e($project['share_code']) ?></strong>.
            <?php else: ?>
                <strong>unavailable</strong>. This project has no Project Code, so it cannot be deleted from here.
            <?php endif; ?>
        </p>
        <?php if (!empty($project['share_code'])): ?>
        <label for="delete-project-confirmation">Project Code</label>
        <input
            type="text"
            id="delete-project-confirmation"
            name="confirmation_code"
            autocapitalize="characters"
            autocomplete="off"
            required
            aria-describedby="delete-project-warning"
            data-project-code="<?= e($project['share_code']) ?>">
        <?php endif; ?>
        <input type="hidden" name="project_id" value="<?= e($project['id']) ?>">
        <input type="hidden" name="csrf_token" value="<?= e($csrf_token) ?>">
        <div class="delete-project-actions">
            <button type="button" class="btn btn-secondary" onclick="closeDeleteProjectDialog()">Cancel</button>
            <button type="submit" class="btn btn-danger" id="confirm-delete-project" disabled <?= empty($project['share_code']) ? 'title="This project has no Project Code."' : '' ?>>Delete Project</button>
        </div>
    </form>
</dialog>
<?php endif; ?>

<style>
.page-container {
    max-width: 800px;
    margin: 0 auto;
    padding: 20px;
}

.form-container {
    margin-top: 0;
}

.form-actions {
    display: flex;
    gap: 15px;
    align-items: center;
    margin-top: 30px;
    padding-top: 20px;
    border-top: 1px solid #dee2e6;
}

.form-actions .btn {
    min-width: 140px;
}

.form-group label i {
    margin-right: 8px;
    color: #666;
    width: 16px;
}

.delete-project-dialog {
    width: min(32rem, calc(100% - 2rem));
    max-width: none;
    max-height: calc(100% - 2rem);
    margin: auto;
    padding: 1.5rem;
    border: 0;
    border-radius: 8px;
    box-shadow: 0 12px 40px rgba(0, 0, 0, 0.25);
}

.delete-project-dialog::backdrop {
    background: rgba(0, 0, 0, 0.6);
}

.delete-project-form h2 {
    margin-bottom: 1rem;
}

.delete-project-form p,
.delete-project-form label {
    display: block;
    margin-bottom: 0.75rem;
}

.delete-project-form input[type="text"] {
    width: 100%;
    min-height: 44px;
    margin-bottom: 1.25rem;
    padding: 0.5rem;
}

.delete-project-actions {
    display: flex;
    justify-content: flex-end;
    gap: 0.75rem;
}

.delete-project-actions .btn {
    min-height: 44px;
}

@media (max-width: 768px) {
    .form-actions {
        flex-direction: column;
        align-items: stretch;
    }
    
    .form-actions .btn {
        width: 100%;
        margin-left: 0 !important;
    }

    .delete-project-actions {
        flex-direction: column-reverse;
    }

    .delete-project-actions .btn {
        width: 100%;
    }
}
</style>

<script>
document.getElementById('status').addEventListener('change', function() {
    const completionDateField = document.getElementById('completion_date');

    if (this.value === 'completed' && !completionDateField.value) {
        const today = new Date();
        const year = today.getFullYear();
        const month = String(today.getMonth() + 1).padStart(2, '0');
        const day = String(today.getDate()).padStart(2, '0');
        completionDateField.value = `${year}-${month}-${day}`;
    } else if (this.value !== 'completed') {
        completionDateField.value = '';
    }
});
</script>

<?php if ($project): ?>
<script>
const deleteProjectDialog = document.getElementById('delete-project-dialog');
const deleteProjectInput = document.getElementById('delete-project-confirmation');
const deleteProjectSubmit = document.getElementById('confirm-delete-project');

function openDeleteProjectDialog() {
    deleteProjectDialog.showModal();
    if (deleteProjectInput) {
        deleteProjectInput.focus();
    } else {
        deleteProjectSubmit.focus();
    }
}

function closeDeleteProjectDialog() {
    deleteProjectDialog.close();
}

if (deleteProjectInput) {
    deleteProjectInput.addEventListener('input', function() {
        deleteProjectSubmit.disabled = this.value === ''
            || this.value !== this.dataset.projectCode;
    });
}

deleteProjectDialog.addEventListener('close', function() {
    if (deleteProjectInput) {
        deleteProjectInput.value = '';
    }
    deleteProjectSubmit.disabled = true;
});
</script>
<?php endif; ?>

<?php include 'includes/footer.php'; ?>