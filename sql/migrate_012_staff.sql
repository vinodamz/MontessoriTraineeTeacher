-- ============================================================================
-- migrate_012_staff.sql
--
-- Staff management module. Once a recruit is hired into a users row, this
-- module is where day-to-day people-ops happens: attendance, leave, 1:1 /
-- incident notes, HR documents, and a staff-to-management message channel.
--
-- All FKs target users.id (no separate "staff" table — staff are users,
-- mirroring how migrate_011_recruitment hands candidates off).
--
-- Tables:
--   staff_attendance         — one row per (user, date)
--   staff_leave_allowances   — annual quota per (user, year, type)
--   staff_leave_requests     — apply / approve / reject leave
--   staff_issues             — 1:1, performance, incident, kudos log
--   staff_documents          — HR documents (ID, contract, certs)
--   staff_messages           — staff → management notes + admin response
--
-- Idempotent — information_schema guards. Re-running is safe.
-- ============================================================================

SET NAMES utf8mb4;
SET sql_mode = 'STRICT_TRANS_TABLES,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION';

DROP PROCEDURE IF EXISTS pr_lg_staff;
DELIMITER //
CREATE PROCEDURE pr_lg_staff()
BEGIN
    -- 1. Extend users.modules SET to include 'staff'.
    IF EXISTS (
        SELECT 1 FROM information_schema.columns
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME   = 'users'
          AND COLUMN_NAME  = 'modules'
          AND COLUMN_TYPE  NOT LIKE '%staff%'
    ) THEN
        ALTER TABLE users
            MODIFY modules SET('tasks','montessori','students','crm','recruitment','staff')
            NOT NULL DEFAULT '';
    END IF;

    -- 2. staff_attendance — one row per (user, date). check_in / check_out
    --    are clock times (NULL while pending). status follows the same shape
    --    as the student attendance table for familiarity.
    IF NOT EXISTS (SELECT 1 FROM information_schema.tables
                   WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='staff_attendance') THEN
        CREATE TABLE staff_attendance (
            id              INT UNSIGNED NOT NULL AUTO_INCREMENT,
            user_id         INT UNSIGNED NOT NULL,
            att_date        DATE         NOT NULL,
            status          ENUM('present','absent','late','leave','holiday','wfh')
                            NOT NULL DEFAULT 'present',
            check_in        TIME NULL,
            check_out       TIME NULL,
            notes           VARCHAR(255) NULL,
            marked_by       INT UNSIGNED NOT NULL,
            marked_at       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
                                     ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uq_sa_user_date (user_id, att_date),
            KEY idx_sa_date (att_date),
            CONSTRAINT fk_sa_user   FOREIGN KEY (user_id)   REFERENCES users(id) ON DELETE CASCADE,
            CONSTRAINT fk_sa_marker FOREIGN KEY (marked_by) REFERENCES users(id) ON DELETE RESTRICT
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    END IF;

    -- 3. staff_leave_allowances — annual quota per (user, year, type).
    --    Used days are computed from approved staff_leave_requests, not
    --    stored, so the balance always reflects current truth.
    IF NOT EXISTS (SELECT 1 FROM information_schema.tables
                   WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='staff_leave_allowances') THEN
        CREATE TABLE staff_leave_allowances (
            id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
            user_id     INT UNSIGNED NOT NULL,
            year        SMALLINT UNSIGNED NOT NULL,
            leave_type  ENUM('casual','sick','earned','unpaid','other') NOT NULL,
            days_total  DECIMAL(5,1) NOT NULL DEFAULT 0,
            created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
                                 ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uq_sla_user_year_type (user_id, year, leave_type),
            CONSTRAINT fk_sla_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    END IF;

    -- 4. staff_leave_requests — apply / approve / reject. days_count is
    --    materialized at submission so partial-day requests (0.5) survive.
    IF NOT EXISTS (SELECT 1 FROM information_schema.tables
                   WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='staff_leave_requests') THEN
        CREATE TABLE staff_leave_requests (
            id              INT UNSIGNED NOT NULL AUTO_INCREMENT,
            user_id         INT UNSIGNED NOT NULL,
            leave_type      ENUM('casual','sick','earned','unpaid','other') NOT NULL DEFAULT 'casual',
            start_date      DATE NOT NULL,
            end_date        DATE NOT NULL,
            days_count      DECIMAL(5,1) NOT NULL,
            reason          TEXT NULL,
            status          ENUM('pending','approved','rejected','cancelled')
                            NOT NULL DEFAULT 'pending',
            decided_by      INT UNSIGNED NULL,
            decided_at      DATETIME NULL,
            decision_note   VARCHAR(255) NULL,
            created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
                                     ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_slr_user_status (user_id, status),
            KEY idx_slr_range (start_date, end_date),
            CONSTRAINT fk_slr_user    FOREIGN KEY (user_id)    REFERENCES users(id) ON DELETE CASCADE,
            CONSTRAINT fk_slr_decider FOREIGN KEY (decided_by) REFERENCES users(id) ON DELETE SET NULL,
            CONSTRAINT chk_slr_dates  CHECK (end_date >= start_date),
            CONSTRAINT chk_slr_days   CHECK (days_count > 0)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    END IF;

    -- 5. staff_issues — per-staff log for 1:1s, performance discussions,
    --    incidents and kudos. occurred_at is when the event happened (vs.
    --    created_at, when it was logged). Only admins log issues — staff
    --    can view their own.
    IF NOT EXISTS (SELECT 1 FROM information_schema.tables
                   WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='staff_issues') THEN
        CREATE TABLE staff_issues (
            id           INT UNSIGNED NOT NULL AUTO_INCREMENT,
            user_id      INT UNSIGNED NOT NULL,
            kind         ENUM('one_on_one','performance','incident','kudos','other')
                         NOT NULL DEFAULT 'one_on_one',
            occurred_at  DATETIME NOT NULL,
            subject      VARCHAR(200) NOT NULL,
            body         TEXT NULL,
            visible_to_staff TINYINT(1) NOT NULL DEFAULT 1,
            logged_by    INT UNSIGNED NOT NULL,
            created_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
                                  ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_si_user_when (user_id, occurred_at),
            CONSTRAINT fk_si_user FOREIGN KEY (user_id)   REFERENCES users(id) ON DELETE CASCADE,
            CONSTRAINT fk_si_by   FOREIGN KEY (logged_by) REFERENCES users(id) ON DELETE RESTRICT
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    END IF;

    -- 6. staff_documents — HR documents. Files live under
    --    uploads/staff_docs/<user_id>/, gated by uploads/.htaccess; the
    --    only retrieval path is /staff/download.php.
    IF NOT EXISTS (SELECT 1 FROM information_schema.tables
                   WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='staff_documents') THEN
        CREATE TABLE staff_documents (
            id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
            user_id       INT UNSIGNED NOT NULL,
            kind          ENUM('id_proof','contract','certification','medical','reference','other')
                          NOT NULL DEFAULT 'other',
            original_name VARCHAR(255) NOT NULL,
            stored_name   VARCHAR(255) NOT NULL,
            mime_type     VARCHAR(100) NULL,
            size_bytes    INT UNSIGNED NULL,
            uploaded_by   INT UNSIGNED NULL,
            uploaded_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_sd_user (user_id),
            CONSTRAINT fk_sd_user FOREIGN KEY (user_id)     REFERENCES users(id) ON DELETE CASCADE,
            CONSTRAINT fk_sd_by   FOREIGN KEY (uploaded_by) REFERENCES users(id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    END IF;

    -- 7. staff_messages — staff → management notes (suggestions, concerns,
    --    requests). Admins can respond inline. status is the lifecycle.
    IF NOT EXISTS (SELECT 1 FROM information_schema.tables
                   WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='staff_messages') THEN
        CREATE TABLE staff_messages (
            id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
            from_user_id  INT UNSIGNED NOT NULL,
            subject       VARCHAR(200) NOT NULL,
            body          TEXT NOT NULL,
            category      ENUM('suggestion','concern','request','appreciation','other')
                          NOT NULL DEFAULT 'other',
            status        ENUM('open','acknowledged','resolved','archived')
                          NOT NULL DEFAULT 'open',
            response      TEXT NULL,
            responded_by  INT UNSIGNED NULL,
            responded_at  DATETIME NULL,
            created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
                                   ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_sm_from_status (from_user_id, status),
            KEY idx_sm_status_created (status, created_at),
            CONSTRAINT fk_sm_from FOREIGN KEY (from_user_id) REFERENCES users(id) ON DELETE CASCADE,
            CONSTRAINT fk_sm_by   FOREIGN KEY (responded_by) REFERENCES users(id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    END IF;
END //
DELIMITER ;
CALL pr_lg_staff();
DROP PROCEDURE pr_lg_staff;
