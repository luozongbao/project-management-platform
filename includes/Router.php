<?php
/**
 * v2.0 front-controller route table (issue-035 Appendix C.2).
 *
 * nginx already forwards every unmatched path to index.php
 * (`try_files $uri $uri/ /index.php?$query_string`), so routing lives here
 * rather than in server config. The .htaccess equivalent is the RewriteRule
 * catch-all.
 *
 * A route is [method, pattern, script, mode]:
 *   method  — GET|POST, or '*' for either.
 *   pattern — literal path, or a pattern whose <slug> / <token> segment is
 *             captured into $matches[1]/$matches[2].
 *   script  — file to run, relative to the project root.
 *   mode    — 'file'  : just include the script (it validates its own inputs).
 *             'slug'  : resolve <slug> to a record id, inject $_GET['id'],
 *                       then include. Unknown slug => 404, never a redirect.
 *             'token' : pass <token> through as $_GET['token'].
 *
 * Why 'slug' injects $_GET['id'] instead of rewriting the page scripts: the
 * v1.8.0 page bodies read $_GET['id'] in a dozen places. Rewriting them all in
 * one pass would be a much larger diff than the routing change needs, and the
 * slug's real job is to be the public URL. The id stays an internal detail.
 *
 * Red line (C.1): the client portal is NOT in this table. /client/dashboard.php
 * keeps its existing entry point, and ?code= is never treated as a slug.
 */

/**
 * @return array<string, array{0:string,1:string,2:string,3:string}>
 */
function pmpRouteTable(): array
{
    return [
        // --- public ---
        '/'                   => ['*',     'landing',                               'landing', 'file'],

        '/login'              => ['*',     'login.php',                             'login',   'file'],
        '/register'           => ['*',     'register.php',                          'register','file'],
        '/forgot-password'    => ['*',     'forgot_password.php',                   'forgot',  'file'],
        '/reset-password'     => ['*',     'reset_password.php',                    'reset',   'token'],
        // issue-037: the path form. pmpTokenSegment() has always parsed
        // /reset-password/<token>, but this route was never registered, so the
        // helper could only ever match a URL that 404'd at step (2) before
        // reaching it. Both forms are now routable and behave identically.
        '/reset-password/<token>' => ['*',  'reset_password.php',                    'reset',   'token'],
        '/logout'             => ['*',     'logout.php',                            'logout',  'file'],

        // --- authenticated: home ---
        '/dashboard'          => ['*',     'dashboard.php',                         'dash',    'file'],

        // --- authenticated: projects ---
        '/projects'           => ['*',     'projects.php',                          'projList','file'],
        '/projects/new'       => ['*',     'project_edit.php',                      'projNew', 'file'],
        '/projects/<slug>'    => ['*',     'project_detail.php',                    'projDet', 'slug'],
        '/projects/<slug>/edit' => ['*',   'project_edit.php',                      'projEdit','slug'],
        '/projects/<slug>/delete' => ['POST','project_delete.php',                  'projDel', 'slug'],

        // --- authenticated: tasks ---
        '/tasks'              => ['*',     'tasks.php',                             'taskList','file'],
        '/tasks/new'          => ['*',     'task_edit.php',                          'taskNew', 'file'],
        '/tasks/<slug>'       => ['*',     'task_detail.php',                       'taskDet', 'slug'],
        '/tasks/<slug>/edit'  => ['*',     'task_edit.php',                          'taskEdit','slug'],

        // --- authenticated: contacts ---
        '/contacts'           => ['*',     'contacts.php',                          'contList','file'],
        '/contacts/new'       => ['*',     'contact_edit.php',                      'contNew', 'file'],
        '/contacts/<slug>'    => ['*',     'contact_detail.php',                    'contDet', 'slug'],
        '/contacts/<slug>/edit' => ['*',   'contact_edit.php',                      'contEdit','slug'],

        // --- authenticated: self ---
        '/profile'            => ['*',     'profile.php',                           'profile', 'file'],
    ];
}

/**
 * Which table a <slug> segment belongs to, keyed by resolved script.
 * Three routes resolve to the same script (list/new/edit), so the table
 * cannot be inferred from the script name alone — it comes from the route.
 */
function pmpRouteSlugTable(): array
{
    return [
        'projDet' => 'projects', 'projEdit' => 'projects', 'projDel' => 'projects',
        'taskDet' => 'tasks',    'taskEdit' => 'tasks',
        'contDet' => 'contacts', 'contEdit' => 'contacts',
    ];
}

