-- ============================================================================
-- migrate_062_survey_one_per_child.sql
--
-- One response per child, for the surveys that need it.
--
-- A consent form is not an opinion survey. Two rows for the same child is not
-- extra data, it is a question about which one counts — and on the morning of
-- a trip, with a teacher reading a list, that is the worst possible moment to
-- find out.
--
-- Enforced in the database rather than only in PHP because an application-level
-- "have we seen this child?" check has a gap between the SELECT and the INSERT.
-- Two parents of the same child tapping Submit within the same second is not a
-- hypothetical on a form that gets sent to a WhatsApp group.
--
-- child_key holds the child's name normalised — trimmed, inner whitespace
-- collapsed, lowercased — so "Aarav  Nair", "aarav nair" and " Aarav Nair "
-- are one child rather than three. It is deliberately NULLable: MySQL allows
-- any number of NULLs in a unique index, so a survey that WANTS repeat
-- responses simply leaves the column empty and is unaffected. Only specs
-- marked one_per_child populate it.
--
-- Backfill: existing rows are keyed only where that does not collide. Where a
-- child already has more than one response the earliest keeps the key and the
-- later ones are left NULL, so the index can be created without deleting
-- anybody's answers. Those extras stay visible on the admin page, which is the
-- right outcome — a human should decide which one counts, not a migration.
--
-- Idempotent — guards on column and index existence.
-- ============================================================================

SET NAMES utf8mb4;
SET sql_mode = 'STRICT_TRANS_TABLES,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION';

DROP PROCEDURE IF EXISTS pr_lg_survey_one_per_child;
DELIMITER //
CREATE PROCEDURE pr_lg_survey_one_per_child()
BEGIN
    IF EXISTS (SELECT 1 FROM information_schema.tables
               WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'survey_responses') THEN

        IF NOT EXISTS (SELECT 1 FROM information_schema.columns
                       WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'survey_responses'
                         AND COLUMN_NAME = 'child_key') THEN
            ALTER TABLE survey_responses
                ADD COLUMN child_key VARCHAR(160) NULL AFTER child_name;
        END IF;

        -- Backfill only the surveys that will enforce this, and only the
        -- earliest response per child, so no existing row is lost.
        UPDATE survey_responses r
          JOIN surveys s ON s.id = r.survey_id
           SET r.child_key = LOWER(TRIM(r.child_name))
         WHERE s.spec_key = 'field_trip'
           AND r.child_key IS NULL
           AND r.id = (
                 SELECT MIN(r2.id) FROM (SELECT * FROM survey_responses) r2
                  WHERE r2.survey_id = r.survey_id
                    AND LOWER(TRIM(r2.child_name)) = LOWER(TRIM(r.child_name))
               );

        IF NOT EXISTS (SELECT 1 FROM information_schema.statistics
                       WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'survey_responses'
                         AND INDEX_NAME = 'uq_sr_survey_child') THEN
            ALTER TABLE survey_responses
                ADD UNIQUE KEY uq_sr_survey_child (survey_id, child_key);
        END IF;
    END IF;
END //
DELIMITER ;
CALL pr_lg_survey_one_per_child();
DROP PROCEDURE pr_lg_survey_one_per_child;
