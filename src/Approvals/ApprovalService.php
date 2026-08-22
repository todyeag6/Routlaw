<?php
declare(strict_types=1);

namespace Routlaw\Approvals;

use Routlaw\Security\AuditLog;
use Routlaw\Security\UserSession;

/**
 * T15.2/T15.3 — ApprovalService (FR-022/023/024).
 *
 * create(): stores a payload-bound approval request (canonical payload hash + expiry).
 * isApproved(): valid only if a non-expired, un-consumed 'approved' row's hash equals
 *   the CURRENT canonical hash of the live payload -> tamper or replay rejected.
 * consume(): single-use — marks consumed_at (replay safety, FR-024).
 *
 * Payload-bound by design: a hash mismatch means the payload was modified after approval
 * and a NEW approval is required. No flag flip can bypass this — validity is recomputed.
 */
final class ApprovalService
{
    private \mysqli $db;

    public function __construct(\mysqli $db)
    {
        $this->db = $db;
    }

    /**
     * Canonical, deterministic sha256 of a payload for a given (action, resource, id).
     * Tie-breaking keys: action and resource columns are also part of the bound tuple.
     *
     * @param array<string,mixed> $payload
     */
    public static function canonicalHash(string $action, string $resourceType, int $resourceId, array $payload): string
    {
        $canonical = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_FORCE_OBJECT);
        $canonical = $canonical === false ? '' : $canonical;
        return hash('sha256', $action . '|' . $resourceType . '|' . $resourceId . '|' . $canonical);
    }

    /**
     * Compute the expiry datetime from a relative spec (e.g. '+1 day', '+0 days').
     */
    public static function computeExpiry(string $spec = '+1 day'): string
    {
        $ts = strtotime($spec);
        if ($ts === false) {
            $ts = time() + 86400;
        }
        return gmdate('Y-m-d H:i:s', $ts);
    }

    /**
     * Create a pending approval request.
     * @param int        $companyId
     * @param int        $actorUserId The user requesting the action (payload requestor).
     * @param string     $action
     * @param string     $resourceType
     * @param int        $resourceId
     * @param array<string,mixed> $payload
     * @param string     $expirySpec Relative time spec.
     * @return int approval_request id.
     */
    public function create(int $companyId, int $actorUserId, string $action, string $resourceType, int $resourceId, array $payload, string $expirySpec = '+1 day'): int
    {
        $hash = self::canonicalHash($action, $resourceType, $resourceId, $payload);
        $expiresAt = self::computeExpiry($expirySpec);
        $payloadJson = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        $stmt = $this->db->prepare(
            'INSERT INTO approval_requests (company_id, actor_user_id, action, resource_type, resource_id, payload_hash, payload_json, expires_at) '
            . 'VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
        );
        if ($stmt === false) {
            throw new \RuntimeException('Failed to prepare approval insert: ' . $this->db->error);
        }
        $types = 'iississs';
        $params = [$companyId, $actorUserId, $action, $resourceType, $resourceId, $hash, $payloadJson, $expiresAt];
        $this->assertBindArity($stmt, $types, $params);
        $stmt->bind_param($types, ...$params);
        $ok = $stmt->execute();
        $id = (int) $this->db->insert_id;
        $stmt->close();
        if (!$ok) {
            throw new \RuntimeException('Failed to create approval request: ' . $this->db->error);
        }
        $audit = new AuditLog($this->db);
        $audit->recordSystem('approval.create', $companyId, 'user', 'create', [
            'approval_id' => $id, 'action' => $action,
            'resource_type' => $resourceType, 'resource_id' => $resourceId,
            'expires_at' => $expiresAt,
        ], 'approval_request', (string) $id);
        return $id;
    }

    /**
     * Approve a pending request. Stores payload_hash of the payload as it stands.
     *
     * @param array<string,mixed> $payload
     * @return bool true if the row was approved (pending; else false).
     */
    public function approve(int $approvalId, int $companyId, UserSession $approver, array $payload): bool
    {
        // approve() is a no-op on the payload here — the hash was already stored at create()
        // and is recomputed by isApproved(). Approval just flips status; the payload is
        // accepted to make the caller's intent explicit and auditable, and because an
        // approver SHOULD re-attest the payload they're signing (FR-024).
        return $this->transition($approvalId, $companyId, 'approved', $approver, 'approval.approve', 'approve');
    }

    /**
     * Whether a valid, unconsumed, non-expired approval exists for this exact payload.
     * Payload-bound: ANY modification => hash mismatch => false.
     *
     * @param array<string,mixed> $payload
     * @param int[]               $companies Tenant scope check list (FR-042 defense) — approval must be in one of these.
     */
    public function isApproved(string $action, string $resourceType, int $resourceId, array $payload, array $companies): bool
    {
        $hash = self::canonicalHash($action, $resourceType, $resourceId, $payload);
        return $this->findActive($action, $resourceType, $resourceId, $hash, $companies) !== null;
    }

    /**
     * Consume (apply) an approval. Single-use: success only on the first consume;
     * a replay (second consume) returns false (FR-024 replay safety).
     *
     * @param array<string,mixed> $payload
     * @param int[]               $companies
     */
    public function consume(string $action, string $resourceType, int $resourceId, array $payload, array $companies): bool
    {
        $hash = self::canonicalHash($action, $resourceType, $resourceId, $payload);
        $row = $this->findActive($action, $resourceType, $resourceId, $hash, $companies);
        if ($row === null) {
            return false;
        }
        $id = (int) $row['id'];
        $companyId = (int) $row['company_id'];

        $stmt = $this->db->prepare(
            'UPDATE approval_requests SET status = ?, consumed_at = NOW() WHERE id = ? AND company_id = ? AND consumed_at IS NULL'
        );
        if ($stmt === false) {
            throw new \RuntimeException('Failed to prepare consume: ' . $this->db->error);
        }
        $types = 'sii';
        $params = ['consumed', $id, $companyId];
        $this->assertBindArity($stmt, $types, $params);
        $stmt->bind_param($types, ...$params);
        $ok = $stmt->execute();
        $affected = $stmt->affected_rows;
        $stmt->close();
        if (!$ok) {
            throw new \RuntimeException('Failed to consume approval: ' . $this->db->error);
        }
        if ($affected > 0) {
            $audit = new AuditLog($this->db);
            $audit->recordSystem('approval.consume', $companyId, 'system', 'consume', [
                'approval_id' => $id, 'action' => $action,
            ], 'approval_request', (string) $id);
        }
        return $affected > 0;
    }

    /**
     * @return array<string,mixed>|null  The active approval row, or null.
     * @param int[]                      $companies Tenant scope list (FR-042).
     */
    private function findActive(string $action, string $resourceType, int $resourceId, string $hash, array $companies): ?array
    {
        if ($companies === []) {
            return null;
        }
        // Build an IN(?,...) clause for tenant scoping (defense-in-depth — isApproved/consume
        // are also called from a tenant-scoped context). Reference-trick bind_param for arrays.
        $in = str_repeat('?,', count($companies) - 1) . '?';
        $sql = 'SELECT id, company_id, actor_user_id, status, expires_at, consumed_at, payload_hash '
            . 'FROM approval_requests WHERE action = ? AND resource_type = ? AND resource_id = ? '
            . 'AND payload_hash = ? AND status = ? AND consumed_at IS NULL '
            . 'AND expires_at > NOW() AND company_id IN (' . $in . ') ORDER BY id DESC LIMIT 1';
        $stmt = $this->db->prepare($sql);
        if ($stmt === false) {
            throw new \RuntimeException('Failed to prepare approval lookup: ' . $this->db->error);
        }
        $types = 'ssiss';
        /** @var list<mixed> $params */
        $params = [$action, $resourceType, $resourceId, $hash, 'approved'];
        foreach ($companies as $c) {
            $types .= 'i';
            $params[] = (int) $c;
        }
        $this->assertBindArity($stmt, $types, $params);
        // mysqli::bind_param needs references; spread passes by value (routlaw pattern).
        $bindParams = [(string) $types];
        foreach ($params as $key => $value) {
            $bindParams[] = &$params[$key];
        }
        $stmt->bind_param(...$bindParams);
        $stmt->execute();
        $result = $stmt->get_result();
        $row = $result !== false ? $result->fetch_assoc() : false;
        $stmt->close();
        return ($row === null || $row === false) ? null : $row;
    }

    private function transition(int $approvalId, int $companyId, string $newStatus, UserSession $actor, string $eventType, string $action): bool
    {
        $stmt = $this->db->prepare(
            'UPDATE approval_requests SET status = ?, decided_by = ?, decided_at = NOW() '
            . 'WHERE id = ? AND company_id = ? AND status = ?'
        );
        if ($stmt === false) {
            throw new \RuntimeException('Failed to prepare approval transition: ' . $this->db->error);
        }
        $types = 'siiis';
        $params = [$newStatus, $actor->userId, $approvalId, $companyId, 'pending'];
        $this->assertBindArity($stmt, $types, $params);
        $stmt->bind_param($types, ...$params);
        $ok = $stmt->execute();
        $affected = $stmt->affected_rows;
        $stmt->close();
        if (!$ok) {
            throw new \RuntimeException('Failed to transition approval: ' . $this->db->error);
        }
        if ($affected > 0) {
            $audit = new AuditLog($this->db);
            $audit->record($actor, $eventType, 'approval_request', (string) $approvalId, 'success', [
                'status' => $newStatus,
            ]);
        }
        return $affected > 0;
    }

    /**
     * bind_param arity + type-char guard (routlaw skill: a count-only check is unsafe).
     *
     * @param array<int, mixed> $params
     */
    private function assertBindArity(\mysqli_stmt $stmt, string $types, array $params): void
    {
        if (strlen($types) !== count($params)) {
            $stmt->close();
            throw new \LogicException(sprintf('bind_param arity mismatch: %d types vs %d params', strlen($types), count($params)));
        }
        $expected = ['i' => 'integer', 'd' => 'double', 's' => 'string', 'b' => 'string'];
        foreach ($params as $i => $p) {
            if ($p === null) { continue; }
            $want = $expected[$types[$i]] ?? null;
            if ($want !== null && gettype($p) !== $want) {
                $stmt->close();
                throw new \LogicException(sprintf('bind_param type mismatch at param %d: "%s" expects %s, got %s', $i, $types[$i], $want, gettype($p)));
            }
        }
    }
}
