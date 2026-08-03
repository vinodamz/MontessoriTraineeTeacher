-- ============================================================================
-- migrate_057_leave_accrual.sql
--
-- Leave becomes an accruing balance instead of a fixed annual allowance, and
-- payroll finally learns about it.
--
--   staff_leave_ledger — the only stored balance events. Everything else is
--                        computed:
--
--                          balance(as_of) = opening
--                                         + accrual (completed months × rate)
--                                         + adjustments
--                                         − approved paid leave taken
--
--                        Two kinds of row:
--                          'opening' — "as of this date, this person has N
--                                       days". The admin sets it; the latest
--                                       one on or before the date being asked
--                                       about wins, and everything before it
--                                       is ignored. That is what makes
--                                       "update the balance as of today" a
--                                       single honest action rather than a
--                                       reconciliation exercise.
--                          'adjust'  — a correction of ±N days that does not
--                                       reset history.
--
--                        Accrual is NOT stored. A row per person per month
--                        would need a scheduler this host cannot be trusted
--                        to run, and a missed month would silently underpay
--                        somebody. Counting completed months at read time
--                        cannot drift.
--
-- staff_leave_allowances is left in place but is no longer read: the fixed
-- per-year, per-type allowance is what the accrual model replaces. Dropping
-- it would throw away the record of what was granted historically.
--
-- staff_leave_requests gains `lop_days`, filled in when a request is approved
-- and the balance could not cover it — so a payslip can be explained from the
-- request that caused it rather than recomputed and hoped over.
--
-- Idempotent — guards on table and column existence.
-- ============================================================================

SET NAMES utf8mb4;
SET sql_mode = 'STRICT_TRANS_TABLES,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION';

DROP PROCEDURE IF EXISTS pr_lg_leave_accrual;
DELIMITER //
CREATE PROCEDURE pr_lg_leave_accrual()
BEGIN
    IF NOT EXISTS (SELECT 1 FROM information_schema.tables
                   WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='staff_leave_ledger') THEN
        CREATE TABLE staff_leave_ledger (
            id         INT UNSIGNED  NOT NULL AUTO_INCREMENT,
            user_id    INT UNSIGNED  NOT NULL,
            entry_date DATE          NOT NULL,
            kind       ENUM('opening','adjust') NOT NULL DEFAULT 'adjust',
            days       DECIMAL(6,2)  NOT NULL DEFAULT 0.00,
            note       VARCHAR(255)  NULL,
            created_by INT UNSIGNED  NULL,
            created_at DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_sll_user (user_id, entry_date),
            KEY idx_sll_kind (user_id, kind, entry_date),
            CONSTRAINT fk_sll_user    FOREIGN KEY (user_id)    REFERENCES users(id) ON DELETE CASCADE,
            CONSTRAINT fk_sll_creator FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    END IF;

    -- How many days a request could not cover from balance. Recorded at
    -- approval so the payslip can point at the request that caused it.
    IF NOT EXISTS (SELECT 1 FROM information_schema.columns
                   WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='staff_leave_requests'
                     AND COLUMN_NAME='lop_days') THEN
        ALTER TABLE staff_leave_requests
            ADD COLUMN lop_days DECIMAL(5,1) NOT NULL DEFAULT 0.0 AFTER days_count;
    END IF;

    -- Half-day support: which half, when days_count is 0.5.
    IF NOT EXISTS (SELECT 1 FROM information_schema.columns
                   WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='staff_leave_requests'
                     AND COLUMN_NAME='half_day') THEN
        ALTER TABLE staff_leave_requests
            ADD COLUMN half_day ENUM('','first','second') NOT NULL DEFAULT '' AFTER end_date;
    END IF;

    -- Payslips record the split so an issued slip stays explainable even if
    -- leave is edited afterwards.
    IF NOT EXISTS (SELECT 1 FROM information_schema.columns
                   WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='staff_payslips'
                     AND COLUMN_NAME='lop_leave_days') THEN
        ALTER TABLE staff_payslips
            ADD COLUMN lop_leave_days DECIMAL(5,1) NOT NULL DEFAULT 0.0;
        ALTER TABLE staff_payslips
            ADD COLUMN lop_absent_days DECIMAL(5,1) NOT NULL DEFAULT 0.0;
    END IF;

    -- One paid leave day earned per completed month, carried forward.
    INSERT IGNORE INTO app_settings (setting_key, setting_value)
        VALUES ('staff_leave_accrual_per_month', '1');
END //
DELIMITER ;
CALL pr_lg_leave_accrual();
DROP PROCEDURE pr_lg_leave_accrual;
