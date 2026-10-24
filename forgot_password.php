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
    $email = sanitize($_POST['email'] ?? '');

    if (empty($email)) {
        $errors[] = "Email is required";
    } elseif (!isValidEmail($email)) {
        $errors[] = "Please enter a valid email address";
    } else {
        // Issue-018: rate-limit per client IP. The IP is REMOTE_ADDR,
        // not X-Forwarded-For (this app is not behind a load balancer
        // that sets the header; if a reverse proxy is added later,
        // REMOTE_ADDR resolution must be revisited).
        $clientIp = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';

        if (isPasswordResetRateLimited($clientIp, 'request')) {
            $errors[] = "Too many reset requests. Please try again later.";
            // Record the dropped attempt so the counter is consistent
            // with the 5+1 = 6 row count the spec's acceptance criteria
            // expect on the 6th request.
            recordPasswordResetAttempt($clientIp, 'request', null, false);
        } else {
            $db = Database::getInstance();

            // Check if user exists
            $user = $db->fetchOne(
                "SELECT id, name, email FROM users WHERE email = ?",
                [$email]
            );

            if ($user) {
                // Delete any existing reset tokens for this user
                $db->query(
                    "DELETE FROM password_reset_tokens WHERE user_id = ?",
                    [$user['id']]
                );

                // Generate new reset token
                $reset_token = generateToken();
                $expires_at = date('Y-m-d H:i:s', time() + PASSWORD_RESET_EXPIRY);

                $db->query(
                    "INSERT INTO password_reset_tokens (user_id, token, expires_at) VALUES (?, ?, ?)",
                    [$user['id'], $reset_token, $expires_at]
                );

                // Send reset email
                $emailService = new EmailService();
                $emailOk = $emailService->sendPasswordResetEmail($user['email'], $user['name'], $reset_token);

                if ($emailOk) {
                    $success = true;
                    recordPasswordResetAttempt($clientIp, 'request', $user['id'], true);
                } else {
                    $errors[] = "Failed to send reset email. Please try again later.";
                    // SMTP failure is still a "request was processed"
                    // from the rate-limit perspective — record as a
                    // failure so a sustained SMTP outage does not let
                    // the attacker bypass the limit.
                    recordPasswordResetAttempt($clientIp, 'request', $user['id'], false);
                }
            } else {
                // For security, don't reveal that email doesn't exist
                $success = true;
                // user_id is null for unknown emails; the attempt still
                // counts so an attacker cannot bypass the rate limit by
                // sending requests for non-existent addresses.
                recordPasswordResetAttempt($clientIp, 'request', null, true);
            }
        }
    }
}

$title = "Forgot Password";

// issue-055 bug 4: these flags MUST be assigned in PHP mode. A stray
// PHP close tag before them closed PHP early, so the two assignments
// below were echoed into the HTML as literal text instead of being
// executed. The v2 component layer (v2-components.css) is only linked
// when $use_v2_shell is truthy, so the auth page rendered without it:
// .field lost its column layout (the label sat inline beside a narrow
// input) and .input lost its full-width rule. Keep this block in PHP
// mode -- login.php and register.php set the same flags correctly.
$use_v2_shell = true;
$content_bleed = true;

include 'includes/header.php';
?>
<link rel="stylesheet" href="<?= e(asset_url('/assets/css/pages/auth.css')) ?>">

<div class="auth-shell">
    <aside class="auth-aside">
        <div class="auth-aside__brand">
            <span class="auth-aside__brand-icon"><i class="fas fa-project-diagram"></i></span>
            <?= APP_NAME ?>
        </div>
        <div>
            <h2 class="auth-aside__headline">Locked out?<br>We'll get you<br>back in.</h2>
            <p class="auth-aside__sub">Enter the email on your account and we'll send a link to set a new password.</p>
            <ul class="auth-aside__points">
                <li><i class="fas fa-circle-check"></i><span>Reset links expire after one hour</span></li>
                <li><i class="fas fa-circle-check"></i><span>Requests are rate-limited per account</span></li>
                <li><i class="fas fa-circle-check"></i><span>We never reveal whether an email is registered</span></li>
            </ul>
        </div>
        <div class="auth-aside__foot"><?= APP_VERSION ?> &middot; <?= APP_NAME ?></div>
    </aside>

    <main class="auth-main">
        <div class="auth-card">
            <?php if ($success): ?>
                <div class="alert alert--success" role="alert">
                    <i class="fas fa-circle-check"></i>
                    <div class="alert__body">
                        <div class="alert__title">Check your email</div>
                        <p>Please check your email and follow the instructions to reset your password.</p>
                        <p>The reset link will expire in 1 hour.</p>
                    </div>
                </div>

                <h1>Reset Email Sent</h1>
                <p class="auth-card__sub">If an account with that email exists, a reset link is on its way.</p>
            <?php else: ?>
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

                <h1>Forgot password</h1>
                <p class="auth-card__sub">We will email you a password reset link.</p>

                <form method="POST" action="">
                    <div class="field">
                        <label for="email">Email Address <span class="req" aria-hidden="true">*</span></label>
                        <input class="input" type="email" id="email" name="email"
                               value="<?= e($_POST['email'] ?? '') ?>"
                               placeholder="you@example.com" autocomplete="email" required autofocus>
                    </div>

                    <button type="submit" class="btn btn--primary btn--lg btn--block" style="margin-top:.5rem;">
                        <i class="fas fa-paper-plane"></i>
                        Send Reset Link
                    </button>
                </form>
            <?php endif; ?>

            <div class="auth-links" style="margin-top: var(--sp-5);">
                <a href="/login"><i class="fas fa-arrow-left"></i> Back to Login</a>
                <?php if (isRegistrationAllowed()): ?>
                    <a href="/register">Don't have an account? Register</a>
                <?php endif; ?>
            </div>
        </div>
    </main>
</div>


<?php include 'includes/footer.php'; ?>