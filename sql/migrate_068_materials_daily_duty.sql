-- ============================================================================
-- migrate_068_materials_daily_duty.sql
--
-- Regular materials walk as an assignable duty:
--
--   1. staff_duty_templates.action_key — a duty can open a real workflow.
--      'materials_check' sends the assignee to today's blank materials sheet.
--   2. mm_daily_media — photo / video / voice memo on a daily check row.
--      Same files dir as monthly media. CASCADE with the daily check, so a
--      new calendar day has zero rows (clean sheet) and yesterday's evidence
--      stays forever.
--
-- Idempotent.
-- ============================================================================

SET NAMES utf8mb4;
SET sql_mode = 'STRICT_TRANS_TABLES,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION';

DROP PROCEDURE IF EXISTS pr_lg_materials_daily_duty;
DELIMITER //
CREATE PROCEDURE pr_lg_materials_daily_duty()
BEGIN
    IF NOT EXISTS (SELECT 1 FROM information_schema.columns
                   WHERE TABLE_SCHEMA = DATABASE()
                     AND TABLE_NAME = 'staff_duty_templates'
                     AND COLUMN_NAME = 'action_key') THEN
        ALTER TABLE staff_duty_templates
            ADD COLUMN action_key VARCHAR(40) NOT NULL DEFAULT '' AFTER notes,
            ADD KEY idx_duty_tpl_action (action_key, is_active);
    END IF;

    IF NOT EXISTS (SELECT 1 FROM information_schema.tables
                   WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'mm_daily_media') THEN
        CREATE TABLE mm_daily_media (
            id                  INT UNSIGNED NOT NULL AUTO_INCREMENT,
            check_id            INT UNSIGNED NOT NULL,
            kind                ENUM('photo','video','audio') NOT NULL DEFAULT 'photo',
            original_filename   VARCHAR(255) NOT NULL,
            stored_filename     VARCHAR(255) NOT NULL,
            mime_type           VARCHAR(100) NOT NULL,
            size_bytes          INT UNSIGNED NOT NULL DEFAULT 0,
            uploaded_by_user_id INT UNSIGNED NULL,
            uploaded_at         DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uq_mm_daily_media_stored (stored_filename),
            KEY idx_mm_daily_media_check (check_id),
            CONSTRAINT fk_mm_daily_media_check FOREIGN KEY (check_id)
                REFERENCES mm_daily_checks(id) ON DELETE CASCADE,
            CONSTRAINT fk_mm_daily_media_user FOREIGN KEY (uploaded_by_user_id)
                REFERENCES users(id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    END IF;
END //
DELIMITER ;
CALL pr_lg_materials_daily_duty();
DROP PROCEDURE pr_lg_materials_daily_duty;
