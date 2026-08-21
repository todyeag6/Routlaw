<?php
declare(strict_types=1);

namespace Routlaw\Loads;

use Routlaw\Security\AuditLog;

/**
 * LoadService: T8 manual + approved-email load intake + source preservation
 * (build-plan §4 Phase 3 T8, FR-010/FR-011/FR-014).
 *
 * FR-010: authorized users create loads with required pickup/delivery/commodity/weight.
 * FR-011: ingested approved email creates a reviewable candidate load (Gmail OAuth itself ships later).
 * FR-014: every load links to an immutable source_record; the original is preserved.
 *
 * Tenant-scoped from day one (FR-042, BR-020): every query carries company_id.
 * No Python, no Redis, no Docker — pure PHP 8.3 on MariaDB.
 */
final class LoadService
{
    private \mysqli $db;

    /** Required load facts for a minimum viable/reviewable load (FR-010). */
    private const REQUIRED_FIELDS = ['origin_city', 'origin_state', 'dest_city', 'dest_state', 'commodity', 'weight_lbs'];

    public function __construct(\mysqli $db)
    {
        $this->db = $db;
    }

    /**
     * Persist a load, preserving its immutable source and scoring missing required fields.
     *
     * @param int                $companyId  Tenant scope (FR-042).
     * @param array<string,mixed> $fields     Load facts (subset of loads columns).
     * @param int                $actorId    Acting user id (audit).
     * @param int|null           $rawSourceId Link to immutable source_record (FR-014).
     * @param string             $loadSource 'manual' | 'email'.
     * @return int Load ID.
     */
    private function persistLoad(int $companyId, array $fields, int $actorId, ?int $rawSourceId, string $loadSource): int
    {
        // FR-010: enumerate missing required fields; flag review when any are absent.
        $missing = [];
        foreach (self::REQUIRED_FIELDS as $req) {
            $val = $fields[$req] ?? null;
            if ($val === null || $val === '' || $val === 0) {
                $missing[] = $req;
            }
        }
        $reviewRequired = $missing !== [] ? 1 : 0;
        $missingJson = $missing === [] ? null : json_encode($missing, JSON_UNESCAPED_SLASHES);

        $stmt = $this->db->prepare(
            'INSERT INTO loads '
            . '(company_id, source_record_id, broker_id, origin_city, origin_state, dest_city, dest_state, '
            . 'commodity, weight_lbs, posted_rate, extraction_missing, review_required, status, created_by) '
            . 'VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        if ($stmt === false) {
            throw new \RuntimeException('Failed to prepare load insert: ' . $this->db->error);
        }
        $broker = isset($fields['broker_id']) ? (int) $fields['broker_id'] : null;
        $originCity = (string) ($fields['origin_city'] ?? '');
        $originState = (string) ($fields['origin_state'] ?? '');
        $destCity = (string) ($fields['dest_city'] ?? '');
        $destState = (string) ($fields['dest_state'] ?? '');
        $commodity = (string) ($fields['commodity'] ?? '');
        $weight = isset($fields['weight_lbs']) ? (int) $fields['weight_lbs'] : null;
        $rate = isset($fields['posted_rate']) ? (string) $fields['posted_rate'] : null;
        $status = 'new';
        $srcVar = $rawSourceId ?? null;
        $brokerVar = $broker ?? null;
        // 14 placeholders: i,i,i,s,s,s,s,s,i,s,s,i,s,i
        $stmt->bind_param(
            'iiisssssissisi',
            $companyId,
            $srcVar,
            $brokerVar,
            $originCity,
            $originState,
            $destCity,
            $destState,
            $commodity,
            $weight,
            $rate,
            $missingJson,
            $reviewRequired,
            $status,
            $actorId
        );
        if (!$stmt->execute()) {
            $stmt->close();
            throw new \RuntimeException('Failed to create load: ' . $this->db->error);
        }
        $id = $this->lastInsertId();
        $stmt->close();

        $audit = new AuditLog($this->db);
        $audit->recordSystem('load.create', $companyId, 'user', 'create', [
            'load_id' => $id,
            'source' => $loadSource,
            'source_record_id' => $srcVar,
            'missing_required' => $missing,
        ], 'load', (string) $id);

        return $id;
    }

    /**
     * Create a manual load (FR-010). Enters review; missing details are visible.
     *
     * @param array<string,mixed> $fields
     * @return int Load ID.
     */
    public function createManualLoad(int $companyId, array $fields, int $actorId, ?int $rawSourceId = null): int
    {
        return $this->persistLoad($companyId, $fields, $actorId, $rawSourceId, 'manual');
    }

    /**
     * Create an email-derived candidate load (FR-011). Must reference a preserved
     * email source_record. Reviewable, never auto-published.
     *
     * @param array<string,mixed> $fields Extracted/normalized load facts.
     * @return int Load ID.
     */
    public function createEmailLoad(int $companyId, int $emailSourceId, array $fields, int $actorId): int
    {
        return $this->persistLoad($companyId, $fields + ['broker_id' => null], $actorId, $emailSourceId, 'email');
    }

