-- ============================================================================
-- migrate_046_staff_profiles.sql
--
-- Personal-details "application" for staff (teachers + non-teaching). One row
-- per user, filled in by the staff member themselves (or an admin). Kept in a
-- side table rather than bloating the shared `users` identity table.
--
-- Sections mirror the joining form:
--   Profile              — date of birth, gender, blood group
--   Address & emergency  — home address, emergency contact + phone
--   Father / Spouse      — relation, name, email, phone
--   Education            — highest qualification
--   Experience           — previous employer (free text; "NA" if none). The
--                          experience *letter* itself is a staff_documents row
--                          with kind='experience' (see migrate_045), not a
--                          column here.
--
-- Idempotent — guards on table existence. Re-running is safe.
-- ============================================================================

SET NAMES utf8mb4;
SET sql_mode = 'STRICT_TRANS_TABLES,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION';

DROP PROCEDURE IF EXISTS pr_lg_staff_profiles;
DELIMITER //
CREATE PROCEDURE pr_lg_staff_profiles()
BEGIN
    IF NOT EXISTS (SELECT 1 FROM information_schema.tables
                   WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='staff_profiles') THEN
        CREATE TABLE staff_profiles (
            user_id                 INT UNSIGNED NOT NULL,
            -- Profile
            date_of_birth           DATE NULL,
            gender                  ENUM('female','male','other','prefer_not') NULL,
            blood_group             ENUM('A+','A-','B+','B-','O+','O-','AB+','AB-') NULL,
            -- Address & emergency
            home_address            TEXT NULL,
            emergency_contact_name  VARCHAR(120) NULL,
            emergency_phone         VARCHAR(30)  NULL,
            -- Father / Spouse
            relative_relation       ENUM('father','spouse','mother','guardian','other') NULL,
            relative_name           VARCHAR(120) NULL,
            relative_email          VARCHAR(190) NULL,
            relative_phone          VARCHAR(30)  NULL,
            -- Education
            highest_qualification   VARCHAR(150) NULL,
            -- Experience
            previous_employer       TEXT NULL,
            -- Bookkeeping
            created_at              DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at              DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
                                             ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (user_id),
            CONSTRAINT fk_sprof_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    END IF;
END //
DELIMITER ;
CALL pr_lg_staff_profiles();
DROP PROCEDURE pr_lg_staff_profiles;
