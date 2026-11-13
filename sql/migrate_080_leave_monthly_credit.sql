-- ============================================================================
-- migrate_080_leave_monthly_credit.sql
--
-- Paid leave is credited on the 1st of each month, not after a completed
-- month of service. The credit is stored, one row per person per month, so
-- a catch-up (page view or scripts/leave_monthly_credit.php) can fill a
-- missed month without ever writing it twice. Inactive staff are simply
-- not inserted.
--
-- staff_leave_credit_start is the first month the new rule applies. It is
-- written once. The PHP catch-up then stores, for anyone already on the
-- roster, an opening balance equal to what the old rule showed the day
-- before — so existing staff keep the balance already on record, and the
-- monthly credit is added on top.
--
-- Payslips remember how many days were carried in and how many were added
-- on the 1st, so an issued slip can still explain paid leave versus loss
-- of pay after the underlying leave changes.
--
-- Idempotent.
-- ============================================================================

SET NAMES utf8mb4;
SET sql_mode = 'STRICT_TRANS_TABLES,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION';

DROP PROCEDURE IF EXISTS pr_lg_leave_monthly_credit;
DELIMITER //
CREATE PROCEDURE pr_lg_leave_monthly_credit()
BEGIN
    IF NOT EXISTS (SELECT 1 FROM information_schema.tables
                   WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'staff_leave_credits') THEN
        CREATE TABLE staff_leave_credits (
            user_id      INT UNSIGNED NOT NULL,
            credit_month DATE         NOT NULL,
            days         DECIMAL(6,2) NOT NULL DEFAULT 1.00,
            created_at   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (user_id, credit_month),
            CONSTRAINT fk_slc_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    END IF;

    IF NOT EXISTS (SELECT 1 FROM information_schema.columns
                   WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'staff_payslips'
                     AND COLUMN_NAME = 'leave_carry_days') THEN
        ALTER TABLE staff_payslips
            ADD COLUMN leave_carry_days DECIMAL(6,2) NULL AFTER lop_absent_days;
    END IF;

    IF NOT EXISTS (SELECT 1 FROM information_schema.columns
                   WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'staff_payslips'
                     AND COLUMN_NAME = 'leave_credit_days') THEN
        ALTER TABLE staff_payslips
            ADD COLUMN leave_credit_days DECIMAL(6,2) NULL AFTER leave_carry_days;
    END IF;

    -- First of the month this migration runs. INSERT IGNORE so a later
    -- deploy does not move the start and re-snapshot everyone.
    INSERT IGNORE INTO app_settings (setting_key, setting_value)
        VALUES ('staff_leave_credit_start', DATE_FORMAT(CURDATE(), '%Y-%m-01'));
END //
DELIMITER ;
CALL pr_lg_leave_monthly_credit();
DROP PROCEDURE pr_lg_leave_monthly_credit;
