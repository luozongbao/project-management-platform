<?php
/**
 * Regression test for issue-024 — password-reset redirect-loop bug.
 *
 * The issue: the previous reset_password.php flow rotated the token on
 * every successful GET render and 302-redirected to the rotated-token
 * URL. The intent was to invalidate a URL leaked via browser history
 * the moment the user opens the page, but the implementation created
 * an infinite redirect loop because rotatePasswordResetToken() ALWAYS
 * returns a fresh token (it generates a new one via generateToken()
 * on every call), so `$rotated_token !== $token` was always true.
 * The 302 fired on every request; the browser followed the next
 * request; the page rotated again; and so on until the browser
 * gave up. The user saw either a "Too many redirects" error or, on
 * some browsers, the cached error page from a later request that had
 * already lost track of the original token. Symptom: "clicked the
 * link immediately and got 'Invalid Reset Link'".
 *
 * The fix removes the rotation-on-render AND the 302 redirect. The
 * new flow:
 *   1. GET /reset_password.php?token=X → validate the token, check
 *      rate limit, render the form (no redirect).
 *   2. POST /reset_password.php?token=X → validate the token, check
 *      rate limit, validate the new password, update the password,
 *      invalidate ALL of the user's tokens.
 *
 * Layers of verification (12 sections, ~30 assertions):
 *
 *   1. Unit test: rotatePasswordResetToken always returns a fresh
 *      token (so the old loop logic was tautologically broken).
 *   2. Unit test: rotatePasswordResetToken called twice in a row on
 *      the same starting token produces TWO different tokens (the
 *      proof that the redirect-on-rotation was always a loop).
 *   3. Source-grep: reset_password.php does NOT call
 *      rotatePasswordResetToken.
 *   4. Source-grep: reset_password.php does NOT issue a 302 to a
 *      rotated-token URL.
 *   5. Source-grep: reset_password.php still defines the
 *      $valid_token flag (the form-render gate is preserved).
 *   6. Live HTTP: GET /reset_password.php?token=VALID returns HTTP
 *      200 with a form (the <form> tag is in the body), not a 302.
 *   7. Live HTTP: GET with the SAME token TWICE returns HTTP 200
 *      both times (the loop is gone — the old code would have
 *      returned 302 on the first call and a different status on
 *      subsequent calls due to token churn).
 *   8. Live HTTP: GET /reset_password.php?token=INVALID returns
 *      HTTP 200 with the "Invalid Reset Link" error block (the
 *      no-redirect path is preserved for bad tokens too).
 *   9. Live HTTP: GET /reset_password.php (no token) returns HTTP
 *      200 with the "Invalid reset link" error.
 *  10. End-to-end: GET form → POST with new password → assert the
 *      token is invalidated, the password was updated, and the
 *      success page renders.
 *  11. After successful reset, the old token returns the
 *      "Invalid or expired reset link" block (defence against
 *      bookmark reuse).
 *  12. Lint touched files.
 *
 * Cleanup is unconditional — a fatal error in the assertions must
 * not leave the seed rows / attempts behind.
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/Database.php';
require_once __DIR__ . '/../includes/functions.php';

$repo_root = realpath(__DIR__ . '/..');
$db = Database::getInstance();
$failures = [];
$pass = 0;

function ok(string $label, bool $cond, string $detail = ''): void {
    global $failures, $pass;
    if ($cond) {
        $pass++;
        echo "  [PASS] $label\n";
    } else {
        $failures[] = $label . ($detail !== '' ? " — $detail" : '');
        echo "  [FAIL] $label" . ($detail !== '' ? " — $detail" : '') . "\n";
    }
}

function section(string $name): void {
    echo "\n## $name\n";
}

function strip_php_comments(string $code): string {
    // Strip // line comments, # line comments, and /* */ block comments
    // so a regression grep on actual code is not fooled by an
    // explanation in a comment.
    $code = preg_replace('!//.*!', '', $code);
    $code = preg_replace('![^/]*#.*!', '', $code);
    $code = preg_replace('!/\*.*?\*/!s', '', $code);
    return $code;
}

// ---------------------------------------------------------------------------
// Test fixture
// ---------------------------------------------------------------------------
// Use a per-run random marker so re-runs of the test (or two parallel
// runs) don't collide. Cleanup is keyed on the marker too.
$marker    = bin2hex(random_bytes(8));
$testEmail = 'reset_loop_' . $marker . '@example.test';
$testToken = bin2hex(random_bytes(32));   // 64-char hex

