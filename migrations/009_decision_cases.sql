-- ROUTLAW decision-case schema (Phase 4 T12.1/T13.x; FRD §12.4).
-- decision_cases + input snapshots + outcomes + prediction variances + observations.
-- All tenant-scoped from day one (FR-042, BR-020). Soft-delete-friendly via common columns.
-- MariaDB-safe: no CREATE DATABASE/USE; utf8mb4; IF NOT EXISTS.
SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS decision_cases (
    id                INT UNSIGNED NOT NULL AUTO_INCREMENT,
    company_id        INT UNSIGNED NOT NULL,
    -- Link to the hard-gate evaluation that ran BEFORE scoring (T10.3).
    gate_result_id    INT UNSIGNED NULL,
    load_id           INT UNSIGNED NULL,
    carrier_id        INT UNSIGNED NULL,
    -- Decision status: clear | needs_review | recommended | rejected | approved (FRD §13).
    status            ENUM('clear','needs_review','recommended','rejected','approved') NOT NULL DEFAULT 'clear',
    -- Selected alternative when decided (T12.2): accept|reject|negotiate|delay|combine|avoid|abstain.
    selected_alternative VARCHAR(32) NULL,
    -- Reason-coded abstention / decision note (visible hard-fail reasons, FRD §6.1).
    decision_note     MEDIUMTEXT NULL,
    created_at        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    created_by        INT UNSIGNED NULL,
    updated_by        INT UNSIGNED NULL,
    row_version       INT UNSIGNED NOT NULL DEFAULT 1,
    deleted_at        DATETIME NULL,
    deleted_by        INT UNSIGNED NULL,
    delete_reason     VARCHAR(255) NULL,
    PRIMARY KEY (id),
    KEY idx_dc_company (company_id),
    KEY idx_dc_gate (gate_result_id),
    KEY idx_dc_load (load_id),
    KEY idx_dc_carrier (carrier_id),
    CONSTRAINT fk_dc_company FOREIGN KEY (company_id) REFERENCES companies (id) ON DELETE RESTRICT,
    CONSTRAINT fk_dc_gate FOREIGN KEY (gate_result_id) REFERENCES gate_results (id) ON DELETE SET NULL
) DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Immutable snapshot of the inputs used for a decision (reproducibility, FR-051/§19).
CREATE TABLE IF NOT EXISTS decision_input_snapshots (
    id                INT UNSIGNED NOT NULL AUTO_INCREMENT,
    company_id        INT UNSIGNED NOT NULL,
    decision_case_id  INT UNSIGNED NULL,
    -- JSON: the full input payload (load, equipment, carrier, cost-profile version, etc.).
    snapshot_json     MEDIUMTEXT NOT NULL,
    -- Hash for tamper-evidence / replay-safe referencing (FR-015/§13 approval).
    snapshot_hash     VARCHAR(64) NOT NULL,
    created_at        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_dis_company (company_id),
    KEY idx_dis_case (decision_case_id),
    CONSTRAINT fk_dis_company FOREIGN KEY (company_id) REFERENCES companies (id) ON DELETE RESTRICT,
    CONSTRAINT fk_dis_case FOREIGN KEY (decision_case_id) REFERENCES decision_cases (id) ON DELETE CASCADE
) DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Actual outcome captured after a decision is executed (FR-060).
CREATE TABLE IF NOT EXISTS decision_outcomes (
    id                INT UNSIGNED NOT NULL AUTO_INCREMENT,
    company_id        INT UNSIGNED NOT NULL,
    decision_case_id  INT UNSIGNED NOT NULL,
    -- Outcome classification: success | partial | failure | exception (FRD §13/§19).
    outcome           ENUM('success','partial','failure','exception') NOT NULL,
    -- Human-readable outcome notes (actual operational/service/financial evidence).
    notes             MEDIUMTEXT NULL,
    occurred_at       DATETIME NULL,
    created_at        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_do_company (company_id),
    KEY idx_do_case (decision_case_id),
    CONSTRAINT fk_do_company FOREIGN KEY (company_id) REFERENCES companies (id) ON DELETE RESTRICT,
    CONSTRAINT fk_do_case FOREIGN KEY (decision_case_id) REFERENCES decision_cases (id) ON DELETE CASCADE
) DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Predicted-vs-actual variance (FR-061), computed from the original input + version snapshot.
CREATE TABLE IF NOT EXISTS prediction_variances (
    id                INT UNSIGNED NOT NULL AUTO_INCREMENT,
    company_id        INT UNSIGNED NOT NULL,
    decision_case_id  INT UNSIGNED NOT NULL,
    decision_outcome_id INT UNSIGNED NULL,
    -- Variance classification (FRD §19): missing_data | source_change | estimation_error | exception | policy_model.
    variance_class    ENUM('missing_data','source_change','estimation_error','exception','policy_model') NULL,
    predicted_value   DECIMAL(12,4) NULL COMMENT 'Predicted metric (e.g. net economics).',
    actual_value      DECIMAL(12,4) NULL COMMENT 'Actual metric captured post-outcome.',
    variance_value    DECIMAL(12,4) NULL COMMENT 'actual - predicted.',
    detail_json       MEDIUMTEXT NULL,
    created_at        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_pv_company (company_id),
    KEY idx_pv_case (decision_case_id),
    CONSTRAINT fk_pv_company FOREIGN KEY (company_id) REFERENCES companies (id) ON DELETE RESTRICT,
    CONSTRAINT fk_pv_case FOREIGN KEY (decision_case_id) REFERENCES decision_cases (id) ON DELETE CASCADE
) DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Counterparty (broker/shipper) observations (FR-057).
CREATE TABLE IF NOT EXISTS counterparty_observations (
    id                INT UNSIGNED NOT NULL AUTO_INCREMENT,
    company_id        INT UNSIGNED NOT NULL,
    decision_case_id  INT UNSIGNED NULL,
    counterparty_type ENUM('broker','shipper','other') NOT NULL DEFAULT 'broker',
    counterparty_ref VARCHAR(255) NULL COMMENT 'Broker/shipper identifier or name.',
    observation       MEDIUMTEXT NOT NULL,
    severity          ENUM('info','watch','concern','critical') NOT NULL DEFAULT 'info',
    created_at        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    created_by        INT UNSIGNED NULL,
    deleted_at        DATETIME NULL,
    deleted_by        INT UNSIGNED NULL,
    delete_reason     VARCHAR(255) NULL,
    PRIMARY KEY (id),
    KEY idx_co_company (company_id),
    KEY idx_co_case (decision_case_id),
    CONSTRAINT fk_co_company FOREIGN KEY (company_id) REFERENCES companies (id) ON DELETE RESTRICT
) DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Facility (pickup/delivery) observations + reload/downstream positioning (FR-058).
CREATE TABLE IF NOT EXISTS facility_observations (
    id                INT UNSIGNED NOT NULL AUTO_INCREMENT,
    company_id        INT UNSIGNED NOT NULL,
    decision_case_id  INT UNSIGNED NULL,
    facility_type     ENUM('pickup','delivery','reload','other') NOT NULL DEFAULT 'pickup',
    facility_ref      VARCHAR(255) NULL COMMENT 'Facility identifier or name.',
    observation       MEDIUMTEXT NOT NULL,
    -- Uncertainty explicitly recorded for reload/downstream positioning (FR-058).
    uncertainty       MEDIUMTEXT NULL,
    severity          ENUM('info','watch','concern','critical') NOT NULL DEFAULT 'info',
    created_at        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    created_by        INT UNSIGNED NULL,
    deleted_at        DATETIME NULL,
    deleted_by        INT UNSIGNED NULL,
    delete_reason     VARCHAR(255) NULL,
    PRIMARY KEY (id),
    KEY idx_fo_company (company_id),
    KEY idx_fo_case (decision_case_id),
    CONSTRAINT fk_fo_company FOREIGN KEY (company_id) REFERENCES companies (id) ON DELETE RESTRICT
) DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
