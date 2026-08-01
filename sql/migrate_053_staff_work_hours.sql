-- ============================================================================
-- migrate_053_staff_work_hours.sql
--
-- Per-staff working hours, so "late" means late for *that person's* shift.
--
-- The school runs shifts: an aaya on the early daycare shift starts at 07:30,
-- a teacher at 09:00. Until now the only lateness rule in the app was a single
-- hardcoded 09:15 cutoff in staff/attendance.php, which marked the early-shift
-- person on time at 09:10 (45 minutes late) and would have marked a late-shift
-- person late before their day even began.
--
-- Two columns on staff_profiles:
--
--   work_start / work_end  TIME NULL
--
--     NULL means "no shift defined". Lateness is then never computed — a blank
--     shift must never manufacture a late mark against someone, so those staff
--     are recorded 'present' exactly as before.
--
-- These two columns are ADMIN-ONLY: staff_profile_save() (used by the
-- self-service /staff/profile.php and the public /staff/apply.php token form)
-- deliberately does not write them, so nobody can shift their own start time
-- to cover an arrival. They are written only by staff_shift_save(), which is
-- reachable from the admin-only /staff/shifts.php.
--
-- Plus one setting: the grace period in minutes. The school's rule is 5 minutes
-- (a 09:00 start starts counting late at 09:05), but it lives in app_settings
-- rather than in code so it can be changed without a deploy.
--
-- Idempotent — information_schema guards + INSERT IGNORE.
-- ============================================================================

SET NAMES utf8mb4;
SET sql_mode = 'STRICT_TRANS_TABLES,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION';

DROP PROCEDURE IF EXISTS pr_lg_staff_work_hours;
DELIMITER //
CREATE PROCEDURE pr_lg_staff_work_hours()
BEGIN
    -- staff_profiles is created by migrate_046; guard in case migrations are
    -- run out of order on an old copy of the database.
    IF EXISTS (SELECT 1 FROM information_schema.tables
               WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'staff_profiles') THEN

        IF NOT EXISTS (
            SELECT 1 FROM information_schema.columns
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'staff_profiles'
              AND COLUMN_NAME = 'work_start'
        ) THEN
            ALTER TABLE staff_profiles
                ADD COLUMN work_start TIME NULL AFTER previous_employer,
                ADD COLUMN work_end   TIME NULL AFTER work_start;
        END IF;

    END IF;
END //
DELIMITER ;
CALL pr_lg_staff_work_hours();
DROP PROCEDURE pr_lg_staff_work_hours;

-- Grace period in minutes. INSERT IGNORE so a school that has already tuned
-- the value keeps it when this migration is re-run.
INSERT IGNORE INTO app_settings (setting_key, setting_value) VALUES
    ('staff_late_grace_minutes', '5');
