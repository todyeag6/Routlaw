-- ROUTLAW document permissions (Phase 5 T16; FR-025/026).
-- Adds documents.upload (grants company_admin) — documents.share already seeded in 000.
-- Idempotent: INSERT IGNORE. role_permissions is global per role.
SET NAMES utf8mb4;

INSERT IGNORE INTO permissions (code, description) VALUES
    ('documents.upload', 'Upload private documents');

INSERT IGNORE INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id
FROM roles r JOIN permissions p
WHERE r.slug = 'company_admin'
  AND p.code = 'documents.upload';
