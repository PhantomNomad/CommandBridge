<?php
declare(strict_types=1);

/**
 * Standard browser security headers for every page.
 *
 * Content-Security-Policy mode comes from the environment variable COMMANDBRIDGE_CSP_MODE (set it in the Apache site
 * with SetEnv): "enforce" (default), "report-only" (log violations in the browser console but block nothing - use this
 * if a page ever looks broken after an update) or "off". The policy still allows inline scripts/styles because the pages
 * use them; it blocks framing by other sites, base-tag tricks and sending forms to other sites.
 */
if (!function_exists('ics_send_security_headers')) {
    function ics_send_security_headers(): void
    {
        if (PHP_SAPI === 'cli' || headers_sent()) {
            return;
        }
        header('X-Content-Type-Options: nosniff');
        header('X-Frame-Options: SAMEORIGIN');
        header('Referrer-Policy: strict-origin-when-cross-origin');
        header('Permissions-Policy: camera=(), microphone=(), geolocation=(), payment=()');
        header('Cross-Origin-Opener-Policy: same-origin');
        // Strict-Transport-Security is NOT sent from here. Once a browser has seen it over a trusted certificate it
        // refuses any other certificate for that name for a year, with no "proceed anyway". The installer adds it in
        // the Apache site only for trusted certificates (Let's Encrypt or your own), never for self-signed ones.

        $mode = strtolower(trim((string)(getenv('COMMANDBRIDGE_CSP_MODE') ?: ($_SERVER['COMMANDBRIDGE_CSP_MODE'] ?? 'enforce'))));
        if ($mode === 'off') {
            return;
        }
        $policy = "default-src 'self'; script-src 'self' 'unsafe-inline'; style-src 'self' 'unsafe-inline'; "
            . "img-src 'self' data: blob: https://openweathermap.org; font-src 'self' data:; connect-src 'self'; "
            . "object-src 'self'; base-uri 'self'; form-action 'self' https://login.microsoftonline.com; frame-ancestors 'self'";
        header(($mode === 'report-only' ? 'Content-Security-Policy-Report-Only' : 'Content-Security-Policy') . ': ' . $policy);
    }
}
