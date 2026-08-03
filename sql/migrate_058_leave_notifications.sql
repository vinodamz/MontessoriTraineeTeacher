-- ============================================================================
-- migrate_058_leave_notifications.sql
--
-- Leave requests become a conversation: staff apply, admins are told, and the
-- decision comes back to the person who asked.
--
-- Adds a 'staff' notification category, with a preference that defaults ON.
-- Defaulting to off would mean the first person to apply for leave after this
-- deploys gets silence from every admin, which is worse than one extra bell.
--
-- Decisions are deliberately NOT sent under this category — they go out as
-- 'system', which _notify_category_enabled() always allows. Being told your
-- leave was rejected changes your pay and your attendance record; it should
-- not be possible to opt out of hearing it by muting a category.
--
-- Idempotent — guards on the enum already containing the value, and on the
-- preference column already existing.
-- ============================================================================

SET NAMES utf8mb4;
SET sql_mode = 'STRICT_TRANS_TABLES,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION';

DROP PROCEDURE IF EXISTS pr_lg_leave_notifications;
DELIMITER //
CREATE PROCEDURE pr_lg_leave_notifications()
BEGIN
    IF NOT EXISTS (SELECT 1 FROM information_schema.columns
                   WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='notifications'
                     AND COLUMN_NAME='category'
                     AND COLUMN_TYPE LIKE '%staff%') THEN
        ALTER TABLE notifications
            MODIFY COLUMN category
                ENUM('tasks','attendance','fees','students','staff','system')
                NOT NULL DEFAULT 'system';
    END IF;

    IF NOT EXISTS (SELECT 1 FROM information_schema.columns
                   WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='notification_preferences'
                     AND COLUMN_NAME='staff_enabled') THEN
        ALTER TABLE notification_preferences
            ADD COLUMN staff_enabled TINYINT(1) NOT NULL DEFAULT 1 AFTER students_enabled;
    END IF;
END //
DELIMITER ;
CALL pr_lg_leave_notifications();
DROP PROCEDURE pr_lg_leave_notifications;
