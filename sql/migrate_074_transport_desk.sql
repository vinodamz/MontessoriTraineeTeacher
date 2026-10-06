-- ============================================================================
-- migrate_074_transport_desk.sql
--
-- Messages (including SOS and speed alerts), a parent's "not riding" notice,
-- and a handover PIN shared by the family and the class teacher.
-- Idempotent.
-- ============================================================================

SET NAMES utf8mb4;
SET sql_mode = 'STRICT_TRANS_TABLES,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION';

DROP PROCEDURE IF EXISTS pr_lg_transport_desk;
DELIMITER //
CREATE PROCEDURE pr_lg_transport_desk()
BEGIN
    IF NOT EXISTS (SELECT 1 FROM information_schema.tables
                   WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'transport_messages') THEN
        CREATE TABLE transport_messages (
            id         INT UNSIGNED NOT NULL AUTO_INCREMENT,
            user_id    INT UNSIGNED NULL,
            trip_id    INT UNSIGNED NULL,
            kind       ENUM('message','broadcast','sos','alert') NOT NULL DEFAULT 'message',
            body       VARCHAR(500) NOT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_transport_messages_created (created_at),
            CONSTRAINT fk_transport_messages_user FOREIGN KEY (user_id) REFERENCES users (id),
            CONSTRAINT fk_transport_messages_trip FOREIGN KEY (trip_id) REFERENCES transport_trips (id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    END IF;

    IF NOT EXISTS (SELECT 1 FROM information_schema.tables
                   WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'transport_absences') THEN
        CREATE TABLE transport_absences (
            id           INT UNSIGNED NOT NULL AUTO_INCREMENT,
            student_id   INT UNSIGNED NOT NULL,
            absence_date DATE NOT NULL,
            scope        ENUM('morning','afternoon','both') NOT NULL,
            reason       VARCHAR(200) NOT NULL DEFAULT '',
            created_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uq_transport_absence_day (student_id, absence_date),
            CONSTRAINT fk_transport_absences_student FOREIGN KEY (student_id) REFERENCES students (id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    END IF;

    IF NOT EXISTS (SELECT 1 FROM information_schema.columns
                   WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'transport_trip_stops'
                     AND COLUMN_NAME = 'handover_pin') THEN
        ALTER TABLE transport_trip_stops
            ADD COLUMN handover_pin CHAR(4) NULL AFTER marked_by_user_id;
    END IF;
END //
DELIMITER ;
CALL pr_lg_transport_desk();
DROP PROCEDURE pr_lg_transport_desk;
