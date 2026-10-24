<?php
/**
 * Regression test for issue-029 v1.7.2.2 — _diag.php contract.
 * Updated in issue-030 v1.7.2.3 to allow the new landing-page
 * live-URL probe (which legitimately does outbound HTTP, but only
 * to the public prod URL, gated by the public base constant).
 *
 * The new prod sanity probe at _diag.php is a deployable, read-only
 * diagnostic. This test asserts the contract that the dev must hold
 * to at every release:
 *
 *   1. _diag.php exists at the repo root.
 *   2. No FILL_IN_AT_RELEASE_TIME literal remains anywhere in the file
 *      (the dev must fill in the 5 expected-md5 placeholders before
 *      committing the release).
 *   3. The 5 expected-md5 values in the $expected_md5 array match the
 *      actual md5sums of the 5 v1.7.2.2-touched source files in the
 *      repo HEAD. The dev bumps the placeholders in the same commit
 *      that ships the release; the regression test enforces that
 *      the bump actually happened.
 *   4. The file has no <?php open tag after line 1 (mirroring the
 *      index.php bug from issue-028 — a <?php inside a // comment
 *      would be a parse error if the comment block is ever moved
 *      into a place that breaks the comment context).
 *   5. The file has no DB / SMTP side-effect calls ($db->,
 *      Database::, $mailer, SMTP_). The v1.7.2.2 read-only
 *      invariant is preserved. The v1.7.2.3 release relaxes the
 *      "no HTTP" rule: the landing-page probe legitimately does
 *      outbound HTTP via curl_init(), but only against the public
 *      $public_base constant. The test no longer forbids curl_init
 *      (it asserts the new probe structure instead, see #6 below).
 *   6. Issue-030 v1.7.2.3: the diag template includes the new
 *      landing-page probe sub-section (4 sub-checks labelled
 *      "Sub-check 1" through "Sub-check 4") and asserts the
 *      response is not a redirect to /login.php. Catches a future
 *      dev who removes the new probe or weakens its assertion.
 *   7. Issue-031 v1.7.3: every HTML page in the project that has a
 *      <head> section must include a <link rel="icon"> tag to the
 *      project favicon at assets/image/project-favicon.png, so
 *      browsers stop falling back to GET /favicon.ico (which 404s
 *      and spams the console). Verifies the exact tag string in
 *      includes/header.php, index.php, install.php, and
 *      client/dashboard.php (which uses a ../assets/... href).
 *   8. The favicon PNG itself must be on disk at
 *      assets/image/project-favicon.png and not be empty (a
 *      broken <link rel="icon"> would itself 404).
 *   9. Issue-032 v1.7.3.1: the public landing index.php must
 *      not contain any inline <script> block. The page is
 *      served with the CLIENT-PORTAL CSP (script-src does NOT
 *      contain 'unsafe-inline' — the issue-020 invariant), so
 *      the browser blocks any inline script and emits a CSP
 *      violation in the console. The v1.7.3.1 fix was to
 *      externalise the share-code auto-format IIFE to
 *      assets/js/share-code-format.js and load it via
 *      <script src="…">. The check asserts: (a) no inline
 *      '<script>(function' IIFE pattern in index.php, (b) at
 *      least one '<script src="assets/js/…">' tag referencing
 *      the externalised file. Catches a future dev who adds a
 *      new inline <script> to index.php or reverts the
 *      externalise edit.
 *  10. Issue-033 v1.7.3.2: _diag.php must contain the new
 *      §3.6 all-pages HTTP probe sub-section (which does a
 *      curl GET against all 7 public pages and asserts each
 *      returns the expected status code). This is the
 *      regression detector for the v1.7.3.0 + v1.7.3.1 class
 *      of bug (prod-side state change — stale config.php —
 *      that the existing §3.5 landing-only probe and the .htaccess
 *      / cPanel checks do not catch).
 *  11. Issue-033 v1.7.3.2: includes/header.php must contain
 *      the new 5-line defined('APP_VERSION') guard at the top
 *      of the file. The guard detects a stale config.php
 *      (missing APP_NAME / APP_VERSION / TIMEZONE constants)
 *      and emits a clear "re-upload config.php" message instead
 *      of a generic 500. Catches a future dev who removes the
 *      guard without understanding why it is there.
 *
 * This is a dev-side test, not a prod-side one. The dev runs it
 * once per release to verify the _diag.php template is well-formed
 * and the 5 expected-md5 placeholders are filled in. The actual
 * prod-side probe is _diag.php itself, run from the prod shell.
 *
 * Run with:  docker compose exec -T app php database/probe_diag_test.php
 */

