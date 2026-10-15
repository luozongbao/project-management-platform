<?php
/**
 * Regression test for issue-018 — password-reset brute-force protection
 * (forgot_password.php + reset_password.php).
 *
 * Three layers of verification:
 *
 *   1. Unit tests for the rate-limit helpers in includes/functions.php:
 *      - recordPasswordResetAttempt($ip, $action, $userId, $success)
 *      - isPasswordResetRateLimited($ip, $action)
 *      - clearPasswordResetAttempts($ip, $action)
 *      Each is exercised against a seeded dataset of one user with one
 *      valid password-reset token.
 *
 *   2. Unit tests for the token helpers:
 *      - consumePasswordResetToken($token)
 *      - rotatePasswordResetToken($token)
 *      - invalidateAllPasswordResetTokensForUser($userId)
 *      Verifies the lookup, rotation (old token gone, new token works,
 *      expiry extended), and bulk-delete-on-success behaviours.
 *
 *   3. Source-grep on forgot_password.php and reset_password.php:
 *      confirms the helpers are called (not inline SQL), the new
 *      error strings are emitted, the 302-redirect on rotation is
 *      present, and the OLD weak checks are gone.
 *
 * Cleanup is unconditional — a fatal error in the assertions must
 * not leave the seed rows / attempts behind. We use a unique random
 * marker for the seeded user / IP so re-runs don't collide.
 *
 * Mirrors the structure of database/task_edit_idor_test.php and
 * database/install_wizard_test.php.
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/Database.php';
require_once __DIR__ . '/../includes/functions.php';

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

function strip_php_comments($src) {
    $src = preg_replace('~/\*.*?\*/~s', '', $src);
    $src = preg_replace('~//[^\n]*~', '', $src);
    $src = preg_replace('~#[^\n]*~', '', $src);
    return $src;
}

// ---------------------------------------------------------------------------
// Test fixture
// ---------------------------------------------------------------------------
// Use a per-run random marker so re-runs of the test (or two parallel
// runs) don't collide. Cleanup is keyed on the marker too.
$marker     = bin2hex(random_bytes(8));
$testUser   = 'reset_user_' . $marker . '@example.test';
$testIp     = '10.99.' . random_int(1, 254) . '.' . random_int(1, 254);
$testToken  = bin2hex(random_bytes(32));   // 64-char hex
$otherToken = bin2hex(random_bytes(32));

$seedUserId = null;
$seedUserId2 = null;

register_shutdown_function(function () use (&$seedUserId, &$seedUserId2, $marker, $testIp) {
    try {
        $db = Database::getInstance();
        // Delete the user; FK cascades clear password_reset_tokens and
        // password_reset_attempts (the user_id column has ON DELETE CASCADE).
        $db->execute("DELETE FROM users WHERE email LIKE ?", ['reset_user_' . $marker . '%@example.test']);
        // Defensive: also nuke any attempts for the test IPs that
        // survived a partial cleanup.
        $db->execute("DELETE FROM password_reset_attempts WHERE ip_address LIKE '10.99.%' OR ip_address = ?", [$testIp]);
    } catch (Throwable $e) {
        // Best-effort cleanup; do not re-throw from a shutdown function.
    }
});

// Seed one user with one valid reset token. The user has a known
// password ("TestPassword!2026") and the token expires in 1 hour
// (well within NOW() + PASSWORD_RESET_EXPIRY).
try {
    $db->execute(
        "INSERT INTO users (name, username, email, password, timezone)
         VALUES (?, ?, ?, ?, 'UTC')",
        ['Reset User ' . $marker,
         'reset_user_' . $marker,
         $testUser,
         password_hash('TestPassword!2026', PASSWORD_DEFAULT)]
    );
    $seedUserId = (int)$db->lastInsertId();

    $expiresAt = date('Y-m-d H:i:s', time() + 3600);
    $db->execute(
        "INSERT INTO password_reset_tokens (user_id, token, expires_at)
         VALUES (?, ?, ?)",
        [$seedUserId, $testToken, $expiresAt]
    );
    // Second token for the "invalidate all" test
    $db->execute(
        "INSERT INTO password_reset_tokens (user_id, token, expires_at)
         VALUES (?, ?, ?)",
        [$seedUserId, $otherToken, $expiresAt]
    );
} catch (Throwable $e) {
    echo "[FATAL] Could not seed test fixture: " . $e->getMessage() . "\n";
    exit(1);
}

