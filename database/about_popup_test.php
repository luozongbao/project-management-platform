<?php
/**
 * Regression test for issue-028 (Application "About" / Version popup +
 * Release-process checklist).
 *
 * Covers all 5 layers of docs/issues/issue-028.md#regression-test-plan:
 *   1. APP_VERSION drift guard — config.php == config.template.php ==
 *      RELEASE.md top entry == README.md:3 banner. Any disagreement fails
 *      with the exact mismatch.
 *   2. install.php emits APP_VERSION via var_export() (matches the
 *      existing writer style from issue-021 §2). The new assertion
 *      lives in database/install_wizard_test.php so a single install
 *      regression surfaces all drift paths at once; here we re-check
 *      the install.php source for the writer line.
 *   3. includes/header.php carries the <details class="app-about">
 *      block in the navbar AND the floating variant for logged-out
 *      pages (login / register / forgot / reset). Both must have
 *      aria-label="About this application" and the version rendered
 *      through e(APP_VERSION).
 *   4. docs/RELEASE_PROCESS.md exists and contains the 6 step
 *      headings from issue-028 §9.
 *   5. Visual / manual smoke test (not in this file).
 *
 * The Layer-1 drift guard is the contract that protects future
 * releases: every new release must keep the four sites in lock-step
 * or this test will fail.
 */

$repo_root = realpath(__DIR__ . '/..');
$failures  = [];

function ok($label, $ok, $detail = '') {
    global $failures;
    if ($ok) {
        echo "  [PASS] $label\n";
    } else {
        $failures[] = $label . ($detail !== '' ? " — $detail" : '');
        echo "  [FAIL] $label" . ($detail !== '' ? " — $detail" : '') . "\n";
    }
}

/**
 * Strip PHP comments from the source so a "does NOT contain X" check
 * is not fooled by a literal X in a doc-block.
 */
function strip_php_comments($src) {
    $src = preg_replace('~/\*.*?\*/~s', '', $src);
    $src = preg_replace('~//[^\n]*~', '', $src);
    $src = preg_replace('~#[^\n]*~', '', $src);
    return $src;
}

// ---------------------------------------------------------------------------
// 1. APP_VERSION drift guard
// ---------------------------------------------------------------------------
echo "## 1. APP_VERSION drift guard (config.php == config.template.php == RELEASE.md top == README.md:3)\n";

// 1a. config.php
$config_src  = file_get_contents($repo_root . '/config.php');
$config_code = strip_php_comments($config_src);
if (!preg_match("~define\\s*\\(\\s*['\"]APP_VERSION['\"]\\s*,\\s*['\"]([^'\"]+)['\"]~", $config_code, $m)
 && !preg_match("~const\\s+APP_VERSION\\s*=\\s*['\"]([^'\"]+)['\"]~", $config_code, $m)) {
    $config_version = null;
} else {
    $config_version = $m[1];
}
ok(
    'config.php declares APP_VERSION',
    $config_version !== null,
    $config_version === null ? 'no APP_VERSION constant found' : ''
);

// 1b. config.template.php
$template_src  = file_get_contents($repo_root . '/config.template.php');
$template_code = strip_php_comments($template_src);
if (!preg_match("~define\\s*\\(\\s*['\"]APP_VERSION['\"]\\s*,\\s*['\"]([^'\"]+)['\"]~", $template_code, $m)
 && !preg_match("~const\\s+APP_VERSION\\s*=\\s*['\"]([^'\"]+)['\"]~", $template_code, $m)) {
    $template_version = null;
} else {
    $template_version = $m[1];
}
ok(
    'config.template.php declares APP_VERSION',
    $template_version !== null,
    $template_version === null ? 'no APP_VERSION constant found' : ''
);

// 1c. config.php === config.template.php on the APP_VERSION line
ok(
    'config.php and config.template.php agree on APP_VERSION',
    $config_version !== null && $config_version === $template_version,
    $config_version !== null && $template_version !== null && $config_version !== $template_version
        ? "config.php='$config_version' but config.template.php='$template_version'"
        : ''
);

