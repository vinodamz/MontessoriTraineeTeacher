-- ============================================================================
-- migrate_040_materials_module.sql
--
-- Adds 'materials' to the users.modules SET so the new Material Condition
-- Audit module can be granted to a user. Without this, saving a user with the
-- Materials box ticked silently truncates the value (SET rejects unknown
-- members) and the grant is lost.
--
-- Idempotent: only rewrites the column when 'materials' isn't already a member.
-- ============================================================================

SET NAMES utf8mb4;

DROP PROCEDURE IF EXISTS pr_lg_materials_module;
DELIMITER //
CREATE PROCEDURE pr_lg_materials_module()
BEGIN
    IF (
        SELECT LOCATE('''materials''', COLUMN_TYPE) = 0
        FROM information_schema.columns
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = 'modules'
    ) THEN
        ALTER TABLE users MODIFY COLUMN modules
            SET('tasks','montessori','students','crm','recruitment','staff',
                'expenses','fees','logbook','inventory','materials','wacrm','n8n')
            NOT NULL DEFAULT '';
    END IF;
END //
DELIMITER ;
CALL pr_lg_materials_module();
DROP PROCEDURE pr_lg_materials_module;
