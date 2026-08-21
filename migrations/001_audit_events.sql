-- ROUTLAW audit + agent-trace schema (build-plan §4 Phase 1 T3, FR-029/FR-030/MOD-15)
-- MariaDB-safe: no CREATE DATABASE/USE; utf8mb4; idempotent seeds.
-- Supports DELIMITER directive for trigger bodies (Connection::applyFile).
SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS audit_events (
    id             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    company_id     INT UNSIGNED NULL,
    actor_type     ENUM('user','agent','system','anonymous') NOT NULL,
    actor_id       INT UNSIGNED NULL,
    event_type     VARCHAR(64) NOT NULL,
    action         VARCHAR(64) NOT NULL,
    target_type    VARCHAR(64) NULL,
    target_id      VARCHAR(128) NULL,
    result         ENUM('success','failure') NOT NULL,
    correlation_id VARCHAR(64) NULL,
    ip_address     VARCHAR(45) NULL,
    user_agent     VARCHAR(512) NULL,
    detail         JSON NULL,
    created_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_audit_company (company_id),
    KEY idx_audit_actor (actor_type, actor_id),
    KEY idx_audit_event (event_type),
    KEY idx_audit_created (created_at),
    KEY idx_audit_correlation (correlation_id)
) DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- FR-001: Persistent login failure tracking for brute-force rate limiting.
-- Unlike in-memory counters, this table survives across HTTP requests.
CREATE TABLE IF NOT EXISTS login_attempts (
    id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    email      VARCHAR(255) NOT NULL,
    ip_address VARCHAR(45) NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_login_email (email),
    KEY idx_login_created (created_at)
) DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS agent_runs (
    id             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    company_id     INT UNSIGNED NULL,
    correlation_id VARCHAR(64) NULL,
    workflow_type  VARCHAR(96) NOT NULL,
    agent_version  VARCHAR(64) NULL,
    policy_version VARCHAR(64) NULL,
    status         ENUM('queued','running','succeeded','failed','cancelled') NOT NULL DEFAULT 'queued',
    started_at     DATETIME NULL,
    finished_at    DATETIME NULL,
    created_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_agent_company (company_id),
    KEY idx_agent_correlation (correlation_id),
    KEY idx_agent_status (status)
) DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS tool_calls (
    id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    company_id    INT UNSIGNED NULL,
    agent_run_id  BIGINT UNSIGNED NOT NULL,
    tool_name     VARCHAR(96) NOT NULL,
    request_hash  VARCHAR(64) NULL,
    result_status ENUM('success','failure','blocked') NOT NULL DEFAULT 'success',
    created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    CONSTRAINT fk_toolcall_agent FOREIGN KEY (agent_run_id) REFERENCES agent_runs (id) ON DELETE CASCADE,
    KEY idx_tool_agent (agent_run_id),
    KEY idx_tool_company (company_id)
) DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- BR-017: Audit events are append-only. Normal application roles cannot
-- UPDATE or DELETE audit records. These triggers enforce immutability
-- at the data layer so that even a compromised app role cannot tamper
-- with the audit trail.
DELIMITER //
CREATE TRIGGER audit_events_no_update BEFORE UPDATE ON audit_events
FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'audit_events is append-only; UPDATE denied (BR-017)';
END//
CREATE TRIGGER audit_events_no_delete BEFORE DELETE ON audit_events
FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'audit_events is append-only; DELETE denied (BR-017)';
END//
DELIMITER ;
