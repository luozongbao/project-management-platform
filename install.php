<?php
/**
 * Installation Script for Project Management System
 *
 * Issue-021: hardened. Changes vs. the original:
 *   1. session_start() is called at the top of the file (not just on
 *      step 3) so the CSRF token persists across the multi-step flow.
 *   2. The wizard is gated by a two-stage check: config.php present
 *      OR the `users` table already exists in the configured DB.
 *   3. Database name is validated against a strict regex
 *      (letters / digits / underscore only) before being interpolated
 *      into SQL or the DSN.
 *   4. CSRF token is required on every mutating POST (step 2 + step 3)
 *      and a hidden csrf_token input is rendered in every form.
 *   5. config.php is written via var_export() (produces valid PHP
 *      literal syntax) instead of addslashes().
 *   6. APP_URL is derived from $_SERVER['SERVER_NAME'] (set by Nginx
 *      from the server_name directive, NOT user-controllable) plus
 *      SERVER_PORT, NEVER from $_SERVER['HTTP_HOST'].
 *   7. Catch blocks log the raw PDO message server-side but show a
 *      generic, non-leaking error to the user.
 */

require_once __DIR__ . '/includes/functions.php';

// Start the session up front so the CSRF token is available to all
// three steps and persists across step redirects.
startSession();

// --- Gate -----------------------------------------------------------------
// The wizard's only effective gate is `config.php` existing. A "stage
// 2" probe against the live DB (e.g. SHOW TABLES LIKE 'users') is
// unreachable here because the wizard does NOT load config.php — its
// job is to CREATE config.php — so the DB_* constants are never
// defined and any DB probe is dead code. An earlier draft of this
// block wrapped the probe in `if (defined('DB_HOST') && ...)` but
// that condition is always false on a wizard hit. (See
// docs/issues/issue-021/tester-report.md Deviation 1 for the
// reproduction; the spec's stage-2 design is acknowledged as not
// implementable without a separate marker file, which is out of
// scope for this issue.)
if (file_exists(__DIR__ . '/config.php')) {
    header('Location: index.php');
    exit();
}
// -------------------------------------------------------------------------

$step = $_GET['step'] ?? 1;
$errors = [];
$success = false;

// --- Helper: build the APP_URL from server-controlled values only ----------
function install_build_app_url() {
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $port   = (int)($_SERVER['SERVER_PORT'] ?? 80);
    // SERVER_NAME is set by the web server (Nginx server_name) and is
    // NOT user-controllable. Fall back to 'localhost' for CLI / CGI
    // contexts where it is absent.
    $host   = $_SERVER['SERVER_NAME'] ?? 'localhost';
    $port_part = ($port === 80 || $port === 443) ? '' : ':' . $port;
    $path   = rtrim(dirname($_SERVER['PHP_SELF'] ?? ''), '/');
    return $scheme . '://' . $host . $port_part . $path;
}

// --- Step 2: Process database configuration -------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && (int)$step === 2) {
    // CSRF gate (issue-021 §4). A third-party site must not be able
    // to force a victim to re-run the install.
    if (!validateCsrfToken($_POST['csrf_token'] ?? null)) {
        $errors[] = 'Your session could not be verified. '
                  . 'Please reload the page and submit again.';
    } else {

    $db_host = trim($_POST['db_host'] ?? '');
    $db_name = trim($_POST['db_name'] ?? '');
    $db_user = trim($_POST['db_user'] ?? '');
    $db_pass = $_POST['db_pass'] ?? '';

    // Validate database inputs
    if (empty($db_host)) {
        $errors[] = "Database host is required";
    }
    if (empty($db_name)) {
        $errors[] = "Database name is required";
    } else {
        // Issue-021 §1: strict regex. Rejects SQL metacharacters that
        // the backtick-quoting was (accidentally) absorbing, and
        // rejects hyphenated names that the DSN cannot round-trip.
        if (!preg_match('/^[A-Za-z0-9_]+$/', $db_name)) {
            $errors[] = "Database name may only contain letters, digits, and underscores.";
        }
    }
    if (empty($db_user)) {
        $errors[] = "Database user is required";
    }

    if (empty($errors)) {
        // Test database connection and create database if needed
        try {
            $pdo = new PDO("mysql:host=$db_host;charset=utf8mb4", $db_user, $db_pass);
            $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

            // Create database if it doesn't exist
            $pdo->exec("CREATE DATABASE IF NOT EXISTS `$db_name` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
            $pdo->exec("USE `$db_name`");

            // Execute schema
            $schema = file_get_contents('database/schema.sql');
            $pdo->exec($schema);

            // Store database config in session for next step
            $_SESSION['db_config'] = [
                'host' => $db_host,
                'name' => $db_name,
                'user' => $db_user,
                'pass' => $db_pass
            ];

            header('Location: install.php?step=3');
            exit();

        } catch (PDOException $e) {
            // Issue-021: log the underlying PDO error server-side but
            // do NOT echo it to the user (info disclosure).
            error_log("Install wizard DB step failed: " . $e->getMessage());
            $errors[] = "Database connection failed. Please check the host, "
                      . "name, user, and password, then try again. "
                      . "If the problem persists, contact your administrator.";
        }
    }

    } // end else (!validateCsrfToken failed)
}

