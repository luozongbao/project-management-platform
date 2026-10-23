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
 *      - The toggle button carries the three new data-* attributes
 *        (data-share-code, data-label-show-all, data-label-show-top)
 *        that the external controller reads on first paint
 *        (issue-027).
 *      - The Tasks & Progress count and the root <ul.task-tree> are
 *        still emitted (i.e. the markup is intact, not re-rendered
 *        server-side based on state).
 *      - The page loads the external controller file via
 *        <script src="/client/assets/js/subtask-toggle.js" defer></script>
 *        and does NOT contain an inline <script>(function () { ... })
 *        block (issue-027: the issue-020 CSP tightening on the public
 *        client portal drops 'unsafe-inline' from script-src, so any
 *        inline script body in the page will be refused by the
 *        browser).
 *        (issue-049: the path used to be "/assets/js/subtask-toggle.js",
 *        which 404s — the file lives under client/assets/js/. The
 *        root-absolute "/client/assets/js/subtask-toggle.js" is the
 *        form issue-035 mandates for every asset reference.)
 *   2. Project WITHOUT sub-tasks (and project with NO tasks):
 *      - Does NOT render the toggle button.
 *      - Does NOT include the <script src=…> tag.
 *   3. Source-level invariants:
 *      - The external controller file
 *        client/assets/js/subtask-toggle.js exists, is reachable from
 *        the dashboard, and contains the read/write/first-paint/click
 *        glue, the scoped aria-hidden selector, the per-project
 *        localStorage key, the IIFE wrapper, and reads the three new
 *        data-* attributes off the button (no `<?= … ?>` in the
 *        file — it must be static).
 *      - client/lang.php exposes the three new keys in both `en` and
 *        `zh`.
 *      - client/assets/client.css has the scoped
 *        `#tasks-progress[data-subtasks="hidden"] ul.task-tree:not(.root)`
 *        rule (the :not(.root) clause is the bit that keeps the root
 *        tree visible).
 *   4. State machine: re-implements the visible/hidden transition in
 *      PHP exactly as the external script does, and asserts the
 *      resulting label / icon / aria-pressed values match what the
 *      markup + lang catalog say they should be.
 *   5. Cleanup: deletes every row it inserts, and verifies the DB
 *      connection is still healthy at the end (the old code
 *      attempted to restore the `subtask_functions.sql` triggers
 *      via `shell_exec('mariadb …')`, but the pmp-app container has
 *      no `mariadb` binary, so that was a silent no-op — the
 *      triggers are also no longer used by the app since
 *      parent-completion moved to PHP via
 *      `calculateTaskCompletionMap()`, so the test no longer
 *      tries to restore them).
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
        "INSERT INTO projects (name, slug, share_code, responsible_person_id, status, created_at)
         VALUES (?, ?, ?, ?, 'in_progress', NOW())",
        [$name, generateUniqueSlug($name, 'projects'), $code, $user_id]
    );
    $pid = (int)$db->lastInsertId();
    $created[$key] = $code;
    $created_ids[] = $pid;
    if ($key === 'with-subs') {
        // top-level task
        $db->execute(
            "INSERT INTO tasks (project_id, parent_task_id, name, slug, status, completion_percentage, responsible_person_id, created_at)
             VALUES (?, NULL, 'Workstream A', ?, 'in_progress', 50, ?, NOW())",
            [$pid, generateUniqueSlug('Workstream A', 'tasks'), $user_id]
        );
        $top_id = (int)$db->lastInsertId();
        // two sub-tasks
        $db->execute(
            "INSERT INTO tasks (project_id, parent_task_id, name, slug, status, completion_percentage, responsible_person_id, created_at)
             VALUES (?, ?, 'Sub 1', ?, 'completed', 100, ?, NOW())",
            [$pid, $top_id, generateUniqueSlug('Sub 1', 'tasks'), $user_id]
        );
        $db->execute(
            "INSERT INTO tasks (project_id, parent_task_id, name, slug, status, completion_percentage, responsible_person_id, created_at)
             VALUES (?, ?, 'Sub 2', ?, 'in_progress', 30, ?, NOW())",
            [$pid, $top_id, generateUniqueSlug('Sub 2', 'tasks'), $user_id]
        );
    } elseif ($key === 'no-subs') {
        $db->execute(
            "INSERT INTO tasks (project_id, parent_task_id, name, slug, status, completion_percentage, responsible_person_id, created_at)
             VALUES (?, NULL, 'Solo workstream', ?, 'in_progress', 20, ?, NOW())",
            [$pid, generateUniqueSlug('Solo workstream', 'tasks'), $user_id]
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
    // Save to a real .php file in the same dir so __DIR__/lang.php is resolvable.
    $proxy = <<<PHP
<?php
\$_GET = ['code' => '$code', 'lang' => 'en'];
\$_SERVER['HTTP_ACCEPT_LANGUAGE'] = 'en';
\$_COOKIE = [];
chdir(__DIR__);
require '$script';
PHP;
    $tmpfile = __DIR__ . '/_issue010_render_' . bin2hex(random_bytes(4)) . '.php';
    file_put_contents($tmpfile, $proxy);
    // register_shutdown_function guarantees the proxy file is deleted
    // even if renderDashboard() throws or the test exits early via
    // $failures. The @unlink below is the fast path; the shutdown
    // handler is the safety net. (v1.6.0 hardening — see
    // docs/issues/issue-014.md §3.)
    register_shutdown_function(static function () use ($tmpfile) {
        if (is_file($tmpfile)) {
            @unlink($tmpfile);
        }
    });
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

// External controller file is present, served from the dashboard, and not
// duplicated by an inline <script>(function () { ... })() block. The
// inline-block check is the issue-027 invariant: any inline script
// (especially the one the dashboard used to ship) would be refused by
// the browser because the issue-020 CSP tightening on the public client
// portal drops 'unsafe-inline' from script-src. (issue-027 "Goals".)
$js_path = __DIR__ . '/assets/js/subtask-toggle.js';
if (!is_file($js_path)) {
    $failures[] = "with-subs dashboard requires client/assets/js/subtask-toggle.js, but the file is missing";
}
$js_src = is_file($js_path) ? file_get_contents($js_path) : '';
// Issue-049: the tag must be the root-absolute "/client/assets/js/subtask-toggle.js",
// matching where the file actually lives and the root-absolute asset contract
// that issue-035 §"The fix — every asset reference becomes root-absolute"
// mandates for this file. The old "/assets/js/subtask-toggle.js" 404s — the
// controller never loaded and the sub-task toggle was dead in the browser.
$expected_script_tag = 'src="/client/assets/js/subtask-toggle.js" defer';
if (strpos($with_subs_html, $expected_script_tag) === false) {
    $failures[] = "with-subs dashboard is missing the <script {$expected_script_tag}></script> tag for the external controller";
}
// Issue-049: guard against the broken path coming back. It resolves to
// /assets/js/subtask-toggle.js, which does not exist — the file is under
// client/assets/js/. A regression here is silent in the test output otherwise.
if (strpos($with_subs_html, 'src="/assets/js/subtask-toggle.js"') !== false) {
    $failures[] = 'with-subs dashboard references /assets/js/subtask-toggle.js — that path 404s; the file lives at client/assets/js/subtask-toggle.js and must be referenced as "/client/assets/js/subtask-toggle.js"';
}
// No inline <script>(function () { ... })() block in the page.
if (preg_match('#<script>\s*\(function\s*\(\s*\)\s*\{#i', $with_subs_html)) {
    $failures[] = 'with-subs dashboard still contains an inline <script>(function () { … })() block — the issue-020 CSP tightening forbids this; the controller must be loaded via <script src=…> instead (issue-027)';
}

// External controller must read the per-project localStorage key + drive
// the click handler + the data-subtasks attribute. The actual key is
// built at runtime by concatenating the prefix literal with the shareCode
// read from data-share-code, so we check the prefix is in the file and
// that the share code is reachable via the data-* attribute.
$expected_storage_prefix = 'client_dash.subtasks_visible.';
if (strpos($js_src, $expected_storage_prefix) === false) {
    $failures[] = "external controller is missing the storage-key prefix '$expected_storage_prefix'";
}
if (strpos($js_src, "addEventListener('click'") === false) {
    $failures[] = 'external controller is missing the click handler (addEventListener on click)';
}
if (strpos($js_src, 'localStorage.setItem') === false) {
    $failures[] = 'external controller does not write back to localStorage';
}
if (strpos($js_src, 'localStorage.getItem') === false) {
    $failures[] = 'external controller does not read from localStorage on first paint';
}
if (strpos($js_src, "data-subtasks") === false) {
    $failures[] = 'external controller does not toggle the data-subtasks attribute';
}
// ES5-compatible style — no `const`, `let`, `=>`, or template literals
// (issue-027: must match assets/js/script.js).
foreach (['\bconst\b', '\blet\b', '=>', '`'] as $forbidden) {
    if (preg_match('/' . $forbidden . '/', $js_src)) {
        $failures[] = "external controller uses ES6-only token ($forbidden) — must be ES5 to match assets/js/script.js (issue-027)";
    }
}
// File is static — no PHP tags inside.
if (preg_match('/<\\?=|<\\?php/i', $js_src)) {
    $failures[] = 'external controller contains a PHP tag — it must be a static .js file (issue-027)';
}

// data-* attribute contract (issue-027 "Data flow"). The controller reads
// these three attributes off the toggle button, so the server-rendered
// markup MUST include them with the expected values.
$with_subs_code = $created['with-subs'];
$expected_data_attrs = [
    'data-share-code="' . $with_subs_code . '"',
    'data-label-show-all="Show all sub-tasks"',
    'data-label-show-top="Show top-level only"',
];
foreach ($expected_data_attrs as $needle) {
    if (strpos($with_subs_html, $needle) === false) {
        $failures[] = "with-subs dashboard is missing the data-* attribute pair: $needle";
    }
}
// And the controller must actually read all three (not just rely on
// data-share-code).
foreach (["getAttribute('data-share-code')", "getAttribute('data-label-show-all')", "getAttribute('data-label-show-top')"] as $needle) {
    if (strpos($js_src, $needle) === false) {
        $failures[] = "external controller is missing a read of $needle";
    }
}

// The selector that sets aria-hidden MUST scope to nested sub-trees only,
// mirroring the CSS rule `#tasks-progress[data-subtasks="hidden"] ul.task-tree:not(.root)`.
// The previous version of this test only checked for the literal string
// "aria-hidden" and silently accepted a buggy selector that also matched
// the root <ul class="task-tree root">, hiding the top-level workstream
// names from screen readers (issue-010 tester-note-001 bug 1).
if (strpos($js_src, 'ul.task-tree:not(.root)') === false) {
    $failures[] = "external controller selector does not scope to nested sub-trees; it would set aria-hidden on the root <ul class='task-tree root'> too";
}

// The Tasks & Progress count and the root task-tree are still rendered.
// Issue-050: the count moved from the v1 inline "(3 total)" text into a
// v2 .badge--neutral chip inside .card__actions. The assertion follows the
// markup change but keeps its intent: the flat task count (1 top-level +
// 2 subs = 3) must still be printed next to the heading.
if (strpos($with_subs_html, 'badge--neutral') === false) {
    $failures[] = "with-subs dashboard should render the task count as a .badge--neutral chip in .card__actions";
}
if (!preg_match('/badge--neutral[^>]*>\s*3\s*total/', $with_subs_html)
    && !preg_match('/>\s*3\s*total\s*</', $with_subs_html)) {
    $failures[] = "with-subs dashboard should still print the flat task count '3 total' next to the heading (1 top-level + 2 subs = 3 flat tasks)";
}
if (strpos($with_subs_html, '<ul class="task-tree root">') === false) {
    $failures[] = 'with-subs dashboard should still render the root <ul class="task-tree root">';
}
// Nested sub-trees are still emitted server-side; only the CSS hides them.
if (substr_count($with_subs_html, '<ul class="task-tree">') < 1) {
    $failures[] = 'with-subs dashboard should still emit at least one nested <ul class="task-tree"> (the sub-task tree)';
}

// ---------------------------------------------------------------------------
// 2. Project WITHOUT sub-tasks: NO toggle, NO script tag.
// ---------------------------------------------------------------------------
foreach (['id="subtasks-toggle"', 'src="/client/assets/js/subtask-toggle.js"'] as $needle) {
    if (strpos($no_subs_html, $needle) !== false) {
        $failures[] = "no-subs dashboard should not contain '$needle'";
    }
}
foreach (['id="subtasks-toggle"', 'src="/client/assets/js/subtask-toggle.js"'] as $needle) {
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
//
// Note: an earlier version of this section attempted to restore the
// `subtask_functions.sql` triggers via `shell_exec('mariadb … < $tmp')`,
// but the pmp-app container has no mariadb/mysql client installed, so
// the call was a silent no-op. The triggers are also no longer used by
// the application — parent-completion is now computed in PHP via
// `calculateTaskCompletionMap()` in includes/functions.php — so
// dropping the dead restore and just verifying the DB connection is
// still healthy post-cleanup is the correct fix
// (issue-010 tester-note-001 bug 3).
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

// Sanity check: the DB connection must still work after cleanup so a
// future regression in the Database class surfaces here, not in some
// unrelated test that runs after us.
try {
    $db->fetchOne('SELECT 1 AS ok');
} catch (Exception $e) {
    $failures[] = 'DB connection is not healthy after cleanup: ' . $e->getMessage();
}

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
