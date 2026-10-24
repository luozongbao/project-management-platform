<?php
/**
 * Client dashboard — public, read-only, code-keyed.
 *
 * Looks up the project via ?code=… and renders a snapshot of:
 *   project info (name, scope, budget, start/expected/completion dates, status)
 *   overall completion percentage
 *   top-level tasks + recursive subtasks, with their statuses and percentages
 *
 * No writes, no login, no edit actions. Backed by the same DB as the
 * project-management app — but accessible to anyone with the share code.
 *
 * Localization: ENG (default) / 中文, via ?lang=, cookie, or Accept-Language.
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/Database.php';
require_once __DIR__ . '/../includes/functions.php';
// Issue-029: client/lang.php MUST be loaded ABOVE the security-headers
// emission so the t() helper is defined before any future t() call that
// might happen during header rendering. Also keeps the partial-deploy
// "client/lang.php missing about.* keys" failure mode off the critical
// 500 path — the page is allowed to fall through to its own lang
// defaults if the require fails (see below).
require_once __DIR__ . '/lang.php';

// Issue-020: tightened CSP for the public client portal — no
// 'unsafe-inline' for script-src because the toggle JS is in an
// external file and there are no inline event handlers on this page.
// Issue-029 v1.7.2.2: also allows https://static.cloudflareinsights.com
// in script-src so the Cloudflare Web Analytics beacon (auto-injected at
// the edge when the zone has Web Analytics enabled) does not produce
// a CSP violation in the browser console. See docs/issues/issue-029.md.
require_once __DIR__ . '/../includes/security_headers.php';
sendClientPortalSecurityHeaders();

// Issue-029 v1.7.2.2: open an output buffer so any stray output (PHP
// notice, stray whitespace, an opcache warning) cannot turn a future
// header() call into a "headers already sent" 500. The buffer is
// flushed automatically at script exit. Belt-and-braces against the
// "partial deploy" / "headers already sent" class of bug that caused
// the v1.7.2 prod 500 reported in issue-029.
ob_start();

// Issue-029 v1.7.2.2 deploy marker — confirms the new file actually
// ran on prod (vs an opcache-served old version). The dev greps the
// prod error log for "[v1.7.2.2 deploy]" after a deploy to confirm.
error_log('[v1.7.2.2 deploy] client/dashboard.php loaded from ' . __FILE__);

// Persist ?lang= into the cookie so subsequent navigations remember it.
if (isset($_GET['lang']) && in_array($_GET['lang'], client_supported_langs(), true)) {
    client_set_lang_cookie($_GET['lang']);
}
$lang = client_current_lang();

$db = Database::getInstance();

// Issue-023: rate-limit the public client portal by client IP. We
// also accept POST so the index.php "Find by Code" form can be
// rate-limited symmetrically. The IP is REMOTE_ADDR, never
// X-Forwarded-For, so the limit cannot be spoofed by setting a
// client header.
$clientIp = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
$code = strtoupper(trim($_GET['code'] ?? $_POST['code'] ?? ''));
$error = '';

if ($code === '') {
    // Issue-029 v1.7.2.2: defensive against "headers already sent" if a
    // future change ever emits output before this point. The
    // ob_start() above already covers this in 99% of cases; the
    // headers_sent() guard here is a belt-and-braces safety net so a
    // future regression cannot 500 the page.
    if (!headers_sent()) {
        header('Location: /');
    } else {
        // Fall back to a meta-refresh redirect so a stray echo or
        // whitespace before this point cannot turn the page into a
        // 500. The browser will follow the meta-refresh on receipt.
        echo '<!DOCTYPE html><html><head>';
        echo '<meta charset="UTF-8">';
        echo '<meta http-equiv="refresh" content="0;url=index.php">';
        echo '<title>Redirecting…</title></head><body>';
        echo 'Redirecting to <a href="index.php">index.php</a>…';
        echo '</body></html>';
    }
    exit();
}

// Issue-023: per-IP rate limit fires BEFORE the format check so a
// brute-force script that sprays random strings still hits the
// 429 wall. A 429 records one failure so the next iteration of
// the same attacker is still counted even if the limit was just
// not hit.
if (isShareCodeRateLimited($clientIp)) {
    recordShareCodeAttempt($clientIp, $code, null, false);
    http_response_code(429);
    ?>
    <!DOCTYPE html>
    <html lang="<?= e($lang) ?>">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>Too many requests — <?= e(APP_NAME) ?></title>
        <link rel="icon" type="image/png" href="/assets/image/project-favicon.png">
        <link rel="stylesheet" href="<?= e(asset_url('/assets/css/v2-tokens.css')) ?>">
        <link rel="stylesheet" href="<?= e(asset_url('/assets/css/style.css')) ?>">
        <?php /* Issue-050: layer 2.5 — the shared v2 component layer. The client
                portal is not served through includes/header.php, so it has to
                opt in explicitly. Same order as the internal app: tokens ->
                legacy base -> v2 components -> page CSS, so the client page
                CSS gets the final say at equal specificity. */ ?>
        <link rel="stylesheet" href="<?= e(asset_url('/assets/css/v2-components.css')) ?>">
        <link rel="stylesheet" href="<?= e(asset_url('/client/assets/client.css')) ?>">
    </head>
    <body class="client-body">
    <main class="client-content" style="max-width: 560px; padding-top: var(--sp-10);">
        <div class="card">
            <div class="card__body">
                <div class="client-brand">
                    <i class="fas fa-project-diagram"></i>
                    <h1><?= e(APP_NAME) ?></h1>
                </div>
                <div class="alert alert--danger">
                    <h2><i class="fas fa-ban"></i> Too many requests</h2>
                    <p>Please try again later.</p>
                </div>
                <p><a href="index.php" class="btn btn--secondary"><i class="fas fa-arrow-left"></i> <?= e(t('landing.try_again')) ?></a></p>
            </div>
        </div>
    </main>
    </body>
    </html>
    <?php
    exit();
}

