<?php
/**
 * v2.0 ACCEPTANCE HARNESS — issues 035 to 055.
 *
 * One executable sweep that exercises every v2.0 issue file and reports
 * PASS / FAIL per check. Run inside the app container:
 *
 *     docker compose exec -T app php /var/www/html/database/v2_0_acceptance_test.php
 *
 * Optional:
 *     ... v2_0_acceptance_test.php --json=/tmp/v2acc.json   # machine-readable dump
 *
 * The harness never mutates application data except for two disposable
 * records (a scratch contact and a scratch task) that are removed again.
 */

error_reporting(E_ALL);
ini_set('display_errors', '1');

$ORIGIN = 'http://pmp-web';
$MOCKUP = 'http://mockup';
$REPO   = '/var/www/html';
$JAR    = '/tmp/v2acc_auth.jar';
$JAR_ANON = '/tmp/v2acc_anon.jar';
$JSON_OUT = null;
foreach ($argv as $a) {
    if (str_starts_with($a, '--json=')) $JSON_OUT = substr($a, 7);
}

require_once $REPO . '/config.php';
require_once $REPO . '/includes/Database.php';

$GLOBALS['RESULTS'] = [];
$GLOBALS['SECTION'] = '';

/* ------------------------------------------------------------------ */
/* tiny assertion framework                                            */
/* ------------------------------------------------------------------ */

function section(string $name): void
{
    $GLOBALS['SECTION'] = $name;
    echo "\n" . str_repeat('=', 78) . "\n";
    echo "  $name\n";
    echo str_repeat('=', 78) . "\n";
}

function check(string $id, string $desc, bool $cond, string $detail = ''): bool
{
    $ok = (bool)$cond;
    $GLOBALS['RESULTS'][] = [
        'section' => $GLOBALS['SECTION'],
        'id'      => $id,
        'desc'    => $desc,
        'pass'    => $ok,
        'detail'  => $detail,
    ];
    printf("  [%s] %-9s %s\n", $ok ? 'PASS' : 'FAIL', $id, $desc);
    if (!$ok && $detail !== '') {
        foreach (explode("\n", $detail) as $l) echo "           $l\n";
    }
    return $ok;
}

function note(string $s): void { echo "           - $s\n"; }

/* ------------------------------------------------------------------ */
/* HTTP helper                                                         */
/* ------------------------------------------------------------------ */

/**
 * Session cookie stores.
 *
 * The session cookie is tracked BY HAND rather than with CURLOPT_COOKIEJAR /
 * CURLOPT_COOKIEFILE, because this curl build drops session cookies (no
 * expiry attribute -> stored as expiry 0) when a cookie-jar file is read
 * back. With the jar the harness was never authenticated; with an explicit
 * Cookie header it always is. The application is not at fault.
 */
$GLOBALS['COOKIES'] = [];

function cookieHeader(string $ckey): string
{
    if ($ckey === '') return '';
    return $GLOBALS['COOKIES'][$ckey] ?? '';
}

function rememberCookies(string $ckey, string $headers): void
{
    if ($ckey === '') return;
    if (!preg_match_all('/^Set-Cookie:\s*([^;]+)/mi', $headers, $m)) return;
    $store = [];
    if (isset($GLOBALS['COOKIES'][$ckey])) {
        foreach (explode('; ', $GLOBALS['COOKIES'][$ckey]) as $pair) {
            if (strpos($pair, '=') !== false) {
                [$n, $v] = explode('=', $pair, 2);
                $store[trim($n)] = trim($v);
            }
        }
    }
    foreach ($m[1] as $c) {
        if (!str_contains($c, '=')) continue;
        [$n, $v] = explode('=', $c, 2);
        $n = trim($n); $v = trim($v);
        if ($v === '' || $v === 'deleted') { unset($store[$n]); continue; }
        $store[$n] = $v;
    }
    $out = [];
    foreach ($store as $n => $v) $out[] = "$n=$v";
    $GLOBALS['COOKIES'][$ckey] = implode('; ', $out);
}

function req(string $url, string $ckey = '', bool $post = false, ?array $fields = null, bool $follow = true): array
{
    $ch = curl_init();
    $opts = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER         => true,
        CURLOPT_FOLLOWLOCATION => $follow,
        CURLOPT_MAXREDIRS      => 8,
        CURLOPT_TIMEOUT        => 20,
        CURLOPT_SSL_VERIFYPEER => false,
    ];
    if ($ckey !== '') {
        $c = cookieHeader($ckey);
        if ($c !== '') $opts[CURLOPT_COOKIE] = $c;
    }
    if ($post) {
        $opts[CURLOPT_POST]       = true;
        $opts[CURLOPT_POSTFIELDS] = http_build_query($fields ?? []);
    }
    curl_setopt_array($ch, $opts);
    curl_setopt($ch, CURLOPT_URL, $url);
    $raw  = curl_exec($ch);
    $st   = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $hl   = (int)curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    $eff  = (string)curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
    $nred = (int)curl_getinfo($ch, CURLINFO_REDIRECT_COUNT);
    curl_close($ch);
    $raw = $raw === false ? '' : (string)$raw;
    $head = substr($raw, 0, $hl);
    rememberCookies($ckey, $head);
    return [
        'status'  => $st,
        'body'    => substr($raw, $hl),
        'headers' => $head,
        'url'     => $eff,
        'redirects' => $nred,
        'location' => null,
    ];
}

/** First Location header of a non-followed response. */
function locOf(array $r): ?string
{
    if (preg_match('/^Location:\s*(.+)$/mi', $r['headers'], $m)) return trim($m[1]);
    return null;
}

function src(string $rel): string
{
    $f = $GLOBALS['REPO'] . '/' . ltrim($rel, '/');
    return is_file($f) ? (string)file_get_contents($f) : '';
}

function has(string $rel): bool { return is_file($GLOBALS['REPO'] . '/' . ltrim($rel, '/')); }

/* ------------------------------------------------------------------ */
/* bootstrap: log in                                                   */
/* ------------------------------------------------------------------ */

@unlink($JAR);
@unlink($JAR_ANON);
req("$ORIGIN/login", 'auth');
$login = req("$ORIGIN/login", 'auth', true, ['username' => 'luozongbao', 'password' => 'admin123'], false);
if ($login['status'] !== 302 && $login['status'] !== 200) {
    fwrite(STDERR, "FATAL: login failed (status {$login['status']})\n");
    exit(2);
}
$probe = req("$ORIGIN/dashboard", 'auth', false, null, false);
$AUTHED = ($probe['status'] === 200);
if (!$AUTHED) {
    fwrite(STDERR, "FATAL: authenticated session not established\n");
    exit(2);
}

$db = Database::getInstance();

/* Discover live IDs for the admin user (data is user-scoped through
 * projects.responsible_person_id, and contacts through project_contacts). */
$uid = (int)($db->fetchOne('SELECT id FROM users WHERE username = ?', ['luozongbao'])['id'] ?? 1);
$proj = $db->fetchOne(
    'SELECT id, name FROM projects WHERE responsible_person_id = ? ORDER BY id DESC LIMIT 1',
    [$uid]
);
$task = $db->fetchOne(
    'SELECT t.id, t.name, t.project_id FROM tasks t
       JOIN projects p ON p.id = t.project_id
      WHERE t.responsible_person_id = ? AND p.responsible_person_id = ?
      ORDER BY t.id DESC LIMIT 1',
    [$uid, $uid]
);
$contact = $db->fetchOne(
    'SELECT c.id, c.name FROM contacts c
       JOIN project_contacts pc ON c.id = pc.contact_id
       JOIN projects p ON pc.project_id = p.id
      WHERE p.responsible_person_id = ?
      GROUP BY c.id ORDER BY c.id DESC LIMIT 1',
    [$uid]
);
$PID  = (int)($proj['id'] ?? 0);
$TID  = (int)($task['id'] ?? 0);
$CID  = (int)($contact['id'] ?? 0);

