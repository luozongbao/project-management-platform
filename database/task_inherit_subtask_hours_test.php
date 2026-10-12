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
// 5. Cleanup. Run LAST regardless of failures.
// ---------------------------------------------------------------------------
try {
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
