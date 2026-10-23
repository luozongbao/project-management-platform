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
$use_v2_shell = true;
$content_bleed = true;
?>

<?php include 'includes/header.php'; ?>
<link rel="stylesheet" href="<?= e(asset_url('/assets/css/pages/auth.css')) ?>">

<div class="auth-shell">
    <aside class="auth-aside">
        <div class="auth-aside__brand">
            <span class="auth-aside__brand-icon"><i class="fas fa-project-diagram"></i></span>
            <?= APP_NAME ?>
        </div>
        <div>
            <h2 class="auth-aside__headline">Set up your<br>workspace in<br>under a minute.</h2>
            <p class="auth-aside__sub">One owner account gives you projects, tasks, subtasks, contacts and a client-facing share portal.</p>
            <ul class="auth-aside__points">
                <li><i class="fas fa-circle-check"></i><span>Projects with budget, scope and three milestone dates</span></li>
                <li><i class="fas fa-circle-check"></i><span>Tasks and subtasks with completion tracking</span></li>
                <li><i class="fas fa-circle-check"></i><span>Contacts linked to projects and tasks</span></li>
            </ul>
        </div>
        <div class="auth-aside__foot"><?= APP_VERSION ?> &middot; <?= APP_NAME ?></div>
    </aside>

    <main class="auth-main">
        <div class="auth-card">
            <?php if ($success): ?>
                <h1>Registration Successful!</h1>
                <p class="auth-card__sub">Your account has been created.</p>

                <div class="alert alert--success" role="alert">
                    <i class="fas fa-circle-check"></i>
                    <div class="alert__body">
                        <div class="alert__title">Check your email</div>
                        <p>We've sent a password setup link to your email address.</p>
                        <p>Please check your email and follow the instructions to set your password.</p>
                        <p><a href="/login">Return to Login</a></p>
                    </div>
                </div>
            <?php else: ?>
                <h1>Create your account</h1>
                <p class="auth-card__sub">Already have an account? <a href="/login">Sign in</a>.</p>

                <?php if (!empty($errors)): ?>
                    <div class="alert alert--danger" role="alert">
                        <i class="fas fa-circle-exclamation"></i>
                        <div class="alert__body">
                            <?php foreach ($errors as $error): ?>
                                <div><?= e($error) ?></div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                <?php endif; ?>

                <form method="POST" action="">
                    <div class="field">
                        <label for="name">Full Name</label>
                        <input class="input" type="text" id="name" name="name"
                               value="<?= e($_POST['name'] ?? '') ?>" required>
                    </div>

                    <div class="field">
                        <label for="username">Username</label>
                        <input class="input" type="text" id="username" name="username"
                               value="<?= e($_POST['username'] ?? '') ?>"
                               minlength="3" required>
                        <span class="hint">Must be at least 3 characters long</span>
                    </div>

                    <div class="field">
                        <label for="email">Email Address</label>
                        <input class="input" type="email" id="email" name="email"
                               value="<?= e($_POST['email'] ?? '') ?>" required>
                    </div>

                    <button type="submit" class="btn btn--primary btn--lg btn--block" style="margin-top: 1rem;">
                        <i class="fas fa-user-plus"></i>
                        Create Account
                    </button>
                </form>
            <?php endif; ?>

            <div class="auth-card__foot">
                <a href="/"><i class="fas fa-key"></i> Client? Enter your share code</a>
            </div>
        </div>
    </main>
</div>


<?php include 'includes/footer.php'; ?>