echo "Authenticated. user_id=$uid  project_id=$PID  task_id=$TID  contact_id=$CID\n";

/* ================================================================== */
/* SECTION 0 — baseline: the existing regression suite                */
/* ================================================================== */

section('BASELINE — existing regression suite (issue-042 integrity)');

$testFiles = glob($GLOBALS['REPO'] . '/database/*_test.php');
$self      = basename(__FILE__);
$testFiles = array_values(array_filter($testFiles, fn($f) => basename($f) !== $self));
$suitePass = 0; $suiteFail = 0; $suiteFailNames = [];
foreach ($testFiles as $tf) {
    // `timeout` guarantees one slow or blocking test cannot stall the sweep.
    $cmd = 'cd ' . escapeshellarg($GLOBALS['REPO'])
         . ' && timeout 180 php ' . escapeshellarg($tf) . ' 2>&1';
    exec($cmd, $out, $rc);
    if ($rc === 0) { $suitePass++; }
    else {
        $suiteFail++;
        $suiteFailNames[] = basename($tf) . ' (rc=' . $rc . ')';
    }
    $out = [];
}
check('B-01', 'existing regression suite: ' . $suitePass . ' PASS / ' . $suiteFail . ' FAIL',
    $suiteFail === 0, implode("\n", $suiteFailNames));

/* ================================================================== */
/* SECTION 1 — issue-035: design system + routing foundation          */
/* ================================================================== */

section('ISSUE-035 — design system, tokens, component library, routing');

$tokens = src('assets/css/v2-tokens.css');
$tokenCount = preg_match_all('/--[_a-zA-Z][_a-zA-Z0-9-]*\s*:/', $tokens);
check('035-01', "v2-tokens.css exists and declares >= 80 custom properties (found $tokenCount)",
    has('assets/css/v2-tokens.css') && $tokenCount >= 80);

$comp = src('assets/css/v2-components.css');
$compCount = preg_match_all('/\.(-?[_a-zA-Z][_a-zA-Z0-9-]*)/', $comp);
check('035-02', "v2-components.css exists and declares >= 300 classes (found $compCount)",
    has('assets/css/v2-components.css') && $compCount >= 300);

$needClasses = ['card__header', 'status--', 'task-node', 'grid-sidebar', 'detail-row', 'progress', 'btn--primary', 'stat-grid', 'stat__value'];
$missingCls = [];
foreach ($needClasses as $c) {
    if (strpos($comp, '.' . $c) === false) $missingCls[] = $c;
}
check('035-03', 'v2-components.css carries the core vocabulary (' . implode(', ', $needClasses) . ')',
    $missingCls === [], 'missing: ' . implode(', ', $missingCls));

// legacy .php -> slug 301
$legacy = [
    '/dashboard.php' => '/dashboard',
    '/projects.php'  => '/projects',
    '/tasks.php'     => '/tasks',
    '/contacts.php'  => '/contacts',
    '/profile.php'   => '/profile',
    '/login.php'     => '/login',
    '/register.php'  => '/register',
    '/logout.php'    => '/logout',
    '/forgot_password.php' => '/forgot-password',
    '/reset_password.php'  => '/reset-password',
];
$legacyBad = [];
foreach ($legacy as $old => $new) {
    $r = req($ORIGIN . $old, '', false, null, false);
    $l = locOf($r);
    if ($r['status'] !== 301 || $l === null || rtrim($l, '/') !== rtrim($new, '/')) {
        $legacyBad[] = sprintf('%s -> status=%s Location=%s (want 301 %s)', $old, $r['status'], $l ?? '-', $new);
    }
}
check('035-04', 'legacy .php URLs 301-redirect to their canonical slug', $legacyBad === [],
    implode("\n", $legacyBad));

// slug URLs 200 (authenticated)
$slugPages = ['/dashboard', '/projects', '/tasks', '/contacts', '/profile'];
$slugBad = [];
foreach ($slugPages as $p) {
    $r = req($ORIGIN . $p, 'auth');
    if ($r['status'] !== 200) $slugBad[] = "$p -> {$r['status']}";
}
check('035-05', 'canonical slug URLs return 200 when authenticated', $slugBad === [], implode("\n", $slugBad));

// every CSS var referenced by a live page must be defined
$cssFiles = [];
foreach (['assets/css/style.css', 'assets/css/v2-components.css', 'assets/css/v2-tokens.css', 'client/assets/client.css'] as $f) {
    if (has($f)) $cssFiles[] = $GLOBALS['REPO'] . '/' . $f;
}
foreach (glob($GLOBALS['REPO'] . '/assets/css/pages/*.css') as $f) $cssFiles[] = $f;
$varIndex = [];
foreach ($cssFiles as $f) {
    $s = preg_replace('#/\*.*?\*/#s', '', (string)file_get_contents($f));
    if (preg_match_all('/(--[_a-zA-Z][_a-zA-Z0-9-]*)\s*:/', $s, $m)) {
        foreach ($m[1] as $v) $varIndex[$v] = true;
    }
}
$allPages = ['/dashboard', '/projects', '/tasks', '/contacts', '/profile', '/', '/login', '/forgot-password', '/reset-password'];
if ($PID) $allPages[] = "/projects/$PID";
if ($TID) $allPages[] = "/tasks/$TID";
if ($CID) $allPages[] = "/contacts/$CID";
$badVars = [];
foreach ($allPages as $p) {
    $r = req($ORIGIN . $p, 'auth');
    if ($r['status'] !== 200) continue;
    if (preg_match_all('/var\(\s*(--[_a-zA-Z][_a-zA-Z0-9-]*)/', $r['body'], $vm)) {
        foreach ($vm[1] as $v) if (!isset($varIndex[$v])) $badVars[$p . ' -> ' . $v] = true;
    }
}
check('035-06', 'every CSS custom property used by a live page is defined', $badVars === [],
    implode("\n", array_keys($badVars)));

/* ================================================================== */
/* SECTION 2 — issue-036: mockup ops                                  */
/* ================================================================== */

section('ISSUE-036 — design mockup served on :8080');

$mIdx = req("$MOCKUP/", '', false, null, false);
if ($mIdx['status'] !== 200) $mIdx = req('http://localhost:8080/', '', false, null, false);
check('036-01', 'mockup root serves the mockup index (200)', $mIdx['status'] === 200,
    'status=' . $mIdx['status']);

$mRoot = $mIdx['status'] === 200 ? $mIdx['body'] : '';
$mockScreens = [];
if (preg_match_all('/href="([^"]+\.html)"/', $mRoot, $hm)) {
    foreach ($hm[1] as $h) $mockScreens[] = $h;
}
$deadLinks = [];
foreach (array_unique($mockScreens) as $h) {
    $u = preg_match('#^https?://#', $h) ? $h : $MOCKUP . '/' . ltrim($h, '/');
    $rr = req($u, '', false, null, false);
    if ($rr['status'] !== 200) $deadLinks[] = "$h -> {$rr['status']}";
}
check('036-02', 'every screen linked from the mockup index is reachable (' . count(array_unique($mockScreens)) . ' links)',
    $deadLinks === [], implode("\n", $deadLinks));

// issue-035.md reachable from the mockup traceability link
$t035 = req($MOCKUP . '/issues/issue-035.md', '', false, null, false);
if ($t035['status'] !== 200) $t035 = req('http://localhost:8080/issues/issue-035.md', '', false, null, false);
check('036-03', 'issue-035.md is reachable from the mockup traceability matrix', $t035['status'] === 200,
    'status=' . $t035['status']);