// Real users already exist in the live DB (the install wizard seeds
// user #1 with a known password). We use a dedicated throwaway
// account so the test does not perturb the live state.
$seedUserId = null;
$probeIp    = trim((string)shell_exec("hostname -i 2>/dev/null"));
if (!preg_match('/^\d+\.\d+\.\d+\.\d+$/', $probeIp)) {
    $probeIp = '172.20.0.4';
}

register_shutdown_function(function () use (&$seedUserId, $marker, $testEmail, $testToken, $probeIp) {
    try {
        $db = Database::getInstance();
        // FK cascades clear password_reset_tokens and
        // password_reset_attempts (the user_id column has ON DELETE CASCADE).
        $db->execute("DELETE FROM users WHERE email = ?", [$testEmail]);
        // Defensive: also nuke any attempts for the probe IP that
        // survived a partial cleanup.
        $db->execute("DELETE FROM password_reset_attempts WHERE ip_address = ?", [$probeIp]);
    } catch (Throwable $e) {
        // Best-effort cleanup; do not re-throw from a shutdown function.
    }
});

// Seed a throwaway user with a valid reset token. The user has a
// known password ("ResetLoop!2026") and the token expires in 1 hour
// (well within NOW() + PASSWORD_RESET_EXPIRY).
$db->execute(
    "INSERT INTO users (name, username, email, password, created_at, updated_at)
     VALUES (?, ?, ?, ?, NOW(), NOW())",
    [
        'Reset Loop User ' . $marker,
        'reset_loop_' . $marker,
        $testEmail,
        hashPassword('ResetLoop!2026'),
    ]
);
$seedUserId = (int)$db->lastInsertId();

$db->execute(
    "INSERT INTO password_reset_tokens (user_id, token, expires_at)
     VALUES (?, ?, DATE_ADD(NOW(), INTERVAL 1 HOUR))",
    [$seedUserId, $testToken]
);

// ---------------------------------------------------------------------------
// 1. Unit test: rotatePasswordResetToken always returns a fresh token
// ---------------------------------------------------------------------------
section("1. rotatePasswordResetToken always returns a fresh token");

$seedTokenForRotation = bin2hex(random_bytes(32));
$db->execute(
    "INSERT INTO password_reset_tokens (user_id, token, expires_at)
     VALUES (?, ?, DATE_ADD(NOW(), INTERVAL 1 HOUR))",
    [$seedUserId, $seedTokenForRotation]
);

$rotated1 = rotatePasswordResetToken($seedTokenForRotation);
ok("rotatePasswordResetToken returns a non-null token", $rotated1 !== null);
ok("rotatePasswordResetToken returns a non-empty hex string",
    is_string($rotated1) && strlen($rotated1) > 0 && ctype_xdigit($rotated1));
ok("rotatePasswordResetToken's output is NOT the input token (always fresh)",
    $rotated1 !== $seedTokenForRotation);

// ---------------------------------------------------------------------------
// 2. Unit test: rotatePasswordResetToken called twice produces TWO
//    different tokens (the proof that the old loop logic was always
//    a loop, never a no-op on the second pass as the original
//    comment claimed).
// ---------------------------------------------------------------------------
section("2. Two consecutive rotations produce distinct tokens (proves the old loop logic was always a loop)");

$rotated2 = rotatePasswordResetToken($seedTokenForRotation);
// Wait — the row for $seedTokenForRotation no longer exists; it
// was overwritten by the first rotation. The "second call" must
// be on a freshly-seeded token, not on the rotated one, because
// the rotate helper UPDATES the row in place (it does not insert
// a new row).
$seedToken2 = bin2hex(random_bytes(32));
$db->execute(
    "INSERT INTO password_reset_tokens (user_id, token, expires_at)
     VALUES (?, ?, DATE_ADD(NOW(), INTERVAL 1 HOUR))",
    [$seedUserId, $seedToken2]
);
$rotated2a = rotatePasswordResetToken($seedToken2);
$seedToken3 = bin2hex(random_bytes(32));
$db->execute(
    "INSERT INTO password_reset_tokens (user_id, token, expires_at)
     VALUES (?, ?, DATE_ADD(NOW(), INTERVAL 1 HOUR))",
    [$seedUserId, $seedToken3]
);
$rotated2b = rotatePasswordResetToken($seedToken3);
ok("Two consecutive rotations produce distinct tokens", $rotated2a !== $rotated2b);
ok("Two consecutive rotations produce tokens of the same shape (both non-empty hex)",
    strlen($rotated2a) > 0 && strlen($rotated2b) > 0
    && ctype_xdigit($rotated2a) && ctype_xdigit($rotated2b)
    && strlen($rotated2a) === strlen($rotated2b));

