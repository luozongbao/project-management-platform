<?php
/**
 * Regression test for issue-022 — task_edit.php IDOR (responsible person,
 * contact person, and project POST re-validation).
 *
 * Two layers of verification:
 *
 *   1. Unit tests for the three helpers in includes/task_edit_auth.php:
 *      - taskEditCanAssignResponsible($db, $user_id, $responsible_person_id)
 *      - taskEditCanAssignContact($db, $user_id, $contact_person_id)
 *      - taskEditCanUseProject($db, $user_id, $project_id)
 *      Each helper is exercised against a seeded dataset of three users
 *      (A, B, C), two projects (A's, B's), and two contacts (one on
 *      A's project, one on B's project). C is a registered user with
 *      no projects — the classic "ex-employee account" attacker.
 *
 *   2. Source-grep on task_edit.php: confirms the validation block
 *      calls the three helpers (not inline SQL), that the new error
 *      strings are emitted, and that the GET-time $users dropdown is
 *      scoped to project owners (not all users).
 *
 * Cleanup is unconditional — a fatal error in the assertions must not
 * leave the seed rows behind.
 *
 * Mirrors the structure of database/task_inherit_subtask_hours_test.php
 * and database/install_wizard_test.php.
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/Database.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/task_edit_auth.php';

$repo_root = realpath(__DIR__ . '/..');
$db = Database::getInstance();
$failures = [];

function ok($label, $ok, $detail = '') {
    global $failures;
    if ($ok) {
        echo "  [PASS] $label\n";
    } else {
        $failures[] = $label . ($detail !== '' ? " — $detail" : '');
        echo "  [FAIL] $label" . ($detail !== '' ? " — $detail" : '') . "\n";
    }
}

/**
 * Strip PHP comments from the source so a "does NOT contain X" check
 * is not fooled by a literal X in a doc-block.
 */
function strip_php_comments($src) {
    $src = preg_replace('~/\*.*?\*/~s', '', $src);
    $src = preg_replace('~//[^\n]*~', '', $src);
    $src = preg_replace('~#[^\n]*~', '', $src);
    return $src;
}

// ---------------------------------------------------------------------------
// 0. Seed the dataset. Use a deterministic suffix so re-runs don't trip
//    on UNIQUE(email) — uniqueness on username/email is NOT enforced
//    by the schema, but the test's own re-runs should be idempotent.
// ---------------------------------------------------------------------------
$marker = 'issue022-' . bin2hex(random_bytes(4));

// Database::execute() returns a boolean (true on success), NOT the row
// count. The new AUTO_INCREMENT id is always available via lastInsertId()
// on the same connection. Pattern: call execute() for the side effect,
// then lastInsertId() for the id.
$db->execute(
    "INSERT INTO users (name, username, email, password, timezone, created_at)
     VALUES (?, ?, ?, ?, 'UTC', NOW())",
    [
        'Issue022 A',
        $marker . '-a',
        $marker . '-a@example.test',
        password_hash('TestPassA!', PASSWORD_ARGON2ID),
    ]
);
$user_a_id = (int)$db->lastInsertId();

$db->execute(
    "INSERT INTO users (name, username, email, password, timezone, created_at)
     VALUES (?, ?, ?, ?, 'UTC', NOW())",
    [
        'Issue022 B',
        $marker . '-b',
        $marker . '-b@example.test',
        password_hash('TestPassB!', PASSWORD_ARGON2ID),
    ]
);
$user_b_id = (int)$db->lastInsertId();

$db->execute(
    "INSERT INTO users (name, username, email, password, timezone, created_at)
     VALUES (?, ?, ?, ?, 'UTC', NOW())",
    [
        'Issue022 C (no projects)',
        $marker . '-c',
        $marker . '-c@example.test',
        password_hash('TestPassC!', PASSWORD_ARGON2ID),
    ]
);
$user_c_id = (int)$db->lastInsertId();

$db->execute(
    "INSERT INTO projects (name, description, responsible_person_id, status, created_at)
     VALUES (?, ?, ?, 'in_progress', NOW())",
    [$marker . ' project A', 'seed for issue-022', $user_a_id]
);
$project_a_id = (int)$db->lastInsertId();

$db->execute(
    "INSERT INTO projects (name, description, responsible_person_id, status, created_at)
     VALUES (?, ?, ?, 'in_progress', NOW())",
    [$marker . ' project B', 'seed for issue-022', $user_b_id]
);
$project_b_id = (int)$db->lastInsertId();

$db->execute(
    "INSERT INTO contacts (name, email, created_at)
     VALUES (?, ?, NOW())",
    [$marker . ' contact on A', $marker . '-contact-a@example.test']
);
$contact_a_id = (int)$db->lastInsertId();

$db->execute(
    "INSERT INTO contacts (name, email, created_at)
     VALUES (?, ?, NOW())",
    [$marker . ' contact on B', $marker . '-contact-b@example.test']
);
$contact_b_id = (int)$db->lastInsertId();

// Link contacts to their owner's projects.
$db->execute(
    "INSERT INTO project_contacts (project_id, contact_id) VALUES (?, ?)",
    [$project_a_id, $contact_a_id]
);
$db->execute(
    "INSERT INTO project_contacts (project_id, contact_id) VALUES (?, ?)",
    [$project_b_id, $contact_b_id]
);

