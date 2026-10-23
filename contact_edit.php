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
?>

<?php include 'includes/header.php'; ?>

<div class="page-container">
    <div class="page-header">
        <div class="page-title">
            <div class="breadcrumb">
                <a href="/dashboard">Dashboard</a>
                <span>/</span>
                <?php if ($project_id): ?>
                    <?php 
                    $project_name = $db->fetchOne("SELECT name FROM projects WHERE id = ?", [$project_id])['name'];
                    ?>
                    <a href="/projects">Projects</a>
                    <span>/</span>
                    <a href="/projects/<?= (int)$project_id ?>"><?= e($project_name) ?></a>
                    <span>/</span>
                    <a href="/contacts?project_id=<?= $project_id ?>">Contacts</a>
                <?php else: ?>
                    <a href="/contacts">Contacts</a>
                <?php endif; ?>
                <?php if ($is_editing): ?>
                    <span>/</span>
                    <a href="/contacts/<?= (int)$contact['id'] ?>"><?= e($contact['name']) ?></a>
                    <span>/</span>
                    <span>Edit</span>
                <?php else: ?>
                    <span>/</span>
                    <span>New Contact</span>
                <?php endif; ?>
            </div>
            <h1>
                <i class="fas fa-<?= $is_editing ? 'edit' : 'plus' ?>"></i>
                <?= $title ?>
            </h1>
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
        <form method="POST" class="contact-form">
            <input type="hidden" name="csrf_token" value="<?= e(getCsrfToken()) ?>">
            <div class="form-grid">
                <!-- Basic Information -->
                <div class="form-section">
                    <h3>Basic Information</h3>
                    
                    <div class="form-group">
                        <label for="name" class="required">Contact Name:</label>
                        <input type="text" id="name" name="name" 
                               value="<?= e($_POST['name'] ?? $contact['name'] ?? '') ?>" 
                               required maxlength="255">
                    </div>
                    
                    <div class="form-group">
                        <label for="position">Position/Title:</label>
                        <input type="text" id="position" name="position" 
                               value="<?= e($_POST['position'] ?? $contact['position'] ?? '') ?>" 
                               maxlength="255" placeholder="e.g., Project Manager, CEO">
                    </div>
                    
                    <div class="form-group">
                        <label for="company">Company:</label>
                        <input type="text" id="company" name="company" 
                               value="<?= e($_POST['company'] ?? $contact['company'] ?? '') ?>" 
                               maxlength="255" placeholder="Company name">
                    </div>
                    
                    <div class="form-group">
                        <label for="description">Description/Notes:</label>
                        <textarea id="description" name="description" rows="4" 
                                  placeholder="Additional notes about this contact..."><?= e($_POST['description'] ?? $contact['description'] ?? '') ?></textarea>
                    </div>
                </div>

                <!-- Contact Information -->
                <div class="form-section">
                    <h3>Contact Information</h3>
                    
                    <div class="form-group">
                        <label for="email">Email Address:</label>
                        <input type="email" id="email" name="email" 
                               value="<?= e($_POST['email'] ?? $contact['email'] ?? '') ?>" 
                               maxlength="255" placeholder="contact@example.com">
                    </div>
                    
                    <div class="form-group">
                        <label for="phone">Phone Number:</label>
                        <input type="tel" id="phone" name="phone" 
                               value="<?= e($_POST['phone'] ?? $contact['phone'] ?? '') ?>" 
                               maxlength="20" placeholder="+1 (555) 123-4567">
                    </div>
                    
                    <div class="form-group">
                        <label for="mobile">Mobile Number:</label>
                        <input type="tel" id="mobile" name="mobile" 
                               value="<?= e($_POST['mobile'] ?? $contact['mobile'] ?? '') ?>" 
                               maxlength="20" placeholder="+1 (555) 987-6543">
                    </div>
                    
                    <div class="form-group">
                        <label for="address">Address:</label>
                        <textarea id="address" name="address" rows="3" 
                                  placeholder="Full address including street, city, state, zip..."><?= e($_POST['address'] ?? $contact['address'] ?? '') ?></textarea>
                    </div>
                </div>

                <!-- Social Media -->
                <div class="form-section">
                    <h3>Social Media & Messaging</h3>
                    
                    <div class="form-group">
                        <label for="wechat">WeChat ID:</label>
                        <input type="text" id="wechat" name="wechat" 
                               value="<?= e($_POST['wechat'] ?? $contact['wechat'] ?? '') ?>" 
                               maxlength="100" placeholder="WeChat username">
                    </div>
                    
                    <div class="form-group">
                        <label for="line_id">LINE ID:</label>
                        <input type="text" id="line_id" name="line_id" 
                               value="<?= e($_POST['line_id'] ?? $contact['line_id'] ?? '') ?>" 
                               maxlength="100" placeholder="LINE username">
                    </div>
                    
                    <div class="form-group">
                        <label for="facebook">Facebook Profile:</label>
                        <input type="url" id="facebook" name="facebook" 
                               value="<?= e($_POST['facebook'] ?? $contact['facebook'] ?? '') ?>" 
                               maxlength="255" placeholder="https://facebook.com/username">
                    </div>
                    
                    <div class="form-group">
                        <label for="linkedin">LinkedIn Profile:</label>
                        <input type="url" id="linkedin" name="linkedin" 
                               value="<?= e($_POST['linkedin'] ?? $contact['linkedin'] ?? '') ?>" 
                               maxlength="255" placeholder="https://linkedin.com/in/username">
                    </div>
                </div>

                <!-- Project Assignment -->
                <div class="form-section">
                    <h3>Project Assignment</h3>
                    
                    <div class="form-group">
                        <label class="required">Assign to Projects:</label>
                        <div class="project-checkboxes">
                            <?php if (empty($user_projects)): ?>
                                <div class="no-projects-message">
                                    <p>No projects available. <a href="/projects/new">Create a project first</a>.</p>
                                </div>
                            <?php else: ?>
                                <?php foreach ($user_projects as $project): ?>
                                    <label class="checkbox-label">
                                        <input type="checkbox" name="projects[]" value="<?= $project['id'] ?>"
                                               <?= in_array($project['id'], $_POST['projects'] ?? $assigned_projects ?? ($project_id ? [$project_id] : [])) ? 'checked' : '' ?>>
                                        <span class="checkbox-text"><?= e($project['name']) ?></span>
                                    </label>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </div>
                        <p class="field-help">Select one or more projects to associate with this contact</p>
                    </div>
                </div>
            </div>

            <div class="form-actions">
                <div class="form-actions-left">
                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-save"></i>
                        <?= $is_editing ? 'Update Contact' : 'Create Contact' ?>
                    </button>
                    <a href="<?= $is_editing ? '/contacts/' . (int)$contact['id'] : '/contacts' ?>" 
                       class="btn btn-secondary">
                        <i class="fas fa-times"></i>
                        Cancel
                    </a>
                </div>
                <?php if ($is_editing): ?>
                    <div class="form-actions-right">
                        <button type="button" class="btn btn-danger" onclick="confirmDelete()">
                            <i class="fas fa-trash"></i>
                            Delete Contact
                        </button>
                    </div>
                <?php endif; ?>
            </div>
        </form>
    </div>
