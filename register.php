<?php
require_once 'config.php';
require_once 'includes/Database.php';
require_once 'includes/functions.php';
require_once 'includes/EmailService.php';

startSession();

// Redirect if already logged in
if (isLoggedIn()) {
    header('Location: dashboard.php');
    exit();
}

$errors = [];
$success = false;

if ($_POST) {
    $name = sanitize($_POST['name'] ?? '');
    $username = sanitize($_POST['username'] ?? '');
    $email = sanitize($_POST['email'] ?? '');
    
    // Validation
    if (empty($name)) $errors[] = "Name is required";
    if (empty($username)) $errors[] = "Username is required";
    if (empty($email)) $errors[] = "Email is required";
    
    if (!empty($username) && strlen($username) < 3) {
        $errors[] = "Username must be at least 3 characters long";
    }
    
    if (!empty($email) && !isValidEmail($email)) {
        $errors[] = "Please enter a valid email address";
    }
    
    if (empty($errors)) {
        $db = Database::getInstance();

        // Check if username or email already exists
        $existing = $db->fetchOne(
            "SELECT id FROM users WHERE username = ? OR email = ?",
            [$username, $email]
        );

        if ($existing) {
            // Account enumeration mitigation (issue-019): do NOT reveal that
            // the user/email is taken. Render the SAME success card as a real
            // registration would, but do NOT create a user and do NOT send a
            // welcome email. The existing user already has their original
            // welcome email; this silent no-op is byte-identical to a fresh
            // registration from the attacker's point of view. The attacker
            // cannot distinguish "user created" from "user exists" from the
            // response, so they cannot enumerate the user table.
            $success = true;
        } else {
            try {
                // Create user with temporary password
                $temp_password = generateToken(16);
                $hashed_password = hashPassword($temp_password);

                $db->query(
                    "INSERT INTO users (name, username, email, password, created_at) VALUES (?, ?, ?, ?, NOW())",
                    [$name, $username, $email, $hashed_password]
                );

                $user_id = $db->lastInsertId();

                // Generate password reset token
                $reset_token = generateToken();
                $expires_at = date('Y-m-d H:i:s', time() + PASSWORD_RESET_EXPIRY);

                $db->query(
                    "INSERT INTO password_reset_tokens (user_id, token, expires_at) VALUES (?, ?, ?)",
                    [$user_id, $reset_token, $expires_at]
                );

                // Send welcome email with password setup link
                $emailService = new EmailService();
                if ($emailService->sendWelcomeEmail($email, $name, $reset_token)) {
                    $success = true;
                } else {
                    $errors[] = "Account created but failed to send email. Please use forgot password to set your password.";
                }

            } catch (Exception $e) {
                error_log("Registration error: " . $e->getMessage());
                $errors[] = "Registration failed. Please try again.";
            }
        }
    }
}

$title = "Register";
?>

<?php include 'includes/header.php'; ?>

<div class="auth-container">
    <div class="auth-form">
        <div class="auth-header">
            <i class="fas fa-user-plus"></i>
            <h1>Create Account</h1>
            <p>Join <?= APP_NAME ?> today</p>
        </div>
        
        <?php if ($success): ?>
            <div class="alert alert-success">
                <h4>Registration Successful!</h4>
                <p>Your account has been created successfully. We've sent a password setup link to your email address.</p>
                <p>Please check your email and follow the instructions to set your password.</p>
                <p><a href="/login">Return to Login</a></p>
            </div>
        <?php else: ?>
            <?php if (!empty($errors)): ?>
                <div class="alert alert-danger">
                    <?php foreach ($errors as $error): ?>
                        <div><?= e($error) ?></div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
            
            <form method="POST" action="">
                <div class="form-group">
                    <label for="name">
                        <i class="fas fa-user"></i>
                        Full Name
                    </label>
                    <input type="text" id="name" name="name" 
                           value="<?= e($_POST['name'] ?? '') ?>" required>
                </div>
                
                <div class="form-group">
                    <label for="username">
                        <i class="fas fa-at"></i>
                        Username
                    </label>
                    <input type="text" id="username" name="username" 
                           value="<?= e($_POST['username'] ?? '') ?>" 
                           minlength="3" required>
                    <small>Must be at least 3 characters long</small>
                </div>
                
                <div class="form-group">
                    <label for="email">
                        <i class="fas fa-envelope"></i>
                        Email Address
                    </label>
                    <input type="email" id="email" name="email" 
                           value="<?= e($_POST['email'] ?? '') ?>" required>
                </div>
                
                <button type="submit" class="btn btn-primary btn-block">
                    <i class="fas fa-user-plus"></i>
                    Create Account
                </button>
            </form>
        <?php endif; ?>
        
        <div class="auth-links">
            <a href="/login">Already have an account? Sign in</a>
        </div>
    </div>
</div>

<style>
.main-content.no-nav {
    padding: 0;
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    min-height: 100vh;
    display: flex;
    align-items: center;
    justify-content: center;
}

.auth-container {
    width: 100%;
    max-width: 400px;
    padding: 20px;
}

.auth-form {
    background: white;
    padding: 40px;
    border-radius: 10px;
    box-shadow: 0 10px 30px rgba(0,0,0,0.2);
}

.auth-header {
    text-align: center;
    margin-bottom: 30px;
}

.auth-header i {
    font-size: 48px;
    color: #28a745;
    margin-bottom: 10px;
}

.auth-header h1 {
    margin: 10px 0;
    color: #333;
    font-size: 24px;
}

.auth-header p {
    color: #666;
    margin: 0;
}

.form-group {
    margin-bottom: 20px;
}

.form-group label {
    display: block;
    margin-bottom: 8px;
    font-weight: 500;
    color: #333;
}

.form-group label i {
    margin-right: 8px;
    color: #666;
    width: 16px;
}

.form-group input {
    width: 100%;
    padding: 12px;
    border: 1px solid #ddd;
    border-radius: 5px;
    font-size: 16px;
    transition: border-color 0.3s;
    box-sizing: border-box;
}

.form-group input:focus {
    outline: none;
    border-color: #28a745;
    box-shadow: 0 0 0 2px rgba(40,167,69,0.25);
}

.form-group small {
    display: block;
    margin-top: 5px;
    color: #666;
    font-size: 12px;
}

.btn {
    display: inline-block;
    padding: 12px 24px;
    text-decoration: none;
    border: none;
    border-radius: 5px;
    cursor: pointer;
    font-size: 16px;
    transition: all 0.3s;
    text-align: center;
}

.btn-primary {
    background: #28a745;
    color: white;
}

.btn-primary:hover {
    background: #218838;
}

.btn-block {
    width: 100%;
}

.auth-links {
    text-align: center;
    margin-top: 30px;
}

.auth-links a {
    display: block;
    margin: 10px 0;
    color: #007bff;
    text-decoration: none;
    font-size: 14px;
}

.auth-links a:hover {
    text-decoration: underline;
}

.alert {
    padding: 12px;
    margin-bottom: 20px;
    border-radius: 5px;
}

.alert-danger {
    background: #f8d7da;
    color: #721c24;
    border: 1px solid #f5c6cb;
}

.alert-success {
    background: #d4edda;
    color: #155724;
    border: 1px solid #c3e6cb;
}

.alert h4 {
    margin-top: 0;
    margin-bottom: 10px;
}
</style>

<?php include 'includes/footer.php'; ?>