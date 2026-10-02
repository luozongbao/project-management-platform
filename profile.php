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
    redirect('login.php');
}

// Handle form submissions
$success_message = '';
$error_messages = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
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
                $errors[] = "Error updating profile: " . $e->getMessage();
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
                $errors[] = "Error changing password: " . $e->getMessage();
            }
        }
        
        $error_messages = $errors;
    }
}

// Get user statistics
$user_stats = [
    'projects_count' => $db->fetchOne("SELECT COUNT(*) as count FROM projects WHERE responsible_person_id = ?", [$user_id])['count'] ?? 0,
    'tasks_count' => $db->fetchOne("SELECT COUNT(*) as count FROM tasks t JOIN projects p ON t.project_id = p.id WHERE p.responsible_person_id = ?", [$user_id])['count'] ?? 0,
    'completed_tasks' => $db->fetchOne("SELECT COUNT(*) as count FROM tasks t JOIN projects p ON t.project_id = p.id WHERE p.responsible_person_id = ? AND t.status = 'completed'", [$user_id])['count'] ?? 0,
    'contacts_count' => $db->fetchOne("SELECT COUNT(DISTINCT c.id) as count FROM contacts c JOIN project_contacts pc ON c.id = pc.contact_id JOIN projects p ON pc.project_id = p.id WHERE p.responsible_person_id = ?", [$user_id])['count'] ?? 0,
];

include 'includes/header.php';
?>

<div class="page-header">
    <div class="page-title">
        <h1>
            <i class="fas fa-user-circle"></i>
            User Profile
        </h1>
        <p>Manage your account information and settings</p>
    </div>
</div>

<?php if ($success_message): ?>
    <div class="alert alert-success">
        <i class="fas fa-check-circle"></i>
        <?= e($success_message) ?>
    </div>
<?php endif; ?>

<?php if (!empty($error_messages)): ?>
    <div class="alert alert-danger">
        <i class="fas fa-exclamation-circle"></i>
        <ul style="margin: 0; padding-left: 20px;">
            <?php foreach ($error_messages as $error): ?>
                <li><?= e($error) ?></li>
            <?php endforeach; ?>
        </ul>
    </div>
<?php endif; ?>

<div class="profile-layout">
    <!-- Profile Overview -->
    <div class="profile-overview">
        <div class="card">
            <div class="card-header">
                <h3>Profile Overview</h3>
            </div>
            <div class="card-body">
                <div class="profile-avatar">
                    <div class="avatar-circle">
                        <i class="fas fa-user"></i>
                    </div>
                    <div class="profile-info">
                        <h2><?= e($user['name']) ?></h2>
                        <p class="username">@<?= e($user['username']) ?></p>
                        <p class="email"><?= e($user['email']) ?></p>
                        <p class="member-since">
                            <i class="fas fa-calendar"></i>
                            Member since <?= formatDateTime($user['created_at'], 'F j, Y') ?>
                        </p>
                    </div>
                </div>
                
                <div class="profile-stats">
                    <div class="stat-item">
                        <div class="stat-value"><?= $user_stats['projects_count'] ?></div>
                        <div class="stat-label">Projects</div>
                    </div>
                    <div class="stat-item">
                        <div class="stat-value"><?= $user_stats['tasks_count'] ?></div>
                        <div class="stat-label">Tasks</div>
                    </div>
                    <div class="stat-item">
                        <div class="stat-value"><?= $user_stats['completed_tasks'] ?></div>
                        <div class="stat-label">Completed</div>
                    </div>
                    <div class="stat-item">
                        <div class="stat-value"><?= $user_stats['contacts_count'] ?></div>
                        <div class="stat-label">Contacts</div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Profile Settings -->
    <div class="profile-settings">
        <!-- Edit Profile Form -->
        <div class="card">
            <div class="card-header">
                <h3>
                    <i class="fas fa-user-edit"></i>
                    Edit Profile
                </h3>
            </div>
            <div class="card-body">
                <form method="POST" action="">
                    <input type="hidden" name="action" value="update_profile">
                    
                    <div class="form-group">
                        <label for="name">Full Name</label>
                        <input type="text" id="name" name="name" 
                               value="<?= e($_POST['name'] ?? $user['name']) ?>" 
                               required maxlength="100">
                    </div>
                    
                    <div class="form-group">
                        <label for="username">Username</label>
                        <input type="text" id="username" name="username" 
                               value="<?= e($_POST['username'] ?? $user['username']) ?>" 
                               required maxlength="50"
                               pattern="[a-zA-Z0-9_.-]+"
                               title="Username can only contain letters, numbers, dots, hyphens, and underscores">
                    </div>
                    
                    <div class="form-group">
                        <label for="email">Email Address</label>
                        <input type="email" id="email" name="email" 
                               value="<?= e($_POST['email'] ?? $user['email']) ?>" 
                               required maxlength="255">
                    </div>
                    
                    <div class="form-actions">
                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-save"></i>
                            Update Profile
                        </button>
                    </div>
                </form>
            </div>
        </div>

        <!-- Change Password Form -->
        <div class="card">
            <div class="card-header">
                <h3>
                    <i class="fas fa-key"></i>
                    Change Password
                </h3>
            </div>
            <div class="card-body">
                <form method="POST" action="">
                    <input type="hidden" name="action" value="change_password">
                    
                    <div class="form-group">
                        <label for="current_password">Current Password</label>
                        <input type="password" id="current_password" name="current_password" required>
                    </div>
                    
                    <div class="form-group">
                        <label for="new_password">New Password</label>
                        <input type="password" id="new_password" name="new_password" 
                               required minlength="8"
                               title="Password must be at least 8 characters long">
                        <small class="form-text">Password must be at least 8 characters long.</small>
                    </div>
                    
                    <div class="form-group">
                        <label for="confirm_password">Confirm New Password</label>
                        <input type="password" id="confirm_password" name="confirm_password" required>
                    </div>
                    
                    <div class="form-actions">
                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-key"></i>
                            Change Password
                        </button>
                    </div>
                </form>
            </div>
        </div>

        <!-- Account Actions -->
        <div class="card">
            <div class="card-header">
                <h3>
                    <i class="fas fa-cogs"></i>
                    Account Actions
                </h3>
            </div>
            <div class="card-body">
                <div class="action-buttons">
                    <a href="dashboard.php" class="btn btn-outline">
                        <i class="fas fa-tachometer-alt"></i>
                        Back to Dashboard
                    </a>
                    <a href="logout.php" class="btn btn-secondary">
                        <i class="fas fa-sign-out-alt"></i>
                        Logout
                    </a>
                </div>
            </div>
        </div>
    </div>