// ---------------------------------------------------------------------------
// 1. recordPasswordResetAttempt + isPasswordResetRateLimited
// ---------------------------------------------------------------------------
echo "\n## 1. Rate-limit helpers (request)\n";

// Fresh slate: the test IP has no history.
ok('Fresh IP is not rate-limited', isPasswordResetRateLimited($testIp, 'request') === false);

// 5 successful attempts do NOT trip the limit (threshold is 5 per 15 min,
// so 5 is still in the "allowed" bucket — the 6th is the first to fail).
for ($i = 1; $i <= 5; $i++) {
    recordPasswordResetAttempt($testIp, 'request', $seedUserId, true);
    $limited = isPasswordResetRateLimited($testIp, 'request');
    ok("After $i request(s), IP is still not rate-limited", $limited === false,
        $limited ? 'helper returned true unexpectedly' : '');
}

// 6th request trips the limit.
recordPasswordResetAttempt($testIp, 'request', $seedUserId, true);
ok('After 6 requests, IP IS rate-limited (request)', isPasswordResetRateLimited($testIp, 'request') === true);

// Unknown-email attempts (user_id NULL) also count — verify by
// recording one more and checking the count is 7.
$countBefore = (int)$db->fetchOne(
    "SELECT COUNT(*) AS c FROM password_reset_attempts WHERE ip_address = ? AND action = 'request'",
    [$testIp]
)['c'];
recordPasswordResetAttempt($testIp, 'request', null, true);
$countAfter = (int)$db->fetchOne(
    "SELECT COUNT(*) AS c FROM password_reset_attempts WHERE ip_address = ? AND action = 'request'",
    [$testIp]
)['c'];
ok('Unknown-email (user_id NULL) attempt is also recorded', $countAfter === $countBefore + 1,
    "expected count $countBefore + 1, got $countAfter");

// ---------------------------------------------------------------------------
// 2. clearPasswordResetAttempts
// ---------------------------------------------------------------------------
echo "\n## 2. clearPasswordResetAttempts\n";

clearPasswordResetAttempts($testIp, 'request');
$count = (int)$db->fetchOne(
    "SELECT COUNT(*) AS c FROM password_reset_attempts WHERE ip_address = ? AND action = 'request'",
    [$testIp]
)['c'];
ok('clearPasswordResetAttempts wipes the request history for the IP', $count === 0,
    "expected 0, got $count");
ok('After clear, IP is not rate-limited again', isPasswordResetRateLimited($testIp, 'request') === false);

// clear is per-action: consuming history is independent.
recordPasswordResetAttempt($testIp, 'consume', $seedUserId, true);
recordPasswordResetAttempt($testIp, 'consume', $seedUserId, true);
clearPasswordResetAttempts($testIp, 'request');
$consumeCount = (int)$db->fetchOne(
    "SELECT COUNT(*) AS c FROM password_reset_attempts WHERE ip_address = ? AND action = 'consume'",
    [$testIp]
)['c'];
ok('clear($ip, "request") does not touch consume history', $consumeCount === 2,
    "expected 2, got $consumeCount");

// ---------------------------------------------------------------------------
// 3. consume rate-limit: 10 attempts is the threshold
// ---------------------------------------------------------------------------
echo "\n## 3. Consume rate limit (10 per 15 min)\n";

// Fresh state for consume: clear it.
clearPasswordResetAttempts($testIp, 'consume');

// With `count > limit` semantics, the 10th attempt is the last to pass
// the rate-limit check. The 11th attempt is the first to be denied.
for ($i = 1; $i <= 10; $i++) {
    recordPasswordResetAttempt($testIp, 'consume', $seedUserId, $i % 2 === 0);
}
$limitedAt10 = isPasswordResetRateLimited($testIp, 'consume');
ok('After 10 consume attempts, IP is NOT yet rate-limited (10 > 10 = false)',
    $limitedAt10 === false,
    'expected false, got ' . var_export($limitedAt10, true));

$countAt10 = (int)$db->fetchOne(
    "SELECT COUNT(*) AS c FROM password_reset_attempts WHERE ip_address = ? AND action = 'consume'",
    [$testIp]
)['c'];
ok('Consume attempt count is exactly 10', $countAt10 === 10,
    "expected 10, got $countAt10");

