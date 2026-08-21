<?php
declare(strict_types=1);

namespace Tests;

use PHPUnit\Framework\TestCase;

/**
 * Regression guard: config/autoload.php MUST load the Composer PSR-4 autoloader
 * so Routlaw\* classes resolve at runtime (web/worker/CLI). Tests passed before
 * only because tests/bootstrap.php loaded vendor/autoload.php directly; the live
 * entry point (public/index.php) requires config/autoload.php, which previously
 * did NOT pull in the autoloader -> "Class not found" + no security headers.
 */
final class BootstrapTest extends TestCase
{
    public function test_autoload_resolves_routlaw_security_headers(): void
    {
        // Simulate a production entry point requiring the app bootstrap.
        require __DIR__ . '/../config/autoload.php';

        $this->assertTrue(
            class_exists(\Routlaw\Security\Headers::class),
            'config/autoload.php must load the PSR-4 autoloader so Routlaw\Security\Headers resolves.'
        );
    }

    public function test_headers_emit_produces_exactly_one_enforced_csp(): void
    {
        require __DIR__ . '/../config/autoload.php';
        if (headers_sent() || PHP_SAPI === 'cli') {
            // PHPUnit runs under CLI where header() is a no-op and headers_list()
            // stays empty; the live HTTP probe (build-plan §3 T1.4) is authoritative.
            $this->markTestSkipped('CSP emission is verified via the live HTTP probe (CLI SAPI drops headers).');
        }
        \Routlaw\Security\Headers::emit(false);
        $csp = array_values(array_filter(
            headers_list(),
            static fn(string $h): bool => stripos($h, 'Content-Security-Policy:') === 0
        ));
        $this->assertCount(1, $csp, 'Exactly one enforced CSP must be emitted, zero report-only.');
    }
}