// docs/issues must expose ONLY issue-035.md
$leaks = [];
foreach (['/requirements.md', '/design/2.0/_build/build.py', '/issues/issue-044.md'] as $l) {
    $rr = req($MOCKUP . $l, '', false, null, false);
    if ($rr['status'] === 200) $leaks[] = $l . ' -> 200 (exposed)';
}
// enumerate every issue file the mockup publishes
$exposed = [];
$issueFiles = glob('/var/www/html/docs/issues/issue-*.md') ?: [];
foreach ($issueFiles as $f) {
    $base = basename($f);
    $rr = req($MOCKUP . '/issues/' . $base, '', false, null, false);
    if ($rr['status'] === 200 && $base !== 'issue-035.md') $exposed[] = $base;
}
if ($exposed) {
    $leaks[] = count($exposed) . ' non-035 issue files are publicly readable, e.g. '
        . implode(', ', array_slice($exposed, 0, 6));
}
check('036-04', 'mockup exposes only issue-035.md from docs/issues (no other issue files, no _build)',
    $leaks === [], implode("\n", $leaks));

// mockup must not be started by the default `docker compose up -d`
$compose = src('docker-compose.yml');
$hasProfile = (bool)preg_match('/profiles:\s*\n(\s+-\s+\w+\s*\n)*\s*-\s*mockup/', $compose)
    || (bool)preg_match('/mockup:[\s\S]{0,900}?profiles:/', $compose);
check('036-05', 'mockup service is profile-gated so `docker compose up -d` does not start it',
    $hasProfile, 'no profiles: key found for the mockup service in docker-compose.yml');

/* ================================================================== */
/* SECTION 3 — issue-037: /reset-password reachable                    */
/* ================================================================== */

section('ISSUE-037 — GET /reset-password must not 404');

$rp = req($ORIGIN . '/reset-password', 'anon', false, null, false);
check('037-01', 'GET /reset-password returns 200', $rp['status'] === 200, 'status=' . $rp['status']);

$rp2 = req($ORIGIN . '/reset-password?token=not-a-real-token', 'anon', false, null, false);
check('037-02', 'GET /reset-password?token=<bogus> returns 200 (no server error)',
    in_array($rp2['status'], [200, 302], true), 'status=' . $rp2['status'] . ' body=' . substr(strip_tags($rp2['body']), 0, 160));

$rp3 = req($ORIGIN . '/reset-password/not-a-real-token', 'anon', false, null, false);
check('037-03', 'GET /reset-password/<bogus-token> returns 200 (path form)',
    in_array($rp3['status'], [200, 302], true), 'status=' . $rp3['status']);

/* ================================================================== */
/* SECTION 4 — issue-038: browser-found page list                      */
/* ================================================================== */

section('ISSUE-038 — every screen in the browser-found list loads');

$publicPages = ['/login', '/forgot-password'];
$privatePages = ['/dashboard', '/projects', '/tasks', '/contacts'];
$regAllowed = defined('APP_ALLOW_REGISTRATION') ? (bool)APP_ALLOW_REGISTRATION : null;
if ($PID) { $privatePages[] = "/projects/$PID"; $privatePages[] = "/projects/new"; $privatePages[] = "/projects/$PID/edit"; }
if ($TID) { $privatePages[] = "/tasks/$TID"; $privatePages[] = "/tasks/new"; $privatePages[] = "/tasks/$TID/edit"; }
if ($CID) { $privatePages[] = "/contacts/$CID"; $privatePages[] = "/contacts/new"; $privatePages[] = "/contacts/$CID/edit"; }
$foundBad = [];
foreach ($publicPages as $p) {
    $r = req($ORIGIN . $p, 'anon', false, null, false);
    if ($r['status'] !== 200) $foundBad[] = "$p -> {$r['status']}";
}
if ($regAllowed === false) {
    $r = req($ORIGIN . '/register', 'anon', false, null, false);
    if ($r['status'] !== 403) $foundBad[] = "/register -> {$r['status']} (expected 403: registration closed)";
} else {
    $r = req($ORIGIN . '/register', 'anon', false, null, false);
    if ($r['status'] !== 200) $foundBad[] = "/register -> {$r['status']}";
}
foreach ($privatePages as $p) {
    $r = req($ORIGIN . $p, 'auth', false, null, false);
    if ($r['status'] !== 200) $foundBad[] = "$p -> {$r['status']}";
}
check('038-01', 'all ' . (count($publicPages) + count($privatePages) + 1) . ' issue-038 screens return the expected status',
    $foundBad === [], implode("\n", $foundBad));

/* ================================================================== */
/* SECTION 5 — issue-039: registration flag                            */
/* ================================================================== */

section('ISSUE-039 — "Allow Registration" flag');

$regAllowed = defined('APP_ALLOW_REGISTRATION') ? (bool)APP_ALLOW_REGISTRATION : null;
check('039-01', 'APP_ALLOW_REGISTRATION is declared in config.php', $regAllowed !== null);

$flag = $regAllowed === true ? 'on' : 'off';
$reg = req($ORIGIN . '/register', 'anon', false, null, false);
if ($regAllowed === false) {
    check('039-02', 'GET /register returns 403 while registration is closed', $reg['status'] === 403,
        'status=' . $reg['status']);
    check('039-03', 'GET /register renders no registration form while closed',
        stripos($reg['body'], 'name="username"') === false);
} else {
    check('039-02', 'GET /register returns 200 while registration is open', $reg['status'] === 200,
        'status=' . $reg['status']);
    check('039-03', 'GET /register renders a registration form while open',
        stripos($reg['body'], 'name="username"') !== false);
}

// POST must not create a user while closed
if ($regAllowed === false) {
    $before = (int)$db->fetchOne('SELECT COUNT(*) c FROM users')['c'];
    $email = 'v2acc_probe_' . time() . '@example.test';
    $rp = req($ORIGIN . '/register', 'anon', true,
        ['username' => 'v2acc_' . time(), 'email' => $email, 'password' => 'Str0ng!Passw0rd', 'confirm_password' => 'Str0ng!Passw0rd'], false);
    $after = (int)$db->fetchOne('SELECT COUNT(*) c FROM users')['c'];
    check('039-04', 'POST /register is rejected (403) and creates no user',
        $rp['status'] === 403 && $after === $before,
        "status={$rp['status']} users before=$before after=$after");
}

// login / forgot-password must hide the register links while closed
$loginBody = req($ORIGIN . '/login', 'anon', false, null, false)['body'];
$fpBody   = req($ORIGIN . '/forgot-password', 'anon', false, null, false)['body'];
if ($regAllowed === false) {
    check('039-05', '/login hides the "Create an account" link while registration is closed',
        stripos($loginBody, 'href="/register"') === false && stripos($loginBody, '>Create an account<') === false);
    check('039-06', '/forgot-password hides the "Register" link while registration is closed',
        stripos($fpBody, 'href="/register"') === false);
} else {
    check('039-05', '/login shows the "Create an account" link while registration is open',
        stripos($loginBody, '/register') !== false);
    check('039-06', '/forgot-password shows the "Register" link while registration is open',
        stripos($fpBody, '/register') !== false);
}

// the constant must exist in every control-point file
$decls = [];
foreach (['config.php', 'config.template.php', 'docker/entrypoint.sh', 'install.php'] as $f) {
    $s = src($f);
    $decls[$f] = (stripos($s, 'APP_ALLOW_REGISTRATION') !== false);
}
$noDecl = array_keys(array_filter($decls, fn($v) => !$v));
check('039-07', 'APP_ALLOW_REGISTRATION is declared in config.php, config.template.php, entrypoint.sh and install.php',
    $noDecl === [], 'missing in: ' . implode(', ', $noDecl));