// --- Step 3: Process SMTP configuration and complete installation -------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && (int)$step === 3) {
    // CSRF gate.
    if (!validateCsrfToken($_POST['csrf_token'] ?? null)) {
        $errors[] = 'Your session could not be verified. '
                  . 'Please reload the page and submit again.';
    } else {

    $smtp_host = trim($_POST['smtp_host'] ?? '');
    $smtp_port_raw = trim($_POST['smtp_port'] ?? '');
    $smtp_protocol = trim($_POST['smtp_protocol'] ?? '');
    $smtp_user = trim($_POST['smtp_user'] ?? '');
    $smtp_pass = $_POST['smtp_pass'] ?? '';

    // Validate SMTP inputs
    if (empty($smtp_host)) {
        $errors[] = "SMTP host is required";
    }
    if ($smtp_port_raw === '' || !ctype_digit($smtp_port_raw)) {
        $errors[] = "Valid SMTP port is required";
    }
    if (empty($smtp_protocol)) {
        $errors[] = "SMTP protocol is required";
    }
    if (empty($smtp_user)) {
        $errors[] = "SMTP user is required";
    }
    if (empty($smtp_pass)) {
        $errors[] = "SMTP password is required";
    }

    if (empty($errors) && isset($_SESSION['db_config'])) {
        $db_config = $_SESSION['db_config'];
        $smtp_port = (int)$smtp_port_raw;
        $app_url   = install_build_app_url();

        // Issue-028: APP_VERSION is the single source of truth for the
        // version string rendered in the in-app About popup (the `?` icon
        // in the navbar on the internal app, the `?` icon in the header
        // on the client portal). Bump $current_version by hand on each
        // release as part of the docs/RELEASE_PROCESS.md checklist; the
        // drift guard in database/about_popup_test.php will fail the
        // build if this literal drifts away from config.php /
        // config.template.php.
        $current_version = 'v1.8.0';

        // Issue-021 §2: var_export() produces a valid PHP literal for
        // the value. Far safer than addslashes() against hostile
        // backslash / quote sequences.
        $config_content = "<?php\n"
            . "// Auto-generated by install.php. Safe to edit.\n"
            . "const DB_HOST     = " . var_export($db_config['host'], true) . ";\n"
            . "const DB_NAME     = " . var_export($db_config['name'], true) . ";\n"
            . "const DB_USER     = " . var_export($db_config['user'], true) . ";\n"
            . "const DB_PASS     = " . var_export($db_config['pass'], true) . ";\n"
            . "\n"
            . "const SMTP_HOST     = " . var_export($smtp_host, true) . ";\n"
            . "const SMTP_PORT     = " . $smtp_port . ";\n"
            . "const SMTP_PROTOCOL = " . var_export($smtp_protocol, true) . ";\n"
            . "const SMTP_USER     = " . var_export($smtp_user, true) . ";\n"
            . "const SMTP_PASS     = " . var_export($smtp_pass, true) . ";\n"
            . "\n"
            . "const APP_NAME    = 'Project Management System';\n"
            . "const APP_VERSION = " . var_export($current_version, true) . ";\n"
            . "const APP_URL     = " . var_export($app_url, true) . ";\n"
            . "const TIMEZONE = 'UTC';\n"
            . "\n"
            . "const SESSION_LIFETIME      = 3600;\n"
            . "const PASSWORD_RESET_EXPIRY = 3600;\n"
            . "const ENCRYPTION_KEY        = " . var_export(bin2hex(random_bytes(32)), true) . ";\n"
            . "\n"
            . "const LOGIN_FAILURE_LIMIT   = 5;\n"
            . "const LOGIN_FAILURE_WINDOW  = 900;\n"
            . "const LOGIN_IP_LIMIT        = 30;\n"
            . "const LOGIN_IP_WINDOW       = 900;\n";

        if (file_put_contents(__DIR__ . '/config.php', $config_content)) {
            session_destroy();
            $success = true;
        } else {
            error_log("Install wizard: file_put_contents(config.php) failed");
            $errors[] = "Failed to create config.php file. Check file permissions "
                      . "on the application directory.";
        }
    }

    } // end else (!validateCsrfToken failed)
}

