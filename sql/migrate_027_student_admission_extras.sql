-- ============================================================================
-- migrate_027_student_admission_extras.sql
--
-- Front-office requested fields for the students roster. These already
-- exist on the school's paper/Excel admission forms; this migration
-- gives them a typed home in the database so the xlsx round-trip on
-- /students/export.php and /students/import.php can carry them.
--
-- Added on students:
--   place_of_birth   VARCHAR(120)  — child's birthplace
--   section          VARCHAR(20)   — classroom section (A/B/C/D); kept
--                                    as VARCHAR so the section list can
--                                    grow by editing STUDENT_SECTIONS in
--                                    includes/functions.php, no schema
--                                    change required.
--   admission_type   ENUM('new','old')  — fresh admit vs returning child
--   consent_given    TINYINT(1) NULL    — parental media/data consent,
--                                         three-state (1=yes/0=no/NULL=unknown)
--   consent_date     DATE NULL          — when consent was recorded
--   transport        ENUM('own','cab','bus','walk') — how the child
--                                                     reaches school
--
-- Idempotent — safe to re-run.
-- ============================================================================

SET NAMES utf8mb4;

DROP PROCEDURE IF EXISTS pr_lg_student_admission_extras;
DELIMITER //
CREATE PROCEDURE pr_lg_student_admission_extras()
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM information_schema.columns
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'students'
          AND COLUMN_NAME = 'place_of_birth'
    ) THEN
        ALTER TABLE students
            ADD COLUMN place_of_birth VARCHAR(120) NULL AFTER dob;
    END IF;

    IF NOT EXISTS (
        SELECT 1 FROM information_schema.columns
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'students'
          AND COLUMN_NAME = 'section'
    ) THEN
        ALTER TABLE students
            ADD COLUMN section VARCHAR(20) NULL AFTER grade;
    END IF;

    IF NOT EXISTS (
        SELECT 1 FROM information_schema.statistics
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'students'
          AND INDEX_NAME = 'idx_students_section'
    ) THEN
        ALTER TABLE students
            ADD KEY idx_students_section (section);
    END IF;

    IF NOT EXISTS (
        SELECT 1 FROM information_schema.columns
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'students'
          AND COLUMN_NAME = 'admission_type'
    ) THEN
        ALTER TABLE students
            ADD COLUMN admission_type ENUM('new','old') NULL AFTER joining_date;
    END IF;

    IF NOT EXISTS (
        SELECT 1 FROM information_schema.columns
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'students'
          AND COLUMN_NAME = 'consent_given'
    ) THEN
        ALTER TABLE students
            ADD COLUMN consent_given TINYINT(1) NULL AFTER notes,
            ADD COLUMN consent_date  DATE       NULL AFTER consent_given;
    END IF;

    IF NOT EXISTS (
        SELECT 1 FROM information_schema.columns
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'students'
          AND COLUMN_NAME = 'transport'
    ) THEN
        ALTER TABLE students
            ADD COLUMN transport ENUM('own','cab','bus','walk') NULL AFTER consent_date;
    END IF;
END //
DELIMITER ;
CALL pr_lg_student_admission_extras();
DROP PROCEDURE pr_lg_student_admission_extras;
