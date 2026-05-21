-- ============================================================================
-- migrate_009_crm.sql
--
-- Admissions / CRM module: tracks prospect families through the enrollment
-- funnel. Sits alongside the students module — when a family is marked
-- "enrolled", each child is copied into the existing `students` table and
-- the inquiry rows keep a back-reference (`promoted_student_id`) so the
-- funnel history is preserved.
--
-- Pipeline statuses (see includes/crm.php for labels + default win-probability):
--   new → tour_scheduled → application_submitted → offered → enrolled
--                                                       └→ waitlisted / lost
--
-- Tables: inquiry_families, inquiry_parents, inquiry_children, inquiry_touchpoints.
-- Also expands users.modules SET to include 'crm'.
--
-- Idempotent — uses information_schema guards. Re-running is safe.
-- ============================================================================

SET NAMES utf8mb4;
SET sql_mode = 'STRICT_TRANS_TABLES,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION';

DROP PROCEDURE IF EXISTS pr_lg_crm;
DELIMITER //
CREATE PROCEDURE pr_lg_crm()
BEGIN
    -- 1. Add 'crm' to users.modules SET (metadata-only change).
    IF EXISTS (
        SELECT 1 FROM information_schema.columns
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME   = 'users'
          AND COLUMN_NAME  = 'modules'
          AND COLUMN_TYPE  NOT LIKE '%crm%'
    ) THEN
        ALTER TABLE users
            MODIFY modules SET('tasks','montessori','students','crm') NOT NULL DEFAULT '';
    END IF;

    -- 2. inquiry_families — the lead / prospect family unit.
    IF NOT EXISTS (SELECT 1 FROM information_schema.tables
                   WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='inquiry_families') THEN
        CREATE TABLE inquiry_families (
            id              INT UNSIGNED NOT NULL AUTO_INCREMENT,
            primary_name    VARCHAR(160) NOT NULL,
            primary_phone   VARCHAR(40)  NULL,
            primary_email   VARCHAR(160) NULL,
            source          VARCHAR(60)  NULL,
            status          ENUM('new','tour_scheduled','application_submitted',
                                 'offered','enrolled','waitlisted','lost')
                            NOT NULL DEFAULT 'new',
            probability     TINYINT UNSIGNED NOT NULL DEFAULT 20,
            expected_fee    DECIMAL(10,2) NULL,
            expected_start  DATE NULL,
            notes           TEXT NULL,
            owner_id        INT UNSIGNED NULL,
            enrolled_at     DATETIME NULL,
            created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
                                     ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_inq_status  (status),
            KEY idx_inq_created (created_at),
            CONSTRAINT fk_inq_owner FOREIGN KEY (owner_id) REFERENCES users(id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    END IF;

    -- 3. inquiry_parents — one or more parents/guardians per family.
    IF NOT EXISTS (SELECT 1 FROM information_schema.tables
                   WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='inquiry_parents') THEN
        CREATE TABLE inquiry_parents (
            id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
            family_id   INT UNSIGNED NOT NULL,
            relation    ENUM('father','mother','guardian','other')
                        NOT NULL DEFAULT 'guardian',
            name        VARCHAR(160) NOT NULL,
            phone       VARCHAR(40)  NULL,
            email       VARCHAR(160) NULL,
            occupation  VARCHAR(120) NULL,
            is_primary  TINYINT(1)   NOT NULL DEFAULT 0,
            PRIMARY KEY (id),
            KEY idx_ip_fam (family_id),
            CONSTRAINT fk_ip_fam FOREIGN KEY (family_id)
                REFERENCES inquiry_families(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    END IF;

    -- 4. inquiry_children — one or more children per family.
    --    `target_grade` mirrors the students.grade enum so promotion is 1-click.
    IF NOT EXISTS (SELECT 1 FROM information_schema.tables
                   WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='inquiry_children') THEN
        CREATE TABLE inquiry_children (
            id                  INT UNSIGNED NOT NULL AUTO_INCREMENT,
            family_id           INT UNSIGNED NOT NULL,
            first_name          VARCHAR(120) NOT NULL,
            last_name           VARCHAR(120) NULL,
            dob                 DATE NULL,
            gender              ENUM('Male','Female','Other') NULL,
            target_grade        ENUM('Playgroup','Nursery','LKG','UKG') NULL,
            notes               TEXT NULL,
            promoted_student_id INT UNSIGNED NULL,
            PRIMARY KEY (id),
            KEY idx_ic_fam (family_id),
            CONSTRAINT fk_ic_fam FOREIGN KEY (family_id)
                REFERENCES inquiry_families(id) ON DELETE CASCADE,
            CONSTRAINT fk_ic_student FOREIGN KEY (promoted_student_id)
                REFERENCES students(id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    END IF;

    -- 5. inquiry_touchpoints — communication log for a family.
    --    `follow_up_at` powers the "next follow-ups" widget on the dashboard.
    IF NOT EXISTS (SELECT 1 FROM information_schema.tables
                   WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='inquiry_touchpoints') THEN
        CREATE TABLE inquiry_touchpoints (
            id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
            family_id     INT UNSIGNED NOT NULL,
            kind          ENUM('call','email','sms','meeting','tour','note','other')
                          NOT NULL DEFAULT 'note',
            occurred_at   DATETIME NOT NULL,
            follow_up_at  DATETIME NULL,
            body          TEXT NULL,
            created_by    INT UNSIGNED NULL,
            created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_it_fam_when (family_id, occurred_at),
            KEY idx_it_followup (follow_up_at),
            CONSTRAINT fk_it_fam FOREIGN KEY (family_id)
                REFERENCES inquiry_families(id) ON DELETE CASCADE,
            CONSTRAINT fk_it_by  FOREIGN KEY (created_by)
                REFERENCES users(id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    END IF;
END //
DELIMITER ;
CALL pr_lg_crm();
DROP PROCEDURE pr_lg_crm;
