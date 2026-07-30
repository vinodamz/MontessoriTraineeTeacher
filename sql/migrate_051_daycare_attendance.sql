-- ============================================================================
-- migrate_051_daycare_attendance.sql
--
-- Daycare attendance module: a standalone check-in/check-out screen for daycare
-- children and staff, run by a restricted "track" user who can reach nothing
-- else in the app.
--
-- Two changes, both additive:
--
--   1. users.modules gains 'daycare'. Give a user ONLY this module and the app
--      already sends them straight into it on login (index.php redirects
--      single-module users), so the track user sees the attendance screen and
--      nothing more — no new auth mechanism needed.
--
--   2. attendance gains check_in / check_out TIME columns. Student attendance
--      only recorded a status; daycare needs clock times. Extending the existing
--      table rather than adding a daycare-specific one keeps ONE source of truth
--      for a child's attendance, so the existing students/attendance.php views
--      and fee/attendance reports still see daycare days. Both columns are NULL,
--      so every existing row and every page that ignores them is unaffected.
--
-- Staff need nothing: staff_attendance already carries check_in / check_out
-- (migrate_012), so the module writes there for staff.
--
-- Idempotent — information_schema guards throughout.
-- ============================================================================

SET NAMES utf8mb4;
SET sql_mode = 'STRICT_TRANS_TABLES,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION';

DROP PROCEDURE IF EXISTS pr_lg_daycare_attendance;
DELIMITER //
CREATE PROCEDURE pr_lg_daycare_attendance()
BEGIN
    -- 1. Add the module. Appending keeps every existing SET member at its
    --    current position, so stored module sets aren't remapped.
    IF (
        SELECT LOCATE('''daycare''', COLUMN_TYPE) = 0
        FROM information_schema.columns
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = 'modules'
    ) THEN
        ALTER TABLE users MODIFY COLUMN modules
            SET('tasks','montessori','students','crm','recruitment','staff',
                'expenses','fees','logbook','inventory','materials','wacrm','n8n',
                'daycare')
            NOT NULL DEFAULT '';
    END IF;

    -- 2. Clock times on student attendance.
    IF NOT EXISTS (
        SELECT 1 FROM information_schema.columns
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'attendance'
          AND COLUMN_NAME = 'check_in'
    ) THEN
        ALTER TABLE attendance
            ADD COLUMN check_in  TIME NULL AFTER status,
            ADD COLUMN check_out TIME NULL AFTER check_in;
    END IF;
END //
DELIMITER ;
CALL pr_lg_daycare_attendance();
DROP PROCEDURE pr_lg_daycare_attendance;
