<?php
/**
 * Regression test for issue-009 — contacts.company / position / address.
 *
 * Three layers of verification:
 *
 *   1. Schema introspection — confirms the columns exist with the
 *      documented types. Without them, the next schema-drift bug will
 *      not be caught at all.
 *   2. Live DB round-trip — INSERTs a contact with name = '张总' and
 *      wechat = 'YADEPAN18' (the exact input from the bug report),
 *      verifies the new columns default to NULL, then UPDATEd them and
 *      re-reads to confirm persistence. This is the failure mode that
 *      would have caught issue-001 and issue-009 on the day they were
 *      introduced.
 *   3. Source-grep checks on contact_edit.php and contacts.php — the
 *      form must still bind company / position / address, and the
 *      list view's search must still match on c.company.
 *
 * Runs entirely inside the Docker app container, no separate test
 * runner needed. Cleans up every row it inserts.
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/Database.php';
require_once __DIR__ . '/../includes/functions.php';

$db = Database::getInstance();
$failures = [];

// ---------------------------------------------------------------------------
// 1. Schema introspection.
// ---------------------------------------------------------------------------
$expected = [
    'company'  => ['VARCHAR', 255],
    'position' => ['VARCHAR', 255],
    'address'  => ['TEXT',    null],
];
$columns = [];
foreach ($db->fetchAll('DESCRIBE contacts') as $row) {
    $columns[$row['Field']] = $row;
}
foreach ($expected as $col => [$type, $len]) {
    if (!isset($columns[$col])) {
        $failures[] = "contacts.$col column is missing — run database/migrations/20261006_add_contacts_company_position_address.sql";
        continue;
    }
    $row = $columns[$col];
    if ($row['Null'] !== 'YES') {
        $failures[] = "contacts.$col should be NULL-able, got Null='{$row['Null']}'";
    }
    if (strtoupper(strtok($row['Type'], ' (')) !== $type) {
        $failures[] = "contacts.$col type should start with $type, got '{$row['Type']}'";
    }
    if ($len !== null) {
        // MariaDB returns lowercase 'varchar(255)'; normalise before comparing.
        if (preg_match('/\((\d+)\)/', $row['Type'], $m)) {
            if ((int)$m[1] !== $len) {
                $failures[] = "contacts.$col length should be $len, got '{$row['Type']}'";
            }
        } else {
            $failures[] = "contacts.$col length should be $len, got '{$row['Type']}'";
        }
    }
}

// ---------------------------------------------------------------------------
// 2. Live DB round-trip — the exact failure mode from the bug report.
//    Use a dedicated test user + project so the cleanup is targeted.
// ---------------------------------------------------------------------------
$test_username = 'issue009_test_user_' . bin2hex(random_bytes(4));
$test_project_name = 'issue009_test_project ' . bin2hex(random_bytes(4));

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

// Exact input from the bug report: 张总 / YADEPAN18, no other fields.
$contact_name  = '张总';
$contact_wechat = 'YADEPAN18';

try {
    $db->execute(
        "INSERT INTO contacts (
            name, description, email, phone, mobile, company, position, address,
            wechat, line_id, facebook, linkedin, created_at, updated_at
         ) VALUES (?, '', ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())",
        [
            $contact_name, null, null, null, null, null, null,
            $contact_wechat, null, null, null
        ]
    );
    $contact_id = (int)$db->lastInsertId();
} catch (Exception $e) {
    $failures[] = 'INSERT contact (the exact failing case from issue-009) still throws: ' . $e->getMessage();
    $contact_id = null;
}

if ($contact_id) {
    // The new columns must default to NULL on a fresh INSERT.
    $row = $db->fetchOne('SELECT company, position, address FROM contacts WHERE id = ?', [$contact_id]);
    foreach (['company', 'position', 'address'] as $col) {
        if ($row[$col] !== null) {
            $failures[] = "newly inserted contacts.$col should be NULL, got " . var_export($row[$col], true);
        }
    }

    // UPDATE with non-null values, then re-read.
    $db->execute(
        "UPDATE contacts SET company = ?, position = ?, address = ? WHERE id = ?",
        ['Acme Co.', 'Director', "1 Infinite Loop\nCupertino, CA", $contact_id]
    );
    $row = $db->fetchOne('SELECT name, wechat, company, position, address FROM contacts WHERE id = ?', [$contact_id]);
    if ($row['name'] !== $contact_name) {
        $failures[] = "after UPDATE, contacts.name should be '$contact_name', got " . var_export($row['name'], true);
    }
    if ($row['wechat'] !== $contact_wechat) {
        $failures[] = "after UPDATE, contacts.wechat should be '$contact_wechat', got " . var_export($row['wechat'], true);
    }
    if ($row['company'] !== 'Acme Co.') {
        $failures[] = "after UPDATE, contacts.company should be 'Acme Co.', got " . var_export($row['company'], true);
    }
    if ($row['position'] !== 'Director') {
        $failures[] = "after UPDATE, contacts.position should be 'Director', got " . var_export($row['position'], true);
    }
    if ($row['address'] !== "1 Infinite Loop\nCupertino, CA") {
        $failures[] = "after UPDATE, contacts.address should preserve newlines, got " . var_export($row['address'], true);
    }

    // Link to a project via project_contacts (mirrors what the form does).
    $db->execute(
        "INSERT INTO project_contacts (contact_id, project_id, created_at) VALUES (?, ?, NOW())",
        [$contact_id, $project_id]
    );
    $link = $db->fetchOne(
        'SELECT project_id FROM project_contacts WHERE contact_id = ? AND project_id = ?',
        [$contact_id, $project_id]
    );
    if (!$link) {
        $failures[] = 'project_contacts link was not persisted';
    }

    // contacts.php search by company must work.
    $hits = $db->fetchAll(
        "SELECT c.id FROM contacts c
         JOIN project_contacts pc ON c.id = pc.contact_id
         WHERE c.company LIKE ?",
        ['%Acme%']
    );
    if (!in_array($contact_id, array_column($hits, 'id'), true)) {
        $failures[] = 'contacts.php c.company LIKE query did not return the newly inserted contact';
    }

    // The wrapper's "Database execute failed: …" prefix must surface the
    // underlying PDO message so future schema-drift bugs are obvious.
    try {
        $db->execute('INSERT INTO contacts (name, definitely_does_not_exist) VALUES (?, ?)', ['x', 'y']);
        $failures[] = 'expected Database::execute() to throw on missing column';
    } catch (Exception $e) {
        $msg = $e->getMessage();
        if (strpos($msg, 'Database execute failed') !== 0) {
            $failures[] = "Database::execute() should still prefix with 'Database execute failed', got: '$msg'";
        }
        if (strpos($msg, 'definitely_does_not_exist') === false) {
            $failures[] = "Database::execute() should surface the underlying PDO error (got: '$msg')";
        }
    }
}

// ---------------------------------------------------------------------------
// 3. Source-grep checks.
// ---------------------------------------------------------------------------
$contact_edit = file_get_contents(__DIR__ . '/../contact_edit.php');
foreach (['company', 'position', 'address'] as $field) {
    // Each new column appears in BOTH the INSERT and UPDATE statements.
    if (substr_count($contact_edit, $field) < 2) {
        $failures[] = "contact_edit.php references '$field' fewer than 2 times — INSERT/UPDATE may be incomplete";
    }
}

$contacts_list = file_get_contents(__DIR__ . '/../contacts.php');
if (strpos($contacts_list, 'c.company LIKE') === false) {
    $failures[] = "contacts.php search no longer matches c.company — search box would silently drop the new field";
}

$migration = file_get_contents(__DIR__ . '/migrations/20261006_add_contacts_company_position_address.sql');
foreach (['company', 'position', 'address'] as $field) {
    if (strpos($migration, "COLUMN_NAME = '$field'") === false) {
        $failures[] = "migration is missing information_schema guard for '$field'";
    }
    if (strpos($migration, "ADD COLUMN $field") === false) {
        $failures[] = "migration is missing ADD COLUMN $field";
    }
}

$schema = file_get_contents(__DIR__ . '/schema.sql');
foreach (['company', 'position', 'address'] as $field) {
    if (strpos($schema, $field) === false) {
        $failures[] = "database/schema.sql CREATE TABLE contacts does not declare $field";
    }
}

// ---------------------------------------------------------------------------
// 4. Cleanup. Run LAST regardless of failures, so the test does not
//    pollute the user's DB even when assertions fail.
// ---------------------------------------------------------------------------
try {
    if (isset($contact_id) && $contact_id) {
        $db->execute('DELETE FROM project_contacts WHERE contact_id = ?', [$contact_id]);
        $db->execute('DELETE FROM contacts WHERE id = ?', [$contact_id]);
    }
    if (isset($project_id) && $project_id) {
        $db->execute('DELETE FROM projects WHERE id = ?', [$project_id]);
    }
    if (isset($user_id) && $user_id) {
        $db->execute('DELETE FROM users WHERE id = ?', [$user_id]);
    }
} catch (Exception $e) {
    // Cleanup failure should not mask assertion failures, but should be
    // visible so the user knows to clean up manually.
    fwrite(STDERR, "warning: cleanup failed: " . $e->getMessage() . "\n");
}

if (!empty($failures)) {
    fwrite(STDERR, "Contacts columns regression test failed:\n");
    foreach ($failures as $f) {
        fwrite(STDERR, "  - $f\n");
    }
    exit(1);
}

echo "Contacts columns regression test passed.\n";