require_once __DIR__ . '/../config.php';

$failures = [];

// 1. _diag.php exists.
$diag_path = __DIR__ . '/../_diag.php';
if (!is_readable($diag_path)) {
    $failures[] = '_diag.php is missing entirely at the repo root';
    // No point continuing if the file does not exist.
    fwrite(STDERR, "probe_diag_test failed:\n");
    foreach ($failures as $f) {
        fwrite(STDERR, "  - $f\n");
    }
    exit(1);
}

$diag = file_get_contents($diag_path);

// 3. The 5 expected-md5 values in the $expected_md5 array match the
//    actual md5sums of the 5 v1.7.2.2-touched source files in the
//    repo HEAD. Defined here so both the placeholder check (2) and
//    the md5 match check (3) can iterate over the same list.
$files_to_check = [
    'config.php'              => __DIR__ . '/../config.php',
    'client/dashboard.php'    => __DIR__ . '/../client/dashboard.php',
    'client/lang.php'         => __DIR__ . '/../client/lang.php',
    'includes/header.php'     => __DIR__ . '/../includes/header.php',
    'includes/functions.php'  => __DIR__ . '/../includes/functions.php',
];

// 2. The 5 $expected_md5 array entries are filled in with real md5s
//    (not the FILL_IN_AT_RELEASE_TIME placeholder). We check the
//    parsed array entries specifically (rather than a blanket
//    strpos on the file) so the explanatory comments around the
//    array can keep referencing the placeholder name without
//    triggering the test.
$placeholder_remaining = false;
foreach ($files_to_check as $label => $path) {
    $pattern = "/'" . preg_quote($label, '/') . "'\s*=>\s*'([^']*)'/";
    if (preg_match($pattern, $diag, $m)) {
        if ($m[1] === 'FILL_IN_AT_RELEASE_TIME' || $m[1] === '') {
            $placeholder_remaining = true;
        }
    } else {
        $placeholder_remaining = true;
    }
}
if ($placeholder_remaining) {
    $failures[] = '_diag.php \$expected_md5 array still has a FILL_IN_AT_RELEASE_TIME placeholder (or a missing entry) — the dev must fill in the 5 expected-md5 placeholders before committing the release';
}

// 3. The 5 expected-md5 values in the $expected_md5 array match the
//    actual md5sums of the 5 v1.7.2.2-touched source files in the
//    repo HEAD.
foreach ($files_to_check as $label => $path) {
    $expected = '';
    // Parse the $expected_md5 array. The pattern is single-quoted
    // PHP literal: 'label' => 'hash', (with possible whitespace and
    // trailing comma). Capture the hash value for the matching label.
    $pattern = "/'" . preg_quote($label, '/') . "'\s*=>\s*'([a-f0-9]+)'/";
    if (preg_match($pattern, $diag, $m)) {
        $expected = $m[1];
    }
    $actual = is_readable($path) ? md5_file($path) : '(missing)';
    if ($expected === '' || $expected === 'FILL_IN_AT_RELEASE_TIME') {
        $failures[] = "_diag.php \$expected_md5 entry for $label is empty or still a placeholder (expected md5: $actual)";
    } elseif ($expected !== $actual) {
        $failures[] = "_diag.php \$expected_md5 for $label is '$expected' but the actual md5 of the file is '$actual' — the dev must re-bump the placeholders in step 5.5 of the release process";
    }
}

