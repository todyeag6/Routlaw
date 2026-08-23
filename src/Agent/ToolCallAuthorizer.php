<?php
declare(strict_types=1);

namespace Routlaw\Agent;

use Routlaw\Approvals\ApprovalService;
use Routlaw\Security\AuditLog;
use Routlaw\Security\Authorization;
use Routlaw\Security\UserSession;

/**
 * ToolCallAuthorizer — T17 per-call authorization (HAR-01/02/09, LLM06).
 *
 * For every tool invocation the harness proposes, this decides ALLOW / DENY using:
 *   1. Allowlist membership (ToolAllowlist — server-side, immutable by model output).
 *   2. Caller tenant + role permission (Authorization — RBAC at the data layer).
 *   3. If the tool writes a regulated entity, a payload-bound human approval
 *      (ApprovalService::isApproved — reuse of T15, tamper/replay safe).
 *
 * A DENY records a tool_calls row (allowed=0, result_status='blocked') + an audit
 * event, and the harness MUST NOT execute any side effect. There is no path by which
 * a model can broaden this decision.
 */
final class ToolCallAuthorizer
{
    public function __construct(
        private \mysqli $db,
        private ToolAllowlist $allowlist,
        private Authorization $authorization,
        private AuditLog $audit,
    ) {
    }

    /**
     * @param array<string,mixed> $payload The tool's input payload (for approval binding).
     * @param int|null            $agentRunId Optional trace linkage to agent_runs.
     */
    public function authorize(
        string $tool,
        UserSession $caller,
        array $payload,
        string $agentVersion,
        string $policyVersion,
        ?int $agentRunId = null,
    ): ToolCallDecision {
        $companyId = $caller->companyId;

        // (1) Allowlist gate — first, cheapest, and immutable.
        if (!$this->allowlist->isAllowed($tool, $agentVersion, $policyVersion)) {
            return $this->deny($tool, $companyId, $agentRunId, 'tool not in allowlist (HAR-04)', 'allowlist');
        }

        // (2) RBAC gate — tenant + role permission.
        $required = $this->allowlist->requiredPermission($tool, $agentVersion, $policyVersion);
        if ($required !== '' && !$this->authorization->hasPermission($caller->userId, $companyId, $required)) {
            return $this->deny($tool, $companyId, $agentRunId, "missing permission: {$required}", 'permission');
        }

        // (3) Regulated-write gate — payload-bound approval reuse (T15).
        if ($this->allowlist->requiresApproval($tool, $agentVersion, $policyVersion)
            || $this->allowlist->writesRegulated($tool, $agentVersion, $policyVersion)) {
            $approval = new ApprovalService($this->db);
            $resourceId = (int) ($payload['carrier_id'] ?? $payload['document_id'] ?? $payload['entry_id'] ?? 0);
            $resourceType = $this->resourceTypeFor($tool);
            $companies = [$companyId];
            if (!$approval->isApproved($tool, $resourceType, $resourceId, $payload, $companies)) {
                return $this->deny($tool, $companyId, $agentRunId, 'no valid payload-bound approval (LLM06)', 'approval');
            }
            $decision = new ToolCallDecision(true, $tool, 'allowed', null, $resourceId);
            $this->recordToolCall($tool, $companyId, $agentRunId, true, null, $decision->approvalId);
            return $decision;
        }

        $decision = new ToolCallDecision(true, $tool, 'allowed', null, null);
        $this->recordToolCall($tool, $companyId, $agentRunId, true, null, null);
        return $decision;
    }

    private function deny(
        string $tool,
        int $companyId,
        ?int $agentRunId,
        string $reason,
        string $category,
    ): ToolCallDecision {
        $this->recordToolCall($tool, $companyId, $agentRunId, false, $reason, null);
        $this->audit->recordSystem('tool_call.denied', $companyId, 'agent', 'deny', [
            'tool' => $tool,
            'reason' => $reason,
            'category' => $category,
        ], 'tool_call', $tool);
        return new ToolCallDecision(false, $tool, 'blocked', $reason, null);
    }

    private function resourceTypeFor(string $tool): string
    {
        return match ($tool) {
            'carrier.update' => 'carrier',
            'document.share' => 'document',
            'memory.write' => 'memory',
            default => 'tool',
        };
    }

    /**
     * Record a tool_call using the FULL 016-extended schema (allowed, deny_reason,
     * output_hash, sanitized). Inserts directly because AuditLog::recordToolCall only
     * covers the original 5 columns.
     */
    private function recordToolCall(
        string $tool,
        int $companyId,
        ?int $agentRunId,
        bool $allowed,
        ?string $denyReason,
        ?int $approvalId,
    ): void {
        $stmt = $this->db->prepare(
            'INSERT INTO tool_calls '
            . '(company_id, agent_run_id, tool_name, request_hash, result_status, allowed, deny_reason) '
            . 'VALUES (?, ?, ?, ?, ?, ?, ?)'
        );
        if ($stmt === false) {
            throw new \RuntimeException('Failed to prepare tool_call insert: ' . $this->db->error);
        }
        $hash = $approvalId !== null ? (string) $approvalId : '';
        $status = $allowed ? 'success' : 'blocked';
        $allowedInt = $allowed ? 1 : 0;
        $types = 'iisssis';
        /** @var list<mixed> $params */
        $params = [$companyId, $agentRunId, $tool, $hash, $status, $allowedInt, $denyReason ?? ''];
        $this->assertBindArity($stmt, $types, $params);
        // mysqli bind_param needs references.
        $bind = [(string) $types];
        foreach ($params as $k => $v) {
            $bind[] = &$params[$k];
        }
        $stmt->bind_param(...$bind);
        if (!$stmt->execute()) {
            throw new \RuntimeException('Failed to record tool_call: ' . $this->db->error);
        }
        $stmt->close();
    }

    /**
     * @param array<int, mixed> $params
     */
    private function assertBindArity(\mysqli_stmt $stmt, string $types, array $params): void
    {
        if (strlen($types) !== count($params)) {
            $stmt->close();
            throw new \LogicException(sprintf('bind_param arity mismatch: %d vs %d', strlen($types), count($params)));
        }
        $expected = ['i' => 'integer', 'd' => 'double', 's' => 'string', 'b' => 'string'];
        foreach ($params as $i => $p) {
            if ($p === null) {
                continue;
            }
            $want = $expected[$types[$i]] ?? null;
            if ($want !== null && gettype($p) !== $want) {
                $stmt->close();
                throw new \LogicException(sprintf('bind_param type mismatch at %d: "%s" expects %s, got %s', $i, $types[$i], $want, gettype($p)));
            }
        }
    }
}
