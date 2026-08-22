<?php
declare(strict_types=1);

namespace Routlaw\Messages;

use Routlaw\Security\AuditLog;
use Routlaw\Security\UserSession;

/**
 * T14.2 — MessageDraftService (FR-021).
 *
 * Editable, source-linked message drafts. Tenant-scoped from day one (FR-042).
 *
 * NON-NEGOTIABLE (FR-021 "cannot self-send"):
 *   This class MUST NOT import, reference, or invoke Routlaw\Mail\Mailer (or any
 *   delivery path). The draft store has no send capability by construction — only a
 *   separate OutboundService::approveAndSend() (T14.4) can flip a draft to `sent`.
 *   See MessageDraftServiceTest::test_no_mailer_coupling().
 *
 * No Python, no Redis, no Docker — pure PHP 8.3 on MariaDB.
 */
final class MessageDraftService
{
    private const SOURCE_TABLES = [
        'decision' => 'decision_cases',
        'load'     => 'loads',
        'lead'     => 'leads',
    ];

    private \mysqli $db;

    public function __construct(\mysqli $db)
    {
        $this->db = $db;
    }

    /**
     * Create a source-linked draft.
     * @param UserSession $actor     Acting user (tenant + identity).
     * @param string      $sourceType 'decision'|'load'|'lead'
     * @param int         $sourceId   In-tenant ID of the source entity.
     * @param string      $subject
     * @param string      $body
     * @return int Draft ID.
     * @throws \RuntimeException if source_type unknown or source not in tenant; bind error.
     */
    public function create(UserSession $actor, string $sourceType, int $sourceId, string $subject, string $body): int
    {
        if (!isset(self::SOURCE_TABLES[$sourceType])) {
            throw new \RuntimeException('Unknown source_type: ' . $sourceType);
        }
        $table = self::SOURCE_TABLES[$sourceType];
        $ok = $this->assertSourceInTenant($table, $sourceId, $actor->companyId);
        if (!$ok) {
            throw new \RuntimeException('Source not found in tenant (FR-021 source-link).');
        }

        $stmt = $this->db->prepare(
            'INSERT INTO message_drafts (company_id, source_type, source_id, subject, body, status, created_by) '
            . 'VALUES (?, ?, ?, ?, ?, ?, ?)'
        );
        if ($stmt === false) {
            throw new \RuntimeException('Failed to prepare draft insert: ' . $this->db->error);
        }
        $status = 'draft';
        $params = [$actor->companyId, $sourceType, $sourceId, $subject, $body, $status, $actor->userId];
        $types = 'isisssi';
        $this->assertBindArity($stmt, $types, $params);
        $stmt->bind_param($types, ...$params);
        $ok = $stmt->execute();
        $id = $this->lastInsertId();
        $stmt->close();
        if (!$ok) {
            throw new \RuntimeException('Failed to create message draft: ' . $this->db->error);
        }

        $audit = new AuditLog($this->db);
        $audit->record($actor, 'message_draft.create', 'message_draft', (string) $id, 'success', [
            'source_type' => $sourceType, 'source_id' => $sourceId,
        ]);
        return $id;
    }

    /**
     * Edit a draft's subject/body (editable — FR-021). Tenant-scoped update.
     * @return bool False if the draft is not in the tenant or not editable (sent/rejected/deleted).
     */
    public function update(int $draftId, int $companyId, UserSession $actor, string $subject, string $body): bool
    {
        $stmt = $this->db->prepare(
            'UPDATE message_drafts SET subject = ?, body = ?, updated_by = ? '
            . 'WHERE id = ? AND company_id = ? AND status = "draft" AND deleted_at IS NULL'
        );
        if ($stmt === false) {
            throw new \RuntimeException('Failed to prepare draft update: ' . $this->db->error);
        }
        $types = 'ssiii';
        $params = [$subject, $body, $actor->userId, $draftId, $companyId];
        $this->assertBindArity($stmt, $types, $params);
        $stmt->bind_param($types, ...$params);
        $ok = $stmt->execute();
        $affected = $stmt->affected_rows;
        $stmt->close();
        if (!$ok) {
            throw new \RuntimeException('Failed to update draft: ' . $this->db->error);
        }
        if ($affected > 0) {
            $audit = new AuditLog($this->db);
            $audit->record($actor, 'message_draft.update', 'message_draft', (string) $draftId, 'success');
        }
        return $affected > 0;
    }

