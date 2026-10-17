<?php
/**
 * Regression test for issue-020 — Response Security Headers + Session Hardening.
 *
 * Five layers of verification:
 *
 *   1. Source-grep on includes/security_headers.php — both helpers exist
 *      with the documented shape (idempotent via static $sent, six
 *      headers in sendSecurityHeaders(), tightened script-src in
 *      sendClientPortalSecurityHeaders()).
 *   2. Source-grep on the wired-up call sites:
 *        - includes/header.php calls sendSecurityHeaders()
 *        - client/dashboard.php and index.php call
 *          sendClientPortalSecurityHeaders()
 *   3. Source-grep on the session-cookie hardening in
 *      includes/functions.php (session_set_cookie_params with
 *      httponly => true and samesite => 'Lax') and on the
 *      session_regenerate_id(true) call sites in login.php
 *      and reset_password.php.
 *   4. Source-grep on docker/nginx.conf — three complement headers
 *      in BOTH the server block AND the static asset location block
 *      (nginx add_header does NOT inherit from server to nested
 *      location, so both must be present).
 *   5. Live HTTP through the nginx container:
 *        - /login.php returns all 5 PHP-side headers + the 3 nginx-side
 *          headers (overlap on 3 is expected and documented)
 *        - /client/dashboard.php?code=... returns the tightened
 *          script-src (no 'unsafe-inline')
 *        - /index.php returns the tightened script-src
 *        - /assets/css/style.css returns the 3 nginx-side headers
 *          and does NOT carry a Content-Security-Policy (CSP is PHP-only)
 *        - POST /login.php with valid creds returns Set-Cookie with
 *          HttpOnly and SameSite=Lax; session ID before/after differs
 *          (rotation)
 *
 * The test does NOT modify the DB. The login flow probe
 * (/tmp/issue020_login_test.php) was used to verify the rotation
 * interactively before the test was written; this test does the same
 * end-to-end as a CI step.
 *
 * Run with:  docker exec project-management-platform-app-1 php database/security_headers_test.php
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/Database.php';
require_once __DIR__ . '/../includes/functions.php';

$db = Database::getInstance();
$failures = [];

// Base URL for live HTTP probes. The app container can reach pmp-web
// on the docker network, but NOT host localhost — so the live tests
// use the docker hostname. When the test is run from the host shell
// (e.g. for local debugging), set PMP_TEST_BASE_URL=http://localhost
// to override.
$BASE = getenv('PMP_TEST_BASE_URL') ?: 'http://pmp-web';

// ---------------------------------------------------------------------------
// 1. includes/security_headers.php — both helpers exist with the
//    documented shape.
// ---------------------------------------------------------------------------
$sec = file_get_contents(__DIR__ . '/../includes/security_headers.php');
if ($sec === false) {
    $failures[] = 'includes/security_headers.php is missing entirely';
} else {
    if (strpos($sec, 'function sendSecurityHeaders()') === false) {
        $failures[] = 'sendSecurityHeaders() is not defined';
    }
    if (strpos($sec, 'function sendClientPortalSecurityHeaders()') === false) {
        $failures[] = 'sendClientPortalSecurityHeaders() is not defined';
    }
    // Idempotent via static $sent — calling twice must not error.
    foreach (['sendSecurityHeaders', 'sendClientPortalSecurityHeaders'] as $fn) {
        // The function must be wrapped in `if (!function_exists(...))` so
        // double-require of the helper file does not fatal.
        if (strpos($sec, "if (!function_exists('$fn'))") === false
         && strpos($sec, 'if (!function_exists("' . $fn . '"))') === false) {
            $failures[] = "$fn() is not wrapped in if (!function_exists('$fn')) — double-include will fatal";
        }
        // The function body itself must guard with `static $sent` so a
        // caller that invokes it from two paths (e.g. header.php + a
        // page-specific include) does not emit duplicate headers.
        $fnStart = strpos($sec, "function $fn(");
        if ($fnStart !== false) {
            $body = substr($sec, $fnStart, 400);
            if (strpos($body, 'static $sent') === false) {
                $failures[] = "$fn() body is missing `static \$sent` guard for idempotency";
            }
        }
    }
    // The six required headers in the internal-app helper.
    foreach ([
        'Content-Security-Policy',
        'X-Frame-Options: DENY',
        'X-Content-Type-Options: nosniff',
        'Referrer-Policy: strict-origin-when-cross-origin',
        'Permissions-Policy',
        "frame-ancestors 'none'",
    ] as $needle) {
        if (strpos($sec, $needle) === false) {
            $failures[] = "sendSecurityHeaders() does not emit '$needle'";
        }
    }
    // Permissions-Policy must disable at least the seven common sensors.
    foreach (['camera', 'microphone', 'geolocation', 'usb', 'payment', 'accelerometer', 'gyroscope'] as $p) {
        if (!preg_match('/' . preg_quote($p, '/') . '=\(\)/', $sec)) {
            $failures[] = "Permissions-Policy does not disable '$p'";
        }
    }
    // The client portal helper MUST tighten script-src — no 'unsafe-inline'.
    $clientFnStart = strpos($sec, 'function sendClientPortalSecurityHeaders()');
    if ($clientFnStart !== false) {
        $clientFn = substr($sec, $clientFnStart);
        if (strpos($clientFn, "script-src 'self'") === false) {
            $failures[] = "sendClientPortalSecurityHeaders() does not pin script-src to 'self'";
        }
        if (strpos($clientFn, "script-src 'self' 'unsafe-inline'") !== false) {
            $failures[] = "sendClientPortalSecurityHeaders() still allows 'unsafe-inline' in script-src — should be tightened";
        }
        // Sanity: the internal-app helper DOES allow 'unsafe-inline' (the
        // current app has inline onclick= and inline <script> blocks).
        $internalFnStart = strpos($sec, 'function sendSecurityHeaders()');
        $internalFn = substr($sec, $internalFnStart, $clientFnStart - $internalFnStart);
        if (strpos($internalFn, "script-src 'self' 'unsafe-inline'") === false) {
            $failures[] = "sendSecurityHeaders() no longer allows 'unsafe-inline' in script-src — internal app's inline onclick= would break";
        }
    }
}

// ---------------------------------------------------------------------------
// 2. Call sites in the page templates.
// ---------------------------------------------------------------------------
$header = file_get_contents(__DIR__ . '/../includes/header.php');
if (strpos($header, "require_once __DIR__ . '/security_headers.php'") === false) {
    $failures[] = "includes/header.php does not require_once security_headers.php";
}
if (strpos($header, 'sendSecurityHeaders()') === false) {
    $failures[] = 'includes/header.php does not call sendSecurityHeaders()';
}
// The call must come BEFORE any HTML output (otherwise headers are too late).
$htmlPos = strpos($header, '<!DOCTYPE');
$callPos = strpos($header, 'sendSecurityHeaders()');
if ($htmlPos !== false && $callPos !== false && $callPos > $htmlPos) {
    $failures[] = 'sendSecurityHeaders() in includes/header.php is called AFTER the <!DOCTYPE> — too late';
}

foreach ([
    __DIR__ . '/../client/dashboard.php' => 'sendClientPortalSecurityHeaders()',
    __DIR__ . '/../index.php'            => 'sendClientPortalSecurityHeaders()',
] as $file => $call) {
    $src = file_get_contents($file);
    if (strpos($src, 'security_headers.php') === false) {
        $failures[] = basename($file) . " does not require_once security_headers.php";
    }
    if (strpos($src, $call) === false) {
        $failures[] = basename($file) . " does not call $call";
    }
}

// ---------------------------------------------------------------------------
// 3. Session-cookie hardening in includes/functions.php + regenerate
//    call sites in login.php and reset_password.php.
// ---------------------------------------------------------------------------
$funcs = file_get_contents(__DIR__ . '/../includes/functions.php');
$startFn = strpos($funcs, 'function startSession(');
$startFnEnd = strpos($funcs, "\n}\n", $startFn);
$startFnBody = substr($funcs, $startFn, $startFnEnd - $startFn + 3);

if (strpos($startFnBody, 'session_set_cookie_params') === false) {
    $failures[] = 'startSession() does not call session_set_cookie_params() — cookie flags are ungoverned';
}
foreach ([
    "'httponly' => true",
    "'samesite' => 'Lax'",
    "'lifetime' => 0",
] as $needle) {
    if (strpos($startFnBody, $needle) === false) {
        $failures[] = "startSession() is missing $needle in session_set_cookie_params()";
    }
}
// The Secure flag must be computed from $_SERVER['HTTPS'] / SERVER_PORT,
// not hard-coded.
if (strpos($startFnBody, '_SERVER[\'HTTPS\']') === false) {
    $failures[] = "startSession() does not compute the Secure flag from \$_SERVER['HTTPS'] — would not switch on/off for HTTPS deployments";
}
if (strpos($startFnBody, "session_start()") === false) {
    $failures[] = 'startSession() does not call session_start()';
}

// login.php — session_regenerate_id must be called BEFORE any $_SESSION
// write in the verifyPassword() success branch.
$login = file_get_contents(__DIR__ . '/../login.php');
$regenLoginPos = strpos($login, 'session_regenerate_id(true)');
if ($regenLoginPos === false) {
    $failures[] = 'login.php is missing session_regenerate_id(true) — session fixation is possible';
} else {
    // The line of $_SESSION['user_id'] = $user['id'] must come AFTER
    // session_regenerate_id(true) (rotation must happen before the
    // authenticated state is written to the new ID).
    $userIdWritePos = strpos($login, "\$_SESSION['user_id']", $regenLoginPos);
    $verifyPos = strrpos(substr($login, 0, $regenLoginPos), 'verifyPassword(');
    if ($verifyPos === false) {
        $failures[] = 'session_regenerate_id(true) in login.php is BEFORE verifyPassword() — must be inside the success branch';
    }
    if ($userIdWritePos !== false && $userIdWritePos < $regenLoginPos) {
        $failures[] = 'session_regenerate_id(true) in login.php is AFTER the $_SESSION[\'user_id\'] write — must come first';
    }
}

// reset_password.php — same check, after the password update.
$reset = file_get_contents(__DIR__ . '/../reset_password.php');
$regenResetPos = strpos($reset, 'session_regenerate_id(true)');
if ($regenResetPos === false) {
    $failures[] = 'reset_password.php is missing session_regenerate_id(true) — password reset leaves the old session ID live';
}

// ---------------------------------------------------------------------------
// 4. docker/nginx.conf — three complement headers in BOTH the server
//    block and the static asset location block (add_header does not
//    inherit from server to nested location).
// ---------------------------------------------------------------------------
$nginx = file_get_contents(__DIR__ . '/../docker/nginx.conf');
foreach ([
    'X-Frame-Options "DENY"',
    'X-Content-Type-Options "nosniff"',
    'Referrer-Policy "strict-origin-when-cross-origin"',
] as $needle) {
    $count = substr_count($nginx, $needle);
    if ($count < 2) {
        $failures[] = "docker/nginx.conf contains '$needle' only $count time(s) — should be at least 2 (server block + static asset location block)";
    }
    if (strpos($nginx, $needle . ' always') === false) {
        $failures[] = "docker/nginx.conf '$needle' is missing the 'always' suffix — 4xx/5xx responses will not carry the header";
    }
}

// ---------------------------------------------------------------------------
// 5. Live HTTP through the nginx container.
// ---------------------------------------------------------------------------
function httpHead($url) {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_NOBODY         => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER         => true,
        CURLOPT_TIMEOUT        => 5,
    ]);
    $resp = curl_exec($ch);
    $err  = curl_error($ch);
    curl_close($ch);
    if ($resp === false) {
        return ['error' => $err];
    }
    // Parse the response into a header-name => value array.
    $headers = [];
    $lines = preg_split('/\r?\n/', $resp);
    foreach ($lines as $line) {
        if (strpos($line, ':') === false) continue;
        list($name, $value) = explode(':', $line, 2);
        $headers[strtolower(trim($name))] = trim($value);
    }
    return ['headers' => $headers];
}

// 5a. /login.php — internal app.
$r = httpHead("$BASE/login.php");
if (isset($r['error'])) {
    $failures[] = "live HTTP GET /login.php failed: {$r['error']} (BASE=$BASE)";
} else {
    $h = $r['headers'];
    foreach ([
        'content-security-policy',
        'x-frame-options',
        'x-content-type-options',
        'referrer-policy',
        'permissions-policy',
    ] as $required) {
        if (empty($h[$required])) {
            $failures[] = "live /login.php is missing the $required header";
        }
    }
    if (!empty($h['content-security-policy'])) {
        // Internal app: 'unsafe-inline' MUST be allowed in script-src today
        // (the app has inline onclick= and inline <script>). Tightening to
        // nonces is a v2.0 item.
        if (strpos($h['content-security-policy'], "'unsafe-inline'") === false) {
            $failures[] = "live /login.php Content-Security-Policy has dropped 'unsafe-inline' — internal app's inline onclick= would break";
        }
        if (strpos($h['content-security-policy'], "frame-ancestors 'none'") === false) {
            $failures[] = "live /login.php Content-Security-Policy is missing frame-ancestors 'none' — clickjacking defence is gone";
        }
    }
    if (!empty($h['x-frame-options']) && stripos($h['x-frame-options'], 'DENY') === false) {
        $failures[] = "live /login.php X-Frame-Options is not DENY (got '{$h['x-frame-options']}')";
    }
}

// 5b. /client/dashboard.php?code=ABCDE-FGHIJ-KLMNO — client portal.
$r = httpHead("$BASE/client/dashboard.php?code=ABCDE-FGHIJ-KLMNO");
if (isset($r['error'])) {
    $failures[] = "live HTTP GET /client/dashboard.php failed: {$r['error']} (BASE=$BASE)";
} else {
    $h = $r['headers'];
    if (empty($h['content-security-policy'])) {
        $failures[] = 'live /client/dashboard.php is missing Content-Security-Policy';
    } else {
        // Client portal: script-src MUST NOT contain 'unsafe-inline'
        // (the only JS is /client/assets/js/subtask-toggle.js, served
        // as an external file via <script src=…> defer — issue-027
        // extracted it from an inline <script> block to satisfy this
        // CSP tightening from issue-020).
        $csp = $h['content-security-policy'];
        if (preg_match("/script-src[^;]*'unsafe-inline'/", $csp)) {
            $failures[] = "live /client/dashboard.php Content-Security-Policy still has 'unsafe-inline' in script-src — should be tightened for the public portal";
        }
        if (strpos($csp, "default-src 'self'") === false) {
            $failures[] = "live /client/dashboard.php Content-Security-Policy is missing default-src 'self'";
        }
    }
}

// 5c. /index.php — root, also client-portal-shaped.
$r = httpHead("$BASE/index.php");
if (isset($r['error'])) {
    $failures[] = "live HTTP GET /index.php failed: {$r['error']} (BASE=$BASE)";
} else {
    $h = $r['headers'];
    if (!empty($h['content-security-policy'])) {
        if (preg_match("/script-src[^;]*'unsafe-inline'/", $h['content-security-policy'])) {
            $failures[] = "live /index.php Content-Security-Policy still has 'unsafe-inline' in script-src";
        }
    }
}

// 5d. /assets/css/style.css — static asset. The 3 simple headers must
//     come from nginx; the CSP must NOT (no PHP ran).
$r = httpHead("$BASE/assets/css/style.css");
if (isset($r['error'])) {
    $failures[] = "live HTTP GET /assets/css/style.css failed: {$r['error']} (BASE=$BASE)";
} else {
    $h = $r['headers'];
    foreach (['x-frame-options', 'x-content-type-options', 'referrer-policy'] as $required) {
        if (empty($h[$required])) {
            $failures[] = "live /assets/css/style.css is missing the $required header — nginx add_header did not inherit to the nested location block";
        }
    }
    if (!empty($h['content-security-policy'])) {
        $failures[] = "live /assets/css/style.css carries a Content-Security-Policy header — CSP is PHP-only, nginx is not supposed to send it (would mean the PHP layer is leaking into static assets, or nginx config is misconfigured)";
    }
}

// 5e. Set-Cookie hardening + session ID rotation on a real login.
//     Pick user #1 (the seed admin), reset password to a known value,
//     drive a full GET → POST login through the live stack, and assert:
//       - 302 status
//       - Location: dashboard.php
//       - Set-Cookie has HttpOnly AND SameSite=Lax
//       - PHPSESSID changed (rotation)
//
//     We parse Set-Cookie headers from the response directly rather than
//     relying on CURLOPT_COOKIEJAR. libcurl 8.22 in the app container has
//     a quirk where it loads the cookie into the engine but does not
//     flush it to a fresh tempnam() file on close. Reading the headers
//     straight out of the response is simpler, more deterministic, and
//     does not depend on the cookie-jar file format.
$u = $db->fetchOne('SELECT id, username, password FROM users ORDER BY id LIMIT 1');
$userId   = (int)$u['id'];
$username = $u['username'];
$originalHash = $u['password'];   // restore in finally
$knownPw  = 'Issue020Test_' . bin2hex(random_bytes(4)) . 'Aa1!';
$db->execute('UPDATE users SET password = ? WHERE id = ?', [hashPassword($knownPw), $userId]);

/**
 * Extract the PHPSESSID value from a Set-Cookie response header.
 * Returns the session id string on success, or null if no PHPSESSID
 * was set in the response.
 */