/**
 * Turn a route pattern into a PCRE.
 *
 * The literal segments and the placeholders are escaped separately and then
 * joined, rather than escaping the whole pattern first: preg_quote() DOES
 * escape angle brackets (verified: preg_quote('/projects/<slug>') yields
 * '/projects/\<slug\>'), so a replace-after-quote approach would silently
 * never substitute and every slug route would match only the literal text
 * "<slug>". Splitting first means the placeholders are still real when the
 * regex is assembled.
 *
 * A <slug> is one path segment only: no slashes, so a crafted URL cannot
 * reach a different file or wander the tree.
 */
function pmpCompilePattern(string $pattern): string
{
    $parts = preg_split('/(<slug>|<token>)/', $pattern, -1, PREG_SPLIT_DELIM_CAPTURE);
    $out = '#^';
    foreach ($parts as $part) {
        if ($part === '<slug>' || $part === '<token>') {
            $out .= '([^/]+)';
        } elseif ($part !== '') {
            $out .= preg_quote($part, '#');
        }
    }
    return $out . '$#';
}

/**
 * Match the request path against the table.
 *
 * @return array{method:string,script:string,mode:string,slugTable:?string}|null
 */
function pmpMatchRoute(string $method, string $path): ?array
{
    $isPost = ($method === 'POST');
    foreach (pmpRouteTable() as $pattern => [$routeMethod, $script, $id, $mode]) {
        if ($routeMethod !== '*' && $routeMethod !== $method) {
            continue;
        }
        if (!preg_match(pmpCompilePattern($pattern), $path, $m)) {
            continue;
        }

        // /projects/new must win over /projects/<slug>. The table lists the
        // literal first, and PHP keeps the first match, so this is already
        // correct — but assert it, because the failure mode is a project
        // called "new" silently becoming unreachable.
        if ($mode === 'slug' && isset($m[1]) && $m[1] === '') {
            continue;
        }

        $slugTable = $mode === 'slug' ? (pmpRouteSlugTable()[$id] ?? null) : null;
        if ($mode === 'slug' && $slugTable === null) {
            // A slug route with no table cannot resolve; refuse rather than
            // include the script with an unset id.
            return null;
        }
        return ['method' => $method, 'script' => $script, 'mode' => $mode, 'slugTable' => $slugTable];
    }
    return null;
}

/**
 * Is this a slug candidate at all?
 *
 * A slug is lowercase alphanumeric plus single dashes. Anything else can never
 * exist in the database, so rejecting it here turns "weird URL" into a plain
 * 404 instead of a query against the UNIQUE index.
 */
function pmpIsWellFormedSlug(string $slug): bool
{
    return $slug !== '' && strlen($slug) <= 80
        && preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $slug) === 1;
}

/**
 * Serve a 404 that matches the app's existing error style.
 *
 * Deliberately does not redirect to the list page: C.2 R13 requires an unknown
 * slug to be a 404, so that a stale link is visibly broken rather than
 * silently landing the user on the wrong page.
 */
function pmpSendNotFound(string $detail = ''): void
{
    http_response_code(404);
    if (!headers_sent()) {
        header('Content-Type: text/html; charset=utf-8');
    }
    $detail = e($detail);
    $suffix = $detail !== '' ? '<p class="muted">' . $detail . '</p>' : '';
    echo '<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8">'
        . '<meta name="viewport" content="width=device-width, initial-scale=1.0">'
        . '<title>404 — Not found</title>'
        . '<link rel="icon" type="image/png" href="/assets/image/project-favicon.png">'
        . '<link rel="stylesheet" href="/assets/css/v2-tokens.css">'
        . '<link rel="stylesheet" href="/assets/css/style.css"></head>'
        . '<body class="page"><main class="container"><h1>404</h1>'
        . '<p>The page you asked for does not exist.</p>' . $suffix
        . '<p><a href="/">Back to the start page</a></p>'
        . '</main></body></html>';
    exit();
}

/**
 * Dispatch the current request.
 *
 * Order matters here (C.1 R20):
 *   1. Legacy 301s run FIRST, before session and permission logic, so an
 *      old bookmark is redirected even when the visitor is not logged in.
 *   2. The route table is consulted next.
 *   3. Only then is the target script included.
 */
