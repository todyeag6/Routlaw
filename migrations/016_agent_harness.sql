-- ROUTLAW agent harness + memory schema (build-plan §4 Phase 6 T17/T18, FR-034/035/036/037, FR-032, HAR-*).
-- MariaDB-safe: no CREATE DATABASE/USE; utf8mb4; idempotent (IF NOT EXISTS on tables,
-- ADD COLUMN IF NOT EXISTS on alters). No triggers introduced here.
-- Conventions: FK parent RESTRICT / child CASCADE; tenant scope on every operational table.
-- Role-rank model (from 000 seed): super_admin=1, company_admin=2, dispatcher=3,
-- carrier=4, reviewer=5. Lower number = higher privilege. A memory entry with
-- min_role=N is visible to a caller iff caller.roleId <= N.
SET NAMES utf8mb4;

-- T17: extend agent_runs with budget/kill-switch state (AuditLog::recordAgentRun inserts
-- the ORIGINAL 6 columns only; these additions are nullable-with-default and never reorder).
ALTER TABLE agent_runs
    ADD COLUMN IF NOT EXISTS step_count   INT UNSIGNED NOT NULL DEFAULT 0 AFTER finished_at,
    ADD COLUMN IF NOT EXISTS token_estimate INT UNSIGNED NOT NULL DEFAULT 0 AFTER step_count,
    ADD COLUMN IF NOT EXISTS cost_cents   INT UNSIGNED NOT NULL DEFAULT 0 AFTER token_estimate,
    ADD COLUMN IF NOT EXISTS budget_status ENUM('ok','exceeded','killed') NOT NULL DEFAULT 'ok' AFTER cost_cents;

-- T17: extend tool_calls with allowlist decision + output validation/sanitization trace.
-- AuditLog::recordToolCall inserts (company_id, agent_run_id, tool_name, request_hash,
-- result_status) only; additions are nullable-with-default and never reorder.
ALTER TABLE tool_calls
    ADD COLUMN IF NOT EXISTS allowed       TINYINT(1) NOT NULL DEFAULT 1 AFTER result_status,
    ADD COLUMN IF NOT EXISTS deny_reason   VARCHAR(255) NULL AFTER allowed,
    ADD COLUMN IF NOT EXISTS output_hash   VARCHAR(64) NULL AFTER deny_reason,
    ADD COLUMN IF NOT EXISTS sanitized     TINYINT(1) NOT NULL DEFAULT 0 AFTER output_hash;

-- T17: global kill switch + other operator flags. Keyed; company-scoped where per-tenant
-- policy is needed, but the kill switch is global (company_id NULL = platform-wide).
CREATE TABLE IF NOT EXISTS system_flags (
    `key`        VARCHAR(96) NOT NULL,
    company_id   INT UNSIGNED NULL,
    value_json   JSON NULL,
    updated_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`key`, company_id),
    KEY idx_sysflag_company (company_id)
) DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- T18: memory entries — provenance-tagged, tenant/role-scoped, contradiction + expiry aware.
CREATE TABLE IF NOT EXISTS memory_entries (
    id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    company_id      INT UNSIGNED NOT NULL,
    agent_run_id    BIGINT UNSIGNED NULL,
    min_role        TINYINT UNSIGNED NOT NULL DEFAULT 1,
    content         MEDIUMTEXT NOT NULL,
    provenance_json JSON NULL,
    contradiction_of BIGINT UNSIGNED NULL,
    status          ENUM('active','contradicted','expired') NOT NULL DEFAULT 'active',
    expires_at      DATETIME NULL,
    revalidated_at  DATETIME NULL,
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    CONSTRAINT fk_memory_company FOREIGN KEY (company_id) REFERENCES companies (id) ON DELETE RESTRICT,
    CONSTRAINT fk_memory_agent FOREIGN KEY (agent_run_id) REFERENCES agent_runs (id) ON DELETE CASCADE,
    CONSTRAINT fk_memory_contradiction FOREIGN KEY (contradiction_of) REFERENCES memory_entries (id) ON DELETE SET NULL,
    KEY idx_mem_agent (agent_run_id),
    KEY idx_mem_company (company_id),
    KEY idx_mem_role (min_role),
    KEY idx_mem_status (status),
    KEY idx_mem_company_role (company_id, min_role)
) DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
