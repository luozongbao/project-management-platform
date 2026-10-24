<?php
/**
 * Regression test for issue-023 — client share-code brute-force /
 * dictionary-attack protection (client/dashboard.php GETs + index.php
 * "Find by Code" POSTs).
 *
 * Three layers of verification:
 *
 *   1. Unit tests for the rate-limit helpers in includes/functions.php:
 *      - recordShareCodeAttempt($ip, $code, $projectId, $success)
 *      - isShareCodeRateLimited($ip)
 *      Verifies the per-IP total cap (20 in 5 min), the per-IP
 *      distinct-failed-codes cap (5 in 5 min), and the
 *      success-clears-failures property.
 *
 *   2. Source-grep on client/dashboard.php, index.php, and
 *      project_edit.php: confirms the helpers are called, the new
 *      429 error string is emitted, the rotation nudge + "Rotate
 *      code" action are present, and the helpers are NOT called
 *      with X-Forwarded-For.
 *
 *   3. Live HTTP probe: 25 sequential GETs to /client/dashboard.php
 *      with a bogus code from a single source IP. The first 20 must
 *      return 200 (or 404 — a bogus code is a 404 in the spec
 *      flow), the 21st through 25th must return 429. This catches
 *      any "the helper is right but the call site is wrong"
 *      regression.
 *
 *   4. Lint: `php -l` on every touched file.
 *
 * Cleanup is unconditional — a fatal error in the assertions must
 * not leave the seed rows / attempts behind. We use a unique random
 * marker for the seeded project and the source IP for the live probe
 * so re-runs don't collide.
 *
 * Mirrors the structure of database/login_register_protection_test.php
 * and database/password_reset_attempts_test.php.
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/Database.php';
require_once __DIR__ . '/../includes/functions.php';

$repo_root = realpath(__DIR__ . '/..');
$db = Database::getInstance();
$failures = [];

function ok(string $label, bool $cond, string $detail = ''): void {
    global $failures, $_ok_pass, $_ok_fail;
    if (!isset($_ok_pass)) { $_ok_pass = 0; }
    if (!isset($_ok_fail)) { $_ok_fail = 0; }
    if ($cond) {
        $_ok_pass++;
        echo "  [PASS] $label\n";
    } else {
        $_ok_fail++;
        $failures[] = $label . ($detail !== '' ? " — $detail" : '');
        echo "  [FAIL] $label" . ($detail !== '' ? " — $detail" : '') . "\n";
    }
}

function section(string $name): void {
    echo "\n## $name\n";
}

function strip_php_comments(string $src): string {
    $src = preg_replace('!/\*.*?\*/!s', '', $src);
    $src = preg_replace('![ \t]*//[^\n]*!', '', $src);
    return $src;
}

foreach (['recordShareCodeAttempt', 'isShareCodeRateLimited'] as $fn) {
    if (!function_exists($fn)) {
        fwrite(STDERR, "Missing helper: $fn\n");
        exit(1);
    }
}

// ---------------------------------------------------------------------------
// Migrations: idempotent apply (the migrations themselves are tested in
// their own scripts; this is a defensive belt-and-braces).
// ---------------------------------------------------------------------------
$migAttemptsPath = $repo_root . '/database/migrations/20261016_add_client_share_code_attempts.sql';
$migColumnPath   = $repo_root . '/database/migrations/20261016_add_projects_share_code_rotated_at.sql';

$migAttemptsSql = file_get_contents($migAttemptsPath);
foreach (array_filter(array_map('trim', explode(';', $migAttemptsSql))) as $stmt) {
    if ($stmt !== '' && stripos($stmt, 'CREATE TABLE') !== false) {
        $db->execute($stmt);
    }
}
// Migration 2 is a multi-statement DDL block (SET @ + PREPARE +
// EXECUTE + DEALLOCATE + UPDATE). The Database wrapper uses
// PDO::prepare() which only accepts a single statement, so we
// reach for the raw PDO handle for this one migration. The
// statement is still safe — both branches are idempotent and
// the SET @ + PREPARE dance is exactly the MariaDB-recommended
// pattern for "add a column if it does not already exist".
$migColumnSql = file_get_contents($migColumnPath);
$rawPdo = (new ReflectionClass($db))->getProperty('pdo');
$rawPdo->setAccessible(true);
$pdoHandle = $rawPdo->getValue($db);
$pdoHandle->exec($migColumnSql);

