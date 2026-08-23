<?php
declare(strict_types=1);

namespace Routlaw\Agent;

/**
 * SelfPromotionGuard — T19 / FR-041 (proposals cannot self-promote).
 *
 * The proposal status lifecycle:
 *   draft → evaluated → approved → staged → rolled_back
 *                            ↘ rejected
 *
 * HARD GATE (FRD §2.2 "policy outside the model"):
 * - createDraft() is the ONLY method the model/LLM path can reach. It always
 *   inserts status='draft' with approved_by=NULL. The model cannot set any other status.
 * - advance() transitions out of 'draft' ONLY with a non-null human approver id.
 * - rollback() is an explicit human action (requires approver id); it records a history
 *   row and bumps version (no destructive overwrite).
 *
 * All transitions write proposal_status_history (immutable audit).
 */
final class SelfPromotionGuard
{
    private const STATUS = ['draft', 'evaluated', 'approved', 'staged', 'rolled_back', 'rejected'];

    public function __construct(private \mysqli $m) {}

    /**
     * Model/LLM-reachable: create a proposal in 'draft' only. Cannot self-promote.
     *
     * @param array<string,mixed> $payload The proposal content (hashed for identity).
     * @param int $companyId tenant scope.
     * @param int $runId agent_run_id provenance (NULL allowed).
     * @param int $systemCreatedBy system/agent principal that originated the draft.
     * @return int proposal id
     */
    public function createDraft(array $payload, int $companyId, ?int $runId, int $systemCreatedBy): int
    {
        $hash = hash('sha256', json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '');
        $json = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        $stmt = $this->m->prepare(
            'INSERT INTO learning_proposals (company_id, run_id, status, payload_hash, payload_json, version, created_by, approved_by) '
            . 'VALUES (?, ?, "draft", ?, ?, 1, ?, NULL)'
        );
        if ($stmt === false) {
            throw new \RuntimeException('Failed to prepare draft: ' . $this->m->error);
        }
        $types = 'iissi';
        /** @var list<mixed> $params */
        $params = [$companyId, $runId, $hash, $json, $systemCreatedBy];
        $this->assertBindArity($stmt, $types, $params);
        $stmt->bind_param($types, ...$params);
        if (!$stmt->execute()) {
            throw new \RuntimeException('Failed to insert draft: ' . $this->m->error);
        }
        $id = (int) $stmt->insert_id;
        $stmt->close();

        $this->recordHistory($id, null, 'draft', $systemCreatedBy, 'created by model/LLM path');
        return $id;
    }

    /**
     * Advance a proposal to a new status. ONLY a human approver (non-null $approverId)
     * may transition out of 'draft'. Returns false if the guard refuses.
     *
     * @param int $proposalId
     * @param string $toStatus Must be a valid lifecycle status (not 'draft').
     * @param int|null $approverId A real human actor id. NULL => refused (model path only).
     * @param string $reason
     * @return bool
     */
    public function advance(int $proposalId, string $toStatus, ?int $approverId, string $reason): bool
    {
        if (!in_array($toStatus, self::STATUS, true)) {
            return false;
        }
        // The core FR-041 gate: no transition out of draft without a human approver.
        if ($toStatus !== 'draft' && $approverId === null) {
            return false;
        }

        $res = $this->m->query("SELECT status, approved_by FROM learning_proposals WHERE id={$proposalId}");
        $cur = ($res instanceof \mysqli_result) ? $res->fetch_assoc() : false;
        if ($cur === false) {
            return false; // unknown proposal or query error
        }
        $oldStatus = (string) ($cur['status'] ?? '');
        if ($oldStatus === 'rolled_back' || $oldStatus === 'rejected') {
            return false; // terminal; cannot be reopened
        }
        $stmt = $this->m->prepare(
            'UPDATE learning_proposals SET status = ?, approved_by = ?, version = version + 1, updated_at = NOW() WHERE id = ?'
        );
        if ($stmt === false) {
            throw new \RuntimeException('Failed to prepare advance: ' . $this->m->error);
        }
        $types = 'sii';
        /** @var list<mixed> $params */
        $params = [$toStatus, $approverId, $proposalId];
        $this->assertBindArity($stmt, $types, $params);
        $stmt->bind_param($types, ...$params);
        $ok = $stmt->execute();
        $stmt->close();
        if (!$ok) {
            return false;
        }

        $this->recordHistory($proposalId, $oldStatus, $toStatus, $approverId ?? 0, $reason);
        return true;
    }

    /**
     * Human-only rollback to a prior version. Records history; never deletes the row.
     */
    public function rollback(int $proposalId, int $approverId, string $reason): bool
    {
        $res = $this->m->query("SELECT status FROM learning_proposals WHERE id={$proposalId}");
        $cur = ($res instanceof \mysqli_result) ? $res->fetch_assoc() : false;
        if ($cur === false) {
            return false;
        }
        $old = (string) ($cur['status'] ?? '');

        $stmt = $this->m->prepare(
            'UPDATE learning_proposals SET status = "rolled_back", approved_by = ?, version = version + 1 WHERE id = ?'
        );
        if ($stmt === false) {
            throw new \RuntimeException('Failed to prepare rollback: ' . $this->m->error);
        }
        $types = 'ii';
        /** @var list<mixed> $params */
        $params = [$approverId, $proposalId];
        $this->assertBindArity($stmt, $types, $params);
        $stmt->bind_param($types, ...$params);
        $ok = $stmt->execute();
        $stmt->close();
        if (!$ok) {
            return false;
        }

        $this->recordHistory($proposalId, $old, 'rolled_back', $approverId, $reason);
        return true;
    }

    private function recordHistory(int $proposalId, ?string $from, string $to, int $actorId, string $reason): void
    {
        $stmt = $this->m->prepare(
            'INSERT INTO proposal_status_history (proposal_id, from_status, to_status, actor_id, reason) VALUES (?, ?, ?, ?, ?)'
        );
        if ($stmt === false) {
            throw new \RuntimeException('Failed to prepare history: ' . $this->m->error);
        }
        $types = 'issis';
        $params = [$proposalId, $from, $to, $actorId, $reason];
        $this->assertBindArity($stmt, $types, $params);
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $stmt->close();
    }

    /**
     * bind_param arity + type guard (routlaw skill: count-only is not enough).
     */
    /**
     * @param list<mixed> $params
     */
    private function assertBindArity(\mysqli_stmt $stmt, string $types, array $params): void
    {
        if (strlen($types) !== count($params)) {
            $stmt->close();
            throw new \LogicException(sprintf('bind_param arity mismatch: %d types vs %d params', strlen($types), count($params)));
        }
        $expected = ['i' => 'integer', 's' => 'string', 'd' => 'double', 'b' => 'string'];
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
