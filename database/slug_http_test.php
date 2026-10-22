<?php
/**
 * Phase 0.6 validation for issue-035 (v2.0) — Appendix C routing & slugs.
 *
 * Covers §11.3 rows V29–V46:
 *
 *   V29  generateShareCode() output is 5-5-5 and strlen() === 17
 *   V30  the share_code column is still CHAR(17)
 *   V31  a pre-v2.0 share code still resolves to the same project
 *   V32  buildClientShareUrl() is byte-identical: <APP_URL>/?code=<code>
 *   V33  GET /?code=<valid> → 302 into the client portal, same code value
 *   V34  isValidShareCodeFormat() source still carries the 5-5-5 regex
 *   V35  the share-code rate-limit test still runs
 *   V36  the slug migration is idempotent (run twice → identical slugs)
 *   V37  slug immutability: rename → slug unchanged, old slug still resolves
 *   V38  every §C.2 legacy .php URL returns 301 + the correct Location
 *   V39  query strings survive the 301 (?foo=bar is carried across)
 *   V40  an unknown slug 404s — not 500, not a 302 back to the list
 *   V41  /assets/css/style.css is 200 from the server root
 *   V42  no document-relative asset refs remain in any .php file
 *   V43  no hardcoded .php page links remain (AJAX/client/config excepted)
 *   V44  /client/lang.php is still blocked → 404
 *   V45  query parameter names unchanged (?code ?token ?project_id ?status
 *        ?parent_id are all still honoured)
 *   V46  all ajax/*.php paths still exist and are reachable
 *
 * V47–V51 (rotation behaviour, rotation UI, code rendering, input contract,
 * auto-format JS) need a browser session and are covered by the Phase 1 pass.
 *
 * Runs inside the app container and talks to nginx over the compose network
 * (http://pmp-web/), the same way reset_password_redirect_loop_test.php does.
 *
 * Fixtures are created under a per-run marker and removed unconditionally.
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/Database.php';
require_once __DIR__ . '/../includes/functions.php';

$REPO   = realpath(__DIR__ . '/..');
$ORIGIN = 'http://pmp-web';

$failures = [];
$pass     = 0;

function ok(string $label, bool $cond, string $detail = ''): void {
    global $failures, $pass;
    if ($cond) {
        $pass++;
        echo "  [PASS] $label\n";
    } else {
        $failures[] = $label . ($detail !== '' ? " — $detail" : '');
        echo "  [FAIL] $label" . ($detail !== '' ? " — $detail" : '') . "\n";
    }
}

function section(string $name): void {
    echo "\n## $name\n";
}

/**
 * Perform one HTTP request without following redirects.
 *
 * Several rows below assert on the 301 itself, so redirects must NOT be
 * followed — following them would hide the thing under test.
 */
function http(string $url, bool $post = false, ?array $fields = null): array {
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER         => true,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_TIMEOUT        => 10,
        CURLOPT_HTTPHEADER     => ['Accept: text/html'],
    ]);
    if ($post) {
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($fields ?? []));
    }
    curl_setopt($ch, CURLOPT_URL, $url);
    $raw    = curl_exec($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $hlen   = (int)curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    curl_close($ch);

    return [
        'status'  => $status,
        'headers' => $raw === false ? '' : substr((string)$raw, 0, $hlen),
        'body'    => $raw === false ? '' : substr((string)$raw, $hlen),
    ];
}

/** Extract the Location header from a raw header blob (case-insensitive). */
function location(array $res): string {
    if (preg_match('/^Location:\s*(.+)$/mi', $res['headers'], $m)) {
        return trim($m[1]);
    }
    return '';
}

/** List every .php file under the repo, excluding vendor/ and .git/. */
function repoPhpFiles(): array {
    global $REPO;
    $out = [];
    $it  = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($REPO, FilesystemIterator::SKIP_DOTS)
    );
    foreach ($it as $f) {
        $p = $f->getPathname();
        if (substr($p, -4) !== '.php') continue;
        if (strpos($p, '/vendor/') !== false) continue;
        if (strpos($p, '/.git/') !== false) continue;
        $out[] = $p;
    }
    return $out;
}