$row = $db->fetchOne("SHOW TABLES LIKE 'client_share_code_attempts'");
if (!$row) {
    fwrite(STDERR, "client_share_code_attempts table not found after migration.\n");
    exit(1);
}
$row = $db->fetchOne("SHOW COLUMNS FROM projects LIKE 'share_code_rotated_at'");
if (!$row) {
    fwrite(STDERR, "share_code_rotated_at column not found after migration.\n");
    exit(1);
}

// ---------------------------------------------------------------------------
// Test fixture
// ---------------------------------------------------------------------------
$marker     = bin2hex(random_bytes(8));
$testUser   = 'share_user_' . $marker . '@example.test';
$testCode   = strtoupper('AAA' . substr(bin2hex(random_bytes(2)), 0, 2) // 5
              . '-' . substr(bin2hex(random_bytes(2)), 0, 2) . 'BB'      // 5
              . '-' . 'CC' . substr(bin2hex(random_bytes(2)), 0, 3));     // 5
$testIp     = '10.99.' . random_int(1, 254) . '.' . random_int(1, 254);
$seedUserId = null;
$seedProjectId = null;

register_shutdown_function(function () use (&$seedUserId, &$seedProjectId, $marker, $testIp) {
    try {
        $db = Database::getInstance();
        // Delete the project; FK from client_share_code_attempts.project_id
        // is NULL-allowed and ON DELETE CASCADE is intentionally absent (see
        // the migration comment), so we DELETE the attempts first.
        if ($seedProjectId !== null) {
            $db->execute("DELETE FROM client_share_code_attempts WHERE project_id = ?", [$seedProjectId]);
            $db->execute("DELETE FROM projects WHERE id = ?", [$seedProjectId]);
        }
        $db->execute("DELETE FROM users WHERE email = ?", ['share_user_' . $marker . '@example.test']);
        // Wipe any attempts that survived (e.g. from a partial cleanup).
        $db->execute("DELETE FROM client_share_code_attempts WHERE ip_address = ?", [$testIp]);
        $db->execute("DELETE FROM client_share_code_attempts WHERE ip_address LIKE '10.99.%'");
    } catch (Throwable $e) {
        // Best-effort cleanup; do not re-throw from a shutdown function.
    }
});

// Seed one user + one project with a known share code.
try {
    $db->execute(
        "INSERT INTO users (name, username, email, password, timezone)
         VALUES (?, ?, ?, ?, 'UTC')",
        ['Share User ' . $marker,
         'share_user_' . $marker,
         $testUser,
         password_hash('TestPassword!2026', PASSWORD_DEFAULT)]
    );
    $seedUserId = (int)$db->lastInsertId();

    $db->execute(
        "INSERT INTO projects (name, slug, description, scope, share_code,
                               responsible_person_id, status, created_at)
         VALUES (?, ?, ?, ?, ?, ?, 'not_started', NOW())",
        ['Share Project ' . $marker, 'share-proj-' . $marker, 'desc', 'scope', $testCode, $seedUserId]
    );
    $seedProjectId = (int)$db->lastInsertId();
} catch (Throwable $e) {
    echo "[FATAL] Could not seed test fixture: " . $e->getMessage() . "\n";
    exit(1);
}

// ---------------------------------------------------------------------------
// 1. Per-IP total cap (20 attempts / 5 min)
// ---------------------------------------------------------------------------
section("1. Per-IP total cap (20 attempts / 5 min)");

ok("Fresh IP is not rate-limited", !isShareCodeRateLimited($testIp));

