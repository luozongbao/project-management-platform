<?php
/**
 * _diag.php — read-only prod sanity probe (issue-029 v1.7.2.2,
 *              extended in issue-030 v1.7.2.3).
 *
 * Browsable only from the prod shell (the .htaccess deny rule in the
 * repo root blocks public HTTP access). Prints a self-contained HTML
 * page listing:
 *   - PHP runtime info (version, SAPI, memory_limit, error_log path)
 *   - opcache status (enabled, revalidate_freq)
 *   - File checks for the 5 v1.7.2.2-touched files
 *     (config.php, client/dashboard.php, client/lang.php,
 *      includes/header.php, includes/functions.php)
 *   - About-popup lang-key presence (en + zh)
 *   - Landing-page live-URL probe (issue-030 v1.7.2.3) — does a
 *     curl-based GET / against the public prod URL and asserts the
 *     landing form is served (not a 301 to /login.php and not the
 *     owner login form). The probe also prints actionable guidance
 *     pointing the dev to the cPanel hPanel Redirects UI and the
 *     Index Manager (Directory Privacy) page.
 *   - The actual CSP that the public client portal emits
 *   - An "ALL CHECKS PASSED" / "N CHECKS FAILED" banner at the top
 *
 * The v1.7.2.2 invariant is preserved: no DB calls, no SMTP calls.
 * The v1.7.2.3 release adds ONE outbound HTTP call to the public
 * prod URL ($public_base) for the landing-page probe. $public_base
 * is overridable for the dev-side test in
 * database/landing_page_probe_test.php (which points it at a local
 * php -S server).
 *
 * Run from the prod shell:
 *   php /home/u162912405/public_html/_diag.php
 *
 * Or from a browser (will be 403 by .htaccess unless you are
 * running a local Apache with the .htaccess disabled).
 */

error_reporting(E_ALL);
ini_set('display_errors', '1');

// Start output buffering so the banner can be rendered at the top
// of the page (between the title and section 1) AFTER all the
// other checks have run and updated $failed. The banner placeholder
// is the string '__DIAG_BANNER__'. We replace it with the real
// banner HTML at the end of the file, just before flush. Issue-030
// v1.7.2.3 — needed because the new landing-page probe sub-section
// increments $failed and the banner needs to reflect it.
ob_start();

$checks  = [];
$failed  = 0;

/**
 * Render one sub-check row for the new landing-page probe (issue-030
 * v1.7.2.3) and the future probes that follow the same shape. The
 * row is appended to the table body by the caller.
 */
function probe_row($label, $result, $failed, $help = null) {
    $status_icon = $failed ? '<span class="bad">&#10007;</span>' : '<span class="ok">&#10003;</span>';
    $row  = '<tr><td>' . htmlspecialchars($label) . '</td>'
          . '<td>' . htmlspecialchars($result) . '</td>'
          . '<td>' . $status_icon . '</td></tr>' . "\n";
    if ($help) {
        $row .= '<tr><td colspan="3" style="background:#fff3cd;padding:8px;font-size:0.85em;">'
              . '<strong>How to fix:</strong> ' . htmlspecialchars($help) . '</td></tr>' . "\n";
    }
    return $row;
}

// --- PHP runtime info -------------------------------------------------
$php_version   = PHP_VERSION;
$php_sapi      = php_sapi_name();
$mem_limit     = ini_get('memory_limit');
$err_log_path  = ini_get('error_log') ?: '(default — syslog or stderr)';
$opcache_on    = function_exists('opcache_get_status') && ini_get('opcache.enable');
$opcache_freq  = ini_get('opcache.revalidate_freq');

// --- Files to check ---------------------------------------------------
$files_to_check = [
    'config.php'              => __DIR__ . '/config.php',
    'client/dashboard.php'    => __DIR__ . '/client/dashboard.php',
    'client/lang.php'         => __DIR__ . '/client/lang.php',
    'includes/header.php'     => __DIR__ . '/includes/header.php',
    'includes/functions.php'  => __DIR__ . '/includes/functions.php',
];