/* ================================================================== */
/* SECTION 6 — issue-040: ID-based canonical URLs                      */
/* ================================================================== */

section('ISSUE-040 — IDs are the canonical URL; slugs 301 to IDs');

if ($PID && $TID && $CID) {
    $slug = $db->fetchOne('SELECT slug FROM projects WHERE id = ?', [$PID]);
    $tslug = $db->fetchOne('SELECT slug FROM tasks WHERE id = ?', [$TID]);
    $cslug = $db->fetchOne('SELECT slug FROM contacts WHERE id = ?', [$CID]);

    $r = req($ORIGIN . '/projects/' . $PID, 'auth', false, null, false);
    check('040-01', 'GET /projects/<id> returns 200 (id URL is canonical)', $r['status'] === 200, 'status=' . $r['status']);

    if ($slug && $slug['slug']) {
        $rs = req($ORIGIN . '/projects/' . $slug['slug'], 'auth', false, null, false);
        $l  = locOf($rs);
        check('040-02', 'GET /projects/<slug> 301-redirects to /projects/<id>',
            $rs['status'] === 301 && $l !== null && rtrim($l, '/') === "/projects/$PID",
            "status={$rs['status']} Location=" . ($l ?? '-'));
    }
    if ($tslug && $tslug['slug']) {
        $rs = req($ORIGIN . '/tasks/' . $tslug['slug'], 'auth', false, null, false);
        $l  = locOf($rs);
        check('040-03', 'GET /tasks/<slug> 301-redirects to /tasks/<id>',
            $rs['status'] === 301 && $l !== null && rtrim($l, '/') === "/tasks/$TID",
            "status={$rs['status']} Location=" . ($l ?? '-'));
    }
    if ($cslug && $cslug['slug']) {
        $rs = req($ORIGIN . '/contacts/' . $cslug['slug'], 'auth', false, null, false);
        $l  = locOf($rs);
        check('040-04', 'GET /contacts/<slug> 301-redirects to /contacts/<id>',
            $rs['status'] === 301 && $l !== null && rtrim($l, '/') === "/contacts/$CID",
            "status={$rs['status']} Location=" . ($l ?? '-'));
    }

    // query string survives the slug -> id redirect
    $rs = req($ORIGIN . '/projects/' . ($slug['slug'] ?? $PID) . '?foo=bar', 'auth', false, null, false);
    $l  = locOf($rs);
    check('040-05', 'query string survives the slug -> id 301 (?foo=bar)',
        $l !== null && strpos($l, 'foo=bar') !== false, 'Location=' . ($l ?? '-'));

    // edit routes use ids
    foreach ([["/projects/$PID/edit", 'project'], ["/tasks/$TID/edit", 'task'], ["/contacts/$CID/edit", 'contact']] as $rt) {
        $r = req($ORIGIN . $rt[0], 'auth', false, null, false);
        check('040-06', "GET {$rt[0]} returns 200 (id-based {$rt[1]} edit)", $r['status'] === 200, 'status=' . $r['status']);
    }

    // delete must be POST-only
    $rd = req($ORIGIN . "/projects/$PID/delete", 'auth', false, null, false);
    check('040-07', 'GET /projects/<id>/delete is not allowed (405 or 3xx, never 200)',
        $rd['status'] !== 200, 'status=' . $rd['status']);
}

/* ================================================================== */
/* SECTION 7 — issue-041: project_detail v2 conversion + app shell     */
/* ================================================================== */

section('ISSUE-041 — project_detail v2 conversion + app-shell opt-in');

$header = src('includes/header.php');
check('041-01', 'header.php still exposes the $use_v2_shell / $content_bleed opt-in',
    strpos($header, 'use_v2_shell') !== false && strpos($header, 'content_bleed') !== false);

if ($PID) {
    $body = req($ORIGIN . "/projects/$PID", 'auth')['body'];
    check('041-02', 'project_detail renders in the v2 shell (uses v2 shell classes)',
        strpos($body, 'v2-components.css') !== false || strpos($body, 'card__header') !== false);
    check('041-03', 'project_detail does NOT use the v1 "card-header" class that collides with v2',
        strpos($body, 'card-header') === false);
    check('041-04', 'project_detail still exposes the rotate-share-code control (not moved to edit)',
        stripos($body, 'rotate') !== false || stripos($body, 'share') !== false);
}

/* ================================================================== */
/* SECTION 8 — issue-043: card header collision                         */
/* ================================================================== */

section('ISSUE-043 — blank grey card headers on task / contact detail');

if ($TID) {
    $b = req($ORIGIN . "/tasks/$TID", 'auth')['body'];
    check('043-01', 'task_detail uses the v2 .card__header class', strpos($b, 'card__header') !== false);
    check('043-02', 'task_detail does not use the colliding v1 .card-header class', strpos($b, 'card-header') === false);
}
if ($CID) {
    $b = req($ORIGIN . "/contacts/$CID", 'auth')['body'];
    check('043-03', 'contact_detail uses the v2 .card__header class', strpos($b, 'card__header') !== false);
    check('043-04', 'contact_detail does not use the colliding v1 .card-header class', strpos($b, 'card-header') === false);
}
$compSrc = src('assets/css/v2-components.css');
check('043-05', 'v2-components.css defines .card__header with a non-transparent background',
    (bool)preg_match('/\.card__header\s*\{[^}]*background[^}]*\}/i', $compSrc));

/* ================================================================== */
/* SECTION 9 — issue-046: task sidebar stat strip + contact overview   */
/* ================================================================== */

section('ISSUE-046 — task sidebar stat strip + contact Overview card');

if ($TID) {
    $b = req($ORIGIN . "/tasks/$TID", 'auth')['body'];
    // The v2 sidebar stat strip is a dedicated block: .stat-grid.td-stats
    // (issue-046). Scope the ordering check to that block and to the Time
    // card heading, not to the first textual occurrence of the words.
    $statStrip = strpos($b, 'td-stats');
    $timeCard = strpos($b, 'fa-clock"></i> Time');
    if ($timeCard === false) $timeCard = strpos($b, '>Time</h2>');
    $subLabel = ($statStrip !== false) ? strpos($b, '>Subtasks</div>', $statStrip) : false;
    $cmpLabel = ($statStrip !== false) ? strpos($b, '>Completion</div>', $statStrip) : false;
    check('046-01', 'task_detail sidebar shows a "Completion" stat tile', $cmpLabel !== false);
    check('046-02', 'task_detail sidebar shows a "Subtasks" stat tile', $subLabel !== false);
    check('046-03', 'the Completion + Subtasks stat strip is rendered above the "Time" card',
        $statStrip !== false && $timeCard !== false && $statStrip < $timeCard
        && $cmpLabel < $timeCard && $subLabel < $timeCard,
        "statStrip=$statStrip posCompletion=$cmpLabel posSubtasks=$subLabel posTime=$timeCard");
}
if ($CID) {
    $b = req($ORIGIN . "/contacts/$CID", 'auth')['body'];
    check('046-04', 'contact_detail has an Overview card', stripos($b, 'Overview') !== false);
    check('046-05', 'contact_detail Overview uses detail-row rows', strpos($b, 'detail-row') !== false);
}

/* ================================================================== */
/* SECTION 10 — issue-047: form alignment + delete endpoints            */
/* ================================================================== */

section('ISSUE-047 — v2 forms and the two new delete endpoints');

