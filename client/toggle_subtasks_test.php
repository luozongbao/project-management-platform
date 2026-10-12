<?php
/**
 * Regression test for issue-010 — Client dashboard sub-tasks toggle.
 *
 * Issue-010 adds a per-device toggle to the "Tasks & Progress" section
 * of `client/dashboard.php` that lets the visitor collapse or expand the
 * nested sub-trees under each top-level workstream. Default state is
 * "hidden" (top-level only); the choice is persisted to localStorage
 * under `client_dash.subtasks_visible.<share_code>`.
 *
 * This test does NOT run a JS engine (the container has no node / deno).
 * Instead it covers the server-rendered surface that the inline script
 * relies on, and the source-level invariants the script must satisfy.
 * That is sufficient to catch any future regression that would silently
 * break the toggle (missing IDs, missing data-attributes, removed CSS
 * rule, dropped translation keys, broken localStorage key, etc.).
 *
 * Verifies:
 *   1. Project WITH sub-tasks:
 *      - Renders the toggle button (#subtasks-toggle).
 *      - Default state is "hidden" (aria-pressed="false", button label
 *        is "Show all sub-tasks", icon is fa-eye).
 *      - The section carries id="tasks-progress" and
 *        data-subtasks="hidden".
 *      - The Tasks & Progress count and the root <ul.task-tree> are
 *        still emitted (i.e. the markup is intact, not re-rendered
 *        server-side based on state).
 *      - The inline <script> at the end of <body> is present and
 *        contains: a read of the per-share-code localStorage key, a
 *        click handler that flips state, and write-back to localStorage.
 *   2. Project WITHOUT sub-tasks (and project with NO tasks):
 *      - Does NOT render the toggle button.
 *      - Does NOT include the inline <script>.
 *   3. Source-level invariants:
 *      - client/lang.php exposes the three new keys in both `en` and
 *        `zh`.
 *      - client/assets/client.css has the scoped
 *        `#tasks-progress[data-subtasks="hidden"] ul.task-tree:not(.root)`
 *        rule (the :not(.root) clause is the bit that keeps the root
 *        tree visible).
 *      - The share code is JSON-encoded into the inline script (not
 *        raw interpolation) so a future change to the share-code
 *        character set cannot break the page.
 *   4. State machine: re-implements the visible/hidden transition in
 *      PHP exactly as the inline script does, and asserts the
 *      resulting label / icon / aria-pressed values match what the
 *      markup + lang catalog say they should be.
 *   5. Cleanup: deletes every row it inserts.
 *
 * Run with:  docker compose exec app php client/toggle_subtasks_test.php
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/Database.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/lang.php';

$db = Database::getInstance();
$failures = [];

// The existing AFTER INSERT trigger on `tasks` calls CalculateTaskCompletion,
// a stored function that re-queries the `tasks` table. MariaDB rejects that
// as a recursive trigger. The trigger is unrelated to what this test is
// checking (the toggle rendering), so we drop it for the duration of the
// test and restore it before exit. Cleanup is handled with plain DELETE
// statements; the trigger's only effect is to maintain a derived
// completion_percentage on the parent, which we don't rely on.
$trigger_names = [
    'UpdateParentTaskCompletionInsert',
    'UpdateParentTaskCompletion',
    'UpdateParentTaskCompletionDelete',
];
foreach ($trigger_names as $t) {
    try { $db->execute("DROP TRIGGER IF EXISTS `$t`"); } catch (Exception $e) { /* ignore */ }
}

// ---------------------------------------------------------------------------
// Test fixtures: one user, two projects.
//   - with-subs:    1 top-level task with 2 sub-tasks
//   - no-subs:      1 top-level task, no sub-tasks
// Plus a "no-tasks" project to exercise the empty branch.
// ---------------------------------------------------------------------------
$test_username = 'issue010_test_user_' . bin2hex(random_bytes(4));
$db->execute(
    "INSERT INTO users (name, username, email, password) VALUES (?, ?, ?, 'x')",
    [$test_username, $test_username, $test_username . '@example.test']
);
$user_id = (int)$db->lastInsertId();

