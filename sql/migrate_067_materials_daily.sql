-- ============================================================================
-- migrate_067_materials_daily.sql
--
-- Daily material condition audit — separate from the monthly Kreedo
-- replacement workflow in mm_condition_checks (that table stays exactly as
-- is: vendor reordering is a monthly concern, not a daily one).
--
--   mm_daily_checks   one condition mark per (material, calendar day).
--                     UNIQUE KEY (material_id, check_date) means a new day
--                     starts with zero rows — the checklist is blank by
--                     construction — while every previous day's rows stay
--                     untouched forever. There is no "reset" to run: the
--                     date simply changes and old rows are already history.
--
-- Reuses mm_materials (catalogue) and the mm_conditions() vocabulary in
-- includes/materials.php — no new condition list, no duplicated media/
-- replacement machinery.
--
-- Idempotent — guards on table existence.
-- ============================================================================

SET NAMES utf8mb4;
SET sql_mode = 'STRICT_TRANS_TABLES,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION';

DROP PROCEDURE IF EXISTS pr_lg_materials_daily;
DELIMITER //
CREATE PROCEDURE pr_lg_materials_daily()
BEGIN
    IF NOT EXISTS (SELECT 1 FROM information_schema.tables
                   WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'mm_daily_checks') THEN
        CREATE TABLE mm_daily_checks (
            id                  INT UNSIGNED NOT NULL AUTO_INCREMENT,
            material_id         INT UNSIGNED NOT NULL,
            check_date          DATE         NOT NULL,
            condition_code      VARCHAR(20)  NOT NULL,
            notes               TEXT         NULL,
            checked_by_user_id  INT UNSIGNED NULL,
            checked_at          DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at          DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP
                                             ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uq_mm_daily (material_id, check_date),
            KEY idx_mm_daily_date (check_date, condition_code),
            KEY idx_mm_daily_material (material_id, check_date),
            CONSTRAINT fk_mm_daily_material FOREIGN KEY (material_id)
                REFERENCES mm_materials(id) ON DELETE CASCADE,
            CONSTRAINT fk_mm_daily_user FOREIGN KEY (checked_by_user_id)
                REFERENCES users(id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    END IF;
END //
DELIMITER ;
CALL pr_lg_materials_daily();
DROP PROCEDURE pr_lg_materials_daily;