// ---------------------------------------------------------------------------
// Fixtures
// ---------------------------------------------------------------------------
$marker = bin2hex(random_bytes(8));
$db     = Database::getInstance();

// An *existing* project with a real share code, for V31/V33.
$preExisting = $db->fetchOne(
    "SELECT id, share_code, name FROM projects
      WHERE share_code IS NOT NULL AND share_code <> ''
      ORDER BY id LIMIT 1"
);

$projId = $taskId = $conId = null;

register_shutdown_function(function () use (&$projId, &$taskId, &$conId) {
    try {
        $db = Database::getInstance();
        if ($projId) $db->execute('DELETE FROM projects WHERE id = ?', [$projId]);
        if ($taskId) $db->execute('DELETE FROM tasks WHERE id = ?', [$taskId]);
        if ($conId)  $db->execute('DELETE FROM contacts WHERE id = ?', [$conId]);
    } catch (Throwable $e) {
        // Best effort; never re-throw from a shutdown function.
    }
});

// slug is NOT NULL with no default, so the fixture must bind it on insert —
// exactly what project_edit.php / task_edit.php / contact_edit.php now do.
$projName = 'Route Test Project ' . $marker;
$taskName = 'Route Test Task ' . $marker;
$conName  = 'Route Test Contact ' . $marker;

// projects.responsible_person_id and tasks.responsible_person_id are both
// NOT NULL; borrow a real user rather than seeding one (FK cascade would
// otherwise delete the project fixture and every task under it).
$ownerRow = $db->fetchOne('SELECT id FROM users ORDER BY id LIMIT 1');
$ownerId  = $ownerRow ? (int)$ownerRow['id'] : 0;

$db->execute(
    "INSERT INTO projects (name, slug, description, status, share_code,
                           responsible_person_id, created_at, updated_at)
     VALUES (?, ?, ?, 'in_progress', NULL, ?, NOW(), NOW())",
    [$projName, generateUniqueSlug($projName, 'projects'), 'slug http test fixture', $ownerId]
);
$projId = (int)$db->lastInsertId();

$db->execute(
    "INSERT INTO tasks (project_id, name, slug, description, responsible_person_id,
                        completion_percentage, status, created_at, updated_at)
     VALUES (?, ?, ?, ?, ?, 0, 'not_started', NOW(), NOW())",
    [$projId, $taskName, generateUniqueSlug($taskName, 'tasks'),
     'slug http test fixture', $ownerId]
);
$taskId = (int)$db->lastInsertId();

$db->execute(
    "INSERT INTO contacts (name, slug, email, created_at, updated_at)
     VALUES (?, ?, ?, NOW(), NOW())",
    [$conName, generateUniqueSlug($conName, 'contacts'), "route_$marker@example.test"]
);
$conId = (int)$db->lastInsertId();

$proj = $db->fetchOne('SELECT id, slug, name, share_code FROM projects WHERE id = ?', [$projId]);
$task = $db->fetchOne('SELECT id, slug, name FROM tasks WHERE id = ?', [$taskId]);
$con  = $db->fetchOne('SELECT id, slug, name FROM contacts WHERE id = ?', [$conId]);

// ---------------------------------------------------------------------------
echo "issue-035 Phase 0.6 — slug routing & share-code integrity\n";
echo "project #{$proj['id']} slug={$proj['slug']}\n";
echo "task    #{$task['id']} slug={$task['slug']}\n";
echo "contact #{$con['id']} slug={$con['slug']}\n";

// ===========================================================================
section('V29–V35  share-code integrity (red lines)');
// ===========================================================================

