-- ============================================================================
-- migrate_025_student_extras.sql
--
-- Adds the "important info" fields the front office actually needs:
--   - students.permanent_address  — the family's home town address (the
--     existing home_address is the local/current one).
--   - student_parents.photo_path  — per-parent photo, served from
--     /uploads/student_photos/. Child photos already live on students.photo_path.
--
-- Idempotent.
-- ============================================================================

SET NAMES utf8mb4;

DROP PROCEDURE IF EXISTS pr_lg_student_extras;
DELIMITER //
CREATE PROCEDURE pr_lg_student_extras()
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM information_schema.columns
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'students'
          AND COLUMN_NAME = 'permanent_address'
    ) THEN
        ALTER TABLE students
            ADD COLUMN permanent_address TEXT NULL AFTER home_address;
    END IF;

    IF NOT EXISTS (
        SELECT 1 FROM information_schema.columns
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'student_parents'
          AND COLUMN_NAME = 'photo_path'
    ) THEN
        ALTER TABLE student_parents
            ADD COLUMN photo_path VARCHAR(255) NULL AFTER address;
    END IF;
END //
DELIMITER ;
CALL pr_lg_student_extras();
DROP PROCEDURE pr_lg_student_extras;