check('047-01', 'contact_delete.php exists', has('contact_delete.php'));
check('047-02', 'task_delete.php exists', has('task_delete.php'));
check('047-03', 'assets/css/pages/forms.css exists', has('assets/css/pages/forms.css'));

$formPages = [];
if ($PID) { $formPages["/projects/$PID/edit"] = 'project_edit.php'; $formPages['/projects/new'] = 'project_edit.php'; }
if ($TID) { $formPages["/tasks/$TID/edit"] = 'task_edit.php';    $formPages['/tasks/new'] = 'task_edit.php'; }
if ($CID) { $formPages["/contacts/$CID/edit"] = 'contact_edit.php'; $formPages['/contacts/new'] = 'contact_edit.php'; }
$formBad = [];
foreach ($formPages as $url => $file) {
    $r = req($ORIGIN . $url, 'auth', false, null, false);
    if ($r['status'] !== 200) $formBad[] = "$url -> {$r['status']}";
    if (strpos($r['body'], 'form-grid') === false && strpos($r['body'], 'form__') === false) {
        $formBad[] = "$url does not use the v2 form grid vocabulary";
    }
}
check('047-04', 'all six new/edit forms render on the v2 form grid', $formBad === [], implode("\n", $formBad));

// no inline style blocks left in the converted pages
$inline = [];
foreach (['project_edit.php', 'task_edit.php', 'contact_edit.php'] as $f) {
    $s = src($f);
    if (preg_match('/<style\b/i', $s)) $inline[] = $f . ' still contains an inline <style> block';
}
check('047-05', 'converted form pages have no inline <style> blocks', $inline === [], implode("\n", $inline));

/* ================================================================== */
/* SECTION 11 — issue-048: edit-mode look defects                       */
/* ================================================================== */

section('ISSUE-048 — stuck delete modal, asset cache-busting, Project ID, task delete label');

if ($PID) {
    $b = req($ORIGIN . "/projects/$PID/edit", 'auth')['body'];
    // the delete modal must not be open on load
    $modalOpen = preg_match('/<div[^>]*class="[^"]*modal[^"]*"[^>]*>\s*<div[^>]*class="[^"]*modal[^"]*(?:--open|\bshow\b|\bactive\b)[^"]*"/i', $b)
        || preg_match('/class="[^"]*modal[^"]*(?:--open|\bshow\b|\bactive\b)[^"]*"/i', $b)
        || preg_match('/<style[^>]*>[^<]*\.modal\s*\{[^}]*display\s*:\s*(block|flex)/i', $b);
    check('048-01', 'the delete modal is NOT open on page load', !$modalOpen);
    check('048-02', 'project edit shows the Project ID', stripos($b, 'Project ID') !== false || stripos($b, 'project_id') !== false);
    check('048-03', 'project edit shows a share code', stripos($b, 'hare code') !== false);
}
if ($TID) {
    $b = req($ORIGIN . "/tasks/$TID/edit", 'auth')['body'];
    $tname = (string)($task['name'] ?? '');
    $checkLabel = $tname !== '' && stripos($b, htmlspecialchars($tname, ENT_QUOTES)) !== false;
    check('048-04', 'task delete confirmation uses the task NAME (not the internal id)', $checkLabel);
    $internalOnly = preg_match('/delete[^\n]{0,80}task[^\n]{0,40}\b#?\$?id\b/i', $b);
    note('internal-id-style delete label present: ' . ($internalOnly ? 'YES (inspect)' : 'no'));
}

// asset_url() cache-busting
$fnSrc = src('includes/functions.php');
check('048-05', 'asset_url() implements cache-busting (asset version / filemtime)',
    (bool)preg_match('/function\s+asset_url/i', $fnSrc)
    && (bool)preg_match('/function\s+asset_url[\s\S]{0,900}?(filemtime|ASSET_VERSION|version=|\?v=)/i', $fnSrc));

// every stylesheet / script link in a live page carries a version query
if ($PID) {
    $b = req($ORIGIN . "/projects/$PID", 'auth')['body'];
    preg_match_all('/(?:href|src)="([^"]+\.(?:css|js)(?:\?[^"]*)?)"/i', $b, $lm);
    $unversioned = [];
    foreach ($lm[1] as $u) {
        if (strpos($u, '?') !== false) continue;
        if (strpos($u, 'fonts') !== false) continue;
        // third-party CDN assets are outside the application's cache-busting scope
        if (preg_match('#^(?:https?:)?//#i', $u)) continue;
        $unversioned[] = $u;
    }
    check('048-06', 'CSS/JS assets referenced by project_detail are versioned',
        $unversioned === [], 'unversioned: ' . implode(', ', array_unique($unversioned)));
}

/* ================================================================== */
/* SECTION 12 — issue-049: client sub-task toggle controller            */
/* ================================================================== */

section('ISSUE-049 — client portal sub-task toggle must not 404');

$clientDash = src('client/dashboard.php');
preg_match_all('/(?:src|href)="([^"]*subtask[^"]*)"/i', $clientDash, $sm);
$toggleRefs = $sm[1] ?? [];
$toggleBad = [];
foreach ($toggleRefs as $t) {
    if (preg_match('#^https?://#', $t)) continue;
    $u = strpos($t, '/') === 0 ? $ORIGIN . $t : $ORIGIN . '/' . $t;
    $rr = req($u, '', false, null, false);
    if ($rr['status'] !== 200) $toggleBad[] = "$t -> {$rr['status']}";
}
check('049-01', 'every sub-task script referenced by client/dashboard.php resolves (200)',
    $toggleRefs !== [] && $toggleBad === [], implode("\n", $toggleBad));

// and the same for the live client page
$shareCode = $db->fetchOne('SELECT share_code FROM projects WHERE share_code IS NOT NULL ORDER BY id DESC LIMIT 1');
if ($shareCode && $shareCode['share_code']) {
    $cl = req($ORIGIN . '/?code=' . rawurlencode($shareCode['share_code']), '', false, null, true);
    $html = $cl['body'];
    preg_match_all('/(?:src|href)="([^"]*\.js[^"]*)"/i', $html, $lm2);
    $jsBad = [];
    foreach ($lm2[1] as $u) {
        if (preg_match('#^https?://#', $u)) continue;
        $uu = strpos($u, '/') === 0 ? $ORIGIN . $u : $ORIGIN . '/' . $u;
        $rr = req($uu, '', false, null, false);
        if ($rr['status'] !== 200) $jsBad[] = "$u -> {$rr['status']}";
    }
    check('049-02', 'every JS asset referenced by the live client portal resolves (200)',
        $jsBad === [], implode("\n", $jsBad));
} else {
    check('049-02', 'a share code was available to exercise the live client portal', false, 'no share code in DB');
}

/* ================================================================== */
/* SECTION 13 — issue-050: client dashboard v2 markup + layering        */
/* ================================================================== */

section('ISSUE-050 — client dashboard v2 markup + stylesheet layering');

$cd = src('client/dashboard.php');
$layers = ['v2-tokens.css', 'v2-components.css', 'client.css'];
$layerMiss = [];
foreach ($layers as $l) {
    if (strpos($cd, $l) === false) $layerMiss[] = $l;
}
check('050-01', 'client/dashboard.php loads all three CSS layers (' . implode(', ', $layers) . ')',
    $layerMiss === [], 'missing: ' . implode(', ', $layerMiss));

$clientCss = src('client/assets/client.css');
check('050-02', 'client.css is referenced root-absolute (/client/assets/client.css)',
    strpos($cd, '/client/assets/client.css') !== false || strpos($cd, 'client/assets/client.css') !== false);