</div>

<style>
/* Per-page CSS for contact_edit.php.
 *
 * As of v1.6.0 (issue-014), the global rules in assets/css/style.css
 * provide the baseline (page-container, breadcrumb, form-layout,
 * form-grid, form-section, form-group, btn, alert, checkbox-label,
 * field-help, form-actions, and the @media (max-width: 768px) collapse).
 * Rules below are EITHER page-unique to this form OR explicit
 * per-page overrides of a global rule (the divergence is intentional;
 * comments tag them).
 */

/* Per-page override of .form-grid in style.css: this form prefers wider
 * gaps (30px) and an extra bottom margin. The 2-column structure
 * itself matches the global rule. */
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

/* Per-page override of .form-group input/select/textarea in style.css:
 * 1px border (vs global 2px) and a tighter 0.95rem font size. */
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
    min-height: 80px;
}

.project-checkboxes {
    display: flex;
    flex-direction: column;
    gap: 12px;
    max-height: 200px;
    overflow-y: auto;
    padding: 15px;
    border: 1px solid #ddd;
    border-radius: 6px;
    background: #f8f9fa;
}

/* Per-page override of .checkbox-label in style.css: adds padding and
 * hover background. The issue-013 global rule sets display:flex +
 * align-items:center + gap:8px + cursor:pointer; this one extends
 * `gap: 10px` and adds interactive feedback. */
.checkbox-label {
    display: flex;
    align-items: center;
    gap: 10px;
    cursor: pointer;
    padding: 8px;
    border-radius: 4px;
    transition: background-color 0.2s ease;
}

.checkbox-label:hover {
    background: #e9ecef;
}

.checkbox-label input[type="checkbox"] {
    width: auto;
    margin: 0;
    cursor: pointer;
}

.checkbox-text {
    font-size: 0.95rem;
    color: #333;
}

.no-projects-message {
    text-align: center;
    padding: 20px;
    color: #666;
}

.no-projects-message p {
    margin: 0;
}

.no-projects-message a {
    color: #007bff;
    text-decoration: none;
}

.no-projects-message a:hover {
    text-decoration: underline;
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
     * .form-grid now lives in style.css; only the contact-page-specific
     * mobile tweaks remain. */
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

    .project-checkboxes {
        max-height: 150px;
    }
}
</style>

<?php if ($is_editing): ?>
<script>
function confirmDelete() {
    if (confirm('Are you sure you want to delete this contact? This action cannot be undone.')) {
        window.location.href = 'contact_delete.php?id=<?= $contact_id ?>';
    }
}
</script>
<?php endif; ?>

<?php include 'includes/footer.php'; ?>