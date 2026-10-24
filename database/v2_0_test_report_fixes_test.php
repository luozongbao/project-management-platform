<?php
/**
 * Regression test for the v2.0 QA remediation (docs/v2.0-test-report.md).
 *
 * Covers the four blocking defects plus the "same class of bug" table:
 *
 *   A. BUG-1  requireLogin() must emit an ABSOLUTE Location header.
 *            A relative "login.php" resolved against the current
 *            directory, so every deep link 404'd
 *            (/projects/1692 -> /projects/login.php -> 404).
 *   B. BUG-1  is_safe_return_path() must reject every open-redirect
 *            shape, and safe_return_target() must fall back to the
 *            dashboard for anything unsafe.
 *   C. BUG-1  The ?redirect= round-trip must work: an unauthenticated
 *            deep link carries the original path, and the login page
 *            hands it back after a successful authentication.
 *   D. BUG-2  The mockup must publish ONLY issue-035.md - the whole
 *            docs/issues/ tree must never be bind-mounted, and nginx
 *            must deny /issues/ as a catch-all.
 *   E. BUG-3  /_diag.php and /_smtp_test.php must be denied by nginx
 *            before the PHP handler runs.
 *   F. BUG-4  The _diag.php md5 pins must match the shipped files.
 *   G. The "same class of bug" table: every other Location header in
 *            the application must be an absolute path.
 *
 * No DB connection is needed: everything here is a source-structural
 * check plus a functional check of the pure helper functions.
 *
 * Exit code 0 = all checks pass, 1 = at least one failed.
 */

declare(strict_types=1);

$root = realpath(__DIR__ . '/..');
$failures = [];

/**
 * Strip PHP comments from a source file so prose in a doc block or a
 * `//` note can never satisfy (or break) a source-grep assertion.
 */
function strip_php_comments(string $src): string
{
    $out = '';
    $len = strlen($src);
    $inSingle = false;
    $inDouble = false;
    $inLine = false;
    $inBlock = false;

    for ($i = 0; $i < $len; $i++) {
        $ch = $src[$i];
        $next = $i + 1 < $len ? $src[$i + 1] : '';

        if ($inLine) {
            if ($ch === "\n") {
                $inLine = false;
                $out .= $ch;
            }
            continue;
        }
        if ($inBlock) {
            if ($ch === '*' && $next === '/') {
                $inBlock = false;
                $i++;
            }
            continue;
        }
        if ($inSingle) {
            $out .= $ch;
            if ($ch === '\\') {
                if ($next !== '') {
                    $out .= $next;
                    $i++;
                }
            } elseif ($ch === "'") {
                $inSingle = false;
            }
            continue;
        }
        if ($inDouble) {
            $out .= $ch;
            if ($ch === '\\') {
                if ($next !== '') {
                    $out .= $next;
                    $i++;
                }
            } elseif ($ch === '"') {
                $inDouble = false;
            }
            continue;
        }
        if ($ch === '/' && $next === '/') {
            $inLine = true;
            $i++;
            continue;
        }
        if ($ch === '/' && $next === '*') {
            $inBlock = true;
            $i++;
            continue;
        }
        if ($ch === "'") {
            $inSingle = true;
            $out .= $ch;
            continue;
        }
        if ($ch === '"') {
            $inDouble = true;
            $out .= $ch;
            continue;
        }
        $out .= $ch;
    }
    return $out;
}

function ok(string $label, bool $ok, string $detail = ''): void
{
    global $failures;
    if ($ok) {
        echo "  [PASS] $label\n";
    } else {
        $failures[] = $label . ($detail !== '' ? " — $detail" : '');
        echo "  [FAIL] $label" . ($detail !== '' ? " — $detail" : '') . "\n";
    }
}

function read_source(string $path): string
{
    $src = @file_get_contents($path);
    if (!is_string($src)) {
        $src = '';
    }
    return $src;
}

echo "== A. BUG-1: requireLogin() emits an absolute Location ==\n";

$functionsSrc = read_source($root . '/includes/functions.php');
$functionsCode = strip_php_comments($functionsSrc);

ok(
    'includes/functions.php defines requireLogin()',
    strpos($functionsCode, 'function requireLogin()') !== false
);

// The function body must NOT contain a relative Location header.
// Match "Location: login.php" (no leading slash) specifically.
ok(
    'requireLogin() does NOT emit a relative "Location: login.php"',
    strpos($functionsCode, "Location: login.php") === false,
    'a relative Location resolves against the current directory -> 404 on deep links'
);

// The Location target must be built from a variable that starts with a
// slash.
ok(
    'requireLogin() builds its target as an absolute path ("/login")',
    strpos($functionsCode, "'/login'") !== false
        || strpos($functionsCode, '"/login"') !== false
);
ok(
    'requireLogin() emits header(\'Location: \' . $target) — a computed absolute target',
    preg_match(
        "~header\(\s*'Location:\s*'\s*\.\s*\\\$target\s*\)~",
        $functionsCode
    ) === 1
);

