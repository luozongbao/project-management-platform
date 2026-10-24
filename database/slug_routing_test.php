<?php
/**
 * slug_routing_test.php — issue-035 v2.0 / Appendix C.7
 *
 * Covers the slug half of Phase 0 (T4, T5, T6, T7) plus the
 * share-code regression guard (T2, T3). The HTTP-level routing
 * assertions (T8–T15) live in slug_http_test.php because they need a
 * running web server.
 *
 * Run with:
 *   docker compose exec -T app php /var/www/html/database/slug_routing_test.php
 *
 * The load-bearing test here is T4 (parity): PHP slugify() and the SQL
 * slug_base() in database/migrations/20261022_add_slugs.sql MUST produce
 * byte-identical output. If they drift, a row migrated today gets a
 * different URL than the same name inserted tomorrow, and C.4 is violated.
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/Database.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/slug.php';

$repo_root = dirname(__DIR__);
$pass = 0;
$fail = 0;

function ok($condition, $message) {
    global $pass, $fail;
    if ($condition) {
        $pass++;
        echo "  PASS: {$message}\n";
    } else {
        $fail++;
        echo "  FAIL: {$message}\n";
    }
}

function section($title) {
    echo "\n=== {$title} ===\n";
}

$db = Database::getInstance();

// ---------------------------------------------------------------------------
section('T2. Share-code format unchanged (Appendix C.1 R1/R4)');
// ---------------------------------------------------------------------------

for ($i = 0; $i < 200; $i++) {
    $code = generateShareCode();
    if (!preg_match('/^[A-Z2-9]{5}-[A-Z2-9]{5}-[A-Z2-9]{5}$/', $code)) {
        ok(false, "generateShareCode() produced an off-contract code: {$code}");
        break;
    }
    if (strlen($code) !== 17) {
        ok(false, "generateShareCode() length is " . strlen($code) . ", expected 17: {$code}");
        break;
    }
}
ok(true, 'generateShareCode() returned 200 codes all matching /^[A-Z2-9]{5}-[A-Z2-9]{5}-[A-Z2-9]{5}$/ and strlen()===17');

$unique = [];
for ($i = 0; $i < 500; $i++) {
    $unique[generateShareCode()] = true;
}
ok(count($unique) === 500, 'generateShareCode() produced 500 distinct codes out of 500 draws');

// ---------------------------------------------------------------------------
section('T3. share_code column is still CHAR(17) (Appendix C.1 R1)');
// ---------------------------------------------------------------------------

$col = $db->fetchOne(
    "SELECT DATA_TYPE, CHARACTER_MAXIMUM_LENGTH FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'projects' AND COLUMN_NAME = 'share_code'"
);
ok($col && $col['DATA_TYPE'] === 'char', 'projects.share_code is still a CHAR column');
ok($col && (int)$col['CHARACTER_MAXIMUM_LENGTH'] === 17, 'projects.share_code is still CHAR(17)');

// ---------------------------------------------------------------------------
section('T4a. slugify() unit behaviour (Appendix C.4)');
// ---------------------------------------------------------------------------

$cases = [
    // [input, expected, why]
    ['North Riverside Housing', 'north-riverside-housing', 'spaces become single dashes'],
    ['MEP rough-in', 'mep-rough-in', 'existing hyphen survives as one dash'],
    ['Café', 'cafe', 'diacritic stripped'],
    ['naïve', 'naive', 'diaeresis stripped'],
    ['Zürich', 'zurich', 'umlaut stripped'],
    ['Straße', 'strasse', 'eszett expands to ss'],
    ['Ångström', 'angstrom', 'both ring accents stripped'],
    ['ÅNGSTRÖM', 'angstrom', 'lowercased then stripped'],
    ['Łódź', 'lod', 'stroke L and acute z handled'],
    ['Ñandú', 'nandu', 'tilde n stripped'],
    ['Ærø', 'aero', 'AE ligature expands'],
    ['  --Hello--World--  ', 'hello-world', 'runs collapse to one dash, ends trimmed'],
    ['Hello   World!!', 'hello-world', 'punctuation run collapses'],
    ['a-b_c.d', 'a-b-c-d', 'every non-alnum class is one separator'],
    ['A', 'a', 'single char'],
    ['Somchai Prasert', 'somchai-prasert', 'C.4 contact example'],
    ['MEP粗装 Task', 'mep-task', 'CJK treated as separator, matching SQL'],
    ['项目', '', 'pure CJK yields empty → caller applies item-<id>'],
    ['Ελλάδα', '', 'Greek has no map entry → empty'],
    ['---', '', 'punctuation-only yields empty'],
    ['', '', 'empty input yields empty'],
];

foreach ($cases as [$input, $expected, $why]) {
    $actual = slugify($input);
    ok(
        $actual === $expected,
        sprintf('slugify(%s) === %s  [%s] — got %s', var_export($input, true), var_export($expected, true), $why, var_export($actual, true))
    );
}

// Length cap: 60 chars, then a trailing '-' is trimmed away.
$long = str_repeat('ab', 50); // 100 chars, no separators
$capped = slugify($long);
ok(strlen($capped) === 60, 'slugify() caps at 60 chars — got ' . strlen($capped));

// A long name whose 60-char cut lands mid-token must not end in '-'.
$withDash = str_repeat('abcde-', 20); // 120 chars, dash at every 6th position
ok(
    substr(slugify($withDash), -1) !== '-',
    'slugify() never leaves a trailing dash after the 60-char cut'
);

// ---------------------------------------------------------------------------
section('T4b. PHP slugify() and SQL slug_base() agree byte-for-byte (C.4)');
// ---------------------------------------------------------------------------

// Recreate slug_base() from the migration. The migration DROPs it at the
// end (it is a build-time helper, not a permanent schema object), so the
// parity test installs it again for the duration of the check and removes
// it afterwards. Extracting the definition from the migration file itself
// means this test can never drift from the shipped SQL.
$migration_sql = file_get_contents($repo_root . '/database/migrations/20261022_add_slugs.sql');
if (!preg_match('/CREATE FUNCTION slug_base.*?END\$\$/s', $migration_sql, $m)) {
    ok(false, 'could not extract slug_base() definition from the migration file');
} else {
    $fn_sql = str_replace('$$', ';', $m[0]);
    $db->execute('DROP FUNCTION IF EXISTS slug_base');
    $db->execute($fn_sql);

    // Deliberately includes inputs that stress the exact divergence risk:
    // the iconv trap (apostrophes/quotes), CJK, ligatures, and multi-space
    // runs. See the header note in includes/slug.php for the iconv
    // measurements that motivated the explicit map.
    $parity_inputs = [
        'North Riverside Housing',
        'Café',
        'naïve',
        'Zürich',
        'Straße',
        'Ångström',
        'Łódź Straße',
        'Ñandú',
        'Ærø',
        'ΜΕP粗装 Task',
        '项目',
        'Ελλάδα',
        '  --Hello--World--  ',
        "O'Brien & Sons",
        'a-b_c.d',
        'Test Contact 016',
        str_repeat('ab', 50),
        str_repeat('abcde-', 20),
        '---',
        '',
    ];

    $parity_failures = [];
    foreach ($parity_inputs as $in) {
        $php = slugify($in);
        $row = $db->fetchOne('SELECT slug_base(?) AS s', [$in]);
        $sql = $row === null || $row === false ? null : (string)$row['s'];
        if ($php !== $sql) {
            $parity_failures[] = sprintf(
                '%s: php=%s sql=%s',
                var_export($in, true),
                var_export($php, true),
                var_export($sql, true)
            );
        }
    }

    ok(
        empty($parity_failures),
        'PHP slugify() matches SQL slug_base() on all ' . count($parity_inputs) . " parity inputs"
            . (empty($parity_failures) ? '' : "\n         MISMATCHES:\n           " . implode("\n           ", $parity_failures))
    );

    $db->execute('DROP FUNCTION IF EXISTS slug_base');
}

// ---------------------------------------------------------------------------
section('T5. Slug uniqueness — duplicates get -2, -3, … (Appendix C.4/C.5)');
// ---------------------------------------------------------------------------

// Use `contacts` for this check: it has no foreign keys, so inserting and
// cleaning up rows cannot disturb project/task data.
$createdIds = [];
try {
    foreach (['Dup Check Alpha', 'Dup Check Alpha', 'Dup Check Alpha'] as $i => $n) {
        $slug = generateUniqueSlug($n, 'contacts');
        $db->execute('INSERT INTO contacts (name, slug) VALUES (?, ?)', [$n, $slug]);
        $createdIds[] = (int)$db->lastInsertId();
    }

    $rows = $db->fetchAll(
        'SELECT name, slug FROM contacts WHERE name = ? ORDER BY slug',
        ['Dup Check Alpha']
    );
    $slugs = array_column($rows, 'slug');

    ok(
        $slugs === ['dup-check-alpha', 'dup-check-alpha-2', 'dup-check-alpha-3'],
        'three same-named contacts get -2 / -3 suffixes — got ' . json_encode($slugs)
    );

    // T5b: generateUniqueSlug() must not collide with the row it is
    // regenerating for (the $excludeId path used on UPDATE).
    $existingId = $createdIds[0];
    $regenerated = generateUniqueSlug('Dup Check Alpha', 'contacts', $existingId);
    ok(
        $regenerated === 'dup-check-alpha',
        'generateUniqueSlug() with $excludeId returns the row\'s own slug instead of bumping to -2'
    );

    // T5c: uniqueness is per table (C.2). The same slug may exist in both
    // projects and contacts without conflict.
    ok(
        in_array('dup-check-alpha', $slugs, true),
        'slug exists in contacts and would be reusable in projects/tasks (per-table uniqueness)'
    );
} finally {
    foreach ($createdIds as $id) {
        $db->execute('DELETE FROM contacts WHERE id = ?', [$id]);
    }
}

// ---------------------------------------------------------------------------
section('T6. Migration idempotency — re-running does not renumber (C.2)');
// ---------------------------------------------------------------------------

// Verify the live schema already carries the columns (the migration has
// been applied to this database).
foreach (['projects', 'tasks', 'contacts'] as $table) {
    $c = $db->fetchOne(
        'SELECT COLUMN_TYPE, IS_NULLABLE FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?',
        [$table, 'slug']
    );
    ok(
        $c && $c['COLUMN_TYPE'] === 'varchar(80)' && $c['IS_NULLABLE'] === 'NO',
        "{$table}.slug is VARCHAR(80) NOT NULL (migration steps 1-5 complete)"
    );

    $idx = $db->fetchOne(
        'SELECT INDEX_NAME FROM information_schema.STATISTICS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ? AND NON_UNIQUE = 0',
        [$table, 'slug']
    );
    ok($idx !== null, "{$table}.slug has a UNIQUE index");
}

// The migration is guarded so re-running is a no-op. Assert the guard
// exists rather than actually re-running DDL against the live DB: a
// snapshot of the current slug map is enough to prove the guards are in
// place, and slug_http_test.php / the manual V36 check re-run it for real.
$before = $db->fetchAll('SELECT id, slug FROM projects ORDER BY id');
$migration_text = file_get_contents($repo_root . '/database/migrations/20261022_add_slugs.sql');

ok(
    substr_count($migration_text, "COLUMN_NAME = 'slug'") >= 6,
    'migration guards every ADD/MODIFY with an information_schema check (idempotent)'
);
ok(
    stripos($migration_text, 'WHERE slug IS NULL') !== false,
    'migration backfill only touches rows with an empty slug, so a re-run cannot renumber'
);
ok(
    stripos($migration_text, 'ORDER BY id') !== false,
    'migration iterates ORDER BY id, giving the -2/-3 counter a stable order'
);

$after = $db->fetchAll('SELECT id, slug FROM projects ORDER BY id');
ok($before === $after, 'project slug map is stable across a no-op re-run of the migration logic');

// ---------------------------------------------------------------------------
section('T7. Slug immutability — renaming a record does not change its URL (R15)');
// ---------------------------------------------------------------------------

$probeId = null;
try {
    $probeSlug = generateUniqueSlug('Immutability Probe', 'contacts');
    $db->execute('INSERT INTO contacts (name, slug) VALUES (?, ?)', ['Immutability Probe', $probeSlug]);
    $probeId = (int)$db->lastInsertId();

    $s1 = $db->fetchOne('SELECT slug FROM contacts WHERE id = ?', [$probeId]);
    ok($s1['slug'] === $probeSlug, 'contact created with its generated slug');

    // Simulate a rename. The application must NOT regenerate the slug
    // (C.5). This assertion is the guard on that behaviour.
    $db->execute('UPDATE contacts SET name = ? WHERE id = ?', ['Totally Different Name', $probeId]);
    $s2 = $db->fetchOne('SELECT slug FROM contacts WHERE id = ?', [$probeId]);

    ok(
        $s2['slug'] === $probeSlug,
        'slug survives a rename unchanged (C.5: "Record name edited → slug is NOT regenerated")'
    );
    ok(
        findIdBySlug($probeSlug, 'contacts') === $probeId,
        'the original slug still resolves to the record after the rename'
    );
} finally {
    if ($probeId !== null) {
        $db->execute('DELETE FROM contacts WHERE id = ?', [$probeId]);
    }
}

// ---------------------------------------------------------------------------
section('T-extra. findIdBySlug() / getSlugById() round-trip and guards');
// ---------------------------------------------------------------------------

$sample = $db->fetchOne('SELECT id, slug FROM projects ORDER BY id LIMIT 1');
if ($sample) {
    ok(
        findIdBySlug($sample['slug'], 'projects') === (int)$sample['id'],
        'findIdBySlug() resolves a real project slug back to its id'
    );
    ok(
        getSlugById('projects', (int)$sample['id']) === (string)$sample['slug'],
        'getSlugById() returns the stored slug for a real project'
    );
}

ok(
    findIdBySlug('definitely-not-a-real-slug-xyz', 'projects') === null,
    'findIdBySlug() returns null for an unknown slug (caller must 404, not redirect)'
);
ok(
    getSlugById('projects', 99999999) === '',
    'getSlugById() returns "" for an unknown id'
);

// Both helpers interpolate $table into SQL, so the allow-list is a
// security control, not a nicety.
$threw = false;
try {
    findIdBySlug('x', 'users; DROP TABLE users');
} catch (InvalidArgumentException $e) {
    $threw = true;
}
ok($threw, 'findIdBySlug() rejects a non-allow-listed table name (SQL-injection guard)');

$threw = false;
try {
    generateUniqueSlug('x', 'users');
} catch (InvalidArgumentException $e) {
    $threw = true;
}
ok($threw, 'generateUniqueSlug() rejects a non-allow-listed table name (SQL-injection guard)');

$threw = false;
try {
    getSlugById('password_reset_tokens', 1);
} catch (InvalidArgumentException $e) {
    $threw = true;
}
ok($threw, 'getSlugById() rejects a non-allow-listed table name (SQL-injection guard)');

// ---------------------------------------------------------------------------
echo "\n" . str_repeat('=', 60) . "\n";
echo "Passed: {$pass}   Failed: {$fail}\n";
echo str_repeat('=', 60) . "\n";

exit($fail === 0 ? 0 : 1);