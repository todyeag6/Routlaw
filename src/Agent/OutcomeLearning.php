<?php
declare(strict_types=1);

namespace Routlaw\Agent;

use Routlaw\Agent\Llm\Client as LlmClient;
use Routlaw\Agent\Memory\MemoryStore;

/**
 * OutcomeLearning — T19 / FR-039 (post-outcome learning, tenant-scoped).
 *
 * The LLM summarizes WHY a run succeeded/failed; PHP builds a provenance-tagged,
 * tenant-scoped MemoryEntry (kind=learning) and writes it via the tenant-scoped
 * MemoryStore. The model can never choose the company_id or bypass the store's
 * tenant/role WHERE filtering.
 */
final class OutcomeLearning
{
    public function __construct(
        private MemoryStore $store,
        private LlmClient $llm,
    ) {}

    /**
     * Capture a learning from a completed run.
     *
     * @param list<string> $approvedTools
     * @param list<string> $actualTools
     */
    public function record(
        int $companyId,
        int $runId,
        array $approvedTools,
        array $actualTools,
        string $approvedOutputHash,
        string $finalOutputHash,
        bool $verdict,
        ?int $callerRole = 5,
    ): int {
        // LLM produces an advisory, untrusted summary of the run outcome. We treat it
        // as DATA only — it is stored as text, never executed or promoted.
        $prompt = 'Summarize this agent run outcome in one sentence. '
            . 'Approved tools: ' . implode(', ', $approvedTools)
            . '. Actual tools: ' . implode(', ', $actualTools) . '. '
            . 'Verdict: ' . ($verdict ? 'PASS' : 'FAIL') . '.';
        $msg = $this->llm->chat([
            ['role' => 'system', 'content' => 'You summarize agent outcomes as data. One sentence.'],
            ['role' => 'user', 'content' => $prompt],
        ]);
        $summary = is_string($msg['content'] ?? null) ? $msg['content'] : ($verdict ? 'run passed' : 'run failed');

        $entry = new \Routlaw\Agent\Memory\MemoryEntry(
            companyId: $companyId,
            minRole: $callerRole ?? 5,
            content: $summary,
            provenance: [
                'kind'          => 'learning',
                'source'        => 'self_correction',
                'run_id'        => $runId,
                'approved_tools'=> $approvedTools,
                'actual_tools'  => $actualTools,
                'approved_hash' => $approvedOutputHash,
                'final_hash'    => $finalOutputHash,
                'verdict'       => $verdict ? 'pass' : 'fail',
            ],
            agentRunId: $runId,
            status: 'active',
            expiresAt: null,
            revalidatedAt: null,
        );

        return $this->store->write($entry);
    }
}
