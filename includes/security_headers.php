<?php
/**
 * Security response headers helper (issue-020).
 *
 * Six headers are sent on every PHP-rendered response:
 *   - Content-Security-Policy (defence in depth against XSS)
 *   - X-Frame-Options: DENY (clickjacking)
 *   - X-Content-Type-Options: nosniff (MIME-type confusion)
 *   - Referrer-Policy: strict-origin-when-cross-origin (URL leakage)
 *   - Permissions-Policy (sensor / API surface lockdown)
 *
 * The `static $sent` guard makes the helper safe to call from multiple
 * include points (header.php, client/dashboard.php, index.php) without
 * duplicate-header warnings from PHP.
 *
 * CSP notes:
 *   - script-src / style-src include 'unsafe-inline' because the current
 *     internal app uses onclick=, oninput=, and inline <style> blocks.
 *     Tightening to nonces is a v2.0 item (out of scope here).
 *   - frame-ancestors 'none' is the modern equivalent of X-Frame-Options: DENY.
 *   - form-action 'self' prevents the login form from being repurposed by
 *     an injected <form> to exfiltrate the password to an attacker domain.
 *   - base-uri 'self' prevents <base href="…"> injection from changing
 *     where relative URLs resolve.
 *
 * Nginx duplicates the three simple headers (X-Frame-Options,
 * X-Content-Type-Options, Referrer-Policy) so they appear on the
 * static-asset 200/404 responses too. The CSP and Permissions-Policy
 * are sent by PHP only — nginx add_header for those would require
 * escaping the semicolons, which is brittle. PHP-side is canonical.
 */

if (!function_exists('sendSecurityHeaders')) {
    function sendSecurityHeaders() {
        static $sent = false;
        if ($sent) {
            return;
        }
        $sent = true;

        // CSP for the internal app. The public client portal overrides
        // script-src via sendClientPortalSecurityHeaders() below (drops
        // 'unsafe-inline' because the toggle JS is in an external file).
        // Issue-029 v1.7.2.2 (Path B): adds https://static.cloudflareinsights.com
        // to script-src so the Cloudflare Web Analytics beacon
        // (auto-injected at the edge when the zone has Web Analytics
        // enabled) does not produce a CSP violation in the browser
        // console. See docs/issues/issue-029.md §5.1.
        header("Content-Security-Policy: "
            . "default-src 'self'; "
            . "script-src 'self' 'unsafe-inline' https://cdnjs.cloudflare.com https://static.cloudflareinsights.com; "
            . "style-src 'self' 'unsafe-inline' https://cdnjs.cloudflare.com; "
            . "img-src 'self' data:; "
            . "font-src 'self' https://cdnjs.cloudflare.com data:; "
            . "connect-src 'self'; "
            . "frame-ancestors 'none'; "
            . "base-uri 'self'; "
            . "form-action 'self'"
        );
        header("X-Frame-Options: DENY");
        header("X-Content-Type-Options: nosniff");
        header("Referrer-Policy: strict-origin-when-cross-origin");
        header("Permissions-Policy: "
            . "camera=(), microphone=(), geolocation=(), usb=(), "
            . "payment=(), accelerometer=(), gyroscope=()"
        );
    }
}

if (!function_exists('sendClientPortalSecurityHeaders')) {
    /**
     * Send the tightened CSP for the public client portal.
     *
     * Differences from the internal-app CSP:
     *   - script-src drops 'unsafe-inline' — the toggle JS is in
     *     assets/js/subtask-manager.js, loaded via <script src="…">,
     *     and the client portal has no inline event handlers.
     *   - All other directives are identical.
     *
     * Like sendSecurityHeaders(), this is safe to call multiple times.
     */
    function sendClientPortalSecurityHeaders() {
        static $sent = false;
        if ($sent) {
            return;
        }
        $sent = true;

        // Issue-029 v1.7.2.2 (Path B): also allow
        // https://static.cloudflareinsights.com in script-src so the
        // Cloudflare Web Analytics beacon does not produce a CSP
        // violation in the browser console. The client portal
        // (this helper) still drops 'unsafe-inline' from script-src
        // because the toggle JS is in an external file (issue-020
        // invariant preserved).
        header("Content-Security-Policy: "
            . "default-src 'self'; "
            . "script-src 'self' https://cdnjs.cloudflare.com https://static.cloudflareinsights.com; "
            . "style-src 'self' 'unsafe-inline' https://cdnjs.cloudflare.com; "
            . "img-src 'self' data:; "
            . "font-src 'self' https://cdnjs.cloudflare.com data:; "
            . "connect-src 'self'; "
            . "frame-ancestors 'none'; "
            . "base-uri 'self'; "
            . "form-action 'self'"
        );
        header("X-Frame-Options: DENY");
        header("X-Content-Type-Options: nosniff");
        header("Referrer-Policy: strict-origin-when-cross-origin");
        header("Permissions-Policy: "
            . "camera=(), microphone=(), geolocation=(), usb=(), "
            . "payment=(), accelerometer=(), gyroscope=()"
        );
    }
}