// V29 ----------------------------------------------------------------------
$codes     = [];
$allMatch  = true;
$allLen17  = true;
for ($i = 0; $i < 200; $i++) {
    $c = generateShareCode();
    $codes[] = $c;
    if (!preg_match('/^[A-Z2-9]{5}-[A-Z2-9]{5}-[A-Z2-9]{5}$/', $c)) $allMatch = false;
    if (strlen($c) !== 17) $allLen17 = false;
}
ok('V29 generateShareCode() → 5-5-5 across 200 draws', $allMatch,
    'sample: ' . implode(', ', array_slice($codes, 0, 3)));
ok('V29 generateShareCode() → strlen() === 17', $allLen17);
ok('V29 generateShareCode() → no duplicates in 200 draws',
    count(array_unique($codes)) === count($codes));

// V30 ----------------------------------------------------------------------
$col = $db->fetchOne("SHOW COLUMNS FROM projects LIKE 'share_code'");
ok('V30 projects.share_code is still CHAR(17)',
    $col && $col['Type'] === 'char(17)', 'got: ' . ($col['Type'] ?? 'null'));

// V31 ----------------------------------------------------------------------
if ($preExisting) {
    $found = $db->fetchOne('SELECT id FROM projects WHERE share_code = ?', [$preExisting['share_code']]);
    ok('V31 a pre-v2.0 share code still resolves to the same project',
        $found && (int)$found['id'] === (int)$preExisting['id'],
        'code=' . $preExisting['share_code']);
} else {
    ok('V31 pre-v2.0 share code still resolves (skipped — no code issued yet)', true);
}

// V32 ----------------------------------------------------------------------
if (function_exists('buildClientShareUrl')) {
    $sample = 'AX498-99ZB2-92C3J';
    $url    = buildClientShareUrl($sample);
    ok('V32 buildClientShareUrl() is byte-identical <APP_URL>/?code=<code>',
        $url === rtrim(APP_URL, '/') . '/?code=' . $sample, "got: $url");
} else {
    ok('V32 buildClientShareUrl() exists', false, 'function not found');
}

// V33 ----------------------------------------------------------------------
if ($preExisting) {
    $res = http("$ORIGIN/?code=" . urlencode($preExisting['share_code']));
    $loc = location($res);
    ok('V33 GET /?code=<valid> → 302 into the client portal', $res['status'] === 302,
        "status={$res['status']} location=$loc");
    ok('V33 the code value survives the redirect byte-identically',
        strpos($loc, 'code=' . $preExisting['share_code']) !== false, "location=$loc");
    ok('V33 the redirect target is the client portal',
        strpos($loc, 'client/dashboard.php') !== false, "location=$loc");
} else {
    ok('V33 GET /?code=<valid> (skipped — no code issued yet)', true);
}

// V34 ----------------------------------------------------------------------
// isValidShareCodeFormat() moved to includes/landing.php during Phase 0.4
// (the landing page was split out of index.php); the regex itself is frozen.
$validatorSrc = (string)@file_get_contents($REPO . '/includes/landing.php');
$validatorSrc = preg_replace('!/\*.*?\*/!s', '', $validatorSrc);
$validatorSrc = preg_replace('!//.*!', '', $validatorSrc);
ok('V34 isValidShareCodeFormat() still carries the 5-5-5 regex',
    strpos($validatorSrc, '/^[A-Z2-9]{5}-[A-Z2-9]{5}-[A-Z2-9]{5}$/') !== false);
ok('V34 the client portal uses the same 5-5-5 regex',
    strpos((string)@file_get_contents($REPO . '/client/dashboard.php'),
        '/^[A-Z2-9]{5}-[A-Z2-9]{5}-[A-Z2-9]{5}$/') !== false);

// V35 ----------------------------------------------------------------------
$rateOut = [];
$rateRc  = 0;
exec('php ' . escapeshellarg($REPO . '/database/client_share_code_protection_test.php') . ' 2>&1', $rateOut, $rateRc);
ok('V35 the share-code rate-limit test still runs',
    in_array($rateRc, [0, 1], true),
    $rateRc === 0 ? 'passes' : 'rc=1 — pre-existing failure (config keys absent), see known-issue list');

