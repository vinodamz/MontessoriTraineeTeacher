-- ============================================================================
-- migrate_070_plans_module.sql
--
-- Weekly Plans: each teacher plans Mon–Sat ahead (Asia/Kolkata week keys),
-- with per-day activities + materials, a note to the Principal (voice/photos),
-- and a draft → submitted → approved / changes_requested review workflow.
--
-- Also:
--   - users.modules gains 'plans'
--   - seeds a weekly all_teachers duty template with action_key='weekly_plan'
--
-- Idempotent — information_schema / existence guards throughout.
-- ============================================================================

SET NAMES utf8mb4;
SET sql_mode = 'STRICT_TRANS_TABLES,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION';

DROP PROCEDURE IF EXISTS pr_lg_plans_module;
DELIMITER //
CREATE PROCEDURE pr_lg_plans_module()
BEGIN
    -- 1. Module grant on users.
    IF (
        SELECT LOCATE('''plans''', COLUMN_TYPE) = 0
        FROM information_schema.columns
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = 'modules'
    ) THEN
        ALTER TABLE users MODIFY COLUMN modules
            SET('tasks','montessori','students','crm','recruitment','staff',
                'expenses','fees','logbook','inventory','materials','wacrm','n8n',
                'daycare','plans')
            NOT NULL DEFAULT '';
    END IF;

    -- 2. weekly_plans
    IF NOT EXISTS (SELECT 1 FROM information_schema.tables
                   WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'weekly_plans') THEN
        CREATE TABLE weekly_plans (
            id                    INT UNSIGNED NOT NULL AUTO_INCREMENT,
            teacher_id            INT UNSIGNED NOT NULL,
            week_key              VARCHAR(16)  NOT NULL,
            class_grade           VARCHAR(120) NOT NULL DEFAULT '',
            status                ENUM('draft','submitted','approved','changes_requested')
                                  NOT NULL DEFAULT 'draft',
            addressed_to_user_id  INT UNSIGNED NULL,
            note_to_principal     TEXT NULL,
            weekly_materials      TEXT NULL,
            submitted_at          DATETIME NULL,
            reviewed_by_user_id   INT UNSIGNED NULL,
            reviewed_at           DATETIME NULL,
            created_at            DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at            DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
                                           ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uq_weekly_plans_teacher_week (teacher_id, week_key),
            KEY idx_weekly_plans_week_status (week_key, status),
            KEY idx_weekly_plans_addressed (addressed_to_user_id),
            CONSTRAINT fk_weekly_plans_teacher FOREIGN KEY (teacher_id)
                REFERENCES users(id) ON DELETE CASCADE,
            CONSTRAINT fk_weekly_plans_addressed FOREIGN KEY (addressed_to_user_id)
                REFERENCES users(id) ON DELETE SET NULL,
            CONSTRAINT fk_weekly_plans_reviewer FOREIGN KEY (reviewed_by_user_id)
                REFERENCES users(id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    END IF;

    -- 3. plan_days
    IF NOT EXISTS (SELECT 1 FROM information_schema.tables
                   WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'plan_days') THEN
        CREATE TABLE plan_days (
            id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
            plan_id     INT UNSIGNED NOT NULL,
            day_date    DATE NOT NULL,
            activities  TEXT NULL,
            materials   TEXT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY uq_plan_days_plan_date (plan_id, day_date),
            KEY idx_plan_days_date (day_date),
            CONSTRAINT fk_plan_days_plan FOREIGN KEY (plan_id)
                REFERENCES weekly_plans(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    END IF;

    -- 4. plan_media (principal-note attachments only)
    IF NOT EXISTS (SELECT 1 FROM information_schema.tables
                   WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'plan_media') THEN
        CREATE TABLE plan_media (
            id                  INT UNSIGNED NOT NULL AUTO_INCREMENT,
            plan_id             INT UNSIGNED NOT NULL,
            kind                ENUM('photo','audio') NOT NULL DEFAULT 'photo',
            original_filename   VARCHAR(255) NOT NULL,
            stored_filename     VARCHAR(255) NOT NULL,
            mime_type           VARCHAR(100) NOT NULL,
            size_bytes          INT UNSIGNED NOT NULL DEFAULT 0,
            uploaded_by_user_id INT UNSIGNED NULL,
            uploaded_at         DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uq_plan_media_stored (stored_filename),
            KEY idx_plan_media_plan (plan_id),
            CONSTRAINT fk_plan_media_plan FOREIGN KEY (plan_id)
                REFERENCES weekly_plans(id) ON DELETE CASCADE,
            CONSTRAINT fk_plan_media_user FOREIGN KEY (uploaded_by_user_id)
                REFERENCES users(id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    END IF;

    -- 5. plan_reviews (feedback thread + audit)
    IF NOT EXISTS (SELECT 1 FROM information_schema.tables
                   WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'plan_reviews') THEN
        CREATE TABLE plan_reviews (
            id                INT UNSIGNED NOT NULL AUTO_INCREMENT,
            plan_id           INT UNSIGNED NOT NULL,
            reviewer_user_id  INT UNSIGNED NULL,
            action            ENUM('comment','approved','changes_requested') NOT NULL DEFAULT 'comment',
            body              TEXT NULL,
            created_at        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_plan_reviews_plan (plan_id, created_at),
            CONSTRAINT fk_plan_reviews_plan FOREIGN KEY (plan_id)
                REFERENCES weekly_plans(id) ON DELETE CASCADE,
            CONSTRAINT fk_plan_reviews_user FOREIGN KEY (reviewer_user_id)
                REFERENCES users(id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    END IF;

    -- 6. Seed weekly "Submit weekly plan" duty for all teachers (once).
    IF EXISTS (SELECT 1 FROM information_schema.tables
               WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'staff_duty_templates')
       AND EXISTS (SELECT 1 FROM information_schema.columns
                   WHERE TABLE_SCHEMA = DATABASE()
                     AND TABLE_NAME = 'staff_duty_templates'
                     AND COLUMN_NAME = 'action_key')
       AND NOT EXISTS (
           SELECT 1 FROM staff_duty_templates WHERE action_key = 'weekly_plan' LIMIT 1
       ) THEN
        INSERT INTO staff_duty_templates
            (title, notes, action_key, frequency, audience, is_active, sort_order)
        VALUES (
            'Submit weekly plan',
            'Plan Mon–Sat activities and materials, then submit for Principal review.',
            'weekly_plan',
            'weekly',
            'all_teachers',
            1,
            20
        );
    END IF;
END //
DELIMITER ;
CALL pr_lg_plans_module();
DROP PROCEDURE pr_lg_plans_module;
