<?php
/**
 * Legacy `.php` → slug 301 redirects (issue-035 Appendix C.2 R11/R12).
 *
 * Every `.php` URL that existed in v1.x must keep working forever, because
 * they are already sitting in clients' inboxes and browser bookmarks. Each one
 * answers 301 with the slug URL in Location, so the address bar settles on the
 * canonical form and search engines stop seeing two URLs per page.
 *
 * Two properties this file depends on, both from the spec:
 *
 *  R11 — it is a real 301, not a 302 and not an internal rewrite. An internal
 *        rewrite would render the page at the old URL and leave it in the
 *        address bar, which is the thing we are trying to eliminate.
 *
 *  R12 — the query string survives: project_detail.php?id=12&foo=bar becomes
 *        /projects/<slug>?foo=bar. The `id` is consumed to resolve the slug and
 *        is NOT re-appended; everything else is passed through untouched, so
 *        filter/sort/page params keep working on the new URL.
 *
 * The whole thing runs before any session or permission logic (see
 * pmpDispatch), because a logged-out visitor following an old bookmark must
 * still get the 301 instead of a login page.
 */

/**
 * Legacy file → [target kind, table for slug lookup].
 *
 *   'plain'  → the target needs no slug lookup; the literal is used as-is.
 *   'slug'   → read ?id=<n>, look the slug up, build /<collection>/<slug>.
 *   'slugEdit'→ as above but the target ends in /edit.
 *
 * project_delete.php is POST-only and resolves to a slug route that is also
 * POST-only, so it lives here too and is reached the same way.
 */
function pmpLegacyMap(): array
{
    return [
        'dashboard.php'         => ['plain', '/dashboard'],
        'projects.php'          => ['plain', '/projects'],
        'project_edit.php'      => ['editOrNew', 'projects'],
        'project_detail.php'    => ['slug', 'projects'],
        'project_delete.php'    => ['slugEdit', 'projects'],
        'tasks.php'             => ['plain', '/tasks'],
        'task_edit.php'         => ['editOrNew', 'tasks'],
        'task_detail.php'       => ['slug', 'tasks'],
        'contacts.php'          => ['plain', '/contacts'],
        'contact_edit.php'      => ['editOrNew', 'contacts'],
        'contact_detail.php'    => ['slug', 'contacts'],
        'profile.php'           => ['plain', '/profile'],
        'logout.php'            => ['plain', '/logout'],
        'login.php'             => ['plain', '/login'],
        'register.php'          => ['plain', '/register'],
        'forgot_password.php'   => ['plain', '/forgot-password'],
        'reset_password.php'    => ['plain', '/reset-password'],
    ];
}

/** Collection segment for a table name. */
function pmpCollectionPath(string $table): string
{
    return $table === 'tasks' ? 'tasks' : ($table === 'contacts' ? 'contacts' : 'projects');
}

/**
 * Build the slug URL for a legacy `?id=` link.
 *
 * Returns null when the id is missing or no longer resolves — e.g. the
 * project was deleted after the email went out. In that case there is no
 * canonical URL to point at, so we fall back to the collection's index rather
 * than redirecting into a 404 loop.
 */
function pmpLegacySlugUrl(string $table, int $id, bool $edit = false): string
{
    require_once __DIR__ . '/slug.php';
    $slug = getSlugById($table, $id);
    $base = '/' . pmpCollectionPath($table);
    if ($slug === '') {
        return $base;
    }
    return $base . '/' . $slug . ($edit ? '/edit' : '');
}

/**
 * The query string with `id` removed.
 *
 * `id` is an implementation detail of the old URL; the slug carries that
 * meaning now. Leaving it in would also mean the new URL has an id parameter
 * nothing reads, and it invites the id from the *old* URL to disagree with the
 * slug if someone hand-edits it.
 */
function pmpQueryWithoutId(): string
{
    $params = $_GET;
    unset($params['id']);
    if (!$params) {
        return '';
    }
    // http_build_query re-encodes; this is fine for the app's own params and
    // keeps the raw spelling of anything the app does not own.
    $qs = http_build_query($params);
    return $qs === '' ? '' : '?' . $qs;
}

/**
 * If $path is a legacy `.php` URL, issue the 301 and exit.
 *
 * Returns normally when $path is not a legacy URL, which is the common case.
 */
function pmpRedirectLegacyUrl(string $path): void
{
    $file = basename($path);
    // Only act when the path IS the .php file at the site root, i.e. nothing
    // before the filename. That single check covers the nested cases too:
    // /includes/functions.php and /client/dashboard.php both have a non-empty
    // prefix, so they never reach here (and the client portal must never be
    // caught here - C.1 R10).
    if ($path !== '/' . $file || $file === $path) {
        return;
    }

    $map = pmpLegacyMap();
    if (!isset($map[$file])) {
        return;
    }

    [$kind, $target] = $map[$file];

    if ($kind === 'plain') {
        $url = $target;
    } elseif ($kind === 'editOrNew') {
        // project_edit.php with an id is "edit this one"; without an id it is
        // the create form. Same file, two different destinations.
        $id = (int) ($_GET['id'] ?? 0);
        $url = $id > 0
            ? pmpLegacySlugUrl($target, $id, true)
            : '/' . pmpCollectionPath($target) . '/new';
    } else {
        $id = (int) ($_GET['id'] ?? 0);
        if ($id <= 0) {
            // No id means no record. Send them to the collection rather than
            // bouncing off the 404 page.
            $url = '/' . pmpCollectionPath($target);
        } else {
            $url = pmpLegacySlugUrl($target, $id, $kind === 'slugEdit');
        }
    }

    $url .= pmpQueryWithoutId();

    if (!headers_sent()) {
        header('Location: ' . $url, true, 301);
    }
    exit();
}