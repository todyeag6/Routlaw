-- ROUTLAW message_drafts: sent tracking (Phase 5 T14.4; FR-021).
-- Tracks when a draft transitions to sent via OutboundService (the only send path).
-- MariaDB-safe: no CREATE DATABASE/USE; IF NOT EXISTS on the column.
SET NAMES utf8mb4;

ALTER TABLE message_drafts
    ADD COLUMN IF NOT EXISTS sent_at DATETIME NULL AFTER status,
    ADD COLUMN IF NOT EXISTS sent_by INT UNSIGNED NULL AFTER sent_at;
