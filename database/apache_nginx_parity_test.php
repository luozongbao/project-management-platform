<?php
/**
 * Regression test — Apache (.htaccess) and nginx (docker/nginx.conf) deny
 * parity.
 *
 * Background
 * ----------
 * The project must install and run WITHOUT Docker (production = plain LAMP
 * / shared hosting with Apache). For a long time the Apache target was
 * completely non-functional:
 *
 *   - `.htaccess` contained `<DirectoryMatch "/(includes|database|...)">`.
 *     Apache 2.4 gives `<DirectoryMatch>` the context "server config,
 *     virtual host" only, so Apache REJECTED the whole file and answered
 *     500 for EVERY request (including the front controller).
 *   - `mod_rewrite` was never enabled on the Apache target, so
 *     `RewriteEngine On` alone was an "Invalid command" → 500 everywhere.
 *   - The dotfile / `docker/` / `client/lang.php` denies existed only in
 *     `docker/nginx.conf`, so a shared-hosting install published `/.env`
 *     (the database password), `/docker/entrypoint.sh` and the translation
 *     catalog over plain HTTP.
 *
 * This test locks the two web-server configurations against each other so
 * the drift cannot come back.
 *
 * What it asserts
 * ---------------
 *   1. STRUCTURAL sanity of `.htaccess`:
 *        a. No `<DirectoryMatch` — it is illegal in .htaccess and 500s
 *           every request.
 *        b. `RewriteEngine On` is present.
 *        c. The front-controller catch-all is present.
 *        d. `Options -Indexes` is present (no browsable directory listing).
 *        e. Every `<FilesMatch>` backstop lives INSIDE
 *           `<IfModule !mod_rewrite.c>`. `<FilesMatch>` authorization runs
 *           in the authz phase, BEFORE the per-directory rewrite (fixup)
 *           phase, so an unconditional `<FilesMatch>` turns every intended
 *           404 into a 403 and breaks status-code parity.
 *   2. STRUCTURAL sanity of `docker/nginx.conf`:
 *        a. The front-controller `try_files` is present.
 *        b. `mod_rewrite`'s Apache counterpart is not needed here, but the
 *           nginx deny set must be non-empty.
 *   3. PARITY: for every path in the curated list, the set of paths blocked
 *      by nginx must be identical to the set blocked by Apache.
 *      The deny regexes are extracted from the two config files and
 *      evaluated against each path (PCRE, so the same engine the servers
 *      use). nginx patterns are matched against the URI WITH a leading
 *      slash; Apache per-directory RewriteRule patterns are matched
 *      against the path RELATIVE to the .htaccess directory, WITHOUT one.
 *   4. COVERAGE: the sensitive paths must be blocked by BOTH servers. This
 *      catches a joint regression that the parity check alone would accept
 *      (both broken in the same way).
 *   5. The public paths must be reachable on BOTH servers.
 *   6. The Apache target's mod_rewrite requirement is documented in
 *      README.md (the official `php:apache` image does NOT enable it).
 *
 * The test is structural only — it needs no database and no running
 * server.
 *
 * Run with:
 *   docker exec project-management-platform-app-1 \
 *       php /var/www/html/database/apache_nginx_parity_test.php
 *
 * Exit code 0 = all checks pass, 1 = at least one failed.
 */

declare(strict_types=1);

$root = realpath(__DIR__ . '/..');

$failures = [];
$checks   = 0;

function fail(string $message): void
{
    global $failures;
    $failures[] = $message;
}

function check(bool $condition, string $message): void
{
    global $checks;
    $checks++;
    if (!$condition) {
        fail($message);
    }
}

// ---------------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------------

/**
 * Extract the deny patterns (the ones that answer "404") from an nginx
 * server block. Only `location` blocks whose body contains `return 404`
 * count — the static-asset and fastcgi locations are not denies.
 *
 * @return string[] The raw regexes, as written in the config.
 */
