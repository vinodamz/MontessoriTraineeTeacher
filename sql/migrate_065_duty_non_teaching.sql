-- ============================================================================
-- migrate_065_duty_non_teaching.sql
--
-- Duty templates can target non-teaching staff as a group, same as teachers.
-- Idempotent — skips if the enum already contains all_non_teaching.
-- ============================================================================

SET NAMES utf8mb4;
SET sql_mode = 'STRICT_TRANS_TABLES,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION';

DROP PROCEDURE IF EXISTS pr_lg_duty_non_teaching;
DELIMITER //
CREATE PROCEDURE pr_lg_duty_non_teaching()
BEGIN
    IF EXISTS (SELECT 1 FROM information_schema.columns
               WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'staff_duty_templates'
                 AND COLUMN_NAME = 'audience'
                 AND COLUMN_TYPE NOT LIKE '%all_non_teaching%') THEN
        ALTER TABLE staff_duty_templates
            MODIFY COLUMN audience
                ENUM('all_teachers','all_non_teaching','all_staff','users')
                NOT NULL DEFAULT 'all_teachers';
    END IF;
END //
DELIMITER ;
CALL pr_lg_duty_non_teaching();
DROP PROCEDURE pr_lg_duty_non_teaching;
