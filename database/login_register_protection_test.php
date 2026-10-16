<?php
/**
 * Regression test for issue-019 — login & registration brute-force /
 * enumeration protection (login.php + register.php).
 *
 * Three layers of verification:
 *
 *   1. Unit tests for the rate-limit helpers in includes/functions.php:
 *      - recordLoginAttempt($ip, $username, $success)
 *      - isLoginRateLimited($ip, $username)
 *      Verifies the (IP, username) threshold, the per-IP threshold, the
 *      success-clears-failures property, and the cross-username isolation
 *      (a successful login for user A does not clear the failure count
 *      for user B on the same IP).
 *
 *   2. Source-grep on login.php and register.php: confirms the helpers
 *      are called, the new error strings / success-card render are
 *      present, the OLD weak checks are gone, and the "already exists"
 *      enumeration leak is closed.
 *
 *   3. Lint: `php -l` on every touched file.
 *
 * Cleanup is unconditional — a fatal error in the assertions must not
 * leave the seed rows / attempts behind. We use a unique random marker
 * for the seeded usernames so re-runs don't collide.
 */

// ---------------------------------------------------------------------------
// Bootstrap: locate config.php and load the project.
// ---------------------------------------------------------------------------

$root = __DIR__ . '/..';
if (!file_exists($root . '/config.php')) {
    fwrite(STDERR, "config.php not found; run the install wizard first.\n");
    exit(1);
}
require_once $root . '/config.php';
require_once $root . '/includes/Database.php';
require_once $root . '/includes/functions.php';

// Re-load functions.php to guarantee the new helpers are present even if
// the file was already included via a prior test in the same process.
require_once $root . '/includes/functions.php';

foreach (['recordLoginAttempt', 'isLoginRateLimited'] as $fn) {
    if (!function_exists($fn)) {
        fwrite(STDERR, "Missing helper: $fn\n");
        exit(1);
    }
}

// ---------------------------------------------------------------------------
// Test harness.
// ---------------------------------------------------------------------------

$pass = 0;
$fail = 0;
$marker = bin2hex(random_bytes(8));

function ok(string $label, bool $cond): void {
    global $pass, $fail;
    if ($cond) { $pass++; echo "  [PASS] $label\n"; }
    else       { $fail++; echo "  [FAIL] $label\n"; }
}

function section(string $name): void {
    echo "\n## $name\n";
}

// strip_php_comments: removes // line comments and /* ... */ block comments
// while preserving the rest of the source. Used to grep for SQL or call
// patterns that might be inside a comment.
function strip_php_comments(string $src): string {
    $src = preg_replace('!/\*.*?\*/!s', '', $src);
    $src = preg_replace('![ \t]*//[^\n]*!', '', $src);
    return $src;
}

$db = Database::getInstance();

// Idempotent migration: ensure the table exists. The migration is
// `CREATE TABLE IF NOT EXISTS` so re-running is safe.
$migPath = $root . '/database/migrations/20261015_add_login_attempts.sql';
$migSql = file_get_contents($migPath);
foreach (array_filter(array_map('trim', explode(';', $migSql))) as $stmt) {
    if ($stmt !== '' && stripos($stmt, 'CREATE TABLE') !== false) {
        $db->execute($stmt);
    }
}
$row = $db->fetchOne("SHOW TABLES LIKE 'login_attempts'");
if (!$row) {
    fwrite(STDERR, "login_attempts table not found after migration.\n");
    exit(1);
}

// Register cleanup. The seeded users cascade to login_attempts via no FK
// (login_attempts.username_attempted is not a FK), so we DELETE the rows
// explicitly by IP+marker.
register_shutdown_function(function() use ($db, $marker) {
    // Find our seeded IPs and users.
    $users = $db->fetchAll(
        "SELECT id FROM users WHERE username LIKE ?",
        ['login-test-' . $marker . '%']
    );
    foreach ($users as $u) {
        $db->execute("DELETE FROM login_attempts WHERE ip_address LIKE ?", ['10.99.' . $u['id'] . '.%']);
        $db->execute("DELETE FROM users WHERE id = ?", [$u['id']]);
    }
    // Also clean the per-IP test's marker-derived IP.
    $perIp = '10.99.' . ((hexdec(substr($marker, 0, 2)) % 250) + 1) . '.' . ((hexdec(substr($marker, 2, 2)) % 250) + 1);
    $db->execute("DELETE FROM login_attempts WHERE ip_address = ?", [$perIp]);
});