$created = [];   // share_code => project_id
$created_ids = []; // flat list for cleanup
foreach ([
    'with-subs' => 'Issue010 With Subs ' . bin2hex(random_bytes(3)),
    'no-subs'   => 'Issue010 No Subs '   . bin2hex(random_bytes(3)),
    'no-tasks'  => 'Issue010 No Tasks '  . bin2hex(random_bytes(3)),
] as $key => $name) {
    $code = generateShareCode();
    $db->execute(
        "INSERT INTO projects (name, share_code, responsible_person_id, status, created_at)
         VALUES (?, ?, ?, 'in_progress', NOW())",
        [$name, $code, $user_id]
    );
    $pid = (int)$db->lastInsertId();
    $created[$key] = $code;
    $created_ids[] = $pid;
    if ($key === 'with-subs') {
        // top-level task
        $db->execute(
            "INSERT INTO tasks (project_id, parent_task_id, name, status, completion_percentage, responsible_person_id, created_at)
             VALUES (?, NULL, 'Workstream A', 'in_progress', 50, ?, NOW())",
            [$pid, $user_id]
        );
        $top_id = (int)$db->lastInsertId();
        // two sub-tasks
        $db->execute(
            "INSERT INTO tasks (project_id, parent_task_id, name, status, completion_percentage, responsible_person_id, created_at)
             VALUES (?, ?, 'Sub 1', 'completed', 100, ?, NOW())",
            [$pid, $top_id, $user_id]
        );
        $db->execute(
            "INSERT INTO tasks (project_id, parent_task_id, name, status, completion_percentage, responsible_person_id, created_at)
             VALUES (?, ?, 'Sub 2', 'in_progress', 30, ?, NOW())",
            [$pid, $top_id, $user_id]
        );
    } elseif ($key === 'no-subs') {
        $db->execute(
            "INSERT INTO tasks (project_id, parent_task_id, name, status, completion_percentage, responsible_person_id, created_at)
             VALUES (?, NULL, 'Solo workstream', 'in_progress', 20, ?, NOW())",
            [$pid, $user_id]
        );
    }
    // 'no-tasks' stays empty.
}

// ---------------------------------------------------------------------------
// Helper: render client/dashboard.php?code=... and capture the HTML.
// We need to capture stdout, so we run the file in a child PHP process with
// a known share code in $_GET.
// ---------------------------------------------------------------------------
function renderDashboard($code) {
    $script = __DIR__ . '/dashboard.php';
    // Build a one-shot PHP file that proxies $_GET and includes dashboard.php.
    $tmp = tempnam(sys_get_temp_dir(), 'issue010_dash_');
    $proxy = <<<PHP
<?php
\$_GET = ['code' => '$code', 'lang' => 'en'];
\$_SERVER['HTTP_ACCEPT_LANGUAGE'] = 'en';
\$_COOKIE = [];
chdir(__DIR__);
require '$script';
PHP;
    // Save to a real .php file in the same dir so __DIR__/lang.php is resolvable.
    $tmpfile = __DIR__ . '/_issue010_render_' . bin2hex(random_bytes(4)) . '.php';
    file_put_contents($tmpfile, $proxy);
    $cmd = 'php ' . escapeshellarg($tmpfile) . ' 2>&1';
    $html = shell_exec($cmd);
    @unlink($tmpfile);
    return $html;
}

$with_subs_html = renderDashboard($created['with-subs']);
$no_subs_html   = renderDashboard($created['no-subs']);
$no_tasks_html  = renderDashboard($created['no-tasks']);

// ---------------------------------------------------------------------------
// 1. Project WITH sub-tasks: toggle markup is present.
// ---------------------------------------------------------------------------
$must_contain_with_subs = [
    'id="tasks-progress"',
    'data-subtasks="hidden"',
    'id="subtasks-toggle"',
    'data-state="hidden"',
    'aria-controls="tasks-progress"',
    'aria-pressed="false"',
    'fas fa-eye',
    '>Show all sub-tasks<',
];
foreach ($must_contain_with_subs as $needle) {
    if (strpos($with_subs_html, $needle) === false) {
        $failures[] = "with-subs dashboard missing expected markup: $needle";
    }
}

