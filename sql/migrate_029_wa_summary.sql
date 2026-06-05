-- ============================================================================
-- migrate_029_wa_summary.sql
--
-- "WA Conversation Summary" — a short, AI-maintained summary of each lead's
-- WhatsApp conversation, kept on the lead so staff see the gist without
-- reading the whole thread. The bot updates it via crm/lead_summary.php.
--
--   wa_summary      the latest summary text
--   wa_summary_at   when it was last updated
--
-- Idempotent — guarded on information_schema.
-- ============================================================================

SET NAMES utf8mb4;

DROP PROCEDURE IF EXISTS pr_lg_wa_summary;
DELIMITER //
CREATE PROCEDURE pr_lg_wa_summary()
BEGIN
    IF NOT EXISTS (SELECT 1 FROM information_schema.columns
        WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='inquiry_families' AND COLUMN_NAME='wa_summary') THEN
        ALTER TABLE inquiry_families ADD COLUMN wa_summary TEXT NULL AFTER notes;
    END IF;
    IF NOT EXISTS (SELECT 1 FROM information_schema.columns
        WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='inquiry_families' AND COLUMN_NAME='wa_summary_at') THEN
        ALTER TABLE inquiry_families ADD COLUMN wa_summary_at DATETIME NULL AFTER wa_summary;
    END IF;
END //
DELIMITER ;
CALL pr_lg_wa_summary();
DROP PROCEDURE pr_lg_wa_summary;