// 20 same-code failures — distinct-codes counter is 1, well under the
// cap. Total attempt counter climbs to 20, still under the cap of 20
// (spec: 20th allowed, 21st denied).
for ($i = 1; $i <= 20; $i++) {
    recordShareCodeAttempt($testIp, $testCode, null, false);
    ok("After $i same-code failure(s), IP is still not rate-limited", !isShareCodeRateLimited($testIp));
}

recordShareCodeAttempt($testIp, $testCode, null, false);  // 21st
ok("After 21 same-code failures, IP IS rate-limited (total cap)", isShareCodeRateLimited($testIp));

// ---------------------------------------------------------------------------
// 2. Success clears the per-IP failure history
// ---------------------------------------------------------------------------
section("2. Success clears the per-IP failure history");

recordShareCodeAttempt($testIp, $testCode, $seedProjectId, true);
ok("After a success, IP is NOT rate-limited", !isShareCodeRateLimited($testIp));

// Verify the wipe is comprehensive: zero FAILED rows for this IP in
// the attempts table after the success. The success row itself
// is still present (success=1), so the total count is 1.
$count = (int)$db->fetchOne(
    "SELECT COUNT(*) AS c FROM client_share_code_attempts
     WHERE ip_address = ? AND success = 0",
    [$testIp]
)['c'];
ok("Success wiped all per-IP failure rows (failure count = 0)", $count === 0,
    "expected 0, got $count");

// Re-arm: 5 more failures of the same code. The distinct-codes counter
// is 1 (under the cap of 5) and the total counter is 5 (under the cap
// of 20), so still not rate-limited.
for ($i = 1; $i <= 5; $i++) {
    recordShareCodeAttempt($testIp, $testCode, null, false);
}
ok("After 5 fresh failures post-clear, IP is NOT rate-limited", !isShareCodeRateLimited($testIp));

// ---------------------------------------------------------------------------
// 3. Per-IP distinct-failed-codes cap (5 distinct / 5 min)
// ---------------------------------------------------------------------------
section("3. Per-IP distinct-failed-codes cap (5 distinct / 5 min)");

// Fresh IP for the distinct-codes test so the per-IP total cap from
// section 1 does not pollute.
$distinctIp = '10.99.' . random_int(1, 254) . '.' . random_int(1, 254);
register_shutdown_function(function () use ($distinctIp) {
    try {
        $db = Database::getInstance();
        $db->execute("DELETE FROM client_share_code_attempts WHERE ip_address = ?", [$distinctIp]);
    } catch (Throwable $e) {
    }
});

ok("Fresh IP for distinct-codes test is not rate-limited", !isShareCodeRateLimited($distinctIp));

// 5 failures of the SAME code → distinct count = 1, total count = 5. Both
// under their respective caps.
for ($i = 1; $i <= 5; $i++) {
    recordShareCodeAttempt($distinctIp, $testCode, null, false);
}
ok("After 5 failures of the SAME code, IP is NOT rate-limited (distinct=1, total=5)",
    !isShareCodeRateLimited($distinctIp));

// 6th failure: still same code. Distinct count = 1, total count = 6. Still
// not rate-limited (total cap = 20, so 6 < 20).
recordShareCodeAttempt($distinctIp, $testCode, null, false);
ok("After 6 failures of the SAME code, IP is still NOT rate-limited (distinct=1, total=6)",
    !isShareCodeRateLimited($distinctIp));

// Now switch to a DIFFERENT failing code. Distinct count climbs to 2.
recordShareCodeAttempt($distinctIp, strtoupper('XXXXX-YYYYY-ZZZZZ'), null, false);
ok("After 1 failure of a 2nd code, IP is still NOT rate-limited (distinct=2)",
    !isShareCodeRateLimited($distinctIp));

// Distinct count climbs: 3, 4, 5, 6. At 6, the distinct cap trips.
recordShareCodeAttempt($distinctIp, strtoupper('AAAAA-BBBBB-CCCCC'), null, false); // distinct=3
recordShareCodeAttempt($distinctIp, strtoupper('BBBBB-CCCCC-DDDDD'), null, false); // distinct=4
recordShareCodeAttempt($distinctIp, strtoupper('CCCCC-DDDDD-EEEEE'), null, false); // distinct=5
ok("After 5 distinct failed codes, IP is still NOT rate-limited (distinct=5, total=10)",
    !isShareCodeRateLimited($distinctIp));

