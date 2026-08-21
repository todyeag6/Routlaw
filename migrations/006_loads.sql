-- ROUTLAW loads schema (build-plan §4 Phase 3 T8, FR-010/FR-011/FR-013/FR-014).
-- MariaDB-safe: no CREATE DATABASE/USE; utf8mb4; IF NOT EXISTS.
-- Tenant-scoped from day one (FR-042, BR-020): every operational row carries company_id.
-- FR-014: load links to immutable source_record (preserved original).
-- FR-013: extraction is versioned + carries per-field confidence + missing-field reporting.
-- Load state machine (FRD §13.3): new -> extracted -> needs_info | review_ready -> ...
SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS loads (
    id                  INT UNSIGNED NOT NULL AUTO_INCREMENT,
    company_id          INT UNSIGNED NOT NULL,
    -- FR-014: link to the immutable original source.
    source_record_id    INT UNSIGNED NULL,
    -- Broker linkage (brokers entity ships in a later module; kept nullable, no FK yet).
    broker_id           INT UNSIGNED NULL,
    -- FR-010/FR-013: core load facts (required pickup, delivery, commodity, weight, source context).
    origin_city         VARCHAR(128) NULL,
    origin_state        VARCHAR(8) NULL,
    dest_city           VARCHAR(128) NULL,
    dest_state          VARCHAR(8) NULL,
    commodity           VARCHAR(255) NULL,
    weight_lbs          INT UNSIGNED NULL,
    posted_rate         DECIMAL(10,2) NULL,
    -- FR-013: extraction versioning + per-field confidence + missing-field reporting.
    extraction_version  VARCHAR(32) NULL,
    extraction_confidence VARCHAR(16) NULL COMMENT 'overall: high|medium|low',
    extraction_missing  MEDIUMTEXT NULL COMMENT 'JSON list of missing required fields (FR-013)',
    extraction_fields   MEDIUMTEXT NULL COMMENT 'JSON: per-field {value, confidence, provenance} (FR-013)',
    -- FR-013 acceptance: low confidence requires review.
    review_required     TINYINT(1) NOT NULL DEFAULT 0,
    -- FRD §13.3 load state machine.
    status              ENUM('new','extracted','needs_info','review_ready','rejected','archived') NOT NULL DEFAULT 'new',
    notes               TEXT NULL,
    created_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    created_by          INT UNSIGNED NULL,
    updated_by          INT UNSIGNED NULL,
    row_version         INT UNSIGNED NOT NULL DEFAULT 1,
    deleted_at          DATETIME NULL,
    deleted_by          INT UNSIGNED NULL,
    delete_reason       VARCHAR(255) NULL,
    PRIMARY KEY (id),
    KEY idx_loads_company_status (company_id, status),
    KEY idx_loads_source (company_id, source_record_id),
    CONSTRAINT fk_loads_company FOREIGN KEY (company_id) REFERENCES companies (id) ON DELETE RESTRICT,
    CONSTRAINT fk_loads_source FOREIGN KEY (source_record_id) REFERENCES source_records (id) ON DELETE RESTRICT
) DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
