<?php
declare(strict_types=1);

namespace Tests;

use PHPUnit\Framework\TestCase;
use Routlaw\Guard;

/**
 * TDD for the standing quality guard (scripts/guard.php).
 * The guard must exit non-zero when a seeded secret violation is present
 * (ROUTLAW build-plan §3 T1.2; mirrors the mokimi guard contract).
 */
final class GuardTest extends TestCase
{
    public function test_secret_scan_flags_hardcoded_password(): void
    {
        $code = "<?php\n\$db_password = 'supersecret123';";
        $violations = Guard::scanSecrets($code);
        $this->assertNotEmpty($violations, 'Expected a hardcoded-secret violation for a literal password assignment.');
    }

    public function test_secret_scan_passes_env_reference(): void
    {
        $code = "<?php\n\$db_password = \$_ENV['DB_PASS'] ?? '';";
        $violations = Guard::scanSecrets($code);
        $this->assertEmpty($violations, 'An env-var reference must NOT trip the secret scan.');
    }

    public function test_decision_is_nonzero_when_violations_present(): void
    {
        $this->assertSame(1, Guard::decide(['hardcoded secret candidate']), 'Guard must fail closed on any violation.');
        $this->assertSame(0, Guard::decide([]), 'Guard must pass when clean.');
    }

    public function test_cli_exits_nonzero_on_seeded_secret_file(): void
    {
        $dir = sys_get_temp_dir() . '/rlguard_' . uniqid('', true);
        mkdir($dir);
        file_put_contents($dir . '/bad.php', "<?php\n\$api_key = 'AKIA1234567890abcdef';");

        $cmd = escapeshellarg(PHP_BINARY)
            . ' ' . escapeshellarg(__DIR__ . '/../scripts/guard.php')
            . ' --paths=' . escapeshellarg($dir);
        exec($cmd, $output, $exitCode);

        array_map('unlink', glob($dir . '/*') ?: []);
        rmdir($dir);

        $this->assertSame(1, $exitCode, 'Guard CLI must exit 1 on a seeded secret violation. Output: ' . implode("\n", $output));
    }
}
