<?php
declare(strict_types=1);

namespace Routlaw\Security;

/**
 * Security-header + CSP emitter (SEC-013, SEC-005).
 * Enforced CSP (no unsafe-inline in script-src/style-src). JSON-LD is CSP-exempt
 * by WHATWG spec, so no nonce needed for data blocks.
 */
final class Headers
{
    /** Default enforced policy. Same-origin assets only; no inline script/style. */
    public static function cspPolicy(): string
    {
        return "default-src 'self'; "
             . "script-src 'self'; "
              . "style-src 'self'; "                 // no unsafe-inline (ASVS L2 / plan §5.1); inline style attrs blocked too
             . "img-src 'self' data:; "
             . "font-src 'self'; "
             . "connect-src 'self'; "
             . "frame-ancestors 'none'; "
             . "base-uri 'self'; "
             . "form-action 'self';";
    }

    /** Emit all security headers. Idempotent: safe to call once per response. */
    public static function emit(bool $reportOnly = false): void
    {
        if (headers_sent()) {
            return;
        }
        $ro = $reportOnly ? '-Report-Only' : '';
        header("Content-Security-Policy{$ro}: " . self::cspPolicy());
        header('X-Content-Type-Options: nosniff');
        header('X-Frame-Options: DENY');
        header('Referrer-Policy: strict-origin-when-cross-origin');
        header('Permissions-Policy: geolocation=(), microphone=(), camera=()');
        // HSTS is added at the web-server layer after deploy validation (SEC-001).
    }
}