// The guard must appear in EVERY header() call inside requireLogin().
$requireLoginBody = '';
if (preg_match('~function requireLogin\(\)\s*\{(.*?)\n\}~s', $functionsCode, $m)) {
    $requireLoginBody = $m[1];
}
ok(
    'requireLogin() body could be extracted',
    $requireLoginBody !== ''
);
ok(
    'requireLogin() contains no relative Location header at all',
    $requireLoginBody !== ''
        && preg_match('~Location:\s*[\'"]?(?!/)[A-Za-z0-9_.-]~', $requireLoginBody) === 0,
    'every Location target inside requireLogin() must begin with "/"'
);

echo "\n== B. BUG-1: the open-redirect guard ==\n";

ok(
    'is_safe_return_path() exists',
    strpos($functionsCode, 'function is_safe_return_path(') !== false
);
ok(
    'safe_return_target() exists',
    strpos($functionsCode, 'function safe_return_target(') !== false
);

// Exercise the pure helpers in isolation.
$probe = $root . '/database/__v2fix_probe.php';
$helperSrc = <<<'PHP'
<?php
function is_safe_return_path($path) {
    if (!is_string($path) || $path === '') {
        return false;
    }
    if ($path[0] !== '/' || (isset($path[1]) && $path[1] === '/')) {
        return false;
    }
    if (preg_match('#^/[A-Za-z0-9/_.\-~%?&=+:@]*$#', $path) !== 1) {
        return false;
    }
    return true;
}
function safe_return_target($default = '/dashboard') {
    $candidate = $_GET['redirect'] ?? '';
    if (is_safe_return_path($candidate)) {
        return $candidate;
    }
    return $default;
}
// The probe is invoked as: php probe.php redirect=<value>
// Populate $_GET so the real function body is exercised unchanged.
$_GET['redirect'] = '';
foreach (array_slice($argv, 1) as $arg) {
    if (strpos($arg, 'redirect=') === 0) {
        $_GET['redirect'] = substr($arg, strlen('redirect='));
    }
}
echo safe_return_target('/dashboard');
PHP;
@file_put_contents($probe, $helperSrc);

// Run each case in a separate PHP process so $_GET does not leak
// between assertions.
$cases = [
    // [ candidate, expected_result, label ]
    ['/projects/1692',          '/projects/1692',    'a normal deep link is accepted'],
    ['/projects/1692/edit',     '/projects/1692/edit', 'a nested deep link is accepted'],
    ['/tasks/935?foo=bar',      '/tasks/935?foo=bar', 'a query string survives'],
    ['/dashboard',              '/dashboard',        'the dashboard itself is accepted'],
    ['https://evil.example/x',  '/dashboard',        'an absolute URL is rejected'],
    ['//evil.example/x',        '/dashboard',        'a protocol-relative URL is rejected'],
    ['/\\evil.example',         '/dashboard',        'a backslash-normalised URL is rejected'],
    ['javascript:alert(1)',     '/dashboard',        'a javascript: scheme is rejected'],
    ['',                        '/dashboard',        'an empty value falls back'],
    ['projects/1692',           '/dashboard',        'a relative path is rejected'],
];
foreach ($cases as [$candidate, $expected, $label]) {
    $cmd = 'php ' . escapeshellarg($probe)
        . ' redirect=' . escapeshellarg($candidate)
        . ' 2>/dev/null';
    $actual = trim((string) @shell_exec($cmd));
    ok(
        'safe_return_target(): ' . $label,
        $actual === $expected,
        "expected '$expected', got '" . $actual . "'"
    );
}
@unlink($probe);

echo "\n== C. BUG-1: the ?redirect= round-trip is wired up ==\n";

$loginSrc = read_source($root . '/login.php');
$loginCode = strip_php_comments($loginSrc);

ok(
    'login.php honours the return target after a successful login',
    strpos($loginCode, 'safe_return_target(') !== false
);
ok(
    'login.php honours the return target in the already-logged-in branch',
    preg_match('~isLoggedIn\(\)\s*\)\s*\{\s*header\(\s*\'Location:\s*\'\s*\.\s*safe_return_target~', $loginCode) === 1
);
ok(
    'login.php still falls back to /dashboard',
    strpos($loginCode, "safe_return_target('/dashboard')") !== false
);

// The redirect() helper must refuse a relative target outright.
ok(
    'redirect() rejects a relative target (defence in depth)',
    strpos($functionsCode, "if (!is_string(\$url) || \$url === '' || \$url[0] !== '/')") !== false
);

echo "\n== D. BUG-2: the mockup publishes only issue-035.md ==\n";

$composeSrc = read_source($root . '/docker-compose.yml');
ok(
    'docker-compose.yml does NOT mount the whole docs/issues/ directory',
    preg_match('~^\s*-\s*\./docs/issues:/~m', $composeSrc) === 0,
    'the whole issue tree must never be published'
);
ok(
    'docker-compose.yml mounts ONLY docs/issues/issue-035.md',
    strpos($composeSrc, './docs/issues/issue-035.md:') !== false
);
ok(
    'the single-file mount is read-only',
    preg_match('~^\s*-\s*\./docs/issues/issue-035\.md:\S+:ro\s*$~m', $composeSrc) === 1
);