</div>

<style>
.profile-layout {
    display: grid;
    grid-template-columns: 1fr 2fr;
    gap: 20px;
    max-width: 1200px;
    margin: 0 auto;
}

.profile-overview .card {
    position: sticky;
    top: 20px;
}

.profile-avatar {
    display: flex;
    align-items: center;
    gap: 20px;
    margin-bottom: 30px;
    padding-bottom: 20px;
    border-bottom: 1px solid #e9ecef;
}

.avatar-circle {
    width: 80px;
    height: 80px;
    border-radius: 50%;
    background: linear-gradient(135deg, var(--primary-color), var(--secondary-color));
    display: flex;
    align-items: center;
    justify-content: center;
    color: white;
    font-size: 32px;
    flex-shrink: 0;
}

.profile-info h2 {
    margin: 0 0 5px 0;
    color: #333;
}

.profile-info .username {
    margin: 0 0 5px 0;
    color: #666;
    font-weight: 500;
}

.profile-info .email {
    margin: 0 0 10px 0;
    color: #666;
}

.profile-info .member-since {
    margin: 0;
    color: #999;
    font-size: 14px;
}

.profile-stats {
    display: grid;
    grid-template-columns: repeat(2, 1fr);
    gap: 15px;
}

.stat-item {
    text-align: center;
    padding: 15px;
    background: #f8f9fa;
    border-radius: 8px;
}

.stat-value {
    font-size: 24px;
    font-weight: bold;
    color: var(--primary-color);
    margin-bottom: 5px;
}

.stat-label {
    font-size: 14px;
    color: #666;
    text-transform: uppercase;
    letter-spacing: 0.5px;
}

.profile-settings {
    display: flex;
    flex-direction: column;
    gap: 20px;
}

.action-buttons {
    display: flex;
    gap: 10px;
    flex-wrap: wrap;
}

.form-text {
    display: block;
    margin-top: 5px;
    color: #666;
    font-size: 12px;
}

/* Responsive Design */
@media (max-width: 768px) {
    .profile-layout {
        grid-template-columns: 1fr;
        gap: 15px;
    }
    
    .profile-avatar {
        flex-direction: column;
        text-align: center;
        gap: 15px;
    }
    
    .profile-stats {
        grid-template-columns: repeat(4, 1fr);
        gap: 10px;
    }
    
    .stat-item {
        padding: 10px;
    }
    
    .stat-value {
        font-size: 20px;
    }
    
    .action-buttons {
        flex-direction: column;
    }
    
    .action-buttons .btn {
        width: 100%;
        justify-content: center;
    }
}
</style>

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