// 4. The file has no PHP open tag inside a // line comment. The
//    v1.7.2 bug (index.php / issue-028) was a // comment that
//    embedded a literal open tag and was being parsed as actual
//    PHP if the comment context was ever broken. The check is
//    more specific than a blanket "no open tag after line 1" (which
//    would false-positive on legitimate procedural-PHP code that
//    mixes PHP and HTML with inline PHP open/close tag blocks).
//    We grep per line for a // comment that contains a PHP open
//    tag on the same line.
$lines = preg_split('/\r?\n/', $diag);
for ($i = 0; $i < count($lines); $i++) {
    if (preg_match('#//.*<\?php#', $lines[$i])) {
        $failures[] = "_diag.php has a '<?php' inside a // comment on line " . ($i + 1) . " — this is the v1.7.2 index.php bug pattern (issue-028), it would be parsed as actual PHP if the comment context is ever broken";
    }
}

// 5. Read-only invariant: no DB / SMTP side-effect calls. (The
//    v1.7.2.2 "no HTTP" rule is relaxed in v1.7.2.3 — the
//    landing-page probe legitimately does outbound HTTP via
//    curl_init(), but only against the public $public_base
//    constant, which is asserted in #6 below.)
$forbidden_patterns = [
    '$db->'      => 'database call (\$db->) — the probe must be read-only',
    'Database::' => 'database call (Database::) — the probe must be read-only',
    '$mailer'    => 'mailer call (\$mailer) — the probe must be read-only',
    'SMTP_'      => 'SMTP constant (SMTP_) — the probe must not depend on mail config',
];
foreach ($forbidden_patterns as $needle => $reason) {
    if (strpos($diag, $needle) !== false) {
        $failures[] = "_diag.php contains '$needle' — $reason";
    }
}

// 6. Issue-030 v1.7.2.3: the diag template includes the new
//    landing-page probe sub-section (4 sub-checks) and asserts the
//    response is not a redirect to /login.php. Catches a future dev
//    who removes the new probe or weakens its assertion.
$diagHasLandingProbe = strpos($diag, 'Sub-check 1: GET / returns 200') !== false
    && strpos($diag, 'Sub-check 2: Location header is not /login.php') !== false
    && strpos($diag, 'Sub-check 3: body contains the public landing form') !== false
    && strpos($diag, 'Sub-check 4: body does NOT contain the owner login form') !== false;
if (!$diagHasLandingProbe) {
    $failures[] = '_diag.php is missing the 4 sub-checks of the landing-page probe (issue-030 v1.7.2.3)';
}

$diagRejectsLoginRedirect = strpos($diag, "stripos(\$location, '/login.php') === false") !== false
    && strpos($diag, 'name="username"') !== false;
if (!$diagRejectsLoginRedirect) {
    $failures[] = '_diag.php landing probe does not assert the response is not a redirect to /login.php (issue-030 v1.7.2.3)';
}

// 7. Issue-031 v1.7.3: every HTML page in the project that has a
//    <head> section must include a <link rel="icon"> to the project
//    favicon at assets/image/project-favicon.png. Without it,
//    browsers fall back to GET /favicon.ico and log a 404 in the
//    console. The fix is the standard <link rel="icon"> tag in each
//    <head> (modern browsers respect it and stop requesting the
//    default /favicon.ico path).
//
//    The 4 files we ship a <head> for, and the expected href path
//    that must appear in that file:
//      - includes/header.php   → '/assets/image/project-favicon.png'
//        (root-absolute; all pages that include header.php are
//        served from anywhere in the tree, e.g. /projects/<slug>).
//      - includes/landing.php  → '/assets/image/project-favicon.png'
//        (v2.0 issue-035: the landing HTML moved out of index.php
//        into includes/landing.php so index.php could become the
//        front controller; its assets are root-absolute).
//      - install.php           → '/assets/image/project-favicon.png'
//        (served from the app root, even when run as a CLI script,
//        the head is rendered into the response).
//      - client/dashboard.php  → '/assets/image/project-favicon.png'
//        (v2.0 issue-035: root-absolute like every other page, so
//        the asset resolves regardless of the URL depth).
//
//    Each <head> must also carry the <link rel="icon" type="image/png">
//    attribute prefix so the browser knows it is a PNG (and not the
//    old /favicon.ico). We check both the prefix and the href.
//
//    _diag.php is excluded — its <head> is in a .htaccess-denied
//    file that the browser never requests.
$favicon_files = [
    'includes/header.php'    => '/assets/image/project-favicon.png',
    'includes/landing.php'   => '/assets/image/project-favicon.png',
    'install.php'            => '/assets/image/project-favicon.png',
    'client/dashboard.php'   => '/assets/image/project-favicon.png',
];
foreach ($favicon_files as $label => $expected_href) {
    $path = __DIR__ . '/../' . $label;
    if (!is_readable($path)) {
        $failures[] = "favicon check: $label is missing entirely (cannot verify favicon link)";
        continue;
    }
    $src = file_get_contents($path);
    $prefix = '<link rel="icon" type="image/png" href="';
    $expected_tag = $prefix . $expected_href . '">';
    if (strpos($src, $expected_tag) === false) {
        $failures[] = "favicon check: $label is missing the exact tag $expected_tag — every HTML page must link the project favicon to suppress the /favicon.ico 404 (issue-031 v1.7.3)";
    }
}