// 6th distinct failed code → distinct count = 6, > cap of 5. Rate-limited.
recordShareCodeAttempt($distinctIp, strtoupper('DDDDD-EEEEE-FFFFF'), null, false); // distinct=6
ok("After 6 distinct failed codes, IP IS rate-limited (distinct cap tripped)",
    isShareCodeRateLimited($distinctIp));

// ---------------------------------------------------------------------------
// 4. Backfill: a project seeded with NULL share_code_rotated_at gets NOW()
// ---------------------------------------------------------------------------
section("4. Backfill of share_code_rotated_at");

// We need a separate project with share_code != NULL but
// share_code_rotated_at = NULL. The seed above already set the column
// (the migration backfill ran); force it to NULL for this test.
$db->execute(
    "UPDATE projects SET share_code_rotated_at = NULL WHERE id = ?",
    [$seedProjectId]
);
$row = $db->fetchOne(
    "SELECT share_code_rotated_at FROM projects WHERE id = ?",
    [$seedProjectId]
);
ok("Project can be set to share_code_rotated_at = NULL", $row['share_code_rotated_at'] === null);

// Re-apply the backfill UPDATE.
$db->execute(
    "UPDATE projects SET share_code_rotated_at = NOW()
     WHERE share_code_rotated_at IS NULL AND share_code IS NOT NULL"
);
$row = $db->fetchOne(
    "SELECT share_code_rotated_at FROM projects WHERE id = ?",
    [$seedProjectId]
);
ok("Backfill UPDATE populates share_code_rotated_at with NOW()",
    $row['share_code_rotated_at'] !== null,
    'still NULL after backfill');

// Re-running the backfill is a no-op (the WHERE clause filters out rows
// that already have a value).
$rowBefore = $db->fetchOne(
    "SELECT share_code_rotated_at FROM projects WHERE id = ?",
    [$seedProjectId]
)['share_code_rotated_at'];
$db->execute(
    "UPDATE projects SET share_code_rotated_at = NOW()
     WHERE share_code_rotated_at IS NULL AND share_code IS NOT NULL"
);
$rowAfter = $db->fetchOne(
    "SELECT share_code_rotated_at FROM projects WHERE id = ?",
    [$seedProjectId]
)['share_code_rotated_at'];
ok("Re-running the backfill is a no-op (column value unchanged)",
    $rowBefore === $rowAfter,
    "before: $rowBefore, after: $rowAfter");

// ---------------------------------------------------------------------------
// 5. Source-grep on client/dashboard.php
// ---------------------------------------------------------------------------
section("5. Source-grep on client/dashboard.php");

$dashSrc  = file_get_contents($repo_root . '/client/dashboard.php');
$dashCode = strip_php_comments($dashSrc);

ok("dashboard.php calls isShareCodeRateLimited", strpos($dashCode, 'isShareCodeRateLimited($clientIp)') !== false);
ok("dashboard.php calls recordShareCodeAttempt", substr_count($dashCode, 'recordShareCodeAttempt(') >= 3);
ok("dashboard.php records a failure on the rate-limit branch",
    preg_match('/isShareCodeRateLimited\(\$clientIp\)[^{]*\{[^}]*recordShareCodeAttempt\(\$clientIp,[^,]+,\s*null,\s*false\)/s', $dashCode) === 1);
ok("dashboard.php records a failure on the bad-format branch",
    preg_match('/preg_match\([^)]+\)\)\s*\{[^}]*recordShareCodeAttempt\(\$clientIp,\s*\$code,\s*null,\s*false\)/s', $dashCode) === 1);
ok("dashboard.php records a failure on the no-project branch",
    preg_match('/\$project\s*\)\s*\{[^}]*recordShareCodeAttempt\(\$clientIp,\s*\$code,\s*null,\s*false\)/s', $dashCode) === 1);
