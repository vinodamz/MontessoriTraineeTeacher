-- ============================================================================
-- migrate_066_duty_adhoc.sql
--
-- Adhoc duties with a start/end window, optional weekdays, and a repeat
-- slot (once / each day / each week / each month). Recurring daily/weekly/
-- monthly templates may also carry a date window.
-- Idempotent.
-- ============================================================================

SET NAMES utf8mb4;
SET sql_mode = 'STRICT_TRANS_TABLES,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION';

DROP PROCEDURE IF EXISTS pr_lg_duty_adhoc;
DELIMITER //
CREATE PROCEDURE pr_lg_duty_adhoc()
BEGIN
    IF EXISTS (SELECT 1 FROM information_schema.columns
               WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'staff_duty_templates'
                 AND COLUMN_NAME = 'frequency'
                 AND COLUMN_TYPE NOT LIKE '%adhoc%') THEN
        ALTER TABLE staff_duty_templates
            MODIFY COLUMN frequency
                ENUM('daily','weekly','monthly','adhoc') NOT NULL DEFAULT 'daily';
    END IF;

    IF EXISTS (SELECT 1 FROM information_schema.columns
               WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'staff_duty_items'
                 AND COLUMN_NAME = 'frequency'
                 AND COLUMN_TYPE NOT LIKE '%adhoc%') THEN
        ALTER TABLE staff_duty_items
            MODIFY COLUMN frequency
                ENUM('daily','weekly','monthly','adhoc') NOT NULL;
    END IF;

    IF EXISTS (SELECT 1 FROM information_schema.columns
               WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'staff_duty_period_notes'
                 AND COLUMN_NAME = 'frequency'
                 AND COLUMN_TYPE NOT LIKE '%adhoc%') THEN
        ALTER TABLE staff_duty_period_notes
            MODIFY COLUMN frequency
                ENUM('daily','weekly','monthly','adhoc') NOT NULL;
    END IF;

    IF NOT EXISTS (SELECT 1 FROM information_schema.columns
                   WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'staff_duty_templates'
                     AND COLUMN_NAME = 'starts_on') THEN
        ALTER TABLE staff_duty_templates
            ADD COLUMN starts_on DATE NULL AFTER audience;
    END IF;

    IF NOT EXISTS (SELECT 1 FROM information_schema.columns
                   WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'staff_duty_templates'
                     AND COLUMN_NAME = 'ends_on') THEN
        ALTER TABLE staff_duty_templates
            ADD COLUMN ends_on DATE NULL AFTER starts_on;
    END IF;

    IF NOT EXISTS (SELECT 1 FROM information_schema.columns
                   WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'staff_duty_templates'
                     AND COLUMN_NAME = 'days_mask') THEN
        ALTER TABLE staff_duty_templates
            ADD COLUMN days_mask TINYINT UNSIGNED NOT NULL DEFAULT 127 AFTER ends_on;
    END IF;

    IF NOT EXISTS (SELECT 1 FROM information_schema.columns
                   WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'staff_duty_templates'
                     AND COLUMN_NAME = 'repeat_as') THEN
        ALTER TABLE staff_duty_templates
            ADD COLUMN repeat_as ENUM('once','daily','weekly','monthly')
                NOT NULL DEFAULT 'once' AFTER days_mask;
    END IF;
END //
DELIMITER ;
CALL pr_lg_duty_adhoc();
DROP PROCEDURE pr_lg_duty_adhoc;
