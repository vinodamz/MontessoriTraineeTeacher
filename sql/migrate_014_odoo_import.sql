-- ============================================================================
-- migrate_014_odoo_import.sql
--
-- Adds idempotency columns so the Odoo CRM importer (crm/import_odoo.php)
-- can be re-run safely. Each Odoo lead / message gets a stable handle so
-- re-imports update the existing row instead of creating duplicates.
--
-- Idempotent — information_schema guards. Re-running is safe.
-- ============================================================================

SET NAMES utf8mb4;
SET sql_mode = 'STRICT_TRANS_TABLES,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION';

DROP PROCEDURE IF EXISTS pr_lg_odoo_import;
DELIMITER //
CREATE PROCEDURE pr_lg_odoo_import()
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM information_schema.columns
        WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='inquiry_families' AND COLUMN_NAME='odoo_lead_id'
    ) THEN
        ALTER TABLE inquiry_families
            ADD COLUMN odoo_lead_id INT UNSIGNED NULL AFTER notes,
            ADD UNIQUE KEY uq_inq_odoo_lead (odoo_lead_id);
    END IF;

    IF NOT EXISTS (
        SELECT 1 FROM information_schema.columns
        WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='inquiry_touchpoints' AND COLUMN_NAME='odoo_msg_id'
    ) THEN
        ALTER TABLE inquiry_touchpoints
            ADD COLUMN odoo_msg_id INT UNSIGNED NULL AFTER body,
            ADD UNIQUE KEY uq_it_odoo_msg (odoo_msg_id);
    END IF;
END //
DELIMITER ;
CALL pr_lg_odoo_import();
DROP PROCEDURE pr_lg_odoo_import;
