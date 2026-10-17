<?php
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
    <title><?= $title ?? APP_NAME ?></title>
    <link rel="stylesheet" href="assets/css/style.css">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <script src="assets/js/subtask-manager.js" defer></script>
</head>
<body>
    <?php if (isset($show_nav) && $show_nav && isLoggedIn()): ?>
    <nav class="navbar">
        <div class="nav-brand">
            <i class="fas fa-project-diagram"></i>
            <span><?= APP_NAME ?></span>
        </div>
        <div class="nav-menu">
            <a href="dashboard.php" class="nav-link">
                <i class="fas fa-tachometer-alt"></i> Dashboard
            </a>
            <a href="projects.php" class="nav-link">
                <i class="fas fa-folder-open"></i> Projects
            </a>
            <a href="tasks.php" class="nav-link">
                <i class="fas fa-tasks"></i> Tasks
            </a>
            <a href="contacts.php" class="nav-link">
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
                    <a href="profile.php" class="dropdown-item">
                        <i class="fas fa-user-edit"></i> Profile
                    </a>
                    <a href="logout.php" class="dropdown-item">
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