function pmpDispatch(): void
{
    require_once __DIR__ . '/slug.php';
    require_once __DIR__ . '/legacy_redirects.php';

    $path = pmpRequestPath();
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

    // (1) Legacy .php URLs. Must precede auth: a logged-out visitor hitting
    // /project_detail.php?id=5 still gets the 301, and gets it whether or not
    // the target project exists.
    pmpRedirectLegacyUrl($path);

    // (2) Route table.
    $route = pmpMatchRoute($method, $path);
    if ($route === null) {
        pmpSendNotFound();
        return;
    }

    // (3) Resolve a <slug> segment to an id the script can use.
    if ($route['mode'] === 'slug') {
        $slug = pmpSlugSegment($path);
        if (!pmpIsWellFormedSlug($slug)) {
            pmpSendNotFound();
            return;
        }
        $id = findIdBySlug($slug, $route['slugTable']);
        if ($id === null) {
            // R13: a 404, not a redirect to the list page. This is what makes
            // a renamed-then-restored record fail loudly instead of silently
            // showing the wrong project.
            pmpSendNotFound('No record matches this address.');
            return;
        }
        $_GET['id'] = $id;
    }

    if ($route['mode'] === 'token') {
        $token = pmpTokenSegment($path);
        // issue-037: only 404 when the route REQUIRES a path segment and it is
        // missing. For /reset-password the token is optional — the page owns
        // the "Invalid reset link" message, and routing a bare visit to 404
        // made the page unreachable by hand and by every malformed link.
        if ($token === '' && $route['script'] !== 'reset_password.php') {
            pmpSendNotFound();
            return;
        }
        if ($token !== '') {
            $_GET['token'] = $token;
        }
    }

    $target = pmpResolveScript($route['script']);
    if ($target === null || !is_file($target)) {
        pmpSendNotFound();
        return;
    }

    // Let the included script emit headers/redirects; this is an include, not
    // a subprocess, so session_start() and setcookie() inside still work.
    require $target;
}

/** Absolute URL path of the request, always with a leading slash. */
function pmpRequestPath(): string
{
    $uri = $_SERVER['REQUEST_URI'] ?? '/';
    $path = parse_url($uri, PHP_URL_PATH);
    if (!is_string($path) || $path === '') {
        return '/';
    }
    return '/' . ltrim($path, '/');
}

/**
 * Pull the <slug> value back out of the path.
 *
 * Matched position depends on the route shape: /projects/<slug> has one
 * captured segment, /projects/<slug>/edit has one followed by a literal, so in
 * every case the slug is the first captured group.
 */
function pmpSlugSegment(string $path): string
{
    foreach (pmpRouteTable() as $pattern => [$m, , , $mode]) {
        if ($mode !== 'slug') {
            continue;
        }
        if (preg_match(pmpCompilePattern($pattern), $path, $mm)) {
            return $mm[1] ?? '';
        }
    }
    return '';
}

/**
 * The <token> value for /reset-password/<token>.
 *
 * issue-037: both URL forms exist in the wild and BOTH must work.
 *
 *   1. /reset-password/<token>        path form (the route's own shape)
 *   2. /reset-password?token=<token>  query form — this is what
 *      EmailService::renderPasswordResetBody() and renderWelcomeBody()
 *      actually put in every email (APP_URL . "/reset-password?token=..."),
 *      i.e. it is the link every real user clicks.
 *
 * Previously only the path form was read, so the query form fell through to
 * an empty token and pmpDispatch() answered 404. Every password-reset email
 * ever sent was therefore a dead link, and forgot-password.php offers no
 * other route to that page — the one you must click was the broken one.
 *
 * Query form is only consulted when the path carries no token segment; the
 * path form still wins when both are present, so a crafted URL cannot
 * override a path token with a query one.
 */
function pmpTokenSegment(string $path): string
{
    if (preg_match(pmpCompilePattern('/reset-password/<token>'), $path, $mm)) {
        return $mm[1] ?? '';
    }
    // issue-037: query-string form. Read $_GET directly rather than re-parsing
    // REQUEST_URI because nginx forwards the query string untouched and
    // $_GET is already the parsed, decoded form.
    $q = $_GET['token'] ?? '';
    if (is_string($q)) {
        return trim($q);
    }
    return '';
}

/**
 * Map a route script name to a real file.
 *
 * 'landing' is not a file — it is includes/landing.php, extracted from the old
 * index.php so the share-code logic stays verbatim.
 */
function pmpResolveScript(string $script): ?string
{
    if ($script === 'landing') {
        return __DIR__ . '/landing.php';
    }
    // Reject anything that could climb out of the project root. The table is
    // a literal in this file, so this is belt-and-braces, but the cost is nil.
    if ($script === '' || str_contains($script, '..') || str_contains($script, '/')) {
        return null;
    }
    return dirname(__DIR__) . '/' . $script;
}