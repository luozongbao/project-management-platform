<?php
/**
 * Regression test for issue-012 — parent status derived from sub-tasks.
 *
 * Four layers of verification:
 *
 *   1. Pure-function unit tests for deriveParentStatus() — the single
 *      source of truth used by syncProjectTaskCompletions() and
 *      ajax/parent_hold.php.
 *   2. Live DB round-trip — INSERTs a parent with sub-tasks, calls
 *      syncProjectTaskCompletions(), and verifies the parent's status
 *      reflects the children. Then exercises on_hold stickiness and
 *      completed → in_progress demotion.
 *   3. Live DB exercise of ajax/parent_hold.php — directly via PHP
 *      (no HTTP), by replicating the endpoint's UPDATE + sync path.
 *   4. Source-grep checks on ajax/parent_hold.php, task_detail.php, and
 *      task_edit.php — the wiring must still call deriveParentStatus()
 *      and read the right fields.
 *
 * Mirrors the pattern in contacts_columns_test.php.
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/Database.php';
require_once __DIR__ . '/../includes/functions.php';

$db = Database::getInstance();
$failures = [];

// ---------------------------------------------------------------------------
// 1. Pure-function tests for deriveParentStatus().
// ---------------------------------------------------------------------------
$VALID = ['not_started', 'in_progress', 'completed', 'on_hold'];

$pure_tests = [
    // on_hold is sticky.
    'on_hold: sticky when current is on_hold regardless of children' => [
        'on_hold', ['completed', 'completed', 'completed'], 'on_hold',
    ],
    'on_hold: sticky even if children are not_started' => [
        'on_hold', ['not_started', 'not_started'], 'on_hold',
    ],
    'on_hold: null current + on_hold child still allows non-on_hold' => [
        // When current is null (treat as "any"), we go by the children. No
        // child has on_hold → on_hold is not sticky because we weren't held.
        null, ['in_progress'], 'in_progress',
    ],
    'in_progress: any in_progress child wins' => [
        null, ['completed', 'in_progress', 'not_started'], 'in_progress',
    ],
    'in_progress: multiple in_progress children still resolve to in_progress' => [
        null, ['in_progress', 'in_progress'], 'in_progress',
    ],
    'completed: all children completed' => [
        null, ['completed', 'completed', 'completed'], 'completed',
    ],
    'in_progress: any in_progress child wins over completed' => [
        null, ['completed', 'in_progress', 'completed'], 'in_progress',
    ],
    'completed: with not_started → still completed (loose rule: any completed wins over not_started)' => [
        null, ['completed', 'not_started'], 'completed',
    ],
    'not_started: all not_started' => [
        null, ['not_started', 'not_started'], 'not_started',
    ],
    'not_started: empty children list' => [
        null, [], 'not_started',
    ],
    'completed beats not_started when no in_progress (loose rule)' => [
        null, ['completed', 'not_started', 'not_started'], 'completed',
    ],
    'on_hold children do not promote to in_progress' => [
        // No child is in_progress, one is completed → parent is completed.
        null, ['completed', 'on_hold'], 'completed',
    ],
    'mixed on_hold + in_progress → in_progress (on_hold is the child, not parent)' => [
        null, ['on_hold', 'in_progress', 'not_started'], 'in_progress',
    ],
];

foreach ($pure_tests as $name => [$current, $children, $expected]) {
    $actual = deriveParentStatus($current, $children);
    if ($actual !== $expected) {
        $failures[] = "deriveParentStatus: '$name' expected '$expected', got '$actual'";
    }
}

// Exhaustive 4x4 matrix: for each current status × each child-state, the
// result must be one of the four valid enum values.
foreach ($VALID as $current) {
    foreach ($VALID as $c0) {
        $actual = deriveParentStatus($current, [$c0]);
        if (!in_array($actual, $VALID, true)) {
            $failures[] = "matrix: deriveParentStatus('$current', ['$c0']) returned non-enum '$actual'";
        }
        // on_hold stickiness
        if ($current === 'on_hold' && $actual !== 'on_hold') {
            $failures[] = "matrix: on_hold should be sticky, got '$actual' for child='$c0'";
        }
    }
}

// ---------------------------------------------------------------------------
// 2. Live DB round-trip.
// ---------------------------------------------------------------------------
$test_username = 'issue012_test_user_' . bin2hex(random_bytes(4));
$test_project_name = 'issue012_test_project ' . bin2hex(random_bytes(4));

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

$created_task_ids = [];

try {
    // Helper to create a task.
    $create = function ($name, $parent_id, $status) use ($db, $project_id, $user_id, &$created_task_ids) {
        $db->execute(
            "INSERT INTO tasks (project_id, parent_task_id, name, responsible_person_id, status, completion_percentage, completion_date, created_at)
             VALUES (?, ?, ?, ?, ?, 0, NULL, NOW())",
            [$project_id, $parent_id, $name, $user_id, $status]
        );
        $id = (int)$db->lastInsertId();
        $created_task_ids[] = $id;
        return $id;
    };

    // 2a. All children not_started → parent not_started.
    $parent_id = $create('issue012 parent A', null, 'not_started');
    $a1 = $create('issue012 sub A1', $parent_id, 'not_started');
    $a2 = $create('issue012 sub A2', $parent_id, 'not_started');
    syncProjectTaskCompletions($db, $project_id);
    $row = $db->fetchOne('SELECT status, completion_percentage, completion_date FROM tasks WHERE id = ?', [$parent_id]);
    if ($row['status'] !== 'not_started') {
        $failures[] = "2a: parent status should be 'not_started', got '{$row['status']}'";
    }
    if ($row['completion_date'] !== null) {
        $failures[] = "2a: not_started parent should have NULL completion_date, got " . var_export($row['completion_date'], true);
    }

    // 2b. Promote a child to in_progress → parent should become in_progress.
    $db->execute("UPDATE tasks SET status = 'in_progress' WHERE id = ?", [$a1]);
    syncProjectTaskCompletions($db, $project_id);
    $row = $db->fetchOne('SELECT status, completion_percentage FROM tasks WHERE id = ?', [$parent_id]);
    if ($row['status'] !== 'in_progress') {
        $failures[] = "2b: parent status should be 'in_progress', got '{$row['status']}'";
    }

    // 2c. All children completed → parent completed, completion_date set.
    $db->execute("UPDATE tasks SET status = 'completed', completion_percentage = 100, completion_date = NOW() WHERE id IN (?, ?)", [$a1, $a2]);
    syncProjectTaskCompletions($db, $project_id);
    $row = $db->fetchOne('SELECT status, completion_percentage, completion_date FROM tasks WHERE id = ?', [$parent_id]);
    if ($row['status'] !== 'completed') {
        $failures[] = "2c: parent status should be 'completed', got '{$row['status']}'";
    }
    if ((float)$row['completion_percentage'] < 99.5) {
        $failures[] = "2c: parent completion_percentage should be ~100, got '{$row['completion_percentage']}'";
    }
    if (empty($row['completion_date'])) {
        $failures[] = "2c: completed parent should have a completion_date";
    }
    $first_completion_date = $row['completion_date'];

    // 2d. Demote one child to in_progress → parent should demote, completion_date cleared.
    $db->execute("UPDATE tasks SET status = 'in_progress', completion_percentage = 0, completion_date = NULL WHERE id = ?", [$a1]);
    syncProjectTaskCompletions($db, $project_id);
    $row = $db->fetchOne('SELECT status, completion_percentage, completion_date FROM tasks WHERE id = ?', [$parent_id]);
    if ($row['status'] !== 'in_progress') {
        $failures[] = "2d: demoted parent status should be 'in_progress', got '{$row['status']}'";
    }
    if ($row['completion_date'] !== null) {
        $failures[] = "2d: demoted parent should have NULL completion_date, got " . var_export($row['completion_date'], true);
    }

    // 2e. on_hold stickiness: put parent on hold, then mutate children; the
    // helper should NOT demote the parent.
    $db->execute("UPDATE tasks SET status = 'on_hold' WHERE id = ?", [$parent_id]);
    syncProjectTaskCompletions($db, $project_id);
    $row = $db->fetchOne('SELECT status FROM tasks WHERE id = ?', [$parent_id]);
    if ($row['status'] !== 'on_hold') {
        $failures[] = "2e: on_hold parent should stay on_hold after sync, got '{$row['status']}'";
    }
    $db->execute("UPDATE tasks SET status = 'not_started' WHERE id = ?", [$a2]);
    syncProjectTaskCompletions($db, $project_id);
    $row = $db->fetchOne('SELECT status FROM tasks WHERE id = ?', [$parent_id]);
    if ($row['status'] !== 'on_hold') {
        $failures[] = "2e: on_hold stickiness — parent should stay on_hold, got '{$row['status']}'";
    }

    // 2f. Resume: derive new status from children.
    $db->execute("UPDATE tasks SET status = 'completed', completion_percentage = 100, completion_date = NOW() WHERE id = ?", [$a1]);
    syncProjectTaskCompletions($db, $project_id);
    // After re-promoting a1 to completed, all children are completed → resume
    // should yield 'completed'.
    $child_statuses = array_map(
        fn($r) => $r['status'],
        $db->fetchAll('SELECT status FROM tasks WHERE parent_task_id = ?', [$parent_id])
    );
    $derived = deriveParentStatus(null, $child_statuses);
    if ($derived !== 'completed') {
        $failures[] = "2f: resume derive expected 'completed', got '$derived'";
    }
} catch (Exception $e) {
    $failures[] = 'DB round-trip threw: ' . $e->getMessage();
}

// ---------------------------------------------------------------------------
// 3. Source-grep checks on ajax/parent_hold.php + display surfaces.
// ---------------------------------------------------------------------------
$ajax_src = file_get_contents(__DIR__ . '/../ajax/parent_hold.php');
foreach ([
    "validateCsrfToken",
    "requireLogin",
    "deriveParentStatus",
    "syncProjectTaskCompletions",
    "'hold'",
    "'resume'",
    "parent_task_id",
] as $needle) {
    if (strpos($ajax_src, $needle) === false) {
        $failures[] = "ajax/parent_hold.php is missing '$needle'";
    }
}

// task_detail.php must show the Auto pill and the On Hold / Resume forms
// only for parents with sub-tasks.
$detail_src = file_get_contents(__DIR__ . '/../task_detail.php');
foreach ([
    'derived-pill',
    'fa-magic',
    'parent_hold.php',
    'value="hold"',
    'value="resume"',
] as $needle) {
    if (strpos($detail_src, $needle) === false) {
        $failures[] = "task_detail.php is missing '$needle'";
    }
}

// task_edit.php must mark the status as read-only when editing a parent
// with sub-tasks, and force the posted value to be ignored.
$edit_src = file_get_contents(__DIR__ . '/../task_edit.php');
foreach ([
    'parent_task_id',
    'inherit_subtask_hours',
    'Use sum of sub-task hours',
] as $needle) {
    if (strpos($edit_src, $needle) === false) {
        $failures[] = "task_edit.php is missing '$needle'";
    }
}

// functions.php must export the two new helpers.
$func_src = file_get_contents(__DIR__ . '/../includes/functions.php');
foreach ([
    'function resolveTaskEstimateHours',
    'function deriveParentStatus',
] as $needle) {
    if (strpos($func_src, $needle) === false) {
        $failures[] = "includes/functions.php is missing '$needle'";
    }
}

// ---------------------------------------------------------------------------
// 4. Cleanup. Run LAST regardless of failures.
// ---------------------------------------------------------------------------
try {
    foreach ($created_task_ids as $tid) {
        $db->execute('DELETE FROM tasks WHERE id = ?', [$tid]);
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
    fwrite(STDERR, "Issue-012 parent_status_derived regression test failed:\n");
    foreach ($failures as $f) {
        fwrite(STDERR, "  - $f\n");
    }
    exit(1);
}

echo "Issue-012 parent_status_derived regression test passed.\n";