function extract_nginx_deny_patterns(string $conf): array
{
    $patterns = [];

    // location ~ <pattern> { ... return 404; ... }
    $re = '/location\s+(~\*?)\s+(\S+)\s*\{(.*?)\}/s';
    if (preg_match_all($re, $conf, $matches, PREG_SET_ORDER)) {
        foreach ($matches as $set) {
            if (stripos($set[3], 'return 404') !== false) {
                $patterns[] = $set[2];
            }
        }
    }

    return $patterns;
}

/**
 * Extract the deny patterns from an .htaccess file. Only rules carrying
 * the [R=404,L] flag are denies; the legacy 301s and the front-controller
 * catch-all use other flags and must not be counted.
 *
 * @return string[]
 */
function extract_apache_deny_patterns(string $htaccess): array
{
    $patterns = [];

    $re = '/^[ \t]*RewriteRule[ \t]+(\S+)[ \t]+\S+[ \t]+\[R=404,L\][ \t]*$/m';
    if (preg_match_all($re, $htaccess, $matches)) {
        $patterns = $matches[1];
    }

    return $patterns;
}

/**
 * Run a raw config regex against a subject, choosing a delimiter that does
 * not occur inside the pattern. Returns false when the pattern is invalid.
 */
function pattern_matches(string $pattern, string $subject): bool
{
    foreach (['#', '~', '%', '!', '@', '`'] as $delim) {
        if (strpos($pattern, $delim) === false) {
            $result = @preg_match($delim . $pattern . $delim, $subject);
            return $result === 1;
        }
    }

    return false;
}

/**
 * Strip comments from an Apache / nginx config.
 *
 * Both servers use "#" to end of line. The .htaccess documents the
 * <DirectoryMatch> pitfall in prose, so the structural check must look at
 * the directives only — a commented-out directive is harmless, a live one
 * 500s every request.
 */
function strip_comments(string $conf): string
{
    $out = [];
    foreach (preg_split('/\R/', $conf) as $line) {
        $trimmed = ltrim($line);
        if ($trimmed === '' || $trimmed[0] === '#') {
            continue;
        }
        $out[] = preg_replace('/(?<=\s)#.*$/', '', $line);
    }

    return implode("\n", $out);
}

/**
 * True when ANY deny pattern matches the subject.
 *
 * @param string[] $patterns
 */
function is_blocked(array $patterns, string $subject): bool
{
    foreach ($patterns as $pattern) {
        if (pattern_matches($pattern, $subject)) {
            return true;
        }
    }

    return false;
}

// ---------------------------------------------------------------------------
// Load the two configurations
// ---------------------------------------------------------------------------

$htaccessPath = $root . '/.htaccess';
$nginxPath   = $root . '/docker/nginx.conf';

if (!is_readable($htaccessPath)) {
    fwrite(STDERR, "FATAL: .htaccess is missing or unreadable\n");
    exit(1);
}
if (!is_readable($nginxPath)) {
    fwrite(STDERR, "FATAL: docker/nginx.conf is missing or unreadable\n");
    exit(1);
}

$htaccess = (string) file_get_contents($htaccessPath);
$nginx   = (string) file_get_contents($nginxPath);

// Parse the directives only — comments are stripped so that the prose in
// .htaccess (which documents the <DirectoryMatch> pitfall) is not mistaken
// for a live directive.
$htaccessDirectives = strip_comments($htaccess);
$nginxDirectives   = strip_comments($nginx);

$apachePatterns = extract_apache_deny_patterns($htaccessDirectives);
$nginxPatterns  = extract_nginx_deny_patterns($nginxDirectives);

// ---------------------------------------------------------------------------
// 1. Structural sanity of .htaccess
// ---------------------------------------------------------------------------

