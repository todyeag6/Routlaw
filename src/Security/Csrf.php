<?php
declare(strict_types=1);

namespace Routlaw\Security;

/**
 * Scoped CSRF tokens (SEC-003). Stable per session+scope; constant-time verify.
 */
final class Csrf
{
    private const KEY = '__routlaw_csrf';

    public static function token(string $scope): string
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            // Allowed in CLI/tests where session may be absent; keep in superglobal.
            if (!isset($_SESSION)) {
                $_SESSION = [];
            }
        }
        $_SESSION[self::KEY][$scope] ??= bin2hex(random_bytes(32));
        return $_SESSION[self::KEY][$scope];
    }

    public static function verify(?string $token, string $scope): bool
    {
        if ($token === null || $token === '' || !isset($_SESSION[self::KEY][$scope])) {
            return false;
        }
        return hash_equals($_SESSION[self::KEY][$scope], $token);
    }
}
