<?php
declare(strict_types=1);

namespace Routlaw\Agent;

use Routlaw\Agent\Llm\Client as LlmClient;

/**
 * SelfCorrectionVerifier — T19 / FR-038 (self-correcting run verifier).
 *
 * Verifies that a completed agent run stayed within its APPROVED scope (tool allowlist
 * + approval-bound payload hashes) and that actual outputs match the approved intent.
 *
 * HARD RULE (FRD §2.2 "policy outside the model"): the PASS/FAIL verdict is computed by
 * SERVER-SIDE PHP rules below. The injected LLM client is consulted ONLY for an advisory
 * human-readable reason string — a model saying "looks fine" can NEVER flip a FAIL to PASS.
 *
 * Evaluation order (first mismatch wins):
 *  1. no approved tools → any actual call = out_of_scope (FAIL)
 *  2. any actual tool not in approved set = out_of_scope (FAIL)
 *  3. actual output hash set must equal the corresponding approved payload hash
 *     (approval-binding / replay-safety, HAR-01/09) = payload_bound_mismatch (FAIL)
 *  4. zero actual tool calls = no_tool_calls (FAIL, nothing to verify)
 *  5. otherwise = verified (PASS); LLM reason appended as advisory context
 */
final class SelfCorrectionVerifier
{
    public function __construct(private LlmClient $llm) {}

    /**
     * @param list<string> $approvedTools Tools the run was authorized to call.
     * @param list<string> $approvedHashes Approval-bound payload hashes (FR-015 T15 replay-safety).
     * @param list<string> $actualTools Tools actually invoked.
     * @param list<string> $actualHashes Output hashes produced, in order of invocation.
     * @return VerifierVerdict
     */
    public function verify(array $approvedTools, array $approvedHashes, array $actualTools, array $actualHashes, int $runId): VerifierVerdict
    {
        // Rule 1: nothing approved ⇒ any tool call is out of scope.
        if (empty($approvedTools)) {
            return $this->fail('out_of_scope', 'run used tools but no tools were approved (run ' . $runId . ')');
        }

        // Rule 2: every actual tool must be in the approved allowlist.
        foreach ($actualTools as $t) {
            if (!in_array($t, $approvedTools, true)) {
                return $this->fail('out_of_scope', "tool {$t} invoked but not in approved set (run {$runId})");
            }
        }

        // Rule 4 (checked before 3 so a dry run is reported meaningfully): no calls at all.
        if (empty($actualTools)) {
            return $this->fail('no_tool_calls', "no tool calls recorded for run {$runId}");
        }

        // Rule 3: approval-binding — actual output hashes must match approved payload hashes.
        $ap = count($actualHashes);
        $hp = count($approvedHashes);
        if ($ap !== $hp) {
            return $this->fail('payload_bound_mismatch', "output count ({$ap}) != approved count ({$hp}) for run {$runId}");
        }
        foreach ($actualHashes as $i => $h) {
            if (!isset($approvedHashes[$i]) || !hash_equals($approvedHashes[$i], $h)) {
                return $this->fail('payload_bound_mismatch', "output hash at index {$i} does not match approved payload (run {$runId})");
            }
        }

        // PASS — PHP verdict first; LLM only supplies advisory reasoning.
        $advisory = $this->llm->chat([
            ['role' => 'system', 'content' => 'You are an objective verifier. Summarize, in one sentence, '
                . 'whether the run stayed within its approved tool scope. Do not decide PASS/FAIL.'],
            ['role' => 'user', 'content' => 'Approved tools: ' . implode(', ', $approvedTools)
                . '. Actual tools: ' . implode(', ', $actualTools) . '.'],
        ]);
        $llmReason = is_string($advisory['content'] ?? null) ? $advisory['content'] : '';

        return new VerifierVerdict(true, 'verified', 'all outputs match approved scope' . ($llmReason !== '' ? ' — ' . $llmReason : ''), $llmReason);
    }

    private function fail(string $verdict, string $reason): VerifierVerdict
    {
        return new VerifierVerdict(false, $verdict, $reason, '');
    }
}
