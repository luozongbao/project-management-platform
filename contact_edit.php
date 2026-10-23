<?php
require_once 'config.php';
require_once 'includes/Database.php';
require_once 'includes/functions.php';

requireLogin();

$db = Database::getInstance();
$user_id = getCurrentUserId();
$contact_id = $_GET['id'] ?? null;
$project_id = $_GET['project_id'] ?? null;

$is_editing = $contact_id !== null;

// If editing, get the existing contact
$contact = null;
if ($is_editing) {
    $contact = $db->fetchOne(
        "SELECT DISTINCT c.*
         FROM contacts c
         JOIN project_contacts pc ON c.id = pc.contact_id
         JOIN projects p ON pc.project_id = p.id
         WHERE c.id = ? AND p.responsible_person_id = ?",
        [$contact_id, $user_id]
    );
    
    if (!$contact) {
        redirect('/contacts');
    }
}

// Get user's projects for assignment
$user_projects = $db->fetchAll(
    "SELECT id, name FROM projects WHERE responsible_person_id = ? ORDER BY name",
    [$user_id]
);

// Get current project assignments if editing
$assigned_projects = [];
if ($is_editing) {
    $assigned_projects = $db->fetchAll(
        "SELECT project_id FROM project_contacts WHERE contact_id = ?",
        [$contact_id]
    );
    $assigned_projects = array_column($assigned_projects, 'project_id');
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
    $email = trim($_POST['email'] ?? '');
    $phone = trim($_POST['phone'] ?? '');
    $mobile = trim($_POST['mobile'] ?? '');
    $company = trim($_POST['company'] ?? '');
    $position = trim($_POST['position'] ?? '');
    $address = trim($_POST['address'] ?? '');
    $wechat = trim($_POST['wechat'] ?? '');
    $line_id = trim($_POST['line_id'] ?? '');
    $facebook = trim($_POST['facebook'] ?? '');
    $linkedin = trim($_POST['linkedin'] ?? '');
    $selected_projects = $_POST['projects'] ?? [];
    
    $errors = [];
    
    // Validation
    if (empty($name)) {
        $errors[] = "Contact name is required.";
    }
    
    if ($email && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors[] = "Please enter a valid email address.";
    }
    
    // Ensure at least one project is selected
    if (empty($selected_projects)) {
        $errors[] = "Please select at least one project for this contact.";
    }
    
    // Convert empty strings to null for database
    $email = $email ?: null;
    $phone = $phone ?: null;
    $mobile = $mobile ?: null;
    $company = $company ?: null;
    $position = $position ?: null;
    $address = $address ?: null;
    $wechat = $wechat ?: null;
    $line_id = $line_id ?: null;
    $facebook = $facebook ?: null;
    $linkedin = $linkedin ?: null;
    
    if (empty($errors)) {
        try {
            if ($is_editing) {
                // Update existing contact
                $db->execute(
                    "UPDATE contacts SET 
                        name = ?, description = ?, email = ?, phone = ?, mobile = ?,
                        company = ?, position = ?, address = ?, wechat = ?, line_id = ?,
                        facebook = ?, linkedin = ?, updated_at = NOW()
                     WHERE id = ?",
                    [
                        $name, $description, $email, $phone, $mobile,
                        $company, $position, $address, $wechat, $line_id,
                        $facebook, $linkedin, $contact_id
                    ]
                );
                
                // Update project assignments
                $db->execute("DELETE FROM project_contacts WHERE contact_id = ?", [$contact_id]);
                foreach ($selected_projects as $pid) {
                    $db->execute(
                        "INSERT INTO project_contacts (contact_id, project_id, created_at) VALUES (?, ?, NOW())",
                        [$contact_id, $pid]
                    );
                }
                
                // Redirect based on context
                if ($project_id) {
                    redirect('/projects/' . $project_id, 'Contact updated successfully!', 'success');
                } else {
                    redirect('/contacts/' . $contact_id, 'Contact updated successfully!', 'success');
                }
            } else {
                // Create new contact
                $db->execute(
                    "INSERT INTO contacts (
                        name, slug, description, email, phone, mobile, company, position, address,
                        wechat, line_id, facebook, linkedin, created_at, updated_at
                     ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())",
                    [
                        $name, generateUniqueSlug($name, 'contacts'),
                        $description, $email, $phone, $mobile, $company, $position, $address,
                        $wechat, $line_id, $facebook, $linkedin
                    ]
                );
                
                $new_contact_id = $db->lastInsertId();
                
                // Assign to projects
                foreach ($selected_projects as $pid) {
                    $db->execute(
                        "INSERT INTO project_contacts (contact_id, project_id, created_at) VALUES (?, ?, NOW())",
                        [$new_contact_id, $pid]
                    );
                }
                
                // Redirect based on context
                if ($project_id) {
                    redirect('/projects/' . $project_id, 'Contact created successfully!', 'success');
                } else {
                    redirect('/contacts/' . $new_contact_id, 'Contact created successfully!', 'success');
                }
            }
        } catch (Exception $e) {
            // Issue-016: log the underlying PDO message server-side but
            // do NOT echo it to the user (info disclosure).
            error_log("Contact save failed: " . $e->getMessage());
            $errors[] = "Failed to save the contact. Please try again. "
                      . "If the problem persists, contact your administrator.";
        }
    }
    } // end CSRF-validated POST branch
}

