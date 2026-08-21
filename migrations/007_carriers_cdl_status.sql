-- ROUTLAW carriers.cdl_status (Phase 4 T10.0; FRD §12.4 logical schema vs migration 002 gap).
-- MariaDB has no ADD COLUMN IF NOT EXISTS; guard via INFORMATION_SCHEMA for idempotency.
-- Tenant-scoped from day one: carriers already carry company_id (FR-042, BR-020).
SET NAMES utf8mb4;

SET @col_exists = (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'carriers'
      AND COLUMN_NAME = 'cdl_status'
);

SET @sql = IF(
    @col_exists = 0,
    "ALTER TABLE carriers ADD COLUMN cdl_status ENUM('unknown','non_cdl','cdl_a','cdl_b','cdl_c') NOT NULL DEFAULT 'unknown' AFTER mc_number",
    'SELECT 1'
);

PREPARE stmt_cdl FROM @sql;
EXECUTE stmt_cdl;
DEALLOCATE PREPARE stmt_cdl;
