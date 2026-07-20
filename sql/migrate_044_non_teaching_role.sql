-- ============================================================================
-- migrate_044_non_teaching_role.sql
--
-- Adds a third value to users.role: 'non_teaching'.
--
-- Context: 'teacher' has always doubled as "classroom teacher" — it drives the
-- Assessment / My Class daily loop in the nav and landing pages. Non-teaching
-- support staff (aaya: cleaning, childcare, daycare) are still staff — they
-- appear in the Staff roster and get self-service attendance / leave / payslip
-- — but they should never see the classroom-teacher surface. A distinct role
-- lets the UI branch cleanly instead of overloading 'teacher'.
--
-- Idempotent — guards on COLUMN_TYPE so re-running is a no-op.
-- ============================================================================

SET NAMES utf8mb4;
SET sql_mode = 'STRICT_TRANS_TABLES,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION';

DROP PROCEDURE IF EXISTS pr_lg_non_teaching_role;
DELIMITER //
CREATE PROCEDURE pr_lg_non_teaching_role()
BEGIN
    -- Extend the role ENUM to include 'non_teaching'. Only alter when the
    -- current column definition doesn't already carry the value, so re-running
    -- on an already-migrated DB does nothing.
    IF EXISTS (
        SELECT 1 FROM information_schema.columns
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME   = 'users'
          AND COLUMN_NAME  = 'role'
          AND COLUMN_TYPE  NOT LIKE '%non_teaching%'
    ) THEN
        ALTER TABLE users
            MODIFY role ENUM('teacher','admin','non_teaching')
            NOT NULL DEFAULT 'teacher';
    END IF;
END //
DELIMITER ;
CALL pr_lg_non_teaching_role();
DROP PROCEDURE pr_lg_non_teaching_role;