// Strict format check; if it doesn't even look right, send back to landing.
if (!preg_match('/^[A-Z2-9]{5}-[A-Z2-9]{5}-[A-Z2-9]{5}$/', $code)) {
    recordShareCodeAttempt($clientIp, $code, null, false);
    $error = t('err.bad_format_dash');
}

if (!$error) {
    // Project + stats (project_stats view gives us counts + avg %).
    $project = $db->fetchOne(
        "SELECT p.id, p.name, p.description, p.scope, p.budget_amount, p.currency,
                p.status, p.responsible_person_id, p.expected_completion_date,
                p.start_date, p.completion_date, p.created_at, p.share_code,
                ps.total_tasks, ps.completed_tasks, ps.uncompleted_tasks,
                ps.avg_completion_percentage, ps.contact_count,
                u.name AS owner_name
         FROM projects p
         JOIN project_stats ps ON ps.id = p.id
         JOIN users u ON p.responsible_person_id = u.id
         WHERE p.share_code = ?",
        [$code]
    );
    if (!$project) {
        // Issue-023: unknown-code is a failure, not a success. The
        // distinct-codes cap catches a dictionary attack that
        // sweeps a leaked list of codes.
        recordShareCodeAttempt($clientIp, $code, null, false);
        $error = t('err.no_project') . ' Please double-check it with your project manager.';
    } else {
        // Issue-023: a successful code lookup clears the per-IP
        // failure history so a legitimate user is not locked out
        // by their own past failures.
        recordShareCodeAttempt($clientIp, $code, $project['id'], true);
    }
}

if ($error) {
    ?>
    <!DOCTYPE html>
    <html lang="<?= e($lang) ?>">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title><?= e(t('landing.title')) ?> — <?= e(APP_NAME) ?></title>
        <link rel="icon" type="image/png" href="/assets/image/project-favicon.png">
        <link rel="stylesheet" href="<?= e(asset_url('/assets/css/v2-tokens.css')) ?>">
        <link rel="stylesheet" href="<?= e(asset_url('/assets/css/style.css')) ?>">
        <?php /* Issue-050: layer 2.5 — the shared v2 component layer. The client
                portal is not served through includes/header.php, so it has to
                opt in explicitly. Same order as the internal app: tokens ->
                legacy base -> v2 components -> page CSS, so the client page
                CSS gets the final say at equal specificity. */ ?>
        <link rel="stylesheet" href="<?= e(asset_url('/assets/css/v2-components.css')) ?>">
        <link rel="stylesheet" href="<?= e(asset_url('/client/assets/client.css')) ?>">
        <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    </head>
    <body class="client-body">
    <main class="client-content" style="max-width: 560px; padding-top: var(--sp-10);">
        <div class="card">
            <div class="card__body">
                <div class="client-brand">
                    <i class="fas fa-project-diagram"></i>
                    <h1><?= e(APP_NAME) ?></h1>
                </div>
                <div class="alert alert--danger"><?= e($error) ?></div>
                <p><a href="index.php" class="btn btn--secondary"><i class="fas fa-arrow-left"></i> <?= e(t('landing.try_again')) ?></a></p>
            </div>
        </div>
    </main>
    </body>
    </html>
    <?php
    exit();
}

