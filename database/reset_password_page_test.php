<?php
/**
 * Regression test — reset_password.php page rendering (issue-055 bug 4 class).
 *
 * Two defects lived in reset_password.php, both of them invisible to a
 * normal "does the page load" check:
 *
 *   1. STYLE BUG — the v2 shell flags were assigned OUTSIDE PHP mode.
 *      A stray close tag closed PHP early, so
 *          $use_v2_shell = true; $content_bleed = true;
 *      was echoed into the HTML as literal text and never executed.
 *      Because includes/header.php only links v2-components.css when
 *      $use_v2_shell is truthy, the page rendered without the v2
 *      component layer: .field fell back to display:block (label
 *      inline beside a 166px input) and .input lost its full-width
 *      rule. This is the same defect class as the forgot-password page.
 *
 *   2. CONSOLE ERROR — "Uncaught TypeError: Cannot read properties of
 *      null (reading 'addEventListener')". The inline script attached
 *      an 'input' listener to #password / #confirm_password without a
 *      null check, but those elements only exist when the token is
 *      valid. On an invalid/expired link the page renders the
 *      "Invalid Reset Link" branch, so the script dereferenced null.
 *
 * The test is SOURCE-level for the flags (a token scan proves the
 * assignments happen in PHP mode and are not echoed as HTML) and
 * RENDER-level for the stylesheet (the served page must link
 * v2-components.css) and SOURCE-level for the JS guard.
 *
 * Run: docker compose exec -T app php /var/www/html/database/reset_password_page_test.php
 */

$root = dirname(__DIR__);
$pass = 0;
$fail = 0;
$failures = [];

function ok(string $msg): void {
    global $pass, $fail;
    echo "  [PASS] $msg\n";
    $pass++;
}

function bad(string $msg): void {
    global $fail, $failures;
    echo "  [FAIL] $msg\n";
    $fail++;
    $failures[] = $msg;
}

function check(bool $cond, string $okMsg, string $failMsg): void {
    if ($cond) {
        ok($okMsg);
    } else {
        bad($failMsg);
    }
}

// ---------------------------------------------------------------- A. source
echo "\n== A. the v2 shell flags are assigned in PHP mode (not echoed as HTML) ==\n";

$file = $root . '/reset_password.php';
check(is_file($file), 'reset_password.php exists', 'reset_password.php is missing');
$src = file_get_contents($file);

// A1 — token scan. This is the authoritative check: token_get_all()
// tells us exactly which bytes the PHP engine treats as code and which
// bytes it treats as inline HTML. A leaked assignment shows up as a
// T_INLINE_HTML token containing the flag assignment.
$tokens = token_get_all($src);
$inlineHtml = '';
$phpAssigned = [];
$mode = 'php';
foreach ($tokens as $i => $t) {
    if (!is_array($t)) {
        continue;
    }
    if ($t[0] === T_OPEN_TAG) {
        $mode = 'php';
        continue;
    }
    if ($t[0] === T_CLOSE_TAG) {
        $mode = 'html';
        continue;
    }
    if ($t[0] === T_INLINE_HTML) {
        $inlineHtml .= $t[1];
        continue;
    }
    if ($t[0] === T_COMMENT || $t[0] === T_DOC_COMMENT) {
        continue;
    }
    if ($mode === 'php' && $t[0] === T_VARIABLE
        && in_array($t[1], ['$use_v2_shell', '$content_bleed'], true)) {
        // An assignment target is followed by '='.
        $j = $i + 1;
        while ($j < count($tokens) && is_array($tokens[$j])
               && $tokens[$j][0] === T_WHITESPACE) {
            $j++;
        }
        if (($tokens[$j] ?? null) === '=') {
            $phpAssigned[] = $t[1];
        }
    }
}

check(
    in_array('$use_v2_shell', $phpAssigned, true),
    '$use_v2_shell is assigned in PHP mode',
    '$use_v2_shell is NOT assigned in PHP mode (the v2 component layer would never link)'
);
check(
    in_array('$content_bleed', $phpAssigned, true),
    '$content_bleed is assigned in PHP mode',
    '$content_bleed is NOT assigned in PHP mode'
);

// A2 — the flag assignment must not also appear as literal HTML.
check(
    stripos($inlineHtml, '$use_v2_shell') === false
    && stripos($inlineHtml, '$content_bleed') === false,
    'the flag assignments are NOT echoed into the HTML as literal text',
    'the flag assignments ARE echoed into the HTML as literal text (stray close tag)'
);

// A3 — no bare PHP-looking statement may appear in the HTML output.
check(
    !preg_match('/^\s*\$use_v2_shell\s*=/m', $inlineHtml),
    'no bare "$use_v2_shell =" statement in the inline HTML',
    'a bare "$use_v2_shell =" statement is present in the inline HTML'
);

