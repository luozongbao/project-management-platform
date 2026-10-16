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
    $username = sanitize($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';
    $clientIp = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';

    if (empty($username)) $errors[] = "Username is required";
    if (empty($password)) $errors[] = "Password is required";

    if (empty($errors)) {
        $db = Database::getInstance();

        // Issue-019: rate-limit gate. The denied branch records a FAILURE
        // so the counter keeps incrementing for a sustained attack — an
        // attacker cannot ping the rate-limit branch forever without the
        // counter moving. The user-visible message is byte-identical to a
        // wrong-password attempt, so the attacker cannot tell "rate-limited"
        // from "wrong password".
        if (isLoginRateLimited($clientIp, $username)) {
            $errors[] = "Invalid username or password";
            recordLoginAttempt($clientIp, $username, false);
        } else {
            // Find user by username or email
            $user = $db->fetchOne(
                "SELECT id, name, username, email, password FROM users WHERE username = ? OR email = ?",
                [$username, $username]
            );

            if ($user && verifyPassword($password, $user['password'])) {
                // Login successful. recordLoginAttempt(true) also clears
                // the per-(IP, username) failure history so the user is
                // not locked out by their own past failures.
                $_SESSION['user_id'] = $user['id'];
                $_SESSION['username'] = $user['username'];
                $_SESSION['name'] = $user['name'];
                $_SESSION['timezone'] = $_SESSION['timezone'] ?? TIMEZONE;

                recordLoginAttempt($clientIp, $username, true);
                redirect('dashboard.php', 'Login successful!', 'success');
            } else {
                $errors[] = "Invalid username or password";
                recordLoginAttempt($clientIp, $username, false);
            }
        }
    }
}

$title = "Login";
?>

<?php include 'includes/header.php'; ?>

<div class="auth-container">
    <div class="auth-form">
        <div class="auth-header">
            <i class="fas fa-project-diagram"></i>
            <h1><?= APP_NAME ?></h1>
            <p>Sign in to your account</p>
        </div>
        
        <?php if (!empty($errors)): ?>
            <div class="alert alert-danger">
                <?php foreach ($errors as $error): ?>
                    <div><?= e($error) ?></div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
        
        <form method="POST" action="">
            <div class="form-group">
                <label for="username">
                    <i class="fas fa-user"></i>
                    Username or Email
                </label>
                <input type="text" id="username" name="username" 
                       value="<?= e($_POST['username'] ?? '') ?>" required>
            </div>
            
            <div class="form-group">
                <label for="password">
                    <i class="fas fa-lock"></i>
                    Password
                </label>
                <input type="password" id="password" name="password" required>
            </div>
            
            <button type="submit" class="btn btn-primary btn-block">
                <i class="fas fa-sign-in-alt"></i>
                Sign In
            </button>
        </form>
        
        <div class="auth-links">
            <a href="register.php">Don't have an account? Register</a>
            <a href="forgot_password.php">Forgot your password?</a>
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
    color: #007bff;
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
    border-color: #007bff;
    box-shadow: 0 0 0 2px rgba(0,123,255,0.25);
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
    background: #007bff;
    color: white;
}

.btn-primary:hover {
    background: #0056b3;
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
</style>

<?php include 'includes/footer.php'; ?>