// Local repo md5s — hard-coded so the dev can compare without
// needing the repo on the prod box. These are the md5sums of the
// v1.7.2.2-shipped versions of the 5 v1.7.2.2-touched source
// files; the dev updates them in the same commit when shipping a
// new release that touches any of these files. v1.7.2.3 did NOT
// touch any of the 5 files below (the only changes are .htaccess,
// _diag.php itself, and database/probe_diag_test.php), so the
// v1.7.2.2 md5s remain valid. v1.7.3 DOES touch 3 of the 5 files
// below: the favicon <link> edit changes the md5 of
// includes/header.php and client/dashboard.php, and the
// APP_VERSION v1.7.2.3 → v1.7.3 bump changes the md5 of the
// local config.php (because the APP_VERSION literal changes
// inside the file). All 3 entries are re-bumped in this commit.
// The other 2 (client/lang.php, includes/functions.php) keep
// their v1.7.2.2 md5s because the v1.7.3 edit did not change
// them. The placeholder FILL_IN_AT_RELEASE_TIME is replaced by
// the actual md5sum at release time (see docs/RELEASE_PROCESS.md
// step 5.5 and docs/issues/issue-029.md §6.4). The
// probe_diag_test.php test asserts that no FILL_IN_AT_RELEASE_TIME
// literal remains in the deployed file.
$expected_md5 = [
    'config.php'              => '642c61e3ed25d6f813e059fd79c12408',
    'client/dashboard.php'    => '4c87b97107c8856f62f269d0bfd08576',
    'client/lang.php'         => '795a1c1363a82612dde97fde24c616f6',
    'includes/header.php'     => '1c686e4f6e502f85a0586e7c1dd85c12',
    'includes/functions.php'  => '6821e5ec01eced298a5f196ecbb802c0',
];

// --- CSP probe -------------------------------------------------------
// The two CSPs the app sends (internal + client portal). Reproduced
// from includes/security_headers.php so the dev can compare what the
// browser sees against what the source code says.
$csp_internal = "default-src 'self'; "
    . "script-src 'self' 'unsafe-inline' https://cdnjs.cloudflare.com https://static.cloudflareinsights.com; "
    . "style-src 'self' 'unsafe-inline' https://cdnjs.cloudflare.com; "
    . "img-src 'self' data:; "
    . "font-src 'self' https://cdnjs.cloudflare.com data:; "
    . "connect-src 'self'; "
    . "frame-ancestors 'none'; "
    . "base-uri 'self'; "
    . "form-action 'self'";

$csp_client = "default-src 'self'; "
    . "script-src 'self' https://cdnjs.cloudflare.com https://static.cloudflareinsights.com; "
    . "style-src 'self' 'unsafe-inline' https://cdnjs.cloudflare.com; "
    . "img-src 'self' data:; "
    . "font-src 'self' https://cdnjs.cloudflare.com data:; "
    . "connect-src 'self'; "
    . "frame-ancestors 'none'; "
    . "base-uri 'self'; "
    . "form-action 'self'";

// --- Run the file checks ---------------------------------------------
$rows = '';
foreach ($files_to_check as $label => $path) {
    $readable = is_readable($path) ? 'YES' : 'NO';
    $size     = is_readable($path) ? filesize($path) : 0;
    $md5      = is_readable($path) ? md5_file($path) : '(missing)';
    $mtime    = is_readable($path) ? date('c', filemtime($path)) : '(missing)';
    $expected = $expected_md5[$label];
    // FILL_IN_AT_RELEASE_TIME placeholders are treated as "unknown"
    // for the purpose of the md5 check — they are caught at deploy
    // time by probe_diag_test.php, not by this prod probe. We still
    // flag them in the row so the dev can see them visually.
    if ($expected === 'FILL_IN_AT_RELEASE_TIME') {
        $md5_ok   = 'PLACEHOLDER (re-upload _diag.php with the actual md5)';
    } else {
        $md5_ok   = ($md5 === $expected) ? 'OK' : 'MISMATCH (expected ' . $expected . ')';
        if ($md5 !== $expected) { $failed++; }
    }
    $rows .= "<tr><td>$label</td><td>$readable</td><td>$size</td>"
           . "<td>$md5</td><td>$md5_ok</td><td>$mtime</td></tr>\n";
}

