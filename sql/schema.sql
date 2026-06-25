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
DROP TABLE IF EXISTS notification_preferences;
DROP TABLE IF EXISTS notifications;
DROP TABLE IF EXISTS inquiry_touchpoints;
DROP TABLE IF EXISTS inquiry_children;
DROP TABLE IF EXISTS inquiry_parents;
DROP TABLE IF EXISTS inquiry_families;
DROP TABLE IF EXISTS crm_campaigns;
DROP TABLE IF EXISTS app_settings;
DROP TABLE IF EXISTS expenses;
DROP TABLE IF EXISTS expense_categories;
DROP TABLE IF EXISTS task_deletions;
DROP TABLE IF EXISTS task_attachments;
DROP TABLE IF EXISTS task_subtasks;
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
DROP TABLE IF EXISTS fee_payments;
DROP TABLE IF EXISTS fee_invoices;
DROP TABLE IF EXISTS attendance;
DROP TABLE IF EXISTS student_form_tokens;
DROP TABLE IF EXISTS student_documents;
DROP TABLE IF EXISTS student_parents;
DROP TABLE IF EXISTS students;
DROP TABLE IF EXISTS users;

-- ----------------------------------------------------------------------------
-- Users (staff). PINs are bcrypt-hashed. The `modules` SET grants per-module
-- access; admins implicitly have access to every module regardless.
-- ----------------------------------------------------------------------------
CREATE TABLE app_settings (
    setting_key   VARCHAR(60) NOT NULL,
    setting_value TEXT        NULL,
    updated_at    DATETIME    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (setting_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT INTO app_settings (setting_key, setting_value) VALUES
    ('app_name',           'Little Graduates'),
    ('app_short_name',     'LG'),
    ('email_from_name',    'Little Graduates'),
    ('email_from_address', 'no-reply@thelittlegraduates.in');

CREATE TABLE notifications (
    id           INT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id      INT UNSIGNED NOT NULL,
    category     ENUM('tasks','attendance','fees','students','system') NOT NULL DEFAULT 'system',
    event_type   VARCHAR(40)  NOT NULL,
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

CREATE TABLE users (
    id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
    name        VARCHAR(120) NOT NULL,
    pin_hash    VARCHAR(255) NOT NULL,
    role        ENUM('teacher','admin') NOT NULL DEFAULT 'teacher',
    modules     SET('tasks','montessori','students','crm','recruitment','staff','expenses','fees','logbook','inventory','wacrm','n8n') NOT NULL DEFAULT '',
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
    place_of_birth           VARCHAR(120) NULL,
    nationality              VARCHAR(60)  NULL,
    mother_tongue            VARCHAR(60)  NULL,
    joining_date             DATE         NULL,
    admission_type           ENUM('new','old') NULL,
    blood_group              VARCHAR(5)   NULL,
    allergies                TEXT         NULL,
    medical_notes            TEXT         NULL,
    home_address             TEXT         NULL,
    -- Permanent / home-town address. home_address is the local/current address.
    permanent_address        TEXT         NULL,
    pickup_person            VARCHAR(120) NULL,
    pickup_phone             VARCHAR(40)  NULL,
    emergency_contact_name   VARCHAR(120) NULL,
    emergency_contact_phone  VARCHAR(40)  NULL,
    emergency_contact_relation VARCHAR(60) NULL,
    emergency_contact_address TEXT        NULL,
    photo_path               VARCHAR(255) NULL,
    notes                    TEXT         NULL,
    -- Free-form, one line per sibling: "Name | Gender | Age | Class | School".
    sibling_details          TEXT         NULL,
    -- Three-state media/data consent: 1=yes, 0=no, NULL=unknown.
    consent_given            TINYINT(1)   NULL,
    consent_date             DATE         NULL,
    transport                ENUM('own','cab','bus','walk') NULL,
    is_active                TINYINT(1)   NOT NULL DEFAULT 1,
    grade                    ENUM('Playgroup','Nursery','LKG','UKG') NOT NULL,
    -- Classroom section (A/B/C/...). Free-form so the list can grow by
    -- editing STUDENT_SECTIONS in includes/functions.php.
    section                  VARCHAR(20)  NULL,
    teacher_id               INT UNSIGNED NOT NULL,
    academic_year            VARCHAR(9)   NULL,
    enrollment_status        ENUM('enrolled','promoted','withdrawn','graduated','on_break','intake_pending')
                             NOT NULL DEFAULT 'enrolled',
    -- Stamped when an admin promotes an intake_pending row to enrolled.
    -- Drives the "Added on …" display on the student profile.
    intake_approved_at       DATETIME     NULL,
    withdrawal_date          DATE         NULL,
    withdrawal_reason        VARCHAR(40)  NULL,
    withdrawal_notes         TEXT         NULL,
    created_at               DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_students_admission (admission_number),
    KEY idx_students_teacher (teacher_id),
    KEY idx_students_grade   (grade),
    KEY idx_students_section (section),
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
    workplace   VARCHAR(160) NULL,
    address     TEXT         NULL,
    photo_path  VARCHAR(255) NULL,
    is_primary  TINYINT(1)   NOT NULL DEFAULT 0,
    created_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_sp_student (student_id),
    CONSTRAINT fk_sp_student FOREIGN KEY (student_id) REFERENCES students(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Public per-student form tokens. The token is the auth: anyone holding
-- it can open /students/parent_form.php?token=… and update the child's
-- record (no school login needed). Admin generates/revokes from
-- /students/view.php.
CREATE TABLE student_form_tokens (
    id                  INT UNSIGNED NOT NULL AUTO_INCREMENT,
    student_id          INT UNSIGNED NOT NULL,
    token               CHAR(64)     NOT NULL,
    created_by_user_id  INT UNSIGNED NOT NULL,
    created_at          DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    last_accessed_at    DATETIME     NULL,
    last_saved_at       DATETIME     NULL,
    revoked_at          DATETIME     NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_sft_token   (token),
    KEY        idx_sft_student (student_id, revoked_at),
    CONSTRAINT fk_sft_student FOREIGN KEY (student_id)         REFERENCES students(id) ON DELETE CASCADE,
    CONSTRAINT fk_sft_creator FOREIGN KEY (created_by_user_id) REFERENCES users(id)    ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE student_documents (
    id                  INT UNSIGNED NOT NULL AUTO_INCREMENT,
    student_id          INT UNSIGNED NOT NULL,
    category            ENUM('birth_certificate','vaccination','id_proof','medical','school','other')
                        NOT NULL DEFAULT 'other',
    title               VARCHAR(160) NOT NULL,
    original_filename   VARCHAR(255) NOT NULL,
    stored_filename     VARCHAR(80)  NOT NULL,
    mime_type           VARCHAR(120) NOT NULL,
    size_bytes          INT UNSIGNED NOT NULL,
    uploaded_by_user_id INT UNSIGNED NOT NULL,
    uploaded_at         DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_sd_student (student_id, uploaded_at),
    UNIQUE KEY uq_stored_filename (stored_filename),
    CONSTRAINT fk_sd_student  FOREIGN KEY (student_id)          REFERENCES students(id) ON DELETE CASCADE,
    CONSTRAINT fk_sd_uploader FOREIGN KEY (uploaded_by_user_id) REFERENCES users(id)    ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

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

CREATE TABLE fee_invoices (
    id                  INT UNSIGNED NOT NULL AUTO_INCREMENT,
    student_id          INT UNSIGNED NOT NULL,
    title               VARCHAR(120) NOT NULL,
    period              VARCHAR(30)  NULL,
    amount              DECIMAL(10,2) NOT NULL,
    issue_date          DATE         NOT NULL,
    due_date            DATE         NULL,
    status              ENUM('open','paid','partial','waived','cancelled') NOT NULL DEFAULT 'open',
    notes               TEXT         NULL,
    created_by_user_id  INT UNSIGNED NOT NULL,
    created_at          DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at          DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_fi_student (student_id, issue_date),
    KEY idx_fi_due     (due_date),
    CONSTRAINT fk_fi_student FOREIGN KEY (student_id)         REFERENCES students(id) ON DELETE CASCADE,
    CONSTRAINT fk_fi_creator FOREIGN KEY (created_by_user_id) REFERENCES users(id)    ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE fee_payments (
    id                  INT UNSIGNED NOT NULL AUTO_INCREMENT,
    invoice_id          INT UNSIGNED NOT NULL,
    amount              DECIMAL(10,2) NOT NULL,
    paid_on             DATE         NOT NULL,
    method              ENUM('cash','bank_transfer','upi','card','cheque','cofee','other') NOT NULL DEFAULT 'cash',
    reference_no        VARCHAR(80)  NULL,
    -- Public receipt link: /receipt.php?t=<token>, no login. Same
    -- link-only pattern as the parent admission form.
    receipt_token       CHAR(32)     NULL,
    notes               TEXT         NULL,
    recorded_by_user_id INT UNSIGNED NOT NULL,
    created_at          DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_fp_receipt (receipt_token),
    KEY idx_fp_invoice (invoice_id, paid_on),
    CONSTRAINT fk_fp_invoice  FOREIGN KEY (invoice_id)          REFERENCES fee_invoices(id) ON DELETE CASCADE,
    CONSTRAINT fk_fp_recorder FOREIGN KEY (recorded_by_user_id) REFERENCES users(id)        ON DELETE RESTRICT
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
    -- Soft-delete tombstone (migration 032). The Trash page reads it.
    deleted_at          DATETIME     NULL,
    INDEX idx_status (status),
    INDEX idx_col_pos (column_id, board_position),
    INDEX idx_assigned (assigned_to_user_id),
    INDEX idx_due (due_date),
    INDEX idx_instance_date (instance_date),
    INDEX idx_tasks_deleted (deleted_at),
    UNIQUE KEY uq_recurrence_date (recurrence_id, instance_date),
    CONSTRAINT fk_tasks_column     FOREIGN KEY (column_id)           REFERENCES task_columns(id)      ON DELETE RESTRICT,
    CONSTRAINT fk_tasks_assigned   FOREIGN KEY (assigned_to_user_id) REFERENCES users(id)             ON DELETE SET NULL,
    CONSTRAINT fk_tasks_creator    FOREIGN KEY (created_by_user_id)  REFERENCES users(id)             ON DELETE RESTRICT,
    CONSTRAINT fk_tasks_recurrence FOREIGN KEY (recurrence_id)       REFERENCES task_recurrences(id)  ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE task_subtasks (
    id               INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    task_id          INT UNSIGNED NOT NULL,
    title            VARCHAR(255) NOT NULL,
    done             TINYINT(1)   NOT NULL DEFAULT 0,
    assignee_user_id INT UNSIGNED NULL,
    order_idx        INT UNSIGNED NOT NULL DEFAULT 0,
    created_at       DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at       DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY idx_ts_task     (task_id, order_idx),
    KEY idx_ts_assignee (assignee_user_id),
    CONSTRAINT fk_ts_task FOREIGN KEY (task_id)          REFERENCES tasks(id) ON DELETE CASCADE,
    CONSTRAINT fk_ts_user FOREIGN KEY (assignee_user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE task_attachments (
    id                  INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    task_id             INT UNSIGNED NOT NULL,
    original_filename   VARCHAR(255) NOT NULL,
    stored_filename     VARCHAR(80)  NOT NULL,
    mime_type           VARCHAR(120) NOT NULL,
    size_bytes          INT UNSIGNED NOT NULL,
    uploaded_by_user_id INT UNSIGNED NOT NULL,
    uploaded_at         DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_ta_stored (stored_filename),
    KEY idx_ta_task (task_id, uploaded_at),
    CONSTRAINT fk_ta_task FOREIGN KEY (task_id)             REFERENCES tasks(id) ON DELETE CASCADE,
    CONSTRAINT fk_ta_user FOREIGN KEY (uploaded_by_user_id) REFERENCES users(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Append-only deletion audit. No FK on task_id so the row survives if a
-- task is ever hard-purged; snapshot_json holds the full task payload.
CREATE TABLE task_deletions (
    id                  INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    task_id             INT UNSIGNED NOT NULL,
    snapshot_json       MEDIUMTEXT   NOT NULL,
    deleted_by_user_id  INT UNSIGNED NOT NULL,
    deleted_at          DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    restored            TINYINT(1)   NOT NULL DEFAULT 0,
    restored_at         DATETIME     NULL,
    restored_by_user_id INT UNSIGNED NULL,
    KEY idx_td_task     (task_id),
    KEY idx_td_deleted  (deleted_at),
    KEY idx_td_restored (restored, deleted_at),
    CONSTRAINT fk_td_user     FOREIGN KEY (deleted_by_user_id)  REFERENCES users(id) ON DELETE RESTRICT,
    CONSTRAINT fk_td_restorer FOREIGN KEY (restored_by_user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ----------------------------------------------------------------------------
-- Admissions / CRM module — prospect funnel. On enrollment, children are
-- copied into `students` and linked back via inquiry_children.promoted_student_id.
-- Leads live in inquiry_families with status='lead' and graduate to 'new'
-- once contacted/qualified.
-- ----------------------------------------------------------------------------
CREATE TABLE crm_campaigns (
    id         INT UNSIGNED NOT NULL AUTO_INCREMENT,
    name       VARCHAR(120) NOT NULL,
    channel    ENUM('walk_in','referral','website','instagram','facebook',
                    'google','whatsapp','event','other')
               NOT NULL DEFAULT 'other',
    cost       DECIMAL(10,2) NULL,
    active     TINYINT(1)    NOT NULL DEFAULT 1,
    notes      TEXT          NULL,
    created_at DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_camp_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT INTO crm_campaigns (name, channel, active) VALUES
    ('Walk-in',       'walk_in',  1),
    ('Word of mouth', 'referral', 1),
    ('Website form',  'website',  1),
    ('Instagram',     'instagram',1);

-- Admin-editable pipeline stages — one row per kanban column on the
-- admissions board. See /crm/stages.php for the management UI.
CREATE TABLE crm_stages (
    id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
    code          VARCHAR(40)  NOT NULL,
    label         VARCHAR(60)  NOT NULL,
    display_order INT          NOT NULL DEFAULT 0,
    probability   TINYINT UNSIGNED NOT NULL DEFAULT 20,
    is_open       TINYINT(1)   NOT NULL DEFAULT 1,
    is_active     TINYINT(1)   NOT NULL DEFAULT 1,
    created_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_stage_code   (code),
    KEY        idx_stage_order (display_order)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT INTO crm_stages (code, label, display_order, probability, is_open) VALUES
    ('lead',                  'Leads',                 10,  10, 1),
    ('new',                   'New inquiry',           20,  20, 1),
    ('details_shared',        'Details shared',        30,  35, 1),
    ('tour_scheduled',        'Tour scheduled',        40,  45, 1),
    ('school_visited',        'School visited',        50,  60, 1),
    ('application_submitted', 'Application submitted', 60,  70, 1),
    ('offered',               'Offered',               70,  85, 1),
    ('enrolled',              'Enrolled',              80, 100, 0),
    ('waitlisted',            'Waitlisted',            90,  25, 1),
    ('lost',                  'Lost',                 100,   0, 0);

CREATE TABLE inquiry_families (
    id              INT UNSIGNED NOT NULL AUTO_INCREMENT,
    primary_name    VARCHAR(160) NOT NULL,
    primary_phone   VARCHAR(40)  NULL,
    primary_email   VARCHAR(160) NULL,
    source          VARCHAR(60)  NULL,
    campaign_id     INT UNSIGNED NULL,
    -- Stage code — references crm_stages.code. Free-form VARCHAR rather
    -- than an ENUM so admins can add stages from /crm/stages.php without
    -- a schema change.
    status          VARCHAR(40)  NOT NULL DEFAULT 'new',
    -- When status='lost' this captures the why (cost, distance, no_response, …).
    -- Free-form VARCHAR — label list lives in includes/crm.php.
    lost_reason     VARCHAR(40)  NULL,
    probability     TINYINT UNSIGNED NOT NULL DEFAULT 20,
    priority        ENUM('low','normal','high','urgent') NOT NULL DEFAULT 'normal',
    expected_fee    DECIMAL(10,2) NULL,
    expected_start  DATE NULL,
    notes           TEXT NULL,
    odoo_lead_id    INT UNSIGNED NULL,
    ip_hash         VARCHAR(64) NULL,
    owner_id        INT UNSIGNED NULL,
    enrolled_at     DATETIME NULL,
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_inq_odoo_lead (odoo_lead_id),
    KEY idx_inq_status      (status),
    KEY idx_inq_lost_reason (lost_reason),
    KEY idx_inq_created     (created_at),
    KEY idx_inq_priority (priority),
    KEY idx_inq_campaign (campaign_id),
    KEY idx_inq_ip_recent (ip_hash, created_at),
    CONSTRAINT fk_inq_owner    FOREIGN KEY (owner_id)    REFERENCES users(id)         ON DELETE SET NULL,
    CONSTRAINT fk_inq_campaign FOREIGN KEY (campaign_id) REFERENCES crm_campaigns(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE inquiry_parents (
    id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
    family_id   INT UNSIGNED NOT NULL,
    relation    ENUM('father','mother','guardian','other') NOT NULL DEFAULT 'guardian',
    name        VARCHAR(160) NOT NULL,
    phone       VARCHAR(40)  NULL,
    email       VARCHAR(160) NULL,
    occupation  VARCHAR(120) NULL,
    is_primary  TINYINT(1)   NOT NULL DEFAULT 0,
    PRIMARY KEY (id),
    KEY idx_ip_fam (family_id),
    CONSTRAINT fk_ip_fam FOREIGN KEY (family_id) REFERENCES inquiry_families(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE inquiry_children (
    id                  INT UNSIGNED NOT NULL AUTO_INCREMENT,
    family_id           INT UNSIGNED NOT NULL,
    first_name          VARCHAR(120) NOT NULL,
    last_name           VARCHAR(120) NULL,
    dob                 DATE NULL,
    gender              ENUM('Male','Female','Other') NULL,
    target_grade        ENUM('Playgroup','Nursery','LKG','UKG') NULL,
    notes               TEXT NULL,
    promoted_student_id INT UNSIGNED NULL,
    PRIMARY KEY (id),
    KEY idx_ic_fam (family_id),
    CONSTRAINT fk_ic_fam     FOREIGN KEY (family_id)           REFERENCES inquiry_families(id) ON DELETE CASCADE,
    CONSTRAINT fk_ic_student FOREIGN KEY (promoted_student_id) REFERENCES students(id)         ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE inquiry_touchpoints (
    id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
    family_id     INT UNSIGNED NOT NULL,
    kind          ENUM('call','email','sms','meeting','tour','note','other') NOT NULL DEFAULT 'note',
    occurred_at   DATETIME NOT NULL,
    follow_up_at  DATETIME NULL,
    body          TEXT NULL,
    odoo_msg_id   INT UNSIGNED NULL,
    created_by    INT UNSIGNED NULL,
    created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_it_odoo_msg (odoo_msg_id),
    KEY idx_it_fam_when (family_id, occurred_at),
    KEY idx_it_followup (follow_up_at),
    CONSTRAINT fk_it_fam FOREIGN KEY (family_id)  REFERENCES inquiry_families(id) ON DELETE CASCADE,
    CONSTRAINT fk_it_by  FOREIGN KEY (created_by) REFERENCES users(id)            ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Append-only audit trail for the admissions module — admin-only.
-- See /crm/audit.php (global) and the "Activity log" card on
-- /crm/view.php (per family).
CREATE TABLE inquiry_audit (
    id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
    family_id   INT UNSIGNED NULL,
    user_id     INT UNSIGNED NULL,
    action      VARCHAR(40)  NOT NULL,
    target_type VARCHAR(40)  NULL,
    target_id   INT UNSIGNED NULL,
    meta_json   TEXT         NULL,
    ip_address  VARCHAR(45)  NULL,
    user_agent  VARCHAR(255) NULL,
    created_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_audit_family (family_id, created_at),
    KEY idx_audit_user   (user_id,   created_at),
    KEY idx_audit_action (action),
    CONSTRAINT fk_audit_family FOREIGN KEY (family_id) REFERENCES inquiry_families(id) ON DELETE SET NULL,
    CONSTRAINT fk_audit_user   FOREIGN KEY (user_id)   REFERENCES users(id)            ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Admin-managed WhatsApp message templates — shown in the picker that
-- pops up when the user taps the WhatsApp pill on an inquiry. See
-- /crm/wa_templates.php for the admin UI.
CREATE TABLE crm_wa_templates (
    id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
    name          VARCHAR(80)  NOT NULL,
    body          TEXT         NOT NULL,
    display_order INT          NOT NULL DEFAULT 0,
    is_active     TINYINT(1)   NOT NULL DEFAULT 1,
    created_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_wat_order (display_order)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Logbook module — typed operational logs (visitor, incident, observation,
-- pickup, health, medication, cleaning, drill, maintenance). See
-- includes/logbook.php for the type definitions.
CREATE TABLE logbook_entries (
    id              INT UNSIGNED NOT NULL AUTO_INCREMENT,
    log_type        VARCHAR(30)  NOT NULL,
    occurred_at     DATETIME     NOT NULL,
    student_id      INT UNSIGNED NULL,
    title           VARCHAR(160) NULL,
    details         TEXT         NULL,
    meta_json       TEXT         NULL,
    parent_notified TINYINT(1)   NOT NULL DEFAULT 0,
    notified_at     DATETIME     NULL,
    photo_path      VARCHAR(255) NULL,
    logged_by       INT UNSIGNED NULL,
    created_at      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_log_type_when (log_type, occurred_at),
    KEY idx_log_student   (student_id, occurred_at),
    KEY idx_log_when      (occurred_at),
    CONSTRAINT fk_log_student FOREIGN KEY (student_id) REFERENCES students(id) ON DELETE SET NULL,
    CONSTRAINT fk_log_by      FOREIGN KEY (logged_by)  REFERENCES users(id)    ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Inventory module — stock items + an append-only movement ledger.
-- See includes/inventory.php.
CREATE TABLE inventory_items (
    id               INT UNSIGNED NOT NULL AUTO_INCREMENT,
    sku              VARCHAR(60)  NULL,                  -- Item ID (master spec)
    name             VARCHAR(160) NOT NULL,
    category         VARCHAR(60)  NOT NULL DEFAULT 'Other',
    sub_category     VARCHAR(60)  NULL,                  -- validated app-side per category
    quantity         DECIMAL(10,2) NOT NULL DEFAULT 0,
    unit             VARCHAR(20)  NOT NULL DEFAULT 'Nos',
    reorder_level    DECIMAL(10,2) NOT NULL DEFAULT 0,
    purchase_date    DATE         NULL,
    unit_cost        DECIMAL(10,2) NULL,                 -- per unit
    supplier         VARCHAR(120) NULL,
    location         VARCHAR(80)  NULL,
    `condition`      ENUM('new','good','repair_needed','damaged') NOT NULL DEFAULT 'good',
    assigned_to      VARCHAR(120) NULL,
    last_stock_check DATE         NULL,
    status           ENUM('active','issued','lost','damaged','disposed') NOT NULL DEFAULT 'active',
    notes            TEXT         NULL,
    is_active        TINYINT(1)   NOT NULL DEFAULT 1,    -- shadowed by status; kept for legacy queries
    created_by       INT UNSIGNED NULL,
    created_at       DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at       DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_inv_sku    (sku),
    KEY idx_inv_category (category),
    KEY idx_inv_active   (is_active),
    KEY idx_inv_name     (name),
    KEY idx_inv_status   (status),
    KEY idx_inv_check    (last_stock_check),
    CONSTRAINT fk_inv_by FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE inventory_movements (
    id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
    item_id       INT UNSIGNED NOT NULL,
    kind          ENUM('in','out','adjust') NOT NULL,
    quantity      DECIMAL(10,2) NOT NULL,
    balance_after DECIMAL(10,2) NOT NULL,
    reason        VARCHAR(40)  NULL,
    note          VARCHAR(255) NULL,
    moved_by      INT UNSIGNED NULL,
    created_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_im_item_when (item_id, created_at),
    CONSTRAINT fk_im_item FOREIGN KEY (item_id)  REFERENCES inventory_items(id) ON DELETE CASCADE,
    CONSTRAINT fk_im_by   FOREIGN KEY (moved_by) REFERENCES users(id)           ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================================
-- Expenses module
-- ============================================================================

CREATE TABLE expense_categories (
    id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
    name          VARCHAR(60)  NOT NULL,
    display_order INT          NOT NULL DEFAULT 0,
    is_active     TINYINT(1)   NOT NULL DEFAULT 1,
    created_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_exp_cat_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT INTO expense_categories (name, display_order) VALUES
    ('Stationery',        1),
    ('Cleaning',          2),
    ('Maintenance',       3),
    ('Equipment',         4),
    ('Food & snacks',     5),
    ('Travel & fuel',     6),
    ('Utilities',         7),
    ('Events & decor',    8),
    ('Teaching aids',     9),
    ('Other',            99);

CREATE TABLE expenses (
    id                  INT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id             INT UNSIGNED NOT NULL,
    category_id         INT UNSIGNED NULL,
    merchant            VARCHAR(160) NULL,
    expense_date        DATE         NOT NULL,
    amount              DECIMAL(10,2) NOT NULL,
    currency            CHAR(3)      NOT NULL DEFAULT 'INR',
    description         TEXT         NULL,
    payment_method      ENUM('cash','card','upi','bank_transfer','cheque','other')
                        NOT NULL DEFAULT 'cash',
    status              ENUM('submitted','approved','rejected','reimbursed')
                        NOT NULL DEFAULT 'submitted',
    receipt_filename    VARCHAR(80)  NULL,
    receipt_original    VARCHAR(255) NULL,
    receipt_mime        VARCHAR(120) NULL,
    receipt_size        INT UNSIGNED NULL,
    ocr_text            MEDIUMTEXT   NULL,
    reviewed_by_user_id INT UNSIGNED NULL,
    reviewed_at         DATETIME     NULL,
    review_notes        TEXT         NULL,
    created_at          DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at          DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP
                        ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_exp_user_date (user_id, expense_date),
    KEY idx_exp_status    (status),
    KEY idx_exp_category  (category_id),
    CONSTRAINT fk_exp_user     FOREIGN KEY (user_id)             REFERENCES users(id)              ON DELETE RESTRICT,
    CONSTRAINT fk_exp_category FOREIGN KEY (category_id)         REFERENCES expense_categories(id) ON DELETE SET NULL,
    CONSTRAINT fk_exp_reviewer FOREIGN KEY (reviewed_by_user_id) REFERENCES users(id)              ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- After running this schema, open /install.php to create the first admin.
-- For seed data (rating scheme + curriculum indicators), run sql/seeds.sql next.
