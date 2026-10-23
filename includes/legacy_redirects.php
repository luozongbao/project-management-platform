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
 *   'plain'  → the target needs no lookup; the literal is used as-is.
 *   'id'     → read ?id=<n>, build /<collection>/<n>.
 *   'idEdit' → as above but the target ends in /edit.
 *
 * The kinds were named 'slug'/'slugEdit' when the canonical URL was the slug
 * and this file had to look it up. issue-040 made the id canonical, so the
 * names follow the behaviour. project_delete.php is POST-only and resolves to
 * an id route that is also POST-only, so it lives here too and is reached the
 * same way.
 */
function pmpLegacyMap(): array
{
    return [
        'dashboard.php'         => ['plain', '/dashboard'],
        'projects.php'          => ['plain', '/projects'],
        'project_edit.php'      => ['editOrNew', 'projects'],
        'project_detail.php'    => ['id', 'projects'],
        'project_delete.php'    => ['idEdit', 'projects'],
        'tasks.php'             => ['plain', '/tasks'],
        'task_edit.php'         => ['editOrNew', 'tasks'],
        'task_detail.php'       => ['id', 'tasks'],
        'contacts.php'          => ['plain', '/contacts'],
        'contact_edit.php'      => ['editOrNew', 'contacts'],
        'contact_detail.php'    => ['id', 'contacts'],
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
 * Build the canonical URL for a legacy `?id=` link.
 *
 * issue-040: the canonical form is the id, so this no longer looks the slug up.
 * It used to, because the slug was the public URL; now that it is not, the
 * lookup was a round-trip whose only output was a string we can build directly.
 *
 * No longer falls back to the collection index: an id taken from the old URL is
 * a real id, and whether the row still exists is the router's answer to give.
 * Redirecting to the index for a live id would hide a broken record, and the
 * delete-then-old-email case now produces the same 404 a bookmarked slug does,
 * which is consistent.
 */
function pmpLegacyIdUrl(string $table, int $id, bool $edit = false): string
{
    return '/' . pmpCollectionPath($table) . '/' . $id . ($edit ? '/edit' : '');
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
            ? pmpLegacyIdUrl($target, $id, true)
            : '/' . pmpCollectionPath($target) . '/new';
    } else {
        $id = (int) ($_GET['id'] ?? 0);
        if ($id <= 0) {
            // No id means no record. Send them to the collection rather than
            // bouncing off the 404 page.
            $url = '/' . pmpCollectionPath($target);
        } else {
            $url = pmpLegacyIdUrl($target, $id, $kind === 'idEdit');
        }
    }

    $url .= pmpQueryWithoutId();

    if (!headers_sent()) {
        header('Location: ' . $url, true, 301);
    }
    exit();
}