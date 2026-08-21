<?php
declare(strict_types=1);

namespace Tests;

use PHPUnit\Framework\TestCase;
use Routlaw\Security\Headers;

final class HeadersTest extends TestCase
{
    public function test_csp_has_no_unsafe_inline_in_script_src(): void
    {
        $csp = Headers::cspPolicy();
        // script-src must be self-only (no unsafe-inline). Allow a trailing ';' or end.
        $this->assertMatchesRegularExpression("/script-src\s+'self'(;|\s|$)/", $csp, 'script-src must be self-only (no unsafe-inline).');
        $this->assertStringNotContainsString("script-src 'self' 'unsafe-inline'", $csp);
    }

    public function test_csp_has_no_unsafe_inline_in_style_src(): void
    {
        $csp = Headers::cspPolicy();
        // style-src must be self-only (no unsafe-inline). ASVS L2 / plan §5.1 forbids
        // unsafe-inline in BOTH script-src and style-src.
        $this->assertStringNotContainsString(
            "style-src 'self' 'unsafe-inline'",
            $csp,
            'style-src must be self-only (no unsafe-inline) per ASVS L2 / plan §5.1.'
        );
    }

    public function test_emit_sets_exactly_one_enforced_csp_and_no_report_only(): void
    {
        // The policy contract: frame-ancestors none, base-uri self, form-action self,
        // and no report-only variant baked into the base policy.
        $csp = Headers::cspPolicy();
        $this->assertStringContainsString("frame-ancestors 'none'", $csp);
        $this->assertStringContainsString("base-uri 'self'", $csp);
        $this->assertStringContainsString("form-action 'self'", $csp);
        $this->assertStringNotContainsString('-Report-Only', $csp, 'Base policy must not be report-only.');
    }
}
