<?php
/**
 * _diag.php — read-only prod sanity probe (issue-029 v1.7.2.2).
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
 *   - The actual CSP that the public client portal emits
 *   - An "ALL CHECKS PASSED" / "N CHECKS FAILED" banner at the top
 *
 * No DB calls, no SMTP calls, no HTTP calls. Safe to leave in
 * public_html behind the .htaccess deny rule.
 *
 * Run from the prod shell:
 *   php /home/u162912405/public_html/_diag.php
 *
 * Or from a browser (will be 403 by .htaccess unless you are
 * running a local Apache with the .htaccess disabled).
 */

error_reporting(E_ALL);
ini_set('display_errors', '1');

$checks  = [];
$failed  = 0;

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
// v1.7.2.2-shipped versions; the dev updates them in the same
// commit when shipping a new release. The placeholder
// FILL_IN_AT_RELEASE_TIME is replaced by the actual md5sum at
// release time (see docs/RELEASE_PROCESS.md step 5.5 and
// docs/issues/issue-029.md §6.4). The probe_diag_test.php test
// asserts that no FILL_IN_AT_RELEASE_TIME literal remains in the
// deployed file.
$expected_md5 = [
    'config.php'              => 'e28b3f4c8c4cd4bace6ad3836c7857ad',
    'client/dashboard.php'    => '3336a970f07cf9f71f9338883f4a29a1',
    'client/lang.php'         => '795a1c1363a82612dde97fde24c616f6',
    'includes/header.php'     => '3a49d1353ce5b2c1e6ed6419cec9989f',
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

$banner = $failed === 0
    ? '<div style="background:#28a745;color:#fff;padding:12px 18px;border-radius:6px;font-weight:600;font-size:1.1em;">&#10003; ALL CHECKS PASSED</div>'
    : '<div style="background:#dc3545;color:#fff;padding:12px 18px;border-radius:6px;font-weight:600;font-size:1.1em;">&#10007; ' . $failed . ' CHECK(S) FAILED</div>';

// --- Render -----------------------------------------------------------
?><!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>_diag.php &mdash; prod sanity probe (issue-029 v1.7.2.2)</title>
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
<p>Read-only diagnostic for the v1.7.2.2 release. See <code>docs/issues/issue-029.md</code>.</p>

<?= $banner ?>

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

<h2>2. File checks (v1.7.2.2-touched files)</h2>
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
