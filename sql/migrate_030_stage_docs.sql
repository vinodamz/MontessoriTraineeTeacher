-- ============================================================================
-- migrate_030_stage_docs.sql
--
-- Per-stage document attachments (PDFs) for the automation. When the bot moves
-- a lead into a stage that has documents, it sends each as a WhatsApp document
-- after the stage message — e.g. "Details shared" sends the parent handbook.
--
--   wa_docs   JSON array of { "link": "...pdf", "filename": "...", "caption": "..." }
--
-- Idempotent — guarded on information_schema.
-- ============================================================================

SET NAMES utf8mb4;

DROP PROCEDURE IF EXISTS pr_lg_stage_docs;
DELIMITER //
CREATE PROCEDURE pr_lg_stage_docs()
BEGIN
    IF NOT EXISTS (SELECT 1 FROM information_schema.columns
        WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='crm_stages' AND COLUMN_NAME='wa_docs') THEN
        ALTER TABLE crm_stages ADD COLUMN wa_docs TEXT NULL AFTER wa_template_lang;
    END IF;
END //
DELIMITER ;
CALL pr_lg_stage_docs();
DROP PROCEDURE pr_lg_stage_docs;
