-- ============================================================================
-- migrate_001_unify_users.sql
--
-- One-shot in-place migration for the existing MTT database. Converts the
-- `teachers` table into a unified `users` table that backs both the
-- montessori and tasks modules, and repoints every FK that referenced
-- teachers(id) at users(id). Then creates the tasks-module tables.
--
-- Idempotent: re-running is a no-op (uses information_schema checks).
--
-- Run via /migrate.php (admin login) or phpMyAdmin → Import.
-- ============================================================================

SET NAMES utf8mb4;
SET sql_mode = 'STRICT_TRANS_TABLES,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION';

DROP PROCEDURE IF EXISTS pr_lg_unify_users;
DELIMITER //
CREATE PROCEDURE pr_lg_unify_users()
BEGIN
    -- 1. Create users table if missing.
    IF NOT EXISTS (
        SELECT 1 FROM information_schema.TABLES
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users'
    ) THEN
        CREATE TABLE users (
            id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
            name        VARCHAR(120) NOT NULL,
            pin_hash    VARCHAR(255) NOT NULL,
            role        ENUM('teacher','admin') NOT NULL DEFAULT 'teacher',
            modules     SET('tasks','montessori') NOT NULL DEFAULT '',
            active      TINYINT(1)   NOT NULL DEFAULT 1,
            created_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uq_users_name (name)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    END IF;

    -- 2. If teachers table still exists, copy its rows into users (preserving ids)
    --    and tag them with the 'montessori' module. Admins get both modules.
    IF EXISTS (
        SELECT 1 FROM information_schema.TABLES
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'teachers'
    ) THEN
        INSERT INTO users (id, name, pin_hash, role, modules, active, created_at)
        SELECT
            t.id, t.name, t.pin_hash, t.role,
            CASE WHEN t.role = 'admin' THEN 'tasks,montessori' ELSE 'montessori' END,
            t.active, t.created_at
        FROM teachers t
        WHERE NOT EXISTS (SELECT 1 FROM users u WHERE u.id = t.id);

        -- 3. Repoint each FK that referenced teachers(id) at users(id).
        IF EXISTS (
            SELECT 1 FROM information_schema.TABLE_CONSTRAINTS
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'students'
              AND CONSTRAINT_NAME = 'fk_students_teacher'
        ) THEN
            ALTER TABLE students DROP FOREIGN KEY fk_students_teacher;
        END IF;
        ALTER TABLE students
            ADD CONSTRAINT fk_students_teacher FOREIGN KEY (teacher_id) REFERENCES users(id) ON DELETE RESTRICT;

        IF EXISTS (
            SELECT 1 FROM information_schema.TABLE_CONSTRAINTS
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'student_custom_indicators'
              AND CONSTRAINT_NAME = 'fk_sci_teacher'
        ) THEN
            ALTER TABLE student_custom_indicators DROP FOREIGN KEY fk_sci_teacher;
        END IF;
        ALTER TABLE student_custom_indicators
            ADD CONSTRAINT fk_sci_teacher FOREIGN KEY (teacher_id) REFERENCES users(id) ON DELETE RESTRICT;

        IF EXISTS (
            SELECT 1 FROM information_schema.TABLE_CONSTRAINTS
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'evaluation_cards'
              AND CONSTRAINT_NAME = 'fk_ec_teacher'
        ) THEN
            ALTER TABLE evaluation_cards DROP FOREIGN KEY fk_ec_teacher;
        END IF;
        ALTER TABLE evaluation_cards
            ADD CONSTRAINT fk_ec_teacher FOREIGN KEY (teacher_id) REFERENCES users(id) ON DELETE RESTRICT;

        IF EXISTS (
            SELECT 1 FROM information_schema.TABLE_CONSTRAINTS
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'assessments'
              AND CONSTRAINT_NAME = 'fk_a_teacher'
        ) THEN
            ALTER TABLE assessments DROP FOREIGN KEY fk_a_teacher;
        END IF;
        ALTER TABLE assessments
            ADD CONSTRAINT fk_a_teacher FOREIGN KEY (teacher_id) REFERENCES users(id) ON DELETE RESTRICT;

        IF EXISTS (
            SELECT 1 FROM information_schema.TABLE_CONSTRAINTS
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'assessment_comments'
              AND CONSTRAINT_NAME = 'fk_ac_teacher'
        ) THEN
            ALTER TABLE assessment_comments DROP FOREIGN KEY fk_ac_teacher;
        END IF;
        ALTER TABLE assessment_comments
            ADD CONSTRAINT fk_ac_teacher FOREIGN KEY (teacher_id) REFERENCES users(id) ON DELETE RESTRICT;

        IF EXISTS (
            SELECT 1 FROM information_schema.TABLE_CONSTRAINTS
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'student_baselines'
              AND CONSTRAINT_NAME = 'fk_sb_teacher'
        ) THEN
            ALTER TABLE student_baselines DROP FOREIGN KEY fk_sb_teacher;
        END IF;
        ALTER TABLE student_baselines
            ADD CONSTRAINT fk_sb_teacher FOREIGN KEY (teacher_id) REFERENCES users(id) ON DELETE RESTRICT;

        -- 4. Drop the legacy teachers table now that everything points at users.
        DROP TABLE teachers;
    END IF;

    -- 5. Add task module tables.
    IF NOT EXISTS (
        SELECT 1 FROM information_schema.TABLES
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'task_columns'
    ) THEN
        CREATE TABLE task_columns (
            id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            name          VARCHAR(50)    NOT NULL,
            position      INT UNSIGNED   NOT NULL DEFAULT 0,
            color         VARCHAR(7)     NOT NULL DEFAULT '#EC407A',
            is_done       TINYINT(1)     NOT NULL DEFAULT 0,
            created_at    TIMESTAMP      NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at    TIMESTAMP      NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uq_name (name),
            INDEX idx_position (position)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

        INSERT INTO task_columns (name, position, color, is_done) VALUES
            ('To do',       1, '#EC407A', 0),
            ('In progress', 2, '#F5B342', 0),
            ('Done',        3, '#5BA547', 1);
    END IF;

    IF NOT EXISTS (
        SELECT 1 FROM information_schema.TABLES
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'task_recurrences'
    ) THEN
        CREATE TABLE task_recurrences (
            id                   INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            title                VARCHAR(200)   NOT NULL,
            description          TEXT           NULL,
            priority             ENUM('low','normal','high') NOT NULL DEFAULT 'normal',
            column_id            INT UNSIGNED   NULL,
            assigned_to_user_id  INT UNSIGNED   NULL,
            frequency            ENUM('daily','weekly','monthly') NOT NULL DEFAULT 'daily',
            days_mask            TINYINT UNSIGNED NOT NULL DEFAULT 127,
            day_of_month         TINYINT UNSIGNED NULL,
            due_offset_days      INT             NOT NULL DEFAULT 0,
            start_date           DATE           NOT NULL,
            end_date             DATE           NULL,
            is_active            TINYINT(1)     NOT NULL DEFAULT 1,
            created_by_user_id   INT UNSIGNED   NOT NULL,
            created_at           TIMESTAMP      NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at           TIMESTAMP      NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_active (is_active, start_date),
            CONSTRAINT fk_rec_column   FOREIGN KEY (column_id)           REFERENCES task_columns(id) ON DELETE SET NULL,
            CONSTRAINT fk_rec_assignee FOREIGN KEY (assigned_to_user_id) REFERENCES users(id)        ON DELETE SET NULL,
            CONSTRAINT fk_rec_creator  FOREIGN KEY (created_by_user_id)  REFERENCES users(id)        ON DELETE RESTRICT
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    END IF;

    IF NOT EXISTS (
        SELECT 1 FROM information_schema.TABLES
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tasks'
    ) THEN
        CREATE TABLE tasks (
            id                  INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            title               VARCHAR(200) NOT NULL,
            description         TEXT         NULL,
            status              ENUM('todo','in_progress','done') NOT NULL DEFAULT 'todo',
            column_id           INT UNSIGNED NULL,
            board_position      INT UNSIGNED NOT NULL DEFAULT 0,
            priority            ENUM('low','normal','high') NOT NULL DEFAULT 'normal',
            due_date            DATE         NULL,
            assigned_to_user_id INT UNSIGNED NULL,
            created_by_user_id  INT UNSIGNED NOT NULL,
            recurrence_id       INT UNSIGNED NULL,
            instance_date       DATE         NULL,
            created_at          TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at          TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_status (status),
            INDEX idx_col_pos (column_id, board_position),
            INDEX idx_assigned (assigned_to_user_id),
            INDEX idx_due (due_date),
            INDEX idx_instance_date (instance_date),
            UNIQUE KEY uq_recurrence_date (recurrence_id, instance_date),
            CONSTRAINT fk_tasks_column     FOREIGN KEY (column_id)           REFERENCES task_columns(id)      ON DELETE RESTRICT,
            CONSTRAINT fk_tasks_assigned   FOREIGN KEY (assigned_to_user_id) REFERENCES users(id)             ON DELETE SET NULL,
            CONSTRAINT fk_tasks_creator    FOREIGN KEY (created_by_user_id)  REFERENCES users(id)             ON DELETE RESTRICT,
            CONSTRAINT fk_tasks_recurrence FOREIGN KEY (recurrence_id)       REFERENCES task_recurrences(id)  ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    END IF;

    -- 6. Bump every existing admin to have both modules (they should already,
    --    but covers edge cases where the user already existed with empty SET).
    UPDATE users SET modules = CONCAT_WS(',', NULLIF(modules,''), 'tasks')
        WHERE role = 'admin' AND FIND_IN_SET('tasks', modules) = 0;
    UPDATE users SET modules = CONCAT_WS(',', NULLIF(modules,''), 'montessori')
        WHERE role = 'admin' AND FIND_IN_SET('montessori', modules) = 0;

END //
DELIMITER ;
CALL pr_lg_unify_users();
DROP PROCEDURE pr_lg_unify_users;
