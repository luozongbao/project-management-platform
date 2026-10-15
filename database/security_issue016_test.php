<?php
/**
 * Regression test for issue-016 (security hardening).
 *
 * Covers three concerns from docs/issues/issue-016.md:
 *   A. CSRF gate + hidden CSRF input on every mutating endpoint.
 *   B. Generic, non-leaking DB error copy in catch blocks.
 *   C. Email HTML / header injection via user-controlled display name.
 *
 * Run from the project root:
 *   php database/security_issue016_test.php
 */

require_once __DIR__ . '/../includes/functions.php';

$failures = [];
$pass     = 0;

function check($label, $ok, $detail = '') {
    global $failures, $pass;
    if ($ok) {
        $pass++;
        echo "  [PASS] $label\n";
    } else {
        $failures[] = $label . ($detail !== '' ? " — $detail" : '');
        echo "  [FAIL] $label" . ($detail !== '' ? " — $detail" : '') . "\n";
    }
}

// ----------------------------------------------------------------------
// Section A + B: file-grep checks against the modified PHP files
// ----------------------------------------------------------------------
$repo_root = realpath(__DIR__ . '/..');

$mutating_pages = [
    'contact_edit.php',
    'task_edit.php',
    'profile.php',
];

$ajax_endpoints = [
    'ajax/complete_task.php',
    'ajax/update_task_status.php',
    'ajax/update_task_progress.php',
    'ajax/get_project_contacts.php',
];

echo "\n## A. CSRF gate + hidden CSRF input on mutating endpoints\n";

foreach ($mutating_pages as $rel) {
    $path = $repo_root . '/' . $rel;
    check(
        "$rel contains validateCsrfToken() gate",
        is_file($path) && preg_match('/validateCsrfToken\s*\(\s*\$_POST\s*\[\s*[\'"]csrf_token[\'"]\s*\]\s*\?\?\s*null\s*\)/', file_get_contents($path)) === 1
    );
    check(
        "$rel renders hidden csrf_token input",
        is_file($path) && preg_match('/<input\s+type=[\'"]hidden[\'"]\s+name=[\'"]csrf_token[\'"]/', file_get_contents($path)) === 1
    );
}

echo "\n## B. Generic, non-leaking DB error copy\n";

$page_error_pattern = '/\$errors\s*\[\]\s*=\s*[^\n]*?(Failed to|please try again|contact your administrator)/i';
$ajax_error_patterns = [
    'ajax/complete_task.php'         => '/[\'"]message[\'"]\s*=>\s*[\'"]Failed to complete the task\. Please try again\.[\'"]/i',
    'ajax/update_task_status.php'    => '/[\'"]error[\'"]\s*=>\s*[\'"]Failed to update the task status\. Please try again\.[\'"]/i',
    'ajax/update_task_progress.php'  => '/[\'"]error[\'"]\s*=>\s*[\'"]Failed to update the task progress\. Please try again\.[\'"]/i',
    'ajax/get_project_contacts.php'  => '/[\'"]error[\'"]\s*=>\s*[\'"]Failed to load project contacts\. Please try again\.[\'"]/i',
];

foreach ($mutating_pages as $rel) {
    $path = $repo_root . '/' . $rel;
    $src  = is_file($path) ? file_get_contents($path) : '';
    // The catch block must NOT echo the raw PDO message into $errors[].
    check(
        "$rel catch block does NOT echo \$e->getMessage() into \$errors",
        $src !== '' && !preg_match('/catch\s*\(\s*Exception\s+\$e\s*\)\s*\{[^{}]*\$errors\s*\[\]\s*=\s*[^\n]*\$e->getMessage/s', $src),
        $src === '' ? 'file not found' : ''
    );
    check(
        "$rel catch block adds a generic, non-leaking error message",
        $src !== '' && preg_match($page_error_pattern, $src) === 1
    );
}