// Recursively fetch tasks and subtasks for this project. We pull a flat list
// (top-level + descendants) ordered by parent then created_at so we can build
// a tree on the client without recursive SQL.
$flat_tasks = $db->fetchAll(
    "SELECT t.id, t.parent_task_id, t.name, t.description, t.status,
            t.completion_percentage, t.estimated_hours, t.inherit_subtask_hours,
            t.expected_completion_date, t.completion_date,
            t.on_hold_reason, t.delay_reason,
            u.name AS responsible_name,
            (SELECT COUNT(*) FROM tasks st WHERE st.parent_task_id = t.id) AS subtask_count
     FROM tasks t
     LEFT JOIN users u ON t.responsible_person_id = u.id
     WHERE t.project_id = ?
     ORDER BY (t.parent_task_id IS NULL) DESC, t.parent_task_id ASC, t.created_at ASC",
    [$project['id']]
);
$taskCompletion = calculateTaskCompletionMap($flat_tasks);
$project['avg_completion_percentage'] = $taskCompletion['project_completion'];
foreach ($flat_tasks as &$task) {
    $task['completion_percentage'] = $taskCompletion['task_completion'][(int)$task['id']] ?? 0;
}
unset($task);

// Issue-034 v1.8.0: project-level "X delayed, Y on hold" counts for
// the Project Info panel. Computed from the in-memory $flat_tasks list
// so the client portal never has to issue a second SELECT. We apply
// the same rule as isTaskDelayed(): expected_completion_date < today
// AND status != 'completed'. Completed tasks are exempt; on-hold tasks
// count in BOTH delayed (if past due) and on-hold buckets? No —
// "delayed" is purely date-based; a task that is on-hold AND past due
// is still counted once as on-hold (since that is the more recent
// state), and ALSO once as delayed (so the chip "X delayed, Y on hold"
// remains an honest readout of the two conditions).
$project_delayed_count = 0;
$project_on_hold_count = 0;
$today = strtotime(date('Y-m-d'));
foreach ($flat_tasks as $_ct) {
    $st = $_ct['status'] ?? '';
    if ($st === 'on_hold') {
        $project_on_hold_count++;
    }
    if ($st !== 'completed'
        && !empty($_ct['expected_completion_date'])
        && strtotime((string)$_ct['expected_completion_date']) < $today) {
        $project_delayed_count++;
    }
}
unset($_ct);

