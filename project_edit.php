<?php
require_once 'config.php';
require_once 'includes/Database.php';
require_once 'includes/functions.php';

requireLogin();

$db = Database::getInstance();
$user_id = getCurrentUserId();
$project_id = $_GET['id'] ?? null;
$errors = [];

// Issue-023: "Rotate code" action. The button POSTs
// `action=rotate_share_code` and the same CSRF token. The
// rotation invalidates the previous share code and any URL
// that uses it; the manager can re-send the new URL to the
// client. We MUST validate CSRF first so a forged cross-site
// POST cannot rotate a code silently.
if (($_POST['action'] ?? '') === 'rotate_share_code') {
    if (!validateCsrfToken($_POST['csrf_token'] ?? null)) {
        $errors[] = 'Invalid form token. Please reload the page and submit again.';
    } else {
        $rotateId = (int)($_POST['project_id'] ?? 0);
        if ($rotateId <= 0) {
            $errors[] = 'Missing project id for share-code rotation.';
        } else {
            // Ownership check: the project must belong to the current
            // user. Defence in depth — a forged POST can only target
            // the user's own projects because the WHERE clause
            // matches responsible_person_id.
            $owned = $db->fetchOne(
                "SELECT id, name, share_code FROM projects
                 WHERE id = ? AND responsible_person_id = ?",
                [$rotateId, $user_id]
            );
            if (!$owned) {
                $errors[] = 'Project not found, or you do not have permission to rotate its share code.';
            } else {
                try {
                    // Generate a new code; retry once on the
                    // astronomically-unlikely collision.
                    $newShareCode = generateShareCode();
                    try {
                        $db->execute(
                            "UPDATE projects
                             SET share_code = ?, share_code_rotated_at = NOW()
                             WHERE id = ? AND responsible_person_id = ?",
                            [$newShareCode, $rotateId, $user_id]
                        );
                    } catch (Exception $e) {
                        $newShareCode = generateShareCode();
                        $db->execute(
                            "UPDATE projects
                             SET share_code = ?, share_code_rotated_at = NOW()
                             WHERE id = ? AND responsible_person_id = ?",
                            [$newShareCode, $rotateId, $user_id]
                        );
                    }
                    redirect(
                        '/projects/' . $rotateId . '/edit',
                        'Share code rotated. The previous URL is now invalid.',
                        'success'
                    );
                } catch (Exception $e) {
                    $errors[] = 'Failed to rotate share code. Please try again.';
                }
            }
        }
    }
    // If we got here with errors, fall through and re-render the
    // page below (the project will be re-loaded from the DB so the
    // stale share code is still visible).
}