    /**
     * @return array{ id:int, company_id:int, source_type:string, source_id:int, subject:string, body:string, status:string, created_at:string, updated_at:string|null }|null
     */
    public function get(int $draftId, int $companyId): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT id, company_id, source_type, source_id, subject, body, status, created_at, updated_at '
            . 'FROM message_drafts WHERE id = ? AND company_id = ? AND deleted_at IS NULL'
        );
        if ($stmt === false) {
            throw new \RuntimeException('Failed to prepare draft read: ' . $this->db->error);
        }
        $stmt->bind_param('ii', $draftId, $companyId);
        $stmt->execute();
        $result = $stmt->get_result();
        $row = $result !== false ? $result->fetch_assoc() : false;
        $stmt->close();
        if ($row === null || $row === false) {
            return null;
        }
        // Build an explicitly-typed shape so phpstan L8 can verify the return type.
        return [
            'id'          => (int) $row['id'],
            'company_id'  => (int) $row['company_id'],
            'source_type' => (string) ($row['source_type'] ?? ''),
            'source_id'   => (int) $row['source_id'],
            'subject'     => (string) ($row['subject'] ?? ''),
            'body'        => (string) ($row['body'] ?? ''),
            'status'      => (string) ($row['status'] ?? ''),
            'created_at'  => (string) ($row['created_at'] ?? ''),
            'updated_at'  => isset($row['updated_at']) && $row['updated_at'] !== null ? (string) $row['updated_at'] : null,
        ];
    }

    /** @return list<array<string,mixed>> */
    public function listForTenant(int $companyId, ?string $status = null): array
    {
        $sql = 'SELECT id, source_type, source_id, subject, status, created_at FROM message_drafts '
            . 'WHERE company_id = ? AND deleted_at IS NULL';
        $params = [$companyId];
        $types = 'i';
        if ($status !== null) {
            $sql .= ' AND status = ?';
            $params[] = $status;
            $types .= 's';
        }
        $sql .= ' ORDER BY id DESC';
        $stmt = $this->db->prepare($sql);
        if ($stmt === false) {
            throw new \RuntimeException('Failed to prepare draft list: ' . $this->db->error);
        }
        $this->assertBindArity($stmt, $types, $params);
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $result = $stmt->get_result();
        $rows = [];
        if ($result !== false) {
            while ($row = $result->fetch_assoc()) {
                $row['id'] = (int) $row['id'];
                $row['source_id'] = (int) $row['source_id'];
                $rows[] = $row;
            }
        }
        $stmt->close();
        return $rows;
    }

    /**
     * Soft-delete a draft (regulated entity — BR-020). Tenant-scoped.
     * @return bool True if a row was soft-deleted.
     */
    public function softDelete(int $draftId, int $companyId, UserSession $actor, string $reason): bool
    {
        $stmt = $this->db->prepare(
            'UPDATE message_drafts SET deleted_at = NOW(), deleted_by = ?, delete_reason = ? '
            . 'WHERE id = ? AND company_id = ? AND deleted_at IS NULL'
        );
        if ($stmt === false) {
            throw new \RuntimeException('Failed to prepare draft soft-delete: ' . $this->db->error);
        }
        $types = 'isii';
        $params = [$actor->userId, $reason, $draftId, $companyId];
        $this->assertBindArity($stmt, $types, $params);
        $stmt->bind_param($types, ...$params);
        $ok = $stmt->execute();
        $affected = $stmt->affected_rows;
        $stmt->close();
        if (!$ok) {
            throw new \RuntimeException('Failed to soft-delete draft: ' . $this->db->error);
        }
        if ($affected > 0) {
            $audit = new AuditLog($this->db);
            $audit->record($actor, 'message_draft.delete', 'message_draft', (string) $draftId, 'success', ['reason' => $reason]);
        }
        return $affected > 0;
    }

    /** Confirm the source entity exists in the given tenant (FR-021 source-link). */
    private function assertSourceInTenant(string $table, int $sourceId, int $companyId): bool
    {
        $stmt = $this->db->prepare("SELECT 1 FROM {$table} WHERE id = ? AND company_id = ? LIMIT 1");
        if ($stmt === false) {
            throw new \RuntimeException('Failed to prepare source check: ' . $this->db->error);
        }
        $stmt->bind_param('ii', $sourceId, $companyId);
        $stmt->execute();
        $result = $stmt->get_result();
        $col = $result !== false ? $result->fetch_column() : false;
        $stmt->close();
        return ($col !== false && $col !== null);
    }

    /**
     * bind_param arity + type-char guard (routlaw skill: a count-only check is unsafe).
     *
     * @param \mysqli_stmt     $stmt
     * @param string           $types
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
            if ($p === null) {
                continue;
            }
            $want = $expected[$types[$i]] ?? null;
            if ($want !== null && gettype($p) !== $want) {
                $stmt->close();
                throw new \LogicException(sprintf('bind_param type mismatch at param %d: "%s" expects %s, got %s', $i, $types[$i], $want, gettype($p)));
            }
        }
    }

    private function lastInsertId(): int
    {
        $id = $this->db->insert_id;
        return $id > 0 ? (int) $id : 0;
    }
}
