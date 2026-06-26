-- ============================================================================
-- migrate_033_crm_stage_intro.sql
--
-- Adds an optional per-stage "intro" WhatsApp message that prepends the
-- existing stage message on the FIRST time it's sent to a family.
--
-- Context: Meta-approved templates don't include the school name ("Little
-- Graduates"), so the family doesn't know who's messaging them when the
-- template lands cold. The intro is a free-text "Hi, this is Little
-- Graduates Admissions" line that runs first; from then on we trust the
-- template alone.
--
--   crm_stages.intro_text   TEXT NULL   per-stage intro body. Same {parent_name},
--                                       {child_name}, {school_name}, {stage} vars
--                                       as wa_text. Blank = no intro for this stage.
--   crm_stage_intros_sent   table       remembers which (family, stage) intros have
--                                       already gone, so we never double-send.
--
-- Idempotent.
-- ============================================================================

SET NAMES utf8mb4;

DROP PROCEDURE IF EXISTS pr_lg_crm_stage_intro;
DELIMITER //
CREATE PROCEDURE pr_lg_crm_stage_intro()
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM information_schema.columns
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'crm_stages'
          AND COLUMN_NAME = 'intro_text'
    ) THEN
        -- No AFTER clause — column order doesn't matter and we don't want
        -- to depend on wa_template_lang having been added by an earlier
        -- migration (which lives outside schema.sql).
        ALTER TABLE crm_stages ADD COLUMN intro_text TEXT NULL;
    END IF;
END //
DELIMITER ;
CALL pr_lg_crm_stage_intro();
DROP PROCEDURE pr_lg_crm_stage_intro;

CREATE TABLE IF NOT EXISTS crm_stage_intros_sent (
    family_id   INT UNSIGNED NOT NULL,
    stage_code  VARCHAR(40)  NOT NULL,
    sent_at     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (family_id, stage_code),
    KEY idx_csis_family (family_id),
    CONSTRAINT fk_csis_family FOREIGN KEY (family_id) REFERENCES inquiry_families(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