// Cleanup helper — invoked both at the end and via register_shutdown_function
// so a fatal error mid-test does not leave seed rows behind.
$cleanup = static function () use ($db, $marker, $user_a_id, $user_b_id, $user_c_id,
                                   $project_a_id, $project_b_id,
                                   $contact_a_id, $contact_b_id) {
    // ORDER matters: contact -> project_contacts -> project -> user
    $db->execute("DELETE FROM project_contacts WHERE project_id IN (?, ?)",
        [$project_a_id, $project_b_id]);
    $db->execute("DELETE FROM contacts WHERE id IN (?, ?)",
        [$contact_a_id, $contact_b_id]);
    $db->execute("DELETE FROM projects WHERE id IN (?, ?)",
        [$project_a_id, $project_b_id]);
    $db->execute("DELETE FROM users WHERE id IN (?, ?, ?)",
        [$user_a_id, $user_b_id, $user_c_id]);
};
register_shutdown_function($cleanup);

echo "## 1. taskEditCanAssignResponsible — project-owner rule\n";
ok(
    'A can assign a task to themselves (current user is always allowed)',
    taskEditCanAssignResponsible($db, $user_a_id, $user_a_id)
);
ok(
    'A can assign a task to B (B is a project owner)',
    taskEditCanAssignResponsible($db, $user_a_id, $user_b_id)
);
ok(
    'A CANNOT assign a task to C (C is a registered user but owns no projects — the ex-employee attack)',
    !taskEditCanAssignResponsible($db, $user_a_id, $user_c_id)
);
ok(
    'Bogus user id is rejected (defence against id=0 / id=99999 forgery)',
    !taskEditCanAssignResponsible($db, $user_a_id, 99999999)
);
ok(
    'Empty / zero id is rejected',
    !taskEditCanAssignResponsible($db, $user_a_id, 0)
);

echo "\n## 2. taskEditCanAssignContact — project-contact rule\n";
ok(
    'A can assign a contact that is linked to A\'s project',
    taskEditCanAssignContact($db, $user_a_id, $contact_a_id)
);
ok(
    'A CANNOT assign a contact that is only on B\'s project (cross-user leak)',
    !taskEditCanAssignContact($db, $user_a_id, $contact_b_id)
);
ok(
    'Bogus contact id is rejected',
    !taskEditCanAssignContact($db, $user_a_id, 99999999)
);
ok(
    'Empty / zero contact id is rejected',
    !taskEditCanAssignContact($db, $user_a_id, 0)
);

echo "\n## 3. taskEditCanUseProject — owned-project rule\n";
ok(
    'A can use A\'s own project',
    taskEditCanUseProject($db, $user_a_id, $project_a_id)
);
ok(
    'A CANNOT use B\'s project (project_id forgery)',
    !taskEditCanUseProject($db, $user_a_id, $project_b_id)
);
ok(
    'Bogus project id is rejected',
    !taskEditCanUseProject($db, $user_a_id, 99999999)
);
ok(
    'Empty / zero project id is rejected',
    !taskEditCanUseProject($db, $user_a_id, 0)
);

echo "\n## 4. task_edit.php source-grep — wiring\n";
$edit_src = file_get_contents($repo_root . '/task_edit.php');
$edit_code = strip_php_comments($edit_src);

ok(
    'task_edit.php requires the helper file',
    strpos($edit_code, "require_once 'includes/task_edit_auth.php'") !== false
);
ok(
    'task_edit.php POST validation calls taskEditCanAssignResponsible (not the old existence-only check)',
    strpos($edit_code, 'taskEditCanAssignResponsible(') !== false
        && strpos($edit_code, '"SELECT id FROM users WHERE id = ?"') === false
);
ok(
    'task_edit.php POST validation calls taskEditCanAssignContact (not the old existence-only check)',
    strpos($edit_code, 'taskEditCanAssignContact(') !== false
        && strpos($edit_code, '"SELECT id FROM contacts WHERE id = ?"') === false
);
ok(
    'task_edit.php POST validation calls taskEditCanUseProject',
    strpos($edit_code, 'taskEditCanUseProject(') !== false
);
ok(
    'task_edit.php emits the "is not a user you can assign a task to" error',
    strpos($edit_code, 'is not a user') !== false
        && strpos($edit_code, 'you can assign a task to') !== false
);
ok(
    'task_edit.php emits the "is not on any of your projects" error',
    strpos($edit_code, 'is not on any of your projects') !== false
);
ok(
    'task_edit.php emits the "is not one of your projects" error (project_id branch)',
    strpos($edit_code, 'is not one of your projects') !== false
);

echo "\n## 5. task_edit.php source-grep — \$users dropdown is scoped\n";
ok(
    'task_edit.php $users SELECT is scoped to project owners (no more "every user in the database")',
    (bool)preg_match(
        '~SELECT\s+id,\s*name,\s*email\s+FROM\s+users\s+WHERE\s+id\s*=\s*\?\s+OR\s+id\s+IN\s*\(\s*SELECT\s+responsible_person_id\s+FROM\s+projects~i',
        $edit_code
    )
);
ok(
    'task_edit.php $users SELECT no longer says "SELECT id, name, email FROM users ORDER BY name" (the old global query)',
    strpos($edit_code, 'SELECT id, name, email FROM users ORDER BY name') === false
);

// ---------------------------------------------------------------------------
// 6. Cleanup (the shutdown handler is the safety net; this is the happy
//    path so the failure summary is printed first).
// ---------------------------------------------------------------------------
$cleanup();
echo "\n----------------------------------------\n";

if (empty($failures)) {
    echo "Issue-022 task_edit IDOR regression test passed.\n";
    exit(0);
} else {
    echo "Issue-022 task_edit IDOR regression FAILED:\n";
    foreach ($failures as $f) {
        echo "  - $f\n";
    }
    exit(1);
}