// Build a tree.
$by_parent = [];
foreach ($flat_tasks as $t) {
    $pid = $t['parent_task_id'] ?? 0;
    $by_parent[$pid][] = $t;
}
function render_task_tree($tasks, $by_parent, $level = 0) {
    foreach ($tasks as $task) {
        $child_rows = $by_parent[$task['id']] ?? [];
        // Issue-011: resolve the parent's Total Task Time via the shared helper
        // so the client portal agrees with the internal console (issue-011).
        $resolved_total = resolveTaskEstimateHours($task, $child_rows);

        // Issue-050: v2 mockup vocabulary (docs/design/2.0/client-dashboard.html).
        // The task node carries three modifier classes:
        //   task-node--completed  dimmed row
        //   task-node--on-hold    amber left border
        //   task-node--delayed    red left border
        // and the head row uses .task-node__head / .task-node__name /
        // .task-node__name--done, the meta row .task-node__meta, the progress
        // row .task-node__progress + .progress + .progress__fill and the
        // reasons block .task-node__reasons.
        $status = (string)($task['status'] ?? '');
        $is_on_hold = ($status === 'on_hold');
        $is_delayed = ($status !== 'completed')
            && !empty($task['expected_completion_date'])
            && strtotime((string)$task['expected_completion_date']) < strtotime(date('Y-m-d'));
        $is_completed = ($status === 'completed');

        $node_classes = 'task-node';
        if ($is_completed) { $node_classes .= ' task-node--completed'; }
        if ($is_on_hold)   { $node_classes .= ' task-node--on-hold'; }
        if ($is_delayed)   { $node_classes .= ' task-node--delayed'; }

        // The progress fill colour follows the status, per the mockup:
        // completed -> success, on hold -> warning, otherwise the brand blue.
        $progress_mod = '';
        if ($is_completed) { $progress_mod = ' progress--success'; }
        elseif ($is_on_hold) { $progress_mod = ' progress--warning'; }
        elseif ($is_delayed) { $progress_mod = ' progress--danger'; }

        echo '<li class="' . e($node_classes) . '" data-level="' . (int)$level . '">';
        echo '<div class="task-node__head">';
        echo '<span class="task-node__name' . ($is_completed ? ' task-node__name--done' : '') . '">' . e($task['name']) . '</span>';
        echo '<span class="status status--' . e($status) . '">' . e(t_status($status)) . '</span>';
        // Issue-050: the mockup shows the derived "Delayed" state as its own
        // status chip next to the task's own status (issue-034's delayed
        // condition: past due AND not completed).
        if ($is_delayed) {
            echo '<span class="status status--delayed"><i class="fas fa-clock-rotate-left"></i> ' . e(t('dash.delayed')) . '</span>';
        }
        echo '</div>';
        if (!empty($task['description'])) {
            echo '<p class="task-desc text-sm text-muted">' . e($task['description']) . '</p>';
        }
        echo '<div class="task-node__meta">';
        if (!empty($task['responsible_name'])) {
            echo '<span><i class="fas fa-user"></i> ' . e($task['responsible_name']) . '</span>';
        }
        if (!empty($task['expected_completion_date'])) {
            echo '<span><i class="fas fa-calendar"></i> ' . e(t('dash.task_due')) . ' ' . e(formatDateTime($task['expected_completion_date'], 'M j, Y')) . '</span>';
        }
        if (!empty($task['completion_date'])) {
            echo '<span><i class="fas fa-calendar-check"></i> ' . e(t('dash.task_completed')) . ' ' . e(formatDateTime($task['completion_date'], 'M j, Y')) . '</span>';
        }
        if ($resolved_total !== null) {
            echo '<span><i class="fas fa-clock"></i> ' . e(formatTaskEstimate($resolved_total)) . '</span>';
        } elseif (!empty($task['inherit_subtask_hours']) && (int)($task['subtask_count'] ?? 0) > 0) {
            // Spec issue-011 § "Validation edge cases": inherit-on with no
            // positive child estimates must render the literal "Not
            // estimated" copy on the client portal too
            // (issue-011 tester-note-001). The internal app uses an English
            // string by spec; the client portal uses a translated key.
            echo '<span class="not-estimated"><i class="fas fa-clock"></i> ' . e(t('dash.not_estimated_parent')) . '</span>';
        }
        echo '</div>';
        echo '<div class="task-node__progress">';
        echo '<div class="progress' . $progress_mod . '"><div class="progress__fill" style="width:' . e(number_format((float)$task['completion_percentage'], 1)) . '%"></div></div>';
        echo '<span class="task-node__pct">' . e(number_format((float)$task['completion_percentage'], 1)) . '%</span>';
        echo '</div>';
        // Issue-034 v1.8.0: per-task status reason chips. Delayed chips
        // render whenever expected_completion_date < today AND status
        // != 'completed' (the isTaskDelayed() rule). On-hold chips render
        // unconditionally when status = 'on_hold' (the user can
        // legally be on hold with a NULL reason and we still want the
        // "reason missing" affordance, but we only show a populated
        // chip when the reason column has text). The previous text is
        // escaped; trim() is applied so the chip never prints whitespace
        // from a half-saved form.
        // Issue-050: retagged from .task-reason to the mockup's
        // .reason-chip / .reason-chip--delay / .reason-chip--on-hold.
        $on_hold_reason = isset($task['on_hold_reason']) ? trim((string)$task['on_hold_reason']) : '';
        $delay_reason   = isset($task['delay_reason'])   ? trim((string)$task['delay_reason'])   : '';
        if ($is_on_hold && $on_hold_reason !== '') {
            echo '<div class="task-node__reasons">';
            echo '<div class="reason-chip reason-chip--on-hold">'
                . '<i class="fas fa-pause"></i>'
                . '<span><strong>' . e(t('dash.on_hold')) . ':</strong> ' . e($on_hold_reason) . '</span>'
                . '</div>';
            if ($is_delayed && $delay_reason !== '') {
                echo '<div class="reason-chip reason-chip--delay">'
                    . '<i class="fas fa-clock-rotate-left"></i>'
                    . '<span><strong>' . e(t('dash.delayed')) . ':</strong> ' . e($delay_reason) . '</span>'
                    . '</div>';
            }
            echo '</div>';
        } elseif ($is_delayed && $delay_reason !== '') {
            echo '<div class="task-node__reasons">';
            echo '<div class="reason-chip reason-chip--delay">'
                . '<i class="fas fa-clock-rotate-left"></i>'
                . '<span><strong>' . e(t('dash.delayed')) . ':</strong> ' . e($delay_reason) . '</span>'
                . '</div>';
            echo '</div>';
        }

        // Recurse into subtasks.
        if (!empty($child_rows)) {
            echo '<ul class="task-tree">';
            render_task_tree($child_rows, $by_parent, $level + 1);
            echo '</ul>';
        }
        echo '</li>';
    }
}
?>
<!DOCTYPE html>
<html lang="<?= e($lang) ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= e($project['name']) ?> — <?= e(APP_NAME) ?> <?= e(t('dash.title_suffix')) ?></title>
    <link rel="icon" type="image/png" href="/assets/image/project-favicon.png">
    <link rel="stylesheet" href="<?= e(asset_url('/assets/css/v2-tokens.css')) ?>">
    <link rel="stylesheet" href="<?= e(asset_url('/assets/css/style.css')) ?>">
    <?php /* Issue-050: layer 2.5 — the shared v2 component layer. The client
            portal is not served through includes/header.php, so it has to
            opt in explicitly. Same order as the internal app: tokens ->
            legacy base -> v2 components -> page CSS, so the client page
            CSS gets the final say at equal specificity. */ ?>
    <link rel="stylesheet" href="<?= e(asset_url('/assets/css/v2-components.css')) ?>">
    <link rel="stylesheet" href="<?= e(asset_url('/client/assets/client.css')) ?>">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
