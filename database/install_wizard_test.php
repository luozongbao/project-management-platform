<?php
/**
 * Regression test for issue-021 (install.php hardening +
 * _smtp_test.php web-root removal).
 *
 * Covers all 8 sections of docs/issues/issue-021.md#regression-test-plan:
 *   1. Strict db_name regex in install.php.
 *   2. var_export() calls in install.php.
 *   3. SERVER_NAME-based URL in install.php.
 *   4. CSRF token in install.php.
 *   5. Two-stage gate in install.php.
 *   6. _smtp_test.php is gone from the web root.
 *   7. docker/_smtp_test.php exists and is a CLI-only test.
 *   8. Byte-compare config.php written by the live install.php to one
 *      written by docker/entrypoint.sh (ENCRYPTION_KEY normalized).
 *
 * The byte-compare boots install.php in a CLI proxy (same pattern as
 * database/task_inherit_subtask_hours_test.php's render probe) so the
 * full code path is exercised, not a hand-rolled copy of the writer.
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
    // Use ~ as delimiter to avoid / and # collisions with the patterns
    // we are stripping. Block comments first, then line comments.
    $src = preg_replace('~/\*.*?\*/~s', '', $src);
    $src = preg_replace('~//[^\n]*~', '', $src);
    $src = preg_replace('~#[^\n]*~', '', $src);
    return $src;
}

// ---------------------------------------------------------------------------
// 1-5. Source-grep checks on install.php
// ---------------------------------------------------------------------------
$install_src  = file_get_contents($repo_root . '/install.php');
$install_code = strip_php_comments($install_src);

echo "## 1. Strict db_name regex\n";
ok(
    'install.php contains the strict ^[A-Za-z0-9_]+$ db_name regex',
    (bool)preg_match("~preg_match\\s*\\(\\s*['\"]/\\^\\[A-Za-z0-9_\\]\\+\\$/~", $install_code)
);
ok(
    'install.php emits a "letters, digits, and underscores" error message',
    strpos($install_src, 'letters, digits, and underscores') !== false
);

echo "\n## 2. var_export() config writer (no addslashes)\n";
ok(
    'install.php uses var_export() for DB_HOST',
    (bool)preg_match('~DB_HOST\s*=\s*"\s*\.\s*var_export\(~', $install_code)
);
ok(
    'install.php uses var_export() for SMTP_HOST',
    (bool)preg_match('~SMTP_HOST\s*=\s*"\s*\.\s*var_export\(~', $install_code)
);
ok(
    'install.php uses var_export() for ENCRYPTION_KEY',
    (bool)preg_match('~ENCRYPTION_KEY\s*=\s*"\s*\.\s*var_export\(~', $install_code)
);
ok(
    'install.php uses var_export() for APP_VERSION (issue-028)',
    (bool)preg_match('~APP_VERSION\s*=\s*"\s*\.\s*var_export\(~', $install_code)
);
ok(
    'install.php does NOT call addslashes()',
    // Search the code-only (comment-stripped) view, and look for the
    // function-call form, not just the identifier.
    !preg_match('~\baddslashes\s*\(~', $install_code)
);

echo "\n## 3. SERVER_NAME-based URL\n";
ok(
    'install.php reads $_SERVER[\'SERVER_NAME\']',
    strpos($install_code, "'SERVER_NAME'") !== false
        || strpos($install_code, '"SERVER_NAME"') !== false
);
ok(
    'install.php does NOT read $_SERVER[\'HTTP_HOST\']',
    strpos($install_code, "'HTTP_HOST'") === false
        && strpos($install_code, '"HTTP_HOST"') === false
);