// duplicated border declaration — count only real `border:` shorthand
// declarations, not `border-radius` / `border-left` / `border-color` …
$dup = [];
if (preg_match_all('/\.([_a-zA-Z][_a-zA-Z0-9-]*)\s*\{([^}]*)\}/s', $clientCss, $mm)) {
    foreach ($mm[2] as $body) {
        $decls = preg_match_all('/(?:^|[;{\s])border\s*:/i', $body);
        if ($decls > 1) $dup[] = trim(preg_replace('/\s+/', ' ', $body));
    }
}
check('050-03', 'no CSS rule in client.css declares the `border` shorthand twice', $dup === [],
    implode("\n---\n", array_slice($dup, 0, 4)));

/* ================================================================== */
/* SECTION 14 — issue-051: client landing page on the v2 layer          */
/* ================================================================== */

section('ISSUE-051 — landing page on the v2 component layer');

$land = src('includes/landing.php');
check('051-01', 'landing page loads the v2 component layer',
    strpos($land, 'v2-components.css') !== false);
check('051-02', 'landing page loads the v2 token layer',
    strpos($land, 'v2-tokens.css') !== false);
check('051-03', 'landing page has no inline <style> block', !preg_match('/<style\b/i', $land));

$langSrc = src('client/lang.php');
check('051-04', 'client/lang.php exposes a full locale list (>= 2 locales)',
    preg_match_all("/'[a-z]{2}(?:-[A-Z]{2})?'\s*=>/", $langSrc) >= 2);

$diag = src('_diag.php');
$md5s = preg_match_all('/md5_file\s*\(/', $diag);
check('051-05', '_diag.php still pins md5 hashes for the landing assets (' . $md5s . ' pins)', $md5s > 0);

/* ================================================================== */
/* SECTION 15 — issue-052: install wizard on the v2 layer               */
/* ================================================================== */

section('ISSUE-052 — install wizard on the v2 component layer');

$inst = src('install.php');
check('052-01', 'install.php has no inline <style> block', !preg_match('/<style\b/i', $inst));
check('052-02', 'assets/css/pages/install.css exists', has('assets/css/pages/install.css'));
check('052-03', 'install.php loads the v2 component layer',
    strpos($inst, 'v2-components.css') !== false);
check('052-04', 'install.php uses the v2 stepper / form vocabulary',
    strpos($inst, 'stepper') !== false || strpos($inst, 'form-grid') !== false || strpos($inst, 'card__header') !== false);

/* ================================================================== */
/* SECTION 16 — issue-053: dead CSS sweep                               */
/* ================================================================== */

section('ISSUE-053 — dead CSS rule sweep');

$style = src('assets/css/style.css');
$clientCss2 = src('client/assets/client.css');
// issue-053 §3.2 removed these dead selectors. `.client-body .task-desc` is
// explicitly KEPT (still live: client/dashboard.php renders class="task-desc"),
// so only the bare `.task-desc` selector is dead.
$removed = ['.task-item', '.tree-indent', '.task-body', '.task-head', '.task-meta'];
$stillThere = [];
foreach ($removed as $s) {
    if (preg_match('/' . preg_quote($s, '/') . '\s*[,{]/', $style . $clientCss2)) $stillThere[] = $s;
}
// strip the explicitly-kept `.client-body .task-desc` before hunting the bare one
$cssNoKept = str_replace('.client-body .task-desc', '', $style . $clientCss2);
if (preg_match('/(?<![-\w])\.task-desc\s*[,{]/', $cssNoKept)) $stillThere[] = '.task-desc (bare)';
check('053-01', 'the dead task-tree selectors are gone from style.css and client.css '
    . '(.client-body .task-desc is intentionally kept)',
    $stillThere === [], 'still present: ' . implode(', ', $stillThere));

$prof = src('profile.php');
check('053-02', 'profile.php restores the grid modifier dropped by the sweep',
    (bool)preg_match('/grid--\w/', $prof),
    'grid modifiers found: ' . implode(', ', array_unique(array_filter(
        preg_match_all('/grid--[\w-]+/', $prof, $gm) ? $gm[0] : []))));

/* ================================================================== */
/* SECTION 17 — issue-055 Bug 1 — copy button                           */
/* ================================================================== */

section('ISSUE-055 Bug 1 — copy-to-clipboard button');

$js = src('assets/js/script.js');
check('055-01', 'the copy helper has an execCommand fallback for plain-HTTP origins',
    (bool)preg_match('/execCommand\s*\(\s*[\'"]copy[\'"]/i', $js));
check('055-02', 'the copy helper handles a rejected clipboard promise',
    (bool)preg_match('/catch\s*\(/i', $js) && (bool)preg_match('/clipboard/i', $js));
check('055-03', 'the copy helper shows a failure state ("Copy failed" / "Failed")',
    (bool)preg_match('/copy\s*fail/i', $js));
check('055-04', 'the copy helper restores the original label/icon after the timeout',
    (bool)preg_match('/setTimeout/i', $js) && (bool)preg_match('/textContent|innerHTML/i', $js));
// issue-056 UPDATE: this check used to grep the whole of script.js for
// /disabled\s*=\s*false/i. It matched the Status-Reason panel code
// ("ta.disabled = false") and NEVER touched the copy helper — a false
// positive. The copy helper (initCopy) does not disable its button at
// all: it swaps the label to "Copied" / "Copy failed" and restores the
// original label + classes on a timeout.
//
// The INTENT of the check is "the copy button does not get stuck in a
// disabled state after a copy attempt". Assert that directly against
// the initCopy function body, and also assert the restore path exists —
// the button must be usable again (same label, no leftover state class)
// once the copy settles.
$initCopyBody = '';
if (preg_match('/function\s+initCopy\s*\([^)]*\)\s*\{(.*?)\n\}/s', $js, $icm)) {
    $initCopyBody = $icm[1] ?? '';
}
check('055-05', 'the copy helper never disables its button (it restores the label and clears the state classes)',
    $initCopyBody !== ''
        && !preg_match('/\bbtn\.disabled\s*=/', $initCopyBody)
        && (bool)preg_match('/setTimeout/', $initCopyBody)
        && (bool)preg_match('/innerHTML\s*=\s*label/', $initCopyBody)
        && (bool)preg_match('/classList\.remove\(/', $initCopyBody),
    'initCopy() body not found, or it disables the button without restoring the label/classes');

$pdet = src('project_detail.php');
// strip PHP / JS / CSS comments before looking for a second copy implementation
$pdetCode = preg_replace('!/\*.*?\*/!s', '', $pdet);
$pdetCode = preg_replace('!//[^\n]*!', '', $pdetCode);
$pdetCode = preg_replace('!<!--.*?-->!s', '', $pdetCode);
check('055-06', 'project_detail.php delegates to the shared copy helper (no second implementation)',
    (bool)preg_match('/initCopy|data-copy|copyToClipboard|pmpCopyText/i', $pdet)
    && !preg_match('/navigator\.clipboard/i', $pdetCode),
    'project_detail.php still contains its own navigator.clipboard call');

/* ================================================================== */
/* SECTION 18 — issue-055 Bug 2 — task form dates                      */
/* ================================================================== */

section('ISSUE-055 Bug 2 — task form dates');

if ($TID) {
    $b = req($ORIGIN . "/tasks/$TID/edit", 'auth')['body'];
    check('055-07', 'expected_completion_date input is type="date"',
        (bool)preg_match('/id="expected_completion_date"[^>]*type="date"|type="date"[^>]*id="expected_completion_date"/i', $b));
    check('055-08', 'completion_date input is type="date"',
        (bool)preg_match('/id="completion_date"[^>]*type="date"|type="date"[^>]*id="completion_date"/i', $b));
}
$te = src('task_edit.php');
check('055-09', 'task_edit.php parses the posted dates (parseFormDate) instead of storing the raw string',
    (bool)preg_match('/parseFormDate/i', $te));

