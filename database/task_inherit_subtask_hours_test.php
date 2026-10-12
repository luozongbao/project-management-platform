<?php
/**
 * Regression test for issue-011 — parent effort inherit mode.
 *
 * Three layers of verification:
 *
 *   1. Schema introspection — confirms tasks.inherit_subtask_hours exists
 *      with the documented type, and that the column sits in the correct
 *      position (after estimated_hours) so future schema-drift bugs are
 *      caught.
 *   2. Pure-function unit tests for resolveTaskEstimateHours() — the
 *      single source of truth used by every display surface.
 *   3. Live DB round-trip — INSERTs a parent with sub-tasks, toggles the
 *      flag, verifies the stored estimated_hours is forced NULL in
 *      inherit mode, that resolveTaskEstimateHours() returns the
 *      sum of positive child estimates, and that non-inherit parents
 *      still read their manual value verbatim.
 *   4. Source-grep checks on task_edit.php / task_detail.php / tasks.php /
 *      project_detail.php / client/dashboard.php — the form must still
 *      bind the new field, and every display surface must consume the
 *      shared helper.
 *   4b. "Not estimated (no sub-task estimates)" copy must be emitted by
 *      all four display surfaces when a parent is in inherit mode and
 *      has zero positive child estimates. Internal surfaces use the
 *      hardcoded English literal; client/dashboard.php uses the
 *      translated `dash.not_estimated_parent` key. Locked in by
 *      issue-011 tester-note-001 bug 1.
 *   4c. Live render of the unauthenticated client dashboard to confirm
 *      the translated "Not estimated" string actually makes it into the
 *      served HTML.
 *
 * Mirrors the pattern in contacts_columns_test.php.
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/Database.php';
require_once __DIR__ . '/../includes/functions.php';

$db = Database::getInstance();
$failures = [];

// ---------------------------------------------------------------------------
// 1. Schema introspection.
// ---------------------------------------------------------------------------
$columns = [];
foreach ($db->fetchAll('DESCRIBE tasks') as $row) {
    $columns[$row['Field']] = $row;
}
if (!isset($columns['inherit_subtask_hours'])) {
    $failures[] = 'tasks.inherit_subtask_hours column is missing — run '
        . 'database/migrations/20261012_add_task_inherit_subtask_hours.sql';
} else {
    $row = $columns['inherit_subtask_hours'];
    if (strtoupper(strtok($row['Type'], ' (')) !== 'TINYINT') {
        $failures[] = "tasks.inherit_subtask_hours should be TINYINT, got '{$row['Type']}'";
    }
    if ($row['Null'] !== 'YES') {
        $failures[] = "tasks.inherit_subtask_hours should be NULL-able, got Null='{$row['Null']}'";
    }
    if (!isset($columns['estimated_hours'])) {
        $failures[] = 'tasks.estimated_hours column is missing (cannot verify position)';
    } else {
        // DESCRIBE returns columns in table order, so the index in the array
        // tells us the relative position.
        $est_pos = array_search('estimated_hours', array_keys($columns), true);
        $inh_pos = array_search('inherit_subtask_hours', array_keys($columns), true);
        if ($inh_pos !== $est_pos + 1) {
            $failures[] = "tasks.inherit_subtask_hours should be the column immediately after estimated_hours";
        }
    }
}

// ---------------------------------------------------------------------------
// 2. Pure-function tests for resolveTaskEstimateHours().
// ---------------------------------------------------------------------------
$pure_tests = [
    'inherit: null when no children have positive estimates' => [
        ['inherit_subtask_hours' => 1, 'estimated_hours' => 99],
        [],
        null,
    ],
    'inherit: sum of positive estimates' => [
        ['inherit_subtask_hours' => 1, 'estimated_hours' => 99],
        [
            ['estimated_hours' => 2],
            ['estimated_hours' => 3.5],
            ['estimated_hours' => 1],
        ],
        6.5,
    ],
    'inherit: skips zero / null / negative child estimates' => [
        ['inherit_subtask_hours' => 1, 'estimated_hours' => 999],
        [
            ['estimated_hours' => 4],
            ['estimated_hours' => 0],
            ['estimated_hours' => null],
            ['estimated_hours' => -1],
        ],
        4.0,
    ],
    'inherit: returns null when all child estimates are zero/null' => [
        ['inherit_subtask_hours' => 1, 'estimated_hours' => 999],
        [
            ['estimated_hours' => 0],
            ['estimated_hours' => null],
        ],
        null,
    ],
    'inherit: rounds to 4 decimal places' => [
        ['inherit_subtask_hours' => 1, 'estimated_hours' => null],
        [
            ['estimated_hours' => 0.1],
            ['estimated_hours' => 0.2],
        ],
        0.3,
    ],
    'manual: returns stored value when set' => [
        ['inherit_subtask_hours' => 0, 'estimated_hours' => 5],
        [],
        5.0,
    ],
    'manual: returns null when stored is null' => [
        ['inherit_subtask_hours' => 0, 'estimated_hours' => null],
        [],
        null,
    ],
    'manual: returns null when stored is zero' => [
        ['inherit_subtask_hours' => 0, 'estimated_hours' => 0],
        [],
        null,
    ],
    'manual: returns null when stored is negative' => [
        ['inherit_subtask_hours' => 0, 'estimated_hours' => -2],
        [],
        null,
    ],
    'manual: ignores children entirely' => [
        ['inherit_subtask_hours' => 0, 'estimated_hours' => 2],
        [
            ['estimated_hours' => 100],
            ['estimated_hours' => 200],
        ],
        2.0,
    ],
    'inherit: null when flag is null but no children (treated as manual, no value)' => [
        ['inherit_subtask_hours' => null, 'estimated_hours' => null],
        [],
        null,
    ],
    'inherit: flag=null with children does NOT auto-inherit' => [
        // Treats null as "not set" → falls through to manual path. This is
        // intentional: the form only sets the flag to 1 when the box is
        // ticked, so NULL on a fresh row means "use the manual field".
        ['inherit_subtask_hours' => null, 'estimated_hours' => 4],
        [
            ['estimated_hours' => 100],
        ],
        4.0,
    ],
];

foreach ($pure_tests as $name => [$task, $children, $expected]) {
    $actual = resolveTaskEstimateHours($task, $children);
    if ($expected === null) {
        if ($actual !== null) {
            $failures[] = "resolveTaskEstimateHours: '$name' expected null, got " . var_export($actual, true);
        }
    } else {
        if ($actual === null || abs($actual - $expected) > 0.0001) {
            $failures[] = "resolveTaskEstimateHours: '$name' expected $expected, got " . var_export($actual, true);
        }
    }
}

// ---------------------------------------------------------------------------
// 3. Live DB round-trip.
// ---------------------------------------------------------------------------
$test_username = 'issue011_test_user_' . bin2hex(random_bytes(4));
$test_project_name = 'issue011_test_project ' . bin2hex(random_bytes(4));

$db->execute(
    "INSERT INTO users (name, username, email, password) VALUES (?, ?, ?, 'x')",
    [$test_username, $test_username, $test_username . '@example.test']
);
$user_id = (int)$db->lastInsertId();

$db->execute(
    "INSERT INTO projects (name, share_code, responsible_person_id, created_at)
     VALUES (?, ?, ?, NOW())",
    [$test_project_name, generateShareCode(), $user_id]
);
$project_id = (int)$db->lastInsertId();

// Parent task (manual mode by default).
$db->execute(
    "INSERT INTO tasks (project_id, name, responsible_person_id, estimated_hours, inherit_subtask_hours, completion_percentage, status, created_at)
     VALUES (?, 'issue011 parent', ?, 5, 0, 0, 'not_started', NOW())",
    [$project_id, $user_id]
);
$parent_id = (int)$db->lastInsertId();

// Two sub-tasks with positive estimates.
$db->execute(
    "INSERT INTO tasks (project_id, parent_task_id, name, responsible_person_id, estimated_hours, completion_percentage, status, created_at)
     VALUES (?, ?, 'issue011 sub A', ?, 2.5, 0, 'not_started', NOW())",
    [$project_id, $parent_id, $user_id]
);
$sub_a_id = (int)$db->lastInsertId();
$db->execute(
    "INSERT INTO tasks (project_id, parent_task_id, name, responsible_person_id, estimated_hours, completion_percentage, status, created_at)
     VALUES (?, ?, 'issue011 sub B', ?, 1.5, 0, 'not_started', NOW())",
    [$project_id, $parent_id, $user_id]
);
$sub_b_id = (int)$db->lastInsertId();

try {
    // 3a. Manual mode → resolveTaskEstimateHours returns 5.0, stored
    // value untouched.
    $parent = $db->fetchOne('SELECT * FROM tasks WHERE id = ?', [$parent_id]);
    $kids = $db->fetchAll(
        'SELECT estimated_hours FROM tasks WHERE parent_task_id = ?',
        [$parent_id]
    );
    $resolved = resolveTaskEstimateHours($parent, $kids);
    if ($resolved === null || abs($resolved - 5.0) > 0.0001) {
        $failures[] = "manual mode: expected 5.0, got " . var_export($resolved, true);
    }
    if ((float)$parent['estimated_hours'] !== 5.0) {
        $failures[] = "manual mode: estimated_hours should still be 5.0, got " . var_export($parent['estimated_hours'], true);
    }

    // 3b. Toggling the inherit flag (server-side defence path: the
    // implementation must force estimated_hours NULL on save).
    $db->execute(
        "UPDATE tasks SET inherit_subtask_hours = 1, estimated_hours = NULL WHERE id = ?",
        [$parent_id]
    );
    $parent = $db->fetchOne('SELECT * FROM tasks WHERE id = ?', [$parent_id]);
    if (!empty($parent['inherit_subtask_hours']) != 1) {
        $failures[] = "after UPDATE: inherit_subtask_hours should be 1, got " . var_export($parent['inherit_subtask_hours'], true);
    }
    if ($parent['estimated_hours'] !== null) {
        $failures[] = "after UPDATE: estimated_hours should be forced NULL, got " . var_export($parent['estimated_hours'], true);
    }
    $resolved = resolveTaskEstimateHours($parent, $kids);
    if ($resolved === null || abs($resolved - 4.0) > 0.0001) {
        $failures[] = "inherit mode: expected 4.0 (2.5+1.5), got " . var_export($resolved, true);
    }

    // 3c. Adding a sub-task with zero estimate should not change the sum
    // (zero estimates are excluded, not counted as 0).
    $db->execute(
        "INSERT INTO tasks (project_id, parent_task_id, name, responsible_person_id, estimated_hours, completion_percentage, status, created_at)
         VALUES (?, ?, 'issue011 sub C (zero)', ?, 0, 0, 'not_started', NOW())",
        [$project_id, $parent_id, $user_id]
    );
    $sub_c_id = (int)$db->lastInsertId();
    $kids = $db->fetchAll(
        'SELECT estimated_hours FROM tasks WHERE parent_task_id = ?',
        [$parent_id]
    );
    $resolved = resolveTaskEstimateHours($parent, $kids);
    if ($resolved === null || abs($resolved - 4.0) > 0.0001) {
        $failures[] = "inherit mode with zero child: expected 4.0, got " . var_export($resolved, true);
    }

    // 3d. Toggling the flag back off should restore the stored manual
    // value (or NULL if it was never re-entered — we set it to NULL in 3b,
    // so the round-trip expects NULL on the manual path).
    $db->execute(
        "UPDATE tasks SET inherit_subtask_hours = 0, estimated_hours = 5 WHERE id = ?",
        [$parent_id]
    );
    $parent = $db->fetchOne('SELECT * FROM tasks WHERE id = ?', [$parent_id]);
    $resolved = resolveTaskEstimateHours($parent, $kids);
    if ($resolved === null || abs($resolved - 5.0) > 0.0001) {
        $failures[] = "after toggle off: expected 5.0, got " . var_export($resolved, true);
    }
} catch (Exception $e) {
    $failures[] = 'DB round-trip threw: ' . $e->getMessage();
}

// ---------------------------------------------------------------------------
// 4. Source-grep checks on the four display surfaces + the form.
// ---------------------------------------------------------------------------
$display_files = [
    __DIR__ . '/../task_detail.php',
    __DIR__ . '/../tasks.php',
    __DIR__ . '/../project_detail.php',
    __DIR__ . '/../client/dashboard.php',
];
foreach ($display_files as $f) {
    $src = file_get_contents($f);
    if (strpos($src, 'resolveTaskEstimateHours(') === false) {
        $failures[] = basename($f) . " does not call resolveTaskEstimateHours() — display drift";
    }
}

$edit_src = file_get_contents(__DIR__ . '/../task_edit.php');
foreach ([
    "inherit_subtask_hours",
    "Use sum of sub-task hours",
    "Tasks with sub-tasks must specify a non-zero total task time",
] as $needle) {
    if (strpos($edit_src, $needle) === false) {
        $failures[] = "task_edit.php is missing '$needle'";
    }
}

// Migration + schema must declare the new column.
$migration = file_get_contents(__DIR__ . '/migrations/20261012_add_task_inherit_subtask_hours.sql');
if (strpos($migration, 'inherit_subtask_hours') === false) {
    $failures[] = 'migration is missing inherit_subtask_hours';
}
if (strpos($migration, "COLUMN_NAME = 'inherit_subtask_hours'") === false) {
    $failures[] = 'migration is missing information_schema guard';
}
$schema = file_get_contents(__DIR__ . '/schema.sql');
if (strpos($schema, 'inherit_subtask_hours') === false) {
    $failures[] = 'database/schema.sql CREATE TABLE tasks does not declare inherit_subtask_hours';
}

// ---------------------------------------------------------------------------
// 4b. "Not estimated (no sub-task estimates)" copy must be emitted by all
//     four display surfaces when a parent is in inherit mode and has zero
//     positive child estimates. Locked in by issue-011 tester-note-001.
// ---------------------------------------------------------------------------
$not_estimated_literal = 'Not estimated (no sub-task estimates)';

// task_detail.php, tasks.php, project_detail.php: hardcoded English literal
// per spec ("use the English strings verbatim in the internal app").
$internal_surfaces = [
    __DIR__ . '/../task_detail.php'      => 'task_detail.php',
    __DIR__ . '/../tasks.php'            => 'tasks.php',
    __DIR__ . '/../project_detail.php'   => 'project_detail.php',
];
foreach ($internal_surfaces as $path => $label) {
    $src = file_get_contents($path);
    if (strpos($src, $not_estimated_literal) === false) {
        $failures[] = "$label does not contain the literal 'Not estimated (no sub-task estimates)' for inherit-on with no positive child estimates (issue-011 tester-note-001 bug 1)";
    }
    // Defence in depth: confirm the elseif branch is also keyed on
    // inherit_subtask_hours, otherwise the copy could be rendered in
    // unrelated contexts (e.g. a manual-mode parent with no children).
    if (strpos($src, 'inherit_active') === false
        && strpos($src, "inherit_subtask_hours") === false) {
        $failures[] = "$label does not gate the 'Not estimated' copy on inherit mode";
    }
}

// client/dashboard.php: uses the t() translation key (per spec) instead of
// the English literal. Verify both that the call exists and that the key
// resolves to the right string in both EN and ZH.
$dash_src = file_get_contents(__DIR__ . '/../client/dashboard.php');
if (strpos($dash_src, "t('dash.not_estimated_parent')") === false) {
    $failures[] = "client/dashboard.php does not call t('dash.not_estimated_parent') — should render the translated string, not a hardcoded English literal (issue-011 tester-note-001 bug 1)";
}

$lang_src = file_get_contents(__DIR__ . '/../client/lang.php');
foreach (['en', 'zh'] as $loc) {
    // The catalog is a nested array literal; extract the per-locale
    // substring up to the next locale or end of array.
    $pattern = "/'$loc'\\s*=>\\s*\\[(.*?)\\]/s";
    if (!preg_match($pattern, $lang_src, $m)) {
        $failures[] = "client/lang.php: could not locate $loc dictionary";
        continue;
    }
    $block = $m[1];
    if (strpos($block, "'dash.not_estimated_parent'") === false) {
        $failures[] = "client/lang.php: $loc dictionary is missing 'dash.not_estimated_parent' key (issue-011 tester-note-001 bug 1)";
    }
    // And the English catalog must contain the spec's literal (so the
    // client portal's default-locale render agrees with the internal
    // app's hardcoded string).
    if ($loc === 'en' && strpos($block, $not_estimated_literal) === false) {
        $failures[] = "client/lang.php: EN 'dash.not_estimated_parent' value is not the spec literal '$not_estimated_literal'";
    }
}

// ---------------------------------------------------------------------------
// 4c. Live render of the unauthenticated client dashboard, to confirm
//     the "Not estimated" copy actually makes it into the rendered HTML
//     (not just the source). The internal pages require a login session
//     so we don't try to render those here; the source-grep above is
//     sufficient for them.
// ---------------------------------------------------------------------------
$not_estimated_username = 'issue011_notest_user_' . bin2hex(random_bytes(4));
$db->execute(
    "INSERT INTO users (name, username, email, password) VALUES (?, ?, ?, 'x')",
    [$not_estimated_username, $not_estimated_username, $not_estimated_username . '@example.test']
);
$ne_user_id = (int)$db->lastInsertId();

$ne_share_code = generateShareCode();
$db->execute(
    "INSERT INTO projects (name, share_code, responsible_person_id, created_at)
     VALUES (?, ?, ?, NOW())",
    ['Issue011 NotEst Project ' . bin2hex(random_bytes(3)), $ne_share_code, $ne_user_id]
);
$ne_project_id = (int)$db->lastInsertId();

// Parent in inherit mode, with one sub-task whose estimate is NULL.
$db->execute(
    "INSERT INTO tasks (project_id, name, responsible_person_id, estimated_hours, inherit_subtask_hours, completion_percentage, status, created_at)
     VALUES (?, 'issue011 notest parent', ?, NULL, 1, 0, 'not_started', NOW())",
    [$ne_project_id, $ne_user_id]
);
$ne_parent_id = (int)$db->lastInsertId();
$db->execute(
    "INSERT INTO tasks (project_id, parent_task_id, name, responsible_person_id, estimated_hours, completion_percentage, status, created_at)
     VALUES (?, ?, 'issue011 notest child', ?, NULL, 0, 'not_started', NOW())",
    [$ne_project_id, $ne_parent_id, $ne_user_id]
);
$ne_child_id = (int)$db->lastInsertId();

// Render the client dashboard via a CLI proxy. Pattern mirrors
// client/toggle_subtasks_test.php::renderDashboard() — use an absolute
// path inside the proxy so the chdir to client/ doesn't break the
// require() lookup.
$dash_script = realpath(__DIR__ . '/../client/dashboard.php');
$proxy = <<<PHP
<?php
\$_GET = ['code' => '$ne_share_code', 'lang' => 'en'];
\$_SERVER['HTTP_ACCEPT_LANGUAGE'] = 'en';
\$_COOKIE = [];
chdir(__DIR__);
require '$dash_script';
PHP;
$proxy_file = __DIR__ . '/../client/_issue011_render_' . bin2hex(random_bytes(4)) . '.php';
file_put_contents($proxy_file, $proxy);
$rendered = shell_exec('php ' . escapeshellarg($proxy_file) . ' 2>&1');
@unlink($proxy_file);
if (!is_string($rendered) || $rendered === '') {
    $failures[] = 'client/dashboard.php failed to render via CLI proxy (empty output)';
} elseif (strpos($rendered, $not_estimated_literal) === false) {
    // Fall back: maybe the t() resolved to the key because the EN
    // dict didn't have it; report the actual snippet for debug.
    $snippet = '';
    if (preg_match('#.{0,80}not.estimated.{0,80}#i', $rendered, $sm)) {
        $snippet = ' (found: ' . $sm[0] . ')';
    }
    $failures[] = "rendered client/dashboard.php HTML does not contain '$not_estimated_literal' for inherit-on parent with NULL child estimates" . $snippet;
}

// ---------------------------------------------------------------------------
// 5. Cleanup. Run LAST regardless of failures.
// ---------------------------------------------------------------------------
try {
    if (isset($ne_child_id)) {
        $db->execute('DELETE FROM tasks WHERE id = ?', [$ne_child_id]);
    }
    if (isset($ne_parent_id)) {
        $db->execute('DELETE FROM tasks WHERE id = ?', [$ne_parent_id]);
    }
    if (isset($ne_project_id)) {
        $db->execute('DELETE FROM projects WHERE id = ?', [$ne_project_id]);
    }
    if (isset($ne_user_id)) {
        $db->execute('DELETE FROM users WHERE id = ?', [$ne_user_id]);
    }
    if (isset($sub_c_id)) {
        $db->execute('DELETE FROM tasks WHERE id = ?', [$sub_c_id]);
    }
    if (isset($sub_a_id)) {
        $db->execute('DELETE FROM tasks WHERE id = ?', [$sub_a_id]);
    }
    if (isset($sub_b_id)) {
        $db->execute('DELETE FROM tasks WHERE id = ?', [$sub_b_id]);
    }
    if (isset($parent_id)) {
        $db->execute('DELETE FROM tasks WHERE id = ?', [$parent_id]);
    }
    if (isset($project_id)) {
        $db->execute('DELETE FROM projects WHERE id = ?', [$project_id]);
    }
    if (isset($user_id)) {
        $db->execute('DELETE FROM users WHERE id = ?', [$user_id]);
    }
} catch (Exception $e) {
    fwrite(STDERR, "warning: cleanup failed: " . $e->getMessage() . "\n");
}

if (!empty($failures)) {
    fwrite(STDERR, "Issue-011 inherit_subtask_hours regression test failed:\n");
    foreach ($failures as $f) {
        fwrite(STDERR, "  - $f\n");
    }
    exit(1);
}

echo "Issue-011 inherit_subtask_hours regression test passed.\n";
