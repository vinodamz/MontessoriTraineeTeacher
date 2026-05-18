-- ============================================================================
-- migrate_005_fees.sql
--
-- Adds `fee_invoices` and `fee_payments` for per-student fee tracking.
-- Lightweight schema — designed to play nicely with a future CoFee/Zoho
-- reconciliation flow but standalone-usable today.
--
-- Idempotent — uses information_schema guards.
-- ============================================================================

SET NAMES utf8mb4;
SET sql_mode = 'STRICT_TRANS_TABLES,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION';

DROP PROCEDURE IF EXISTS pr_lg_fees;
DELIMITER //
CREATE PROCEDURE pr_lg_fees()
BEGIN
    IF NOT EXISTS (SELECT 1 FROM information_schema.tables
                   WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='fee_invoices') THEN
        CREATE TABLE fee_invoices (
            id                  INT UNSIGNED NOT NULL AUTO_INCREMENT,
            student_id          INT UNSIGNED NOT NULL,
            title               VARCHAR(120) NOT NULL,
            period              VARCHAR(30)  NULL,            -- free-text e.g. "Term 1 2025-26", "May 2026"
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
    END IF;

    IF NOT EXISTS (SELECT 1 FROM information_schema.tables
                   WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='fee_payments') THEN
        CREATE TABLE fee_payments (
            id                  INT UNSIGNED NOT NULL AUTO_INCREMENT,
            invoice_id          INT UNSIGNED NOT NULL,
            amount              DECIMAL(10,2) NOT NULL,
            paid_on             DATE         NOT NULL,
            method              ENUM('cash','bank_transfer','upi','card','cheque','cofee','other') NOT NULL DEFAULT 'cash',
            reference_no        VARCHAR(80)  NULL,
            notes               TEXT         NULL,
            recorded_by_user_id INT UNSIGNED NOT NULL,
            created_at          DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_fp_invoice (invoice_id, paid_on),
            CONSTRAINT fk_fp_invoice  FOREIGN KEY (invoice_id)          REFERENCES fee_invoices(id) ON DELETE CASCADE,
            CONSTRAINT fk_fp_recorder FOREIGN KEY (recorded_by_user_id) REFERENCES users(id)        ON DELETE RESTRICT
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    END IF;
END //
DELIMITER ;
CALL pr_lg_fees();
DROP PROCEDURE pr_lg_fees;
