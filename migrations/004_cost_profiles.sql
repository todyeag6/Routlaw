-- ROUTLAW cost profiles schema (build-plan §4 Phase 2 T6, FR-051/BR-001/021).
-- MariaDB-safe: no CREATE DATABASE/USE; utf8mb4; IF NOT EXISTS; INSERT IGNORE seeds.
-- Tenant-scoped from day one (FR-042, BR-020): every row carries company_id.
SET NAMES utf8mb4;

-- Versioned carrier cost profiles with effective dates (FR-051).
-- Units: per-mile, flat, percentage (BR-021).
-- Each carrier can have multiple cost profile versions; only the latest
-- effective (not stale, not incomplete) version is used for recommendations (BR-001).
CREATE TABLE IF NOT EXISTS carrier_cost_profiles (
    id                INT UNSIGNED NOT NULL AUTO_INCREMENT,
    company_id        INT UNSIGNED NOT NULL,
    carrier_id        INT UNSIGNED NOT NULL,
    is_complete       TINYINT(1) NOT NULL DEFAULT 0,
    unit_type         ENUM('per_mile','flat','percentage') NOT NULL DEFAULT 'per_mile',
    rate              DECIMAL(10,4) NOT NULL DEFAULT 0,
    effective_from    DATE NOT NULL,
    effective_to      DATE NULL,
    version           INT UNSIGNED NOT NULL DEFAULT 1,
    created_at        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    created_by        INT UNSIGNED NULL,
    updated_by        INT UNSIGNED NULL,
    deleted_at        DATETIME NULL,
    PRIMARY KEY (id),
    KEY idx_ccp_carrier (carrier_id),
    KEY idx_ccp_company (company_id),
    KEY idx_ccp_effective (carrier_id, effective_from, version),
    CONSTRAINT fk_ccp_company FOREIGN KEY (company_id) REFERENCES companies (id) ON DELETE RESTRICT,
    CONSTRAINT fk_ccp_carrier FOREIGN KEY (carrier_id) REFERENCES carriers (id) ON DELETE CASCADE
) DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