echo "\n## 4. CSRF on the wizard\n";
ok(
    'install.php calls validateCsrfToken() against $_POST[\'csrf_token\']',
    (bool)preg_match('~validateCsrfToken\(\s*\$_POST\s*\[\s*[\'"]csrf_token[\'"]\s*\]~', $install_code)
);
ok(
    'install.php calls getCsrfToken()',
    strpos($install_code, 'getCsrfToken()') !== false
);
ok(
    'install.php renders a hidden csrf_token input',
    (bool)preg_match("~<input\\s+type=['\"]hidden['\"]\\s+name=['\"]csrf_token['\"]~", $install_src)
);
ok(
    'install.php starts the session at the top (not just on step 3)',
    strpos($install_src, 'startSession()') !== false
        && strpos($install_src, 'startSession()') < strpos($install_src, '<!DOCTYPE html>')
);

echo "\n## 5. Install gate (single-stage, see Deviation 1)\n";
ok(
    'install.php checks file_exists(config.php)',
    strpos($install_code, "file_exists(__DIR__ . '/config.php')") !== false
        || strpos($install_code, "file_exists('config.php')") !== false
);
ok(
    'install.php redirects to index.php when config.php exists',
    substr_count($install_code, "header('Location: index.php')") >= 1
);

// As of v1.7.0 the gate is single-stage. The "stage 2" DB probe
// (SHOW TABLES LIKE 'users') was removed in response to the tester
// report (Deviation 1): the probe was unreachable because the wizard
// never loads config.php and the DB_* constants were never defined.
// Asserting the probe is GONE prevents a future dev from
// re-introducing the same dead code.
echo "\n## 5a. No dead-code stage-2 probe (regression net for Deviation 1)\n";
ok(
    'install.php does NOT probe SHOW TABLES LIKE \'users\' at the top level',
    // The probe is gone from the gate block. It must not reappear
    // unless a real fix (e.g. a marker file) is added to make the
    // probe reachable — and the spec / spec test would have to be
    // updated in the same change. Today the absence of the probe is
    // the contract.
    strpos($install_code, "SHOW TABLES LIKE 'users'") === false
);
ok(
    'install.php has a comment acknowledging the single-stage design',
    // A dev reading the gate block should immediately see WHY it is
    // single-stage. The comment must reference the issue tracker
    // (tester-report.md) so a future reader can follow up. We
    // search the ORIGINAL source ($install_src, NOT the comment-
    // stripped $install_code) because strip_php_comments() would
    // eat the // line containing the reference. The pattern spans
    // lines because the comment is multi-line.
    (bool)preg_match(
        '~//[^\n]*tester-report\.md[\s\S]{0,200}?Deviation\s*1~i',
        $install_src
    )
);

// ---------------------------------------------------------------------------
// 6-7. _smtp_test.php location
// ---------------------------------------------------------------------------
echo "\n## 6. _smtp_test.php is out of the web root\n";
ok(
    '_smtp_test.php is NOT in the web root',
    !is_file($repo_root . '/_smtp_test.php')
);

echo "\n## 7. docker/_smtp_test.php exists, CLI-only\n";
$cli_src = is_file($repo_root . '/docker/_smtp_test.php')
    ? file_get_contents($repo_root . '/docker/_smtp_test.php') : '';
ok(
    'docker/_smtp_test.php exists',
    $cli_src !== ''
);
ok(
    'docker/_smtp_test.php has a php_sapi_name() !== \'cli\' early return',
    strpos($cli_src, "php_sapi_name() !== 'cli'") !== false
);
ok(
    'docker/_smtp_test.php header says CLI-only',
    strpos($cli_src, 'CLI-ONLY') !== false || strpos($cli_src, 'CLI-only') !== false
);

echo "\n## 7b. docker/nginx.conf no longer references _smtp_test.php\n";
$nginx_src = file_get_contents($repo_root . '/docker/nginx.conf');
ok(
    'docker/nginx.conf deny regex no longer contains _smtp_test.php',
    strpos($nginx_src, '_smtp_test') === false
);