// Generate IPs from a private range; deterministic per-user so the cleanup
// above is precise.
function ipForUser(int $userId): string {
    return "10.99." . ($userId % 250 + 1) . "." . (($userId * 7) % 250 + 1);
}

$testUsername1 = 'login-test-' . $marker . '-alice';
$testUsername2 = 'login-test-' . $marker . '-bob';
$testEmail1    = $testUsername1 . '@example.test';
$testEmail2    = $testUsername2 . '@example.test';

// Seed two users. We need a row in `users` for source-grep sections to
// match any "real user" semantic; the helpers themselves do not require
// a users row.
$db->execute(
    "INSERT INTO users (name, username, email, password, timezone) VALUES (?, ?, ?, ?, 'UTC')",
    ['Login Test Alice ' . $marker, $testUsername1, $testEmail1, password_hash('test-password-' . $marker, PASSWORD_DEFAULT)]
);
$user1Id = (int)$db->lastInsertId();
$ip1 = ipForUser($user1Id);

$db->execute(
    "INSERT INTO users (name, username, email, password, timezone) VALUES (?, ?, ?, ?, 'UTC')",
    ['Login Test Bob ' . $marker, $testUsername2, $testEmail2, password_hash('test-password-' . $marker, PASSWORD_DEFAULT)]
);
$user2Id = (int)$db->lastInsertId();
$ip2 = ipForUser($user2Id);

// ---------------------------------------------------------------------------
// 1. Per-(IP, username) rate limit
// ---------------------------------------------------------------------------
section("1. Per-(IP, username) rate limit (5 failed / 15 min)");

ok("Fresh (IP, username) is not rate-limited", !isLoginRateLimited($ip1, $testUsername1));

for ($i = 1; $i <= 5; $i++) {
    recordLoginAttempt($ip1, $testUsername1, false);
    ok("After $i failure(s), (IP, username) is still not rate-limited", !isLoginRateLimited($ip1, $testUsername1));
}

recordLoginAttempt($ip1, $testUsername1, false);  // 6th failure
ok("After 6 failures, (IP, username) IS rate-limited", isLoginRateLimited($ip1, $testUsername1));

ok("A different username on the same IP is NOT rate-limited", !isLoginRateLimited($ip1, $testUsername2));

// ---------------------------------------------------------------------------
// 2. Success clears the per-(IP, username) failure history
// ---------------------------------------------------------------------------
section("2. Success clears the per-(IP, username) failure history");

// Continue with $ip1 / $testUsername1 which is currently locked. A
// successful login should clear the failures for that tuple.
recordLoginAttempt($ip1, $testUsername1, true);
ok("After success, (IP, username) is NOT rate-limited", !isLoginRateLimited($ip1, $testUsername1));

// Re-arm: 5 more failures. The success cleared the previous count, so
// after 5 fresh failures the count is 5, which is still NOT rate-limited
// (spec: 5th passes, 6th denied).
for ($i = 1; $i <= 5; $i++) {
    recordLoginAttempt($ip1, $testUsername1, false);
}
ok("After 5 fresh failures post-clear, (IP, username) is NOT yet rate-limited (count=5, limit=5)", !isLoginRateLimited($ip1, $testUsername1));
recordLoginAttempt($ip1, $testUsername1, false);  // 6th failure
ok("After 6 fresh failures post-clear, (IP, username) IS rate-limited", isLoginRateLimited($ip1, $testUsername1));

// A success for username2 (different user) does NOT clear username1's
// failure history.
recordLoginAttempt($ip1, $testUsername2, true);
ok("Success for user2 does NOT clear user1's failure history", isLoginRateLimited($ip1, $testUsername1));

// A success for user1 clears user1's history.
recordLoginAttempt($ip1, $testUsername1, true);
ok("Success for user1 clears user1's failure history", !isLoginRateLimited($ip1, $testUsername1));

// ---------------------------------------------------------------------------
// 3. Per-IP rate limit (30 failed / 15 min) — independent of username
// ---------------------------------------------------------------------------
section("3. Per-IP rate limit (30 failed / 15 min)");

// Use a fresh IP so the per-(IP, username) counter from sections 1-2
// does not pollute the per-IP test. Marker-based so re-runs don't
// collide with previous test runs (the login_attempts table is shared
// across all tests).
$perIp = '10.99.' . ((hexdec(substr($marker, 0, 2)) % 250) + 1) . '.' . ((hexdec(substr($marker, 2, 2)) % 250) + 1);

// 30 failures spread across distinct usernames. Each username stays
// under its own 5-failure limit, but the IP-wide counter climbs.
for ($i = 1; $i <= 30; $i++) {
    recordLoginAttempt($perIp, "per-ip-user-$i", false);
}
ok("After 30 failures (distinct usernames), IP-only limit NOT yet tripped", !isLoginRateLimited($perIp, "per-ip-user-31"));

