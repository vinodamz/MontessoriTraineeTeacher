-- ============================================================================
-- migrate_063_survey_definitions.sql
--
-- MCP / JSON-authored survey questionnaires.
--
-- Existing specs (orientation_2026_27, field_trip, …) stay defined in PHP in
-- includes/surveys.php. This table holds ONLY specs created via MCP (or any
-- caller of survey_definition_upsert). The loader prefers PHP: a DB row whose
-- spec_key collides with a PHP key is never returned for that key, and upsert
-- refuses to overwrite PHP keys.
--
-- `definition` is LONGTEXT JSON (same rationale as survey_responses.answers —
-- portable across MySQL/MariaDB versions; the app parses it in PHP).
--
-- Idempotent — guards on table existence.
-- ============================================================================

SET NAMES utf8mb4;
SET sql_mode = 'STRICT_TRANS_TABLES,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION';

DROP PROCEDURE IF EXISTS pr_lg_survey_definitions;
DELIMITER //
CREATE PROCEDURE pr_lg_survey_definitions()
BEGIN
    IF NOT EXISTS (SELECT 1 FROM information_schema.tables
                   WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'survey_definitions') THEN
        CREATE TABLE survey_definitions (
            spec_key    VARCHAR(64)  NOT NULL,
            title       VARCHAR(200) NOT NULL,
            definition  LONGTEXT     NOT NULL,
            created_by  INT UNSIGNED NULL,
            created_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP
                                     ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (spec_key),
            CONSTRAINT fk_survey_def_creator FOREIGN KEY (created_by)
                REFERENCES users(id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    END IF;
END //
DELIMITER ;
CALL pr_lg_survey_definitions();
DROP PROCEDURE pr_lg_survey_definitions;
