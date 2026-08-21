<?php
declare(strict_types=1);

namespace Routlaw\Leads;

use Routlaw\Security\AuditLog;

/**
 * SourceRecordService: immutable original-source preservation (FR-014/BR-004/BR-005).
 *
 * Every manual/email/loaded submission first creates a source_record that is the
 * durable reference for the normalized lead/load. The normalized record links back
 * to it (FR-008 raw submission reference, FR-014 source preservation).
 *
 * Tenant-scoped from day one (FR-042). No Python, no Redis, no Docker.
 */
final class SourceRecordService
{
    private \mysqli $db;

    public function __construct(\mysqli $db)
    {
        $this->db = $db;
    }

    /**
     * Persist an immutable source record (FR-014).
     *
     * @param int         $companyId    Tenant scope (FR-042).
     * @param string      $sourceType   e.g. 'web_form', 'email', 'manual_load', 'uploaded_doc'.
     * @param string|null $externalId   Stable external reference (e.g. Gmail message-id), or null.
     * @param string|null $contentHash  sha256 of the canonical payload (idempotency/dedup reference).
     * @param string|null $rawReference Durable reference to the original (e.g. storage path or raw text).
     * @param string|null $payload      The canonical/original payload (immutable snapshot).
     * @param string|null $contentType  MIME/content type.
     * @param string|null $receivedAt   Ingestion timestamp (UTC); null => DB NOW().
     * @return int Source record ID.
     */
    public function create(
        int $companyId,
        string $sourceType,
        ?string $externalId,
        ?string $contentHash,
        ?string $rawReference,
        ?string $payload,
        ?string $contentType,
        ?string $receivedAt
    ): int {
        if ($sourceType === '') {
            throw new \RuntimeException('source_type is required (FR-014).');
        }

        $stmt = $this->db->prepare(
            'INSERT INTO source_records '
            . '(company_id, source_type, external_id, content_hash, raw_reference, canonical_payload, content_type, received_at) '
            . 'VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
        );
        if ($stmt === false) {
            throw new \RuntimeException('Failed to prepare source_record insert: ' . $this->db->error);
        }
        $ext = $externalId ?? '';
        $hash = $contentHash ?? '';
        $ref = $rawReference ?? '';
        $pay = $payload ?? '';
        $ct = $contentType ?? '';
        $recv = $receivedAt ?? null;
        $stmt->bind_param('isssssss', $companyId, $sourceType, $ext, $hash, $ref, $pay, $ct, $recv);
        if (!$stmt->execute()) {
            $stmt->close();
            throw new \RuntimeException('Failed to create source_record: ' . $this->db->error);
        }
        $id = $this->lastInsertId();
        $stmt->close();

        $audit = new AuditLog($this->db);
        $audit->recordSystem('source_record.create', $companyId, 'system', 'create', [
            'source_record_id' => $id,
            'source_type' => $sourceType,
            'content_hash' => $hash,
        ], 'source_record', (string) $id);

        return $id;
    }

    /**
     * Read a source record tenant-scoped (FR-042). Returns null if not found or other tenant.
     *
     * @return array<string, mixed>|null
     */
    public function getForCompany(int $companyId, int $sourceRecordId): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT id, company_id, source_type, external_id, content_hash, raw_reference, canonical_payload, content_type, received_at '
            . 'FROM source_records WHERE id = ? AND company_id = ?'
        );
        if ($stmt === false) {
            throw new \RuntimeException('Failed to prepare source_record read: ' . $this->db->error);
        }
        $stmt->bind_param('ii', $sourceRecordId, $companyId);
        $stmt->execute();
        $result = $stmt->get_result();
        $row = $result !== false ? $result->fetch_assoc() : null;
        $stmt->close();
        return $row !== null && $row !== false ? $row : null;
    }

    private function lastInsertId(): int
    {
        $id = $this->db->insert_id;
        return $id > 0 ? (int) $id : 0;
    }
}