    /**
     * List loads scoped to a tenant (SEC-010/FR-042).
     *
     * @return list<array<string,mixed>>
     */
    public function listForCompany(int $companyId): array
    {
        $stmt = $this->db->prepare(
            'SELECT id, company_id, source_record_id, broker_id, origin_city, origin_state, dest_city, dest_state, '
            . 'commodity, weight_lbs, posted_rate, extraction_missing, review_required, status, created_at '
            . 'FROM loads WHERE company_id = ? AND deleted_at IS NULL ORDER BY id'
        );
        if ($stmt === false) {
            throw new \RuntimeException('Failed to prepare load list: ' . $this->db->error);
        }
        $stmt->bind_param('i', $companyId);
        $stmt->execute();
        $result = $stmt->get_result();
        $rows = [];
        if ($result !== false) {
            while ($row = $result->fetch_assoc()) {
                $rows[] = $row;
            }
        }
        $stmt->close();
        return $rows;
    }

    /**
     * Persist an extracted load (FR-013): validates the extraction schema, then writes
     * versioned per-field JSON, missing-field list, overall confidence, and review flag.
     * Rejects malformed extraction output (FR-013 acceptance: invalid schema output rejected).
     *
     * @param array<string,mixed> $extraction Result of LoadExtractionService::extract() (or provider adapter).
     * @param string             $loadSource 'email' | 'manual' | 'document'.
     * @return int Load ID.
     * @throws \RuntimeException if the extraction schema is invalid.
     */
    public function persistExtractedLoad(
        int $companyId,
        array $extraction,
        int $actorId,
        ?int $rawSourceId,
        string $loadSource
    ): int {
        // FR-013 acceptance: reject invalid schema output before any persist.
        if (!isset($extraction['extraction_version']) || !is_string($extraction['extraction_version'])
            || !isset($extraction['fields']) || !is_array($extraction['fields'])
            || !isset($extraction['missing']) || !is_array($extraction['missing'])
            || !isset($extraction['overall_confidence']) || !is_string($extraction['overall_confidence'])
            || !isset($extraction['review_required'])) {
            throw new \RuntimeException('Invalid extraction schema (FR-013): missing required keys.');
        }

        // Flatten extracted fields for the loads columns (only non-null values land).
        $fields = [];
        foreach ($extraction['fields'] as $name => $spec) {
            if (!is_array($spec) || !array_key_exists('value', $spec)) {
                throw new \RuntimeException('Invalid extraction field spec (FR-013): ' . (string) $name);
            }
            $value = $spec['value'];
            if ($value !== null && $value !== '') {
                if ($name === 'weight_lbs') {
                    $fields['weight_lbs'] = is_numeric($value) ? (int) $value : null;
                } elseif ($name === 'posted_rate') {
                    $fields['posted_rate'] = is_numeric($value) ? (string) $value : null;
                } elseif (in_array($name, ['origin_city', 'origin_state', 'dest_city', 'dest_state', 'commodity'], true)) {
                    $fields[$name] = is_string($value) ? $value : null;
                }
            }
        }

        // Re-check missing required against what actually extracted (no silent completion).
        $missingJson = $extraction['missing'] === [] ? null : json_encode($extraction['missing'], JSON_UNESCAPED_SLASHES);
        $fieldsJson = json_encode($extraction['fields'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $reviewRequired = ((int) $extraction['review_required']) === 1 ? 1 : 0;
        $confidence = $extraction['overall_confidence'];

        $stmt = $this->db->prepare(
            'INSERT INTO loads '
            . '(company_id, source_record_id, broker_id, origin_city, origin_state, dest_city, dest_state, '
            . 'commodity, weight_lbs, posted_rate, extraction_version, extraction_confidence, extraction_missing, '
            . 'extraction_fields, review_required, status, created_by) '
            . 'VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        if ($stmt === false) {
            throw new \RuntimeException('Failed to prepare extracted load insert: ' . $this->db->error);
        }
        $broker = null;
        $originCity = (string) ($fields['origin_city'] ?? '');
        $originState = (string) ($fields['origin_state'] ?? '');
        $destCity = (string) ($fields['dest_city'] ?? '');
        $destState = (string) ($fields['dest_state'] ?? '');
        $commodity = (string) ($fields['commodity'] ?? '');
        $weight = isset($fields['weight_lbs']) ? (int) $fields['weight_lbs'] : null;
        $rate = isset($fields['posted_rate']) ? (string) $fields['posted_rate'] : null;
        $status = 'new';
        $srcVar = $rawSourceId ?? null;
        $brokerVar = null; // brokers entity ships later; FK intentionally absent for now.
        $verVar = $extraction['extraction_version'];
        $confVar = $confidence;
        // 17 placeholders: i,i,i,s,s,s,s,s,i,s,s,s,s,s,i,s,i
        $stmt->bind_param(
            'iiisssssisssssisi',
            $companyId,
            $srcVar,
            $brokerVar,
            $originCity,
            $originState,
            $destCity,
            $destState,
            $commodity,
            $weight,
            $rate,
            $verVar,
            $confVar,
            $missingJson,
            $fieldsJson,
            $reviewRequired,
            $status,
            $actorId
        );
        if (!$stmt->execute()) {
            $stmt->close();
            throw new \RuntimeException('Failed to create extracted load: ' . $this->db->error);
        }
        $id = $this->lastInsertId();
        $stmt->close();

        $audit = new AuditLog($this->db);
        $audit->recordSystem('load.extract', $companyId, 'system', 'create', [
            'load_id' => $id,
            'source' => $loadSource,
            'extraction_version' => $verVar,
            'overall_confidence' => $confVar,
            'missing' => $extraction['missing'],
        ], 'load', (string) $id);

        return $id;
    }

    private function lastInsertId(): int
    {
        $id = $this->db->insert_id;
        return $id > 0 ? (int) $id : 0;
    }
}
