-- ============================================================================
-- migrate_045_staff_doc_kinds.sql
--
-- Extends staff_documents.kind with two new categories:
--   'experience' — previous-experience / relieving certificates from prior jobs
--   'photo'      — a passport-style profile photo (rendered as the staff avatar)
--
-- These sit alongside the existing id_proof / contract / certification / etc.
-- so the per-staff document uploader can accept them. New values are appended
-- in display order.
--
-- Idempotent — guards on COLUMN_TYPE so re-running is a no-op.
-- ============================================================================

SET NAMES utf8mb4;
SET sql_mode = 'STRICT_TRANS_TABLES,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION';

DROP PROCEDURE IF EXISTS pr_lg_staff_doc_kinds;
DELIMITER //
CREATE PROCEDURE pr_lg_staff_doc_kinds()
BEGIN
    -- Only widen the ENUM when it doesn't already carry the new values, so a
    -- second run against an already-migrated DB does nothing.
    IF EXISTS (
        SELECT 1 FROM information_schema.columns
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME   = 'staff_documents'
          AND COLUMN_NAME  = 'kind'
          AND COLUMN_TYPE  NOT LIKE '%experience%'
    ) THEN
        ALTER TABLE staff_documents
            MODIFY kind ENUM(
                'id_proof','contract','certification','experience',
                'medical','reference','photo','other'
            ) NOT NULL DEFAULT 'other';
    END IF;
END //
DELIMITER ;
CALL pr_lg_staff_doc_kinds();
DROP PROCEDURE pr_lg_staff_doc_kinds;
