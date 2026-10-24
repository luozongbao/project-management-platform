<?php
/**
 * Regression test for issue-039 — APP_ALLOW_REGISTRATION flag.
 *
 * Covers the 16 acceptance criteria of docs/issues/issue-039.md:
 *
 *   A. The resolver isRegistrationAllowed() exists, defaults to
 *      permissive, and tolerates a quoted string.
 *   B. register.php enforces a server-side 403 gate for BOTH GET and
 *      POST, and the gate sits ABOVE the POST branch.
 *   C. login.php hides BOTH the "New here?" divider and the
 *      "Create an account" button together (no orphaned divider).
 *   D. forgot_password.php hides only the Register link; the
 *      "Back to Login" link and the .auth-links row still render.
 *   E. All four config writers declare the constant.
 *   F. docker/entrypoint.sh keeps its file-exists guard (so a
 *      hand-edited config.php is never clobbered).
 *   G. The flag lives ONLY in config.php — no .env / docker-compose
 *      mapping, no environment-variable path.
 *
 * No DB connection is needed: everything here is a source-structural
 * check plus a functional check of the pure resolver.
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

// =====================================================================
// A. The resolver
// =====================================================================
echo "## A. isRegistrationAllowed() resolver\n";

$functionsSrc = read_source($root . '/includes/functions.php');
$functionsCode = strip_php_comments($functionsSrc);

ok(
    'includes/functions.php defines isRegistrationAllowed()',
    (bool) preg_match('~function\s+isRegistrationAllowed\s*\(\s*\)\s*\{~', $functionsCode),
    'function declaration not found'
);

ok(
    'the resolver defaults to permissive when the constant is absent',
    (bool) preg_match(
        '~if\s*\(\s*!\s*defined\s*\(\s*[\'"]APP_ALLOW_REGISTRATION[\'"]\s*\)\s*\)\s*\{.*?return\s+\$allowed\s*=\s*true~s',
        $functionsCode
    ),
    'no "constant absent -> allowed" branch found'
);

ok(
    'the resolver tolerates a quoted "true"/"false" string',
    (bool) preg_match('~filter_var\s*\(\s*\$raw\s*,\s*FILTER_VALIDATE_BOOLEAN~', $functionsCode),
    'no FILTER_VALIDATE_BOOLEAN coercion found'
);

ok(
    'the resolver treats an unrecognised value as permissive',
    (bool) preg_match(
        '~\$parsed\s*===\s*null.*?return\s+\$allowed\s*=\s*true~s',
        $functionsCode
    ),
    'no "unrecognised value -> allowed" fallback found'
);

// Functional check of the resolver in isolation, with the constant
// set to each of the documented values.
$resolverBody = '';
if (preg_match('~function\s+isRegistrationAllowed\s*\(\s*\)\s*\{(.*?)\n\}~s', $functionsCode, $m)) {
    $resolverBody = $m[1];
}

if ($resolverBody === '') {
    ok('the resolver body could be extracted for a functional test', false, 'body not extractable');
} else {
    $runResolver = static function (string $literal): string {
        $code = "<?php\n" . $literal . "\n" . "function isRegistrationAllowed() {" . $GLOBALS['__resolver_body'] . "\n}\n"
            . "echo isRegistrationAllowed() ? 'ALLOWED' : 'BLOCKED';\n";
        $tmp = tempnam(sys_get_temp_dir(), 'regflag');
        file_put_contents($tmp, $code);
        $out = shell_exec(escapeshellcmd(PHP_BINARY) . ' ' . escapeshellarg($tmp) . ' 2>&1');
        @unlink($tmp);
        return trim((string) $out);
    };
    $GLOBALS['__resolver_body'] = $resolverBody;

    ok(
        'resolver: constant absent -> registration is ALLOWED',
        $runResolver('') === 'ALLOWED',
        'got: ' . $runResolver('')
    );
    ok(
        'resolver: constant = true -> registration is ALLOWED',
        $runResolver('const APP_ALLOW_REGISTRATION = true;') === 'ALLOWED',
        'got: ' . $runResolver('const APP_ALLOW_REGISTRATION = true;')
    );
    ok(
        'resolver: constant = false -> registration is BLOCKED',
        $runResolver('const APP_ALLOW_REGISTRATION = false;') === 'BLOCKED',
        'got: ' . $runResolver('const APP_ALLOW_REGISTRATION = false;')
    );
    ok(
        'resolver: constant = "false" (quoted) -> registration is BLOCKED',
        $runResolver('const APP_ALLOW_REGISTRATION = "false";') === 'BLOCKED',
        'got: ' . $runResolver('const APP_ALLOW_REGISTRATION = "false";')
    );
    ok(
        'resolver: constant = "true" (quoted) -> registration is ALLOWED',
        $runResolver('const APP_ALLOW_REGISTRATION = "true";') === 'ALLOWED',
        'got: ' . $runResolver('const APP_ALLOW_REGISTRATION = "true";')
    );
    ok(
        'resolver: constant = 1 -> registration is ALLOWED',
        $runResolver('const APP_ALLOW_REGISTRATION = 1;') === 'ALLOWED',
        'got: ' . $runResolver('const APP_ALLOW_REGISTRATION = 1;')
    );
    ok(
        'resolver: constant = 0 -> registration is BLOCKED',
        $runResolver('const APP_ALLOW_REGISTRATION = 0;') === 'BLOCKED',
        'got: ' . $runResolver('const APP_ALLOW_REGISTRATION = 0;')
    );
    ok(
        'resolver: constant = "yes" (garbage) -> registration is ALLOWED',
        $runResolver('const APP_ALLOW_REGISTRATION = "yes";') === 'ALLOWED',
        'got: ' . $runResolver('const APP_ALLOW_REGISTRATION = "yes";')
    );

    unset($GLOBALS['__resolver_body']);
}

// =====================================================================
// B. register.php — the server-side 403 gate
// =====================================================================
echo "## B. register.php server-side 403 gate\n";

$registerSrc = read_source($root . '/register.php');
$registerCode = strip_php_comments($registerSrc);

ok(
    'register.php calls isRegistrationAllowed()',
    strpos($registerCode, 'isRegistrationAllowed()') !== false,
    'no call to isRegistrationAllowed() found'
);

ok(
    'register.php sets a 403 status code',
    (bool) preg_match('~http_response_code\s*\(\s*403\s*\)~', $registerCode),
    'no http_response_code(403) found'
);

// The gate must sit ABOVE the POST branch, so a direct POST cannot
// bypass it and no user is ever created.
$gatePos = strpos($registerCode, 'isRegistrationAllowed()');
$postPos = strpos($registerCode, '$_POST');
ok(
    'the 403 gate sits ABOVE the POST branch in register.php',
    $gatePos !== false && $postPos !== false && $gatePos < $postPos,
    "gate at offset " . var_export($gatePos, true) . ", first \$_POST at " . var_export($postPos, true)
);

ok(
    'the gate also runs for GET (it is not inside an if ($_POST) block)',
    !preg_match('~if\s*\(\s*\$_POST\s*\)\s*\{[^}]*isRegistrationAllowed~s', $registerCode),
    'the guard appears to be nested inside the POST branch'
);

ok(
    'the gate exits after rendering the 403 page',
    (bool) preg_match('~isRegistrationAllowed\s*\(\s*\)\s*\)\s*\{.*?exit\s*\(\s*\)\s*;~s', $registerCode),
    'no exit() after the guard block'
);

ok(
    'the 403 page offers a way back to the login page',
    (bool) preg_match('~href\s*=\s*["\']/login["\']~', $registerCode),
    'no /login link in register.php'
);

// The gate must not depend on a JS check: the 403 body must be
// server-rendered markup, not a script that hides the form.
ok(
    'the 403 gate is server-side, not a JavaScript-only check',
    (bool) preg_match('~Registration is closed~i', $registerCode)
    && !preg_match('~if\s*\(\s*isRegistrationAllowed\s*\(\s*\)\s*\)\s*\{\s*\n?\s*document~', $registerCode),
    'no server-rendered "Registration is closed" message found'
);

// =====================================================================
// C. login.php — divider + button hidden together
// =====================================================================
echo "## C. login.php hides the register CTA\n";

$loginSrc = read_source($root . '/login.php');
$loginCode = strip_php_comments($loginSrc);

ok(
    'login.php gates the register CTA on isRegistrationAllowed()',
    (bool) preg_match('~if\s*\(\s*isRegistrationAllowed\s*\(\s*\)\s*\)\s*:~', $loginCode),
    'no isRegistrationAllowed() conditional found'
);

// The divider and the button must live INSIDE the same conditional,
// otherwise a closed installation shows an orphaned "New here?".
$dividerPos = strpos($loginCode, 'auth-card__divider');
$btnPos = strpos($loginCode, 'href="/register"');
$condPos = strpos($loginCode, 'isRegistrationAllowed()');
$endPos = strpos($loginCode, 'endif', $condPos === false ? 0 : $condPos);

ok(
    'the "New here?" divider and the register button are both present',
    $dividerPos !== false && $btnPos !== false,
    'divider at ' . var_export($dividerPos, true) . ', button at ' . var_export($btnPos, true)
);

ok(
    'the divider AND the register button sit inside the same conditional',
    $dividerPos !== false && $btnPos !== false && $condPos !== false && $endPos !== false
        && $condPos < $dividerPos && $dividerPos < $btnPos && $btnPos < $endPos,
    "cond at $condPos, divider at $dividerPos, button at $btnPos, endif at $endPos"
);

ok(
    'login.php keeps a way to reach the login page itself',
    (bool) preg_match('~href\s*=\s*["\']/login["\']~', $loginCode)
    || (bool) preg_match('~action\s*=\s*["\']["\']~', $loginCode),
    'no login form/links found'
);

// =====================================================================
// D. forgot_password.php — only the Register link is hidden
// =====================================================================
echo "## D. forgot_password.php hides only the Register link\n";

$forgotSrc = read_source($root . '/forgot_password.php');
$forgotCode = strip_php_comments($forgotSrc);

ok(
    'forgot_password.php gates a link on isRegistrationAllowed()',
    (bool) preg_match('~if\s*\(\s*isRegistrationAllowed\s*\(\s*\)\s*\):~', $forgotCode),
    'no isRegistrationAllowed() conditional found'
);

ok(
    'forgot_password.php still offers the "Back to Login" link',
    (bool) preg_match('~href\s*=\s*["\']/login["\']~', $forgotCode),
    'the /login link is missing'
);

ok(
    'forgot_password.php still renders the .auth-links row',
    (bool) preg_match('~class\s*=\s*["\']auth-links["\']~', $forgotCode),
    'the .auth-links row is missing'
);

// The Register link must be inside the conditional; the "Back to
// Login" link must be OUTSIDE it (so it still shows when closed).
$forgotRegPos = strpos($forgotCode, 'href="/register"');
$forgotCondPos = strpos($forgotCode, 'isRegistrationAllowed()');
$forgotEndPos = $forgotCondPos === false ? false : strpos($forgotCode, 'endif', $forgotCondPos);
$forgotLoginPos = strpos($forgotCode, 'href="/login"');

ok(
    'the Register link is inside the isRegistrationAllowed() conditional',
    $forgotRegPos !== false && $forgotCondPos !== false && $forgotEndPos !== false
        && $forgotCondPos < $forgotRegPos && $forgotRegPos < $forgotEndPos,
    "cond at $forgotCondPos, register link at $forgotRegPos, endif at $forgotEndPos"
);

ok(
    'the "Back to Login" link is NOT inside the conditional (it always shows)',
    $forgotLoginPos !== false && $forgotCondPos !== false && $forgotEndPos !== false
        && !($forgotCondPos < $forgotLoginPos && $forgotLoginPos < $forgotEndPos),
    "login link at $forgotLoginPos, cond at $forgotCondPos, endif at $forgotEndPos"
);

// =====================================================================
// E. The four config writers
// =====================================================================
echo "## E. config writers declare the constant\n";

// config.php (gitignored, per-deployment) and config.template.php.
foreach (['config.php', 'config.template.php'] as $file) {
    $code = strip_php_comments(read_source($root . '/' . $file));
    ok(
        "$file declares APP_ALLOW_REGISTRATION",
        (bool) preg_match('~const\s+APP_ALLOW_REGISTRATION\s*=~', $code),
        'no const APP_ALLOW_REGISTRATION found'
    );
}

// install.php writes config.php — it must emit the constant too.
$installCode = strip_php_comments(read_source($root . '/install.php'));
ok(
    'install.php writes APP_ALLOW_REGISTRATION into the generated config.php',
    (bool) preg_match('~const\s+APP_ALLOW_REGISTRATION\s*=\\s*true~', $installCode),
    'no "const APP_ALLOW_REGISTRATION = true" writer line found'
);

// docker/entrypoint.sh writes config.php on first boot.
$entrySrc = read_source($root . '/docker/entrypoint.sh');
ok(
    'docker/entrypoint.sh writes APP_ALLOW_REGISTRATION into the generated config.php',
    (bool) preg_match('~const\s+APP_ALLOW_REGISTRATION\s*=\s*\$\{APP_ALLOW_REGISTRATION:-true\};~', $entrySrc),
    'no "const APP_ALLOW_REGISTRATION = ${APP_ALLOW_REGISTRATION:-true};" line found'
);

// The generated literal must be a bare true/false, not a quoted
// string that would need quoting care inside the heredoc.
ok(
    'the entrypoint writes a bare boolean literal, not a quoted string',
    (bool) preg_match('~const\s+APP_ALLOW_REGISTRATION\s*=\s*\$\{APP_ALLOW_REGISTRATION:-true\};~', $entrySrc)
        && !preg_match('~const\s+APP_ALLOW_REGISTRATION\s*=\s*[\'"]~', $entrySrc),
    'the entrypoint constant is not a bare literal'
);

// =====================================================================
// F. The entrypoint keeps its file-exists guard
// =====================================================================
echo "## F. docker/entrypoint.sh keeps its file-exists guard\n";

ok(
    'the entrypoint only writes config.php when the file is absent',
    (bool) preg_match('~if\s*\[\s*!\s*-f\s+"\$CONFIG_FILE"\s*\]~', $entrySrc),
    'the "[ ! -f "$CONFIG_FILE" ]" guard is missing — a hand-edited config.php would be clobbered'
);

// The heredoc write must live INSIDE the file-exists guard. A `cat >`
// that appears before the guard (or with no guard at all) would
// clobber a hand-edited config.php on every container start.
$guardOffset = strpos($entrySrc, 'if [ ! -f "$CONFIG_FILE" ]');
$writeOffset = strpos($entrySrc, 'cat > "$CONFIG_FILE"');
ok(
    'the config.php heredoc write sits inside the file-exists guard',
    $guardOffset !== false && $writeOffset !== false && $writeOffset > $guardOffset,
    "guard at $guardOffset, write at $writeOffset"
);

// =====================================================================
// G. The flag lives ONLY in config.php
// =====================================================================
echo "## G. the flag is a config.php-only constant\n";

$composeSrc = read_source($root . '/docker-compose.yml');
ok(
    'docker-compose.yml does not map APP_ALLOW_REGISTRATION',
    strpos($composeSrc, 'APP_ALLOW_REGISTRATION') === false,
    'docker-compose.yml mentions APP_ALLOW_REGISTRATION'
);

foreach (['.env', '.env.example'] as $envFile) {
    $envPath = $root . '/' . $envFile;
    if (is_file($envPath)) {
        ok(
            "$envFile does not map APP_ALLOW_REGISTRATION",
            strpos(read_source($envPath), 'APP_ALLOW_REGISTRATION') === false,
            "$envFile mentions APP_ALLOW_REGISTRATION"
        );
    }
}

// The resolver must not read the flag out of the environment.
ok(
    'the resolver does not read getenv()/$_ENV/$_SERVER for the flag',
    !preg_match('~getenv\s*\(\s*[\'"]APP_ALLOW_REGISTRATION~', $functionsCode)
        && !preg_match('~_\$?(ENV|SERVER)\s*\[\s*[\'"]APP_ALLOW_REGISTRATION~', $functionsCode),
    'the resolver reads the flag from the environment'
);

// =====================================================================
// H. Lint every file touched by issue-039
// =====================================================================
echo "## H. Lint the modified PHP files\n";

$lint = (PHP_BINARY !== '' && is_executable(PHP_BINARY)) ? PHP_BINARY : 'php';
foreach ([
    $root . '/includes/functions.php',
    $root . '/register.php',
    $root . '/login.php',
    $root . '/forgot_password.php',
    $root . '/config.php',
    $root . '/config.template.php',
    $root . '/install.php',
    $root . '/_diag.php',
] as $f) {
    $out = shell_exec(escapeshellcmd($lint) . ' -l ' . escapeshellarg($f) . ' 2>&1');
    ok(
        'lint: ' . basename($f),
        is_string($out) && strpos($out, 'No syntax errors detected') !== false,
        trim((string) $out)
    );
}

// =====================================================================
// Report
// =====================================================================
if ($failures) {
    fwrite(STDERR, "FAIL: issue-039 registration-flag regression test\n");
    foreach ($failures as $f) {
        fwrite(STDERR, "  - $f\n");
    }
    exit(1);
}

echo "PASS: issue-039 registration-flag regression test\n";
echo "  - isRegistrationAllowed() exists, defaults permissive, tolerates quoted strings and garbage\n";
echo "  - register.php enforces a 403 for GET and POST, with the gate above the POST branch\n";
echo "  - login.php hides the divider and the register button together\n";
echo "  - forgot_password.php hides only the Register link and keeps \"Back to Login\"\n";
echo "  - config.php, config.template.php, install.php and docker/entrypoint.sh all declare the constant\n";
echo "  - docker/entrypoint.sh keeps its file-exists guard, so a hand-edited config.php survives\n";
echo "  - the flag is a config.php-only constant: no .env and no docker-compose mapping\n";
echo "  - All modified PHP files lint cleanly\n";
exit(0);
