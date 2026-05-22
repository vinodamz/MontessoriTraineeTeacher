-- ============================================================================
-- migrate_009_expenses.sql
--
-- Adds the Expenses module: expense_categories + expenses tables, plus the
-- 'expenses' value in users.modules SET. Idempotent — re-runnable.
--
-- Expenses captures staff-paid receipts (postage, supplies, repairs, travel).
-- Each row stores the receipt image filename (under /uploads/receipts/) and
-- the raw OCR'd text so a reviewer can verify the parsed amount/date/merchant.
-- ============================================================================

SET NAMES utf8mb4;
SET sql_mode = 'STRICT_TRANS_TABLES,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION';

DROP PROCEDURE IF EXISTS pr_lg_expenses;
DELIMITER //
CREATE PROCEDURE pr_lg_expenses()
BEGIN
    -- 1. Expand users.modules SET to include the 'expenses' module.
    IF EXISTS (
        SELECT 1 FROM information_schema.columns
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME   = 'users'
          AND COLUMN_NAME  = 'modules'
          AND COLUMN_TYPE  NOT LIKE '%expenses%'
    ) THEN
        ALTER TABLE users
            MODIFY modules SET('tasks','montessori','students','expenses') NOT NULL DEFAULT '';
    END IF;

    -- 2. expense_categories — short list of buckets the admin can manage.
    IF NOT EXISTS (SELECT 1 FROM information_schema.tables
                   WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='expense_categories') THEN
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
    END IF;

    -- 3. expenses — one row per receipt. receipt_filename points at
    --    /uploads/receipts/<stored_filename>.
    IF NOT EXISTS (SELECT 1 FROM information_schema.tables
                   WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='expenses') THEN
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
    END IF;
END //
DELIMITER ;
CALL pr_lg_expenses();
DROP PROCEDURE pr_lg_expenses;