// Pre-render the CSRF token so it is in scope for every form below.
$csrf_token = getCsrfToken();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Install - Project Management System</title>
    <link rel="icon" type="image/png" href="/assets/image/project-favicon.png">
    <link rel="stylesheet" href="<?= e(asset_url('/assets/css/v2-tokens.css')) ?>">
    <link rel="stylesheet" href="<?= e(asset_url('/assets/css/style.css')) ?>">
    <link rel="stylesheet" href="<?= e(asset_url('/assets/css/v2-components.css')) ?>">
    <link rel="stylesheet" href="<?= e(asset_url('/assets/css/pages/install.css')) ?>">
</head>
<body class="install-body">
    <div class="install-shell">
        <div class="install-card">
            <div class="install-card__logo"><i class="fas fa-cogs"></i></div>
            <h1>Project Management System Installation</h1>
            <p class="install-card__sub">Follow the steps below to configure the database and email server.</p>

            <ol class="stepper" aria-label="Installation progress">
                <li class="stepper__step<?= $step >= 1 ? ' is-active' : '' ?><?= $step > 1 ? ' is-completed' : '' ?>">
                    <span class="stepper__dot"><?= $step > 1 ? '<i class="fas fa-check"></i>' : '1' ?></span>
                    <span class="stepper__label">Welcome</span>
                </li>
                <li class="stepper__step<?= $step >= 2 ? ' is-active' : '' ?><?= $step > 2 ? ' is-completed' : '' ?>">
                    <span class="stepper__dot"><?= $step > 2 ? '<i class="fas fa-check"></i>' : '2' ?></span>
                    <span class="stepper__label">Database</span>
                </li>
                <li class="stepper__step<?= $step >= 3 ? ' is-active' : '' ?><?= $step > 3 ? ' is-completed' : '' ?>">
                    <span class="stepper__dot"><?= $step > 3 ? '<i class="fas fa-check"></i>' : '3' ?></span>
                    <span class="stepper__label">Email</span>
                </li>
            </ol>

            <?php if (!empty($errors)): ?>
                <div class="alert alert--danger" role="alert">
                    <i class="fas fa-circle-exclamation"></i>
                    <div class="alert__body">
                        <div class="alert__title">Installation could not continue</div>
                        <ul class="alert__list">
                            <?php foreach ($errors as $error): ?>
                                <li><?= e($error) ?></li>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                </div>
            <?php endif; ?>

            <?php if ($success): ?>
                <div class="alert alert--success" role="alert">
                    <i class="fas fa-circle-check"></i>
                    <div class="alert__body">
                        <div class="alert__title">Installation Complete!</div>
                        <p>Your Project Management System has been successfully installed.</p>
                        <p class="install-next"><strong>Next Steps:</strong></p>
                        <ol class="install-next-list">
                            <li>Delete the install.php file for security</li>
                            <li>Create your first user account</li>
                            <li>Start managing your projects!</li>
                        </ol>
                        <p><a href="index.php" class="btn btn--primary btn--block">Go to Application</a></p>
                    </div>
                </div>
            <?php elseif ($step == 1): ?>
                <h2 class="install-card__h2">Step 1: Welcome</h2>
                <p>Welcome to the Project Management System installation wizard. This process will:</p>
                <ul class="install-list">
                    <li><i class="fas fa-circle-check"></i> Configure your database connection</li>
                    <li><i class="fas fa-circle-check"></i> Create the necessary database tables</li>
                    <li><i class="fas fa-circle-check"></i> Set up your email configuration</li>
                    <li><i class="fas fa-circle-check"></i> Generate your configuration file</li>
                </ul>

                <div class="install-panel">
                    <div class="install-panel__title"><i class="fas fa-list-check"></i> Requirements</div>
                    <ul class="install-list install-list--compact">
                        <li><i class="fas fa-circle-check"></i> PHP 8.3 or higher</li>
                        <li><i class="fas fa-circle-check"></i> MySQL/MariaDB database</li>
                        <li><i class="fas fa-circle-check"></i> SMTP server for email notifications</li>
                        <li><i class="fas fa-circle-check"></i> Write permissions on the application directory</li>
                    </ul>
                </div>

                <a href="install.php?step=2" class="btn btn--primary btn--lg btn--block">
                    <i class="fas fa-arrow-right"></i> Start Installation
                </a>

            <?php elseif ($step == 2): ?>
                <h2 class="install-card__h2">Step 2: Database Configuration</h2>
                <form method="POST">
                    <input type="hidden" name="csrf_token" value="<?= e($csrf_token) ?>">
                    <div class="form-grid form-grid--2">
                        <div class="field">
                            <label for="db_host">Database Host <span class="req">*</span></label>
                            <input class="input" type="text" id="db_host" name="db_host" value="<?= e($_POST['db_host'] ?? 'localhost') ?>" required>
                        </div>

                        <div class="field">
                            <label for="db_name">Database Name <span class="req">*</span></label>
                            <input class="input" type="text" id="db_name" name="db_name" value="<?= e($_POST['db_name'] ?? 'project_management') ?>" required>
                            <span class="hint">Letters, digits, and underscores only.</span>
                        </div>

                        <div class="field">
                            <label for="db_user">Database User <span class="req">*</span></label>
                            <input class="input" type="text" id="db_user" name="db_user" value="<?= e($_POST['db_user'] ?? '') ?>" required>
                        </div>

                        <div class="field">
                            <label for="db_pass">Database Password</label>
                            <input class="input" type="password" id="db_pass" name="db_pass" value="<?= e($_POST['db_pass'] ?? '') ?>">
                        </div>
                    </div>

                    <button type="submit" class="btn btn--primary btn--lg btn--block">
                        <i class="fas fa-database"></i> Test Database &amp; Continue
                    </button>
                </form>

            <?php elseif ($step == 3): ?>
                <h2 class="install-card__h2">Step 3: SMTP Configuration</h2>
                <p>Configure your email server settings for password reset emails and notifications.</p>

                <form method="POST">
                    <input type="hidden" name="csrf_token" value="<?= e($csrf_token) ?>">
                    <div class="form-grid form-grid--2">
                        <div class="field">
                            <label for="smtp_host">SMTP Host <span class="req">*</span></label>
                            <input class="input" type="text" id="smtp_host" name="smtp_host" value="<?= e($_POST['smtp_host'] ?? 'smtp.gmail.com') ?>" required>
                        </div>

                        <div class="field">
                            <label for="smtp_port">SMTP Port <span class="req">*</span></label>
                            <input class="input" type="number" id="smtp_port" name="smtp_port" value="<?= e($_POST['smtp_port'] ?? '587') ?>" required>
                        </div>

                        <div class="field">
                            <label for="smtp_protocol">SMTP Protocol <span class="req">*</span></label>
                            <select class="select" id="smtp_protocol" name="smtp_protocol" required>
                                <option value="tls" <?= ($_POST['smtp_protocol'] ?? 'tls') == 'tls' ? 'selected' : '' ?>>TLS</option>
                                <option value="ssl" <?= ($_POST['smtp_protocol'] ?? '') == 'ssl' ? 'selected' : '' ?>>SSL</option>
                            </select>
                        </div>

                        <div class="field">
                            <label for="smtp_user">SMTP Username (Email) <span class="req">*</span></label>
                            <input class="input" type="email" id="smtp_user" name="smtp_user" value="<?= e($_POST['smtp_user'] ?? '') ?>" required>
                        </div>

                        <div class="field form-section">
                            <label for="smtp_pass">SMTP Password <span class="req">*</span></label>
                            <input class="input" type="password" id="smtp_pass" name="smtp_pass" value="<?= e($_POST['smtp_pass'] ?? '') ?>" required>
                        </div>
                    </div>

                    <button type="submit" class="btn btn--primary btn--lg btn--block">
                        <i class="fas fa-circle-check"></i> Complete Installation
                    </button>
                </form>
            <?php endif; ?>
        </div>
    </div>

    <script src="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/js/all.min.js"></script>
</body>
</html>
