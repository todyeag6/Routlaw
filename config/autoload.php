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
// Best practice: production MUST supply a least-privilege DB user via secrets.local.php
// (single schema; SELECT/INSERT/UPDATE/DELETE/EXECUTE only — NO DROP/ALTER/GRANT).
// The 'root'/empty defaults below are DEV-ONLY fallbacks for local XAMPP. Running prod
// with root is a compliance/security defect and will be flagged by the bootstrap.
$isProd = ($_ENV['RL_ENV'] ?? 'dev') === 'prod';
if ($isProd && ($_ENV['RL_DB_USER'] ?? 'root') === 'root') {
    // Loud failure rather than silently running prod as root.
    throw new \RuntimeException(
        'RL_DB_USER is root in production. Supply a least-privilege DB user in secrets.local.php.'
    );
}
$_ENV['RL_DB_HOST']   ??= '127.0.0.1';
$_ENV['RL_DB_USER']   ??= 'root';
$_ENV['RL_DB_PASS']   ??= '';
$_ENV['RL_DB_NAME']   ??= 'routlaw';
$_ENV['RL_DB_PORT']   ??= '3306';

// --- Outbound email (Apple iCloud SMTP via PHPMailer; STARTTLS on 587) ---
// Production: set these in secrets.local.php / hPanel env. Empty = mail disabled (dev safe).
$_ENV['RL_SMTP_HOST']     ??= 'smtp.mail.me.com';
$_ENV['RL_SMTP_PORT']     ??= '587';
$_ENV['RL_SMTP_USERNAME'] ??= '';
$_ENV['RL_SMTP_PASSWORD'] ??= '';
$_ENV['RL_SMTP_FROM']     ??= '';
$_ENV['RL_SMTP_FROM_NAME'] ??= 'ROUTLAW';

// --- Cloudflare Turnstile (bot defense for public forms; preferred over reCAPTCHA on CF) ---
// Production: set site + secret keys in secrets.local.php / hPanel env.
// Empty keys => verification is skipped (dev). Front-end loads the widget from RL_TURNSTILE_SITE_KEY.
$_ENV['RL_TURNSTILE_SITE_KEY']   ??= '';
$_ENV['RL_TURNSTILE_SECRET_KEY']  ??= '';
// Cloudflare edge verification endpoint (siteverify). Constant; no secret.
$_ENV['RL_TURNSTILE_VERIFY_URL'] ??= 'https://challenges.cloudflare.com/turnstile/v0/siteverify';

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