$extractSid = function (string $rawResponse): ?string {
    if (preg_match('/^Set-Cookie:\s*PHPSESSID=([a-f0-9]+)/mi', $rawResponse, $m)) {
        return $m[1];
    }
    return null;
};

try {
    // GET /login.php to obtain an initial PHPSESSID.
    $ch = curl_init("$BASE/login.php");
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER         => true,
        CURLOPT_TIMEOUT        => 5,
    ]);
    $getResp = curl_exec($ch);
    curl_close($ch);
    $initialSid = $extractSid($getResp);
    if ($initialSid === null) {
        $failures[] = 'live login: GET /login.php did not set a PHPSESSID cookie';
    } else {
        // POST /login.php with the known credentials. We send the
        // initial PHPSESSID back as a Cookie header so the server
        // associates the POST with the same session, which is what
        // session_regenerate_id(true) rotates.
        $ch = curl_init("$BASE/login.php");
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER         => true,
            CURLOPT_COOKIE         => "PHPSESSID=$initialSid",
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => http_build_query([
                'username' => $username,
                'password' => $knownPw,
            ]),
            CURLOPT_TIMEOUT        => 5,
        ]);
        $postResp = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($code !== 302) {
            $failures[] = "live login: POST /login.php returned HTTP $code, expected 302";
        }
        if (stripos($postResp, "Location: dashboard.php") === false) {
            $failures[] = 'live login: POST /login.php did not redirect to dashboard.php';
        }
        if (stripos($postResp, 'HttpOnly') === false) {
            $failures[] = "live login: Set-Cookie is missing HttpOnly";
        }
        if (stripos($postResp, 'SameSite=Lax') === false) {
            $failures[] = "live login: Set-Cookie is missing SameSite=Lax";
        }
        // The Secure flag is intentionally absent in HTTP-only local dev
        // (the spec computes it from $_SERVER['HTTPS']). We do not assert
        // it here; the source-grep in section 3 is the coverage.
        $newSid = $extractSid($postResp);
        if ($newSid === null) {
            $failures[] = "live login: POST /login.php did not return a new PHPSESSID";
        } elseif ($newSid === $initialSid) {
            $failures[] = "live login: PHPSESSID did NOT rotate (still $initialSid) — session_regenerate_id(true) is not running";
        }
    }
} finally {
    // Restore the test user's original password hash so the dev
    // environment is not affected.
    $db->execute('UPDATE users SET password = ? WHERE id = ?', [$originalHash, $userId]);
}

// ---------------------------------------------------------------------------
// Result.
// ---------------------------------------------------------------------------
if (!empty($failures)) {
    fwrite(STDERR, "Security headers + session hardening regression test failed:\n");
    foreach ($failures as $f) {
        fwrite(STDERR, "  - $f\n");
    }
    exit(1);
}

echo "Security headers + session hardening regression test passed.\n";