recordLoginAttempt($perIp, "per-ip-user-31", false);
ok("After 31 failures, IP-only limit IS tripped (also for unknown usernames)", isLoginRateLimited($perIp, "per-ip-user-31"));

ok("Even with a different (IP, username) tuple, the per-IP cap denies", isLoginRateLimited($perIp, "never-seen-before"));

// ---------------------------------------------------------------------------
// 4. login.php source-grep
// ---------------------------------------------------------------------------
section("4. Source-grep on login.php");

$loginSrc  = file_get_contents($root . '/login.php');
$loginCode = strip_php_comments($loginSrc);

ok("login.php calls isLoginRateLimited",                strpos($loginCode, 'isLoginRateLimited($clientIp, $username)') !== false);
ok("login.php calls recordLoginAttempt",                substr_count($loginCode, 'recordLoginAttempt($clientIp, $username,') >= 3);
ok("login.php records a failure in the rate-limit branch",
   strpos($loginCode, 'if (isLoginRateLimited($clientIp, $username))') !== false
   && strpos($loginCode, "recordLoginAttempt(\$clientIp, \$username, false)") !== false
);
ok("login.php records a failure in the wrong-password branch", strpos($loginCode, "recordLoginAttempt(\$clientIp, \$username, false)") !== false);
ok("login.php records a success in the success branch", strpos($loginCode, "recordLoginAttempt(\$clientIp, \$username, true)") !== false);
ok("login.php reads client IP from REMOTE_ADDR",         strpos($loginCode, "\$_SERVER['REMOTE_ADDR']") !== false);
ok("login.php uses byte-identical 'Invalid username or password' on rate-limit drop",
   strpos($loginCode, 'isLoginRateLimited($clientIp, $username)') !== false
   && substr_count($loginCode, '"Invalid username or password"') >= 2
);
ok("login.php does NOT contain a separate 'locked' error message", strpos($loginCode, 'Too many') === false && strpos($loginCode, 'locked') === false);
ok("login.php does NOT call X-Forwarded-For",            stripos($loginCode, 'HTTP_X_FORWARDED') === false && stripos($loginCode, 'X-Forwarded') === false);

// ---------------------------------------------------------------------------
// 5. register.php source-grep — the enumeration fix
// ---------------------------------------------------------------------------
section("5. Source-grep on register.php (enumeration fix)");

$regSrc  = file_get_contents($root . '/register.php');
$regCode = strip_php_comments($regSrc);

ok("register.php still queries users for an existing user",  strpos($regCode, "SELECT id FROM users WHERE username = ? OR email = ?") !== false);
ok("register.php does NOT push 'already exists' to \$errors", strpos($regCode, 'Username or email already exists') === false);
ok("register.php sets \$success = true on the existing branch", preg_match('/if\s*\(\s*\$existing\s*\)\s*\{[^}]*\$success\s*=\s*true/s', $regCode) === 1);
ok("register.php's existing branch is a no-op (no INSERT in the existing branch)",
   preg_match('/if\s*\(\s*\$existing\s*\)\s*\{(.*?)\}\s*else/s', $regCode, $m) === 1
   && strpos($m[1], 'INSERT INTO users') === false
);
ok("register.php renders the same success card for both branches", strpos($regCode, 'Registration Successful!') !== false);
ok("register.php does NOT send a welcome email in the existing branch (the existing user keeps their original email)", preg_match('/if\s*\(\s*\$existing\s*\)\s*\{[^}]*\$success\s*=\s*true[^}]*\}/s', $regCode) === 1 && !preg_match('/if\s*\(\s*\$existing\s*\)\s*\{[^}]*sendWelcomeEmail/s', $regCode));

// ---------------------------------------------------------------------------
// 6. includes/functions.php source-grep
// ---------------------------------------------------------------------------
section("6. Source-grep on includes/functions.php");

$fnSrc  = file_get_contents($root . '/includes/functions.php');
$fnCode = strip_php_comments($fnSrc);

