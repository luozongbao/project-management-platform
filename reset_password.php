<?php
require_once 'config.php';
require_once 'includes/Database.php';
require_once 'includes/functions.php';

startSession();

// Redirect if already logged in
if (isLoggedIn()) {
    header('Location: dashboard.php');
    exit();
}

$token = $_GET['token'] ?? '';
$errors = [];
$success = false;
$valid_token = false;

if (empty($token)) {
    $errors[] = "Invalid reset link";
} else {
    $db = Database::getInstance();
    
    // Verify token and check if not expired
    $reset_data = $db->fetchOne(
        "SELECT rt.id, rt.user_id, rt.expires_at, u.name, u.email 
         FROM password_reset_tokens rt 
         JOIN users u ON rt.user_id = u.id 
         WHERE rt.token = ? AND rt.expires_at > NOW()",
        [$token]
    );
    
    if ($reset_data) {
        // Issue-024: the previous flow rotated the token on every
        // GET render and 302-redirected to the new URL. The intent
        // was to invalidate a URL leaked via browser history the
        // moment the user opens the page, but the implementation
        // created an infinite redirect loop: rotatePasswordResetToken()
        // ALWAYS returns a fresh token (it generates a new one via
        // generateToken() on every call), so `$rotated_token !==
        // $token` was always true, the 302 fired on every request,
        // the browser followed the next request, the page rotated
        // again, and so on until the browser gave up. The user saw
        // either a "Too many redirects" error or, on some browsers,
        // the cached error page from a later request that had
        // already lost track of the original token. Symptom:
        // "clicked the link immediately and got 'Invalid Reset
        // Link'".
        //
        // The fix: do NOT rotate on render and do NOT redirect.
        // The token in the URL stays valid for the whole form
        // session. Rotation happens ONLY on successful POST (the
        // existing code at line ~116 invalidates ALL of the user's
        // tokens on success, which is stronger than per-link
        // rotation).
        //
        // The rate-limit check stays in place — it is the right
        // defense against a brute-force campaign against a known
        // good token. The "Invalid or expired reset link" string is
        // reused for the rate-limited case so an attacker cannot
        // distinguish "rate limited" from "bad token" from
        // "expired token".
        $clientIp = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
        if (isPasswordResetRateLimited($clientIp, 'consume')) {
            $errors[] = "Invalid or expired reset link";
        } else {
            $valid_token = true;
        }

        if ($_POST) {
            $password = $_POST['password'] ?? '';
            $confirm_password = $_POST['confirm_password'] ?? '';

            // Issue-018: per-IP rate limit on the consume side. We
            // check before any password validation so a sustained
            // brute-force attempt against a known-good token is
            // also blocked. The recordPasswordResetAttempt() call
            // below is paired with the success/failure of THIS POST.
            $clientIp = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
            if (isPasswordResetRateLimited($clientIp, 'consume')) {
                // Drop the request. We do NOT log this attempt so
                // the rate limit is not self-reinforcing: the 11th
                // request does not become part of the 15-min
                // window and lock the user out for a full 15
                // minutes from a single bad password. The page
                // still renders the "Invalid or expired reset
                // link" block because $valid_token is set to
                // false below.
                $valid_token = false;
                $errors[] = "Invalid or expired reset link";
            } else {
                // Validate passwords. Every failure is recorded as
                // a 'consume' attempt so a brute-force campaign
                // against a known-good token is also rate-limited.
                if (empty($password)) {
                    $errors[] = "Password is required";
                    recordPasswordResetAttempt($clientIp, 'consume', $reset_data['user_id'], false);
                } elseif (strlen($password) < 6) {
                    $errors[] = "Password must be at least 6 characters long";
                    recordPasswordResetAttempt($clientIp, 'consume', $reset_data['user_id'], false);
                } elseif ($password !== $confirm_password) {
                    $errors[] = "Passwords do not match";
                    recordPasswordResetAttempt($clientIp, 'consume', $reset_data['user_id'], false);
                } else {
                    try {
                        // Update password
                        $hashed_password = hashPassword($password);
                        $db->query(
                            "UPDATE users SET password = ?, updated_at = NOW() WHERE id = ?",
                            [$hashed_password, $reset_data['user_id']]
                        );

                        // Issue-018: invalidate ALL live tokens for
                        // this user (not just the consumed one). A
                        // previously-issued link must not be usable
                        // after a successful reset.
                        invalidateAllPasswordResetTokensForUser($reset_data['user_id']);

                        // Issue-020: session ID rotation on a successful
                        // password reset. The reset flow does not
                        // auto-log-in, but rotating the session ID here
                        // means the URL token is no longer bound to the
                        // old session. A network-position attacker who
                        // observed the old session ID cannot reuse it
                        // after the reset succeeds.
                        session_regenerate_id(true);

                        $success = true;

                        recordPasswordResetAttempt($clientIp, 'consume', $reset_data['user_id'], true);
                        // A successful consume clears the consume
                        // history for this IP so the legit user is
                        // not locked out by their own success.
                        clearPasswordResetAttempts($clientIp, 'consume');

                    } catch (Exception $e) {
                        error_log("Password reset error: " . $e->getMessage());
                        $errors[] = "Failed to reset password. Please try again.";
                        recordPasswordResetAttempt($clientIp, 'consume', $reset_data['user_id'], false);
                    }
                }
            }
        }
    } else {
        $errors[] = "Invalid or expired reset link";
    }
}

