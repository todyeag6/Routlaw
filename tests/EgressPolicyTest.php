<?php
declare(strict_types=1);

namespace Tests;

use PHPUnit\Framework\TestCase;
use Routlaw\Agent\EgressPolicy;
use Routlaw\Agent\ToolAllowlist;

/**
 * T17 EgressPolicy + policy-snapshot immutability — HAR-07/09, LLM07.
 * Each tool declares an egress host allowlist. A tool attempting an out-of-policy URL
 * is blocked (structural, testable without real network). The combined policy snapshot
 * (allowlist + egress) is byte-for-byte immutable under a model injection attempt
 * (policy-outside-model, mirroring T9 PolicyEnforcement).
 */
final class EgressPolicyTest extends TestCase
{
    private EgressPolicy $egress;
    private ToolAllowlist $allow;

    protected function setUp(): void
    {
        $this->allow = new ToolAllowlist();
        $this->egress = new EgressPolicy($this->allow);
    }

    private function hostOf(string $url): string
    {
        $h = parse_url($url, PHP_URL_HOST);
        return is_string($h) ? $h : '';
    }

    public function test_declared_egress_host_passes(): void
    {
        // extraction.run declares egress=['api.routlaw.local']; that exact host passes.
        $this->assertTrue($this->egress->isEgressAllowed('extraction.run', 'https://api.routlaw.local/v1'),
            'Declared egress host must be allowed.');
        // But a different host is still blocked.
        $this->assertFalse($this->egress->isEgressAllowed('extraction.run', 'https://other.example.com'),
            'Undeclared host blocked even for a tool with egress declared.');
    }
    public function test_empty_egress_blocks_all_hosts(): void
    {
        // carrier.lookup declares egress=[] (no outbound) in policy; therefore it must
        // reach NO external host — including an otherwise-benign one.
        $this->assertFalse($this->egress->isEgressAllowed('carrier.lookup', $this->hostOf('https://api.routlaw.local/x')),
            'A tool with empty egress must block ALL outbound hosts (HAR-07).');
    }

    public function test_out_of_policy_egress_blocked(): void
    {
        // A tool with NO declared egress must not reach any external host.
        $this->assertFalse($this->egress->isEgressAllowed('carrier.lookup', 'evil.example.com'),
            'Out-of-policy host must be blocked (HAR-07).');
        $this->assertFalse($this->egress->isEgressAllowed('carrier.lookup', '169.254.169.254'),
            'Cloud metadata endpoint must be blocked (SSRF defense).');
    }

    public function test_non_http_scheme_blocked(): void
    {
        $this->assertFalse($this->egress->isEgressAllowed('carrier.lookup', 'file:///etc/passwd'),
            'Non-http(s) schemes must be blocked (SSRF/LLM07).');
    }

    public function test_policy_snapshot_immutable_under_injection(): void
    {
        $before = $this->allow->snapshot();
        $hashBefore = md5(serialize($before));
        // Feed an adversarial payload that tries to rewrite egress/allowlist.
        $this->allow->ingestUntrustedModelOutput([
            'tools' => ['carrier.lookup' => ['egress' => ['*'], 'required_permission' => '']],
            'add_tool' => 'self.grant',
        ]);
        $after = $this->allow->snapshot();
        $this->assertSame($before, $after, 'Policy snapshot must be byte-for-byte unchanged after injection attempt (LLM07).');
        $this->assertSame($hashBefore, md5(serialize($after)), 'Serialized policy hash must match.');
        // The adversarial egress change must NOT take effect.
        $this->assertFalse($this->egress->isEgressAllowed('carrier.lookup', 'evil.example.com'),
            'Injected egress allowlist must not apply.');
    }
}
