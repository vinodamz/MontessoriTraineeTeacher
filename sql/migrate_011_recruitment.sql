-- ============================================================================
-- migrate_011_recruitment.sql
--
-- Recruitment & Staff Tracking module. Tracks applicants through a preschool
-- hiring pipeline: resume → screening → practical demo → background check
-- → offered → hired / rejected.
--
-- "Hire" promotes the candidate into a users row (role=teacher, active=0).
-- An admin completes onboarding from /admin.php by setting a PIN and the
-- modules SET. There's no separate "staff" table — staff are users in this
-- app (mirrors how crm_promote_inquiry hands children off to students).
--
-- Tables: recruit_candidates, recruit_attachments, recruit_evaluations,
--         recruit_interviews. Also extends users.modules SET with
--         'recruitment'.
--
-- Idempotent — information_schema guards. Re-running is safe.
-- ============================================================================

SET NAMES utf8mb4;
SET sql_mode = 'STRICT_TRANS_TABLES,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION';

DROP PROCEDURE IF EXISTS pr_lg_recruit;
DELIMITER //
CREATE PROCEDURE pr_lg_recruit()
BEGIN
    -- 1. Extend users.modules SET to include 'recruitment'.
    IF EXISTS (
        SELECT 1 FROM information_schema.columns
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME   = 'users'
          AND COLUMN_NAME  = 'modules'
          AND COLUMN_TYPE  NOT LIKE '%recruitment%'
    ) THEN
        ALTER TABLE users
            MODIFY modules SET('tasks','montessori','students','crm','recruitment')
            NOT NULL DEFAULT '';
    END IF;

    -- 2. recruit_candidates — one row per applicant. promoted_user_id points
    --    at the users row created on hire (mirrors inquiry_children
    --    .promoted_student_id from migrate_009).
    IF NOT EXISTS (SELECT 1 FROM information_schema.tables
                   WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='recruit_candidates') THEN
        CREATE TABLE recruit_candidates (
            id                  INT UNSIGNED NOT NULL AUTO_INCREMENT,
            first_name          VARCHAR(120) NOT NULL,
            last_name           VARCHAR(120) NULL,
            phone               VARCHAR(40)  NULL,
            email               VARCHAR(160) NULL,
            position_applied    ENUM('lead_teacher','assistant_teacher',
                                     'caregiver','admin_staff','other')
                                NOT NULL DEFAULT 'assistant_teacher',
            source              VARCHAR(60)  NULL,
            years_experience    TINYINT UNSIGNED NULL,
            certifications      TEXT NULL,
            status              ENUM('resume_received','screening','demo',
                                     'background_check','offered','hired',
                                     'rejected','withdrawn')
                                NOT NULL DEFAULT 'resume_received',
            priority            ENUM('low','normal','high','urgent')
                                NOT NULL DEFAULT 'normal',
            expected_salary     DECIMAL(10,2) NULL,
            available_from      DATE NULL,
            notes               TEXT NULL,
            owner_id            INT UNSIGNED NULL,
            promoted_user_id    INT UNSIGNED NULL,
            hired_at            DATETIME NULL,
            rejected_reason     VARCHAR(255) NULL,
            created_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
                                         ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_rc_status   (status),
            KEY idx_rc_priority (priority),
            KEY idx_rc_created  (created_at),
            CONSTRAINT fk_rc_owner FOREIGN KEY (owner_id)
                REFERENCES users(id) ON DELETE SET NULL,
            CONSTRAINT fk_rc_promoted FOREIGN KEY (promoted_user_id)
                REFERENCES users(id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    END IF;

    -- 3. recruit_attachments — resume + supporting documents. Files live at
    --    uploads/recruit_docs/<candidate_id>/<random>.<ext>. The parent
    --    uploads/.htaccess already denies direct web access; download.php is
    --    the only legitimate retrieval path.
    IF NOT EXISTS (SELECT 1 FROM information_schema.tables
                   WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='recruit_attachments') THEN
        CREATE TABLE recruit_attachments (
            id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
            candidate_id  INT UNSIGNED NOT NULL,
            kind          ENUM('resume','certification','id_proof','reference','other')
                          NOT NULL DEFAULT 'resume',
            original_name VARCHAR(255) NOT NULL,
            stored_name   VARCHAR(255) NOT NULL,
            mime_type     VARCHAR(100) NULL,
            size_bytes    INT UNSIGNED NULL,
            uploaded_by   INT UNSIGNED NULL,
            uploaded_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_ra_cand (candidate_id),
            CONSTRAINT fk_ra_cand FOREIGN KEY (candidate_id)
                REFERENCES recruit_candidates(id) ON DELETE CASCADE,
            CONSTRAINT fk_ra_by   FOREIGN KEY (uploaded_by)
                REFERENCES users(id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    END IF;

    -- 4. recruit_evaluations — qualitative soft-skill scorecards. One row
    --    per (candidate, evaluator) so multiple interviewers can co-rate
    --    without overwriting each other. Ratings 1–5, nullable for partial
    --    saves. montessori_alignment is the preschool-specific signal.
    IF NOT EXISTS (SELECT 1 FROM information_schema.tables
                   WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='recruit_evaluations') THEN
        CREATE TABLE recruit_evaluations (
            id                   INT UNSIGNED NOT NULL AUTO_INCREMENT,
            candidate_id         INT UNSIGNED NOT NULL,
            evaluator_id         INT UNSIGNED NOT NULL,
            care                 TINYINT UNSIGNED NULL,
            curiosity            TINYINT UNSIGNED NULL,
            empathy              TINYINT UNSIGNED NULL,
            montessori_alignment TINYINT UNSIGNED NULL,
            patience             TINYINT UNSIGNED NULL,
            communication        TINYINT UNSIGNED NULL,
            overall_recommend    ENUM('strong_yes','yes','maybe','no','strong_no') NULL,
            comments             TEXT NULL,
            created_at           DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at           DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
                                          ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uq_re_cand_eval (candidate_id, evaluator_id),
            KEY idx_re_cand (candidate_id),
            CONSTRAINT fk_re_cand FOREIGN KEY (candidate_id)
                REFERENCES recruit_candidates(id) ON DELETE CASCADE,
            CONSTRAINT fk_re_eval FOREIGN KEY (evaluator_id)
                REFERENCES users(id) ON DELETE CASCADE,
            CONSTRAINT chk_re_care  CHECK (care IS NULL OR care BETWEEN 1 AND 5),
            CONSTRAINT chk_re_cur   CHECK (curiosity IS NULL OR curiosity BETWEEN 1 AND 5),
            CONSTRAINT chk_re_emp   CHECK (empathy IS NULL OR empathy BETWEEN 1 AND 5),
            CONSTRAINT chk_re_mont  CHECK (montessori_alignment IS NULL OR montessori_alignment BETWEEN 1 AND 5),
            CONSTRAINT chk_re_pat   CHECK (patience IS NULL OR patience BETWEEN 1 AND 5),
            CONSTRAINT chk_re_comm  CHECK (communication IS NULL OR communication BETWEEN 1 AND 5)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    END IF;

    -- 5. recruit_interviews — interview / demo / note event log. Distinct
    --    from evaluations: this is "when/who/what happened", the scorecard
    --    lives in recruit_evaluations. occurred_at powers the upcoming-
    --    interviews widget on the recruitment dashboard.
    IF NOT EXISTS (SELECT 1 FROM information_schema.tables
                   WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='recruit_interviews') THEN
        CREATE TABLE recruit_interviews (
            id             INT UNSIGNED NOT NULL AUTO_INCREMENT,
            candidate_id   INT UNSIGNED NOT NULL,
            interviewer_id INT UNSIGNED NULL,
            stage          ENUM('screening','demo','background_check',
                                'panel','final','note')
                           NOT NULL DEFAULT 'note',
            occurred_at    DATETIME NOT NULL,
            duration_min   SMALLINT UNSIGNED NULL,
            location       VARCHAR(160) NULL,
            outcome        ENUM('pending','passed','failed','no_show') NULL,
            body           TEXT NULL,
            created_by     INT UNSIGNED NULL,
            created_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_ri_cand_when (candidate_id, occurred_at),
            KEY idx_ri_upcoming  (occurred_at),
            CONSTRAINT fk_ri_cand  FOREIGN KEY (candidate_id)
                REFERENCES recruit_candidates(id) ON DELETE CASCADE,
            CONSTRAINT fk_ri_intvw FOREIGN KEY (interviewer_id)
                REFERENCES users(id) ON DELETE SET NULL,
            CONSTRAINT fk_ri_by    FOREIGN KEY (created_by)
                REFERENCES users(id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    END IF;
END //
DELIMITER ;
CALL pr_lg_recruit();
DROP PROCEDURE pr_lg_recruit;