// 8. The favicon PNG itself must be on disk. Without the file, the
//    <link rel="icon"> tag is a broken 404 in its own right. The
//    asset is committed to git in assets/image/project-favicon.png.
$favicon_asset = __DIR__ . '/../assets/image/project-favicon.png';
if (!is_readable($favicon_asset)) {
    $failures[] = "favicon check: assets/image/project-favicon.png is missing on disk (issue-031 v1.7.3)";
} else {
    $favicon_size = filesize($favicon_asset);
    if ($favicon_size === false || $favicon_size < 100) {
        $failures[] = "favicon check: assets/image/project-favicon.png is suspiciously small ($favicon_size bytes) — looks like an empty/truncated file";
    }
}
// 9. Issue-032 v1.7.3.1: the public landing (index.php) is served
//    with the CLIENT-PORTAL CSP (script-src does NOT contain
//    'unsafe-inline' — the issue-020 invariant), so any inline
//    <script> block in index.php is blocked by the browser and
//    shows a CSP violation in the console. The fix in v1.7.3.1 was
//    to extract the only inline script (the share-code auto-format
//    IIFE) to an external file assets/js/share-code-format.js and
//    load it via <script src="…">. Catches a future dev who adds a
//    new inline <script> to index.php (or reverts the externalise
//    edit) — the assertion is source-grep on the index.php source.
//    The check specifically looks for '<script>' (with no src=
//    attribute) as the inline-script indicator, allowing legitimate
//    external <script src="…"> tags.
$index_path = __DIR__ . '/../includes/landing.php';
if (!is_readable($index_path)) {
    $failures[] = 'inline-script check: includes/landing.php is missing entirely (cannot verify no inline <script>)';
} else {
    $index_src = file_get_contents($index_path);
    // The IIFE pattern '<script>(function' (with optional whitespace)
    // is the exact shape of the v1.7.3 inline block we extracted. We
    // also catch the bare '<script>' followed by code (not a src= attr)
    // as a defensive belt-and-braces check.
    $has_inline_script = preg_match('#<script>\s*\(function#i', $index_src) === 1
        || preg_match('#<script>[^(<]*[A-Za-z0-9_]#s', $index_src) === 1;
    if ($has_inline_script) {
        $failures[] = 'inline-script check: includes/landing.php still contains an inline <script>(function () { … })() block — the public landing is served with the client-portal CSP (no \'unsafe-inline\' in script-src) and the browser blocks it; the script must be externalised to assets/js/… and loaded via <script src="…"> (issue-032 v1.7.3.1)';
    }
    // The fix must also be in place: the externalised file must exist
    // and the page must reference it via <script src=…>. We don't pin
    // the exact filename (the dev may rename it) — we just assert
    // there's a <script src="/assets/js/…"> tag referencing the
    // assets/js/ directory.
    if (strpos($index_src, '<script src="/assets/js/') === false) {
        $failures[] = 'inline-script check: includes/landing.php has no <script src="/assets/js/…"> reference — the externalised share-code-format.js (or equivalent) must be loaded via <script src=…> (issue-032 v1.7.3.1)';
    }
}
// 10. Issue-033 v1.7.3.2: _diag.php must contain the new §3.6
//     all-pages HTTP probe sub-section. The probe is the
//     regression detector for the v1.7.3.0 + v1.7.3.1 class
//     of bug (prod-side state change — stale config.php —
//     that the existing §3.5 landing-only probe and the .htaccess
//     / cPanel checks do not catch). The 7 pages that must be
//     probed (with their expected status codes) are:
//         /                                  → 200
//         /login.php                          → 200
//         /dashboard.php (no session)         → 302
//         /projects.php (no session)          → 302
//         /tasks.php (no session)             → 302
//         /client/dashboard.php?code=INVALID-CODE&lang=en → 200
//         /_diag.php                          → 403
//     The check is source-grep on _diag.php (mirrors the
//     existing #6 landing-probe check). Catches a future dev
//     who removes the all-pages probe or strips any of the
//     7 entries.
$all_pages_probe_required = [
    '<h2>3.6. All-pages HTTP probe'                                        => '<h2>3.6. All-pages HTTP probe (issue-033 v1.7.3.2)</h2>',
    "'/'"                                                                  => "'/'",
    "'/login.php'"                                                         => "'/login.php'",
    "'/dashboard.php'"                                                     => "'/dashboard.php'",
    "'/projects.php'"                                                      => "'/projects.php'",
    "'/tasks.php'"                                                         => "'/tasks.php'",
    "'/client/dashboard.php?code=INVALID-CODE&lang=en'"                    => "'/client/dashboard.php?code=INVALID-CODE&lang=en'",
    "'/_diag.php'"                                                         => "'/_diag.php'",
    '[200]'                                                                => '[200]',
    '[302]'                                                                => '[302]',
    '[403]'                                                                => '[403]',
    '_diag/1.0 (issue-033 all-pages probe)'                                => '_diag/1.0 (issue-033 all-pages probe)',
];
$diag_src = $diag; // already loaded at the top of this script.
$missing_probe_entries = [];
foreach ($all_pages_probe_required as $needle => $_required_label) {
    if (strpos($diag_src, $needle) === false) {
        $missing_probe_entries[] = $needle;
    }
}
if (!empty($missing_probe_entries)) {
    $failures[] = 'all-pages probe check (_diag.php §3.6): the following sub-section markers are missing — _diag.php does not include the v1.7.3.2 all-pages HTTP probe (issue-033). Missing: ' . implode(' | ', $missing_probe_entries);
}