// --- Lang keys --------------------------------------------------------
$about_keys_present_en = 'NO';
$about_keys_present_zh = 'NO';
if (is_readable(__DIR__ . '/client/lang.php')) {
    require_once __DIR__ . '/client/lang.php';
    $tr = client_translations();
    $en = array_keys($tr['en'] ?? []);
    $zh = array_keys($tr['zh'] ?? []);
    $about_en = array_filter($en, fn($k) => str_starts_with($k, 'about.'));
    $about_zh = array_filter($zh, fn($k) => str_starts_with($k, 'about.'));
    $about_keys_present_en = count($about_en) >= 6 ? 'YES (' . count($about_en) . ' keys)' : 'NO (' . count($about_en) . ' keys)';
    $about_keys_present_zh = count($about_zh) >= 6 ? 'YES (' . count($about_zh) . ' keys)' : 'NO (' . count($about_zh) . ' keys)';
    if (count($about_en) < 6 || count($about_zh) < 6) { $failed++; }
}

// NOTE: $banner is computed AFTER the landing-page probe sub-section
// (see below) so the new v1.7.2.3 live-URL checks can increment
// $failed and the banner stays in sync. The banner is rendered by
// the inline <?= $banner ?> below the landing sub-section. Issue-030
// v1.7.2.3.
?><!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>_diag.php &mdash; prod sanity probe (issue-030 v1.7.2.3)</title>
    <style>
        body { font-family: 'Segoe UI', Tahoma, sans-serif; max-width: 1100px; margin: 20px auto; padding: 0 20px; color: #333; }
        h1 { font-size: 1.3rem; border-bottom: 1px solid #ccc; padding-bottom: 6px; }
        h2 { font-size: 1.05rem; margin-top: 28px; color: #007bff; }
        table { border-collapse: collapse; width: 100%; font-size: 0.88rem; margin-top: 8px; }
        th, td { border: 1px solid #ddd; padding: 6px 9px; text-align: left; vertical-align: top; }
        th { background: #f1f3f4; }
        pre { background: #f6f8fa; padding: 10px; border-radius: 4px; font-size: 0.82rem; overflow-x: auto; }
        .ok { color: #28a745; font-weight: 600; }
        .bad { color: #dc3545; font-weight: 600; }
    </style>
</head>
<body>

<h1>_diag.php &mdash; prod sanity probe</h1>
<p>Read-only diagnostic for the v1.7.2.3 release (extended from the v1.7.2.2 original). See <code>docs/issues/issue-029.md</code> and <code>docs/issues/issue-030.md</code>.</p>

__DIAG_BANNER__

<h2>1. PHP runtime</h2>
<table>
    <tr><th>Key</th><th>Value</th></tr>
    <tr><td>PHP version</td><td><?= htmlspecialchars($php_version) ?></td></tr>
    <tr><td>SAPI</td><td><?= htmlspecialchars($php_sapi) ?></td></tr>
    <tr><td>memory_limit</td><td><?= htmlspecialchars($mem_limit) ?></td></tr>
    <tr><td>error_log</td><td><code><?= htmlspecialchars($err_log_path) ?></code></td></tr>
    <tr><td>opcache.enable</td><td><?= $opcache_on ? '<span class="ok">ON</span>' : '<span class="bad">OFF</span>' ?></td></tr>
    <tr><td>opcache.revalidate_freq</td><td><?= htmlspecialchars((string)$opcache_freq) ?> sec</td></tr>
</table>

<h2>2. File checks (v1.7.2.2-touched files; unchanged in v1.7.2.3)</h2>
<table>
    <tr>
        <th>File</th><th>Readable</th><th>Size</th><th>md5</th><th>Status</th><th>mtime</th>
    </tr>
    <?= $rows ?>
</table>
<p style="font-size:0.85em;color:#666;">
    The expected md5sums are hard-coded in this file and updated by the
    dev in the same commit that ships the release. If a row shows
    <code>MISMATCH</code>, either (a) the file on prod is older than the
    release, (b) the file was edited directly on prod (a no-no), or
    (c) this script is older than the release (re-upload <code>_diag.php</code>).
</p>

<h2>3. About-popup lang keys (v1.7.2)</h2>
<table>
    <tr><th>Catalog</th><th>about.* keys present</th></tr>
    <tr><td>en</td><td><?= strpos($about_keys_present_en, 'NO') === 0 ? '<span class="bad">' : '<span class="ok">' ?><?= htmlspecialchars($about_keys_present_en) ?></span></td></tr>
    <tr><td>zh</td><td><?= strpos($about_keys_present_zh, 'NO') === 0 ? '<span class="bad">' : '<span class="ok">' ?><?= htmlspecialchars($about_keys_present_zh) ?></span></td></tr>
</table>
<p style="font-size:0.85em;color:#666;">
    If the en or zh catalog is missing <code>about.*</code>, re-upload
    <code>client/lang.php</code>. The page <code>client/dashboard.php</code>
    will render a 500 if it calls <code>t('about.title')</code> against
    a missing catalog.
</p>

<h2>3.5. Landing page at <code>/</code> (issue-030 v1.7.2.3)</h2>
<?php
// $public_base is overridable for the dev-side
// database/landing_page_probe_test.php test (which spins up a local
// php -S server and points the probe at it). The default is the
// public prod URL. Issue-030 v1.7.2.3.
$public_base = $public_base ?? 'https://project.lorwongam.com';

// The live-URL probe runs by default. Set DIAG_SKIP_LIVE=1 in the
// environment to skip it (useful for dev-side runs where the dev
// does not want the probe to hit the public prod URL). The prod
// shell does NOT set this env var, so the probe always runs on
// prod. The dev-side test (database/landing_page_probe_test.php)
// also does not set it -- the test overrides $public_base and the
// live probe hits a local php -S server, which is exactly what the
// test wants.
$diag_skip_live = getenv('DIAG_SKIP_LIVE') === '1';

$landing_ok    = true;
$landing_rows  = '';
$captured_body = null;

if ($diag_skip_live) {
    // Dev-side: skip the live probe and print a skipped row for each
    // of the 4 sub-checks. The overall banner stays green because
    // $failed is not incremented.
    $landing_rows .= probe_row(
        'Sub-check 1: GET / returns 200',
        'SKIPPED (DIAG_SKIP_LIVE=1)',
        false
    );
    $landing_rows .= probe_row(
        'Sub-check 2: Location header is not /login.php',
        'SKIPPED (DIAG_SKIP_LIVE=1)',
        false
    );
    $landing_rows .= probe_row(
        'Sub-check 3: body contains the public landing form',
        'SKIPPED (DIAG_SKIP_LIVE=1)',
        false
    );
    $landing_rows .= probe_row(
        'Sub-check 4: body does NOT contain the owner login form',
        'SKIPPED (DIAG_SKIP_LIVE=1)',
        false
    );
} else {

// Sub-check 1: HTTP 200 (no redirect) on GET /
$ch = curl_init($public_base . '/');
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HEADER         => true,
    CURLOPT_FOLLOWLOCATION => false,
    CURLOPT_NOBODY         => true,
    CURLOPT_TIMEOUT        => 10,
    CURLOPT_USERAGENT      => '_diag/1.0 (issue-030 probe)',
]);
$out      = curl_exec($ch);
$code     = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$location = '';
if (is_string($out) && preg_match('/^Location:\s*(.+)$/mi', $out, $m)) {
    $location = trim($m[1]);
}
curl_close($ch);

$status_ok = ($code === 200);
$landing_ok = $landing_ok && $status_ok;
$landing_rows .= probe_row(
    'Sub-check 1: GET / returns 200',
    $status_ok ? 'OK (HTTP 200)' : "FAIL (HTTP $code, Location: $location)",
    !$status_ok,
    $status_ok ? null : "The root URL is not returning 200. Likely cause: a cPanel hPanel "
        . "Redirects rule is set (hPanel -> Websites -> project.lorwongam.com -> Manage -> "
        . "Redirects), or the Index Manager is set to 'login.php' (hPanel -> Files -> "
        . "Directory Privacy, or Advanced -> Index Manager). Fix the hPanel setting and "
        . "re-run this probe. See docs/issues/issue-030.md for the step-by-step."
);

// Sub-check 2: not a redirect to /login.php
$no_login_redirect = ($location === '' || stripos($location, '/login.php') === false);
$landing_ok = $landing_ok && $no_login_redirect;
$landing_rows .= probe_row(
    'Sub-check 2: Location header is not /login.php',
    $no_login_redirect ? 'OK' : "FAIL (Location: $location)",
    !$no_login_redirect,
    $no_login_redirect ? null : "The root URL is being redirected to /login.php. Same fix as "
        . "sub-check 1: cPanel hPanel Redirects rule or Index Manager is set wrong. Delete "
        . "the Redirects rule OR set the Index Manager to 'index.php'."
);

// Sub-check 3 and 4: GET / body content
$ch = curl_init($public_base . '/');
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_FOLLOWLOCATION => true,
    CURLOPT_TIMEOUT        => 10,
    CURLOPT_USERAGENT      => '_diag/1.0 (issue-030 probe)',
]);
$body = curl_exec($ch);
$final_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

$body_ok = (is_string($body) && strpos($body, '<form method="POST" action=""') !== false
                          && strpos($body, 'name="code"') !== false);
$landing_ok = $landing_ok && $body_ok;
$landing_rows .= probe_row(
    'Sub-check 3: body contains the public landing form',
    $body_ok ? 'OK (landing form detected)' : 'FAIL (no <form method="POST" action=""> + name="code" in body)',
    !$body_ok,
    $body_ok ? null : "The root URL body does not contain the public landing form. The fix is "
        . "the same as sub-check 1: cPanel hPanel is sending the wrong page. The diag captured "
        . "the body (see the 'captured body' section below) so you can confirm what was served."
);

// Sub-check 4: body does NOT contain the owner login form. We use
// 'name="username"' as the login-form indicator (login.php line 99)
// because that is a unique signature of the owner login form, not
// the landing form (which has 'name="code"' instead, asserted in
// sub-check 3). The spec's literal 'action="login.php"' check is
// not robust here because neither index.php nor login.php actually
// emits that string -- both have action="" -- so it would never
// fire. The 'name="username"' check is the disambiguator that
// actually catches the cPanel Index-Manager-redirects-/to-/login.php
// bug.
$login_form_absent = (!is_string($body) || strpos($body, 'name="username"') === false);
$landing_ok = $landing_ok && $login_form_absent;
$landing_rows .= probe_row(
    'Sub-check 4: body does NOT contain the owner login form',
    $login_form_absent ? 'OK' : 'FAIL (body contains name="username" -- owner login form was served)',
    !$login_form_absent,
    $login_form_absent ? null : "The root URL is serving the owner login form instead of the "
        . "public landing form. This is the bug: cPanel hPanel Index Manager is set to "
        . "'login.php' (or a Redirects rule rewrites / -> /login.php). Fix per sub-check 1."
);

if (!$landing_ok && is_string($body)) {
    $captured_body = $body;
    $landing_rows .= '<tr><td colspan="3" style="background:#fff3cd;padding:8px;">'
        . '<strong>Captured body (first 2000 chars) for debugging:</strong><br>'
        . '<pre style="max-height:300px;overflow:auto;background:#f6f8fa;padding:10px;'
        . 'border-radius:4px;font-size:0.8rem;">'
        . htmlspecialchars(substr($body, 0, 2000))
        . '</pre></td></tr>';
}
} // end if (!$diag_skip_live)
?>
<table>
    <tr>
        <th style="width:55%;">Sub-check</th>
        <th style="width:25%;">Result</th>
        <th style="width:20%;">Status</th>
    </tr>
    <?= $landing_rows ?>
</table>
<p style="font-size:0.85em;color:#666;">
    This sub-section was added in v1.7.2.3 (issue-030) to detect the
    <code>/</code> &rarr; <code>/login.php</code> redirect that the
    v1.7.2.2 ship did not catch. The fix is in the cPanel hPanel UI,
    not in the codebase; see <code>docs/issues/issue-030.md &sect;6.1</code>
    for the step-by-step. Set <code>DIAG_SKIP_LIVE=1</code> in the
    environment to skip the live HTTP probe (dev-side runs only).
</p>
<?php if (!$landing_ok) { $failed++; } ?>

<h2>4. Expected Content-Security-Policy</h2>
<p>The two CSPs the app sends (internal + client portal):</p>
<h3>4.1 Internal app (sendSecurityHeaders)</h3>
<pre><?= htmlspecialchars($csp_internal) ?></pre>
<h3>4.2 Client portal (sendClientPortalSecurityHeaders)</h3>
<pre><?= htmlspecialchars($csp_client) ?></pre>
<p style="font-size:0.85em;color:#666;">
    The exact strings are reproduced from <code>includes/security_headers.php</code>.
    If you change either of them, update both the helper AND the test in
    <code>database/security_headers_test.php</code>.
</p>

<h2>5. What to do if a check fails</h2>
<ol style="font-size:0.9em;">
    <li><strong>md5 MISMATCH</strong>: re-upload the file (SFTP / cPanel File Manager).</li>
    <li><strong>about.* keys missing</strong>: re-upload <code>client/lang.php</code>.</li>
    <li><strong>File NOT readable</strong>: check file permissions (<code>chmod 644</code>) and ownership.</li>
    <li><strong>opcache ON + old mtime</strong>: either wait for <code>opcache.revalidate_freq</code> seconds, or restart Apache (cPanel &rarr; "Restart Apache" or run <code>touch /home/u162912405/public_html/client/dashboard.php</code> to force a mtime update that opcache will notice).</li>
</ol>

<hr>
<p style="font-size:0.8em;color:#999;">
    Generated by <code>_diag.php</code> at <?= date('c') ?>.
    This page is blocked by <code>.htaccess</code> for public HTTP access;
    the dev should run it from the prod shell.
</p>

</body>
</html>
<?php
// Issue-030 v1.7.2.3: compute the banner AFTER all checks have run
// (including the new landing-page probe sub-section) so the failure
// count is accurate, then substitute the placeholder at the top of
// the page and flush the buffer.
$banner = $failed === 0
    ? '<div style="background:#28a745;color:#fff;padding:12px 18px;border-radius:6px;font-weight:600;font-size:1.1em;">&#10003; ALL CHECKS PASSED</div>'
    : '<div style="background:#dc3545;color:#fff;padding:12px 18px;border-radius:6px;font-weight:600;font-size:1.1em;">&#10007; ' . $failed . ' CHECK(S) FAILED</div>';
$out = ob_get_clean();
$out = str_replace('__DIAG_BANNER__', $banner, $out);
echo $out;
?>