check(
    stripos($htaccessDirectives, '<DirectoryMatch') === false,
    '.htaccess contains a live <DirectoryMatch> directive — Apache 2.4 '
    . 'only allows it in server-config / virtual-host context. Apache '
    . 'REJECTS the whole .htaccess and answers 500 for EVERY request.'
);
check(
    preg_match('/^[ \t]*RewriteEngine[ \t]+On/m', $htaccessDirectives) === 1,
    '.htaccess is missing "RewriteEngine On" — the front controller and '
    . 'every deny rule depend on it.'
);
check(
    preg_match('/^[ \t]*RewriteRule[ \t]+\^\(\.\*\)\$[ \t]+index\.php[ \t]+\[QSA,L\]/m', $htaccessDirectives) === 1,
    '.htaccess is missing the front-controller catch-all '
    . '"RewriteRule ^(.*)$ index.php [QSA,L]" — every extensionless URL '
    . '(/login, /tasks, /projects/12) would 404.'
);
check(
    preg_match('/^[ \t]*Options[ \t]+-Indexes/m', $htaccessDirectives) === 1,
    '.htaccess is missing "Options -Indexes" — Apache would serve a '
    . 'browsable index of any directory without an index file.'
);
check(
    stripos($htaccessDirectives, 'RewriteBase') === false,
    '.htaccess sets RewriteBase — the per-directory rules below assume '
    . 'the document root, and RewriteBase would change every match.'
);

// Every <FilesMatch> must sit inside the <IfModule !mod_rewrite.c>
// backstop block. <FilesMatch> authorization runs in the authz phase,
// BEFORE the per-directory rewrite (fixup) phase, so an unconditional
// <FilesMatch> turns every intended 404 into a 403.
$backstopStart = stripos($htaccessDirectives, '<IfModule !mod_rewrite.c>');
$backstopEnd   = $backstopStart === false ? false : stripos($htaccessDirectives, '</IfModule>', $backstopStart);

check(
    $backstopStart !== false && $backstopEnd !== false,
    '.htaccess is missing the <IfModule !mod_rewrite.c> backstop wrapper.'
);

if ($backstopStart !== false && $backstopEnd !== false) {
    $insideBlock  = substr($htaccessDirectives, $backstopStart, $backstopEnd - $backstopStart);
    $outsideBlock = substr($htaccessDirectives, 0, $backstopStart)
        . substr($htaccessDirectives, $backstopEnd + strlen('</IfModule>'));

    check(
        substr_count($insideBlock, '<FilesMatch') === substr_count($htaccessDirectives, '<FilesMatch'),
        '.htaccess has a <FilesMatch> outside <IfModule !mod_rewrite.c>. '
        . '<FilesMatch> authorization runs in the authz phase, before the '
        . 'rewrite (fixup) phase, so it preempts the deny rules and every '
        . '404 becomes a 403.'
    );
    check(
        substr_count($outsideBlock, '<FilesMatch') === 0,
        '.htaccess has a <FilesMatch> outside the backstop wrapper.'
    );
}

// ---------------------------------------------------------------------------
// 2. Structural sanity of docker/nginx.conf
// ---------------------------------------------------------------------------

check(
    strpos($nginxDirectives, 'try_files $uri $uri/ /index.php?$query_string;') !== false,
    'docker/nginx.conf is missing the front-controller try_files.'
);
check(
    count($nginxPatterns) > 0,
    'docker/nginx.conf declares no deny rules (no "location" with '
    . '"return 404").'
);
check(
    count($apachePatterns) > 0,
    '.htaccess declares no deny rules (no "RewriteRule … [R=404,L]").'
);

// ---------------------------------------------------------------------------
// 3. Parity: the same paths must be blocked by both servers
// ---------------------------------------------------------------------------

// Sensitive paths: blocked by BOTH servers.
$sensitivePaths = [
    '.env',
    '.env.example',
    '.env.local',
    '.htaccess',
    '.htpasswd',
    '.git/config',
    '.github/AGENTS.md',
    '.vscode/settings.json',
    'config.php',
    'config.template.php',
    'composer.json',
    'composer.lock',
    'package.json',
    'package-lock.json',
    'npm-shrinkwrap.json',
    'docker-compose.yml',
    'docker-compose.override.yml',
    'Dockerfile',
    'README.md',
    'RELEASE.md',
    'database/schema.sql',
    'database/migrations/20260101_x.sql',
    'docs/requirements.md',
    'includes/functions.php',
    'includes/header.php',
    'vendor/autoload.php',
    'client/lang.php',
    'client/other.php',
    '_diag.php',
    '_smtp_test.php',
    'docker/entrypoint.sh',
    'docker/nginx.conf',
    'docker/_smtp_test.php',
    'assets/js/deploy.sh',
    'tmpdiag/probe.php',
    'backup.sql',
    'app.log',
    'server.ini',
];

