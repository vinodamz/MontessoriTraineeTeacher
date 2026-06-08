-- ============================================================================
-- migrate_029_student_intake.sql
--
-- Extends the students table to support self-service parent intake:
--   1. New enrollment_status value 'intake_pending' — covers draft rows
--      created when an admin issues a parent-form link to a brand-new
--      family. Such rows are hidden from the default students list and
--      visible only under the "Intake review" filter.
--   2. New column students.intake_approved_at — stamped when an admin
--      reviews the parent's submission and flips the status to 'enrolled'.
--      This is the "Added on …" timestamp on the student profile.
--
-- Idempotent — safe to re-run.
-- ============================================================================

SET NAMES utf8mb4;

DROP PROCEDURE IF EXISTS pr_lg_student_intake;
DELIMITER //
CREATE PROCEDURE pr_lg_student_intake()
BEGIN
    DECLARE current_enum TEXT;

    -- Add intake_approved_at if missing.
    IF NOT EXISTS (
        SELECT 1 FROM information_schema.columns
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'students'
          AND COLUMN_NAME = 'intake_approved_at'
    ) THEN
        ALTER TABLE students
            ADD COLUMN intake_approved_at DATETIME NULL AFTER enrollment_status;
    END IF;

    -- Widen the enrollment_status ENUM to include 'intake_pending'.
    SELECT COLUMN_TYPE INTO current_enum
    FROM   information_schema.columns
    WHERE  TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'students'
      AND  COLUMN_NAME = 'enrollment_status';

    IF current_enum IS NOT NULL AND INSTR(current_enum, "'intake_pending'") = 0 THEN
        ALTER TABLE students
            MODIFY COLUMN enrollment_status
              ENUM('enrolled','promoted','withdrawn','graduated','on_break','intake_pending')
              NOT NULL DEFAULT 'enrolled';
    END IF;
END //
DELIMITER ;
CALL pr_lg_student_intake();
DROP PROCEDURE pr_lg_student_intake;