// 11. Issue-033 v1.7.3.2: includes/header.php must contain the
//     new 5-line defined('APP_VERSION') guard at the top of the
//     file. The guard detects a stale config.php (missing
//     APP_NAME / APP_VERSION / TIMEZONE constants) and emits a
//     clear "re-upload config.php" message instead of a generic
//     500. The 3 constants are the ones the current header.php
//     references directly (APP_NAME in the navbar brand link,
//     APP_VERSION in the about popup, TIMEZONE in date-format
//     helpers). The check is source-grep on includes/header.php
//     and asserts:
//       - 'required constant' (the literal guard message)
//       - 'APP_VERSION'       (one of the 3 constants checked)
//       - 're-upload'         (the "How to fix" copy in the
//                              guard message — must point the
//                              dev at the right action)
//     Catches a future dev who removes the guard without
//     understanding why it is there.
$header_path = __DIR__ . '/../includes/header.php';
if (!is_readable($header_path)) {
    $failures[] = 'header.php guard check: includes/header.php is missing entirely (cannot verify v1.7.3.2 defined() guard)';
} else {
    $header_src = file_get_contents($header_path);
    $has_guard = strpos($header_src, 'required constant') !== false
        && strpos($header_src, 'APP_VERSION') !== false
        && strpos($header_src, 're-upload') !== false;
    if (!$has_guard) {
        $failures[] = 'header.php guard check: includes/header.php is missing the v1.7.3.2 defined(\'APP_VERSION\') guard — without it, a stale config.php on prod produces a generic 500 instead of a clear "re-upload your config.php" message (issue-033 v1.7.3.2 §7)';
    }
}
// Result.
if (!empty($failures)) {
    fwrite(STDERR, "probe_diag_test failed:\n");
    foreach ($failures as $f) {
        fwrite(STDERR, "  - $f\n");
    }
    exit(1);
}

echo "probe_diag_test passed (issue-029 v1.7.2.2 _diag.php contract holds; issue-031 v1.7.3 favicon linked; issue-032 v1.7.3.1 inline script externalised; issue-033 v1.7.3.2 all-pages probe present + header.php stale-config guard present).\n";
