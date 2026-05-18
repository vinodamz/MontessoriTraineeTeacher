-- ============================================================================
-- migrate_002_student_module.sql
--
-- Extends `students` with profile fields (DOB, gender, photo, address,
-- emergency contact, etc.), adds the `student_parents` table for
-- parent/guardian records, and expands `users.modules` SET to include the
-- new 'students' module.
--
-- Idempotent — uses information_schema guards. Re-running is safe.
--
-- Apply via /migrate.php (admin login) or phpMyAdmin → Import.
-- ============================================================================

SET NAMES utf8mb4;
SET sql_mode = 'STRICT_TRANS_TABLES,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION';

DROP PROCEDURE IF EXISTS pr_lg_student_module;
DELIMITER //
CREATE PROCEDURE pr_lg_student_module()
BEGIN
    -- ------------------------------------------------------------------
    -- 1. Expand users.modules SET to include the new 'students' module.
    --    Existing rows keep their current modules; only the SET definition
    --    is altered. This is a fast metadata-only change.
    -- ------------------------------------------------------------------
    IF EXISTS (
        SELECT 1 FROM information_schema.columns
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME   = 'users'
          AND COLUMN_NAME  = 'modules'
          AND COLUMN_TYPE  NOT LIKE '%students%'
    ) THEN
        ALTER TABLE users
            MODIFY modules SET('tasks','montessori','students') NOT NULL DEFAULT '';
    END IF;

    -- ------------------------------------------------------------------
    -- 2. Extend `students` with profile + address + emergency fields.
    --    Each column add is guarded so re-running is a no-op.
    -- ------------------------------------------------------------------
    IF NOT EXISTS (SELECT 1 FROM information_schema.columns
                   WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='students' AND COLUMN_NAME='admission_number') THEN
        ALTER TABLE students ADD COLUMN admission_number VARCHAR(40) NULL AFTER id;
        ALTER TABLE students ADD UNIQUE KEY uq_students_admission (admission_number);
    END IF;

    IF NOT EXISTS (SELECT 1 FROM information_schema.columns
                   WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='students' AND COLUMN_NAME='gender') THEN
        ALTER TABLE students ADD COLUMN gender ENUM('Male','Female','Other') NULL AFTER last_name;
    END IF;

    IF NOT EXISTS (SELECT 1 FROM information_schema.columns
                   WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='students' AND COLUMN_NAME='dob') THEN
        ALTER TABLE students ADD COLUMN dob DATE NULL AFTER gender;
    END IF;

    IF NOT EXISTS (SELECT 1 FROM information_schema.columns
                   WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='students' AND COLUMN_NAME='joining_date') THEN
        ALTER TABLE students ADD COLUMN joining_date DATE NULL AFTER dob;
    END IF;

    IF NOT EXISTS (SELECT 1 FROM information_schema.columns
                   WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='students' AND COLUMN_NAME='blood_group') THEN
        ALTER TABLE students ADD COLUMN blood_group VARCHAR(5) NULL AFTER joining_date;
    END IF;

    IF NOT EXISTS (SELECT 1 FROM information_schema.columns
                   WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='students' AND COLUMN_NAME='allergies') THEN
        ALTER TABLE students ADD COLUMN allergies TEXT NULL AFTER blood_group;
    END IF;

    IF NOT EXISTS (SELECT 1 FROM information_schema.columns
                   WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='students' AND COLUMN_NAME='medical_notes') THEN
        ALTER TABLE students ADD COLUMN medical_notes TEXT NULL AFTER allergies;
    END IF;

    IF NOT EXISTS (SELECT 1 FROM information_schema.columns
                   WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='students' AND COLUMN_NAME='home_address') THEN
        ALTER TABLE students ADD COLUMN home_address TEXT NULL AFTER medical_notes;
    END IF;

    IF NOT EXISTS (SELECT 1 FROM information_schema.columns
                   WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='students' AND COLUMN_NAME='pickup_person') THEN
        ALTER TABLE students ADD COLUMN pickup_person VARCHAR(120) NULL AFTER home_address;
    END IF;

    IF NOT EXISTS (SELECT 1 FROM information_schema.columns
                   WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='students' AND COLUMN_NAME='pickup_phone') THEN
        ALTER TABLE students ADD COLUMN pickup_phone VARCHAR(40) NULL AFTER pickup_person;
    END IF;

    IF NOT EXISTS (SELECT 1 FROM information_schema.columns
                   WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='students' AND COLUMN_NAME='emergency_contact_name') THEN
        ALTER TABLE students ADD COLUMN emergency_contact_name VARCHAR(120) NULL AFTER pickup_phone;
    END IF;

    IF NOT EXISTS (SELECT 1 FROM information_schema.columns
                   WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='students' AND COLUMN_NAME='emergency_contact_phone') THEN
        ALTER TABLE students ADD COLUMN emergency_contact_phone VARCHAR(40) NULL AFTER emergency_contact_name;
    END IF;

    IF NOT EXISTS (SELECT 1 FROM information_schema.columns
                   WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='students' AND COLUMN_NAME='photo_path') THEN
        ALTER TABLE students ADD COLUMN photo_path VARCHAR(255) NULL AFTER emergency_contact_phone;
    END IF;

    IF NOT EXISTS (SELECT 1 FROM information_schema.columns
                   WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='students' AND COLUMN_NAME='notes') THEN
        ALTER TABLE students ADD COLUMN notes TEXT NULL AFTER photo_path;
    END IF;

    IF NOT EXISTS (SELECT 1 FROM information_schema.columns
                   WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='students' AND COLUMN_NAME='is_active') THEN
        ALTER TABLE students ADD COLUMN is_active TINYINT(1) NOT NULL DEFAULT 1 AFTER notes;
        ALTER TABLE students ADD INDEX idx_students_active (is_active);
    END IF;

    -- ------------------------------------------------------------------
    -- 3. New table: parents / guardians per student.
    -- ------------------------------------------------------------------
    IF NOT EXISTS (SELECT 1 FROM information_schema.tables
                   WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='student_parents') THEN
        CREATE TABLE student_parents (
            id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
            student_id  INT UNSIGNED NOT NULL,
            relation    ENUM('father','mother','guardian','other') NOT NULL DEFAULT 'guardian',
            name        VARCHAR(120) NOT NULL,
            phone       VARCHAR(40)  NULL,
            email       VARCHAR(120) NULL,
            occupation  VARCHAR(120) NULL,
            address     TEXT         NULL,
            is_primary  TINYINT(1)   NOT NULL DEFAULT 0,
            created_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_sp_student (student_id),
            CONSTRAINT fk_sp_student FOREIGN KEY (student_id) REFERENCES students(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    END IF;
END //
DELIMITER ;
CALL pr_lg_student_module();
DROP PROCEDURE pr_lg_student_module;
