-- ============================================================================
-- migrate_064_staff_duties.sql
--
-- Daily / weekly / monthly duty checklists assigned to all teachers, all
-- staff, or named people. Completions live on staff_duty_items (one row per
-- person per template per period). Teacher-added tasks use the same table
-- with source='self' and notify admins.
--
-- This is separate from the kanban `tasks` board: a duty is a tick, not a
-- card with a column.
--
-- Idempotent — guards on table existence.
-- ============================================================================

SET NAMES utf8mb4;
SET sql_mode = 'STRICT_TRANS_TABLES,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION';

DROP PROCEDURE IF EXISTS pr_lg_staff_duties;
DELIMITER //
CREATE PROCEDURE pr_lg_staff_duties()
BEGIN
    IF NOT EXISTS (SELECT 1 FROM information_schema.tables
                   WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'staff_duty_templates') THEN
        CREATE TABLE staff_duty_templates (
            id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
            title       VARCHAR(200) NOT NULL,
            notes       VARCHAR(500) NULL,
            frequency   ENUM('daily','weekly','monthly') NOT NULL DEFAULT 'daily',
            audience    ENUM('all_teachers','all_staff','users') NOT NULL DEFAULT 'all_teachers',
            is_active   TINYINT(1)   NOT NULL DEFAULT 1,
            sort_order  INT          NOT NULL DEFAULT 0,
            created_by  INT UNSIGNED NULL,
            created_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP
                                     ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_duty_tpl_active (is_active, frequency, sort_order),
            CONSTRAINT fk_duty_tpl_creator FOREIGN KEY (created_by)
                REFERENCES users(id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    END IF;

    IF NOT EXISTS (SELECT 1 FROM information_schema.tables
                   WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'staff_duty_template_users') THEN
        CREATE TABLE staff_duty_template_users (
            template_id INT UNSIGNED NOT NULL,
            user_id     INT UNSIGNED NOT NULL,
            PRIMARY KEY (template_id, user_id),
            KEY idx_duty_tpl_user (user_id),
            CONSTRAINT fk_duty_tpl_u_tpl FOREIGN KEY (template_id)
                REFERENCES staff_duty_templates(id) ON DELETE CASCADE,
            CONSTRAINT fk_duty_tpl_u_user FOREIGN KEY (user_id)
                REFERENCES users(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    END IF;

    IF NOT EXISTS (SELECT 1 FROM information_schema.tables
                   WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'staff_duty_items') THEN
        CREATE TABLE staff_duty_items (
            id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
            template_id   INT UNSIGNED NULL,
            user_id       INT UNSIGNED NOT NULL,
            frequency     ENUM('daily','weekly','monthly') NOT NULL,
            period_key    VARCHAR(16)  NOT NULL,
            title         VARCHAR(200) NOT NULL,
            notes         VARCHAR(500) NULL,
            source        ENUM('template','self') NOT NULL DEFAULT 'template',
            status        ENUM('pending','done','not_done') NOT NULL DEFAULT 'pending',
            reason        TEXT NULL,
            comment       TEXT NULL,
            extra_work    TEXT NULL,
            completed_at  DATETIME NULL,
            created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
                                   ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uq_duty_item_tpl (user_id, template_id, period_key),
            KEY idx_duty_item_period (frequency, period_key, status),
            KEY idx_duty_item_user (user_id, frequency, period_key),
            CONSTRAINT fk_duty_item_tpl FOREIGN KEY (template_id)
                REFERENCES staff_duty_templates(id) ON DELETE SET NULL,
            CONSTRAINT fk_duty_item_user FOREIGN KEY (user_id)
                REFERENCES users(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    END IF;

    IF NOT EXISTS (SELECT 1 FROM information_schema.tables
                   WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'staff_duty_period_notes') THEN
        CREATE TABLE staff_duty_period_notes (
            user_id     INT UNSIGNED NOT NULL,
            frequency   ENUM('daily','weekly','monthly') NOT NULL,
            period_key  VARCHAR(16)  NOT NULL,
            comment     TEXT NULL,
            extra_work  TEXT NULL,
            updated_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
                                 ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (user_id, frequency, period_key),
            CONSTRAINT fk_duty_note_user FOREIGN KEY (user_id)
                REFERENCES users(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    END IF;
END //
DELIMITER ;
CALL pr_lg_staff_duties();
DROP PROCEDURE pr_lg_staff_duties;