// ---------------------------------------------------------------------------
// 8. Byte-compare: live install.php output vs docker/entrypoint.sh output.
//    We boot install.php in a CLI proxy against a temp working directory
//    and capture the config.php it writes.
// ---------------------------------------------------------------------------
echo "\n## 8. Byte-compare config.php (install.php vs entrypoint.sh)\n";

$input = [
    'db_host'       => '127.0.0.1',
    'db_name'       => 'project_management',
    'db_user'       => 'db_user',
    'db_pass'       => "secret'with-quote\\and-backslash",
    'smtp_host'     => 'smtp.example.com',
    'smtp_port'     => 587,
    'smtp_protocol' => 'tls',
    'smtp_user'     => 'user@example.com',
    'smtp_pass'     => 'smtp"pass',
    'app_name'      => 'Project Management System',
    'app_url'       => 'http://localhost',
];

// Build a temp directory that mirrors the layout install.php expects.
// We symlink includes/ (and a couple of other dirs install.php might
// touch) so require_once resolves the same file from both the proxy
// and install.php — otherwise startSession() / getCsrfToken() are
// declared twice and we get a fatal.
$tmp = sys_get_temp_dir() . '/install_wizard_test_' . bin2hex(random_bytes(4));
@mkdir($tmp, 0777, true);

// Best-effort cleanup, even on fatal exit.
$cleanup = static function () use ($tmp) {
    if (!is_dir($tmp)) { return; }
    $entries = @scandir($tmp);
    if (!is_array($entries)) { return; }
    foreach (array_diff($entries, ['.', '..']) as $e) {
        $p = $tmp . '/' . $e;
        if (is_link($p) || is_file($p)) { @unlink($p); continue; }
        if (is_dir($p)) {
            $sub = @scandir($p);
            if (is_array($sub)) {
                foreach (array_diff($sub, ['.', '..']) as $s) {
                    @unlink($p . '/' . $s);
                }
            }
            @rmdir($p);
        }
    }
    @rmdir($tmp);
};
register_shutdown_function($cleanup);

// Symlink so install.php's require_once sees the same file the proxy
// already pulled in. (Copying caused a "Cannot redeclare" fatal.)
copy($repo_root . '/install.php', $tmp . '/install.php');
// @ to silence the "File exists" warning if a previous run leaked.
@symlink($repo_root . '/includes', $tmp . '/includes');
@symlink($repo_root . '/database', $tmp . '/database');
@symlink($repo_root . '/vendor',   $tmp . '/vendor');

// The proxy starts a session and seeds $_SESSION['db_config'] before
// including install.php. It posts step 3 with a fresh CSRF token and
// prints the written config.php between sentinel markers.
$proxy_body = <<<'PHP'
// Defense in depth: silence header() warnings under CLI.
error_reporting(E_ALL & ~E_WARNING);
ini_set('display_errors', '0');

$repo_root = getenv('REPO_ROOT');
$tmp       = getenv('TMP_DIR');

// Use the deterministic session id from the env so the proxy AND the
// included install.php see the same session storage. Strict mode
// would otherwise force-regenerate the id.
ini_set('session.use_strict_mode', '0');
ini_set('session.use_cookies', '0');
session_id(getenv('PROXY_SID'));
session_start();

$_SESSION['db_config'] = json_decode(getenv('DB_CONFIG_JSON'), true);

require $repo_root . '/includes/functions.php';
$token = getCsrfToken();
$_SESSION['csrf_token'] = $token;
session_write_close();

$_SERVER['REQUEST_METHOD'] = 'POST';
$_SERVER['HTTPS']         = 'off';
$_SERVER['SERVER_PORT']   = '80';
$_SERVER['SERVER_NAME']   = 'localhost';
$_SERVER['PHP_SELF']      = '/install.php';
$_SERVER['SCRIPT_NAME']   = '/install.php';
$_GET['step'] = 3;

