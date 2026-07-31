-- ============================================================================
-- migrate_054_parent_surveys.sql
--
-- Parent surveys: one shareable link per survey, responses readable only by
-- admins.
--
--   surveys           — one live run of a questionnaire. `spec_key` names the
--                       question set (defined in includes/surveys.php, not
--                       here) and `token` is the 64-char hex credential that
--                       appears in the public URL. ONE link shared with every
--                       parent — unlike the staff application form, there's no
--                       per-person token, because parents type their own name
--                       and their child's on the form.
--
--   survey_responses  — one row per submission. parent_name / child_name /
--                       class are real columns: they're what the office sorts,
--                       searches and de-duplicates on, and an index on them
--                       beats digging through JSON. Every other answer lives
--                       in `answers` as JSON, pivoted back into spreadsheet
--                       columns in PHP by survey_columns().
--
-- `answers` is LONGTEXT, not the JSON type: nothing in the app queries inside
-- it (the pivot happens in PHP), and LONGTEXT works on every MySQL/MariaDB
-- version this app might land on rather than only 5.7+.
--
-- ip_hash is a SHA-256 of the submitter's address, never the address itself —
-- enough to notice one device submitting twenty times, without keeping
-- identifiable network data about families.
--
-- Deleting a survey takes its responses with it (ON DELETE CASCADE); orphaned
-- answers with no questions attached would be unreadable anyway.
--
-- Idempotent — guards on table existence.
-- ============================================================================

SET NAMES utf8mb4;
SET sql_mode = 'STRICT_TRANS_TABLES,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION';

DROP PROCEDURE IF EXISTS pr_lg_parent_surveys;
DELIMITER //
CREATE PROCEDURE pr_lg_parent_surveys()
BEGIN
    IF NOT EXISTS (SELECT 1 FROM information_schema.tables
                   WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='surveys') THEN
        CREATE TABLE surveys (
            id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
            spec_key    VARCHAR(60)  NOT NULL,
            token       CHAR(64)     NOT NULL,
            active      TINYINT(1)   NOT NULL DEFAULT 1,
            created_by  INT UNSIGNED NULL,
            created_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP
                                     ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uq_surveys_token (token),
            KEY idx_surveys_spec (spec_key),
            CONSTRAINT fk_survey_creator FOREIGN KEY (created_by)
                REFERENCES users(id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    END IF;

    IF NOT EXISTS (SELECT 1 FROM information_schema.tables
                   WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='survey_responses') THEN
        CREATE TABLE survey_responses (
            id           INT UNSIGNED NOT NULL AUTO_INCREMENT,
            survey_id    INT UNSIGNED NOT NULL,
            parent_name  VARCHAR(120) NOT NULL DEFAULT '',
            child_name   VARCHAR(120) NOT NULL DEFAULT '',
            class        VARCHAR(60)  NOT NULL DEFAULT '',
            answers      LONGTEXT     NOT NULL,
            ip_hash      CHAR(64)     NULL,
            submitted_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_sr_survey (survey_id, submitted_at),
            KEY idx_sr_child  (child_name),
            KEY idx_sr_class  (class),
            CONSTRAINT fk_sr_survey FOREIGN KEY (survey_id)
                REFERENCES surveys(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    END IF;
END //
DELIMITER ;
CALL pr_lg_parent_surveys();
DROP PROCEDURE pr_lg_parent_surveys;
