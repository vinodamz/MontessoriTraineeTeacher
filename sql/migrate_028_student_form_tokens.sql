-- ============================================================================
-- migrate_028_student_form_tokens.sql
--
-- Adds the remaining admission-form fields that the school's paper form
-- captures (nationality, mother tongue, sibling notes, parent workplace,
-- richer emergency contact) and introduces a token table that powers a
-- public per-student "parent form" link.
--
-- The token gates an unauthenticated page (/students/parent_form.php)
-- where the family can edit their child's record without a school login.
--
-- Added on students:
--   nationality                 VARCHAR(60)
--   mother_tongue               VARCHAR(60)
--   emergency_contact_relation  VARCHAR(60)
--   emergency_contact_address   TEXT
--   sibling_details             TEXT          -- free-form, one line per sibling
--
-- Added on student_parents:
--   workplace                   VARCHAR(160)
--
-- New table:
--   student_form_tokens         -- one row per active/issued parent link.
--                                  Hashes-of-token aren't necessary because
--                                  the table itself is only readable by the
--                                  app's DB user; if you'd rather store
--                                  hashed tokens, replace the UNIQUE index
--                                  with a unique hash and add a column.
--
-- Idempotent — safe to re-run.
-- ============================================================================

SET NAMES utf8mb4;

DROP PROCEDURE IF EXISTS pr_lg_student_form_tokens;
DELIMITER //
CREATE PROCEDURE pr_lg_student_form_tokens()
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM information_schema.columns
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'students'
          AND COLUMN_NAME = 'nationality'
    ) THEN
        ALTER TABLE students
            ADD COLUMN nationality   VARCHAR(60) NULL AFTER place_of_birth,
            ADD COLUMN mother_tongue VARCHAR(60) NULL AFTER nationality;
    END IF;

    IF NOT EXISTS (
        SELECT 1 FROM information_schema.columns
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'students'
          AND COLUMN_NAME = 'emergency_contact_relation'
    ) THEN
        ALTER TABLE students
            ADD COLUMN emergency_contact_relation VARCHAR(60) NULL AFTER emergency_contact_phone,
            ADD COLUMN emergency_contact_address  TEXT        NULL AFTER emergency_contact_relation;
    END IF;

    IF NOT EXISTS (
        SELECT 1 FROM information_schema.columns
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'students'
          AND COLUMN_NAME = 'sibling_details'
    ) THEN
        ALTER TABLE students
            ADD COLUMN sibling_details TEXT NULL AFTER notes;
    END IF;

    IF NOT EXISTS (
        SELECT 1 FROM information_schema.columns
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'student_parents'
          AND COLUMN_NAME = 'workplace'
    ) THEN
        ALTER TABLE student_parents
            ADD COLUMN workplace VARCHAR(160) NULL AFTER occupation;
    END IF;
END //
DELIMITER ;
CALL pr_lg_student_form_tokens();
DROP PROCEDURE pr_lg_student_form_tokens;

CREATE TABLE IF NOT EXISTS student_form_tokens (
    id                  INT UNSIGNED NOT NULL AUTO_INCREMENT,
    student_id          INT UNSIGNED NOT NULL,
    token               CHAR(64)     NOT NULL,
    created_by_user_id  INT UNSIGNED NOT NULL,
    created_at          DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    last_accessed_at    DATETIME     NULL,
    last_saved_at       DATETIME     NULL,
    revoked_at          DATETIME     NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_sft_token   (token),
    KEY        idx_sft_student (student_id, revoked_at),
    CONSTRAINT fk_sft_student FOREIGN KEY (student_id)         REFERENCES students(id) ON DELETE CASCADE,
    CONSTRAINT fk_sft_creator FOREIGN KEY (created_by_user_id) REFERENCES users(id)    ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
