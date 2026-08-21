<?php
declare(strict_types=1);

namespace Routlaw\Equipment;

use Routlaw\Security\AuditLog;

/**
 * T5 Equipment profiles (build-plan §4 Phase 2 T5, FR-006/FR-007/BR-001/002).
 *
 * Multiple equipment profiles per carrier with numeric-range validation,
 * hard-match constraints (hazmat class, GCWR vs combined GVWR), and approval gating
 * where incomplete profiles can never be approved.
 *
 * Tenant-scoped from day one (FR-042, BR-020): every query carries company_id.
 * No Python, no Redis, no Docker — pure PHP 8.3 on MariaDB.
 */
final class EquipmentProfileService
{
    private \mysqli $db;

    /** Profile status states (FRD §13.2): draft -> needs_review -> approved -> inactive. */
    public const STATUS_DRAFT = 'draft';
    public const STATUS_APPROVED = 'approved';
    public const STATUS_INACTIVE = 'inactive';

    public function __construct(\mysqli $db)
    {
        $this->db = $db;
    }

    /**
     * Create an equipment profile for a carrier (FR-006/BR-002).
     *
     * Validates numeric ranges (FR-007) and hard-match constraints:
     * - GCWR must be >= truck GVWR + trailer GVWR.
     * - Hazmat capabilities must be declared with a matching hazmat endorsement flag.
     *
     * @param int        $companyId    Tenant scope (FR-042).
     * @param int        $carrierId    Carrier this profile belongs to.
     * @param string     $truckType    Required (FR-006).
     * @param string     $trailerType  Optional trailer type.
     * @param int|null   $truckGvwr    Truck GVWR in lbs (must be > 0 if provided).
     * @param int|null   $trailerGvwr  Trailer GVWR in lbs (must be > 0 if provided).
     * @param int|null   $gcwr         GCWR in lbs (must be >= truck+trailer GVWR if both provided).
     * @param int|null   $payloadLbs   Payload capacity in lbs (must be > 0 if provided).
     * @param float|null $deckLength   Deck length in ft (must be > 0 if provided).
     * @param float|null $deckWidth    Deck width in ft (must be > 0 if provided).
     * @param array<string,mixed>|null $capabilities Structured capabilities (e.g. hazmat info).
     * @param bool       $isComplete   Whether the profile has all required fields.
     * @return int Profile ID.
     * @throws \RuntimeException on validation failure (FR-007).
     */
    public function createProfile(
        int $companyId,
        int $carrierId,
        string $truckType,
        string $trailerType,
        ?int $truckGvwr,
        ?int $trailerGvwr,
        ?int $gcwr,
        ?int $payloadLbs,
        ?float $deckLength,
        ?float $deckWidth,
        ?array $capabilities,
        bool $isComplete
    ): int {
        // FR-006: truck_type is required.
        if ($truckType === '') {
            throw new \RuntimeException('truck_type is required (FR-006).');
        }

        // FR-007: numeric range validation — positive values only.
        $this->validatePositiveInt('truck_gvwr_lbs', $truckGvwr);
        $this->validatePositiveInt('trailer_gvwr_lbs', $trailerGvwr);
        $this->validatePositiveInt('gcwr_lbs', $gcwr);
        $this->validatePositiveInt('payload_capacity_lbs', $payloadLbs);
        $this->validatePositiveFloat('deck_length_ft', $deckLength);
        $this->validatePositiveFloat('deck_width_ft', $deckWidth);

        // FR-007: hard-match constraint — GCWR must be >= truck GVWR + trailer GVWR.
        if ($truckGvwr !== null && $trailerGvwr !== null && $gcwr !== null) {
            $combined = $truckGvwr + $trailerGvwr;
            if ($gcwr < $combined) {
                throw new \RuntimeException(
                    sprintf(
                        'gcwr_lbs (%d) must be >= truck_gvwr_lbs + trailer_gvwr_lbs (%d) (FR-007 hard-match).',
                        $gcwr,
                        $combined
                    )
                );
            }
        }

        // FR-007: hard-match constraint — hazmat class requires hazmat endorsement declaration.
        if ($capabilities !== null) {
            $this->validateHazmatConstraint($capabilities);
        }

        $capsJson = $capabilities !== null ? json_encode($capabilities, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) : null;

        $stmt = $this->db->prepare(
            'INSERT INTO equipment_profiles '
            . '(company_id, carrier_id, truck_type, trailer_type, truck_gvwr_lbs, trailer_gvwr_lbs, '
            . 'gcwr_lbs, payload_capacity_lbs, deck_length_ft, deck_width_ft, capabilities, is_complete, status) '
            . 'VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        if ($stmt === false) {
            throw new \RuntimeException('Failed to prepare equipment profile insert: ' . $this->db->error);
        }

        $trailerTypeVal = $trailerType;
        $completeVal = (int) $isComplete;
        $status = self::STATUS_DRAFT;
        $stmt->bind_param(
            'iisssssssssis',
            $companyId,
            $carrierId,
            $truckType,
            $trailerTypeVal,
            $truckGvwr,
            $trailerGvwr,
            $gcwr,
            $payloadLbs,
            $deckLength,
            $deckWidth,
            $capsJson,
            $completeVal,
            $status
        );
        if (!$stmt->execute()) {
            $stmt->close();
            throw new \RuntimeException('Failed to create equipment profile: ' . $this->db->error);
        }
        $id = $this->lastInsertId();
        $stmt->close();

        // FR-029: audit the creation.
        $audit = new AuditLog($this->db);
        $audit->recordSystem('equipment.create', $companyId, 'system', 'create', [
            'profile_id'  => $id,
            'carrier_id'  => $carrierId,
            'truck_type'  => $truckType,
            'is_complete' => $isComplete,
        ]);

        return $id;
    }

