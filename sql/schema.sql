-- ============================================================================
-- Montessori Trainee Teacher — MySQL schema (utf8mb4 / InnoDB).
--
-- This schema is the MySQL port of the Supabase migrations preserved on the
-- `react-legacy` branch. PINs are bcrypt-hashed (no plaintext).
--
-- Apply once on a fresh database. Re-applying is destructive (DROP first).
-- ============================================================================

SET NAMES utf8mb4;
SET sql_mode = 'STRICT_TRANS_TABLES,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION';

DROP TABLE IF EXISTS assessment_comments;
DROP TABLE IF EXISTS assessments;
DROP TABLE IF EXISTS evaluation_cards;
DROP TABLE IF EXISTS student_baselines;
DROP TABLE IF EXISTS student_custom_indicators;
DROP TABLE IF EXISTS skill_indicators;
DROP TABLE IF EXISTS rating_config;
DROP TABLE IF EXISTS students;
DROP TABLE IF EXISTS teachers;

-- ----------------------------------------------------------------------------
-- Teachers (staff). PINs are bcrypt-hashed. Role decides admin-only pages.
-- ----------------------------------------------------------------------------
CREATE TABLE teachers (
    id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
    name        VARCHAR(120) NOT NULL,
    pin_hash    VARCHAR(255) NOT NULL,
    role        ENUM('teacher','admin') NOT NULL DEFAULT 'teacher',
    active      TINYINT(1)   NOT NULL DEFAULT 1,
    created_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_teachers_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ----------------------------------------------------------------------------
-- Students. Each is assigned to exactly one teacher.
-- ----------------------------------------------------------------------------
CREATE TABLE students (
    id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
    first_name  VARCHAR(80)  NOT NULL,
    last_name   VARCHAR(80)  NOT NULL DEFAULT '',
    grade       ENUM('Playgroup','Nursery','LKG','UKG') NOT NULL,
    teacher_id  INT UNSIGNED NOT NULL,
    created_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_students_teacher (teacher_id),
    KEY idx_students_grade   (grade),
    CONSTRAINT fk_students_teacher
        FOREIGN KEY (teacher_id) REFERENCES teachers(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ----------------------------------------------------------------------------
-- Rating scheme. D = developed, P = progressing, N = needs attention.
-- Numeric values feed category averages (D=5, P=3, N=1).
-- ----------------------------------------------------------------------------
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

-- ----------------------------------------------------------------------------
-- Curriculum indicators (grade-scoped, category-grouped, ordered).
-- ----------------------------------------------------------------------------
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

-- ----------------------------------------------------------------------------
-- Per-student indicators that don't fit the standard curriculum.
-- ----------------------------------------------------------------------------
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
    CONSTRAINT fk_sci_teacher FOREIGN KEY (teacher_id) REFERENCES teachers(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ----------------------------------------------------------------------------
-- One row per (student, teacher, month, indicator) rating.
-- indicator_id resolves to skill_indicators OR student_custom_indicators —
-- which one is told by is_custom_indicator. No FK on indicator_id by design.
-- ----------------------------------------------------------------------------
CREATE TABLE evaluation_cards (
    id                  INT UNSIGNED NOT NULL AUTO_INCREMENT,
    student_id          INT UNSIGNED NOT NULL,
    teacher_id          INT UNSIGNED NOT NULL,
    month_year          VARCHAR(8)   NOT NULL,
    indicator_id        INT UNSIGNED NOT NULL,
    rating              ENUM('D','P','N') NOT NULL,
    is_custom_indicator TINYINT(1)   NOT NULL DEFAULT 0,
    created_at          DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_ec (student_id, teacher_id, month_year, indicator_id, is_custom_indicator),
    KEY idx_ec_student_month (student_id, month_year),
    CONSTRAINT fk_ec_student FOREIGN KEY (student_id) REFERENCES students(id) ON DELETE CASCADE,
    CONSTRAINT fk_ec_teacher FOREIGN KEY (teacher_id) REFERENCES teachers(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ----------------------------------------------------------------------------
-- Per-category monthly score summaries derived from evaluation_cards.
-- ----------------------------------------------------------------------------
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
    CONSTRAINT fk_a_teacher FOREIGN KEY (teacher_id) REFERENCES teachers(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ----------------------------------------------------------------------------
-- Narrative comments per (student, month, optional category).
-- category = NULL → overall monthly comment.
-- ----------------------------------------------------------------------------
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
    CONSTRAINT fk_ac_teacher FOREIGN KEY (teacher_id) REFERENCES teachers(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ----------------------------------------------------------------------------
-- Entry baselines — one per student, captured early in the year.
-- ----------------------------------------------------------------------------
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
    CONSTRAINT fk_sb_teacher FOREIGN KEY (teacher_id) REFERENCES teachers(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
