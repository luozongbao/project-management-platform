<?php
// Issue-033 v1.7.3.2: detect a stale config.php early. If any of
// the constants header.php references are not defined, emit a
// clear "re-upload config.php" message instead of a generic 500.
// This addresses the v1.7.3.0 + v1.7.3.1 regression where the
// prod config.php was not re-uploaded and pages fatalled at the
// APP_VERSION reference. See docs/issues/issue-033.md §3.3.3 and §7.
$__required = ['APP_NAME', 'APP_VERSION', 'TIMEZONE'];
foreach ($__required as $__c) {
    if (!defined($__c)) {
        http_response_code(500);
        echo '<div style="background:#dc3545;color:#fff;padding:30px;font-family:sans-serif;font-size:16px;margin:20px;border-radius:6px;">';
        echo '<h2 style="margin:0 0 10px 0;">&#9888; Configuration error</h2>';
        echo '<p>The required constant <code>' . htmlspecialchars($__c) . '</code> is not defined. This usually means <code>config.php</code> on the server is stale (it was not re-uploaded from <code>config.template.php</code> during a recent deploy).</p>';
        echo '<p><strong>How to fix:</strong> re-upload <code>config.php</code> from <code>config.template.php</code>, fill in the DB and SMTP credentials, and refresh this page.</p>';
        echo '</div>';
        exit(1);
    }
}
unset($__required, $__c);
// Issue-020: send security response headers (CSP, X-Frame-Options,
// X-Content-Type-Options, Referrer-Policy, Permissions-Policy).
// Safe to call from multiple include points — has a static $sent guard.
// The output buffer is opened in startSession() (called earlier by every
// page) so header() can run even though the calling page has already
// echoed a closing PHP tag and a blank line by the time header.php is
// included.
require_once __DIR__ . '/security_headers.php';
sendSecurityHeaders();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <?php // Issue-034 v1.8.0: inline reason editor on the task sidebar
          // POSTs JSON to /ajax/update_status_reason.php. That endpoint
          // validates the CSRF token; we expose it via <meta> so the JS
          // controller can read it without scraping a form input. ?>
    <?php if (function_exists('getCsrfToken')): ?>
        <meta name="csrf-token" content="<?= e(getCsrfToken()) ?>">
    <?php endif; ?>
    <title><?= $title ?? APP_NAME ?></title>
    <link rel="icon" type="image/png" href="/assets/image/project-favicon.png">
    <?php // v2.0: layer 1 of the three-layer CSS model. Must load BEFORE
          // style.css so the component layer can resolve every colour, size,
          // radius, shadow and z-index through a --* custom property.
          // See docs/design/2.0/docs-design-notes.html §1. ?>
    <link rel="stylesheet" href="/assets/css/v2-tokens.css">
    <link rel="stylesheet" href="/assets/css/style.css">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <script src="/assets/js/subtask-manager.js" defer></script>
</head>
<body>
    <?php if (isset($show_nav) && $show_nav && isLoggedIn()): ?>
    <nav class="navbar">
        <div class="nav-brand">
            <i class="fas fa-project-diagram"></i>
            <span><?= APP_NAME ?></span>
        </div>
        <div class="nav-menu">
            <a href="/dashboard" class="nav-link">
                <i class="fas fa-tachometer-alt"></i> Dashboard
            </a>
            <a href="/projects" class="nav-link">
                <i class="fas fa-folder-open"></i> Projects
            </a>
            <a href="/tasks" class="nav-link">
                <i class="fas fa-tasks"></i> Tasks
            </a>
            <a href="/contacts" class="nav-link">
                <i class="fas fa-address-book"></i> Contacts
            </a>
            <details class="app-about" aria-label="About this application">
                <summary class="app-about-trigger" aria-label="About this application" title="About this application">?</summary>
                <div class="app-about-panel" role="dialog" aria-label="About this application">
                    <h3>About</h3>
                    <p>Version: <strong><?= e(APP_VERSION) ?></strong></p>
                    <p>Built by: Me</p>
                    <p>Vibe coding with AI agent (MiniMaxM3)</p>
                    <p class="app-about-release"><?= e(getLatestReleaseSummary()) ?></p>
                    <button type="button" onclick="event.stopPropagation(); this.closest('details').open=false">Close</button>
                </div>
            </details>
            <div class="nav-dropdown">
                <a href="#" class="nav-link dropdown-toggle">
                    <i class="fas fa-user-circle"></i>
                    <?= e(getCurrentUser()['name'] ?? 'User') ?>
                    <i class="fas fa-chevron-down"></i>
                </a>
                <div class="dropdown-menu">
                    <a href="/profile" class="dropdown-item">
                        <i class="fas fa-user-edit"></i> Profile
                    </a>
                    <a href="/logout" class="dropdown-item">
                        <i class="fas fa-sign-out-alt"></i> Logout
                    </a>
                </div>
            </div>
        </div>
    </nav>
    <?php endif; ?>

    <?php // Floating About trigger for logged-out pages (login/register/
          // forgot/reset_password). The navbar above is hidden when the
          // visitor is not signed in, but per issue-028 §4.1 the trigger
          // must still be reachable so anyone can verify what build they
          // are looking at. Anchored bottom-right; uses the same zero-JS
          // <details> pattern so it is CSP-safe. ?>
    <?php if (!isLoggedIn()): ?>
    <details class="app-about app-about--floating" aria-label="About this application">
        <summary class="app-about-trigger" aria-label="About this application" title="About this application">?</summary>
        <div class="app-about-panel" role="dialog" aria-label="About this application">
            <h3>About</h3>
            <p>Version: <strong><?= e(APP_VERSION) ?></strong></p>
            <p>Built by: Me</p>
            <p>Vibe coding with AI agent (MiniMaxM3)</p>
            <p class="app-about-release"><?= e(getLatestReleaseSummary()) ?></p>
            <button type="button" onclick="event.stopPropagation(); this.closest('details').open=false">Close</button>
        </div>
    </details>
    <?php endif; ?>

    <main class="main-content <?= (isset($show_nav) && $show_nav && isLoggedIn()) ? 'with-nav' : 'no-nav' ?>">
        <?= displayMessage() ?>