    /**
     * Approve an equipment profile (FR-006/BR-002).
     * Incomplete profiles can never be approved.
     *
     * @param int $profileId   Profile to approve.
     * @param int $companyId   Tenant scope — defense-in-depth (FR-042); the query is scoped
     *                         to company_id so cross-tenant writes cannot occur.
     * @return bool True if approved, false if already approved/incomplete/wrong state/not found.
     */
    public function approveProfile(int $profileId, int $companyId): bool
    {
        // Check completeness — incomplete profiles cannot be approved (FR-006/BR-002).
        $row = $this->fetchProfile($profileId, $companyId);
        if ($row === null) {
            return false;
        }

        $isComplete = (int) $row['is_complete'];
        if ($isComplete !== 1) {
            return false;
        }

        // Transition: draft -> approved (skipping needs_review for self-service).
        // Only draft profiles can be approved.
        $currentStatus = (string) $row['status'];
        if ($currentStatus !== self::STATUS_DRAFT) {
            return false;
        }

        $stmt = $this->db->prepare('UPDATE equipment_profiles SET status = ? WHERE id = ? AND company_id = ?');
        if ($stmt === false) {
            throw new \RuntimeException('Failed to prepare approve query: ' . $this->db->error);
        }
        $newStatus = self::STATUS_APPROVED;
        $stmt->bind_param('sii', $newStatus, $profileId, $companyId);
        $ok = $stmt->execute();
        $stmt->close();

        return $ok;
    }

    /**
     * List equipment profiles scoped to a tenant and carrier (SEC-010/FR-042).
     *
     * @return list<array<string,mixed>>
     */
    public function listForTenant(int $companyId, ?int $carrierId = null): array
    {
        $sql = 'SELECT id, truck_type, trailer_type, truck_gvwr_lbs, trailer_gvwr_lbs, '
            . 'gcwr_lbs, payload_capacity_lbs, deck_length_ft, deck_width_ft, status, is_complete, created_at '
            . 'FROM equipment_profiles WHERE company_id = ? AND deleted_at IS NULL';

        $params = [$companyId];
        $types = 'i';

        if ($carrierId !== null) {
            $sql .= ' AND carrier_id = ?';
            $params[] = $carrierId;
            $types .= 'i';
        }

        $sql .= ' ORDER BY id';

        $stmt = $this->db->prepare($sql);
        if ($stmt === false) {
            throw new \RuntimeException('Failed to prepare list query: ' . $this->db->error);
        }

        $this->bindParamsDynamic($stmt, $types, $params);
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
     * Fetch a single profile by ID, tenant-scoped (FR-042).
     *
     * @return array<string,mixed>|null
     */
    private function fetchProfile(int $profileId, int $companyId): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT id, company_id, carrier_id, truck_type, trailer_type, truck_gvwr_lbs, '
            . 'trailer_gvwr_lbs, gcwr_lbs, payload_capacity_lbs, deck_length_ft, deck_width_ft, '
            . 'capabilities, is_complete, status FROM equipment_profiles WHERE id = ? AND company_id = ? AND deleted_at IS NULL'
        );
        if ($stmt === false) {
            throw new \RuntimeException('Failed to prepare profile fetch: ' . $this->db->error);
        }
        $stmt->bind_param('ii', $profileId, $companyId);
        $stmt->execute();
        $result = $stmt->get_result();
        $row = $result !== false ? $result->fetch_assoc() : null;
        $stmt->close();
        return $row === null || $row === false ? null : $row;
    }

    /**
     * Validate that a nullable positive integer field is null or > 0 (FR-007 range validation).
     */
    private function validatePositiveInt(string $fieldName, ?int $value): void
    {
        if ($value !== null && $value <= 0) {
            throw new \RuntimeException(
                sprintf('%s must be positive if provided (FR-007).', $fieldName)
            );
        }
    }

    /**
     * Validate that a nullable positive float field is null or > 0 (FR-007 range validation).
     */
    private function validatePositiveFloat(string $fieldName, ?float $value): void
    {
        if ($value !== null && $value <= 0) {
            throw new \RuntimeException(
                sprintf('%s must be positive if provided (FR-007).', $fieldName)
            );
        }
    }

    /**
     * FR-007 hard-match constraint: if capabilities declare a hazmat class,
     * the carrier must declare a hazmat endorsement. Without an explicit endorsement
     * flag, the profile is internally inconsistent and rejected.
     *
     * @param array<string,mixed> $capabilities
     */
    private function validateHazmatConstraint(array $capabilities): void
    {
        if (array_key_exists('hazmat_class', $capabilities)) {
            $hazmatClass = $capabilities['hazmat_class'];
            if ($hazmatClass === null || $hazmatClass === '') {
                throw new \RuntimeException('hazmat_class declared but value is empty (FR-007).');
            }
            // Require explicit hazmat endorsement declaration.
            $hasEndorsement = array_key_exists('hazmat_endorsement', $capabilities)
                && $capabilities['hazmat_endorsement'] === true;
            if (!$hasEndorsement) {
                throw new \RuntimeException(
                    sprintf('hazmat_class declared (%s) but hazmat_endorsement not set (FR-007 hard-match).', $hazmatClass)
                );
            }
        }
    }

    /**
     * Bind parameters dynamically to a prepared statement (mysqli requires pass-by-reference).
     *
     * @param \mysqli_stmt $stmt
     * @param string       $types   Type string (e.g. 'iis').
     * @param list<mixed>  $params  Values to bind.
     */
    private function bindParamsDynamic(\mysqli_stmt $stmt, string $types, array $params): void
    {
        $bind = [$types];
        foreach ($params as $key => $value) {
            $bind[] = &$params[$key];
        }
        $stmt->bind_param(...$bind);
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
