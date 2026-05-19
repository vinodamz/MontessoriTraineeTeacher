-- ============================================================================
-- migrate_007_app_settings.sql
--
-- Adds a key-value `app_settings` table so admins can rename the app, tweak
-- the short_name, etc. without touching includes/config.php on the server
-- (which is gitignored and requires cPanel File Manager access).
--
-- Seeds the new rename: 'Trainee Teacher Assessment' → 'Little Graduates'.
-- INSERT IGNORE keeps existing values intact on re-run.
--
-- Idempotent — uses information_schema guards.
-- ============================================================================

SET NAMES utf8mb4;
SET sql_mode = 'STRICT_TRANS_TABLES,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION';

DROP PROCEDURE IF EXISTS pr_lg_app_settings;
DELIMITER //
CREATE PROCEDURE pr_lg_app_settings()
BEGIN
    IF NOT EXISTS (SELECT 1 FROM information_schema.tables
                   WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='app_settings') THEN
        CREATE TABLE app_settings (
            setting_key   VARCHAR(60)  NOT NULL,
            setting_value TEXT         NULL,
            updated_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (setting_key)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

        INSERT INTO app_settings (setting_key, setting_value) VALUES
            ('app_name',       'Little Graduates'),
            ('app_short_name', 'LG');
    END IF;
END //
DELIMITER ;
CALL pr_lg_app_settings();
DROP PROCEDURE pr_lg_app_settings;
