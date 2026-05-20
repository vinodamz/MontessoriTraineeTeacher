-- ============================================================================
-- migrate_008_notifications.sql
--
-- In-app notifications + per-user preferences + email channel.
--
-- One row per (recipient, event). The `link` field is the URL the bell-icon
-- dropdown sends the user to when they click an item. `category` is a coarse
-- bucket used by per-user preferences (tasks / attendance / fees / students)
-- so users can mute whole categories without managing 20 event toggles.
--
-- Idempotent — uses information_schema guards.
-- ============================================================================

SET NAMES utf8mb4;
SET sql_mode = 'STRICT_TRANS_TABLES,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION';

DROP PROCEDURE IF EXISTS pr_lg_notifications;
DELIMITER //
CREATE PROCEDURE pr_lg_notifications()
BEGIN
    IF NOT EXISTS (SELECT 1 FROM information_schema.tables
                   WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='notifications') THEN
        CREATE TABLE notifications (
            id           INT UNSIGNED NOT NULL AUTO_INCREMENT,
            user_id      INT UNSIGNED NOT NULL,                  -- recipient
            category     ENUM('tasks','attendance','fees','students','system')
                         NOT NULL DEFAULT 'system',
            event_type   VARCHAR(40)  NOT NULL,                  -- e.g. 'task_assigned'
            title        VARCHAR(200) NOT NULL,
            body         TEXT         NULL,
            link         VARCHAR(255) NULL,
            read_at      DATETIME     NULL,
            email_status ENUM('pending','sent','skipped','failed') NOT NULL DEFAULT 'pending',
            email_sent_at DATETIME    NULL,
            created_at   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_notif_user_unread (user_id, read_at, created_at),
            KEY idx_notif_email_pending (email_status, created_at),
            CONSTRAINT fk_notif_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    END IF;

    IF NOT EXISTS (SELECT 1 FROM information_schema.tables
                   WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='notification_preferences') THEN
        CREATE TABLE notification_preferences (
            user_id              INT UNSIGNED NOT NULL,
            email_enabled        TINYINT(1)   NOT NULL DEFAULT 1,
            tasks_enabled        TINYINT(1)   NOT NULL DEFAULT 1,
            attendance_enabled   TINYINT(1)   NOT NULL DEFAULT 1,
            fees_enabled         TINYINT(1)   NOT NULL DEFAULT 1,
            students_enabled     TINYINT(1)   NOT NULL DEFAULT 1,
            updated_at           DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (user_id),
            CONSTRAINT fk_notif_prefs_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

        -- Seed defaults for every existing user.
        INSERT INTO notification_preferences (user_id)
            SELECT id FROM users
            WHERE id NOT IN (SELECT user_id FROM notification_preferences);
    END IF;

    -- Seed an app-wide "from email" if not already present.
    IF EXISTS (SELECT 1 FROM information_schema.tables
               WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='app_settings') THEN
        INSERT IGNORE INTO app_settings (setting_key, setting_value) VALUES
            ('email_from_name',    'Little Graduates'),
            ('email_from_address', 'no-reply@thelittlegraduates.in');
    END IF;
END //
DELIMITER ;
CALL pr_lg_notifications();
DROP PROCEDURE pr_lg_notifications;