// Handle form submission for project creation/update
if ($_POST && ($_POST['action'] ?? '') !== 'rotate_share_code') {
    if (!validateCsrfToken($_POST['csrf_token'] ?? null)) {
        $errors[] = 'Invalid form token. Please reload the page and submit again.';
        // Re-render with errors. We deliberately do NOT process the rest of the
        // payload once CSRF has failed, to keep the error UX clean and avoid
        // acting on untrusted input.
    } else {
    $name = sanitize($_POST['name'] ?? '');
    $description = sanitize($_POST['description'] ?? '');
    $scope = sanitize($_POST['scope'] ?? '');
    $budget_amount_raw = trim($_POST['budget_amount'] ?? '');
    $currency = strtoupper(trim($_POST['currency'] ?? ''));
    $expected_completion_date = $_POST['expected_completion_date'] ?? '';
    $start_date = $_POST['start_date'] ?? '';
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
    $start_date = $parseProjectDate($start_date, 'Project start date');
    $completion_date = $parseProjectDate($completion_date, 'Actual completion date');

    // Non-blocking warnings: a start date later than expected/actual completion
    // is suspicious but legal (historical imports, back-dated planning).
    if ($start_date !== null && $expected_completion_date !== null && $start_date > $expected_completion_date) {
        $errors[] = "Project start date is later than the expected completion date. Please double-check the dates.";
    }
    if ($start_date !== null && $completion_date !== null && $start_date > $completion_date) {
        $errors[] = "Project start date is later than the actual completion date. Please double-check the dates.";
    }

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
                     currency = ?, expected_completion_date = ?, start_date = ?,
                     completion_date = ?, status = ?, updated_at = NOW()
                     WHERE id = ? AND responsible_person_id = ?",
                    [$name, $description, $scope, $budget_amount, $currency,
                     $expected_completion_date, $start_date, $completion_date, $status,
                     $project_id, $user_id]
                );
                redirect('/projects/' . $project_id, 'Project updated successfully!', 'success');
            } else {
                // Create new project — auto-generate share_code (with one retry on
                // the astronomically-unlikely collision).
                $share_code = generateShareCode();
                // v2.0 (issue-035 C.2): assign the slug once, at creation. The
                // UPDATE branch above deliberately does not touch it.
                //
                // issue-040: the slug is no longer the public URL - the id is.
                // It is still written, because the column is NOT NULL and
                // because legacy_redirects resolves an old slug URL to its id
                // through it. New records keep a slug so their pre-issue-040
                // URL shape would have worked, but nothing links to it.
                $slug = generateUniqueSlug($name, 'projects');
                try {
                    $db->execute(
                        "INSERT INTO projects (name, slug, description, scope, budget_amount, currency,
                         share_code, responsible_person_id, expected_completion_date,
                         start_date, completion_date, status, created_at)
                         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())",
                        [$name, $slug, $description, $scope, $budget_amount, $currency,
                         $share_code, $user_id, $expected_completion_date, $start_date,
                         $completion_date, $status]
                    );
                } catch (Exception $e) {
                    $share_code = generateShareCode();
                    $db->execute(
                        "INSERT INTO projects (name, slug, description, scope, budget_amount, currency,
                         share_code, responsible_person_id, expected_completion_date,
                         start_date, completion_date, status, created_at)
                         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())",
                        [$name, $slug, $description, $scope, $budget_amount, $currency,
                         $share_code, $user_id, $expected_completion_date, $start_date,
                         $completion_date, $status]
                    );
                }
                $new_project_id = $db->lastInsertId();
                // Redirect to the canonical id URL. lastInsertId() is the
                // authoritative id, so there is nothing to look up.
                redirect('/projects/' . $new_project_id, 'Project created successfully!', 'success');
            }
        } catch (Exception $e) {
            $errors[] = "Failed to save project. Please try again.";
        }
    }
    } // end else (!validateCsrfToken failed)
}

// Load existing project data
$project = null;
if ($project_id) {
    $project = $db->fetchOne(
        "SELECT * FROM projects WHERE id = ? AND responsible_person_id = ?",
        [$project_id, $user_id]
    );
    
    if (!$project) {
        redirect('/projects', 'Project not found.', 'danger');
    }
}

// Counts for the Danger zone copy on the edit page. These are only used to
// tell the manager what a deletion will remove.
$project_task_count = [];
$project_contact_count = [];
if ($project) {
    $project_task_count = $db->fetchAll(
        "SELECT id FROM tasks WHERE project_id = ?",
        [$project['id']]
    );
    $project_contact_count = $db->fetchAll(
        "SELECT DISTINCT contact_id FROM project_contacts WHERE project_id = ?",
        [$project['id']]
    );
}

$title = $project ? "Edit Project" : "New Project";
$show_nav = true;
// CSRF token is required for both create and edit, and is also reused
// by the inline delete dialog further down the page.
$csrf_token = getCsrfToken();
// v2.0: this page was missing $use_v2_shell, so header.php skipped the
// .app-shell/.sidebar wrapper and the page rendered unstyled and
// full-bleed. Every other v2 page sets this. See header.php line ~94.
$use_v2_shell = true;
?>

<?php include 'includes/header.php'; ?>
<link rel="stylesheet" href="/assets/css/pages/forms.css">
<link rel="stylesheet" href="/assets/css/pages/project_edit.css">