$title = $is_editing ? "Edit Contact" : "New Contact";
$show_nav = true;
// v2.0: this page was missing $use_v2_shell, so header.php skipped the
// .app-shell/.sidebar wrapper and the page rendered unstyled and
// full-bleed. Every other v2 page sets this. See header.php line ~94.
$use_v2_shell = true;
?>

<?php include 'includes/header.php'; ?>
<link rel="stylesheet" href="/assets/css/pages/forms.css">

<div class="content">
    <nav class="breadcrumb" aria-label="Breadcrumb">
        <a href="/dashboard">Dashboard</a>
        <span aria-hidden="true">/</span>
        <?php if ($project_id): ?>
            <?php
            $project_name = $db->fetchOne("SELECT name FROM projects WHERE id = ?", [$project_id])['name'];
            ?>
            <a href="/projects">Projects</a>
            <span aria-hidden="true">/</span>
            <a href="/projects/<?= (int)$project_id ?>"><?= e($project_name) ?></a>
            <span aria-hidden="true">/</span>
            <a href="/contacts?project_id=<?= (int)$project_id ?>">Contacts</a>
        <?php else: ?>
            <a href="/contacts">Contacts</a>
        <?php endif; ?>
        <span aria-hidden="true">/</span>
        <span><?= $is_editing ? 'Edit Contact' : 'New Contact' ?></span>
    </nav>

    <div class="page-header">
        <div class="page-header__text">
            <h1>
                <i class="fas fa-address-card"></i>
                <?= $is_editing ? 'Edit Contact' : 'New Contact' ?>
            </h1>
            <?php if ($is_editing): ?>
                <p class="page-header__sub"><?= e($contact['name']) ?></p>
            <?php endif; ?>
        </div>
        <div class="page-actions">
            <a class="btn btn--secondary"
               href="<?= $is_editing ? '/contacts/' . (int)$contact['id'] : '/contacts' ?>">
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

    <form method="POST" class="card">
        <input type="hidden" name="csrf_token" value="<?= e(getCsrfToken()) ?>">
        <div class="card__header">
            <h2 class="card__title-icon"><i class="fas fa-circle-info"></i> Contact details</h2>
        </div>
        <div class="card__body">
            <div class="form-grid form-grid--3">
                <div class="field form-section">
                    <label for="name">Contact Name <span class="req" aria-hidden="true">*</span></label>
                    <input class="input" type="text" id="name" name="name"
                           value="<?= e($_POST['name'] ?? $contact['name'] ?? '') ?>"
                           required maxlength="255">
                </div>

                <div class="field">
                    <label for="position">Position / Title</label>
                    <input class="input" type="text" id="position" name="position"
                           value="<?= e($_POST['position'] ?? $contact['position'] ?? '') ?>"
                           maxlength="255" placeholder="e.g. Project Manager">
                </div>

                <div class="field">
                    <label for="company">Company</label>
                    <input class="input" type="text" id="company" name="company"
                           value="<?= e($_POST['company'] ?? $contact['company'] ?? '') ?>"
                           maxlength="255" placeholder="Company name">
                </div>

                <div class="field form-section">
                    <label for="description">Description / Notes</label>
                    <textarea class="textarea" id="description" name="description" rows="4"
                              placeholder="Additional notes about this contact..."><?= e($_POST['description'] ?? $contact['description'] ?? '') ?></textarea>
                </div>

                <div class="field">
                    <label for="email">Email Address</label>
                    <input class="input" type="email" id="email" name="email"
                           value="<?= e($_POST['email'] ?? $contact['email'] ?? '') ?>"
                           maxlength="255" placeholder="contact@example.com">
                </div>

                <div class="field">
                    <label for="phone">Phone Number</label>
                    <input class="input" type="tel" id="phone" name="phone"
                           value="<?= e($_POST['phone'] ?? $contact['phone'] ?? '') ?>"
                           maxlength="20" placeholder="+1 (555) 123-4567">
                </div>

                <div class="field">
                    <label for="mobile">Mobile Number</label>
                    <input class="input" type="tel" id="mobile" name="mobile"
                           value="<?= e($_POST['mobile'] ?? $contact['mobile'] ?? '') ?>"
                           maxlength="20" placeholder="+1 (555) 987-6543">
                </div>

                <div class="field form-section">
                    <label for="address">Address</label>
                    <textarea class="textarea" id="address" name="address" rows="3"
                              placeholder="Street, city, state, postal code"><?= e($_POST['address'] ?? $contact['address'] ?? '') ?></textarea>
                </div>

                <div class="field">
                    <label for="wechat">WeChat ID</label>
                    <input class="input" type="text" id="wechat" name="wechat"
                           value="<?= e($_POST['wechat'] ?? $contact['wechat'] ?? '') ?>"
                           maxlength="100" placeholder="WeChat username">
                </div>

                <div class="field">
                    <label for="line_id">LINE ID</label>
                    <input class="input" type="text" id="line_id" name="line_id"
                           value="<?= e($_POST['line_id'] ?? $contact['line_id'] ?? '') ?>"
                           maxlength="100" placeholder="LINE username">
                </div>

                <div class="field">
                    <label for="facebook">Facebook Profile</label>
                    <input class="input" type="url" id="facebook" name="facebook"
                           value="<?= e($_POST['facebook'] ?? $contact['facebook'] ?? '') ?>"
                           maxlength="255" placeholder="https://facebook.com/username">
                </div>

                <div class="field">
                    <label for="linkedin">LinkedIn Profile</label>
                    <input class="input" type="url" id="linkedin" name="linkedin"
                           value="<?= e($_POST['linkedin'] ?? $contact['linkedin'] ?? '') ?>"
                           maxlength="255" placeholder="https://linkedin.com/in/username">
                </div>

                <div class="field form-section">
                    <label>Assign to Projects <span class="req" aria-hidden="true">*</span></label>
                    <?php if (empty($user_projects)): ?>
                        <div class="no-projects-message">
                            <p>No projects available. <a href="/projects/new">Create a project first</a>.</p>
                        </div>
                    <?php else: ?>
                        <div class="checkbox-list checkbox-list--grid project-checkboxes">
                            <?php foreach ($user_projects as $project): ?>
                                <label class="checkbox">
                                    <input type="checkbox" name="projects[]" value="<?= (int)$project['id'] ?>"
                                        <?= in_array($project['id'], $_POST['projects'] ?? $assigned_projects ?? ($project_id ? [$project_id] : [])) ? 'checked' : '' ?>>
                                    <span><?= e($project['name']) ?></span>
                                </label>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                    <span class="hint">A contact must belong to at least one project.</span>
                </div>
            </div>
        </div>
        <div class="card__footer">
            <button class="btn btn--primary" type="submit">
                <i class="fas fa-floppy-disk"></i>
                <?= $is_editing ? 'Save Contact' : 'Create Contact' ?>
            </button>
            <a class="btn btn--secondary"
               href="<?= $is_editing ? '/contacts/' . (int)$contact['id'] : '/contacts' ?>">
                Cancel
            </a>
        </div>
    </form>

    <?php if ($is_editing): ?>
        <div class="card danger-zone">
            <div class="card__header">
                <h2 class="card__title-icon"><i class="fas fa-triangle-exclamation"></i> Danger zone</h2>
            </div>
            <div class="card__body">
                <p class="text-sm text-muted">
                    Deleting removes <?= e($contact['name']) ?> from the
                    <?= count($assigned_projects) ?> project(s) they are assigned to.
                    Tasks already assigned to them are kept. This cannot be undone.
                </p>
                <button class="btn btn--danger btn--block" type="button" style="margin-top:1rem;"
                        data-modal-open="confirm-delete-contact">
                    <i class="fas fa-trash"></i> Delete Contact
                </button>
            </div>
        </div>

        <div class="modal-backdrop" id="confirm-delete-contact" hidden>
            <div class="modal" role="dialog" aria-modal="true" aria-labelledby="cdc-title">
                <div class="modal__header">
                    <div>
                        <h3 id="cdc-title">Confirm contact deletion</h3>
                        <p>Type the contact name exactly as shown to confirm.</p>
                    </div>
                    <button class="modal__close" type="button" data-modal-close aria-label="Close">
                        <i class="fas fa-xmark"></i>
                    </button>
                </div>
                <div class="modal__body">
                    <div class="alert alert--danger">
                        <i class="fas fa-triangle-exclamation"></i>
                        <div class="alert__body">
                            <div class="alert__title"><?= e($contact['name']) ?></div>
                            This contact will be unassigned from <?= count($assigned_projects) ?>
                            project(s). Tasks already assigned to them are kept.
                        </div>
                    </div>
                    <div class="field">
                        <label for="delete-contact-confirmation">
                            Contact Name <span class="req" aria-hidden="true">*</span>
                        </label>
                        <input class="input text-mono" type="text" id="delete-contact-confirmation"
                               name="confirmation_name"
                               value="<?= e($_POST['confirmation_name'] ?? '') ?>"
                               placeholder="<?= e($contact['name']) ?>">
                        <span class="hint">Must exactly match <strong><?= e($contact['name']) ?></strong>.</span>
                    </div>
                </div>
                <div class="modal__footer">
                    <button class="btn btn--secondary" type="button" data-modal-close>Cancel</button>
                    <form method="POST" action="/contacts/<?= (int)$contact['id'] ?>/delete" class="inline-form">
                        <input type="hidden" name="contact_id" value="<?= (int)$contact['id'] ?>">
                        <input type="hidden" name="csrf_token" value="<?= e(getCsrfToken()) ?>">
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