$title = "Reset Password";
?>

<?php include 'includes/header.php'; ?>

<div class="auth-container">
    <div class="auth-form">
        <div class="auth-header">
            <i class="fas fa-lock"></i>
            <h1>Reset Password</h1>
            <p>Enter your new password</p>
        </div>
        
        <?php if ($success): ?>
            <div class="alert alert-success">
                <h4>Password Reset Successful!</h4>
                <p>Your password has been successfully reset. You can now log in with your new password.</p>
                <p><a href="login.php" class="btn btn-primary">Go to Login</a></p>
            </div>
        <?php elseif (!$valid_token): ?>
            <div class="alert alert-danger">
                <h4>Invalid Reset Link</h4>
                <p>This password reset link is invalid or has expired. Reset links are only valid for 1 hour.</p>
                <p>Please request a new password reset link.</p>
                <p><a href="forgot_password.php" class="btn btn-primary">Request New Link</a></p>
            </div>
        <?php else: ?>
            <?php if (!empty($errors)): ?>
                <div class="alert alert-danger">
                    <?php foreach ($errors as $error): ?>
                        <div><?= e($error) ?></div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
            
            <div class="reset-info">
                <p><strong>Account:</strong> <?= e($reset_data['name']) ?> (<?= e($reset_data['email']) ?>)</p>
            </div>
            
            <form method="POST" action="">
                <div class="form-group">
                    <label for="password">
                        <i class="fas fa-lock"></i>
                        New Password
                    </label>
                    <input type="password" id="password" name="password" 
                           minlength="6" required>
                    <small>Must be at least 6 characters long</small>
                </div>
                
                <div class="form-group">
                    <label for="confirm_password">
                        <i class="fas fa-lock"></i>
                        Confirm New Password
                    </label>
                    <input type="password" id="confirm_password" name="confirm_password" 
                           minlength="6" required>
                </div>
                
                <button type="submit" class="btn btn-primary btn-block">
                    <i class="fas fa-check"></i>
                    Reset Password
                </button>
            </form>
        <?php endif; ?>
        
        <div class="auth-links">
            <a href="login.php">
                <i class="fas fa-arrow-left"></i>
                Back to Login
            </a>
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

.reset-info {
    background: #f8f9fa;
    padding: 15px;
    border-radius: 5px;
    margin-bottom: 20px;
    border-left: 4px solid #007bff;
}

.reset-info p {
    margin: 0;
    color: #333;
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
    padding: 15px;
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

<script>
// Password confirmation validation
document.addEventListener('DOMContentLoaded', function() {
    const password = document.getElementById('password');
    const confirmPassword = document.getElementById('confirm_password');
    
    function validatePasswordMatch() {
        if (confirmPassword.value !== '' && password.value !== confirmPassword.value) {
            confirmPassword.setCustomValidity('Passwords do not match');
        } else {
            confirmPassword.setCustomValidity('');
        }
    }
    
    password.addEventListener('input', validatePasswordMatch);
    confirmPassword.addEventListener('input', validatePasswordMatch);
});
</script>

<?php include 'includes/footer.php'; ?>