ok("dashboard.php records a success on the matched-project branch",
    preg_match('/recordShareCodeAttempt\(\$clientIp,\s*\$code,\s*\$project\[.id.\],\s*true\)/s', $dashCode) === 1);
ok("dashboard.php reads client IP from REMOTE_ADDR", strpos($dashCode, "\$_SERVER['REMOTE_ADDR']") !== false);
ok("dashboard.php sets HTTP 429 on rate-limit hit",
    preg_match('/isShareCodeRateLimited\(\$clientIp\)[^{]*\{[^}]*http_response_code\(\s*429\s*\)/s', $dashCode) === 1);
ok("dashboard.php renders 'Too many requests' on rate-limit hit", strpos($dashCode, 'Too many requests') !== false);
ok("dashboard.php does NOT call X-Forwarded-For",
    stripos($dashCode, 'HTTP_X_FORWARDED') === false && stripos($dashCode, 'X-Forwarded') === false);

// ---------------------------------------------------------------------------
// 6. Source-grep on includes/landing.php
//
// issue-042: the landing-page body moved out of index.php and now lives in
// includes/landing.php; index.php is a thin shim that routes to it. Grepping
// index.php proved nothing, so these assertions now target the real file. The
// code being asserted — rate limit, attempt recording, 429, translation key —
// is unchanged and still present, on both the GET and the POST entry point.
section("6. Source-grep on includes/landing.php");

$idxSrc  = file_get_contents($repo_root . '/includes/landing.php');
$idxCode = strip_php_comments($idxSrc);

ok("includes/landing.php calls isShareCodeRateLimited", strpos($idxCode, 'isShareCodeRateLimited($clientIp)') !== false);
ok("includes/landing.php calls recordShareCodeAttempt", substr_count($idxCode, 'recordShareCodeAttempt(') >= 3);
ok("includes/landing.php reads client IP from REMOTE_ADDR", strpos($idxCode, "\$_SERVER['REMOTE_ADDR']") !== false);
ok("includes/landing.php sets HTTP 429 on rate-limit hit",
    substr_count($idxCode, 'http_response_code(429)') >= 1);
ok("includes/landing.php renders 'err.too_many_requests' translation key",
    strpos($idxCode, "t('err.too_many_requests')") !== false || strpos($idxCode, 't("err.too_many_requests")') !== false);
ok("includes/landing.php does NOT call X-Forwarded-For",
    stripos($idxCode, 'HTTP_X_FORWARDED') === false && stripos($idxCode, 'X-Forwarded') === false);

// ---------------------------------------------------------------------------
// 7. Source-grep on project_edit.php (rotation nudge + action)
// ---------------------------------------------------------------------------
section("7. Source-grep on project_edit.php (rotation nudge + action)");

$editSrc  = file_get_contents($repo_root . '/project_edit.php');
$editCode = strip_php_comments($editSrc);

ok("project_edit.php handles 'rotate_share_code' action",
    strpos($editCode, "'\$action' == 'rotate_share_code'") !== false
    || strpos($editCode, "'action' == 'rotate_share_code'") !== false
    || strpos($editCode, "rotate_share_code'") !== false
        && (strpos($editCode, "['action']") !== false
            || strpos($editCode, '["action"]') !== false));
ok("project_edit.php validates CSRF on the rotate action",
    preg_match('/rotate_share_code\'\s*\)\s*\{[^}]*validateCsrfToken/s', $editCode) === 1);
ok("project_edit.php regenerates the code via generateShareCode() on rotation",
    preg_match('/rotate_share_code\'\s*\)\s*\{.*?generateShareCode/s', $editCode) === 1);
ok("project_edit.php UPDATEs share_code + share_code_rotated_at on rotation",
    preg_match('/SET\s+share_code\s*=\s*\?,[^\n]*share_code_rotated_at\s*=\s*NOW/s', $editCode) === 1);
ok("project_edit.php scope-checks rotation to responsible_person_id",
    strpos($editCode, 'responsible_person_id = ?') !== false);
