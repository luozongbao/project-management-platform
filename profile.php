<?php
require_once 'config.php';
require_once 'includes/Database.php';
require_once 'includes/functions.php';

requireLogin();

$db = Database::getInstance();
$user_id = getCurrentUserId();

// Get current user information
$user = $db->fetchOne(
    "SELECT id, name, username, email, created_at FROM users WHERE id = ?",
    [$user_id]
);

if (!$user) {
    redirect('/login');
}

// Handle form submissions
$success_message = '';
$error_messages = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Issue-016 (CSRF): reject any request that does not carry a valid
    // CSRF token. The check sits BEFORE the action dispatch so a missing
    // / invalid token cannot leak DB error messages or partial state.
    if (!validateCsrfToken($_POST['csrf_token'] ?? null)) {
        $error_messages[] = 'Your session could not be verified. '
                          . 'Please reload the page and submit again.';
    } else {
    $action = $_POST['action'] ?? '';

    if ($action === 'update_profile') {
        // Update profile information
        $name = trim($_POST['name'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $username = trim($_POST['username'] ?? '');
        
        $errors = [];
        
        // Validation
        if (empty($name)) {
            $errors[] = "Name is required.";
        } elseif (strlen($name) > 100) {
            $errors[] = "Name must be 100 characters or less.";
        }
        
        if (empty($email)) {
            $errors[] = "Email is required.";
        } elseif (!isValidEmail($email)) {
            $errors[] = "Please enter a valid email address.";
        } elseif (strlen($email) > 255) {
            $errors[] = "Email must be 255 characters or less.";
        }
        
        if (empty($username)) {
            $errors[] = "Username is required.";
        } elseif (strlen($username) > 50) {
            $errors[] = "Username must be 50 characters or less.";
        } elseif (!preg_match('/^[a-zA-Z0-9_.-]+$/', $username)) {
            $errors[] = "Username can only contain letters, numbers, dots, hyphens, and underscores.";
        }
        
        // Check if email or username already exists (for other users)
        if (empty($errors)) {
            $existing_email = $db->fetchOne(
                "SELECT id FROM users WHERE email = ? AND id != ?",
                [$email, $user_id]
            );
            if ($existing_email) {
                $errors[] = "This email address is already in use by another account.";
            }
            
            $existing_username = $db->fetchOne(
                "SELECT id FROM users WHERE username = ? AND id != ?",
                [$username, $user_id]
            );
            if ($existing_username) {
                $errors[] = "This username is already taken.";
            }
        }
        
        if (empty($errors)) {
            try {
                $db->execute(
                    "UPDATE users SET name = ?, email = ?, username = ?, updated_at = NOW() WHERE id = ?",
                    [$name, $email, $username, $user_id]
                );
                
                // Update user data for display
                $user['name'] = $name;
                $user['email'] = $email;
                $user['username'] = $username;

                $success_message = "Profile updated successfully!";
            } catch (Exception $e) {
                // Issue-016: log the underlying error server-side but
                // do NOT echo it to the user (info disclosure).
                error_log("Profile update failed: " . $e->getMessage());
                $errors[] = "Failed to update the profile. Please try again. "
                          . "If the problem persists, contact your administrator.";
            }
        }

        $error_messages = $errors;

    } elseif ($action === 'change_password') {
        // Change password
        $current_password = $_POST['current_password'] ?? '';
        $new_password = $_POST['new_password'] ?? '';
        $confirm_password = $_POST['confirm_password'] ?? '';
        
        $errors = [];
        
        // Validation
        if (empty($current_password)) {
            $errors[] = "Current password is required.";
        }
        
        if (empty($new_password)) {
            $errors[] = "New password is required.";
        } elseif (strlen($new_password) < 8) {
            $errors[] = "New password must be at least 8 characters long.";
        }
        
        if ($new_password !== $confirm_password) {
            $errors[] = "New password and confirmation do not match.";
        }
        
        // Verify current password
        if (empty($errors)) {
            $current_user = $db->fetchOne("SELECT password FROM users WHERE id = ?", [$user_id]);
            if (!verifyPassword($current_password, $current_user['password'])) {
                $errors[] = "Current password is incorrect.";
            }
        }
        
        if (empty($errors)) {
            try {
                $hashed_password = hashPassword($new_password);
                $db->execute(
                    "UPDATE users SET password = ?, updated_at = NOW() WHERE id = ?",
                    [$hashed_password, $user_id]
                );
                
                $success_message = "Password changed successfully!";
            } catch (Exception $e) {
                // Issue-016: log the underlying error server-side but
                // do NOT echo it to the user (info disclosure).
                error_log("Password change failed: " . $e->getMessage());
                $errors[] = "Failed to change the password. Please try again. "
                          . "If the problem persists, contact your administrator.";
            }
        }

        $error_messages = $errors;
    }
    } // end else (!validateCsrfToken failed)
}

// Get user statistics
$user_stats = [
    'projects_count' => $db->fetchOne("SELECT COUNT(*) as count FROM projects WHERE responsible_person_id = ?", [$user_id])['count'] ?? 0,
    'tasks_count' => $db->fetchOne("SELECT COUNT(*) as count FROM tasks t JOIN projects p ON t.project_id = p.id WHERE p.responsible_person_id = ?", [$user_id])['count'] ?? 0,
    'completed_tasks' => $db->fetchOne("SELECT COUNT(*) as count FROM tasks t JOIN projects p ON t.project_id = p.id WHERE p.responsible_person_id = ? AND t.status = 'completed'", [$user_id])['count'] ?? 0,
    'contacts_count' => $db->fetchOne("SELECT COUNT(DISTINCT c.id) as count FROM contacts c JOIN project_contacts pc ON c.id = pc.contact_id JOIN projects p ON pc.project_id = p.id WHERE p.responsible_person_id = ?", [$user_id])['count'] ?? 0,
];

$title = "User Profile";
$show_nav = true;
$use_v2_shell = true;

include 'includes/header.php';
?>
<link rel="stylesheet" href="<?= e(asset_url('/assets/css/pages/profile.css')) ?>">

<div class="page-header">
    <div class="page-header__text">
        <h1><i class="fas fa-user-circle"></i> User Profile</h1>
        <p class="page-header__sub">Manage your account information and settings</p>
    </div>
</div>

<?php if ($success_message): ?>
    <div class="alert alert--success">
        <i class="fas fa-check-circle"></i>
        <div class="alert__body"><?= e($success_message) ?></div>
    </div>
<?php endif; ?>

<?php if (!empty($error_messages)): ?>
    <div class="alert alert--danger">
        <i class="fas fa-exclamation-circle"></i>
        <div class="alert__body">
            <ul class="pf-errors">
                <?php foreach ($error_messages as $error): ?>
                    <li><?= e($error) ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
    </div>
<?php endif; ?>

<!-- Profile Overview: not in the mockup, but v1.8.0 shows identity
     information (name / @username / email / member since) on this page and
     removing it would be a content regression, not a restyle. -->
<div class="card pf-overview-card">
    <div class="card__header">
        <h2 class="card__title-icon"><i class="fas fa-user"></i> Profile Overview</h2>
    </div>
    <div class="card__body">
        <div class="pf-overview">
            <div class="pf-avatar"><i class="fas fa-user"></i></div>
            <div class="pf-overview__text">
                <p class="pf-overview__name"><?= e($user['name']) ?></p>
                <p class="pf-overview__handle">@<?= e($user['username']) ?></p>
                <p class="pf-overview__email"><?= e($user['email']) ?></p>
                <p class="pf-overview__since">
                    <i class="fas fa-calendar"></i>
                    Member since <?= formatDateTime($user['created_at'], 'F j, Y') ?>
                </p>
            </div>
        </div>
    </div>
</div>

<div class="stat-grid pf-stat-row">
    <div class="stat">
        <div class="stat__icon"><i class="fas fa-folder-open"></i></div>
        <div><div class="stat__value"><?= $user_stats['projects_count'] ?></div><div class="stat__label">Projects</div></div>
    </div>
    <div class="stat">
        <div class="stat__icon"><i class="fas fa-list-check"></i></div>
        <div><div class="stat__value"><?= $user_stats['tasks_count'] ?></div><div class="stat__label">Tasks</div></div>
    </div>
    <div class="stat stat--success">
        <div class="stat__icon"><i class="fas fa-circle-check"></i></div>
        <div><div class="stat__value"><?= $user_stats['completed_tasks'] ?></div><div class="stat__label">Completed</div></div>
    </div>
    <div class="stat stat--info">
        <div class="stat__icon"><i class="fas fa-address-book"></i></div>
        <div><div class="stat__value"><?= $user_stats['contacts_count'] ?></div><div class="stat__label">Contacts</div></div>
    </div>
</div>

<div class="grid-2 pf-body">
    <!-- Edit Profile Form -->
    <form class="card" method="POST" action="">
        <input type="hidden" name="action" value="update_profile">
        <input type="hidden" name="csrf_token" value="<?= e(getCsrfToken()) ?>">
        <div class="card__header">
            <h2 class="card__title-icon"><i class="fas fa-id-card"></i> Profile information</h2>
        </div>
        <div class="card__body">
            <div class="form-grid">
                <div class="field">
                    <label for="name">Full Name</label>
                    <input class="input" type="text" id="name" name="name"
                           value="<?= e($_POST['name'] ?? $user['name']) ?>"
                           required maxlength="100">
                </div>

                <div class="field">
                    <label for="username">Username</label>
                    <input class="input" type="text" id="username" name="username"
                           value="<?= e($_POST['username'] ?? $user['username']) ?>"
                           required maxlength="50"
                           pattern="[a-zA-Z0-9_.-]+"
                           title="Username can only contain letters, numbers, dots, hyphens, and underscores">
                </div>

                <div class="field">
                    <label for="email">Email Address</label>
                    <input class="input" type="email" id="email" name="email"
                           value="<?= e($_POST['email'] ?? $user['email']) ?>"
                           required maxlength="255">
                </div>
            </div>
        </div>
        <div class="card__footer">
            <button type="submit" class="btn btn--primary">
                <i class="fas fa-floppy-disk"></i> Update Profile
            </button>
        </div>
    </form>

    <!-- Change Password Form -->
    <form class="card" method="POST" action="">
        <input type="hidden" name="action" value="change_password">
        <input type="hidden" name="csrf_token" value="<?= e(getCsrfToken()) ?>">
        <div class="card__header">
            <h2 class="card__title-icon"><i class="fas fa-lock"></i> Change Password</h2>
        </div>
        <div class="card__body">
            <div class="form-grid">
                <div class="field">
                    <label for="current_password">Current Password</label>
                    <input class="input" type="password" id="current_password" name="current_password" autocomplete="current-password" required>
                </div>

                <div class="field">
                    <label for="new_password">New Password</label>
                    <input class="input" type="password" id="new_password" name="new_password" autocomplete="new-password" required minlength="8"
                           title="Password must be at least 8 characters long">
                    <span class="hint">Password must be at least 8 characters long.</span>
                </div>

                <div class="field">
                    <label for="confirm_password">Confirm New Password</label>
                    <input class="input" type="password" id="confirm_password" name="confirm_password" autocomplete="new-password" required>
                </div>
            </div>
        </div>
        <div class="card__footer">
            <button type="submit" class="btn btn--primary">
                <i class="fas fa-key"></i> Change Password
            </button>
        </div>
    </form>

    <!-- Account Actions: also not in the mockup. v1.8.0 offers a logout one
         click from the password form; the sidebar links are not a substitute
         for a deliberate action. btn-outline has no v2 equivalent, so the
         dashboard link uses btn--secondary and logout uses btn--danger to
         keep the destructive action visually distinct. -->
    <div class="card pf-actions-card">
        <div class="card__header">
            <h2 class="card__title-icon"><i class="fas fa-cogs"></i> Account Actions</h2>
        </div>
        <div class="card__body">
            <div class="pf-actions">
                <a href="/dashboard" class="btn btn--secondary">
                    <i class="fas fa-tachometer-alt"></i> Back to Dashboard
                </a>
                <a href="/logout" class="btn btn--danger">
                    <i class="fas fa-sign-out-alt"></i> Logout
                </a>
            </div>
        </div>
    </div>
</div>


<script>
document.addEventListener('DOMContentLoaded', function() {
    // Password confirmation validation
    const newPasswordField = document.getElementById('new_password');
    const confirmPasswordField = document.getElementById('confirm_password');
    
    function validatePasswordMatch() {
        if (newPasswordField.value !== confirmPasswordField.value) {
            confirmPasswordField.setCustomValidity('Passwords do not match');
        } else {
            confirmPasswordField.setCustomValidity('');
        }
    }
    
    if (newPasswordField && confirmPasswordField) {
        newPasswordField.addEventListener('input', validatePasswordMatch);
        confirmPasswordField.addEventListener('input', validatePasswordMatch);
    }
    
    // Username validation
    const usernameField = document.getElementById('username');
    if (usernameField) {
        usernameField.addEventListener('input', function() {
            const value = this.value;
            const pattern = /^[a-zA-Z0-9_.-]+$/;
            
            if (value && !pattern.test(value)) {
                this.setCustomValidity('Username can only contain letters, numbers, dots, hyphens, and underscores');
            } else {
                this.setCustomValidity('');
            }
        });
    }
});
</script>

<?php include 'includes/footer.php'; ?>