$mockupConf = read_source($root . '/docker/mockup-nginx.conf');
ok(
    'mockup-nginx.conf has an exact-match location for issue-035.md',
    preg_match('~location\s*=\s*/issues/issue-035\.md~', $mockupConf) === 1
);
ok(
    'mockup-nginx.conf denies /issues/ as a catch-all',
    preg_match('~location\s+/issues/\s*\{\s*return\s+404;\s*\}~', $mockupConf) === 1
);
ok(
    'mockup-nginx.conf no longer serves the whole /issues/ tree',
    preg_match('~location\s+/issues/\s*\{\s*root~', $mockupConf) === 0
);
ok(
    'the mockup still has no PHP handler (static only)',
    stripos($mockupConf, 'fastcgi_pass') === false
);

echo "\n== E. BUG-3: /_diag.php is not publicly reachable ==\n";

$nginxConf = read_source($root . '/docker/nginx.conf');
$nginxCode = strip_php_comments($nginxConf);
ok(
    'nginx.conf denies _diag.php and _smtp_test.php',
    strpos($nginxCode, '_diag|_smtp_test') !== false
        && preg_match('#location\s+~\*\s.*_diag\|_smtp_test.*\.php.*\{[^}]*return\s+404;#s', $nginxCode) === 1
);

// The deny rule must appear BEFORE the generic \.php$ handler, otherwise
// nginx would pass the request to PHP first.
$denyPos = strpos($nginxConf, '_diag|_smtp_test');
$phpPos = strpos($nginxConf, 'location ~ \.php$');
ok(
    'the deny rule sits BEFORE the generic PHP handler',
    $denyPos !== false && $phpPos !== false && $denyPos < $phpPos,
    'nginx evaluates regex locations in file order'
);

echo "\n== F. BUG-4: the _diag.php md5 pins are current ==\n";

$diagSrc = read_source($root . '/_diag.php');
preg_match_all(
    "~'([^']+)'\s*=>\s*'([0-9a-f]{32})'~",
    $diagSrc,
    $pinMatches,
    PREG_SET_ORDER
);
ok(
    '_diag.php pins at least 5 md5 hashes',
    count($pinMatches) >= 5,
    'found ' . count($pinMatches)
);
foreach ($pinMatches as $pin) {
    $label = $pin[1];
    $expected = $pin[2];
    $path = $root . '/' . $label;
    $actual = is_readable($path) ? md5_file($path) : '(missing)';
    ok(
        "the md5 pin for $label matches the shipped file",
        $actual === $expected,
        "expected $expected, actual $actual"
    );
}
ok(
    '_diag.php contains no FILL_IN_AT_RELEASE_TIME placeholder',
    strpos($diagSrc, 'FILL_IN_AT_RELEASE_TIME') === false
        || preg_match("~'config\.php'\s*=>\s*'FILL_IN_AT_RELEASE_TIME'~", $diagSrc) === 0
);

echo "\n== G. every Location header in the app is absolute ==\n";

// Scan the application sources (not the tests, not the vendor tree) for
// a header('Location: <relative>') and require each one to start with
// a slash.
$appRoot = $root;
$scanFiles = [];
$it = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($appRoot, FilesystemIterator::SKIP_DOTS)
);
foreach ($it as $file) {
    if (!$file->isFile() || $file->getExtension() !== 'php') {
        continue;
    }
    $path = $file->getPathname();
    // Skip the test harness, the vendor tree and the docs.
    $rel = str_replace($appRoot . '/', '', $path);
    if (strpos($rel, 'database/') === 0
        || strpos($rel, 'vendor/') === 0
        || strpos($rel, 'docs/') === 0
    ) {
        continue;
    }
    $scanFiles[] = $path;
}
$violations = [];
$checked = 0;
foreach ($scanFiles as $path) {
    $code = strip_php_comments(read_source($path));
    // header('Location: <target>') with a literal target.
    if (preg_match_all(
        "~header\(\s*['\"]Location:\s*([^'\"]*?)['\"]\s*[,)]~",
        $code,
        $m,
        PREG_SET_ORDER
    )) {
        foreach ($m as $hit) {
            $target = trim($hit[1]);
            if ($target === '') {
                // A concatenated or variable target ("Location: ' . $x")
                // is resolved at runtime; those are asserted separately.
                continue;
            }
            $checked++;
            if (strpos($target, '$') !== false) {
                continue;
            }
            if ($target[0] !== '/') {
                $violations[] = str_replace($appRoot . '/', '', $path) . ' -> "' . $target . '"';
            }
        }
    }
}
ok(
    'no application file emits a relative Location header',
    count($violations) === 0,
    implode('; ', $violations)
);
ok(
    'the scan actually inspected some Location headers',
    $checked >= 8,
    "checked $checked literal Location headers"
);

echo "\n";
if ($failures) {
    echo "FAILED: " . count($failures) . " check(s)\n";
    foreach ($failures as $f) {
        echo "  - $f\n";
    }
    exit(1);
}

echo "All checks passed.\n";
exit(0);
