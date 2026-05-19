-- ============================================================================
-- migrate_006_academic_year.sql
--
-- Adds academic-year handling and structured withdrawal-reason capture to the
-- students table:
--
--   academic_year       — current enrolled year, e.g. '2025-26', '2026-27'.
--   enrollment_status   — enrolled / promoted / withdrawn / graduated / on_break
--   withdrawal_date     — when they left (if applicable)
--   withdrawal_reason   — short code, app-side enum (relocated/financial/...)
--   withdrawal_notes    — free-text detail
--
-- All existing rows are backfilled to academic_year='2025-26' and
-- enrollment_status='enrolled' so they appear in the previous-year view by
-- default. Year-end triage happens via /students/yearend.php.
--
-- Idempotent — every step is guarded.
-- ============================================================================

SET NAMES utf8mb4;
SET sql_mode = 'STRICT_TRANS_TABLES,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION';

DROP PROCEDURE IF EXISTS pr_lg_academic_year;
DELIMITER //
CREATE PROCEDURE pr_lg_academic_year()
BEGIN
    -- academic_year
    IF NOT EXISTS (SELECT 1 FROM information_schema.columns
                   WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='students' AND COLUMN_NAME='academic_year') THEN
        ALTER TABLE students ADD COLUMN academic_year VARCHAR(9) NULL AFTER teacher_id;
        ALTER TABLE students ADD INDEX idx_students_year (academic_year);
        -- Backfill existing rows to the 2025-26 year so the new filter doesn't
        -- accidentally hide them. The user will run /students/yearend.php to
        -- promote / withdraw / graduate each one.
        UPDATE students SET academic_year = '2025-26' WHERE academic_year IS NULL;
    END IF;

    -- enrollment_status
    IF NOT EXISTS (SELECT 1 FROM information_schema.columns
                   WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='students' AND COLUMN_NAME='enrollment_status') THEN
        ALTER TABLE students
            ADD COLUMN enrollment_status ENUM('enrolled','promoted','withdrawn','graduated','on_break')
                NOT NULL DEFAULT 'enrolled' AFTER academic_year;
        ALTER TABLE students ADD INDEX idx_students_status (enrollment_status);
    END IF;

    -- withdrawal_date
    IF NOT EXISTS (SELECT 1 FROM information_schema.columns
                   WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='students' AND COLUMN_NAME='withdrawal_date') THEN
        ALTER TABLE students ADD COLUMN withdrawal_date DATE NULL AFTER enrollment_status;
    END IF;

    -- withdrawal_reason
    IF NOT EXISTS (SELECT 1 FROM information_schema.columns
                   WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='students' AND COLUMN_NAME='withdrawal_reason') THEN
        ALTER TABLE students ADD COLUMN withdrawal_reason VARCHAR(40) NULL AFTER withdrawal_date;
        ALTER TABLE students ADD INDEX idx_students_withdrawal_reason (withdrawal_reason);
    END IF;

    -- withdrawal_notes
    IF NOT EXISTS (SELECT 1 FROM information_schema.columns
                   WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='students' AND COLUMN_NAME='withdrawal_notes') THEN
        ALTER TABLE students ADD COLUMN withdrawal_notes TEXT NULL AFTER withdrawal_reason;
    END IF;
END //
DELIMITER ;
CALL pr_lg_academic_year();
DROP PROCEDURE pr_lg_academic_year;