// Public paths: reachable on BOTH servers.
$publicPaths = [
    'index.php',
    'login.php',
    'dashboard.php',
    'assets/css/style.css',
    'assets/js/script.js',
    'assets/image/logo.png',
    'client/index.php',
    'client/dashboard.php',
    'ajax/complete_task.php',
];

foreach (array_merge($sensitivePaths, $publicPaths) as $path) {
    // nginx matches its location regexes against the full URI (leading
    // slash); Apache matches a per-directory RewriteRule against the path
    // relative to the .htaccess directory (no leading slash).
    $nginxBlocked  = is_blocked($nginxPatterns, '/' . $path);
    $apacheBlocked = is_blocked($apachePatterns, $path);

    check(
        $nginxBlocked === $apacheBlocked,
        sprintf(
            'Deny parity broken for "%s": nginx=%s, Apache=%s. '
            . 'Every path denied in one config must be denied in the other.',
            $path,
            $nginxBlocked ? 'blocked' : 'allowed',
            $apacheBlocked ? 'blocked' : 'allowed'
        )
    );
}

// ---------------------------------------------------------------------------
// 4. Coverage: the sensitive paths must be blocked by BOTH servers
// ---------------------------------------------------------------------------

foreach ($sensitivePaths as $path) {
    check(
        is_blocked($nginxPatterns, '/' . $path),
        sprintf('nginx does NOT block the sensitive path "%s".', $path)
    );
    check(
        is_blocked($apachePatterns, $path),
        sprintf('Apache does NOT block the sensitive path "%s".', $path)
    );
}

// ---------------------------------------------------------------------------
// 5. The public paths must be reachable on BOTH servers
// ---------------------------------------------------------------------------

foreach ($publicPaths as $path) {
    check(
        !is_blocked($nginxPatterns, '/' . $path),
        sprintf('nginx wrongly blocks the public path "%s".', $path)
    );
    check(
        !is_blocked($apachePatterns, $path),
        sprintf('Apache wrongly blocks the public path "%s".', $path)
    );
}

// ---------------------------------------------------------------------------
// 6. The dev-only tooling and the ACME exception
// ---------------------------------------------------------------------------

check(
    is_blocked($nginxPatterns, '/docker/entrypoint.sh')
        && is_blocked($apachePatterns, 'docker/entrypoint.sh'),
    'The dev-only docker/ directory must be denied on both targets — a '
    . 'shared-hosting install copies the whole tree into public_html.'
);
check(
    !is_blocked($nginxPatterns, '/.well-known/acme-challenge/token')
        && !is_blocked($apachePatterns, '.well-known/acme-challenge/token'),
    'The ACME challenge directory (/.well-known/) must stay reachable on '
    . 'both targets so TLS renewal can write its token.'
);

// ---------------------------------------------------------------------------
// 7. Documentation of the Apache requirement
// ---------------------------------------------------------------------------

$readme = (string) file_get_contents($root . '/README.md');

check(
    stripos($readme, 'mod_rewrite') !== false,
    'README.md does not mention mod_rewrite — the Apache target silently '
    . '500s on every request when the module is off.'
);
check(
    stripos($readme, 'a2enmod') !== false,
    'README.md does not tell the operator how to enable mod_rewrite '
    . '(a2enmod rewrite).'
);

// ---------------------------------------------------------------------------
// Result
// ---------------------------------------------------------------------------

$failed = count($failures);

echo "Checks run: {$checks}\n";
echo "Failed: {$failed}\n";

if ($failed > 0) {
    echo "\n";
    foreach ($failures as $message) {
        echo "  FAIL  {$message}\n";
    }
    echo "\nASSERTIONS FAILED\n";
    exit(1);
}

echo "\nALL ASSERTIONS PASSED\n";
exit(0);
