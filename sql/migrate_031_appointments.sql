-- ============================================================================
-- migrate_031_appointments.sql
--
-- School-visit appointments. Booked publicly via crm/book_visit.php (or by
-- staff), shown on the lead detail page and the crm/today.php day view.
--
-- Idempotent — guarded on information_schema.
-- ============================================================================

SET NAMES utf8mb4;

DROP PROCEDURE IF EXISTS pr_lg_appointments;
DELIMITER //
CREATE PROCEDURE pr_lg_appointments()
BEGIN
    IF NOT EXISTS (SELECT 1 FROM information_schema.tables
        WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='crm_appointments') THEN
        CREATE TABLE crm_appointments (
            id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
            family_id     INT UNSIGNED NOT NULL,
            scheduled_at  DATETIME NOT NULL,
            child_name    VARCHAR(120) NULL,
            programme     VARCHAR(60)  NULL,
            notes         VARCHAR(500) NULL,
            status        ENUM('booked','done','cancelled','no_show') NOT NULL DEFAULT 'booked',
            source        VARCHAR(40)  NOT NULL DEFAULT 'web',
            created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_appt_when (scheduled_at),
            KEY idx_appt_family (family_id),
            CONSTRAINT fk_appt_family FOREIGN KEY (family_id)
                REFERENCES inquiry_families(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    END IF;
END //
DELIMITER ;
CALL pr_lg_appointments();
DROP PROCEDURE pr_lg_appointments;