</head>
<body class="client-body">

<div class="client-shell">

<header class="client-header">
    <div class="client-header__inner">
        <div class="client-header__brand">
            <span class="client-header__brand-icon"><i class="fas fa-project-diagram"></i></span>
            <span><?= e(APP_NAME) ?> — <?= e(t('dash.brand_suffix')) ?></span>
        </div>
        <div class="client-header__project">
            <div class="client-header__project-name"><?= e($project['name']) ?></div>
            <div class="client-header__project-meta">
                <?= e(t('dash.code_label')) ?>: <code><?= e($project['share_code']) ?></code>
            </div>
        </div>
        <div class="client-header__tools">
            <details class="app-about" aria-label="<?= e(t('about.trigger_aria')) ?>">
                <summary class="app-about-trigger" aria-label="<?= e(t('about.trigger_aria')) ?>" title="<?= e(t('about.trigger_aria')) ?>">?</summary>
                <div class="app-about-panel" role="dialog" aria-label="<?= e(t('about.trigger_aria')) ?>">
                    <h3><?= e(t('about.title')) ?></h3>
                    <p><?= e(t('about.version')) ?>: <strong><?= e(APP_VERSION) ?></strong></p>
                    <p><?= e(t('about.built_by')) ?>: Me</p>
                    <p><?= e(t('about.attribution')) ?></p>
                    <p class="text-muted"><small><?= e(t('about.foot_note')) ?></small></p>
                </div>
            </details>
            <div class="lang-switch inline" role="group" aria-label="<?= e(t('landing.lang_label')) ?>">
                <?php foreach (client_all_langs() as $code2 => $label): ?>
                    <a href="<?= e(client_lang_url($code2)) ?>"
                       class="lang-switch-link<?= ($code2 === client_current_lang()) ? ' is-active' : '' ?>"
                       <?= ($code2 === client_current_lang()) ? ' aria-current="true"' : '' ?>><?= e($label) ?></a>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
</header>

