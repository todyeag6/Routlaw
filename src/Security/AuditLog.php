<?php
declare(strict_types=1);

namespace Routlaw\Security;

/**
 * AuditLog: T3 append-only audit + agent-trace writer (FR-029/FR-030/MOD-15).
 *
 * - Audit events: authentication, authorization, privileged changes, approvals,
 *   AI runs, tool calls, material data changes (FR-029, BR-017).
 * - Agent run records with model/provider/ workflow/policy version metadata (FR-030).
 * - Tool call trace under each agent run (FR-030).
 * - Audit events are append-only at the DB layer (triggers deny UPDATE/DELETE).
 *
 * No Python, no Redis, no Docker — pure PHP 8.3 on MariaDB.
 */
final class AuditLog
{
    private \mysqli $db;

    public function __construct(\mysqli $db)
    {
        $this->db = $db;
    }

    /**
     * Record an audit event with a UserSession actor (FR-029).
     *
     * @param UserSession  $actor     The authenticated actor.
     * @param string       $eventType Event type (e.g. 'auth.login', 'data.update').
     * @param string       $targetType The target entity type.
     * @param string       $targetId   The target entity ID.
     * @param string       $result     'success' or 'failure'.
     * @param array|null   $detail     Optional structured detail JSON.
     */
    public function record(UserSession $actor, string $eventType, string $targetType, string $targetId, string $result, ?array $detail = null): void
    {
        $this->insert(
            $actor->companyId,
            'user',
            $actor->userId,
            $eventType,
            $eventType,
            $targetType,
            $targetId,
            $result,
            $detail
        );
    }

    /**
     * Record a system-level audit event (FR-029).
     *
     * @param string     $eventType
     * @param int|null   $companyId  Tenant scope (null = global/system).
     * @param string     $actorType  'system' or 'agent'.
     * @param string     $action     The action performed.
     * @param array|null $detail
     */
    public function recordSystem(string $eventType, ?int $companyId, string $actorType, string $action, ?array $detail = null): void
    {
        $this->insert(
            $companyId ?? 0,
            $actorType,
            0,
            $eventType,
            $action,
            null,
            null,
            // System events are always logged as 'success' (no failed system actions).
            'success',
            $detail
        );
    }

    /**
     * Record an agent run (FR-030).
     * Returns the agent_run_id.
     */
    public function recordAgentRun(
        int $companyId,
        string $workflowType,
        string $agentVersion,
        string $policyVersion,
        string $status = 'queued',
        ?string $correlationId = null
    ): int {
        $stmt = $this->db->prepare(
            'INSERT INTO agent_runs (company_id, correlation_id, workflow_type, agent_version, policy_version, status) '
            . 'VALUES (?, ?, ?, ?, ?, ?)'
        );
        $corr = $correlationId ?? '';
        $stmt->bind_param('ssssss', $companyId, $corr, $workflowType, $agentVersion, $policyVersion, $status);
        if (!$stmt->execute()) {
            throw new \RuntimeException('Failed to record agent run: ' . $this->db->error);
        }
        $id = $this->lastInsertId();
        $stmt->close();
        return $id;
    }

    /**
     * Record a tool call under an agent run (FR-030).
     * Returns the tool_call_id.
     */
    public function recordToolCall(
        int $companyId,
        int $agentRunId,
        string $toolName,
        string $resultStatus,
        ?string $requestHash = null
    ): int {
        $stmt = $this->db->prepare(
            'INSERT INTO tool_calls (company_id, agent_run_id, tool_name, request_hash, result_status) '
            . 'VALUES (?, ?, ?, ?, ?)'
        );
        $hash = $requestHash ?? '';
        $stmt->bind_param('iisss', $companyId, $agentRunId, $toolName, $hash, $resultStatus);
        if (!$stmt->execute()) {
            throw new \RuntimeException('Failed to record tool call: ' . $this->db->error);
        }
        $id = $this->lastInsertId();
        $stmt->close();
        return $id;
    }

    /**
     * Core INSERT into audit_events. Shared by all record* methods.
     */
    private function insert(
        int $companyId,
        string $actorType,
        int $actorId,
        string $eventType,
        string $action,
        ?string $targetType,
        ?string $targetId,
        string $result,
        ?array $detail
    ): void {
        $stmt = $this->db->prepare(
            'INSERT INTO audit_events '
            . '(company_id, actor_type, actor_id, event_type, action, target_type, target_id, result, detail) '
            . 'VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $detailJson = $detail !== null ? json_encode($detail, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) : null;
        $targetTypeVar = $targetType ?? '';
        $targetIdVar = $targetId ?? '';

        $stmt->bind_param(
            'issssssss',
            $companyId,
            $actorType,
            $actorId,
            $eventType,
            $action,
            $targetTypeVar,
            $targetIdVar,
            $result,
            $detailJson
        );
        if (!$stmt->execute()) {
            throw new \RuntimeException('Failed to write audit event: ' . $this->db->error);
        }
        $stmt->close();
    }

    /**
     * Read audit events for authorized display (tenant-scoped, FR-029).
     *
     * @param int    $companyId Tenant scope.
     * @param int    $limit     Max rows.
     * @return list<array<string,mixed>>
     */
    public function readForTenant(int $companyId, int $limit = 100): array
    {
        $rows = [];
        $stmt = $this->db->prepare(
            'SELECT id, actor_type, actor_id, event_type, action, target_type, target_id, result, detail, created_at '
            . 'FROM audit_events WHERE company_id = ? ORDER BY id DESC LIMIT ?'
        );
        $stmt->bind_param('ii', $companyId, $limit);
        $stmt->execute();
        $result = $stmt->get_result();
        while ($row = $result->fetch_assoc()) {
            $rows[] = $row;
        }
        $stmt->close();
        return $rows;
    }

    /**
     * Get the last insert ID as an integer (phpstan L8 friendly).
     */
    private function lastInsertId(): int
    {
        $id = $this->db->insert_id;
        return $id > 0 ? (int) $id : 0;
    }
}