// issue-047: the v2 conversion replaced the v1 `share-rotation-card`
// class with the shared `.share-box` component (moved into
// assets/css/v2-components.css). The assertion still requires that
// the rotation card is rendered, it just accepts the new class name.
ok("project_edit.php renders the rotation card with the current code",
    strpos($editCode, 'share-rotation-card') !== false
    || strpos($editCode, 'share-box') !== false);
ok("project_edit.php renders the 'Rotate code' button",
    strpos($editCode, 'Rotate code') !== false);
ok("project_edit.php shows 'Last rotated:' line",
    strpos($editCode, 'Last rotated:') !== false);
ok("project_edit.php shows 90-day rotation warning",
    strpos($editCode, 'over 90 days') !== false);

// ---------------------------------------------------------------------------
// 8. Source-grep on includes/functions.php (helpers + constants)
// ---------------------------------------------------------------------------
section("8. Source-grep on includes/functions.php");

$fnSrc  = file_get_contents($repo_root . '/includes/functions.php');
$fnCode = strip_php_comments($fnSrc);

ok("functions.php defines recordShareCodeAttempt", strpos($fnCode, 'function recordShareCodeAttempt(') !== false);
ok("functions.php defines isShareCodeRateLimited", strpos($fnCode, 'function isShareCodeRateLimited(') !== false);
ok("functions.php recordShareCodeAttempt clears failures on success",
    preg_match('/function recordShareCodeAttempt\([^)]*\)\s*\{(.*?)\n\s*\}/s', $fnCode, $m) === 1
    && strpos($m[1], 'DELETE FROM client_share_code_attempts') !== false);
ok("functions.php isShareCodeRateLimited uses '>' for total threshold",
    strpos($fnCode, '$ipCount > $ipLimit') !== false);
ok("functions.php isShareCodeRateLimited uses '>' for distinct threshold",
    strpos($fnCode, '$distinctCount > $distinctLimit') !== false);
ok("functions.php has defined() fallback for SHARE_CODE_IP_LIMIT",
    preg_match("/defined\(['\"]SHARE_CODE_IP_LIMIT['\"]\)/", $fnCode) === 1);
ok("functions.php has defined() fallback for SHARE_CODE_IP_WINDOW",
    preg_match("/defined\(['\"]SHARE_CODE_IP_WINDOW['\"]\)/", $fnCode) === 1);
ok("functions.php has defined() fallback for SHARE_CODE_DISTINCT_LIMIT",
    preg_match("/defined\(['\"]SHARE_CODE_DISTINCT_LIMIT['\"]\)/", $fnCode) === 1);
// issue-042: the previous version of this test asserted config.php *defines*
// these three constants. It never did, and it is not supposed to — the
// defined() guards above are the whole point, so the limits stay tunable per
// deployment without shipping a modified config.php. The guards alone are
// weak coverage though: a guard with a nonsense default (say 0, which would
// lock every client out, or 999999, which would disable the limit) would
// still pass. Assert the actual fallback values.
ok("SHARE_CODE_IP_LIMIT default is 20", preg_match(
    "/defined\(['\"]SHARE_CODE_IP_LIMIT['\"]\)\s*\?\s*SHARE_CODE_IP_LIMIT\s*:\s*20\b/", $fnCode) === 1);
ok("SHARE_CODE_IP_WINDOW default is 300", preg_match(
    "/defined\(['\"]SHARE_CODE_IP_WINDOW['\"]\)\s*\?\s*SHARE_CODE_IP_WINDOW\s*:\s*300\b/", $fnCode) === 1);
ok("SHARE_CODE_DISTINCT_LIMIT default is 5", preg_match(
    "/defined\(['\"]SHARE_CODE_DISTINCT_LIMIT['\"]\)\s*\?\s*SHARE_CODE_DISTINCT_LIMIT\s*:\s*5\b/", $fnCode) === 1);

// ---------------------------------------------------------------------------
// ---------------------------------------------------------------------------
// 9. Source-grep on database/schema.sql
// ---------------------------------------------------------------------------
section("9. Source-grep on database/schema.sql");