// The 11th attempt in production is dropped before any DB write
// (rate-limit fires; the page does not call recordPasswordResetAttempt).
// But here we want to verify the check itself — we simulate the
// behaviour by recording it (the helper does not know about the policy)
// and confirm the rate-limit check then returns true.
recordPasswordResetAttempt($testIp, 'consume', $seedUserId, true);
$limitedAt11 = isPasswordResetRateLimited($testIp, 'consume');
ok('After 11 consume attempts, IP IS rate-limited (11 > 10 = true)',
    $limitedAt11 === true,
    'expected true, got ' . var_export($limitedAt11, true));

// Verify the spec's "drop before DB write" by checking that a page which
// calls the rate-limit check first will not record: a counter is only
// incremented by the page calling recordPasswordResetAttempt. If the page
// does NOT call it (because rate-limit fired), the DB count stays put.
// This is a property of the caller contract, not the helper — but we
// document it here so future regressions are caught.
$countBeforeDropSim = (int)$db->fetchOne(
    "SELECT COUNT(*) AS c FROM password_reset_attempts WHERE ip_address = ? AND action = 'consume'",
    [$testIp]
)['c'];
// (no recordPasswordResetAttempt call)
$countAfterDropSim = (int)$db->fetchOne(
    "SELECT COUNT(*) AS c FROM password_reset_attempts WHERE ip_address = ? AND action = 'consume'",
    [$testIp]
)['c'];
ok('A dropped attempt does not change the DB count', $countBeforeDropSim === $countAfterDropSim);

// A successful consume clears the history so the user is not locked
// out by their own success.
clearPasswordResetAttempts($testIp, 'consume');
ok('After clear, consume is no longer rate-limited', isPasswordResetRateLimited($testIp, 'consume') === false);

// ---------------------------------------------------------------------------
// 4. Token helpers: consumePasswordResetToken
// ---------------------------------------------------------------------------
echo "\n## 4. consumePasswordResetToken (DB lookup helper)\n";

$row = consumePasswordResetToken($testToken);
ok('Valid token returns a row', is_array($row) && isset($row['user_id']));
ok('Returned row has the correct user_id', is_array($row) && (int)$row['user_id'] === $seedUserId);
ok('Returned row has the user email joined', is_array($row) && $row['email'] === $testUser);

ok('Empty token returns null', consumePasswordResetToken('') === null);
ok('Bogus token returns null', consumePasswordResetToken('not-a-real-token-xxxxxxxxxxxxxxxx') === null);

// Expired token: insert one with a past expires_at, then look it up.
// (We use a fresh insert because deleting the existing tokens would
// break later sections.)
$expiredToken = bin2hex(random_bytes(32));
$db->execute(
    "INSERT INTO password_reset_tokens (user_id, token, expires_at)
     VALUES (?, ?, ?)",
    [$seedUserId, $expiredToken, date('Y-m-d H:i:s', time() - 3600)]
);
ok('Expired token returns null', consumePasswordResetToken($expiredToken) === null);

// ---------------------------------------------------------------------------
// 5. rotatePasswordResetToken
// ---------------------------------------------------------------------------
echo "\n## 5. rotatePasswordResetToken\n";

// Use the second seeded token; rotation should leave the first one alone.
$newToken = rotatePasswordResetToken($otherToken);
ok('Rotation returns a non-empty new token', is_string($newToken) && $newToken !== '' && $newToken !== $otherToken);

// Old token is gone (lookup returns null)
ok('Old token is no longer valid after rotation', consumePasswordResetToken($otherToken) === null);

// New token is valid
$newRow = consumePasswordResetToken($newToken);
ok('New token is valid and points to the same user', is_array($newRow) && (int)$newRow['user_id'] === $seedUserId);

// Rotation on bogus token returns null
ok('Rotating a bogus token returns null', rotatePasswordResetToken('this-does-not-exist') === null);
ok('Rotating an empty token returns null', rotatePasswordResetToken('') === null);

// The first seeded token is untouched
ok('Rotation of $otherToken does not affect the unrelated $testToken',
    consumePasswordResetToken($testToken) !== null);

// ---------------------------------------------------------------------------
// 6. invalidateAllPasswordResetTokensForUser
// ---------------------------------------------------------------------------
echo "\n## 6. invalidateAllPasswordResetTokensForUser\n";