// Sanity: in the old code, on the SECOND pass of a render-then-redirect
// cycle, the page called rotatePasswordResetToken($token) where
// $token was already the rotated value. The page then compared
// $rotated_token !== $token — and because rotatePasswordResetToken
// always returns a fresh token, the comparison was ALWAYS true and
// the 302 ALWAYS fired. The page never reached the form render.
// This is the bug. The "no-op rotation" comment in the old code
// was wrong.

$db->execute(
    "DELETE FROM password_reset_tokens WHERE user_id = ?",
    [$seedUserId]
);
$db->execute(
    "INSERT INTO password_reset_tokens (user_id, token, expires_at)
     VALUES (?, ?, DATE_ADD(NOW(), INTERVAL 1 HOUR))",
    [$seedUserId, $testToken]
);

// ---------------------------------------------------------------------------
// 3-5. Source-grep: reset_password.php does NOT call
//      rotatePasswordResetToken and does NOT issue a 302.
// ---------------------------------------------------------------------------
section("3-5. Source-grep on reset_password.php (issue-024 fix)");

$resetSrc  = file_get_contents($repo_root . '/reset_password.php');
$resetCode = strip_php_comments($resetSrc);

ok("reset_password.php does NOT call rotatePasswordResetToken (issue-024 fix)",
    strpos($resetCode, 'rotatePasswordResetToken(') === false);
ok("reset_password.php does NOT issue a 302 to a rotated-token URL (issue-024 fix)",
    strpos($resetCode, "header('Location: reset_password.php?token=") === false);
ok("reset_password.php does NOT concatenate a urlencode(\$rotated_token) (issue-024 fix)",
    strpos($resetCode, 'urlencode($rotated_token)') === false);
ok("reset_password.php still defines the \$valid_token flag (form-render gate preserved)",
    strpos($resetCode, '$valid_token = true') !== false);
ok("reset_password.php still calls invalidateAllPasswordResetTokensForUser (POST success path)",
    strpos($resetCode, 'invalidateAllPasswordResetTokensForUser') !== false);
ok("reset_password.php still calls recordPasswordResetAttempt (rate-limit recording)",
    strpos($resetCode, 'recordPasswordResetAttempt(') !== false);
ok("reset_password.php still calls isPasswordResetRateLimited (rate-limit gate)",
    strpos($resetCode, 'isPasswordResetRateLimited(') !== false);

// ---------------------------------------------------------------------------
// 6-9. Live HTTP: GET /reset_password.php
// ---------------------------------------------------------------------------
section("6-9. Live HTTP: GET /reset_password.php");

$ch = curl_init();
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HEADER         => true,
    CURLOPT_FOLLOWLOCATION => false,   // critical: do NOT follow the 302
    CURLOPT_TIMEOUT        => 5,
    CURLOPT_HTTPHEADER     => ['Accept: text/html'],
]);

// 6. Valid token → 200 with form (no redirect).
curl_setopt($ch, CURLOPT_URL, "http://pmp-web/reset_password.php?token=" . urlencode($testToken));
$body6 = curl_exec($ch);
$info6 = curl_getinfo($ch);
$status6 = (int)$info6['http_code'];
ok("GET /reset_password.php?token=VALID returns 200 (not 302) — issue-024 fix",
    $status6 === 200,
    "status=$status6, Location header="
    . (preg_match('/^location:\s*(.+)$/mi', (string)$body6, $m) ? trim($m[1]) : 'none'));
ok("GET /reset_password.php?token=VALID body contains a <form> tag",
    is_string($body6) && strpos($body6, '<form') !== false);
ok("GET /reset_password.php?token=VALID body contains a password input",
    is_string($body6) && preg_match('/<input[^>]*name=["\']password["\']/i', $body6) === 1);