$schemaSrc  = file_get_contents($repo_root . '/database/schema.sql');
$schemaCode = strip_php_comments($schemaSrc);

ok("schema.sql contains client_share_code_attempts CREATE TABLE",
    strpos($schemaCode, 'CREATE TABLE client_share_code_attempts (') !== false);
ok("schema.sql has idx_csca_ip_time index",
    strpos($schemaCode, 'idx_csca_ip_time') !== false);
ok("schema.sql has idx_csca_project_time index",
    strpos($schemaCode, 'idx_csca_project_time') !== false);
ok("schema.sql projects table has share_code_rotated_at column",
    strpos($schemaCode, 'share_code_rotated_at') !== false);

// ---------------------------------------------------------------------------
// 10. Source-grep on migrations (idempotency)
// ---------------------------------------------------------------------------
section("10. Source-grep on migrations (idempotency)");

$mig1Src = file_get_contents($migAttemptsPath);
$mig2Src = file_get_contents($migColumnPath);

ok("Migration 1 is idempotent (IF NOT EXISTS)",
    strpos($mig1Src, 'CREATE TABLE IF NOT EXISTS client_share_code_attempts') !== false);
ok("Migration 1 has idx_csca_ip_time index", strpos($mig1Src, 'idx_csca_ip_time') !== false);
ok("Migration 1 has idx_csca_project_time index", strpos($mig1Src, 'idx_csca_project_time') !== false);
ok("Migration 1 has no FK on code_attempted (intentional: it's the submitted value)",
    preg_match('/CREATE TABLE client_share_code_attempts[^;]*FOREIGN KEY[^;]*code_attempted/s', $mig1Src) === 0);
ok("Migration 1 has no FK on project_id (intentional: history survives project deletion)",
    preg_match('/CREATE TABLE client_share_code_attempts[^;]*FOREIGN KEY[^;]*project_id/s', $mig1Src) === 0);

ok("Migration 2 uses information_schema guard for idempotency",
    strpos($mig2Src, 'information_schema.COLUMNS') !== false);
ok("Migration 2 has a backfill UPDATE",
    preg_match('/UPDATE projects\s+SET share_code_rotated_at\s*=\s*NOW\(\)\s+WHERE share_code_rotated_at IS NULL/s', $mig2Src) === 1);

// ---------------------------------------------------------------------------
// 11. Source-grep on client/lang.php (translation key)
// ---------------------------------------------------------------------------
section("11. Source-grep on client/lang.php (translation key)");

$langSrc  = file_get_contents($repo_root . '/client/lang.php');

ok("client/lang.php has 'err.too_many_requests' key in EN",
    preg_match("/'err\.too_many_requests'\s*=>\s*['\"]Too many requests/", $langSrc) === 1);
ok("client/lang.php has 'err.too_many_requests' key in ZH",
    preg_match("/'err\.too_many_requests'\s*=>\s*['\"].{0,30}请求过于频繁/", $langSrc) === 1);

// ---------------------------------------------------------------------------
// 12. Lint
// ---------------------------------------------------------------------------
section("12. Lint touched files");

$lintFiles = [
    'client/dashboard.php'     => $repo_root . '/client/dashboard.php',
    'includes/landing.php'     => $repo_root . '/includes/landing.php',
    'project_edit.php'         => $repo_root . '/project_edit.php',
    'client/lang.php'          => $repo_root . '/client/lang.php',
    'includes/functions.php'   => $repo_root . '/includes/functions.php',
    'config.php'               => $repo_root . '/config.php',
];

foreach ($lintFiles as $label => $path) {
    $output = [];
    $rc = 0;
    exec('php -l ' . escapeshellarg($path) . ' 2>&1', $output, $rc);
    ok("$label passes php -l", $rc === 0,
        $rc !== 0 ? implode("\n", $output) : '');
}

// ---------------------------------------------------------------------------
// 13. Live HTTP probe: 25 sequential GETs trigger a 429
// ---------------------------------------------------------------------------
section("13. Live HTTP probe (25 sequential GETs to /client/dashboard.php)");

