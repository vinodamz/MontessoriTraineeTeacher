-- ============================================================================
-- migrate_026_external_apps.sql
--
-- Adds two new module flags so the existing module-access UI in /admin.php
-- can grant per-user access to external integrations:
--
--   - wacrm  → WhatsApp CRM hosted elsewhere
--   - n8n    → self-hosted automation workflows
--
-- The actual landing URLs live in app_settings (keys wacrm_url, n8n_url)
-- so admins can change them without a code deploy. Default empty.
--
-- Idempotent — guarded on information_schema + ON DUPLICATE KEY UPDATE.
-- ============================================================================

SET NAMES utf8mb4;

DROP PROCEDURE IF EXISTS pr_lg_external_apps;
DELIMITER //
CREATE PROCEDURE pr_lg_external_apps()
BEGIN
    -- 1. Widen users.modules SET to include the new keys. Re-listing every
    --    existing value matters: MODIFY replaces the SET definition, so
    --    leaving anything out silently drops it for existing rows.
    IF EXISTS (
        SELECT 1 FROM information_schema.columns
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users'
          AND COLUMN_NAME = 'modules' AND DATA_TYPE = 'set'
          AND COLUMN_TYPE NOT LIKE '%wacrm%'
    ) THEN
        ALTER TABLE users
            MODIFY modules
              SET('tasks','montessori','students','crm','recruitment','staff','expenses','fees','logbook','inventory','wacrm','n8n')
              NOT NULL DEFAULT '';
    END IF;

    -- 2. Seed empty URL settings so the admin "App settings" page lists them.
    --    INSERT IGNORE so re-running won't clobber values an admin already set.
    INSERT IGNORE INTO app_settings (setting_key, setting_value) VALUES
        ('wacrm_url', ''),
        ('n8n_url',   '');
END //
DELIMITER ;
CALL pr_lg_external_apps();
DROP PROCEDURE pr_lg_external_apps;