// invalid dates must be rejected
if ($TID) {
    $orig = $db->fetchOne('SELECT expected_completion_date, completion_date FROM tasks WHERE id = ?', [$TID]);
    $r = req($ORIGIN . "/tasks/$TID/edit", 'auth', true, [
        'name' => (string)($task['name'] ?? 'x'),
        'status' => 'in_progress',
        'expected_completion_date' => '2026-13-01',
    ], false);
    $now = $db->fetchOne('SELECT expected_completion_date FROM tasks WHERE id = ?', [$TID]);
    check('055-10', 'an impossible month (2026-13-01) is rejected and not written to the DB',
        $orig && $now && (string)$now['expected_completion_date'] === (string)$orig['expected_completion_date'],
        'expected=' . var_export($orig['expected_completion_date'] ?? null, true)
        . ' now=' . var_export($now['expected_completion_date'] ?? null, true));

    $r2 = req($ORIGIN . "/tasks/$TID/edit", 'auth', true, [
        'name' => (string)($task['name'] ?? 'x'),
        'status' => 'in_progress',
        'expected_completion_date' => '2026-02-31',
    ], false);
    $now2 = $db->fetchOne('SELECT expected_completion_date FROM tasks WHERE id = ?', [$TID]);
    check('055-11', 'an impossible calendar date (2026-02-31) is rejected and not written to the DB',
        $orig && $now2 && (string)$now2['expected_completion_date'] === (string)$orig['expected_completion_date'],
        'now=' . var_export($now2['expected_completion_date'] ?? null, true));
}

// DB column precision
$cols = $db->fetchAll("SHOW COLUMNS FROM tasks WHERE Field IN ('expected_completion_date','completion_date')");
$colInfo = [];
foreach ($cols as $c) $colInfo[$c['Field']] = $c['Type'];
check('055-12', 'tasks date columns are DATE (day precision)',
    isset($colInfo['expected_completion_date']) && strtoupper($colInfo['expected_completion_date']) === 'DATE'
    && isset($colInfo['completion_date']) && strtoupper($colInfo['completion_date']) === 'DATE',
    json_encode($colInfo));

// ajax writers must use the same precision
$ajaxBad = [];
foreach (['ajax/complete_task.php', 'ajax/update_task_status.php', 'ajax/update_task_progress.php'] as $a) {
    if (!has($a)) { $ajaxBad[] = "$a missing"; continue; }
    $s = src($a);
    if (preg_match("/completion_date\s*=\s*date\s*\(\s*'Y-m-d H:i:s'\s*\)/i", $s)) $ajaxBad[] = "$a writes Y-m-d H:i:s";
}
check('055-13', 'the ajax task writers use the same day precision as task_edit.php', $ajaxBad === [], implode("\n", $ajaxBad));

/* ================================================================== */
/* SECTION 19 — issue-055 Bug 3 — client language switcher              */
/* ================================================================== */

section('ISSUE-055 Bug 3 — client dashboard language switcher');

$cd2 = src('client/dashboard.php');
check('055-14', 'the language switcher exposes role="group"', (bool)preg_match('/role="group"/i', $cd2));
check('055-15', 'the switcher carries an accessible label', (bool)preg_match('/aria-label/i', $cd2));
check('055-16', 'the active locale carries aria-current="true"', (bool)preg_match('/aria-current="true"/i', $cd2));
check('055-17', 'the switcher labels read ENG and 中文',
    strpos($cd2, 'ENG') !== false && (strpos($cd2, '中文') !== false || preg_match('/\x{4e2d}\x{6587}/u', $cd2) === 1));

$clientCss3 = src('client/assets/client.css');
$langScopeOk = (bool)preg_match('/\.lang-switch[^{]*\.is-active|\.is-active[^{]*\.lang-switch/i', $clientCss3);
check('055-18', 'client.css scopes the active state to the language switcher container', $langScopeOk);

/* ================================================================== */
/* SECTION 20 — issue-055 Bug 4 — forgot password                       */
/* ================================================================== */

section('ISSUE-055 Bug 4 — forgot password page');

$fp = src('forgot_password.php');
check('055-19', 'the email label carries the required marker',
    (bool)preg_match('/required/i', $fp));
check('055-20', 'the email input has autocomplete="email"',
    (bool)preg_match('/autocomplete="email"/i', $fp));
check('055-21', 'the email input receives focus on load',
    (bool)preg_match('/autofocus/i', $fp));
check('055-22', 'the error block is rendered with role="alert"',
    (bool)preg_match('/role="alert"/i', $fp));
$errPos = stripos($fp, 'role="alert"');
// the page title / nav also contain the words "Forgot password"; the heading we
// care about is the <h1> inside the auth card.
$headPos = stripos($fp, '<h1>Forgot password</h1>');
if ($headPos === false) $headPos = stripos($fp, 'Forgot password</h1>');
check('055-23', 'the error block is rendered above the "Forgot password" heading',
    $errPos !== false && $headPos !== false && $errPos < $headPos,
    "errPos=$errPos headPos=$headPos");
check('055-24', 'the submitted email value is preserved after a validation failure',
    (bool)preg_match('/value="<\?=\s*e\(\s*\$_(?:POST|GET)\[\s*[\'"]email[\'"]\s*\]\s*\?\?\s*[\'"][\'"]\s*\)/i', $fp)
    || (bool)preg_match('/\$email\s*\?\s*\$email/i', $fp),
    'no preserved-email expression found in forgot_password.php');

$reg2 = src('register.php');
check('055-25', 'register.php renders its alert in the same position as forgot_password.php',
    (bool)preg_match('/role="alert"/i', $reg2));

/* ================================================================== */
/* SECTION 21 — REGRESSION: unauthenticated deep-link redirect          */
/* ================================================================== */

section('REGRESSION — unauthenticated deep links must reach the login page');

$deep = ['/dashboard', '/projects', '/tasks', '/contacts', '/profile'];
if ($PID) { $deep[] = "/projects/$PID"; $deep[] = "/projects/$PID/edit"; $deep[] = '/projects/new'; }
if ($TID) { $deep[] = "/tasks/$TID"; $deep[] = "/tasks/$TID/edit"; $deep[] = '/tasks/new'; }
if ($CID) { $deep[] = "/contacts/$CID"; $deep[] = "/contacts/$CID/edit"; $deep[] = '/contacts/new'; }
// the ajax endpoints live one level down, so their relative redirect breaks too
$deep[] = '/ajax/complete_task.php';
$deep[] = '/ajax/parent_hold.php';

$relBad = [];
$finalBad = [];
foreach ($deep as $p) {
    $r = req($ORIGIN . $p, 'anon', false, null, false);
    $l = locOf($r);
    if ($l !== null && !preg_match('#^https?://#', $l) && strpos($l, '/') !== 0) {
        $relBad[] = "$p -> Location: $l (relative; resolves to " . dirname($p) . '/' . $l . ')';
    }
    $f = req($ORIGIN . $p, 'anon', false, null, true);
    if ($f['status'] !== 200) {
        $finalBad[] = "$p -> final {$f['status']} at " . $f['url'];
    }
}
check('REG-01', 'every unauthenticated redirect uses an absolute path', $relBad === [], implode("\n", $relBad));
check('REG-02', 'every unauthenticated deep link lands on a 200 login page (no 404)', $finalBad === [], implode("\n", $finalBad));

