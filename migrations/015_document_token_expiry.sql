-- ROUTLAW document token expiry (Phase 5 T16.4; FR-025/026).
-- Adds expires_at to documents (download_token expiry window). Idempotent.
SET NAMES utf8mb4;

ALTER TABLE documents
    ADD COLUMN IF NOT EXISTS expires_at DATETIME NULL AFTER download_uses;
