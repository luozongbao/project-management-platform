<?php
/**
 * Front controller for v2.0 slug routing (issue-035 Appendix C.2).
 *
 * This file used to be the public landing page. It still is — `/` routes to
 * includes/landing.php, which holds the old body verbatim — but the routing
 * decisions now live in includes/Router.php.
 *
 * Why this shape: nginx already forwards unmatched paths here
 * (`try_files $uri $uri/ /index.php?$query_string`) and .htaccess already has
 * a matching catch-all, so no server config needs to change. Both stacks hand
 * us every URL and we decide what it means in one place.
 *
 * The landing page moved to includes/landing.php rather than being rewritten,
 * because it carries the share-code flow — the one piece of C.1 R1-R10 that
 * must not be disturbed. The ?code= handling there is byte-for-byte what v1.8.0
 * shipped.
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/Router.php';

pmpDispatch();
