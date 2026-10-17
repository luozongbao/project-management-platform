<?php
/**
 * Regression test for issue-029 v1.7.2.2 — _diag.php contract.
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
 *   5. The file has no DB / SMTP / HTTP side-effect calls
 *      ($db->, Database::, $mailer, SMTP_, curl_init,
 *      file_get_contents('http)). The read-only invariant is
 *      enforced — the probe MUST NOT touch the DB, the mailer, or
 *      the network.
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

// 4. The file has no <?php open tag after line 1.
$lines = preg_split('/\r?\n/', $diag);
for ($i = 1; $i < count($lines); $i++) {
    if (strpos($lines[$i], '<?php') !== false) {
        $failures[] = "_diag.php has a <?php open tag on line " . ($i + 1) . " (after line 1) — a stray PHP open tag inside a // comment would be a parse error if the comment context is ever broken";
    }
}

// 5. Read-only invariant: no DB / SMTP / HTTP side-effect calls.
$forbidden_patterns = [
    '$db->'         => 'database call (\$db->) — the probe must be read-only',
    'Database::'    => 'database call (Database::) — the probe must be read-only',
    '$mailer'       => 'mailer call (\$mailer) — the probe must be read-only',
    'SMTP_'         => 'SMTP constant (SMTP_) — the probe must not depend on mail config',
    'curl_init'     => 'curl call (curl_init) — the probe must be read-only and not depend on the network',
    "file_get_contents('http" => 'HTTP file_get_contents — the probe must not depend on the network',
    'file_get_contents("http' => 'HTTP file_get_contents — the probe must not depend on the network',
];
foreach ($forbidden_patterns as $needle => $reason) {
    if (strpos($diag, $needle) !== false) {
        $failures[] = "_diag.php contains '$needle' — $reason";
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

echo "probe_diag_test passed (issue-029 v1.7.2.2 _diag.php contract holds).\n";