// 1d. RELEASE.md top entry. The format is "## vX.Y.Z — YYYY-MM-DD"
// (em-dash, the same character that has shipped every prior release).
// We match the em-dash byte sequence directly (E2 80 94 in UTF-8) so
// the regex stays PCRE2-clean.
$release_src = file_get_contents($repo_root . '/RELEASE.md');
if (!preg_match('~^##\s+(v\d+\.\d+(?:\.\d+){0,2})\s+\x{2014}\s+\d{4}-\d{2}-\d{2}~um', $release_src, $m)) {
    // Fallback to a more permissive pattern (ASCII dash, etc.) so the
    // test does not break if a future release uses a different glyph.
    if (!preg_match('~^##\s+(v\d+\.\d+(?:\.\d+){0,2})~um', $release_src, $m)) {
        $release_version = null;
    } else {
        $release_version = $m[1];
    }
} else {
    $release_version = $m[1];
}
ok(
    'RELEASE.md top entry is a "## vX.Y.Z" heading',
    $release_version !== null,
    $release_version === null ? 'no ## vX.Y.Z heading found at the top of RELEASE.md' : ''
);
ok(
    'RELEASE.md top entry matches config.php APP_VERSION',
    $release_version !== null && $config_version !== null && $release_version === $config_version,
    $release_version !== null && $config_version !== null && $release_version !== $config_version
        ? "RELEASE.md='$release_version' but config.php='$config_version'"
        : ''
);

// 1e. README.md:3 banner (we look for the "Latest release:" line, not the
// hard-coded line 3, because the banner can legitimately wrap across lines
// if the version string is longer; the test is the contract, not the byte
// offset).
$readme_src = file_get_contents($repo_root . '/README.md');
$readme_first = implode("\n", array_slice(explode("\n", $readme_src), 0, 10));
if (!preg_match('~Latest release:\*\*\s*(v\d+\.\d+(?:\.\d+){0,2})~', $readme_first, $m)) {
    $readme_version = null;
} else {
    $readme_version = $m[1];
}
ok(
    'README.md banner declares "Latest release: vX.Y.Z"',
    $readme_version !== null,
    $readme_version === null ? 'no "Latest release: vX.Y.Z" line found in the README banner' : ''
);
ok(
    'README.md banner matches config.php APP_VERSION',
    $readme_version !== null && $config_version !== null && $readme_version === $config_version,
    $readme_version !== null && $config_version !== null && $readme_version !== $config_version
        ? "README.md='$readme_version' but config.php='$config_version'"
        : ''
);

// ---------------------------------------------------------------------------
// 2. install.php emits APP_VERSION via var_export()
// ---------------------------------------------------------------------------
echo "\n## 2. install.php APP_VERSION writer (var_export, issue-021 §2 style)\n";
$install_src  = file_get_contents($repo_root . '/install.php');
$install_code = strip_php_comments($install_src);

ok(
    'install.php declares $current_version',
    (bool)preg_match("~\\\$current_version\\s*=\\s*['\"]([^'\"]+)['\"]~", $install_code, $cm)
);
if (preg_match("~\\\$current_version\\s*=\\s*['\"]([^'\"]+)['\"]~", $install_code, $cm)) {
    $current_version_value = $cm[1];
    ok(
        'install.php $current_version matches config.php APP_VERSION',
        $current_version_value === $config_version,
        "install.php='$current_version_value' but config.php='$config_version'"
    );
}
ok(
    'install.php uses var_export() for APP_VERSION (drift guard)',
    (bool)preg_match('~APP_VERSION\s*=\s*"\s*\.\s*var_export\(~', $install_code)
);

// ---------------------------------------------------------------------------
// 3. includes/header.php About popup markup
// ---------------------------------------------------------------------------
echo "\n## 3. includes/header.php About popup markup\n";
$header_src  = file_get_contents($repo_root . '/includes/header.php');
$header_code = strip_php_comments($header_src);