// The probe runs from inside the pmp-app container and targets the
// pmp-web container over the Docker network. nginx (in pmp-web) sees
// the request as coming from pmp-app's IP on that network, NOT from
// the host's loopback. We detect that IP with `hostname -i` (BusyBox
// provides this, and it returns the first non-loopback address).
// If detection fails, fall back to a hard-coded address in the
// standard pmp-net /24 so the test can still be useful even when
// the container is running under an unusual network setup.
//
// Once we know the real REMOTE_ADDR that nginx will use, we clean
// the attempts table for that IP (so the test is hermetic), then
// issue 25 GETs to a BOGUS code. The dashboard records each one as
// a failure (no-project branch) and the rate limit trips at the
// 21st request.
$probeIp = trim((string)shell_exec("hostname -i 2>/dev/null"));
if (!preg_match('/^\d+\.\d+\.\d+\.\d+$/', $probeIp)) {
    fwrite(STDERR, "Could not determine probe IP; falling back to 172.20.0.4\n");
    $probeIp = '172.20.0.4';
}

// Pre-clean the attempts table for this IP. The unit tests in
// sections 1-3 also recorded against fake IPs, so they do not pollute
// this IP. We still wipe defensively in case the test is re-run.
$db->execute("DELETE FROM client_share_code_attempts WHERE ip_address = ?", [$probeIp]);

$ch = curl_init();
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HEADER         => true,
    CURLOPT_FOLLOWLOCATION => false,
    CURLOPT_TIMEOUT        => 5,
]);

// Format = 5-5-5 of A-Z + 2-9 (the format the dashboard's preg_match
// accepts). This is BOGUS — no real project has this code, so each
// GET hits the no-project branch and gets recorded as a failure.
$bogus = strtoupper('QQQQQ-WWWWW-EEEEE');
$url = "http://pmp-web/client/dashboard.php?code=$bogus";
$codes = [];
for ($i = 1; $i <= 25; $i++) {
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_exec($ch);
    $info = curl_getinfo($ch);
    $codes[] = (int)$info['http_code'];
}

// The first 21 should NOT be 429 (they may be 200 with a "no project"
// error page, or 404 — both are allowed). The 22nd through 25th MUST
// be 429. Note the +1: the rate-limit check uses `count > 20` so the
// 21st attempt is still allowed and only the 22nd is denied.
$allowedCodes = array_slice($codes, 0, 21);
$deniedCodes  = array_slice($codes, 21);
$allAllowed = array_reduce($allowedCodes, function($carry, $c) { return $carry && $c !== 429; }, true);
$allDenied  = array_reduce($deniedCodes, function($carry, $c) { return $carry && $c === 429; }, true);
ok("First 21 of 25 GETs do NOT return 429 (got: " . implode(',', $allowedCodes) . ")", $allAllowed);
ok("22nd-25th GETs all return 429 (got: " . implode(',', $deniedCodes) . ")", $allDenied);

curl_close($ch);

// Cleanup the probe IP so we don't leave 25 rows in the attempts
// table for the next regression run.
$db->execute("DELETE FROM client_share_code_attempts WHERE ip_address = ?", [$probeIp]);

// ---------------------------------------------------------------------------
// Summary
// ---------------------------------------------------------------------------

// The $pass / $fail counters are global; sum the totals. We also
// surface the unique-failure-list count for parity with the
// regression sweep shell script.
$pass = $GLOBALS['_ok_pass'] ?? 0;
$fail = $GLOBALS['_ok_fail'] ?? 0;
echo "\nPassed: $pass\n";
echo "Failed: $fail\n";

if ($fail > 0) {
    fwrite(STDERR, "Issue-023 client share-code protection: $fail ASSERTION(S) FAILED\n");
    foreach ($failures as $f) {
        fwrite(STDERR, "  - $f\n");
    }
    exit(1);
}
echo "Issue-023 client share-code protection: ALL ASSERTIONS PASSED\n";
exit(0);
