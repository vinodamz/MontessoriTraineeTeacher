-- ============================================================================
-- Little Graduates — unified MySQL schema (utf8mb4 / InnoDB).
--
-- One schema, two modules:
--   - tasks       (LG Task Manager:  users, task_columns, task_recurrences, tasks)
--   - montessori  (Trainee Teacher Assessment: students, rating_config,
--                 skill_indicators, student_custom_indicators, evaluation_cards,
--                 assessments, assessment_comments, student_baselines)
--
-- Apply once on a fresh database. Re-applying is destructive (DROP first).
-- For an existing MTT database, run sql/migrate_001_unify_users.sql instead.
-- ============================================================================

SET NAMES utf8mb4;
SET sql_mode = 'STRICT_TRANS_TABLES,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION';

-- Drop in reverse-dependency order.
DROP TABLE IF EXISTS tasks;
DROP TABLE IF EXISTS task_recurrences;
DROP TABLE IF EXISTS task_columns;
DROP TABLE IF EXISTS assessment_comments;
DROP TABLE IF EXISTS assessments;
DROP TABLE IF EXISTS evaluation_cards;
DROP TABLE IF EXISTS student_baselines;
DROP TABLE IF EXISTS student_custom_indicators;
DROP TABLE IF EXISTS skill_indicators;
DROP TABLE IF EXISTS rating_config;
DROP TABLE IF EXISTS students;
DROP TABLE IF EXISTS users;