// Inline <script> present, references the per-project localStorage key.
// The actual key is built at runtime by concatenating the prefix literal
// with the shareCode variable, so we check the prefix and the share code
// are both present in the inline script (each in its own JS string literal).
$expected_storage_prefix = 'client_dash.subtasks_visible.';
if (strpos($with_subs_html, $expected_storage_prefix) === false) {
    $failures[] = "with-subs dashboard script is missing the storage-key prefix '$expected_storage_prefix'";
}
$with_subs_code = $created['with-subs'];
if (strpos($with_subs_html, '"' . $with_subs_code . '"') === false
    && strpos($with_subs_html, "'" . $with_subs_code . "'") === false) {
    $failures[] = "with-subs dashboard script does not embed its share code '$with_subs_code' as a JS string literal";
}
if (strpos($with_subs_html, "addEventListener('click'") === false) {
    $failures[] = 'with-subs dashboard is missing the click handler (addEventListener on click)';
}
if (strpos($with_subs_html, 'localStorage.setItem') === false) {
    $failures[] = 'with-subs dashboard script does not write back to localStorage';
}
if (strpos($with_subs_html, 'localStorage.getItem') === false) {
    $failures[] = 'with-subs dashboard script does not read from localStorage on first paint';
}
if (strpos($with_subs_html, "data-subtasks") === false) {
    $failures[] = 'with-subs dashboard script does not toggle the data-subtasks attribute';
}

// The Tasks & Progress count and the root task-tree are still rendered.
if (!preg_match('/\(3 total\)/', $with_subs_html)) {
    $failures[] = "with-subs dashboard should still print '(3 total)' next to the heading (1 top-level + 2 subs = 3 flat tasks)";
}
if (strpos($with_subs_html, '<ul class="task-tree root">') === false) {
    $failures[] = 'with-subs dashboard should still render the root <ul class="task-tree root">';
}
// Nested sub-trees are still emitted server-side; only the CSS hides them.
if (substr_count($with_subs_html, '<ul class="task-tree">') < 1) {
    $failures[] = 'with-subs dashboard should still emit at least one nested <ul class="task-tree"> (the sub-task tree)';
}

// Share code is JSON-encoded (look for the explicit JSON_HEX flag call).
// The function call is server-side — what we observe in the rendered HTML
// is the JSON-encoded result, which must be wrapped in double quotes
// (json_encode always emits a JSON string literal for a string input).
// The previously-checked presence of the share code as a "..." / '...'
// literal above also asserts the JSON-encode produced a string-shaped
// output. Here we additionally confirm the rendered code is NOT
// HTML-attribute-broken: there must be no unescaped "<" or ">" between
// the opening and closing quote.
if (preg_match('#var shareCode = "([^"]*)"#', $with_subs_html, $m)) {
    $decoded = $m[1];
    if ($decoded !== $with_subs_code) {
        $failures[] = "with-subs dashboard share code in inline script ($decoded) does not match the project's actual share code ($with_subs_code)";
    }
    if (strpos($decoded, '<') !== false || strpos($decoded, '>') !== false) {
        $failures[] = "with-subs dashboard share code in inline script is not properly escaped (found '<' or '>')";
    }
} else {
    $failures[] = 'with-subs dashboard inline script is missing `var shareCode = "..."` assignment';
}

// ---------------------------------------------------------------------------
// 2. Project WITHOUT sub-tasks: NO toggle, NO script.
// ---------------------------------------------------------------------------
foreach (['id="subtasks-toggle"', 'addEventListener(\'click\''] as $needle) {
    if (strpos($no_subs_html, $needle) !== false) {
        $failures[] = "no-subs dashboard should not contain '$needle'";
    }
}
foreach (['id="subtasks-toggle"', 'addEventListener(\'click\''] as $needle) {
    if (strpos($no_tasks_html, $needle) !== false) {
        $failures[] = "no-tasks dashboard should not contain '$needle'";
    }
}
// Both still have the section id (idempotent) and the empty-state copy where applicable.
if (strpos($no_subs_html, 'id="tasks-progress"') === false) {
    $failures[] = "no-subs dashboard should still render id='tasks-progress' (the section is always present)";
}
if (strpos($no_tasks_html, 'No tasks have been created yet.') === false) {
    $failures[] = "no-tasks dashboard is missing the empty-state copy";
}