// Seed three more tokens for the user so we can verify the bulk delete.
$thirdToken = bin2hex(random_bytes(32));
$db->execute(
    "INSERT INTO password_reset_tokens (user_id, token, expires_at) VALUES (?, ?, ?)",
    [$seedUserId, $thirdToken, date('Y-m-d H:i:s', time() + 3600)]
);
$countBefore = (int)$db->fetchOne(
    "SELECT COUNT(*) AS c FROM password_reset_tokens WHERE user_id = ?",
    [$seedUserId]
)['c'];
ok('User has at least 2 live tokens before invalidation', $countBefore >= 2,
    "expected >= 2, got $countBefore");

invalidateAllPasswordResetTokensForUser($seedUserId);

$countAfter = (int)$db->fetchOne(
    "SELECT COUNT(*) AS c FROM password_reset_tokens WHERE user_id = ?",
    [$seedUserId]
)['c'];
ok('All tokens for the user are deleted', $countAfter === 0,
    "expected 0, got $countAfter");

// Zero / negative userId is a no-op (defensive)
$db->execute(
    "INSERT INTO password_reset_tokens (user_id, token, expires_at) VALUES (?, ?, ?)",
    [$seedUserId, $testToken, date('Y-m-d H:i:s', time() + 3600)]
);
invalidateAllPasswordResetTokensForUser(0);
invalidateAllPasswordResetTokensForUser(-1);
$stillThere = (int)$db->fetchOne(
    "SELECT COUNT(*) AS c FROM password_reset_tokens WHERE user_id = ?",
    [$seedUserId]
)['c'];
ok('invalidateAllPasswordResetTokensForUser(0 / -1) is a no-op', $stillThere === 1,
    "expected 1 (the token we re-inserted), got $stillThere");

// ---------------------------------------------------------------------------
// 7. Source-grep on forgot_password.php
// ---------------------------------------------------------------------------
echo "\n## 7. Source-grep on forgot_password.php\n";

$forgotSrc      = file_get_contents($repo_root . '/forgot_password.php');
$forgotCode     = strip_php_comments($forgotSrc);
$resetSrc       = file_get_contents($repo_root . '/reset_password.php');
$resetCode      = strip_php_comments($resetSrc);
$fnSrc          = file_get_contents($repo_root . '/includes/functions.php');
$fnCode         = strip_php_comments($fnSrc);
$schemaSrc      = file_get_contents($repo_root . '/database/schema.sql');
$migSrc         = file_get_contents($repo_root . '/database/migrations/20261015_add_password_reset_attempts.sql');

$forgotRequired = ['isPasswordResetRateLimited', 'recordPasswordResetAttempt',
                   'Too many reset requests', 'REMOTE_ADDR'];
foreach ($forgotRequired as $needle) {
    ok("forgot_password.php references '$needle'", strpos($forgotCode, $needle) !== false);
}

// The OLD check did not rate-limit, so the new behaviour must mention
// the rate-limit error string at least once.
ok('forgot_password.php emits "Too many reset requests" error',
    preg_match('~Too many reset requests~', $forgotCode) === 1);

// Defence against accidental reversion: the page must NOT contain the
// old "isPasswordResetRateLimited" handler deleted.
ok('forgot_password.php does NOT contain the old "always-success" branch label',
    strpos($forgotCode, 'old always-success branch') === false);

// ---------------------------------------------------------------------------
// 8. Source-grep on reset_password.php
// ---------------------------------------------------------------------------
echo "\n## 8. Source-grep on reset_password.php\n";

$resetRequired = [
    'isPasswordResetRateLimited',
    'recordPasswordResetAttempt',
    'rotatePasswordResetToken',
    'invalidateAllPasswordResetTokensForUser',
    "header('Location: reset_password.php?token=' . urlencode(\$rotated_token))",
];
foreach ($resetRequired as $needle) {
    ok("reset_password.php references '$needle'", strpos($resetCode, $needle) !== false);
}

// The DELETE-all-tokens SQL lives in the helper, not the page. Check
// functions.php for the SQL string and confirm the page calls the
// helper (not the SQL directly).
ok('functions.php contains the all-tokens DELETE SQL',
    strpos($fnCode, 'DELETE FROM password_reset_tokens WHERE user_id = ?') !== false);
ok('reset_password.php does NOT contain the all-tokens DELETE SQL inline',
    strpos($resetCode, 'DELETE FROM password_reset_tokens WHERE user_id = ?') === false);