ok(
    'header.php has <details class="app-about"> block (navbar variant)',
    strpos($header_src, '<details class="app-about"') !== false
);
ok(
    'header.php has floating variant for logged-out pages (issue-028 §4.1)',
    strpos($header_src, '<details class="app-about app-about--floating"') !== false
);
ok(
    'header.php About trigger uses aria-label="About this application"',
    (bool)preg_match('~aria-label="About this application"~', $header_src)
);
ok(
    'header.php renders APP_VERSION through e() (no raw $APP_VERSION)',
    strpos($header_src, 'e(APP_VERSION)') !== false
        && !preg_match('~<\?=\s*APP_VERSION\b(?!_)~', $header_src)
);
ok(
    'header.php floating variant is wrapped in !isLoggedIn() guard',
    (bool)preg_match(
        '~<\?php\s+if\s*\(\s*!\s*isLoggedIn\(\)\s*\)\s*:\s*\?>\s*<details class="app-about app-about--floating"~s',
        $header_src
    )
);
ok(
    'header.php Close button uses the existing onclick=this.closest pattern',
    strpos($header_src, "this.closest('details').open=false") !== false
);

// ---------------------------------------------------------------------------
// 3b. Regression nets for the tester-note-001 visual bugs
//     (display: inline-flex on the navbar + client-header rules, and
//     a colour override on the floating trigger so the ? glyph is
//     visible on light auth-page backgrounds).
// ---------------------------------------------------------------------------
echo "\n## 3b. tester-note-001 regression nets (CSS display + colour)\n";

$style_css_src  = file_get_contents($repo_root . '/assets/css/style.css');
$client_css_src = file_get_contents($repo_root . '/client/assets/client.css');

// For the two "display: inline-flex" rules, the test anchors on a
// comment that contains "tester-note-001" anywhere within the rule
// body. The comments live INSIDE the rule (not before the selector)
// because the rule has multiple properties and we want the marker
// next to the property it documents.
ok(
    'style.css .app-about rule sets display: inline-flex (navbar layout)',
    (bool)preg_match(
        '~\.app-about\s*\{[^}]*tester-note-001[^}]*display:\s*inline-flex~s',
        $style_css_src
    )
);
ok(
    'client.css .client-header-right .app-about rule sets display: inline-flex (client dashboard layout)',
    (bool)preg_match(
        '~\.client-header-right\s+\.app-about\s*\{[^}]*tester-note-001[^}]*display:\s*inline-flex~s',
        $client_css_src
    )
);
ok(
    'style.css .app-about--floating .app-about-trigger overrides the dark-navbar colour (auth-page visibility)',
    (bool)preg_match(
        '~\.app-about--floating\s+\.app-about-trigger\s*\{[^}]*color:\s*#007bff~s',
        $style_css_src
    )
);
// The init lines are: $code  = ''; and $error = '';
// (Both are needed: a plain GET with no ?code= and no POST falls
// through to the form render without entering either branch, so
// the template reads `$code` and `$error` without the variables
// being in scope — without the initialisers, PHP 8.3 emits
// "Undefined variable" warnings into the response body.) We strip
// PHP comments first so a literal in a doc-block does not fool
// the assertion, and we accept either '' or "" as the empty
// string literal.
//
// v2.0 (issue-035 C.2): this page moved from index.php to
// includes/landing.php when index.php became the front controller.
// The assertion follows the code, which is the whole point — the
// initialisers still have to be there or the landing form warns.
$landing_stripped = strip_php_comments(file_get_contents($repo_root . '/includes/landing.php'));
ok(
    'includes/landing.php declares $error at function scope (no undefined-variable warning on GET)',
    (bool)preg_match('~\$error\s*=\s*(["\'])\1\s*;~', $landing_stripped)
);
ok(
    'includes/landing.php declares $code at function scope (no undefined-variable warning on GET)',
    (bool)preg_match('~\$code\s*=\s*(["\'])\1\s*;~', $landing_stripped)
);