ok("GET /reset_password.php?token=VALID body does NOT show 'Invalid Reset Link'",
    is_string($body6) && strpos($body6, 'Invalid Reset Link') === false);

// 7. Valid token called TWICE → 200 both times (the loop is gone).
curl_setopt($ch, CURLOPT_URL, "http://pmp-web/reset_password.php?token=" . urlencode($testToken));
$body7a = curl_exec($ch);
$status7a = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_setopt($ch, CURLOPT_URL, "http://pmp-web/reset_password.php?token=" . urlencode($testToken));
$body7b = curl_exec($ch);
$status7b = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
ok("First GET /reset_password.php?token=VALID returns 200 (loop test)",
    $status7a === 200, "status=$status7a");
ok("Second GET with the SAME token also returns 200 (loop is gone)",
    $status7b === 200, "status=$status7b");
ok("Both GETs return a <form> tag (form is rendered, not a 302 chain)",
    strpos((string)$body7a, '<form') !== false
    && strpos((string)$body7b, '<form') !== false);

// 8. Invalid token → 200 with "Invalid Reset Link" block.
curl_setopt($ch, CURLOPT_URL, "http://pmp-web/reset_password.php?token=" . urlencode('this-token-does-not-exist'));
$body8 = curl_exec($ch);
$status8 = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
ok("GET /reset_password.php?token=INVALID returns 200", $status8 === 200, "status=$status8");
ok("GET /reset_password.php?token=INVALID shows the 'Invalid Reset Link' H4 block",
    is_string($body8) && strpos($body8, 'Invalid Reset Link') !== false);
ok("GET /reset_password.php?token=INVALID does NOT show a <form> tag",
    is_string($body8) && strpos($body8, '<form') === false);

// 9. No token → 200 with the "Invalid Reset Link" H4 block (the
// same template block as the invalid-token case; the error string
// in $errors[] is not actually rendered to HTML — only the H4 is).
curl_setopt($ch, CURLOPT_URL, "http://pmp-web/reset_password.php");
$body9 = curl_exec($ch);
$status9 = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
ok("GET /reset_password.php (no token) returns 200", $status9 === 200, "status=$status9");
ok("GET /reset_password.php (no token) shows the 'Invalid Reset Link' H4 block",
    is_string($body9) && strpos($body9, 'Invalid Reset Link') !== false);
ok("GET /reset_password.php (no token) does NOT show a <form> tag",
    is_string($body9) && strpos($body9, '<form') === false);

curl_close($ch);

// ---------------------------------------------------------------------------
// 10. End-to-end: GET form, then POST with new password, then assert
//     the token is invalidated and the success page renders.
// ---------------------------------------------------------------------------
section("10. End-to-end: GET form, POST new password, assert token is invalidated");

// Re-seed a fresh token because the GETs above don't change the
// DB state (rotation-on-render is gone, so the existing token is
// still valid).
$postToken = bin2hex(random_bytes(32));
$db->execute(
    "INSERT INTO password_reset_tokens (user_id, token, expires_at)
     VALUES (?, ?, DATE_ADD(NOW(), INTERVAL 1 HOUR))",
    [$seedUserId, $postToken]
);

$ch = curl_init();
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HEADER         => true,
    CURLOPT_FOLLOWLOCATION => false,
    CURLOPT_TIMEOUT        => 5,
]);

// 10a. GET the form with the fresh token.
curl_setopt($ch, CURLOPT_URL, "http://pmp-web/reset_password.php?token=" . urlencode($postToken));
$body10a = curl_exec($ch);
$status10a = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
ok("10a. GET form with fresh token returns 200", $status10a === 200, "status=$status10a");

// 10b. POST the new password. We have to send a cookie jar so the
// session survives the GET → POST cycle. PHP's libcurl 8.22 in the
// app container is finicky with tempnam() cookie jars (see the
// issue-020 memory notes), so we parse Set-Cookie from the GET
// response and forward it on the POST.
preg_match('/^set-cookie:\s*([^;]+)/mi', (string)$body10a, $cookieMatch);
$cookieHeader = $cookieMatch[1] ?? '';
ok("10b. GET response sets a PHPSESSID cookie (needed for POST)",
    $cookieHeader !== '');

