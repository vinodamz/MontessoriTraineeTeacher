-- ============================================================================
-- migrate_020_fees_module.sql
--
-- Adds the Fees module to users.modules SET so it shows as a separate
-- app on the dashboard with its own nav link. Existing fee pages
-- (config, guide, calculator) now live under this module.
--
-- IMPORTANT: the SET list below must include EVERY existing module
-- value to avoid truncating rows that hold them. See migrate_013
-- for the lesson learned.
--
-- Idempotent.
-- ============================================================================

SET NAMES utf8mb4;
SET sql_mode = 'STRICT_TRANS_TABLES,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION';

DROP PROCEDURE IF EXISTS pr_lg_fees_module;
DELIMITER //
CREATE PROCEDURE pr_lg_fees_module()
BEGIN
    IF EXISTS (
        SELECT 1 FROM information_schema.columns
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME   = 'users'
          AND COLUMN_NAME  = 'modules'
          AND DATA_TYPE    = 'set'
          AND COLUMN_TYPE  NOT LIKE '%fees%'
    ) THEN
        ALTER TABLE users
            MODIFY modules
              SET('tasks','montessori','students','crm','recruitment','staff','expenses','fees')
              NOT NULL DEFAULT '';
    END IF;
END //
DELIMITER ;
CALL pr_lg_fees_module();
DROP PROCEDURE pr_lg_fees_module;
