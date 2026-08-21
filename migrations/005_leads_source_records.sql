-- ROUTLAW leads + source_records schema (build-plan §4 Phase 3 T7/T8, FR-008/FR-009/FR-014).
-- MariaDB-safe: no CREATE DATABASE/USE; utf8mb4; IF NOT EXISTS.
-- Tenant-scoped from day one (FR-042, BR-020): every operational row carries company_id.
-- source_records preserves the immutable original (FR-014); leads carry raw_source_id + normalized ids (FR-008/FR-009).
SET NAMES utf8mb4;

-- Immutable original-source preservation (FR-014/BR-004/005).
-- One table serves leads AND loads: raw submission reference / durable reference.
CREATE TABLE IF NOT EXISTS source_records (
    id              INT UNSIGNED NOT NULL AUTO_INCREMENT,
    company_id      INT UNSIGNED NOT NULL,
    source_type     VARCHAR(64) NOT NULL,
    external_id     VARCHAR(255) NULL,
    content_hash    CHAR(64) NULL,
    raw_reference   TEXT NULL,
    canonical_payload MEDIUMTEXT NULL,
    content_type    VARCHAR(64) NULL,
    received_at     DATETIME NULL,
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_sr_company_type (company_id, source_type),
    KEY idx_sr_ext (company_id, external_id),
    CONSTRAINT fk_sr_company FOREIGN KEY (company_id) REFERENCES companies (id) ON DELETE RESTRICT
) DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Typed leads (FR-008/BR-003): carrier/broker/shipper/general/document.
-- Each lead carries tenant, source, status, timestamps, raw submission reference (FR-008 acceptance).
CREATE TABLE IF NOT EXISTS leads (
    id                  INT UNSIGNED NOT NULL AUTO_INCREMENT,
    company_id          INT UNSIGNED NOT NULL,
    lead_type           ENUM('carrier','broker','shipper','general','document') NOT NULL,
    source              VARCHAR(64) NOT NULL DEFAULT 'manual',
    status              ENUM('new','in_review','converted','rejected','archived') NOT NULL DEFAULT 'new',
    -- Normalized identifiers feed conservative dedup (FR-009).
    normalized_email    VARCHAR(255) NULL,
    normalized_phone    VARCHAR(64) NULL,
    normalized_name     VARCHAR(255) NULL,
    -- Raw submission reference (FR-008 acceptance): link to immutable source.
    raw_source_id       INT UNSIGNED NULL,
    -- Conservative dedup outcome (FR-009 acceptance): flagged, reviewable, NEVER auto-merged.
    dup_status          ENUM('none','possible_duplicate','reviewed_distinct','confirmed_duplicate') NOT NULL DEFAULT 'none',
    dup_of_lead_id      INT UNSIGNED NULL,
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
    KEY idx_leads_company_type (company_id, lead_type),
    KEY idx_leads_dup (company_id, dup_status),
    KEY idx_leads_email (company_id, normalized_email),
    KEY idx_leads_phone (company_id, normalized_phone),
    CONSTRAINT fk_leads_company FOREIGN KEY (company_id) REFERENCES companies (id) ON DELETE RESTRICT,
    CONSTRAINT fk_leads_source FOREIGN KEY (raw_source_id) REFERENCES source_records (id) ON DELETE RESTRICT
) DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
