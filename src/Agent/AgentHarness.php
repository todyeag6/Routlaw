<?php
declare(strict_types=1);

namespace Routlaw\Agent;

use Routlaw\Security\UserSession;

/**
 * AgentHarness — T17 orchestration (HAR-01..15, AG-01..11, LLM01..10).
 *
 * Single structural entry point for "the agent wants to call a tool". The pipeline is
 * immutable-by-construction (all gates are server-side PHP, none influenced by model
 * output):
 *   1. ToolAllowlist  — is the tool even permitted? (HAR-01/02/04, LLM06)
 *   2. ToolCallAuthorizer — per-call authz (role/permission/approval/payload-bound)
 *   3. KillSwitch + BudgetEnforcer — global halt + per-run caps (HAR-08, FR-032, LLM10)
 *   4. execute the (stub) tool
 *   5. ToolOutputValidator — schema + injection scan (HAR-13, LLM05)
 *   6. OutputSanitizer — HTML-encode untrusted output (HAR-14, LLM05, SEC-005/014)
 *
 * No live LLM is invoked here (per Phase 6 STOP condition: live LLM waits on owner
 * sign-off). The model's "intended" tool call is supplied as data; the harness never
 * lets it expand scope, grant tools, or bypass authz.
 */
final class AgentHarness
{
    public function __construct(
        private ToolCallAuthorizer $authorizer,
        private KillSwitch $killSwitch,
        private BudgetEnforcer $budget,
        private ToolOutputValidator $outputValidator,
        private OutputSanitizer $sanitizer,
        private SecretHandleService $secrets,
        private UserSession $session,
        private int $agentRunId,
        private string $agentVersion = 'agent.mvp.v1',
        private string $policyVersion = 'policy.mvp.v1',
    ) {
    }

    /**
     * Execute one tool step through the full policy pipeline.
     * @param array<string,mixed> $args tool arguments (may contain opaque secret handles)
     * @param string $payloadBound hash of the approved intent (for regulated writes)
     * @return ToolStepResult
     */
    public function step(AgentTool $tool, array $args, string $payloadBound = ''): ToolStepResult
    {
        $toolName = $tool->name();
        $companyId = $this->session->companyId;
        $roleSlug = $this->session->roleSlug;

        // 1+2: allowlist + per-call authorization (records tool_calls row).
        $decision = $this->authorizer->authorize(
            $toolName,
            $this->session,
            $args,
            $this->agentVersion,
            $this->policyVersion,
            $this->agentRunId,
        );
        if (!$decision->allowed) {
            return new ToolStepResult($toolName, false, $decision->denyReason, true, false, null, $decision->denyReason);
        }

        // 3: kill switch + budget.
        $killActive = $this->killSwitch->isActive($companyId);
        $budgetOk = $this->budget->canStep($this->agentRunId);
        if ($killActive || !$budgetOk) {
            $reason = $killActive ? 'kill switch active' : 'budget exceeded';
            return new ToolStepResult($toolName, true, null, $budgetOk, $killActive, null, $reason);
        }

        // 4: execute tool.
        try {
            $raw = $tool->run($args, $this->secrets);
        } catch (\Throwable $e) {
            return new ToolStepResult($toolName, true, null, true, false, null, 'tool execution failed: ' . $e->getMessage());
        }

        // 5: output schema validation + injection scan.
        $validated = $this->outputValidator->validate($toolName, $raw);
        if (!$validated->valid) {
            return new ToolStepResult($toolName, true, null, true, false, $validated->outputHash, 'output rejected: ' . $validated->error);
        }

        // 6: sanitize for safe downstream rendering.
        $rawText = is_string($raw) ? $raw : json_encode($raw, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $rawText = is_string($rawText) ? $rawText : '';
        $sanitized = $this->sanitizer->sanitizeWithFlag($rawText);

        // Record successful step + sanitized output on the tool_calls row.
        $this->authorizer->recordOutput($this->agentRunId, $toolName, $validated->outputHash, $sanitized->sanitized);

        return new ToolStepResult($toolName, true, null, true, false, $validated->outputHash, null);
    }
}
