-- ROUTLAW private documents (Phase 5 T16; FR-025/026/027/028).
-- Classification is a label, NOT legal validity (FR-028). Documents are stored
-- OUTSIDE the web docroot (non-web storage/documents/ dir); served only via a
-- signed-access PHP endpoint — never a direct public path (locked ADR 2026-08-21).
-- MariaDB-safe: no CREATE DATABASE/USE; IF NOT EXISTS; tenant-scoped.
SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS documents (
    id              INT UNSIGNED NOT NULL AUTO_INCREMENT,
    company_id      INT UNSIGNED NOT NULL,
    uploader_user_id INT UNSIGNED NOT NULL,
    storage_key     CHAR(64) NOT NULL,          -- sodium_bin2hex(random_bytes(32))
    original_name   VARCHAR(255) NOT NULL,
    mime_type       VARCHAR(128) NOT NULL,
    file_size       INT UNSIGNED NOT NULL,
    classification  ENUM('internal','confidential','restricted') NOT NULL DEFAULT 'internal',
    human_verification_note VARCHAR(255) NOT NULL,
    -- classification is an access-control LABEL, not a statement of legal validity (FR-028).
    -- human_verification_note is REQUIRED: a human must attest the document's nature
    -- before it is accepted (FR-028). No automated assertion of legal validity.
    uploaded_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    download_token  CHAR(64) NULL,             -- signed-access token (rotated per share)
    download_uses   INT UNSIGNED NOT NULL DEFAULT 0,
    deleted_at      DATETIME NULL,
    deleted_by      INT UNSIGNED NULL,
    delete_reason   VARCHAR(255) NULL,
    PRIMARY KEY (id),
    KEY idx_documents_company (company_id),
    KEY idx_documents_key (storage_key),
    CONSTRAINT fk_documents_company FOREIGN KEY (company_id) REFERENCES companies (id) ON DELETE RESTRICT,
    CONSTRAINT fk_documents_uploader FOREIGN KEY (uploader_user_id) REFERENCES users (id) ON DELETE RESTRICT
) DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
