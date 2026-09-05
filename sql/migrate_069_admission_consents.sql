-- ============================================================================
-- migrate_069_admission_consents.sql
--
-- Two explicit parental consents captured on the public admission form
-- (students/parent_form.php), each a checkbox the family ticks:
--
--   photo_consent       TINYINT(1) NULL — consent for the school to
--                        photograph the child and use the images in the
--                        brochure / social media.
--   field_trip_consent  TINYINT(1) NULL — consent to take the child on
--                        supervised field trips / extended-learning outings.
--
-- Three-state, matching the existing consent_given column: 1=granted,
-- 0=declined, NULL=not yet answered (existing rows before this migration).
--
-- Idempotent — safe to re-run.
-- ============================================================================

SET NAMES utf8mb4;

DROP PROCEDURE IF EXISTS pr_lg_admission_consents;
DELIMITER //
CREATE PROCEDURE pr_lg_admission_consents()
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM information_schema.columns
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'students'
          AND COLUMN_NAME = 'photo_consent'
    ) THEN
        ALTER TABLE students
            ADD COLUMN photo_consent TINYINT(1) NULL AFTER consent_date;
    END IF;

    IF NOT EXISTS (
        SELECT 1 FROM information_schema.columns
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'students'
          AND COLUMN_NAME = 'field_trip_consent'
    ) THEN
        ALTER TABLE students
            ADD COLUMN field_trip_consent TINYINT(1) NULL AFTER photo_consent;
    END IF;
END //
DELIMITER ;
CALL pr_lg_admission_consents();
DROP PROCEDURE pr_lg_admission_consents;