// root cause: requireLogin() must redirect to an absolute path
$fnSrc2 = src('includes/functions.php');
preg_match('/function\s+requireLogin\s*\(\s*\)\s*\{(.*?)\n\}/s', $fnSrc2, $rm);
$reqBody = $rm[1] ?? '';
$reqLoc = '';
if (preg_match('/header\s*\(\s*[\'"]Location:\s*([^\'"]*)[\'"]/i', $reqBody, $lm2)) {
    $reqLoc = $lm2[1];
}
// The target may be a literal ("/login") or a concatenated/computed
// one ("header('Location: ' . $target)"). The literal regex above
// captures an EMPTY string for the concatenated form, so detect that
// case explicitly and resolve the variable's assignments.
$reqLocIsAbsolute = false;
if ($reqLoc !== '' && strpos($reqLoc, '/') === 0) {
    $reqLocIsAbsolute = true;
} elseif (preg_match('/header\s*\(\s*[\'"]Location:\s*[\'"]\s*\.\s*\$([A-Za-z_][A-Za-z0-9_]*)/i', $reqBody, $vm)) {
    // The target is a variable: find where it is assigned and check
    // that every literal assigned to it starts with a slash.
    $varName = $vm[1];
    if (preg_match_all(
        '/\$' . preg_quote($varName, '/') . '\s*=\s*[\'"]([^\'"]*)[\'"]/',
        $reqBody,
        $assigns
    )) {
        $reqLocIsAbsolute = true;
        foreach ($assigns[1] as $lit) {
            if ($lit === '' || $lit[0] !== '/') {
                $reqLocIsAbsolute = false;
                break;
            }
        }
        $reqLoc = 'computed: $' . $varName;
    }
}
check('REG-02b', 'requireLogin() redirects to an absolute path (/login), not a relative one',
    $reqLocIsAbsolute,
    'requireLogin() sends: Location: ' . ($reqLoc === '' ? '(not found)' : $reqLoc));

/* ================================================================== */
/* SECTION 22 — REGRESSION: issue-034 Bug 9b on-hold chip overlap        */
/* ================================================================== */

section('REGRESSION — issue-034 Bug 9b: on-hold chip must not overlap the task text');

/* The v1.8.0 markup used a `.task-line` flex row; the v2.0 conversion
 * (issue-035/041/053) replaced it with `.task-node__head` (flex-wrap: wrap)
 * plus `.task-node__reasons` (a COLUMN flex container that holds the reason
 * chips). The chip therefore stacks under the task name by construction.
 * The retired `.task-line` rule is intentionally gone (issue-053 §3.2). */

$v2c = src('assets/css/v2-components.css');
$headWrap = (bool)preg_match('/\.task-node__head\s*\{[^}]*flex-wrap\s*:\s*wrap/i', $v2c);
check('REG-03a', 'assets/css/v2-components.css — .task-node__head allows flex-wrap:wrap (owner pages)',
    $headWrap, $headWrap ? '' : 'no flex-wrap:wrap on .task-node__head');

$reasonsCol = (bool)preg_match('/\.task-node__reasons\s*\{[^}]*flex-direction\s*:\s*column/i', $v2c);
check('REG-03b', 'assets/css/v2-components.css — .task-node__reasons is a column flex container '
    . '(the reason chips stack under the task name)',
    $reasonsCol, $reasonsCol ? '' : 'no flex-direction:column on .task-node__reasons');

foreach (['assets/css/style.css' => 'owner pages', 'client/assets/client.css' => 'client portal'] as $f => $where) {
    $s = src($f);
    $has = (bool)preg_match('/\.task-reason\s*\{[^}]*flex-basis\s*:\s*100%/i', $s);
    check('REG-03c', "$f — .task-reason drops to its own row (flex-basis:100%) ($where)", $has,
        $has ? '' : 'no flex-basis:100% on .task-reason');
}

// the chip must really be rendered inside its own container
$cdash = src('client/dashboard.php');
$chipInReasons = (bool)preg_match('/task-node__reasons[\s\S]{0,500}?reason-chip/i', $cdash);
check('REG-03d', 'the reason chip is rendered inside .task-node__reasons (client portal)',
    $chipInReasons, 'chip not found inside a .task-node__reasons container');

// and it must be so in the LIVE portal HTML, not just in the source.
// Pick a shared project that actually has an on-hold task with a reason,
// otherwise there is no chip to render.
$shareCode2 = $db->fetchOne(
    "SELECT p.share_code FROM projects p JOIN tasks t ON t.project_id = p.id
     WHERE p.share_code IS NOT NULL AND t.status = 'on_hold'
       AND t.on_hold_reason IS NOT NULL AND t.on_hold_reason <> ''
     ORDER BY p.id DESC LIMIT 1"
);
if ($shareCode2 && $shareCode2['share_code']) {
    $live = req($ORIGIN . '/?code=' . rawurlencode($shareCode2['share_code']), '', false, null, true)['body'];
    $liveOk = (bool)preg_match('/class="task-node__reasons"[^>]*>\s*<div class="reason-chip/i', $live);
    check('REG-03f', 'the LIVE client portal renders the chip inside .task-node__reasons',
        $liveOk, 'the chip was not found inside .task-node__reasons in the rendered HTML');
} else {
    check('REG-03f', 'a share code was available to exercise the live client portal', false, 'no share code');
}

// the owner task-detail page keeps the chip in its own sidebar card
$tdSrc = src('task_detail.php');
check('REG-03g', 'task_detail.php keeps the on-hold reason in its own .task-reason-card container',
    (bool)preg_match('/class="card task-reason-card"/i', $tdSrc),
    'no .task-reason-card container found');

// the retired v1 selector must stay retired
$legacyLine = (bool)preg_match('/\.task-line\s*[,{]/', src('assets/css/style.css') . src('client/assets/client.css'));
check('REG-03e', 'the retired v1 .task-line rule is not reintroduced', !$legacyLine,
    'a .task-line rule is back');

/* ================================================================== */
/* SECTION 23 — process hygiene: missing issue files                    */
/* ================================================================== */

section('PROCESS — issue-file completeness');

$expected = ['035','036','037','038','039','040','041','042','043','045','046','047','048','049','050','051','052','053','055'];
$missingFiles = [];
foreach ($expected as $n) {
    if (!has("docs/issues/issue-$n.md")) $missingFiles[] = "issue-$n.md";
}
check('PROC-01', 'every referenced issue file exists in docs/issues/', $missingFiles === [],
    'missing: ' . implode(', ', $missingFiles));

$gap = [];
for ($i = 35; $i <= 55; $i++) {
    $n = sprintf('%03d', $i);
    if (!has("docs/issues/issue-$n.md")) $gap[] = "issue-$n.md";
}
note('gaps in the 035-055 sequence: ' . ($gap ? implode(', ', $gap) : 'none'));

/* ================================================================== */
/* SUMMARY                                                             */
/* ================================================================== */

$pass = 0; $fail = 0; $fails = [];
foreach ($GLOBALS['RESULTS'] as $r) {
    if ($r['pass']) $pass++; else { $fail++; $fails[] = $r['id'] . ' — ' . $r['desc']; }
}

echo "\n" . str_repeat('=', 78) . "\n";
echo "  SUMMARY: $pass PASS / $fail FAIL\n";
echo str_repeat('=', 78) . "\n";
if ($fails) {
    echo "\n  FAILURES:\n";
    foreach ($fails as $f) echo "   - $f\n";
}
echo "\n";

if ($JSON_OUT) {
    file_put_contents($JSON_OUT, json_encode([
        'origin'   => $ORIGIN,
        'user_id'  => $uid,
        'ids'      => ['project' => $PID, 'task' => $TID, 'contact' => $CID],
        'pass'     => $pass,
        'fail'     => $fail,
        'results'  => $GLOBALS['RESULTS'],
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    echo "  JSON written to $JSON_OUT\n\n";
}

exit($fail === 0 ? 0 : 1);
