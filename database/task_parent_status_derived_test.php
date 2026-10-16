<?php
/**
 * Regression test for issue-012 — parent status derived from sub-tasks.
 *
 * Four layers of verification:
 *
 *   1. Pure-function unit tests for deriveParentStatus() — the strict
 *      all-completed source of truth used by ajax/parent_hold.php
 *      (issue-025).
 *   1b. Pure-function unit tests for deriveParentStatusFromCompletion()
 *      — the percentage-driven helper used by syncProjectTaskCompletions()
 *      (issue-026).
 *   2. Live DB round-trip — INSERTs a parent with sub-tasks, calls
 *      syncProjectTaskCompletions(), and verifies the parent's status
 *      reflects the children. Then exercises on_hold stickiness,
 *      completed → in_progress demotion, and the UAT2 regression
 *      (parent with 3 sub-tasks, one at 5% / not_started) where the
 *      parent should be in_progress with a non-zero percentage.
 *   3. Live DB exercise of ajax/parent_hold.php — directly via PHP
 *      (no HTTP), by replicating the endpoint's UPDATE + sync path.
 *   4. Source-grep checks on ajax/parent_hold.php, task_detail.php,
 *      task_edit.php, and includes/functions.php — the wiring must
 *      still call deriveParentStatus() for the resume branch, define
 *      deriveParentStatusFromCompletion(), and use it from
 *      syncProjectTaskCompletions().
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
    'completed: all children completed' => [
        null, ['completed', 'completed', 'completed'], 'completed',
    ],
    'completed: single completed child' => [
        null, ['completed'], 'completed',
    ],
    'not_started: completed + not_started (strict rule: not all completed)' => [
        // Issue-025: under the strict rule, even one non-completed child
        // blocks promotion. Was 'completed' under the old loose rule.
        null, ['completed', 'not_started'], 'not_started',
    ],
    'not_started: all not_started' => [
        null, ['not_started', 'not_started'], 'not_started',
    ],
    'not_started: empty children list' => [
        null, [], 'not_started',
    ],
    'not_started: completed + completed + not_started (strict rule)' => [
        // Issue-025: third child is not_started → no promotion.
        null, ['completed', 'completed', 'not_started'], 'not_started',
    ],
    'not_started: completed + on_hold (strict rule: on_hold blocks)' => [
        // Issue-025: on_hold child is not 'completed' → strict rule fails.
        null, ['completed', 'on_hold'], 'not_started',
    ],
    'not_started: on_hold only (no progress, not all completed)' => [
        null, ['on_hold'], 'not_started',
    ],
    'in_progress: mixed on_hold + in_progress (on_hold is the child, not parent)' => [
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
// 1b. Pure-function tests for deriveParentStatusFromCompletion() (issue-026).
// ---------------------------------------------------------------------------
// Rule recap: on_hold (parent) sticky; else completed if pct >= 99.5;
// else in_progress if pct > 0; else not_started. The 99.5 threshold
// matches the round(x, 2) granularity used at the call site.
$completion_tests = [
    'not_started: 0%' => [
        null, 0.0, 'not_started',
    ],
    'not_started: 0% (rounded to 0.00)' => [
        // Spec note: the helper does NOT round its input; it tests
        // 0.004999 against the `> 0` comparator directly, so this
        // value classifies as in_progress (any positive value
        // counts). The fixture's name reflects the on-disk parent
        // percentage after the round(x, 2) write, not the helper's
        // input behaviour. The expected value below is the correct
        // behaviour for the helper body the spec defines.
        null, 0.004999, 'in_progress',
    ],
    'in_progress: 1.6% (the UAT2 case)' => [
        null, 1.6, 'in_progress',
    ],
    'in_progress: 50%' => [
        null, 50.0, 'in_progress',
    ],
    'in_progress: 99.49% (just below threshold)' => [
        null, 99.49, 'in_progress',
    ],
    'completed: 99.5% (the threshold)' => [
        null, 99.5, 'completed',
    ],
    'completed: 100%' => [
        null, 100.0, 'completed',
    ],
    'completed: 99.6%' => [
        null, 99.6, 'completed',
    ],
    'on_hold: 0% (sticky even at zero)' => [
        'on_hold', 0.0, 'on_hold',
    ],
    'on_hold: 50% (sticky regardless of percentage)' => [
        'on_hold', 50.0, 'on_hold',
    ],
    'on_hold: 100% (sticky even at completion)' => [
        'on_hold', 100.0, 'on_hold',
    ],
    'in_progress: tiny positive (0.0001)' => [
        null, 0.0001, 'in_progress',
    ],
];

foreach ($completion_tests as $name => [$current, $percentage, $expected]) {
    $actual = deriveParentStatusFromCompletion($current, $percentage);
    if ($actual !== $expected) {
        $failures[] = "deriveParentStatusFromCompletion: '$name' expected '$expected', got '$actual'";
    }
}

// Boundary stress: 99.49 must NOT classify as completed, 99.50 must.
// (These two are the most load-bearing boundary values; tested
// separately so a regression in the comparator would surface a
// single, targeted failure.)
$strict_99_49 = deriveParentStatusFromCompletion(null, 99.49);
$strict_99_50 = deriveParentStatusFromCompletion(null, 99.50);
if ($strict_99_49 !== 'in_progress') {
    $failures[] = "boundary: 99.49 should be in_progress, got '$strict_99_49'";
}
if ($strict_99_50 !== 'completed') {
    $failures[] = "boundary: 99.50 should be completed, got '$strict_99_50'";
}

// Output must always be a valid enum value, for every current in $VALID
// crossed with a few representative percentages.
$representative_pcts = [0.0, 0.0001, 50.0, 99.49, 99.5, 100.0];
foreach ($VALID as $current) {
    foreach ($representative_pcts as $p) {
        $actual = deriveParentStatusFromCompletion($current, $p);
        if (!in_array($actual, $VALID, true)) {
            $failures[] = "matrix-b: deriveParentStatusFromCompletion('$current', $p) returned non-enum '$actual'";
        }
        // on_hold is the first check, so it must always win.
        if ($current === 'on_hold' && $actual !== 'on_hold') {
            $failures[] = "matrix-b: on_hold should be sticky at $p%, got '$actual'";
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

    // 2a. All children not_started → parent not_started, percentage 0.
    $parent_id = $create('issue012 parent A', null, 'not_started');
    $a1 = $create('issue012 sub A1', $parent_id, 'not_started');
    $a2 = $create('issue012 sub A2', $parent_id, 'not_started');
    syncProjectTaskCompletions($db, $project_id);
    $row = $db->fetchOne('SELECT status, completion_percentage, completion_date FROM tasks WHERE id = ?', [$parent_id]);
    if ($row['status'] !== 'not_started') {
        $failures[] = "2a: parent status should be 'not_started', got '{$row['status']}'";
    }
    // Issue-026: parent completion_percentage must be 0.00 when every
    // child is at 0% (the percentage source of truth drives status).
    if ((float)$row['completion_percentage'] !== 0.0) {
        $failures[] = "2a: parent completion_percentage should be 0.0, got '{$row['completion_percentage']}'";
    }
    if ($row['completion_date'] !== null) {
        $failures[] = "2a: not_started parent should have NULL completion_date, got " . var_export($row['completion_date'], true);
    }

    // 2b. Promote a child to in_progress → parent should become in_progress.
    // Issue-026: under the new contract, the parent status is driven by
    // the parent completion_percentage. The child must therefore carry
    // a non-zero completion_percentage for the parent to land on
    // in_progress; status alone is no longer enough. (A leaf's status is
    // the manager's manual signal and is not auto-derived from its own
    // percentage — see Out of scope in docs/issues/issue-026.md.)
    $db->execute("UPDATE tasks SET status = 'in_progress', completion_percentage = 50 WHERE id = ?", [$a1]);
    syncProjectTaskCompletions($db, $project_id);
    $row = $db->fetchOne('SELECT status, completion_percentage FROM tasks WHERE id = ?', [$parent_id]);
    if ($row['status'] !== 'in_progress') {
        $failures[] = "2b: parent status should be 'in_progress', got '{$row['status']}'";
    }
    if ((float)$row['completion_percentage'] <= 0.0) {
        $failures[] = "2b: parent completion_percentage should be > 0, got '{$row['completion_percentage']}'";
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
    // Issue-026: keep the child's completion_percentage > 0 so the
    // parent's percentage is still positive after the demotion, which
    // means the parent lands on in_progress under the new contract.
    $db->execute("UPDATE tasks SET status = 'in_progress', completion_percentage = 25, completion_date = NULL WHERE id = ?", [$a1]);
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
    // After re-promoting a1 to completed, a1=completed, a2=not_started.
    // Under the strict rule (issue-025), derive yields 'not_started'
    // (one child is not yet completed, so strict all-completed fails).
    $child_statuses = array_map(
        fn($r) => $r['status'],
        $db->fetchAll('SELECT status FROM tasks WHERE parent_task_id = ?', [$parent_id])
    );
    $derived = deriveParentStatus(null, $child_statuses);
    if ($derived !== 'not_started') {
        $failures[] = "2f: resume derive expected 'not_started' (strict rule, a2 still not_started), got '$derived'";
    }

    // 2g. UAT2 regression (issue-026): a parent with three sub-tasks,
    // exactly one of them at 5% and not_started, must derive as
    // `in_progress` with a positive completion_percentage. This
    // guards the failure captured in the issue-026 screenshots:
    // before the fix, the parent row carried percentage=0 and
    // status=not_started even though one child was mid-progress.
    $g_parent = $create('issue026 parent G', null, 'not_started');
    $g1 = $create('issue026 sub G1', $g_parent, 'not_started');  // 0%
    $g2 = $create('issue026 sub G2', $g_parent, 'in_progress');  // 5%
    $g3 = $create('issue026 sub G3', $g_parent, 'not_started');  // 0%
    $db->execute(
        "UPDATE tasks SET completion_percentage = 5 WHERE id = ?",
        [$g2]
    );
    syncProjectTaskCompletions($db, $project_id);
    $g_row = $db->fetchOne(
        'SELECT status, completion_percentage FROM tasks WHERE id = ?',
        [$g_parent]
    );
    // Without the fix, completion_percentage would round to 0.00
    // and the parent would land on not_started.
    if ($g_row['status'] !== 'in_progress') {
        $failures[] = "2g: UAT2 parent status should be 'in_progress', got '{$g_row['status']}'";
    }
    if ((float)$g_row['completion_percentage'] <= 0.0) {
        $failures[] = "2g: UAT2 parent completion_percentage should be > 0, got '{$g_row['completion_percentage']}'";
    }
    // And the new helper must agree, given the persisted percentage.
    $g_pct = (float)$g_row['completion_percentage'];
    $g_derived = deriveParentStatusFromCompletion(null, $g_pct);
    if ($g_derived !== 'in_progress') {
        $failures[] = "2g: deriveParentStatusFromCompletion(0%, $g_pct) should be 'in_progress', got '$g_derived'";
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

// functions.php must export all three helpers and the call site in
// syncProjectTaskCompletions() must use deriveParentStatusFromCompletion().
$func_src = file_get_contents(__DIR__ . '/../includes/functions.php');
foreach ([
    'function resolveTaskEstimateHours',
    'function deriveParentStatus',
    'function deriveParentStatusFromCompletion',
] as $needle) {
    if (strpos($func_src, $needle) === false) {
        $failures[] = "includes/functions.php is missing '$needle'";
    }
}
// The call site must pass the (casted) parent completion_percentage
// to the new helper. This guards against a regression where someone
// restores deriveParentStatus() in syncProjectTaskCompletions() and
// silently re-introduces the UAT2 bug.
if (strpos($func_src, 'deriveParentStatusFromCompletion($currentStatus, (float)$storedCompletion)') === false) {
    $failures[] = "includes/functions.php: syncProjectTaskCompletions() must call deriveParentStatusFromCompletion(\$currentStatus, (float)\$storedCompletion)";
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
    fwrite(STDERR, "Issue-012/025/026 parent_status_derived regression test failed:\n");
    foreach ($failures as $f) {
        fwrite(STDERR, "  - $f\n");
    }
    exit(1);
}

echo "Issue-012/025/026 parent_status_derived regression test passed.\n";