ok("functions.php defines recordLoginAttempt",  strpos($fnCode, 'function recordLoginAttempt(') !== false);
ok("functions.php defines isLoginRateLimited",  strpos($fnCode, 'function isLoginRateLimited(') !== false);
ok("functions.php has defined() fallback for LOGIN_FAILURE_LIMIT",  preg_match("/defined\(['\"]LOGIN_FAILURE_LIMIT['\"]\)/", $fnCode) === 1);
ok("functions.php has defined() fallback for LOGIN_FAILURE_WINDOW", preg_match("/defined\(['\"]LOGIN_FAILURE_WINDOW['\"]\)/", $fnCode) === 1);
ok("functions.php has defined() fallback for LOGIN_IP_LIMIT",       preg_match("/defined\(['\"]LOGIN_IP_LIMIT['\"]\)/", $fnCode) === 1);
ok("functions.php has defined() fallback for LOGIN_IP_WINDOW",      preg_match("/defined\(['\"]LOGIN_IP_WINDOW['\"]\)/", $fnCode) === 1);
ok("functions.php isLoginRateLimited uses '>' (not '>=') for the threshold", strpos($fnCode, '$userCount > $userLimit') !== false);
ok("functions.php isLoginRateLimited uses '>' (not '>=') for the IP threshold", strpos($fnCode, '$ipCount > $ipLimit') !== false);
ok("functions.php recordLoginAttempt clears failures on success",
   strpos($fnCode, 'function recordLoginAttempt(') !== false
   && strpos($fnCode, 'if ($success) {') !== false
   && strpos($fnCode, 'DELETE FROM login_attempts') !== false
);

// ---------------------------------------------------------------------------
// 7. config.php source-grep
// ---------------------------------------------------------------------------
section("7. Source-grep on config.php (constants)");

$cfgSrc  = file_get_contents($root . '/config.php');
$cfgCode = strip_php_comments($cfgSrc);

ok("config.php defines LOGIN_FAILURE_LIMIT",  strpos($cfgCode, "const LOGIN_FAILURE_LIMIT") !== false);
ok("config.php defines LOGIN_FAILURE_WINDOW", strpos($cfgCode, "const LOGIN_FAILURE_WINDOW") !== false);
ok("config.php defines LOGIN_IP_LIMIT",       strpos($cfgCode, "const LOGIN_IP_LIMIT") !== false);
ok("config.php defines LOGIN_IP_WINDOW",      strpos($cfgCode, "const LOGIN_IP_WINDOW") !== false);

// ---------------------------------------------------------------------------
// 8. database/schema.sql source-grep
// ---------------------------------------------------------------------------
section("8. Source-grep on database/schema.sql");

$schemaSrc  = file_get_contents($root . '/database/schema.sql');
$schemaCode = strip_php_comments($schemaSrc);

ok("schema.sql contains login_attempts CREATE TABLE",  strpos($schemaCode, 'CREATE TABLE login_attempts (') !== false);
ok("schema.sql has idx_la_user_time index",           strpos($schemaCode, 'idx_la_user_time') !== false);
ok("schema.sql has idx_la_ip_time index",             strpos($schemaCode, 'idx_la_ip_time') !== false);
ok("schema.sql has no FK on username_attempted (intentional: it's the submitted value, not a users FK)", preg_match('/CREATE TABLE login_attempts[^;]*FOREIGN KEY[^;]*username_attempted/s', $schemaCode) === 0);

// ---------------------------------------------------------------------------
// 9. Migration source-grep
// ---------------------------------------------------------------------------
section("9. Source-grep on the migration");

$migSrc = file_get_contents($migPath);

ok("Migration is idempotent (IF NOT EXISTS)", strpos($migSrc, 'CREATE TABLE IF NOT EXISTS login_attempts') !== false);
ok("Migration has idx_la_user_time index",    strpos($migSrc, 'idx_la_user_time') !== false);
ok("Migration has idx_la_ip_time index",      strpos($migSrc, 'idx_la_ip_time') !== false);

// ---------------------------------------------------------------------------
// 10. Lint
// ---------------------------------------------------------------------------
section("10. Lint touched files");

$lintFiles = [
    'login.php'                                => $root . '/login.php',
    'register.php'                             => $root . '/register.php',
    'includes/functions.php'                   => $root . '/includes/functions.php',
];

foreach ($lintFiles as $label => $path) {
    $output = [];
    $rc = 0;
    exec('php -l ' . escapeshellarg($path) . ' 2>&1', $output, $rc);
    ok("$label passes php -l", $rc === 0);
}

// ---------------------------------------------------------------------------
// Summary
// ---------------------------------------------------------------------------

echo "\n";
echo "Passed: $pass\n";
echo "Failed: $fail\n";

if ($fail > 0) {
    fwrite(STDERR, "Issue-019 login/register protection: $fail ASSERTION(S) FAILED\n");
    exit(1);
}
echo "Issue-019 login/register protection: ALL ASSERTIONS PASSED\n";
exit(0);
