-- ROUTLAW foundation schema (build-plan §3 T1.3, §4 Phase 1)
-- MariaDB-safe: no CREATE DATABASE/USE; explicit utf8mb4; IF NOT EXISTS; INSERT IGNORE seeds.
-- Unique natural keys on seed tables to survive re-apply (mokimi lesson).
SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS companies (
    id              INT UNSIGNED NOT NULL AUTO_INCREMENT,
    legal_name      VARCHAR(255) NOT NULL,
    display_name    VARCHAR(255) NOT NULL,
    status          ENUM('active','inactive','archived') NOT NULL DEFAULT 'active',
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    created_by      INT UNSIGNED NULL,
    updated_by      INT UNSIGNED NULL,
    row_version     INT UNSIGNED NOT NULL DEFAULT 1,
    deleted_at      DATETIME NULL,
    deleted_by      INT UNSIGNED NULL,
    delete_reason   VARCHAR(255) NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_companies_legal (legal_name)
) DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS roles (
    id          TINYINT UNSIGNED NOT NULL AUTO_INCREMENT,
    slug        VARCHAR(64) NOT NULL,
    name        VARCHAR(128) NOT NULL,
    scope       ENUM('global','company') NOT NULL DEFAULT 'company',
    description VARCHAR(255) NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_roles_slug (slug)
) DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS permissions (
    id          SMALLINT UNSIGNED NOT NULL AUTO_INCREMENT,
    code        VARCHAR(96) NOT NULL,
    description VARCHAR(255) NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_perms_code (code)
) DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS role_permissions (
    role_id       TINYINT UNSIGNED NOT NULL,
    permission_id SMALLINT UNSIGNED NOT NULL,
    PRIMARY KEY (role_id, permission_id),
    CONSTRAINT fk_rp_role FOREIGN KEY (role_id) REFERENCES roles (id) ON DELETE CASCADE,
    CONSTRAINT fk_rp_perm FOREIGN KEY (permission_id) REFERENCES permissions (id) ON DELETE CASCADE
) DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS users (
    id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
    company_id    INT UNSIGNED NOT NULL,
    email         VARCHAR(255) NOT NULL,
    password_hash VARCHAR(255) NOT NULL,
    full_name     VARCHAR(255) NULL,
    status        ENUM('pending_verify','active','suspended','archived') NOT NULL DEFAULT 'pending_verify',
    role_id       TINYINT UNSIGNED NOT NULL,
    created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    created_by    INT UNSIGNED NULL,
    updated_by    INT UNSIGNED NULL,
    row_version   INT UNSIGNED NOT NULL DEFAULT 1,
    deleted_at    DATETIME NULL,
    deleted_by    INT UNSIGNED NULL,
    delete_reason VARCHAR(255) NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_users_company_email (company_id, email),
    CONSTRAINT fk_users_company FOREIGN KEY (company_id) REFERENCES companies (id) ON DELETE RESTRICT,
    CONSTRAINT fk_users_role FOREIGN KEY (role_id) REFERENCES roles (id) ON DELETE RESTRICT
) DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS user_role_assignments (
    user_id    INT UNSIGNED NOT NULL,
    role_id    TINYINT UNSIGNED NOT NULL,
    company_id INT UNSIGNED NOT NULL,
    PRIMARY KEY (user_id, role_id, company_id),
    CONSTRAINT fk_ura_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE,
    CONSTRAINT fk_ura_role FOREIGN KEY (role_id) REFERENCES roles (id) ON DELETE CASCADE
) DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS async_jobs (
    id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    company_id      INT UNSIGNED NULL,
    job_type        VARCHAR(96) NOT NULL,
    payload         MEDIUMTEXT NULL,
    status          ENUM('queued','claimed','running','succeeded','failed','cancelled') NOT NULL DEFAULT 'queued',
    claimed_at      DATETIME NULL,
    started_at      DATETIME NULL,
    finished_at     DATETIME NULL,
    correlation_id  VARCHAR(64) NULL,
    attempt_count   TINYINT UNSIGNED NOT NULL DEFAULT 0,
    max_attempts    TINYINT UNSIGNED NOT NULL DEFAULT 3,
    last_error      VARCHAR(512) NULL,
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_jobs_status_claimed (status, claimed_at),
    KEY idx_jobs_corr (correlation_id)
) DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Seed roles (idempotent)
INSERT IGNORE INTO roles (slug, name, scope, description) VALUES
    ('super_admin', 'Super Admin', 'global', 'Full platform administration'),
    ('company_admin', 'Company Admin', 'company', 'Company-scoped administration'),
    ('dispatcher', 'Dispatcher/Operator', 'company', 'Review loads, recommendations, approvals'),
    ('carrier', 'Carrier User', 'company', 'Self-submitted carrier profile fields'),
    ('reviewer', 'Read-Only Reviewer', 'company', 'Authorized read-only scope');

-- Seed permissions (idempotent)
INSERT IGNORE INTO permissions (code, description) VALUES
    ('users.manage', 'Manage users and roles'),
    ('company.policy.manage', 'Manage company policy'),
    ('carriers.view', 'View carriers'),
    ('carriers.edit', 'Edit carrier/equipment'),
    ('loads.create', 'Create/review loads'),
    ('approvals.outbound', 'Approve outbound messages'),
    ('documents.share', 'Share sensitive documents'),
    ('audit.view', 'View audit log'),
    ('agent.policy.change', 'Change agent policy');

-- Seed role->permission mapping for the two admin roles (idempotent)
INSERT IGNORE INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id FROM roles r JOIN permissions p
WHERE r.slug IN ('super_admin','company_admin')
  AND p.code IN ('users.manage','company.policy.manage','carriers.view','carriers.edit','loads.create','approvals.outbound','documents.share','audit.view','agent.policy.change');