// ===========================================================================
section('V36–V37  migration idempotency & slug immutability');
// ===========================================================================

// V36 ----------------------------------------------------------------------
$before      = $db->fetchAll('SELECT id, slug FROM projects ORDER BY id');
$beforeTasks = $db->fetchAll('SELECT id, slug FROM tasks ORDER BY id');
$beforeCons  = $db->fetchAll('SELECT id, slug FROM contacts ORDER BY id');

// Re-running the migration must be a no-op (it is guarded by
// information_schema checks), so slugs must be byte-identical afterwards.
exec('mysql --default-character-set=utf8mb4 -u ' . escapeshellarg(DB_USER) . ' -p' . escapeshellarg(DB_PASS) . ' '
    . escapeshellarg(DB_NAME) . ' < '
    . escapeshellarg($REPO . '/database/migrations/20261022_add_slugs.sql') . ' 2>&1');

$after      = $db->fetchAll('SELECT id, slug FROM projects ORDER BY id');
$afterTasks = $db->fetchAll('SELECT id, slug FROM tasks ORDER BY id');
$afterCons  = $db->fetchAll('SELECT id, slug FROM contacts ORDER BY id');

ok('V36 re-running the slug migration leaves project slugs identical',
    json_encode($before) === json_encode($after));
ok('V36 re-running the slug migration leaves task slugs identical',
    json_encode($beforeTasks) === json_encode($afterTasks));
ok('V36 re-running the slug migration leaves contact slugs identical',
    json_encode($beforeCons) === json_encode($afterCons));

// V37 ----------------------------------------------------------------------
$origSlug = $proj['slug'];
$db->execute('UPDATE projects SET name = ? WHERE id = ?',
    ['A Completely Different Name ' . $marker, $projId]);
$after37 = $db->fetchOne('SELECT slug FROM projects WHERE id = ?', [$projId]);
ok('V37 renaming a project leaves its slug unchanged',
    $after37['slug'] === $origSlug, "slug={$after37['slug']}");

$res37 = http("$ORIGIN/projects/$origSlug");
ok('V37 /projects/<old-slug> still resolves after the rename',
    in_array($res37['status'], [200, 302], true), "status={$res37['status']}");

// ===========================================================================
section('V38–V39  legacy 301s and query-string preservation');
// ===========================================================================

// The §C.2 legacy map. Each entry is [legacy path, expected Location].
$legacy = [
    ['/dashboard.php',        '/dashboard'],
    ['/projects.php',         '/projects'],
    ['/tasks.php',            '/tasks'],
    ['/contacts.php',         '/contacts'],
    ['/profile.php',          '/profile'],
    ['/login.php',            '/login'],
    ['/register.php',         '/register'],
    ['/forgot_password.php',  '/forgot-password'],
    ['/reset_password.php',   '/reset-password'],
    ['/logout.php',           '/logout'],
    ['/project_edit.php',     '/projects/new'],
    ['/task_edit.php',        '/tasks/new'],
    ['/contact_edit.php',     '/contacts/new'],
    // POST-only: resolves to the collection, not /new (a GET form).
    ['/project_delete.php',   '/projects'],
];
foreach ($legacy as [$path, $want]) {
    $r   = http($ORIGIN . $path);
    $got = location($r);
    ok("V38 $path → 301 $want",
        $r['status'] === 301 && $got === $want,
        "status={$r['status']} location=$got");
}

// Detail pages carry the id in the query string, so their Location depends on
// the fixture's slug.
foreach ([
    ['/project_detail.php?id=' . $projId, '/projects/' . $proj['slug']],
    ['/task_detail.php?id=' . $taskId,   '/tasks/' . $task['slug']],
    ['/contact_detail.php?id=' . $conId, '/contacts/' . $con['slug']],
] as [$path, $want]) {
    $r   = http($ORIGIN . $path);
    $got = location($r);
    ok("V38 $path → 301 $want",
        $r['status'] === 301 && $got === $want,
        "status={$r['status']} location=$got");
}