-- ----------------------------------------------------------------------------
-- Users (staff). PINs are bcrypt-hashed. The `modules` SET grants per-module
-- access; admins implicitly have access to every module regardless.
-- ----------------------------------------------------------------------------
CREATE TABLE users (
    id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
    name        VARCHAR(120) NOT NULL,
    pin_hash    VARCHAR(255) NOT NULL,
    role        ENUM('teacher','admin') NOT NULL DEFAULT 'teacher',
    modules     SET('tasks','montessori','students') NOT NULL DEFAULT '',
    active      TINYINT(1)   NOT NULL DEFAULT 1,
    created_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_users_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================================
-- Montessori module
-- ============================================================================

CREATE TABLE students (
    id                       INT UNSIGNED NOT NULL AUTO_INCREMENT,
    admission_number         VARCHAR(40)  NULL,
    first_name               VARCHAR(80)  NOT NULL,
    last_name                VARCHAR(80)  NOT NULL DEFAULT '',
    gender                   ENUM('Male','Female','Other') NULL,
    dob                      DATE         NULL,
    joining_date             DATE         NULL,
    blood_group              VARCHAR(5)   NULL,
    allergies                TEXT         NULL,
    medical_notes            TEXT         NULL,
    home_address             TEXT         NULL,
    pickup_person            VARCHAR(120) NULL,
    pickup_phone             VARCHAR(40)  NULL,
    emergency_contact_name   VARCHAR(120) NULL,
    emergency_contact_phone  VARCHAR(40)  NULL,
    photo_path               VARCHAR(255) NULL,
    notes                    TEXT         NULL,
    is_active                TINYINT(1)   NOT NULL DEFAULT 1,
    grade                    ENUM('Playgroup','Nursery','LKG','UKG') NOT NULL,
    teacher_id               INT UNSIGNED NOT NULL,
    academic_year            VARCHAR(9)   NULL,
    enrollment_status        ENUM('enrolled','promoted','withdrawn','graduated','on_break')
                             NOT NULL DEFAULT 'enrolled',
    withdrawal_date          DATE         NULL,
    withdrawal_reason        VARCHAR(40)  NULL,
    withdrawal_notes         TEXT         NULL,
    created_at               DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_students_admission (admission_number),
    KEY idx_students_teacher (teacher_id),
    KEY idx_students_grade   (grade),
    KEY idx_students_active  (is_active),
    KEY idx_students_year    (academic_year),
    KEY idx_students_status  (enrollment_status),
    KEY idx_students_withdrawal_reason (withdrawal_reason),
    CONSTRAINT fk_students_teacher
        FOREIGN KEY (teacher_id) REFERENCES users(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

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

CREATE TABLE rating_config (
    id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
    code          CHAR(1)      NOT NULL,
    label         VARCHAR(60)  NOT NULL,
    color         VARCHAR(20)  NOT NULL,
    numeric_value INT          NOT NULL,
    is_active     TINYINT(1)   NOT NULL DEFAULT 1,
    created_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_rating_code (code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE skill_indicators (
    id             INT UNSIGNED NOT NULL AUTO_INCREMENT,
    grade          ENUM('Playgroup','Nursery','LKG','UKG') NOT NULL,
    category       VARCHAR(60)  NOT NULL,
    indicator_text TEXT         NOT NULL,
    display_order  INT          NOT NULL DEFAULT 0,
    is_active      TINYINT(1)   NOT NULL DEFAULT 1,
    created_at     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_si_grade_cat (grade, category, display_order)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE student_custom_indicators (
    id             INT UNSIGNED NOT NULL AUTO_INCREMENT,
    student_id     INT UNSIGNED NOT NULL,
    teacher_id     INT UNSIGNED NOT NULL,
    category       VARCHAR(60)  NOT NULL,
    indicator_text TEXT         NOT NULL,
    display_order  INT          NOT NULL DEFAULT 0,
    is_active      TINYINT(1)   NOT NULL DEFAULT 1,
    created_at     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_sci_student (student_id),
    CONSTRAINT fk_sci_student FOREIGN KEY (student_id) REFERENCES students(id) ON DELETE CASCADE,
    CONSTRAINT fk_sci_teacher FOREIGN KEY (teacher_id) REFERENCES users(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE evaluation_cards (
    id                  INT UNSIGNED NOT NULL AUTO_INCREMENT,
    student_id          INT UNSIGNED NOT NULL,
    teacher_id          INT UNSIGNED NOT NULL,
    month_year          VARCHAR(8)   NOT NULL,
    indicator_id        INT UNSIGNED NOT NULL,
    rating              CHAR(1)      NOT NULL,
    is_custom_indicator TINYINT(1)   NOT NULL DEFAULT 0,
    created_at          DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_ec (student_id, teacher_id, month_year, indicator_id, is_custom_indicator),
    KEY idx_ec_student_month (student_id, month_year),
    CONSTRAINT fk_ec_student FOREIGN KEY (student_id) REFERENCES students(id) ON DELETE CASCADE,
    CONSTRAINT fk_ec_teacher FOREIGN KEY (teacher_id) REFERENCES users(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE assessments (
    id           INT UNSIGNED NOT NULL AUTO_INCREMENT,
    student_id   INT UNSIGNED NOT NULL,
    teacher_id   INT UNSIGNED NOT NULL,
    month_year   VARCHAR(8)   NOT NULL,
    category     VARCHAR(60)  NOT NULL,
    score        INT          NOT NULL,
    category_avg DECIMAL(4,2) NOT NULL,
    created_at   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_a (student_id, teacher_id, month_year, category),
    CONSTRAINT fk_a_student FOREIGN KEY (student_id) REFERENCES students(id) ON DELETE CASCADE,
    CONSTRAINT fk_a_teacher FOREIGN KEY (teacher_id) REFERENCES users(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE assessment_comments (
    id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
    student_id  INT UNSIGNED NOT NULL,
    teacher_id  INT UNSIGNED NOT NULL,
    month_year  VARCHAR(8)   NOT NULL,
    category    VARCHAR(60)  DEFAULT NULL,
    comment     TEXT         NOT NULL,
    created_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_ac_student_month (student_id, month_year),
    CONSTRAINT fk_ac_student FOREIGN KEY (student_id) REFERENCES students(id) ON DELETE CASCADE,
    CONSTRAINT fk_ac_teacher FOREIGN KEY (teacher_id) REFERENCES users(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE student_baselines (
    id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
    student_id    INT UNSIGNED NOT NULL,
    teacher_id    INT UNSIGNED NOT NULL,
    recorded_by   VARCHAR(120) NOT NULL DEFAULT '',
    gross_motor   TEXT,
    fine_motor    TEXT,
    literacy      TEXT,
    numeracy      TEXT,
    social_skills TEXT,
    communication TEXT,
    overall_notes TEXT,
    recorded_at   DATE,
    created_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_baseline_student (student_id),
    CONSTRAINT fk_sb_student FOREIGN KEY (student_id) REFERENCES students(id) ON DELETE CASCADE,
    CONSTRAINT fk_sb_teacher FOREIGN KEY (teacher_id) REFERENCES users(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================================
-- Tasks module
-- ============================================================================

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

INSERT IGNORE INTO task_columns (name, position, color, is_done) VALUES
    ('To do',       1, '#EC407A', 0),
    ('In progress', 2, '#F5B342', 0),
    ('Done',        3, '#5BA547', 1);

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

-- After running this schema, open /install.php to create the first admin.
-- For seed data (rating scheme + curriculum indicators), run sql/seeds.sql next.
