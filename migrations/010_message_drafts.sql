-- ROUTLAW message drafts (Phase 5 T14; FR-021).
-- Editable, source-linked drafts that CANNOT self-send (outbound send lives in a
-- separate OutboundService + approval gate — see T14.3/T14.4).
-- Tenant-scoped from day one (FR-042, BR-020). MariaDB-safe: no CREATE DATABASE/USE.
SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS message_drafts (
    id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
    company_id  INT UNSIGNED NOT NULL,

    -- Source link (FR-021): the decision/load/lead this draft serves.
    -- Soft link (source_type + source_id) — the service validates in-tenant existence;
    -- a hard FK to three tables is needlessly coupled and not Routlaw's pattern.
    source_type ENUM('decision','load','lead') NOT NULL,
    source_id   INT UNSIGNED NOT NULL,

    -- Draft content (editable).
    subject     VARCHAR(255) NOT NULL,
    body        MEDIUMTEXT NOT NULL,

    -- Draft lifecycle: drafting -> pending_approval -> sent | rejected.
    -- sent/rejected are only reachable via the separate approved send path (T14.4).
    status      ENUM('draft','pending_approval','sent','rejected') NOT NULL DEFAULT 'draft',

    created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    created_by  INT UNSIGNED NULL,
    updated_by  INT UNSIGNED NULL,
    -- Soft-delete (FR-049): regulated entities never hard-delete.
    deleted_at  DATETIME NULL,
    deleted_by  INT UNSIGNED NULL,
    delete_reason VARCHAR(255) NULL,

    PRIMARY KEY (id),
    KEY idx_md_company (company_id),
    KEY idx_md_status (company_id, status),
    KEY idx_md_source (source_type, source_id),
    CONSTRAINT fk_md_company FOREIGN KEY (company_id) REFERENCES companies (id) ON DELETE RESTRICT
) DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
