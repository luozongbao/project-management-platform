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
require_once __DIR__ . '/lang.php';

// Persist ?lang= into the cookie so subsequent navigations remember it.
if (isset($_GET['lang']) && in_array($_GET['lang'], client_supported_langs(), true)) {
    client_set_lang_cookie($_GET['lang']);
}
$lang = client_current_lang();

$db = Database::getInstance();

$code = strtoupper(trim($_GET['code'] ?? ''));
$error = '';

if ($code === '') {
    header('Location: index.php');
    exit();
}

// Strict format check; if it doesn't even look right, send back to landing.
if (!preg_match('/^[A-Z2-9]{5}-[A-Z2-9]{5}-[A-Z2-9]{5}$/', $code)) {
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
        $error = t('err.no_project') . ' Please double-check it with your project manager.';
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
        <link rel="stylesheet" href="../assets/css/style.css">
        <link rel="stylesheet" href="assets/client.css">
        <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    </head>
    <body class="client-body">
    <main class="client-shell">
        <div class="client-card">
            <div class="client-brand">
                <i class="fas fa-project-diagram"></i>
                <h1><?= e(APP_NAME) ?></h1>
            </div>
            <div class="alert alert-danger"><?= e($error) ?></div>
            <p><a href="index.php" class="btn btn-secondary"><i class="fas fa-arrow-left"></i> <?= e(t('landing.try_again')) ?></a></p>
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
            t.completion_percentage, t.estimated_hours, t.expected_completion_date, t.completion_date,
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

// Build a tree.
$by_parent = [];
foreach ($flat_tasks as $t) {
    $pid = $t['parent_task_id'] ?? 0;
    $by_parent[$pid][] = $t;
}
function render_task_tree($tasks, $by_parent, $level = 0) {
    foreach ($tasks as $task) {
        echo '<li class="task-item status-' . e($task['status']) . '" data-level="' . (int)$level . '">';
        echo '<div class="task-line">';
        if ($level > 0) {
            echo '<span class="tree-indent">└─</span>';
        }
        echo '<div class="task-body">';
        echo '<div class="task-head">';
        echo '<span class="task-name">' . e($task['name']) . '</span>';
        echo '<span class="status-badge status-' . e($task['status']) . '">' . e(t_status($task['status'])) . '</span>';
        echo '</div>';
        if (!empty($task['description'])) {
            echo '<p class="task-desc">' . e($task['description']) . '</p>';
        }
        echo '<div class="task-meta">';
        if (!empty($task['responsible_name'])) {
            echo '<span><i class="fas fa-user"></i> ' . e($task['responsible_name']) . '</span>';
        }
        if (!empty($task['expected_completion_date'])) {
            echo '<span><i class="fas fa-calendar"></i> ' . e(t('dash.task_due')) . ' ' . e(formatDateTime($task['expected_completion_date'], 'M j, Y')) . '</span>';
        }
        if (!empty($task['completion_date'])) {
            echo '<span><i class="fas fa-calendar-check"></i> ' . e(t('dash.task_completed')) . ' ' . e(formatDateTime($task['completion_date'], 'M j, Y')) . '</span>';
        }
        echo '</div>';
        echo '<div class="task-progress"><div class="progress-bar"><div class="progress-fill" style="width:' . e(number_format((float)$task['completion_percentage'], 1)) . '%"></div></div>';
        echo '<span class="progress-text">' . e(number_format((float)$task['completion_percentage'], 1)) . '%</span></div>';
        echo '</div>';
        echo '</div>';

        // Recurse into subtasks.
        $children = $by_parent[$task['id']] ?? [];
        if (!empty($children)) {
            echo '<ul class="task-tree">';
            render_task_tree($children, $by_parent, $level + 1);
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
    <link rel="stylesheet" href="../assets/css/style.css">
    <link rel="stylesheet" href="assets/client.css">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
</head>
<body class="client-body">

<header class="client-header">
    <div class="client-header-inner">
        <div class="client-brand">
            <i class="fas fa-project-diagram"></i>
            <span><?= e(APP_NAME) ?> — <?= e(t('dash.brand_suffix')) ?></span>
        </div>
        <div class="client-header-right">
            <span class="text-muted"><?= e(t('dash.code_label')) ?>: <code><?= e($project['share_code']) ?></code></span>
            <div class="lang-switch inline" aria-label="<?= e(t('landing.lang_label')) ?>">
                <?php foreach (client_other_langs() as $code2 => $label): ?>
                    <a href="<?= e(client_lang_url($code2)) ?>" class="lang-switch-link"><?= e($label) ?></a>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
</header>

<main class="client-shell">

    <!-- Project header -->
    <section class="card client-section">
        <div class="project-header">
            <h1><i class="fas fa-folder-open"></i> <?= e($project['name']) ?></h1>
            <span class="status-badge status-<?= e($project['status']) ?> big">
                <?= e(t_status($project['status'])) ?>
            </span>
        </div>
        <?php if (!empty($project['description'])): ?>
            <p class="project-description"><?= nl2br(e($project['description'])) ?></p>
        <?php endif; ?>

        <div class="project-summary-grid">
            <div>
                <h3 class="info-heading"><i class="fas fa-chart-pie"></i> <?= e(t('dash.overall')) ?></h3>
                <div class="big-progress">
                    <div class="progress-bar big"><div class="progress-fill" style="width:<?= e(number_format((float)$project['avg_completion_percentage'], 1)) ?>%"></div></div>
                    <div class="big-progress-number"><?= e(number_format((float)$project['avg_completion_percentage'], 1)) ?>%</div>
                </div>
                <p class="text-muted"><small><?= e(t('dash.overall_note')) ?></small></p>
            </div>
            <div>
                <h3 class="info-heading"><i class="fas fa-info-circle"></i> <?= e(t('dash.project_info')) ?></h3>
                <ul class="kv-list">
                    <li><span class="k"><?= e(t('dash.started')) ?></span><span class="v"><?= !empty($project['start_date']) ? e(formatDateTime($project['start_date'], 'M j, Y')) : '<em class="text-muted">' . e(t('dash.not_set')) . '</em>' ?></span></li>
                    <li><span class="k"><?= e(t('dash.expected')) ?></span><span class="v"><?= $project['expected_completion_date'] ? e(formatDateTime($project['expected_completion_date'], 'M j, Y')) : '<em class="text-muted">' . e(t('dash.not_set')) . '</em>' ?></span></li>
                    <li><span class="k"><?= e(t('dash.completed')) ?></span><span class="v"><?= $project['completion_date'] ? e(formatDateTime($project['completion_date'], 'M j, Y')) : '<em class="text-muted">' . e(t('dash.in_progress')) . '</em>' ?></span></li>
                    <li><span class="k"><?= e(t('dash.manager')) ?></span><span class="v"><?= e($project['owner_name']) ?></span></li>
                </ul>
            </div>
            <div>
                <h3 class="info-heading"><i class="fas fa-coins"></i> <?= e(t('dash.budget')) ?></h3>
                <?php if ($project['budget_amount'] !== null && $project['currency']): ?>
                    <p class="budget-amount"><?= e(formatMoney($project['budget_amount'], $project['currency'])) ?></p>
                <?php else: ?>
                    <p class="text-muted"><em><?= e(t('dash.budget_none')) ?></em></p>
                <?php endif; ?>
                <h3 class="info-heading" style="margin-top:18px;"><i class="fas fa-chart-bar"></i> <?= e(t('dash.counts')) ?></h3>
                <ul class="kv-list">
                    <li><span class="k"><?= e(t('dash.tasks')) ?></span><span class="v"><?= (int)$project['total_tasks'] ?></span></li>
                    <li><span class="k"><?= e(t('dash.completed_count')) ?></span><span class="v"><?= (int)$project['completed_tasks'] ?></span></li>
                    <li><span class="k"><?= e(t('dash.open')) ?></span><span class="v"><?= (int)$project['uncompleted_tasks'] ?></span></li>
                    <li><span class="k"><?= e(t('dash.contacts')) ?></span><span class="v"><?= (int)$project['contact_count'] ?></span></li>
                </ul>
            </div>
        </div>
    </section>

    <!-- Scope -->
    <section class="card client-section">
        <h2 class="section-title"><i class="fas fa-list-check"></i> <?= e(t('dash.scope')) ?></h2>
        <?php if (!empty($project['scope'])): ?>
            <ul class="scope-list">
                <?php foreach (preg_split('/\r\n|\r|\n/', (string)$project['scope']) as $line):
                    $line = trim($line); if ($line === '') continue; ?>
                    <li><?= e($line) ?></li>
                <?php endforeach; ?>
            </ul>
        <?php else: ?>
            <p class="text-muted"><em><?= e(t('dash.scope_none')) ?></em></p>
        <?php endif; ?>
    </section>

    <!-- Tasks -->
    <section class="card client-section">
        <h2 class="section-title">
            <i class="fas fa-tasks"></i> <?= e(t('dash.tasks_heading')) ?>
            <small class="text-muted">(<?= (int)count($flat_tasks) ?> <?= e(t('dash.total_suffix')) ?>)</small>
        </h2>
        <?php if (empty($flat_tasks)): ?>
            <p class="text-muted"><em><?= e(t('dash.no_tasks')) ?></em></p>
        <?php else: ?>
            <ul class="task-tree root">
                <?php render_task_tree($by_parent[0] ?? [], $by_parent, 0); ?>
            </ul>
        <?php endif; ?>
    </section>

    <p class="client-foot">
        <?= e(t('dash.last_updated')) ?> <?= e(formatDateTime($project['completion_date'] ?? $project['expected_completion_date'] ?? $project['created_at'], 'M j, Y')) ?>.
        <?= e(t('dash.contact_pm')) ?>
    </p>

</main>

</body>
</html>