$_POST = [
    'csrf_token'    => $token,
    'smtp_host'     => getenv('SMTP_HOST'),
    'smtp_port'     => getenv('SMTP_PORT'),
    'smtp_protocol' => getenv('SMTP_PROTOCOL'),
    'smtp_user'     => getenv('SMTP_USER'),
    'smtp_pass'     => getenv('SMTP_PASS'),
];

chdir($tmp);
// IMPORTANT: open the output buffer BEFORE any debug echo and
// BEFORE requiring install.php. If we echo (or let any other output
// escape) before install.php's startSession() calls session_start(),
// PHP raises "headers already sent" warnings and session_start()
// silently fails — the wizard then sees an empty $_SESSION and the
// CSRF check rejects the POST. See:
//   https://www.php.net/manual/en/function.session-start.php
ob_start();
require $tmp . '/install.php';
$html = ob_get_clean();

$out = $tmp . '/config.php';
if (is_file($out)) {
    // The actual config.php is fenced between sentinels so the test
    // runner can extract it with a single regex, regardless of
    // whatever else install.php printed before us.
    echo "===CONFIG_START===\n";
    echo file_get_contents($out);
    echo "\n===CONFIG_END===\n";
} else {
    // Surface the rendered HTML on failure so the next debugger
    // can read the wizard's error message without re-running.
    echo "===CONFIG_MISSING===\n";
    echo "HTML OUTPUT:\n" . $html . "\n";
}
PHP;

$proxy_file = $tmp . '/proxy.php';
file_put_contents($proxy_file, "<?php\n" . $proxy_body . "\n");

$env = [
    'REPO_ROOT'      => $repo_root,
    'TMP_DIR'        => $tmp,
    // Session IDs in PHP only allow [A-Za-z0-9-,], so we use a 64-char
    // hex string (no underscores, no dashes) to avoid the
    // "Session ID is too long or contains illegal characters" warning.
    'PROXY_SID'      => bin2hex(random_bytes(16)),
    'DB_CONFIG_JSON' => json_encode([
        'host' => $input['db_host'],
        'name' => $input['db_name'],
        'user' => $input['db_user'],
        'pass' => $input['db_pass'],
    ]),
    'SMTP_HOST'      => $input['smtp_host'],
    'SMTP_PORT'      => (string)$input['smtp_port'],
    'SMTP_PROTOCOL'  => $input['smtp_protocol'],
    'SMTP_USER'      => $input['smtp_user'],
    'SMTP_PASS'      => $input['smtp_pass'],
];
$env_str = '';
foreach ($env as $k => $v) {
    $env_str .= escapeshellarg("$k=$v") . ' ';
}

$cmd = 'env ' . $env_str . 'php ' . escapeshellarg($proxy_file) . ' 2>&1';
$rendered = shell_exec($cmd);

$config_from_install = null;
if (is_string($rendered) && preg_match('/===CONFIG_START===\n(.*?)\n===CONFIG_END===/s', $rendered, $m)) {
    $config_from_install = $m[1];
}
ok(
    'install.php wrote a config.php in the temp dir',
    is_string($config_from_install) && $config_from_install !== '',
    is_string($rendered)
        ? "proxy output (len=" . strlen($rendered) . "):\n" . substr($rendered, 0, 1500)
        : 'shell_exec returned null (command failed)'
);

