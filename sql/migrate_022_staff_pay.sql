-- ============================================================================
-- migrate_022_staff_pay.sql
--
-- Adds payroll to the Staff module:
--   staff_pay       — the current + historical pay structure per staff member
--                     (earnings + deductions, effective-dated so a raise keeps
--                     the old structure on file for past payslips).
--   staff_payslips  — issued payslips. An immutable snapshot of the pay
--                     structure + attendance for one (staff, month), so a
--                     payslip stays correct even if the pay structure later
--                     changes. One per (user, year, month).
--
-- Login/logoff times already live on staff_attendance (check_in/check_out);
-- hours worked are computed from those, not stored.
--
-- Idempotent — information_schema guards.
-- ============================================================================

SET NAMES utf8mb4;
SET sql_mode = 'STRICT_TRANS_TABLES,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION';

DROP PROCEDURE IF EXISTS pr_lg_staff_pay;
DELIMITER //
CREATE PROCEDURE pr_lg_staff_pay()
BEGIN
    -- 1. staff_pay — effective-dated pay structure. The row with the latest
    --    effective_from on/before a payslip period is the one that applies.
    IF NOT EXISTS (SELECT 1 FROM information_schema.tables
                   WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='staff_pay') THEN
        CREATE TABLE staff_pay (
            id                  INT UNSIGNED NOT NULL AUTO_INCREMENT,
            user_id             INT UNSIGNED NOT NULL,
            effective_from      DATE         NOT NULL,
            -- Earnings (monthly, INR)
            basic               DECIMAL(10,2) NOT NULL DEFAULT 0,
            hra                 DECIMAL(10,2) NOT NULL DEFAULT 0,
            conveyance          DECIMAL(10,2) NOT NULL DEFAULT 0,
            special_allowance   DECIMAL(10,2) NOT NULL DEFAULT 0,
            other_earning       DECIMAL(10,2) NOT NULL DEFAULT 0,
            -- Deductions (monthly, INR)
            pf                  DECIMAL(10,2) NOT NULL DEFAULT 0,
            esi                 DECIMAL(10,2) NOT NULL DEFAULT 0,
            professional_tax    DECIMAL(10,2) NOT NULL DEFAULT 0,
            tds                 DECIMAL(10,2) NOT NULL DEFAULT 0,
            other_deduction     DECIMAL(10,2) NOT NULL DEFAULT 0,
            -- Config
            payable_days_basis  TINYINT UNSIGNED NOT NULL DEFAULT 30,  -- days used for per-day rate (30 / 26 / actual)
            notes               VARCHAR(255) NULL,
            created_by          INT UNSIGNED NULL,
            created_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_sp_user_eff (user_id, effective_from),
            CONSTRAINT fk_sp_user FOREIGN KEY (user_id)    REFERENCES users(id) ON DELETE CASCADE,
            CONSTRAINT fk_sp_by   FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    END IF;

    -- 2. staff_payslips — immutable issued payslips. Components snapshotted
    --    as JSON so the payslip never changes when the pay structure does.
    IF NOT EXISTS (SELECT 1 FROM information_schema.tables
                   WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='staff_payslips') THEN
        CREATE TABLE staff_payslips (
            id                INT UNSIGNED NOT NULL AUTO_INCREMENT,
            user_id           INT UNSIGNED NOT NULL,
            period_year       SMALLINT UNSIGNED NOT NULL,
            period_month      TINYINT UNSIGNED  NOT NULL,
            working_days      DECIMAL(5,1)  NOT NULL DEFAULT 0,
            present_days      DECIMAL(5,1)  NOT NULL DEFAULT 0,
            paid_leave_days   DECIMAL(5,1)  NOT NULL DEFAULT 0,
            lop_days          DECIMAL(5,1)  NOT NULL DEFAULT 0,
            hours_worked      DECIMAL(7,2)  NOT NULL DEFAULT 0,
            earnings_json     TEXT          NULL,
            deductions_json   TEXT          NULL,
            gross_earnings    DECIMAL(10,2) NOT NULL DEFAULT 0,
            lop_amount        DECIMAL(10,2) NOT NULL DEFAULT 0,
            total_deductions  DECIMAL(10,2) NOT NULL DEFAULT 0,
            net_pay           DECIMAL(10,2) NOT NULL DEFAULT 0,
            notes             VARCHAR(255) NULL,
            generated_by      INT UNSIGNED NULL,
            generated_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uq_payslip_period (user_id, period_year, period_month),
            KEY idx_ps_period (period_year, period_month),
            CONSTRAINT fk_ps_user FOREIGN KEY (user_id)      REFERENCES users(id) ON DELETE CASCADE,
            CONSTRAINT fk_ps_by   FOREIGN KEY (generated_by) REFERENCES users(id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    END IF;
END //
DELIMITER ;
CALL pr_lg_staff_pay();
DROP PROCEDURE pr_lg_staff_pay;
