<?php
declare(strict_types=1);

/**
 * config/autoload.php — ROUTLAW bootstrap (ROUTLAW build-plan §3 T1.5).
 * Loads helpers, secrets, and (optionally) starts a hardened session.
 * No age gate here; MVP has no public age wall (carrier-intelligence SaaS).
 */

namespace Routlaw;

use Routlaw\Security\Html;
use Routlaw\Security\Csrf;

// --- Composer PSR-4 autoloader (src/ -> Routlaw\, tests/ -> Tests\) ---
// Every entry point (web, worker, CLI) must require THIS file so Routlaw\*
// classes resolve at runtime. tests/bootstrap.php loads vendor/autoload.php
// directly; production entry points go through here.
$autoloader = __DIR__ . '/../vendor/autoload.php';
if (is_file($autoloader)) {
    require $autoloader;
}

// --- Secrets (gitignored; never committed) ---
$_ENV['RL_DB_HOST']   ??= '127.0.0.1';
$_ENV['RL_DB_USER']   ??= 'root';
$_ENV['RL_DB_PASS']   ??= '';
$_ENV['RL_DB_NAME']   ??= 'routlaw';
$_ENV['RL_DB_PORT']   ??= '3306';
$secretsFile = __DIR__ . '/secrets.local.php';
if (is_file($secretsFile)) {
    // Expected to set $_ENV['RL_*'] values; @ include because it may not exist in CI.
    (static function () use ($secretsFile): void {
        require $secretsFile;
    })();
}

// --- Session hardening (SEC-002) ---
if (!defined('ROUTLAW_NO_SESSION') && PHP_SAPI !== 'cli') {
    if (session_status() !== PHP_SESSION_ACTIVE) {
        ini_set('session.cookie_secure', '1');      // HTTPS-only in prod
        ini_set('session.cookie_httponly', '1');
        ini_set('session.cookie_samesite', 'Lax');   // Strict where cross-site not needed
        ini_set('session.use_strict_mode', '1');
    }
}
