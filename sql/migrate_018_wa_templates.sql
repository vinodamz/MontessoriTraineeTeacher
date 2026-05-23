-- ============================================================================
-- migrate_018_wa_templates.sql
--
-- Adds crm_wa_templates — pre-written WhatsApp messages an admin manages
-- from /crm/wa_templates.php. The kanban / leads list / detail page's
-- WhatsApp pill opens a small picker; selecting a template substitutes
-- {parent_name}, {child_name}, {school_name}, {stage} and opens wa.me
-- with the message pre-filled in the input box so the admin can edit
-- before sending.
--
-- Idempotent — guarded on information_schema.
-- ============================================================================

SET NAMES utf8mb4;
SET sql_mode = 'STRICT_TRANS_TABLES,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION';

DROP PROCEDURE IF EXISTS pr_lg_wa_templates;
DELIMITER //
CREATE PROCEDURE pr_lg_wa_templates()
BEGIN
    IF NOT EXISTS (SELECT 1 FROM information_schema.tables
                   WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='crm_wa_templates') THEN
        CREATE TABLE crm_wa_templates (
            id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
            name          VARCHAR(80)  NOT NULL,
            body          TEXT         NOT NULL,
            display_order INT          NOT NULL DEFAULT 0,
            is_active     TINYINT(1)   NOT NULL DEFAULT 1,
            created_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP
                          ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_wat_order (display_order)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

        INSERT INTO crm_wa_templates (name, body, display_order) VALUES
            ('Welcome',
             'Hi {parent_name}, thanks for reaching out to {school_name} about admission for {child_name}. We''d love to share more about our programmes. When would be a good time for a quick chat?',
             10),
            ('Visit invite',
             'Hi {parent_name}, would you like to bring {child_name} for a school visit? We''re open Mon–Sat 9am–4pm. Reply with a preferred day and we''ll keep a slot ready.',
             20),
            ('Fee structure',
             'Hi {parent_name}, sharing our fee structure for {child_name}. Happy to walk you through the options on a call — when works for you?',
             30),
            ('Follow-up',
             'Hi {parent_name}, just following up on {child_name}''s admission enquiry. Any questions we can help with?',
             40),
            ('Visit reminder',
             'Hi {parent_name}, friendly reminder for your scheduled school visit. Looking forward to meeting you and {child_name}!',
             50),
            ('Documents needed',
             'Hi {parent_name}, to complete {child_name}''s admission we''ll need: birth certificate, vaccination record, and 2 passport-size photos. You can WhatsApp them here. Thank you!',
             60);
    END IF;
END //
DELIMITER ;
CALL pr_lg_wa_templates();
DROP PROCEDURE pr_lg_wa_templates;
