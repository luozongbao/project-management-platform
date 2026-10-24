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
                // Issue-020: session ID rotation. The `true` argument
                // deletes the old session file so a session-fixation
                // attacker's pre-set cookie becomes invalid the moment
                // the victim authenticates. Must run BEFORE any
                // $_SESSION writes so the new ID is what gets the
                // user_id populated.
                session_regenerate_id(true);

                // Login successful. recordLoginAttempt(true) also clears
                // the per-(IP, username) failure history so the user is
                // not locked out by their own past failures.
                $_SESSION['user_id'] = $user['id'];
                $_SESSION['username'] = $user['username'];
                $_SESSION['name'] = $user['name'];
                $_SESSION['timezone'] = $_SESSION['timezone'] ?? TIMEZONE;

                recordLoginAttempt($clientIp, $username, true);
                redirect('/dashboard', 'Login successful!', 'success');
            } else {
                $errors[] = "Invalid username or password";
                recordLoginAttempt($clientIp, $username, false);
            }
        }
    }
}

$title = "Login";
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
            <h2 class="auth-aside__headline">Every project,<br>every task,<br>one clear view.</h2>
            <p class="auth-aside__sub">Track delivery, surface delays before they bite, and share live progress with your clients.</p>
            <ul class="auth-aside__points">
                <li><i class="fas fa-circle-check"></i><span>Weighted completion across projects, tasks and subtasks</span></li>
                <li><i class="fas fa-circle-check"></i><span>Mandatory delay &amp; on-hold reasons &mdash; nothing goes quiet</span></li>
                <li><i class="fas fa-circle-check"></i><span>Secure share codes for client read-only portals</span></li>
                <li><i class="fas fa-circle-check"></i><span>Bilingual (ENG / 中文) client portal</span></li>
            </ul>
        </div>
        <div class="auth-aside__foot"><?= APP_VERSION ?> &middot; <?= APP_NAME ?></div>
    </aside>

    <main class="auth-main">
        <div class="auth-card">
            <h1>Welcome back</h1>
            <p class="auth-card__sub">Sign in to manage your projects, tasks and contacts.</p>

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
                    <label for="username">Username or Email</label>
                    <input class="input" type="text" id="username" name="username"
                           value="<?= e($_POST['username'] ?? '') ?>" required>
                </div>

                <div class="field">
                    <label for="password">Password</label>
                    <input class="input" type="password" id="password" name="password" required>
                </div>

                <div class="auth-links" style="margin-bottom: 1.25rem;">
                    <span></span>
                    <a href="/forgot-password">Forgot your password?</a>
                </div>

                <button type="submit" class="btn btn--primary btn--lg btn--block">
                    <i class="fas fa-right-to-bracket"></i>
                    Sign In
                </button>
            </form>

            <?php if (isRegistrationAllowed()): ?>
                <div class="auth-card__divider">New here?</div>

                <a class="btn btn--secondary btn--block" href="/register">
                    <i class="fas fa-user-plus"></i>
                    Create an account
                </a>
            <?php endif; ?>

            <div class="auth-card__foot">
                <a href="/"><i class="fas fa-key"></i> Client? Enter your share code</a>
            </div>
        </div>
    </main>
</div>

<?php include 'includes/footer.php'; ?>


<?php include 'includes/footer.php'; ?>