// ---------------------------------------------------------------------------
// 3c. Regression nets for the tester-note-002 browser bugs
//     (bug 5: parse-error-from-<?php-in-//-comment, bug 6: Close button
//     re-toggles the <details> due to event bubbling, bug 7: the "View
//     full changelog" link 404s because nginx blocks .md / RELEASE.md).
// ---------------------------------------------------------------------------
echo "\n## 3c. tester-note-002 regression nets (parse error + Close bubble + 404 link)\n";

// Bug 5: the landing page source must not embed PHP open/close tags
// inside any // line comment. PHP's tokenizer (php -l) treats
// every PHP open tag as a script start regardless of comment
// context, so a // line that "demonstrates" a control-flow
// pattern by literally including a PHP open tag and a closing
// tag inside the comment can change the file's semantics and
// introduce a parse error when later edits flip the variable
// in/out of scope. The exact pattern to avoid is a // line
// containing the four-character PHP open tag and/or the two-
// character PHP close tag. We assert both halves of the
// dangerous pattern are absent from any // line, then run
// `php -l` as a final safety net.
//
// v2.0: follows the page to includes/landing.php, and additionally
// lints the new front controller (index.php) which now sits in
// front of it.
$landing_full_src = file_get_contents($repo_root . '/includes/landing.php');
$line_comment_with_php_open  = (bool)preg_match_all('~//[^\n]*<\?php~', $landing_full_src, $_m1);
$line_comment_with_php_close = (bool)preg_match_all('~//[^\n]*\?>~',   $landing_full_src, $_m2);
ok(
    'includes/landing.php does NOT embed <?php inside any // line comment (tester-note-002 bug 5)',
    !$line_comment_with_php_open && !$line_comment_with_php_close,
    'a // line containing <?php or ?> changes the file semantics — php -l will catch it but the regression test should too'
);
foreach (['/includes/landing.php', '/index.php'] as $lint_target) {
    $lint_out = [];
    $lint_rc  = 0;
    exec('php -l ' . escapeshellarg($repo_root . $lint_target) . ' 2>&1', $lint_out, $lint_rc);
    ok(
        $lint_target . ' lints cleanly via `php -l` (no parse errors)',
        $lint_rc === 0,
        $lint_rc !== 0 ? implode("\n", $lint_out) : ''
    );
}

// Bug 6: both Close buttons in includes/header.php must call
// event.stopPropagation() before closing the <details>. Without it,
// the click bubbles up to the native <details> toggle handler and
// the popup re-opens immediately, breaking the PM dashboard dropdown
// (tester-note-002 bug 2.2). We require both the literal substring
// "stopPropagation" and exactly two "this.closest('details').open=false"
// occurrences (one per popup, navbar + floating).
$close_button_count = substr_count($header_src, "this.closest('details').open=false");
ok(
    'header.php has exactly two Close buttons (one per About popup)',
    $close_button_count === 2,
    "expected 2, got $close_button_count"
);
ok(
    'header.php Close buttons call event.stopPropagation() so the <details> does not re-toggle (tester-note-002 bug 6)',
    strpos($header_src, 'stopPropagation') !== false && $close_button_count === 2,
    'both lines 50 and 87 must include `event.stopPropagation();` before the close call'
);

// Bug 7: the About popup must NOT link to RELEASE.md, README.md, or
// any .md file. The issue-020 nginx hardening
// (docker/nginx.conf:22, :28) returns 404 for /RELEASE.md and
// any .md request. The popup used to carry a "View full changelog"
// link to RELEASE.md which 404'd in production. The fix replaces
// the link with an inline summary rendered via getLatestReleaseSummary()
// in includes/functions.php. We assert no .md / RELEASE.md / README.md
// href remains in the popup markup, and that getLatestReleaseSummary()
// is invoked at least once (and not removed by accident later).
$header_src_no_comments = strip_php_comments($header_src);
$has_md_href = preg_match('~href=[\\\'"][^\\\'"]*\\.md[\\\'"]~', $header_src_no_comments);
ok(
    'header.php About popup does NOT link to any .md file (tester-note-002 bug 7)',
    !$has_md_href,
    $has_md_href ? 'found a .md href in the About popup — nginx blocks it with 404' : ''
);
ok(
    'header.php About popup does NOT link to RELEASE.md or README.md',
    strpos($header_src_no_comments, 'href="RELEASE.md"') === false
        && strpos($header_src_no_comments, 'href="README.md"') === false,
    'the changelog link is now rendered inline via getLatestReleaseSummary()'
);
ok(
    'header.php About popup invokes getLatestReleaseSummary() for the inline release summary',
    substr_count($header_src, 'getLatestReleaseSummary(') >= 2,
    'both popups (navbar + floating) should call the helper'
);