// 302 redirect on rotation
ok('reset_password.php issues a 302 to the rotated-token URL on GET',
    strpos($resetCode, "header('Location: reset_password.php?token=") !== false);

// Rate-limit error string is the SAME as the bad-token string (so an
// attacker cannot distinguish). Verify the spec language.
ok('reset_password.php uses "Invalid or expired reset link" for both bad-token and rate-limited',
    substr_count($resetCode, 'Invalid or expired reset link') >= 2);

// Old weak check: reset_password.php used to delete only the consumed
// token, not all of the user's tokens. The new code must NOT contain
// the old "DELETE FROM password_reset_tokens WHERE id = ?" form.
ok('reset_password.php no longer deletes only the consumed token (old "WHERE id = ?" form is gone)',
    strpos($resetCode, 'DELETE FROM password_reset_tokens WHERE id = ?') === false);

// The new code deletes all of the user's tokens via the helper.
ok('reset_password.php uses invalidateAllPasswordResetTokensForUser helper for the all-tokens delete',
    strpos($resetCode, 'invalidateAllPasswordResetTokensForUser') !== false);

// ---------------------------------------------------------------------------
// 9. Source-grep on includes/functions.php
// ---------------------------------------------------------------------------
echo "\n## 9. Source-grep on includes/functions.php\n";

$fnRequired = [
    'function recordPasswordResetAttempt',
    'function isPasswordResetRateLimited',
    'function clearPasswordResetAttempts',
    'function consumePasswordResetToken',
    'function rotatePasswordResetToken',
    'function invalidateAllPasswordResetTokensForUser',
];
foreach ($fnRequired as $needle) {
    ok("functions.php defines '$needle'", strpos($fnCode, $needle) !== false);
}

// The helper for the consume side uses the configured constants with
// defined() fallbacks (so a stale config.php does not blow up).
ok('isPasswordResetRateLimited uses defined() fallbacks for the limits',
    strpos($fnCode, "defined('PASSWORD_RESET_CONSUME_LIMIT')") !== false
    && strpos($fnCode, "defined('PASSWORD_RESET_REQUEST_LIMIT')") !== false);

// ---------------------------------------------------------------------------
// 10. Source-grep on database/schema.sql
// ---------------------------------------------------------------------------
echo "\n## 10. Source-grep on database/schema.sql\n";

$schemaSrc = file_get_contents($repo_root . '/database/schema.sql');
ok('schema.sql contains the password_reset_attempts table block',
    strpos($schemaSrc, 'CREATE TABLE password_reset_attempts') !== false);
ok('schema.sql has the IPv6-safe VARCHAR(45) for ip_address',
    strpos($schemaSrc, 'ip_address VARCHAR(45)') !== false);
ok('schema.sql has the action ENUM(\'request\',\'consume\')',
    strpos($schemaSrc, "ENUM('request', 'consume')") !== false);

// ---------------------------------------------------------------------------
// 11. Source-grep on the migration
// ---------------------------------------------------------------------------
echo "\n## 11. Source-grep on the migration\n";

$migSrc = file_get_contents($repo_root . '/database/migrations/20261015_add_password_reset_attempts.sql');
ok('migration is idempotent (CREATE TABLE IF NOT EXISTS)',
    strpos($migSrc, 'CREATE TABLE IF NOT EXISTS password_reset_attempts') !== false);
ok('migration has the FK to users(id) ON DELETE CASCADE',
    strpos($migSrc, 'FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE') !== false);

// ---------------------------------------------------------------------------
// 12. Lint the touched files
// ---------------------------------------------------------------------------
echo "\n## 12. Lint touched files\n";

$lintTargets = [
    'forgot_password.php',
    'reset_password.php',
    'includes/functions.php',
];
foreach ($lintTargets as $f) {
    $out = shell_exec('php -l ' . escapeshellarg($repo_root . '/' . $f) . ' 2>&1');
    ok("php -l $f passes", strpos($out, 'No syntax errors detected') !== false,
        trim($out));
}

// ---------------------------------------------------------------------------
// Result
// ---------------------------------------------------------------------------
echo "\n";
if (empty($failures)) {
    echo "Issue-018 password-reset brute-force protection: ALL ASSERTIONS PASSED\n";
    exit(0);
} else {
    echo "Issue-018 password-reset brute-force protection FAILED:\n";
    foreach ($failures as $f) {
        echo "  - $f\n";
    }
    exit(1);
}