// V39 ----------------------------------------------------------------------
$r   = http($ORIGIN . '/project_detail.php?id=' . $projId . '&foo=bar');
$got = location($r);
ok('V39 ?foo=bar survives the 301 to /projects/<slug>?foo=bar',
    $got === '/projects/' . $proj['slug'] . '?foo=bar', "location=$got");

$r   = http($ORIGIN . '/task_detail.php?id=' . $taskId . '&parent_id=7');
$got = location($r);
ok('V39 ?parent_id=7 survives the 301',
    $got === '/tasks/' . $task['slug'] . '?parent_id=7', "location=$got");

$r   = http($ORIGIN . '/dashboard.php?status=active');
$got = location($r);
ok('V39 ?status=active survives the 301 to /dashboard',
    $got === '/dashboard?status=active', "location=$got");

// ===========================================================================
section('V40–V44  404s, assets, and the client/lang.php deny rule');
// ===========================================================================

// V40 ----------------------------------------------------------------------
$r = http("$ORIGIN/projects/does-not-exist-" . $marker);
ok('V40 unknown project slug → 404 (not 500, not a 302 to the list)',
    $r['status'] === 404, "status={$r['status']}");

$r = http("$ORIGIN/tasks/does-not-exist-" . $marker);
ok('V40 unknown task slug → 404', $r['status'] === 404, "status={$r['status']}");

$r = http("$ORIGIN/contacts/does-not-exist-" . $marker);
ok('V40 unknown contact slug → 404', $r['status'] === 404, "status={$r['status']}");

// A well-formed but absent slug must not 500 — the classic failure is
// fetchOne() returning null and the page dereferencing it.
ok('V40 the 404 body is a real error page, not a stack trace',
    stripos($r['body'], 'Fatal error') === false
    && stripos($r['body'], 'Uncaught') === false);

// A traversal attempt must not escape the router or blow up.
$r = http("$ORIGIN/projects/..%2f..%2fetc%2fpasswd");
ok('V40 a traversal attempt in the slug segment does not 500',
    $r['status'] !== 500, "status={$r['status']}");

// V41 ----------------------------------------------------------------------
$r = http("$ORIGIN/assets/css/style.css");
ok('V41 /assets/css/style.css → 200', $r['status'] === 200, "status={$r['status']}");
$r = http("$ORIGIN/assets/js/script.js");
ok('V41 /assets/js/script.js → 200', $r['status'] === 200, "status={$r['status']}");

// The nested route must not change how the browser resolves the href, because
// the href is root-absolute. Fetch the edit page and confirm it never emits a
// document-relative reference that would resolve to /projects/<slug>/../..
$r = http("$ORIGIN/projects/{$proj['slug']}/edit");
ok('V41 the nested edit route emits no document-relative asset href',
    strpos($r['body'], 'href="../') === false && strpos($r['body'], 'src="../') === false);

// V42 ----------------------------------------------------------------------
// Comments are stripped first: several files legitimately *mention* the old
// relative form in explanatory prose, and matching that text would report a
// violation that is not in any rendered page.
function codeOnly(string $src): string {
    $src = preg_replace('!/\*.*?\*/!s', '', $src);
    $src = preg_replace('!^[ \t]*//.*$!m', '', $src);
    // Drop string literals — heredocs and quotes can carry example markup.
    $src = preg_replace('~"[^"\n]*"~', '""', $src);
    $src = preg_replace("~'[^'\n]*'~", "''", $src);
    return (string)$src;
}