<div class="content">
    <nav class="breadcrumb" aria-label="Breadcrumb">
        <a href="/dashboard">Dashboard</a>
        <span aria-hidden="true">/</span>
        <a href="/projects">Projects</a>
        <span aria-hidden="true">/</span>
        <span><?= $project ? 'Edit Project' : 'New Project' ?></span>
    </nav>

    <div class="page-header">
        <div class="page-header__text">
            <h1>
                <i class="fas fa-pen-to-square"></i>
                <?= $project ? 'Edit Project' : 'New Project' ?>
            </h1>
            <?php if ($project): ?>
                <p class="page-header__sub">
                    <?= e($project['name']) ?>
                    <?php if (!empty($project['share_code'])): ?>
                        &middot; Code <?= e($project['share_code']) ?>
                    <?php endif; ?>
                </p>
            <?php else: ?>
                <p class="page-header__sub">Create a new project to get started.</p>
            <?php endif; ?>
        </div>
        <div class="page-actions">
            <a class="btn btn--secondary"
               href="<?= $project ? '/projects/' . (int)$project['id'] : '/projects' ?>">
                Cancel
            </a>
        </div>
    </div>

    <?php if (!empty($errors)): ?>
        <div class="alert alert--danger">
            <i class="fas fa-circle-exclamation"></i>
            <div class="alert__body">
                <div class="alert__title">Please fix the following before saving</div>
                <ul style="margin:.35rem 0 0; padding-left:1.1rem;">
                    <?php foreach ($errors as $error): ?>
                        <li><?= e($error) ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
        </div>
    <?php endif; ?>

    <div class="grid-sidebar">
        <form method="POST" class="card" data-validate>
            <input type="hidden" name="csrf_token" value="<?= e($csrf_token) ?>">
            <div class="card__header">
                <h2 class="card__title-icon"><i class="fas fa-circle-info"></i> Project information</h2>
            </div>
            <div class="card__body">
                <div class="form-grid form-grid--2">
                    <div class="field form-section">
                        <label for="name">Project Name <span class="req" aria-hidden="true">*</span></label>
                        <input class="input" type="text" id="name" name="name"
                               value="<?= e($project['name'] ?? $_POST['name'] ?? '') ?>"
                               required maxlength="255">
                    </div>

                    <div class="field">
                        <label for="status">Status <span class="req" aria-hidden="true">*</span></label>
                        <select class="select" id="status" name="status" required>
                            <?php
                            $sel_status = $project['status'] ?? $_POST['status'] ?? 'not_started';
                            $status_options = [
                                'not_started' => 'Not Started',
                                'in_progress' => 'In Progress',
                                'completed'   => 'Completed',
                                'on_hold'     => 'On Hold',
                            ];
                            foreach ($status_options as $value => $label): ?>
                                <option value="<?= $value ?>" <?= $sel_status === $value ? 'selected' : '' ?>>
                                    <?= $label ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="field form-section">
                        <label for="description">Description</label>
                        <textarea class="textarea" id="description" name="description" rows="4"
                                  placeholder="Describe the project goals, scope, and requirements..."><?= e($project['description'] ?? $_POST['description'] ?? '') ?></textarea>
                    </div>

                    <div class="field">
                        <label for="scope">Scope of Work</label>
                        <textarea class="textarea" id="scope" name="scope" rows="5"
                                  placeholder="One requirement per line."><?= e($project['scope'] ?? $_POST['scope'] ?? '') ?></textarea>
                        <span class="hint">Each line is shown as a separate item on the client dashboard.</span>
                    </div>

                    <div class="field">
                        <label for="budget_amount">Budget Amount</label>
                        <input class="input" type="number" step="0.01" min="0" id="budget_amount" name="budget_amount"
                               value="<?= e($project['budget_amount'] ?? $_POST['budget_amount'] ?? '') ?>"
                               placeholder="0.00">
                        <span class="hint">Leave empty for no budget.</span>
                    </div>

                    <div class="field">
                        <label for="currency">Currency</label>
                        <select class="select" id="currency" name="currency">
                            <?php $sel = $project['currency'] ?? $_POST['currency'] ?? ''; ?>
                            <option value="" <?= $sel === '' ? 'selected' : '' ?>>&mdash; No budget &mdash;</option>
                            <?php foreach (allowedCurrencies() as $code): ?>
                                <option value="<?= $code ?>" <?= strtoupper((string)$sel) === $code ? 'selected' : '' ?>>
                                    <?= $code ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="field">
                        <label for="start_date">Start Date</label>
                        <input class="input" type="date" id="start_date" name="start_date"
                               value="<?= e($project['start_date'] ?? $_POST['start_date'] ?? '') ?>">
                        <span class="hint">Optional. The day work on this project actually began.</span>
                    </div>

                    <div class="field">
                        <label for="expected_completion_date">Expected Completion Date</label>
                        <input class="input" type="date" id="expected_completion_date" name="expected_completion_date"
                               value="<?= e($project['expected_completion_date'] ?? $_POST['expected_completion_date'] ?? '') ?>">
                    </div>

                    <div class="field">
                        <label for="completion_date">Completion Date</label>
                        <input class="input" type="date" id="completion_date" name="completion_date"
                               value="<?= e($project['completion_date'] ?? $_POST['completion_date'] ?? '') ?>">
                        <span class="hint">Set when the project is actually finished.</span>
                    </div>
                </div>
            </div>
            <div class="card__footer">
                <button class="btn btn--primary" type="submit">
                    <i class="fas fa-floppy-disk"></i>
                    <?= $project ? 'Save Changes' : 'Create Project' ?>
                </button>
                <a class="btn btn--secondary"
                   href="<?= $project ? '/projects/' . (int)$project['id'] : '/projects' ?>">
                    Cancel
                </a>
            </div>
        </form>

        <div>
            <?php if ($project && !empty($project['share_code'])): ?>
                <!-- Issue-023: client share-code rotation nudge. The
                     "Rotate code" button is a separate POST that
                     invalidates the previous share code and sets
                     share_code_rotated_at = NOW(). -->
                <div class="card">
                    <div class="card__header">
                        <h2 class="card__title-icon"><i class="fas fa-share-nodes"></i> Client share</h2>
                    </div>
                    <div class="card__body">
                        <div class="share-box">
                            <code><?= e($project['share_code']) ?></code>
                            <button class="btn btn--sm btn--secondary" type="button"
                                    data-copy="<?= e($project['share_code']) ?>"
                                    aria-label="Copy share code">
                                <i class="far fa-copy"></i>
                            </button>
                        </div>
                        <?php if (!empty($project['share_code_rotated_at'])): ?>
                            <?php
                            $rotatedTs = strtotime((string)$project['share_code_rotated_at']);
                            $daysAgo = $rotatedTs ? (int)floor((time() - $rotatedTs) / 86400) : null;
                            ?>
                            <p class="text-xs text-muted pe-meta">
                                Last rotated: <?= e(formatDateTime($project['share_code_rotated_at'])) ?>
                                <?php if ($daysAgo !== null): ?>
                                    (<?= $daysAgo ?> day<?= $daysAgo === 1 ? '' : 's' ?> ago)
                                <?php endif; ?>
                            </p>
                            <?php if ($daysAgo !== null && $daysAgo > 90): ?>
                                <div class="alert alert--warning">
                                    <i class="fas fa-triangle-exclamation"></i>
                                    <div class="alert__body">
                                        This share code has not been rotated in over 90 days.
                                        Consider rotating it to invalidate any previously-shared URLs.
                                    </div>
                                </div>
                            <?php endif; ?>
                        <?php endif; ?>
                        <form method="POST" action="/projects/<?= (int)$project['id'] ?>/edit" class="pe-rotate-form">
                            <input type="hidden" name="csrf_token" value="<?= e($csrf_token) ?>">
                            <input type="hidden" name="action" value="rotate_share_code">
                            <input type="hidden" name="project_id" value="<?= (int)$project['id'] ?>">
                            <button class="btn btn--secondary btn--sm btn--block" type="submit"
                                    onclick="return confirm('Rotating the share code will invalidate the previous URL. Any client who has bookmarked the old link will see a 404. Continue?');">
                                <i class="fas fa-rotate"></i> Rotate share code
                            </button>
                        </form>
                        <span class="hint">Generates a new share code and invalidates the previous URL.</span>
                    </div>
                </div>
            <?php endif; ?>

            <?php if ($project): ?>
                <div class="card danger-zone">
                    <div class="card__header">
                        <h2 class="card__title-icon"><i class="fas fa-triangle-exclamation"></i> Danger zone</h2>
                    </div>
                    <div class="card__body">
                        <p class="text-sm text-muted">
                            Deleting removes the project, all <?= count($project_task_count) ?>
                            task(s), their subtasks and every project assignment for
                            <?= count($project_contact_count) ?> contact(s). This cannot be undone.
                        </p>
                        <?php if (!empty($project['share_code'])): ?>
                            <div class="field pe-danger-field">
                                <label for="delete-project-confirmation">
                                    Type the project code to confirm <span class="req" aria-hidden="true">*</span>
                                </label>
                                <input class="input text-mono" type="text" id="delete-project-confirmation"
                                       name="confirmation_code"
                                       value="<?= e($_POST['confirmation_code'] ?? '') ?>"
                                       placeholder="<?= e($project['share_code']) ?>"
                                       autocomplete="off">
                                <span class="hint">Must exactly match <strong><?= e($project['share_code']) ?></strong>.</span>
                            </div>
                        <?php else: ?>
                            <div class="field pe-danger-field">
                                <p class="text-sm text-muted">
                                    This project has no Project Code, so it cannot be deleted from here.
                                </p>
                            </div>
                        <?php endif; ?>
                        <button class="btn btn--danger btn--block" type="button"
                                style="margin-top:1rem;"
                                <?= empty($project['share_code']) ? 'disabled title="This project has no Project Code."' : '' ?>
                                data-modal-open="confirm-delete-project">
                            <i class="fas fa-trash"></i> Delete Project
                        </button>
                    </div>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <?php if ($project && !empty($project['share_code'])): ?>
        <div class="modal-backdrop" id="confirm-delete-project" hidden>
            <div class="modal" role="dialog" aria-modal="true" aria-labelledby="cdp-title">
                <div class="modal__header">
                    <div>
                        <h3 id="cdp-title">Confirm project deletion</h3>
                        <p>Type the project code exactly as shown to confirm.</p>
                    </div>
                    <button class="modal__close" type="button" data-modal-close aria-label="Close">
                        <i class="fas fa-xmark"></i>
                    </button>
                </div>
                <div class="modal__body">
                    <div class="alert alert--danger">
                        <i class="fas fa-triangle-exclamation"></i>
                        <div class="alert__body">
                            <div class="alert__title"><?= e($project['share_code']) ?></div>
                            All <?= count($project_task_count) ?> task(s), their subtasks and
                            <?= count($project_contact_count) ?> contact assignment(s) will be
                            permanently removed.
                        </div>
                    </div>
                    <div class="field">
                        <label for="delete-project-confirmation-modal">
                            Project Code <span class="req" aria-hidden="true">*</span>
                        </label>
                        <input class="input text-mono" type="text" id="delete-project-confirmation-modal"
                               name="confirmation_code"
                               value="<?= e($_POST['confirmation_code'] ?? '') ?>"
                               placeholder="<?= e($project['share_code']) ?>"
                               autocomplete="off">
                    </div>
                </div>
                <div class="modal__footer">
                    <button class="btn btn--secondary" type="button" data-modal-close>Cancel</button>
                    <form method="POST" action="/projects/<?= (int)$project_id ?>/delete" class="inline-form">
                        <input type="hidden" name="project_id" value="<?= e($project['id']) ?>">
                        <input type="hidden" name="csrf_token" value="<?= e($csrf_token) ?>">
                        <button class="btn btn--danger" type="submit">
                            <i class="fas fa-trash"></i> Delete permanently
                        </button>
                    </form>
                </div>
            </div>
        </div>
    <?php endif; ?>
</div>
<?php include 'includes/footer.php'; ?>