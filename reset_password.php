<?php
require_once 'config.php';
require_once 'includes/Database.php';
require_once 'includes/functions.php';

startSession();

// Redirect if already logged in.
// Absolute path so it resolves the same way no matter which URL the
// user landed on.
if (isLoggedIn()) {
    header('Location: /dashboard');
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

// issue-055 bug 4 (same class as forgot_password.php): these flags MUST
// be assigned in PHP mode. A stray PHP close tag before them closed
// PHP early, so the two assignments below were echoed into the HTML as
// literal text instead of being executed. The v2 component layer
// (v2-components.css) is only linked when $use_v2_shell is truthy, so
// the auth page rendered without it: .field lost its column layout
// (the label sat inline beside a narrow input) and .input lost its
// full-width rule. Keep this block in PHP mode -- login.php,
// register.php and forgot_password.php set the same flags correctly.
//
// NOTE: never write a literal PHP close tag inside a comment -- it
// terminates PHP mode and the rest of the comment leaks into the
// HTML as literal text.
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
            <h2 class="auth-aside__headline">Choose a new<br>password.</h2>
            <p class="auth-aside__sub">Pick something you have not used before. You will use it every time you sign in.</p>
            <ul class="auth-aside__points">
                <li><i class="fas fa-circle-check"></i><span>At least 8 characters, including a letter and a number</span></li>
                <li><i class="fas fa-circle-check"></i><span>Reset links expire after one hour</span></li>
                <li><i class="fas fa-circle-check"></i><span>Your session stays signed out until you sign in again</span></li>
            </ul>
        </div>
        <div class="auth-aside__foot"><?= APP_VERSION ?> &middot; <?= APP_NAME ?></div>
    </aside>

    <main class="auth-main">
        <div class="auth-card">
            <?php if ($success): ?>
                <h1>Password Reset Successful!</h1>
                <p class="auth-card__sub">Your password has been successfully reset.</p>

                <div class="alert alert--success" role="alert">
                    <i class="fas fa-circle-check"></i>
                    <div class="alert__body">
                        <div class="alert__title">All set</div>
                        <p>You can now log in with your new password.</p>
                        <p style="margin-top: var(--sp-3);">
                            <a href="/login" class="btn btn--primary">Go to Login</a>
                        </p>
                    </div>
                </div>
            <?php elseif (!$valid_token): ?>
                <h1>Invalid Reset Link</h1>
                <p class="auth-card__sub">This password reset link is invalid or has expired.</p>

                <div class="alert alert--danger" role="alert">
                    <i class="fas fa-circle-exclamation"></i>
                    <div class="alert__body">
                        <div class="alert__title">Link no longer valid</div>
                        <p>Reset links are only valid for 1 hour.</p>
                        <p style="margin-top: var(--sp-3);">
                            <a href="/forgot-password" class="btn btn--primary">Request New Link</a>
                        </p>
                    </div>
                </div>
            <?php else: ?>
                <h1>Set new password</h1>
                <p class="auth-card__sub">You are resetting the password for:</p>

                <div class="auth-reset-info">
                    <p><strong><?= e($reset_data['name']) ?></strong></p>
                    <p><?= e($reset_data['email']) ?></p>
                </div>

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
                        <label for="password">New Password</label>
                        <input class="input" type="password" id="password" name="password"
                               minlength="6" required>
                        <span class="hint">Must be at least 6 characters long</span>
                    </div>

                    <div class="field">
                        <label for="confirm_password">Confirm New Password</label>
                        <input class="input" type="password" id="confirm_password" name="confirm_password"
                               minlength="6" required>
                    </div>

                    <button type="submit" class="btn btn--primary btn--lg btn--block">
                        <i class="fas fa-check"></i>
                        Reset Password
                    </button>
                </form>
            <?php endif; ?>

            <div class="auth-links" style="margin-top: var(--sp-5);">
                <a href="/login"><i class="fas fa-arrow-left"></i> Back to Login</a>
            </div>
        </div>
    </main>
</div>


<script>
// Password confirmation validation.
//
// The form fields only exist when the token is valid. When the link is
// invalid or expired the page renders the error branch instead, and
// that branch has no #password / #confirm_password inputs. The
// listener must therefore be null-guarded -- otherwise this throws
// "Cannot read properties of null (reading 'addEventListener')" on
// every bad link, and the console error is logged on the page the
// user is actually looking at.
document.addEventListener('DOMContentLoaded', function() {
    const password = document.getElementById('password');
    const confirmPassword = document.getElementById('confirm_password');

    // No form on this page (bad or expired link, or the post-reset
    // success block). Nothing to validate.
    if (!password || !confirmPassword) {
        return;
    }

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