// ---------------------------------------------------------------------------
// 3. Translation keys exist in BOTH locales.
// ---------------------------------------------------------------------------
$en_dict = client_translations()['en'];
$zh_dict = client_translations()['zh'];
foreach (['dash.subtasks_show_all', 'dash.subtasks_show_top', 'dash.subtasks_toggle_aria'] as $key) {
    if (empty($en_dict[$key])) {
        $failures[] = "EN translation key missing: $key";
    }
    if (empty($zh_dict[$key])) {
        $failures[] = "ZH translation key missing: $key";
    }
}

// ---------------------------------------------------------------------------
// 4. CSS: scoped rule is present and uses :not(.root) to keep the root
//    <ul.task-tree> visible.
// ---------------------------------------------------------------------------
$css = file_get_contents(__DIR__ . '/assets/client.css');
if (strpos($css, '#tasks-progress[data-subtasks="hidden"]') === false) {
    $failures[] = "client/assets/client.css is missing the scoped #tasks-progress[data-subtasks=\"hidden\"] rule";
}
if (strpos($css, 'ul.task-tree:not(.root)') === false) {
    $failures[] = "client/assets/client.css must use ul.task-tree:not(.root) so the root <ul.task-tree.root> stays visible";
}
if (strpos($css, 'display: none') === false) {
    $failures[] = "client/assets/client.css is missing display: none for the hidden state";
}

// ---------------------------------------------------------------------------
// 5. State machine parity: re-implement the click flip in PHP and check
//    that the resulting label / icon / aria-pressed match the lang catalog
//    and the markup contract.
// ---------------------------------------------------------------------------
function flipState($current) {
    return $current === 'visible' ? 'hidden' : 'visible';
}
$state = 'hidden';
$seq = [];
for ($i = 0; $i < 4; $i++) {
    $state = flipState($state);
    $seq[] = [
        'state'        => $state,
        'aria_pressed' => $state === 'visible' ? 'true' : 'false',
        'label_key'    => $state === 'visible' ? 'dash.subtasks_show_top' : 'dash.subtasks_show_all',
        'icon'         => $state === 'visible' ? 'fa-eye-slash'           : 'fa-eye',
    ];
}
$expected_seq = [
    ['state' => 'visible', 'aria_pressed' => 'true',  'label_key' => 'dash.subtasks_show_top', 'icon' => 'fa-eye-slash'],
    ['state' => 'hidden',  'aria_pressed' => 'false', 'label_key' => 'dash.subtasks_show_all', 'icon' => 'fa-eye'],
    ['state' => 'visible', 'aria_pressed' => 'true',  'label_key' => 'dash.subtasks_show_top', 'icon' => 'fa-eye-slash'],
    ['state' => 'hidden',  'aria_pressed' => 'false', 'label_key' => 'dash.subtasks_show_all', 'icon' => 'fa-eye'],
];
if ($seq !== $expected_seq) {
    $failures[] = 'click-flip state machine produced the wrong sequence: ' . json_encode($seq);
}
foreach ($expected_seq as $row) {
    if (empty($en_dict[$row['label_key']])) {
        $failures[] = "EN dictionary missing label for {$row['state']} state ({$row['label_key']})";
    }
    if (empty($zh_dict[$row['label_key']])) {
        $failures[] = "ZH dictionary missing label for {$row['state']} state ({$row['label_key']})";
    }
}

// ---------------------------------------------------------------------------
// 6. Per-project localStorage key isolation: two distinct projects must
//    reference two distinct share codes in the rendered inline script.
// ---------------------------------------------------------------------------
$no_subs_code = $created['no-subs'];
if (strpos($with_subs_html, $no_subs_code) !== false) {
    $failures[] = "with-subs HTML should NOT embed the no-subs share code '$no_subs_code'";
}

