-- ROUTLAW equipment profiles schema (build-plan §4 Phase 2 T5, FR-006/FR-007/BR-001/002).
-- MariaDB-safe: no CREATE DATABASE/USE; utf8mb4; IF NOT EXISTS; INSERT IGNORE seeds.
-- Tenant-scoped from day one (FR-042, BR-020): every row carries company_id.
SET NAMES utf8mb4;

-- Multiple equipment profiles per carrier (FR-006/BR-002).
-- Numeric-range validation and hard-match constraints enforced in EquipmentProfileService
-- (FR-007: validate ranges, identify inconsistent values without silently correcting).
CREATE TABLE IF NOT EXISTS equipment_profiles (
    id                 INT UNSIGNED NOT NULL AUTO_INCREMENT,
    company_id         INT UNSIGNED NOT NULL,
    carrier_id         INT UNSIGNED NOT NULL,
    truck_type         VARCHAR(128) NOT NULL,
    truck_gvwr_lbs     INT UNSIGNED NULL,
    trailer_type       VARCHAR(128) NULL,
    trailer_gvwr_lbs   INT UNSIGNED NULL,
    gcwr_lbs           INT UNSIGNED NULL,
    payload_capacity_lbs INT UNSIGNED NULL,
    deck_length_ft     DECIMAL(5,2) NULL,
    deck_width_ft      DECIMAL(4,2) NULL,
    capabilities       JSON NULL,
    status             ENUM('draft','needs_review','approved','inactive') NOT NULL DEFAULT 'draft',
    is_complete        TINYINT(1) NOT NULL DEFAULT 0,
    source_type        VARCHAR(64) NOT NULL DEFAULT 'carrier_self',
    source_id          VARCHAR(128) NULL,
    created_at         DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at         DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    created_by         INT UNSIGNED NULL,
    updated_by         INT UNSIGNED NULL,
    row_version        INT UNSIGNED NOT NULL DEFAULT 1,
    deleted_at         DATETIME NULL,
    deleted_by         INT UNSIGNED NULL,
    delete_reason      VARCHAR(255) NULL,
    PRIMARY KEY (id),
    KEY idx_ep_carrier (carrier_id),
    KEY idx_ep_company (company_id),
    CONSTRAINT fk_ep_company FOREIGN KEY (company_id) REFERENCES companies (id) ON DELETE RESTRICT,
    CONSTRAINT fk_ep_carrier FOREIGN KEY (carrier_id) REFERENCES carriers (id) ON DELETE CASCADE
) DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