<main class="client-content">

    <!-- Project header -->
    <div class="client-hero">
        <div class="client-hero__label"><?= e(t('dash.overall')) ?></div>
        <div class="client-hero__value"><?= e(number_format((float)$project['avg_completion_percentage'], 1)) ?>%</div>
        <div class="client-hero__bar">
            <div class="client-hero__bar-fill" style="width:<?= e(number_format((float)$project['avg_completion_percentage'], 1)) ?>%"></div>
        </div>
        <div class="client-hero__stats">
            <div>
                <div class="client-hero__stat-label"><?= e(t('dash.tasks')) ?></div>
                <div class="client-hero__stat-value"><?= (int)$project['total_tasks'] ?></div>
            </div>
            <div>
                <div class="client-hero__stat-label"><?= e(t('dash.completed_count')) ?></div>
                <div class="client-hero__stat-value"><?= (int)$project['completed_tasks'] ?></div>
            </div>
            <div>
                <div class="client-hero__stat-label"><?= e(t('dash.open')) ?></div>
                <div class="client-hero__stat-value"><?= (int)$project['uncompleted_tasks'] ?></div>
            </div>
        </div>
    </div>

    <?php // Issue-034 v1.8.0 — project-level "X delayed, Y on hold" summary.
          // Issue-050: moved OUT of the info grid into the mockup's
          // .client-alert-strip, which sits directly under the hero. ?>
    <?php if ($project_delayed_count > 0 || $project_on_hold_count > 0): ?>
        <div class="client-alert-strip" style="margin-bottom: var(--sp-5);">
            <?php if ($project_delayed_count > 0): ?>
                <span class="badge badge--danger"><i class="fas fa-clock-rotate-left"></i> <?= (int)$project_delayed_count ?> <?= e(t('dash.delayed')) ?></span>
            <?php endif; ?>
            <?php if ($project_on_hold_count > 0): ?>
                <span class="badge badge--warning"><i class="fas fa-pause"></i> <?= (int)$project_on_hold_count ?> <?= e(t('dash.on_hold')) ?></span>
            <?php endif; ?>
        </div>
    <?php endif; ?>

    <!-- Project information -->
    <div class="card">
        <div class="card__header">
            <h2 class="card__title-icon"><i class="fas fa-circle-info"></i> <?= e(t('dash.project_info')) ?></h2>
            <div class="card__actions">
                <span class="status status--<?= e($project['status']) ?>"><?= e(t_status($project['status'])) ?></span>
            </div>
        </div>
        <div class="card__body">
            <div class="project-header">
                <h1><i class="fas fa-folder-open"></i> <?= e($project['name']) ?></h1>
            </div>
            <?php if (!empty($project['description'])): ?>
                <p class="project-description"><?= nl2br(e($project['description'])) ?></p>
            <?php endif; ?>

            <div class="client-info-grid">
                <div class="client-info-item">
                    <div class="client-info-item__k"><?= e(t('dash.started')) ?></div>
                    <div class="client-info-item__v"><?= !empty($project['start_date']) ? e(formatDateTime($project['start_date'], 'M j, Y')) : '<em class="text-muted">' . e(t('dash.not_set')) . '</em>' ?></div>
                </div>
                <div class="client-info-item">
                    <div class="client-info-item__k"><?= e(t('dash.expected')) ?></div>
                    <div class="client-info-item__v"><?= $project['expected_completion_date'] ? e(formatDateTime($project['expected_completion_date'], 'M j, Y')) : '<em class="text-muted">' . e(t('dash.not_set')) . '</em>' ?></div>
                </div>
                <div class="client-info-item">
                    <div class="client-info-item__k"><?= e(t('dash.completed')) ?></div>
                    <div class="client-info-item__v"><?= $project['completion_date'] ? e(formatDateTime($project['completion_date'], 'M j, Y')) : '<em class="text-muted">' . e(t('dash.in_progress')) . '</em>' ?></div>
                </div>
                <div class="client-info-item">
                    <div class="client-info-item__k"><?= e(t('dash.manager')) ?></div>
                    <div class="client-info-item__v"><?= e($project['owner_name']) ?></div>
                </div>
                <div class="client-info-item">
                    <div class="client-info-item__k"><i class="fas fa-coins"></i> <?= e(t('dash.budget')) ?></div>
                    <div class="client-info-item__v">
                        <?php if ($project['budget_amount'] !== null && $project['currency']): ?>
                            <span class="budget-amount"><?= e(formatMoney($project['budget_amount'], $project['currency'])) ?></span>
                        <?php else: ?>
                            <em class="text-muted"><?= e(t('dash.budget_none')) ?></em>
                        <?php endif; ?>
                    </div>
                </div>
                <div class="client-info-item">
                    <div class="client-info-item__k"><i class="fas fa-chart-bar"></i> <?= e(t('dash.counts')) ?></div>
                    <div class="client-info-item__v"><?= (int)$project['contact_count'] ?> <?= e(t('dash.contacts')) ?></div>
                </div>
            </div>

            <?php if (!empty($project['scope'])): ?>
                <div style="margin-top: var(--sp-5);">
                    <div class="client-info-item__k" style="margin-bottom: .35rem;"><?= e(t('dash.scope')) ?></div>
                    <ul class="scope-list">
                        <?php foreach (preg_split('/\r\n|\r|\n/', (string)$project['scope']) as $line):
                            $line = trim($line); if ($line === '') continue; ?>
                            <li><?= e($line) ?></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Tasks -->
    <?php
        // Only render the sub-tasks toggle if there is at least one sub-task
        // anywhere in the tree. (subtask_count is computed by the SELECT.)
        $has_subtasks = false;
        foreach ($flat_tasks as $_t) {
            if ((int)$_t['subtask_count'] > 0) { $has_subtasks = true; break; }
        }
        unset($_t);
    ?>
    <div class="card" id="tasks-progress" data-subtasks="hidden">
        <div class="card__header">
            <h2 class="card__title-icon"><i class="fas fa-tasks"></i> <?= e(t('dash.tasks_heading')) ?></h2>
            <div class="card__actions">
                <span class="badge badge--neutral"><?= (int)count($flat_tasks) ?> <?= e(t('dash.total_suffix')) ?></span>
                <?php if ($has_subtasks): ?>
                    <button type="button" id="subtasks-toggle"
                            class="btn btn--secondary btn--sm subtasks-toggle"
                            data-state="hidden"
                            data-share-code="<?= e($project['share_code']) ?>"
                            data-label-show-all="<?= e(t('dash.subtasks_show_all')) ?>"
                            data-label-show-top="<?= e(t('dash.subtasks_show_top')) ?>"
                            aria-controls="tasks-progress"
                            aria-pressed="false"
                            aria-label="<?= e(t('dash.subtasks_toggle_aria')) ?>">
                        <i class="fas fa-eye" aria-hidden="true"></i>
                        <span class="subtasks-toggle-label"><?= e(t('dash.subtasks_show_all')) ?></span>
                    </button>
                <?php endif; ?>
            </div>
        </div>
        <div class="card__body">
            <?php if (empty($flat_tasks)): ?>
                <div class="empty-state">
                    <i class="fas fa-list-check"></i>
                    <p><?= e(t('dash.no_tasks')) ?></p>
                </div>
            <?php else: ?>
                <ul class="task-tree root">
                    <?php render_task_tree($by_parent[0] ?? [], $by_parent, 0); ?>
                </ul>
            <?php endif; ?>
        </div>
    </div>

</main>

<footer class="footer">
    <?= e(t('dash.last_updated')) ?> <?= e(formatDateTime($project['completion_date'] ?? $project['expected_completion_date'] ?? $project['created_at'], 'M j, Y')) ?>.
    <?= e(t('dash.contact_pm')) ?>
</footer>

</div>

<?php if ($has_subtasks): ?>
<!-- Sub-tasks toggle controller — external file so the issue-020 CSP
     tightening on the public client portal (no 'unsafe-inline') still
     allows it to run. Translation strings + share code are passed in
     via data-* attributes on the button (issue-027).

     Issue-049: the path was "/assets/js/subtask-toggle.js", but the file
     lives at client/assets/js/subtask-toggle.js. That path 404s, so the
     controller never loaded and the sub-task expand/collapse control was
     dead in the browser. The path below is the root-absolute form that
     issue-035 §"The fix — every asset reference becomes root-absolute"
     mandates for this file. -->
<script src="/client/assets/js/subtask-toggle.js" defer></script>
<?php endif; ?>

</body>
</html>