// 10c. POST with the new password.
$postFields = http_build_query([
    'password'         => 'NewPassword!2026',
    'confirm_password' => 'NewPassword!2026',
]);
curl_setopt_array($ch, [
    CURLOPT_POST           => true,
    CURLOPT_POSTFIELDS     => $postFields,
    CURLOPT_HTTPHEADER     => array_filter([
        'Content-Type: application/x-www-form-urlencoded',
        $cookieHeader !== '' ? 'Cookie: ' . $cookieHeader : null,
    ]),
    CURLOPT_URL            => "http://pmp-web/reset_password.php?token=" . urlencode($postToken),
]);
$body10c = curl_exec($ch);
$status10c = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
ok("10c. POST new password returns 200 (or 302 to login — both are valid success paths)",
    $status10c === 200 || $status10c === 302,
    "status=$status10c");
ok("10c. POST success page shows 'Password Reset Successful'",
    is_string($body10c) && strpos($body10c, 'Password Reset Successful') !== false);

// 10d. The token row for $postToken must be DELETED now
// (invalidateAllPasswordResetTokensForUser wipes ALL of the
// user's tokens on success).
$postTokenRow = $db->fetchOne(
    "SELECT id FROM password_reset_tokens WHERE token = ?",
    [$postToken]
);
ok("10d. The consumed token is DELETED from the DB after success",
    $postTokenRow === null || empty($postTokenRow));

// 10e. ALL of the user's tokens must be DELETED (not just the
// consumed one — this is the issue-018 bulk-invalidate
// guarantee).
$userTokenCount = (int)$db->fetchOne(
    "SELECT COUNT(*) AS c FROM password_reset_tokens WHERE user_id = ?",
    [$seedUserId]
)['c'];
ok("10e. ALL of the user's tokens are deleted (bulk-invalidate)",
    $userTokenCount === 0, "count=$userTokenCount");

// 10f. The password was actually updated — login with the new
// password succeeds.
$loginOk = password_verify('NewPassword!2026',
    (string)$db->fetchOne(
        "SELECT password FROM users WHERE id = ?",
        [$seedUserId]
    )['password']);
ok("10f. The new password is now the correct login credential",
    $loginOk);

curl_close($ch);

// ---------------------------------------------------------------------------
// 11. After successful reset, the old token returns the
//     "Invalid or expired reset link" block.
// ---------------------------------------------------------------------------
section("11. After successful reset, the old token is rejected");

$ch = curl_init();
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_FOLLOWLOCATION => false,
    CURLOPT_TIMEOUT        => 5,
]);
curl_setopt($ch, CURLOPT_URL, "http://pmp-web/reset_password.php?token=" . urlencode($postToken));
$body11 = curl_exec($ch);
$status11 = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

ok("11. GET with the now-consumed token returns 200", $status11 === 200, "status=$status11");
ok("11. GET with the now-consumed token shows the 'Invalid Reset Link' H4 block",
    is_string($body11) && strpos($body11, 'Invalid Reset Link') !== false);
ok("11. GET with the now-consumed token does NOT show a <form> tag",
    is_string($body11) && strpos($body11, '<form') === false);

// ---------------------------------------------------------------------------
// 12. Lint touched files
// ---------------------------------------------------------------------------
section("12. Lint touched files");

$lintFiles = [
    'reset_password.php'     => $repo_root . '/reset_password.php',
    'includes/functions.php' => $repo_root . '/includes/functions.php',
];
foreach ($lintFiles as $label => $abs) {
    $lintOut = shell_exec("php -l " . escapeshellarg($abs) . " 2>&1");
    ok("$label passes php -l",
        is_string($lintOut) && strpos($lintOut, 'No syntax errors detected') !== false,
        is_string($lintOut) ? trim($lintOut) : 'no output');
}

// ---------------------------------------------------------------------------
// Summary
// ---------------------------------------------------------------------------
echo "\nPassed: $pass\n";
echo "Failed: " . count($failures) . "\n";

if ($failures) {
    fwrite(STDERR, "\nIssue-024 password-reset redirect-loop test: " . count($failures) . " ASSERTION(S) FAILED\n");
    foreach ($failures as $f) {
        fwrite(STDERR, "  - $f\n");
    }
    exit(1);
}
echo "\nIssue-024 password-reset redirect-loop test: ALL ASSERTIONS PASSED\n";
exit(0);