foreach ($ajax_endpoints as $rel) {
    $path = $repo_root . '/' . $rel;
    $src  = is_file($path) ? file_get_contents($path) : '';
    check(
        "$rel catch block does NOT echo \$e->getMessage() to the client",
        $src !== '' && !preg_match('/catch\s*\(\s*Exception\s+\$e\s*\)\s*\{[^{}]*echo\s+json_encode\([^{}]*\$e->getMessage/s', $src),
        $src === '' ? 'file not found' : ''
    );
    if (isset($ajax_error_patterns[$rel])) {
        check(
            "$rel catch block emits the issue-016 generic error copy",
            $src !== '' && preg_match($ajax_error_patterns[$rel], $src) === 1
        );
    }
}

// ----------------------------------------------------------------------
// Section C: EmailService renderers must neutralise HTML / control-char
// injection in the user's display name.
// ----------------------------------------------------------------------
echo "\n## C. Email HTML / header injection\n";

require_once $repo_root . '/includes/EmailService.php';

// APP_NAME is required by the templates but not defined here; the
// renderers concatenate it, so stub a constant in the same scope.
if (!defined('APP_NAME')) { define('APP_NAME', 'TestApp'); }

$injection_payloads = [
    '<script>alert(1)</script>',
    '"><img src=x onerror=alert(1)>',
    "Bob & Alice",
    "a]b[c",
    "Line1\nBcc: attacker@example.com",
    "Line1\r\nBcc: attacker@example.com",
    "Name\x07with\x00null",
    str_repeat('A', 250), // over-length
    '',                   // empty
];

$marker_link = 'https://example.test/reset?token=abc123';

foreach ($injection_payloads as $i => $payload) {
    $reset  = EmailService::renderPasswordResetBody($payload, $marker_link);
    $welcome = EmailService::renderWelcomeBody($payload, $marker_link);

    // 1. The raw HTML body must not contain a literal unescaped
    //    <script> tag or a javascript: pseudo-URL coming from the
    //    name slot. (The entity-escaped form, &lt;script&gt;, IS safe
    //    — it renders as visible text — so we look for the literal
    //    angle-bracket form.)
    $reset_raw  = $reset['html'];
    $welcome_raw = $welcome['html'];

    check(
        "renderPasswordResetBody #$i: no literal <script> in raw HTML",
        stripos($reset_raw, '<script') === false
    );
    check(
        "renderPasswordResetBody #$i: no javascript: in raw HTML",
        stripos($reset_raw, 'javascript:') === false
    );
    check(
        "renderWelcomeBody #$i: no literal <script> in raw HTML",
        stripos($welcome_raw, '<script') === false
    );
    check(
        "renderWelcomeBody #$i: no javascript: in raw HTML",
        stripos($welcome_raw, 'javascript:') === false
    );

    // 2. The plaintext body must not carry ASCII control characters in
    //    the portion that came from the name (header-injection guard).
    check(
        "renderPasswordResetBody #$i: plaintext has no control chars",
        !preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $reset['plain'])
    );
    check(
        "renderWelcomeBody #$i: plaintext has no control chars",
        !preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $welcome['plain'])
    );

    // 3. After sanitisation, the visible greeting in the HTML body
    //    must be the fallback "there" when the input is empty / made of
    //    only control chars.
    if ($payload === '' || trim(preg_replace('/[\x00-\x1F\x7F]+/u', '', $payload)) === '') {
        check(
            "renderPasswordResetBody #$i: empty name falls back to 'there'",
            strpos($reset['html'], '<strong>there</strong>') !== false
        );
    }
}

// ----------------------------------------------------------------------
// Summary
// ----------------------------------------------------------------------
$total = $pass + count($failures);
echo "\n----------------------------------------\n";
echo "Issue-016 security regression: $pass/$total checks passed.\n";
if ($failures) {
    echo "FAILURES:\n";
    foreach ($failures as $f) {
        echo "  - $f\n";
    }
    exit(1);
}
echo "Issue-016 security regression test passed.\n";
exit(0);
