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
        $current_version = 'v1.7.2.1';

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
    <link rel="stylesheet" href="assets/css/style.css">
    <style>
        .install-container {
            max-width: 600px;
            margin: 50px auto;
            padding: 30px;
            background: white;
            border-radius: 10px;
            box-shadow: 0 0 20px rgba(0,0,0,0.1);
        }
        .step-indicator {
            display: flex;
            justify-content: center;
            margin-bottom: 30px;
        }
        .step {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 10px;
            background: #e9ecef;
            color: #6c757d;
        }
        .step.active {
            background: #007bff;
            color: white;
        }
        .step.completed {
            background: #28a745;
            color: white;
        }
        .form-group {
            margin-bottom: 20px;
        }
        .form-group label {
            display: block;
            margin-bottom: 5px;
            font-weight: 500;
        }
        .form-group input, .form-group select {
            width: 100%;
            padding: 10px;
            border: 1px solid #ddd;
            border-radius: 5px;
            box-sizing: border-box;
        }
        .btn {
            background: #007bff;
            color: white;
            padding: 12px 30px;
            border: none;
            border-radius: 5px;
            cursor: pointer;
            text-decoration: none;
            display: inline-block;
        }
        .btn:hover {
            background: #0056b3;
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
    </style>
</head>
<body>
    <div class="install-container">
        <h1 style="text-align: center; color: #333; margin-bottom: 30px;">
            <i class="fas fa-cogs"></i> Project Management System Installation
        </h1>

        <div class="step-indicator">
            <div class="step <?= $step >= 1 ? 'active' : '' ?> <?= $step > 1 ? 'completed' : '' ?>">1</div>
            <div class="step <?= $step >= 2 ? 'active' : '' ?> <?= $step > 2 ? 'completed' : '' ?>">2</div>
            <div class="step <?= $step >= 3 ? 'active' : '' ?> <?= $step > 3 ? 'completed' : '' ?>">3</div>
        </div>

        <?php if (!empty($errors)): ?>
            <div class="alert alert-danger">
                <ul style="margin: 0; padding-left: 20px;">
                    <?php foreach ($errors as $error): ?>
                        <li><?= htmlspecialchars($error) ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
        <?php endif; ?>

        <?php if ($success): ?>
            <div class="alert alert-success">
                <h3>Installation Complete!</h3>
                <p>Your Project Management System has been successfully installed.</p>
                <p><strong>Next Steps:</strong></p>
                <ol>
                    <li>Delete the install.php file for security</li>
                    <li>Create your first user account</li>
                    <li>Start managing your projects!</li>
                </ol>
                <p><a href="index.php" class="btn">Go to Application</a></p>
            </div>
        <?php elseif ($step == 1): ?>
            <h2>Step 1: Welcome</h2>
            <p>Welcome to the Project Management System installation wizard. This process will:</p>
            <ul>
                <li>Configure your database connection</li>
                <li>Create the necessary database tables</li>
                <li>Set up your email configuration</li>
                <li>Generate your configuration file</li>
            </ul>
            <p><strong>Requirements:</strong></p>
            <ul>
                <li>PHP 8.3 or higher</li>
                <li>MySQL/MariaDB database</li>
                <li>SMTP server for email notifications</li>
                <li>Write permissions on the application directory</li>
            </ul>
            <br>
            <a href="install.php?step=2" class="btn">Start Installation</a>

        <?php elseif ($step == 2): ?>
            <h2>Step 2: Database Configuration</h2>
            <form method="POST">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token, ENT_QUOTES, 'UTF-8') ?>">
                <div class="form-group">
                    <label for="db_host">Database Host:</label>
                    <input type="text" id="db_host" name="db_host" value="<?= htmlspecialchars($_POST['db_host'] ?? 'localhost') ?>" required>
                </div>

                <div class="form-group">
                    <label for="db_name">Database Name:</label>
                    <input type="text" id="db_name" name="db_name" value="<?= htmlspecialchars($_POST['db_name'] ?? 'project_management') ?>" required>
                </div>

                <div class="form-group">
                    <label for="db_user">Database User:</label>
                    <input type="text" id="db_user" name="db_user" value="<?= htmlspecialchars($_POST['db_user'] ?? '') ?>" required>
                </div>

                <div class="form-group">
                    <label for="db_pass">Database Password:</label>
                    <input type="password" id="db_pass" name="db_pass" value="<?= htmlspecialchars($_POST['db_pass'] ?? '') ?>">
                </div>

                <button type="submit" class="btn">Test Database & Continue</button>
            </form>

        <?php elseif ($step == 3): ?>
            <h2>Step 3: SMTP Configuration</h2>
            <p>Configure your email server settings for password reset emails and notifications.</p>

            <form method="POST">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token, ENT_QUOTES, 'UTF-8') ?>">
                <div class="form-group">
                    <label for="smtp_host">SMTP Host:</label>
                    <input type="text" id="smtp_host" name="smtp_host" value="<?= htmlspecialchars($_POST['smtp_host'] ?? 'smtp.gmail.com') ?>" required>
                </div>

                <div class="form-group">
                    <label for="smtp_port">SMTP Port:</label>
                    <input type="number" id="smtp_port" name="smtp_port" value="<?= htmlspecialchars($_POST['smtp_port'] ?? '587') ?>" required>
                </div>

                <div class="form-group">
                    <label for="smtp_protocol">SMTP Protocol:</label>
                    <select id="smtp_protocol" name="smtp_protocol" required>
                        <option value="tls" <?= ($_POST['smtp_protocol'] ?? 'tls') == 'tls' ? 'selected' : '' ?>>TLS</option>
                        <option value="ssl" <?= ($_POST['smtp_protocol'] ?? '') == 'ssl' ? 'selected' : '' ?>>SSL</option>
                    </select>
                </div>

                <div class="form-group">
                    <label for="smtp_user">SMTP Username (Email):</label>
                    <input type="email" id="smtp_user" name="smtp_user" value="<?= htmlspecialchars($_POST['smtp_user'] ?? '') ?>" required>
                </div>

                <div class="form-group">
                    <label for="smtp_pass">SMTP Password:</label>
                    <input type="password" id="smtp_pass" name="smtp_pass" value="<?= htmlspecialchars($_POST['smtp_pass'] ?? '') ?>" required>
                </div>

                <button type="submit" class="btn">Complete Installation</button>
            </form>
        <?php endif; ?>
    </div>

    <script src="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/js/all.min.js"></script>
</body>
</html>