// A4 — the classic trap: a literal PHP close tag inside a comment
// terminates PHP mode. A close tag may be written directly or split
// across a string concatenation (e.g. '?' . '>'), so the check looks
// for a '?' immediately followed (ignoring whitespace, quotes and a
// concatenation dot) by a '>'.
//
// NOTE: the close tag itself is built from two parts. Writing it
// literally inside this file would terminate PHP mode right here.
$commentHasCloseTag = false;
$closeTag = '?' . '>';
foreach ($tokens as $t) {
    if (!is_array($t) || ($t[0] !== T_COMMENT && $t[0] !== T_DOC_COMMENT)) {
        continue;
    }
    // A close tag may be written directly or split across a string
    // concatenation (e.g. '?' . '>'). Normalize by removing quotes,
    // concatenation dots and whitespace, then look for the close tag.
    $normalized = preg_replace('/[\'"\s.]+/', '', $t[1]);
    if (strpos($normalized, $closeTag) !== false) {
        $commentHasCloseTag = true;
        break;
    }
}
check(
    !$commentHasCloseTag,
    'no PHP close tag appears inside a comment (it would terminate PHP mode)',
    'a PHP close tag appears inside a comment — it terminates PHP mode and leaks the rest of the comment into the HTML'
);

// A5 — the header include must come AFTER the flag assignments.
$flagPos = strpos($src, '$use_v2_shell = true;');
$headerPos = strpos($src, "include 'includes/header.php'");
check(
    $flagPos !== false && $headerPos !== false && $flagPos < $headerPos,
    'the flags are set BEFORE includes/header.php is included',
    'the flags are not set before includes/header.php is included'
);

// ------------------------------------------------------- B. the JS null guard
echo "\n== B. the inline script null-guards the password fields ==\n";

check(
    strpos($src, "document.getElementById('password')") !== false,
    "the script looks up #password",
    "the script no longer looks up #password"
);
check(
    strpos($src, "document.getElementById('confirm_password')") !== false,
    "the script looks up #confirm_password",
    "the script no longer looks up #confirm_password"
);

// The guard must exist BEFORE the addEventListener calls.
$guardPos = strpos($src, 'if (!password || !confirmPassword)');
$listenerPos = strpos($src, "password.addEventListener('input'");
check(
    $guardPos !== false,
    'the script null-guards the fields (if (!password || !confirmPassword))',
    'the script does NOT null-guard the fields — it will throw on an invalid/expired link'
);
check(
    $guardPos !== false && $listenerPos !== false && $guardPos < $listenerPos,
    'the null guard runs BEFORE addEventListener is called',
    'the null guard does not run before addEventListener is called'
);
check(
    strpos($src, 'return;') !== false,
    'the null guard returns early',
    'the null guard does not return early'
);

// ------------------------------------------------------- C. rendered output
echo "\n== C. the served page links the v2 component stylesheet ==\n";

// The test usually runs INSIDE the app container, where the nginx
// server is reachable as "web" (the compose service name) and not as
// "localhost" (that is the php-fpm container's own loopback). Probe
// the candidates and use the first one that answers.
$base = getenv('BASE_URL') ?: '';
if ($base === '') {
    foreach (['http://web', 'http://localhost', 'http://127.0.0.1:80'] as $candidate) {
        $probeCtx = stream_context_create([
            'http' => ['timeout' => 5, 'ignore_errors' => true],
        ]);
        if (@file_get_contents("$candidate/reset-password", false, $probeCtx) !== false) {
            $base = $candidate;
            break;
        }
    }
}
if ($base === '') {
    $base = 'http://web';
}

$ctx = stream_context_create([
    'http' => ['timeout' => 10, 'ignore_errors' => true],
]);
$html = @file_get_contents(
    "$base/reset-password?token=render_check_invalid_token_000",
    false,
    $ctx
);

if ($html === false) {
    bad("could not fetch $base/reset-password (is the app running?)");
} else {
    ok("fetched $base/reset-password");

    check(
        strpos($html, 'v2-components.css') !== false,
        'the served page links v2-components.css',
        'the served page does NOT link v2-components.css (the style bug is still present)'
    );
    check(
        strpos($html, '$use_v2_shell') === false
        && strpos($html, '$content_bleed') === false,
        'the served page contains no leaked PHP source text',
        'the served page still contains leaked PHP source text'
    );
    check(
        strpos($html, 'pages/auth.css') !== false,
        'the served page links pages/auth.css',
        'the served page does NOT link pages/auth.css'
    );
}

// ---------------------------------------------------------------- summary
echo "\n" . str_repeat('=', 70) . "\n";
echo "  SUMMARY: $pass PASS / $fail FAIL\n";
echo str_repeat('=', 70) . "\n";

if ($fail > 0) {
    echo "\nFailed checks:\n";
    foreach ($failures as $f) {
        echo "  - $f\n";
    }
    exit(1);
}

echo "\nAll checks passed.\n";
exit(0);
