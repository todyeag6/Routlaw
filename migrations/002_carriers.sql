-- ROUTLAW carrier + lifecycle schema (build-plan §4 Phase 2 T4, FR-004/FR-005/BR-001).
-- MariaDB-safe: no CREATE DATABASE/USE; utf8mb4; IF NOT EXISTS; INSERT IGNORE seeds.
-- Tenant-scoped from day one (FR-042, BR-020): every operational row carries company_id.
SET NAMES utf8mb4;

-- Core carrier entity. Carrier self-registers (email-verify -> active) per §0b #7 / FRD §4.3.
-- Lifecycle states per FRD §13.1: new -> needs_documents | under_review -> active | inactive | rejected -> archived
CREATE TABLE IF NOT EXISTS carriers (
    id                INT UNSIGNED NOT NULL AUTO_INCREMENT,
    company_id        INT UNSIGNED NOT NULL,
    legal_name        VARCHAR(255) NOT NULL,
    doing_business_as VARCHAR(255) NULL,
    dot_number        VARCHAR(32) NULL,
    mc_number         VARCHAR(32) NULL,
    ein               VARCHAR(32) NULL,
    status            ENUM('new','needs_documents','under_review','active','inactive','rejected','archived')
                     NOT NULL DEFAULT 'new',
    source_type       VARCHAR(64) NOT NULL DEFAULT 'carrier_signup',
    source_id         VARCHAR(128) NULL,
    created_at        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    created_by        INT UNSIGNED NULL,
    updated_by        INT UNSIGNED NULL,
    row_version       INT UNSIGNED NOT NULL DEFAULT 1,
    deleted_at        DATETIME NULL,
    deleted_by        INT UNSIGNED NULL,
    delete_reason     VARCHAR(255) NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_carriers_company_dot (company_id, dot_number),
    UNIQUE KEY uq_carriers_company_mc (company_id, mc_number),
    CONSTRAINT fk_carriers_company FOREIGN KEY (company_id) REFERENCES companies (id) ON DELETE RESTRICT
) DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Carrier lifecycle state-transition log (FR-005: only authorized roles transition; transitions logged).
-- Allowed transitions (per FRD §13.1) are enforced in CarrierService:
--   new -> needs_documents | under_review
--   needs_documents -> under_review | new
--   under_review -> active | rejected | new
--   active -> inactive | archived
--   inactive -> active | archived
--   rejected -> archived
CREATE TABLE IF NOT EXISTS carrier_status_history (
    id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    company_id  INT UNSIGNED NOT NULL,
    carrier_id  INT UNSIGNED NOT NULL,
    from_status VARCHAR(32) NOT NULL,
    to_status   VARCHAR(32) NOT NULL,
    reason      VARCHAR(255) NULL,
    actor_id    INT UNSIGNED NULL,
    actor_type  ENUM('user','system','agent') NOT NULL DEFAULT 'user',
    created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_csh_carrier (carrier_id),
    KEY idx_csh_company (company_id),
    CONSTRAINT fk_csh_carrier FOREIGN KEY (carrier_id) REFERENCES carriers (id) ON DELETE CASCADE
) DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
