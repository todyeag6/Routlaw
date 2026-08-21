-- ROUTLAW gate_results (Phase 4 T10.3; FRD §13 / §19.3 hard-gate audit).
-- Stores the aggregate hard-gate evaluation so a decision case can reference it and the
-- hard-gate violation rate is auditable. Tenant-scoped from day one (FR-042, BR-020).
SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS gate_results (
    id           INT UNSIGNED NOT NULL AUTO_INCREMENT,
    company_id   INT UNSIGNED NOT NULL,
    -- Aggregate outcome: clear | blocked | needs_review.
    outcome      ENUM('clear','blocked','needs_review') NOT NULL,
    -- Per-gate results (gate, outcome, reason, message, detail) as JSON for explainability.
    results_json MEDIUMTEXT NOT NULL,
    created_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_gr_company (company_id),
    CONSTRAINT fk_gr_company FOREIGN KEY (company_id) REFERENCES companies (id) ON DELETE RESTRICT
) DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
