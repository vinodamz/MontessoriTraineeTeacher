-- ============================================================================
-- migrate_027_stage_wa_messaging.sql
--
-- Per-stage WhatsApp message config for the "Send via WhatsApp CRM" button on
-- each Admissions lead. The button reads the lead's current stage config and
-- calls WACRM's /api/whatsapp/send-to-lead (hybrid: free-text inside the 24h
-- session window, Meta-approved template outside it).
--
--   wa_text          free-text body sent when the 24h session window is open.
--                    Supports {parent_name} / {child_name} / {school_name} / {stage}.
--   wa_template      name of a Meta-approved template, used outside the window.
--   wa_template_lang template language code (default en_US).
--
-- Idempotent — guarded on information_schema so re-running is a no-op.
-- ============================================================================

SET NAMES utf8mb4;

DROP PROCEDURE IF EXISTS pr_lg_stage_wa;
DELIMITER //
CREATE PROCEDURE pr_lg_stage_wa()
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM information_schema.columns
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'crm_stages'
          AND COLUMN_NAME = 'wa_text'
    ) THEN
        ALTER TABLE crm_stages
            ADD COLUMN wa_text TEXT NULL AFTER probability;
    END IF;

    IF NOT EXISTS (
        SELECT 1 FROM information_schema.columns
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'crm_stages'
          AND COLUMN_NAME = 'wa_template'
    ) THEN
        ALTER TABLE crm_stages
            ADD COLUMN wa_template VARCHAR(80) NULL AFTER wa_text;
    END IF;

    IF NOT EXISTS (
        SELECT 1 FROM information_schema.columns
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'crm_stages'
          AND COLUMN_NAME = 'wa_template_lang'
    ) THEN
        ALTER TABLE crm_stages
            ADD COLUMN wa_template_lang VARCHAR(12) NULL DEFAULT 'en_US' AFTER wa_template;
    END IF;
END //
DELIMITER ;
CALL pr_lg_stage_wa();
DROP PROCEDURE pr_lg_stage_wa;
