-- ROUTLAW self-correction + learning + non-self-promotion schema (build-plan §4 Phase 6 T19,
-- FR-038/039/041, LLM04, FRD §2.2 "policy outside the model").
-- MariaDB-safe: no CREATE DATABASE/USE; utf8mb4; idempotent (IF NOT EXISTS on tables,
-- ADD COLUMN IF NOT EXISTS on alters). No triggers introduced here.
-- Conventions: FK parent RESTRICT / child CASCADE; tenant scope on every operational table.
-- Role-rank model (from 000 seed): super_admin=1, company_admin=2, dispatcher=3,
-- carrier=4, reviewer=5. Lower number = higher privilege.
SET NAMES utf8mb4;

-- T19 / FR-041: learning & improvement proposals that CANNOT self-promote.
-- A model output may only ever create a proposal in status 'draft'. Any transition out of
-- draft requires an explicit human approver (approved_by) recorded server-side (PHP guard),
-- never derivable from model text. proposal_status_history provides full rollback/audit.
CREATE TABLE IF NOT EXISTS learning_proposals (
    id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    company_id   INT UNSIGNED NOT NULL,
    run_id       BIGINT UNSIGNED NULL,
    status       ENUM('draft','evaluated','approved','staged','rolled_back','rejected') NOT NULL DEFAULT 'draft',
    -- payload_hash = sha256 of the immutable proposal payload; any change => new proposal.
    payload_hash VARCHAR(64) NOT NULL,
    payload_json JSON NULL,
    -- version: monotonic per company; staged/approved proposals are versioned & rollback-able.
    version      INT UNSIGNED NOT NULL DEFAULT 1,
    -- created_by: the actor that originated the draft. For LLM-originated drafts this is the
    -- agent run / system principal, NOT a human approver.
    created_by   INT UNSIGNED NULL,
    -- approved_by: NULL until a human advances the proposal past 'draft'. This column is the
    -- hard gate — the SelfPromotionGuard refuses any advance when approved_by is NULL.
    approved_by  INT UNSIGNED NULL,
    created_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    CONSTRAINT fk_prop_company FOREIGN KEY (company_id) REFERENCES companies (id) ON DELETE RESTRICT,
    CONSTRAINT fk_prop_run FOREIGN KEY (run_id) REFERENCES agent_runs (id) ON DELETE SET NULL,
    KEY idx_prop_company (company_id),
    KEY idx_prop_status (status),
    KEY idx_prop_company_status (company_id, status)
) DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- T19 / FR-041: immutable status-transition history (supports rollback + audit trail).
CREATE TABLE IF NOT EXISTS proposal_status_history (
    id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    proposal_id  BIGINT UNSIGNED NOT NULL,
    from_status  VARCHAR(24) NULL,
    to_status    VARCHAR(24) NOT NULL,
    -- actor_id: the actor that performed the transition. For a model "request" this is the
    -- system/agent principal; an advance out of 'draft' MUST be a human (approved_by) actor.
    actor_id     INT UNSIGNED NULL,
    reason       VARCHAR(512) NULL,
    created_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    CONSTRAINT fk_psh_proposal FOREIGN KEY (proposal_id) REFERENCES learning_proposals (id) ON DELETE CASCADE,
    KEY idx_psh_proposal (proposal_id)
) DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
