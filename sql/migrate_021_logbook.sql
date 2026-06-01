-- ============================================================================
-- migrate_021_logbook.sql
--
-- Adds the Logbook module: a single typed-entry table for all the
-- operational logs a playschool keeps — visitor log, incident/accident
-- log, child observations, pickup/handover, health checks, medication,
-- cleaning, safety drills, maintenance.
--
-- Each entry carries a log_type, an occurred_at timestamp, the staff who
-- logged it, an optional linked student, free-text details, a small
-- meta_json for type-specific fields, an optional photo, and a
-- parent_notified flag for incident/medication entries.
--
-- Adds 'logbook' to users.modules SET. Idempotent.
-- ============================================================================

SET NAMES utf8mb4;
SET sql_mode = 'STRICT_TRANS_TABLES,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION';

DROP PROCEDURE IF EXISTS pr_lg_logbook;
DELIMITER //
CREATE PROCEDURE pr_lg_logbook()
BEGIN
    -- 1. Add 'logbook' to the modules SET (list every existing value).
    IF EXISTS (
        SELECT 1 FROM information_schema.columns
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users'
          AND COLUMN_NAME = 'modules' AND DATA_TYPE = 'set'
          AND COLUMN_TYPE NOT LIKE '%logbook%'
    ) THEN
        ALTER TABLE users
            MODIFY modules
              SET('tasks','montessori','students','crm','recruitment','staff','expenses','fees','logbook')
              NOT NULL DEFAULT '';
    END IF;

    -- 2. logbook_entries — one row per logged event.
    IF NOT EXISTS (SELECT 1 FROM information_schema.tables
                   WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='logbook_entries') THEN
        CREATE TABLE logbook_entries (
            id              INT UNSIGNED NOT NULL AUTO_INCREMENT,
            log_type        VARCHAR(30)  NOT NULL,
            occurred_at     DATETIME     NOT NULL,
            student_id      INT UNSIGNED NULL,
            title           VARCHAR(160) NULL,
            details         TEXT         NULL,
            meta_json       TEXT         NULL,
            parent_notified TINYINT(1)   NOT NULL DEFAULT 0,
            notified_at     DATETIME     NULL,
            photo_path      VARCHAR(255) NULL,
            logged_by       INT UNSIGNED NULL,
            created_at      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_log_type_when (log_type, occurred_at),
            KEY idx_log_student   (student_id, occurred_at),
            KEY idx_log_when      (occurred_at),
            CONSTRAINT fk_log_student FOREIGN KEY (student_id) REFERENCES students(id) ON DELETE SET NULL,
            CONSTRAINT fk_log_by      FOREIGN KEY (logged_by)  REFERENCES users(id)    ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    END IF;
END //
DELIMITER ;
CALL pr_lg_logbook();
DROP PROCEDURE pr_lg_logbook;