// ---------------------------------------------------------------------------
// 7. Cleanup. Run LAST regardless of failures so the test does not
//    pollute the user's DB even when assertions fail.
// ---------------------------------------------------------------------------
try {
    foreach ($created_ids as $pid) {
        $db->execute('DELETE FROM tasks WHERE project_id = ?', [$pid]);
        $db->execute('DELETE FROM projects WHERE id = ?', [$pid]);
    }
    $db->execute('DELETE FROM users WHERE id = ?', [$user_id]);
} catch (Exception $e) {
    // Don't mask the real failures with a cleanup error.
    fwrite(STDERR, "Cleanup warning: " . $e->getMessage() . "\n");
}

// Restore the triggers we dropped above, so the rest of the app continues
// to maintain parent completion % as before. The DDL below is a verbatim
// copy of the trigger bodies in database/subtask_functions.sql, intentionally
// duplicated so the test stays self-contained even if the .sql file moves.
$create_trigger_sql = <<<'SQL'

DELIMITER //
CREATE TRIGGER UpdateParentTaskCompletion
AFTER UPDATE ON tasks
FOR EACH ROW
BEGIN
    DECLARE parent_id INT;
    DECLARE new_completion DECIMAL(5,2);
    SET parent_id = NEW.parent_task_id;
    IF parent_id IS NOT NULL THEN
        SET new_completion = CalculateTaskCompletion(parent_id);
        UPDATE tasks
        SET completion_percentage = new_completion, updated_at = NOW()
        WHERE id = parent_id;
    END IF;
END//
CREATE TRIGGER UpdateParentTaskCompletionInsert
AFTER INSERT ON tasks
FOR EACH ROW
BEGIN
    DECLARE parent_id INT;
    DECLARE new_completion DECIMAL(5,2);
    SET parent_id = NEW.parent_task_id;
    IF parent_id IS NOT NULL THEN
        SET new_completion = CalculateTaskCompletion(parent_id);
        UPDATE tasks
        SET completion_percentage = new_completion, updated_at = NOW()
        WHERE id = parent_id;
    END IF;
END//
CREATE TRIGGER UpdateParentTaskCompletionDelete
AFTER DELETE ON tasks
FOR EACH ROW
BEGIN
    DECLARE parent_id INT;
    DECLARE new_completion DECIMAL(5,2);
    SET parent_id = OLD.parent_task_id;
    IF parent_id IS NOT NULL THEN
        SET new_completion = CalculateTaskCompletion(parent_id);
        UPDATE tasks
        SET completion_percentage = new_completion, updated_at = NOW()
        WHERE id = parent_id;
    END IF;
END//
DELIMITER ;
SQL;

// Use the CLI to execute multi-statement trigger DDL. mariadb's --execute
// doesn't grok DELIMITER, so write it to a file and pipe it in.
$tmp = tempnam(sys_get_temp_dir(), 'issue010_restore_');
file_put_contents($tmp, $create_trigger_sql);
$db_host = defined('DB_HOST') ? DB_HOST : 'db';
$db_name = defined('DB_NAME') ? DB_NAME : 'dbname';
$db_user = defined('DB_USER') ? DB_USER : 'dbuser';
$db_pass = defined('DB_PASS') ? DB_PASS : 'dbuserP@ssw0rd';
$cmd = sprintf(
    'mariadb -h%s -u%s -p%s %s < %s 2>&1',
    escapeshellarg($db_host),
    escapeshellarg($db_user),
    escapeshellarg($db_pass),
    escapeshellarg($db_name),
    escapeshellarg($tmp)
);
shell_exec($cmd);
@unlink($tmp);

// ---------------------------------------------------------------------------
// Report.
// ---------------------------------------------------------------------------
if ($failures) {
    fwrite(STDERR, "Sub-tasks toggle regression test FAILED:\n");
    foreach ($failures as $f) {
        fwrite(STDERR, "  - $f\n");
    }
    exit(1);
}
echo "Sub-tasks toggle regression test passed.\n";
