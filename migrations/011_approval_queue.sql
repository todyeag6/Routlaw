-- ROUTLAW approval queue (Phase 5 T15; FR-022/023/024).
-- Payload-bound, replay-safe approval requests. Every row carries the canonical
-- payload hash; an approval is valid only if that hash still matches the live payload.
-- Tenant-scoped from day one (FR-042, BR-020). MariaDB-safe: no CREATE DATABASE/USE.
SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS approval_requests (
    id              INT UNSIGNED NOT NULL AUTO_INCREMENT,
    company_id      INT UNSIGNED NOT NULL,

    -- FR-022/023/024: the actor who must act (and did).
    actor_user_id   INT UNSIGNED NOT NULL,

    -- What is being authorized.
    action          VARCHAR(128) NOT NULL,      -- e.g. 'message.send', 'document.share'
    resource_type   VARCHAR(64)  NOT NULL,      -- e.g. 'message_draft', 'document'
    resource_id     INT UNSIGNED NOT NULL,

    -- FR-024: canonical payload hash. Any modification of the payload -> mismatch ->
    -- a new approval is required (payload-bound, replay-safe). sha256 hex = CHAR(64).
    payload_hash    CHAR(64)     NOT NULL,
    payload_json    MEDIUMTEXT   NULL,         -- the exact payload that was approved (audit)

    -- Lifecycle: pending -> approved|rejected|expired; consumed on apply (single-use).
    status          ENUM('pending','approved','rejected','expired','consumed')
                    NOT NULL DEFAULT 'pending',
    reason          VARCHAR(255) NULL,         -- for rejected/expired

    -- Expiry defeats replay of stale approvals (FR-023/024).
    expires_at      DATETIME NOT NULL,

    -- Single-use marker (replay safety / FR-024).
    consumed_at     DATETIME NULL,

    decided_by      INT UNSIGNED NULL,         -- approver user id
    decided_at      DATETIME NULL,

    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    PRIMARY KEY (id),
    KEY idx_ar_company (company_id),
    KEY idx_ar_resource (resource_type, resource_id),
    KEY idx_ar_status (company_id, status, resource_type, resource_id),
    KEY idx_ar_actor (actor_user_id),
    KEY idx_ar_expires (status, expires_at),
    CONSTRAINT fk_ar_company  FOREIGN KEY (company_id) REFERENCES companies (id) ON DELETE RESTRICT,
    CONSTRAINT fk_ar_actor    FOREIGN KEY (actor_user_id) REFERENCES users (id) ON DELETE RESTRICT
) DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