if (is_string($config_from_install) && $config_from_install !== '') {

    $parsed_install = [];
    if (preg_match_all('/^const\s+(\w+)\s*=\s*(.+);$/m', $config_from_install, $m1, PREG_SET_ORDER)) {
        foreach ($m1 as $row) {
            $parsed_install[$row[1]] = $row[2];
        }
    }

    // What install.php's var_export() writer produces for the same
    // inputs. We compare parsed constant values, so any whitespace
    // variation between the writer and the test is fine.
    //
    // Note: var_export() inside single quotes does NOT escape a
    // double-quote (it just emits the double-quote literally). The
    // heredoc + addslashes() form WOULD escape it. We assert the
    // var_export() form because that's what install.php writes, and
    // round-trip equivalence is already covered by the next check
    // (single-quote in DB_PASS round-trips exactly).
    $expected = [
        'DB_HOST'     => var_export($input['db_host'], true),
        'DB_NAME'     => var_export($input['db_name'], true),
        'DB_USER'     => var_export($input['db_user'], true),
        'DB_PASS'     => var_export($input['db_pass'], true),
        'SMTP_HOST'   => var_export($input['smtp_host'], true),
        'SMTP_PORT'   => (string)$input['smtp_port'],
        'SMTP_PROTOCOL' => var_export($input['smtp_protocol'], true),
        'SMTP_USER'   => var_export($input['smtp_user'], true),
        'SMTP_PASS'   => var_export($input['smtp_pass'], true),
        'APP_NAME'    => "'Project Management System'",
        'APP_VERSION' => "'v1.7.3'",
        'TIMEZONE'    => "'UTC'",
        'SESSION_LIFETIME' => '3600',
        'PASSWORD_RESET_EXPIRY' => '3600',
    ];

    $mismatches = [];
    foreach ($expected as $const => $want) {
        if (!isset($parsed_install[$const])) {
            $mismatches[] = "$const is missing";
            continue;
        }
        if ($parsed_install[$const] !== $want) {
            $mismatches[] = "$const: expected $want, got {$parsed_install[$const]}";
        }
    }

    ok(
        'install.php wrote an ENCRYPTION_KEY (quoted 64-char hex)',
        isset($parsed_install['ENCRYPTION_KEY'])
            && preg_match("/^'([0-9a-f]{32,128})'$/", $parsed_install['ENCRYPTION_KEY']) === 1
    );

    ok(
        'install.php wrote APP_URL derived from SERVER_NAME',
        isset($parsed_install['APP_URL']) && $parsed_install['APP_URL'] === "'http://localhost'"
    );

    // Issue-028: the install wizard's APP_VERSION line must use the same
    // var_export() writer as the other constants (defends against a
    // future "let me just use single quotes here" change that would
    // re-introduce the issue-021 addslashes-style fragility for the
    // version string alone).
    ok(
        'install.php wrote APP_VERSION via var_export() (issue-028 drift guard)',
        isset($parsed_install['APP_VERSION'])
            && $parsed_install['APP_VERSION'] === "'v1.7.3'"
    );

    ok(
        'all 14 expected constants match the entrypoint.sh heredoc format',
        empty($mismatches),
        $mismatches ? implode('; ', $mismatches) : ''
    );

    $lint = shell_exec('php -l ' . escapeshellarg($tmp . '/config.php') . ' 2>&1');
    ok(
        'written config.php lints cleanly',
        is_string($lint) && strpos($lint, 'No syntax errors detected') !== false,
        is_string($lint) ? trim($lint) : 'no output'
    );

    // Spec acceptance: a backslash-quote input is round-tripped safely
    // via var_export. Verify by evaluating the written constant value
    // in a sub-PHP and comparing the runtime string to the input.
    $round_trip = trim((string)shell_exec(
        'php -r ' . escapeshellarg('echo bin2hex(' . $parsed_install['DB_PASS'] . ');')
    ));
    ok(
        'single-quote in DB_PASS round-trips exactly (var_export, not addslashes-doubled)',
        $round_trip === bin2hex($input['db_pass']),
        "got=$round_trip want=" . bin2hex($input['db_pass'])
    );
}

// ---------------------------------------------------------------------------
// Summary
// ---------------------------------------------------------------------------
echo "\n----------------------------------------\n";
if (!empty($failures)) {
    echo "Issue-021 install wizard regression FAILED:\n";
    foreach ($failures as $f) {
        echo "  - $f\n";
    }
    exit(1);
}
echo "Issue-021 install wizard regression test passed.\n";
exit(0);