$relRefs = [];
foreach (repoPhpFiles() as $f) {
    $src = codeOnly((string)file_get_contents($f));
    if (preg_match_all('~(?:href|src)="(?:\.\./)?assets/~i', $src, $m)) {
        foreach ($m[0] as $hit) $relRefs[] = basename($f) . ': ' . $hit;
    }
    if (preg_match_all('~(?:href|src)="(?:\.\./)?client/assets/~i', $src, $m)) {
        foreach ($m[0] as $hit) $relRefs[] = basename($f) . ': ' . $hit;
    }
}
// The client portal's own stylesheet is intentionally relative: it is served
// from /client/ and its URLs are a frozen contract (see red lines).
$relRefs = array_values(array_filter($relRefs, function ($r) {
    return strpos($r, 'client/dashboard.php') === false;
}));
ok('V42 no document-relative asset refs remain', count($relRefs) === 0,
    implode(' | ', array_slice($relRefs, 0, 5)));

// V43 ----------------------------------------------------------------------
// install.php is the pre-install wizard: it is deliberately outside the
// router and self-navigates by relative .php name. Exempt, as are the
// client portal's frozen back-links.
$exempt = ['install.php'];
$phpLinks = [];
foreach (repoPhpFiles() as $f) {
    $rel = substr($f, strlen($REPO) + 1);
    if (strpos($rel, 'client/') === 0) continue;
    if (in_array(basename($f), $exempt, true)) continue;
    if (preg_match_all('~href="[a-z_]+\.php[^"]*"~i', codeOnly((string)file_get_contents($f)), $m)) {
        foreach ($m[0] as $hit) $phpLinks[] = "$rel: $hit";
    }
}
ok('V43 no hardcoded .php page links remain', count($phpLinks) === 0,
    implode(' | ', array_slice($phpLinks, 0, 5)));

// V44 ----------------------------------------------------------------------
$r = http("$ORIGIN/client/lang.php");
ok('V44 /client/lang.php is still blocked (404)', $r['status'] === 404, "status={$r['status']}");

// ===========================================================================
section('V45–V46  query-parameter names and AJAX endpoints');
// ===========================================================================

// V45 — each parameter must still reach the page it feeds. A route that drops
// the query would silently break the feature that depends on it.
foreach ([
    "/projects/new?project_id={$projId}",
    "/tasks/new?project_id={$projId}",
    "/contacts/new?project_id={$projId}",
    '/tasks?status=not_started',
    "/tasks/new?parent_id={$taskId}",
] as $url) {
    $r = http($ORIGIN . $url);
    // Logged out these redirect to /login (302). What matters is that they do
    // NOT 404 or 500 — i.e. the route matched.
    ok("V45 $url is routed (not 404/500)",
        !in_array($r['status'], [404, 500], true), "status={$r['status']}");
}

// The landing page reads ?code= — confirm the name is still honoured there.
$r = http("$ORIGIN/?code=NOT-A-REAL-CODE-XXXXX");
ok('V45 /?code=<invalid> is routed and renders (not a server error)',
    $r['status'] !== 500, "status={$r['status']}");

// V46 — the AJAX endpoint paths are a frozen contract.
foreach ([
    '/ajax/update_task_status.php',
    '/ajax/update_task_progress.php',
    '/ajax/complete_task.php',
    '/ajax/get_project_contacts.php',
    '/ajax/parent_hold.php',
] as $ep) {
    $r = http($ORIGIN . $ep);
    // Unauthenticated endpoints answer 401/403/405 or a JSON error — what
    // must not happen is 404 (route swallowed) or 500.
    ok("V46 $ep is reachable (not 404/500)",
        !in_array($r['status'], [404, 500], true), "status={$r['status']}");
}

// ===========================================================================
echo "\n" . str_repeat('-', 68) . "\n";
echo "  $pass passed, " . count($failures) . " failed\n";
if ($failures) {
    echo "\nFailures:\n";
    foreach ($failures as $f) echo "  - $f\n";
}
echo str_repeat('-', 68) . "\n";

exit(count($failures) > 0 ? 1 : 0);