// Bug 7 (cont.): the helper itself must be defined and produce a
// non-empty string that contains the APP_VERSION prefix, so we know
// the runtime path is wired up. We require_once the functions file
// in isolation (the test process is not bootstrapped for the web app)
// and call the helper directly.
$funcs_path = $repo_root . '/includes/functions.php';
if (!defined('APP_VERSION')) {
    // We do NOT include config.php because it tries to start a
    // session / connect to the DB. The functions file does not
    // require config.php; APP_VERSION is read via the const fallback
    // path in getLatestReleaseSummary(). We simulate it by
    // pre-declaring the constant via runkit-free define().
    define('APP_VERSION', 'v1.8.0');
}
// Suppress any unrelated output (the helper itself echoes nothing).
ob_start();
$summary = null;
$ok_summary = false;
try {
    require_once $funcs_path;
    if (function_exists('getLatestReleaseSummary')) {
        $summary = getLatestReleaseSummary();
        $ok_summary = is_string($summary) && $summary !== '' && strpos($summary, 'v1.8.0') === 0;
    }
} catch (Throwable $t) {
    $summary = null;
}
ob_end_clean();
ok(
    'getLatestReleaseSummary() returns a non-empty string starting with APP_VERSION',
    $ok_summary,
    $summary === null ? 'helper missing or threw' : "got: $summary"
);
ok(
    'getLatestReleaseSummary() output does NOT include literal markdown asterisks (**)',
    $ok_summary && strpos($summary, '**') === false,
    $summary === null ? 'helper missing' : "got: $summary"
);

// ---------------------------------------------------------------------------
// 4. docs/RELEASE_PROCESS.md exists with the 6 step headings
// ---------------------------------------------------------------------------
echo "\n## 4. docs/RELEASE_PROCESS.md (6-step release checklist)\n";
$process_path = $repo_root . '/docs/RELEASE_PROCESS.md';
ok(
    'docs/RELEASE_PROCESS.md exists',
    file_exists($process_path)
);
if (file_exists($process_path)) {
    $process_src = file_get_contents($process_path);
    $expected_steps = [
        '1. Bump the `APP_VERSION` constant',
        '2. Append the changelog entry to `RELEASE.md`',
        '3. Update the README banner',
        '4. Update documentation and inline attribution',
        '5. Run the full test suite',
        '6. Smoke-test the About popup in the browser',
    ];
    foreach ($expected_steps as $i => $heading) {
        ok(
            "docs/RELEASE_PROCESS.md has step heading: \"$heading\"",
            strpos($process_src, $heading) !== false
        );
    }
    // The process doc must cross-reference the drift guard so future
    // contributors understand the contract.
    ok(
        'docs/RELEASE_PROCESS.md mentions the drift guard / regression test',
        strpos($process_src, 'about_popup_test.php') !== false
            || strpos($process_src, 'drift guard') !== false
            || strpos($process_src, 'drift-guard') !== false
    );
}

// ---------------------------------------------------------------------------
// Summary
// ---------------------------------------------------------------------------
echo "\n";
if ($failures) {
    echo "FAILED: " . count($failures) . " assertion(s) failed\n";
    foreach ($failures as $f) echo "  - $f\n";
    exit(1);
}
echo "All assertions passed.\n";
exit(0);
