-- ============================================================================
-- migrate_048_parent_feedback.sql
--
-- Parent feedback & discussion log (admin-only section). Each entry captures a
-- piece of parent feedback and how the school handled it; a per-entry log keeps
-- the running history of discussions and updates so nothing is lost.
--
--   parent_feedback      — one row per feedback item:
--                          entry_date, (optional) student, parent name,
--                          parent comments, discussion points with teachers,
--                          resolution taken, status.
--   parent_feedback_log  — append-only timeline of updates on an entry
--                          (auto entries on create / status change, plus
--                          manual discussion notes).
--
-- Idempotent — guards on table existence.
-- ============================================================================

SET NAMES utf8mb4;
SET sql_mode = 'STRICT_TRANS_TABLES,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION';

DROP PROCEDURE IF EXISTS pr_lg_parent_feedback;
DELIMITER //
CREATE PROCEDURE pr_lg_parent_feedback()
BEGIN
    IF NOT EXISTS (SELECT 1 FROM information_schema.tables
                   WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='parent_feedback') THEN
        CREATE TABLE parent_feedback (
            id                INT UNSIGNED NOT NULL AUTO_INCREMENT,
            entry_date        DATE         NOT NULL,
            student_id        INT UNSIGNED NULL,
            parent_name       VARCHAR(120) NULL,
            parent_comments   TEXT         NOT NULL,
            discussion_points TEXT         NULL,
            resolution        TEXT         NULL,
            status            ENUM('open','in_progress','resolved','closed')
                              NOT NULL DEFAULT 'open',
            created_by        INT UNSIGNED NULL,
            created_at        DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at        DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP
                                       ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_pf_status  (status, entry_date),
            KEY idx_pf_student (student_id),
            CONSTRAINT fk_pf_student FOREIGN KEY (student_id) REFERENCES students(id) ON DELETE SET NULL,
            CONSTRAINT fk_pf_creator FOREIGN KEY (created_by) REFERENCES users(id)    ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    END IF;

    IF NOT EXISTS (SELECT 1 FROM information_schema.tables
                   WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='parent_feedback_log') THEN
        CREATE TABLE parent_feedback_log (
            id           INT UNSIGNED NOT NULL AUTO_INCREMENT,
            feedback_id  INT UNSIGNED NOT NULL,
            entry_type   ENUM('created','note','status','edit') NOT NULL DEFAULT 'note',
            note         TEXT         NOT NULL,
            logged_by    INT UNSIGNED NULL,
            created_at   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_pfl_feedback (feedback_id, created_at),
            CONSTRAINT fk_pfl_feedback FOREIGN KEY (feedback_id) REFERENCES parent_feedback(id) ON DELETE CASCADE,
            CONSTRAINT fk_pfl_by       FOREIGN KEY (logged_by)   REFERENCES users(id)           ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    END IF;
END //
DELIMITER ;
CALL pr_lg_parent_feedback();
DROP PROCEDURE pr_lg_parent_feedback;
