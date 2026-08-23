<?php
declare(strict_types=1);

namespace Tests;

use PHPUnit\Framework\TestCase;
use Routlaw\Agent\Llm\Client as LlmClient;
use Routlaw\Agent\SelfCorrectionVerifier;

/**
 * T19-3 SelfCorrectionVerifier — FR-038 (self-correction verifier).
 *
 * The verdict is RULE-BASED PHP (FRD §2.2): did the run stay within its approved
 * tooling scope and did outputs match approved payload hashes? The LLM reasoner
 * is advisory-only input; it can annotate, never decide.
 */
final class SelfCorrectionVerifierTest extends TestCase
{
    public function test_pass_when_run_stays_in_scope_and_outputs_match(): void
    {
        // approved plan: tool carrier.lookup once; LLM reasoner agrees.
        $llm = new StubLlmClient(content: 'look fine', toolCalls: []);
        $v = new SelfCorrectionVerifier($llm);
        $verdict = $v->verify(
            approvedTools: ['carrier.lookup'],
            approvedHashes: ['abc123'],
            actualTools:   ['carrier.lookup'],
            actualHashes:  ['abc123'],
            runId: 1,
        );
        $this->assertTrue($verdict->passed, 'in-scope + matching outputs => PASS');
        $this->assertSame('verified', $verdict->verdict);
        $this->assertStringContainsString('approved scope', $verdict->reason);
        $this->assertNotEmpty($verdict->llmAdvisory, 'LLM advisory context captured (FR-038)');
    }

    public function test_fail_when_tool_outside_approved_scope(): void
    {
        $llm = new StubLlmClient(content: 'ran carrier.write', toolCalls: []);
        $v = new SelfCorrectionVerifier($llm);
        $verdict = $v->verify(
            approvedTools: ['carrier.lookup'],
            approvedHashes: ['abc123'],
            actualTools:   ['carrier.lookup', 'self.grant'],   // self.grant NOT approved
            actualHashes:  ['abc123'],
            runId: 2,
        );
        $this->assertFalse($verdict->passed, 'tool outside approved scope => FAIL (rule-based, not LLM)');
        $this->assertSame('out_of_scope', $verdict->verdict);
        $this->assertNotEmpty($verdict->reason);
    }

    public function test_fail_when_output_hash_mismatch(): void
    {
        $llm = new StubLlmClient(content: 'output changed', toolCalls: []);
        $v = new SelfCorrectionVerifier($llm);
        $verdict = $v->verify(
            approvedTools: ['carrier.lookup'],
            approvedHashes: ['abc123'],
            actualTools:   ['carrier.lookup'],
            actualHashes:  ['deadbeef'],   // approval-bound hash differs
            runId: 3,
        );
        $this->assertFalse($verdict->passed, 'payload-bound mismatch => FAIL');
        $this->assertSame('payload_bound_mismatch', $verdict->verdict);
    }

    public function test_llm_advisory_only_verdict_is_php_decided(): void
    {
        // Even if the (stubbed) LLM says "looks great", a missing output hash means FAIL.
        $llm = new StubLlmClient(content: 'all good', toolCalls: []);
        $v = new SelfCorrectionVerifier($llm);
        $verdict = $v->verify(
            approvedTools: ['carrier.lookup'],
            approvedHashes: ['abc123'],
            actualTools:   [],            // nothing actually ran
            actualHashes:  [],           // no output
            runId: 4,
        );
        $this->assertFalse($verdict->passed, 'PHP verdict wins over LLM optimism');
        $this->assertSame('no_tool_calls', $verdict->verdict);
    }

    public function test_zero_tools_approved_then_any_call_is_out_of_scope(): void
    {
        $llm = new StubLlmClient(content: 'nothing approved', toolCalls: []);
        $v = new SelfCorrectionVerifier($llm);
        $verdict = $v->verify(
            approvedTools: [],
            approvedHashes: [],
            actualTools:   ['carrier.lookup'],
            actualHashes:  ['x'],
            runId: 5,
        );
        $this->assertFalse($verdict->passed);
        $this->assertSame('out_of_scope', $verdict->verdict);
    }
}

/** Minimal stub LLM (no network) — returns a canned message. */
final class StubLlmClient implements LlmClient
{
    public function __construct(private string $content = '', private array $toolCalls = []) {}
    public function chat(array $messages, array $options = []): array
    {
        return ['role' => 'assistant', 'content' => $this->content, 'tool_calls' => $this->toolCalls];
    }
}
