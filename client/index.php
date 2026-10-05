<?php
/**
 * Legacy client landing page redirect shim.
 *
 * The public client entry point moved to the domain root in issue-004.
 * This file is kept so that old bookmarks, shared links, and email
 * templates pointing at /client/ keep working: every request is redirected
 * to / with the original query string preserved (especially ?code=…).
 *
 * A 301 would also be defensible, but a 302 keeps the door open if we ever
 * want to retire this route without rewriting the URL in stored links.
 */

$qs = $_SERVER['QUERY_STRING'] ?? '';
$target = '/';
if ($qs !== '') {
    $target .= '?' . $qs;
}

header('Location: ' . $target, true, 302);
exit();
?>