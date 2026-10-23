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
    <?php // v2.0: layer 2. OPT-IN — loaded only on pages already converted to
          // the v2 shell/markup (issue-035 Phase 1.3+). It deliberately does
          // NOT replace style.css: .form-grid and .table change behaviour,
          // and the cascade-blind regression tests could not catch that.
          // See the header comment in v2-components.css. Set
          // $use_v2_shell = true before including this file to opt in. ?>
    <?php // v2.0 (issue-038): style.css now loads BEFORE the v2 component
          // layer, not after it. Both files define the same selectors at the
          // same specificity (.card, body, .page-header), so source order
          // was silently deciding the winner. With legacy last, legacy won:
          //   - .card   -> background:white + 0 2px 10px rgba(0,0,0,.1)
          //                  instead of --gray-0 + --sh-sm
          //   - body    -> #333 / 'Segoe UI' / #f8f9fa
          //                  instead of --gray-800 / --font-sans / v2 surface
          // The result was washed-out text and flat surfaces on every
          // converted page. Legacy stays in the document (pages not yet on
          // the v2 shell still depend on it entirely); loading it first just
          // gives the v2 layer the final say for v2 pages.
          // Regression tests here are cascade-blind, so this was only ever
          // visible by eye — see the Phase 1.4b screenshot review. ?>
    <link rel="stylesheet" href="/assets/css/style.css">
    <?php if (!empty($use_v2_shell)): ?>
    <link rel="stylesheet" href="/assets/css/v2-components.css">
    <?php endif; ?>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <script src="/assets/js/subtask-manager.js" defer></script>
</head>
<body>
    <?php
    // v2.0 shell (issue-035 Phase 1.3). Mapping per
    // docs/design/2.0/docs-design-notes.html §2 "Shell migration":
    //
    //   .navbar            -> .app-shell + .sidebar + .sidebar__brand
    //   .nav-menu/.nav-link-> .sidebar__nav + .sidebar__link
    //   .nav-dropdown      -> .topbar__actions + .dropdown (Profile/Logout)
    //   .app-about         -> <details> in the topbar
    //   .main-content      -> .content inside .app-main
    //
    // The mockup also draws a global search box and a notification bell in
    // the topbar. Both are DECORATIVE: there is no search endpoint and no
    // notification store anywhere in v1.8.0, and the design notes' shell
    // mapping does not list them. A control that silently does nothing is
    // worse than no control, so they are deliberately omitted rather than
    // shipped dead. Reintroduce them when the backend lands.
    $__v2_shell = !empty($use_v2_shell) && isset($show_nav) && $show_nav && isLoggedIn();
    if ($__v2_shell):
        $__u = getCurrentUser();
        // Initial letters for the sidebar avatar. mb_substr keeps multi-byte
        // names intact; the guard below handles an empty/whitespace name.
        $__name = trim((string)($__u['name'] ?? ''));
        $__init = '';
        foreach (preg_split('/\s+/u', $__name, -1, PREG_SPLIT_NO_EMPTY) as $__w) {
            $__init .= mb_substr($__w, 0, 1);
            if (mb_strlen($__init) >= 2) break;
        }
        if ($__init === '') $__init = '?';
    endif;
    ?>
    <?php if ($__v2_shell): ?>
    <div class="app-shell">
        <aside class="sidebar">
            <div class="sidebar__brand">
                <span class="sidebar__brand-icon"><i class="fas fa-project-diagram"></i></span>
                <span class="sidebar__brand-text"><?= e(APP_NAME) ?><small><?= e(APP_VERSION) ?></small></span>
            </div>
            <nav class="sidebar__nav" aria-label="Main navigation">
                <a class="sidebar__link<?= navIsActive('dashboard') ? ' is-active' : '' ?>" href="/dashboard">
                    <i class="fas fa-tachometer-alt"></i><span>Dashboard</span>
                </a>
                <a class="sidebar__link<?= navIsActive('projects') ? ' is-active' : '' ?>" href="/projects">
                    <i class="fas fa-folder-open"></i><span>Projects</span>
                </a>
                <a class="sidebar__link<?= navIsActive('tasks') ? ' is-active' : '' ?>" href="/tasks">
                    <i class="fas fa-tasks"></i><span>Tasks</span>
                </a>
                <a class="sidebar__link<?= navIsActive('contacts') ? ' is-active' : '' ?>" href="/contacts">
                    <i class="fas fa-address-book"></i><span>Contacts</span>
                </a>
            </nav>
            <div class="sidebar__footer">
                <span class="sidebar__avatar" aria-hidden="true"><?= e(mb_strtoupper($__init)) ?></span>
                <span class="sidebar__user">
                    <span class="sidebar__user-name"><?= e($__name !== '' ? $__name : 'User') ?></span>
                </span>
            </div>
        </aside>
        <div class="app-main">
            <header class="topbar">
                <div class="topbar__actions" style="margin-left:auto">
                    <?php // The navbar About <details> moves here verbatim —
                          // same markup, same zero-JS <details> pattern, same
                          // stopPropagation() guard on Close (tester-note-002
                          // bug 6). about_popup_test.php pins this block. ?>
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
                    <details class="dropdown">
                        <summary class="topbar__icon-btn" style="list-style:none" aria-label="Account menu">
                            <span class="avatar"><?= e(mb_strtoupper($__init)) ?></span>
                        </summary>
                        <div class="dropdown__menu" role="menu">
                            <a class="dropdown__item" href="/profile" role="menuitem">
                                <i class="fas fa-user-edit"></i> Profile
                            </a>
                            <a class="dropdown__item" href="/logout" role="menuitem">
                                <i class="fas fa-sign-out-alt"></i> Logout
                            </a>
                        </div>
                    </details>
                </div>
            </header>
    <?php endif; ?>

    <?php // Floating About trigger for logged-out pages (login/register/
          // forgot/reset_password/install). The shell above is hidden when
          // the visitor is not signed in, but per issue-028 §4.1 the trigger
          // must still be reachable so anyone can verify what build they are
          // looking at. Anchored bottom-right; uses the same zero-JS
          // <details> pattern so it is CSP-safe.
          //
          // This is NOT optional: the logged-in topbar <details> above and
          // this one are mutually exclusive by isLoggedIn(), so exactly one
          // renders on any given page — but BOTH must exist in the source.
          // about_popup_test.php asserts two <details class="app-about">
          // blocks and two stopPropagation() Close buttons (tester-note-002
          // bug 6). Deleting this block silently breaks that contract. ?>
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

    <?php // Logged-out pages (login/register/forgot/reset/install) get no
          // sidebar and no topbar — a shell with no navigation items would be
          // chrome around a single form. They render bare .content exactly as
          // they did in v1.8.0, and the floating About <details> below stays
          // reachable per issue-028 §4.1.
          //
          // $content_bleed lets a page opt out of .content's padding and
          // max-width for a full-bleed layout (the v2 auth screens). Set it to
          // true before including this file. Deliberately NOT implemented with
          // a `:has()` selector: that would make every full-bleed page depend
          // on the newest CSS feature in the browser matrix. ?>
    <main class="content<?= !empty($content_bleed) ? ' content--bleed' : '' ?>">
        <?= isset($title) && $title !== '' ? '<h1 class="sr-only">' . e($title) . '</h1>' : '' ?>
        <?= displayMessage() ?>