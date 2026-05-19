-- ============================================================================
-- migrate_004_attendance.sql
--
-- Adds `attendance` for daily per-student attendance tracking.
-- Idempotent — uses information_schema guards. Re-running is safe.
-- ============================================================================

SET NAMES utf8mb4;
SET sql_mode = 'STRICT_TRANS_TABLES,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION';

DROP PROCEDURE IF EXISTS pr_lg_attendance;
DELIMITER //
CREATE PROCEDURE pr_lg_attendance()
BEGIN
    IF NOT EXISTS (SELECT 1 FROM information_schema.tables
                   WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='attendance') THEN
        CREATE TABLE attendance (
            id                  INT UNSIGNED NOT NULL AUTO_INCREMENT,
            student_id          INT UNSIGNED NOT NULL,
            attendance_date     DATE         NOT NULL,
            status              ENUM('present','absent','late','excused','holiday') NOT NULL DEFAULT 'present',
            notes               VARCHAR(255) NULL,
            marked_by_user_id   INT UNSIGNED NOT NULL,
            marked_at           DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at          DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uq_attendance_student_date (student_id, attendance_date),
            KEY idx_attendance_date (attendance_date),
            CONSTRAINT fk_att_student FOREIGN KEY (student_id)        REFERENCES students(id) ON DELETE CASCADE,
            CONSTRAINT fk_att_marker  FOREIGN KEY (marked_by_user_id) REFERENCES users(id)    ON DELETE RESTRICT
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    END IF;
END //
DELIMITER ;
CALL pr_lg_attendance();
DROP PROCEDURE pr_lg_attendance;
