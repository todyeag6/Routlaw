<?php
declare(strict_types=1);

namespace Tests;

use PHPUnit\Framework\TestCase;
use Routlaw\Agent\SecretHandleService;

/**
 * T17 SecretHandleService — HAR-06, LLM07, SEC-008/009.
 * Tools receive OPAQUE handles (sec_* token), never the raw secret. The real secret is
 * resolved server-side only. A handle is unguessable + non-reversible (random token,
 * not an encoding of the secret). A handle the model fabricates is rejected.
 */
final class SecretHandleServiceTest extends TestCase
{
    private SecretHandleService $svc;

    protected function setUp(): void
    {
        $this->svc = new SecretHandleService([
            'api_key' => 'AKIA-REAL-SECRET-123',
            'smtp_password' => 'smtp-secret',
        ]);
    }

    public function test_issue_returns_opaque_handle_not_secret(): void
    {
        $handle = $this->svc->issueHandle('api_key');
        $this->assertStringStartsWith('sec_', $handle, 'Handle must be an opaque sec_ token.');
        $this->assertNotSame('AKIA-REAL-SECRET-123', $handle, 'Handle must NOT be the raw secret.');
        $this->assertStringNotContainsString('AKIA', $handle, 'Raw secret must not appear in the handle.');
    }

    public function test_resolve_server_side_returns_secret(): void
    {
        $handle = $this->svc->issueHandle('api_key');
        $this->assertSame('AKIA-REAL-SECRET-123', $this->svc->resolve($handle), 'Server-side resolve returns the real secret.');
    }

    public function test_handle_is_not_reversible_without_service(): void
    {
        $handle = $this->svc->issueHandle('smtp_password');
        // The handle is random; it must not embed/encode the secret in any recoverable way.
        $this->assertStringNotContainsString('smtp-secret', $handle);
        $this->assertStringNotContainsString('smtp', $handle);
    }

    public function test_fabricated_handle_is_rejected(): void
    {
        $this->expectException(\OutOfBoundsException::class);
        $this->svc->resolve('sec_fabricated-by-model');
    }

    public function test_distinct_handles_for_same_secret(): void
    {
        $a = $this->svc->issueHandle('api_key');
        $b = $this->svc->issueHandle('api_key');
        $this->assertNotSame($a, $b, 'Each issue produces a distinct, unguessable handle.');
        $this->assertSame($this->svc->resolve($a), $this->svc->resolve($b), 'Both resolve to the same secret.');
    }
}
