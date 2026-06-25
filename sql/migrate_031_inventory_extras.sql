-- ============================================================================
-- migrate_031_inventory_extras.sql
--
-- Extends the inventory module to the Little Graduates inventory master
-- spec (see docs/goal). Adds the columns the school's master schema
-- needs that PR #d8f641f's inventory module didn't have:
--
--   sku                 stays — repurposed as the "Item ID" / unique
--                       inventory code (UNIQUE).
--   sub_category        free-form (validated app-side against the fixed
--                       category → sub-category map).
--   purchase_date       DATE.
--   condition           ENUM('new','good','repair_needed','damaged').
--   assigned_to         optional teacher/staff name.
--   last_stock_check    DATE (physical verification date).
--   status              ENUM('active','issued','lost','damaged','disposed')
--                       NOT NULL DEFAULT 'active'. Replaces the bare
--                       is_active flag — existing is_active=0 rows map
--                       to 'disposed' so retired items stay retired.
--
-- Existing category codes from inventory.php's old helper are remapped
-- to the master labels in one shot:
--     montessori  → 'Montessori Material'
--     stationery  → 'Stationery'
--     art         → 'Art & Craft'
--     books       → 'Books'
--     toys        → 'Toys'
--     cleaning    → 'Cleaning Supplies'
--     furniture   → 'Furniture'
--     electronics → 'Electronics'
-- Codes with no master equivalent (food, first_aid, other) are left as-is
-- so the admin can pick the right new category by hand.
--
-- Idempotent — safe to re-run.
-- ============================================================================

SET NAMES utf8mb4;

DROP PROCEDURE IF EXISTS pr_lg_inventory_extras;
DELIMITER //
CREATE PROCEDURE pr_lg_inventory_extras()
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM information_schema.columns
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'inventory_items'
          AND COLUMN_NAME = 'sub_category'
    ) THEN
        ALTER TABLE inventory_items
            ADD COLUMN sub_category     VARCHAR(60)  NULL AFTER category,
            ADD COLUMN purchase_date    DATE         NULL AFTER unit_cost,
            ADD COLUMN `condition`      ENUM('new','good','repair_needed','damaged')
                                        NOT NULL DEFAULT 'good' AFTER location,
            ADD COLUMN assigned_to      VARCHAR(120) NULL AFTER `condition`,
            ADD COLUMN last_stock_check DATE         NULL AFTER assigned_to,
            ADD COLUMN status           ENUM('active','issued','lost','damaged','disposed')
                                        NOT NULL DEFAULT 'active' AFTER is_active;
    END IF;

    -- Sku → Item ID (unique).  Make UNIQUE only if it isn't already.
    IF NOT EXISTS (
        SELECT 1 FROM information_schema.statistics
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'inventory_items'
          AND INDEX_NAME = 'uq_inv_sku'
    ) THEN
        -- Tolerate NULL duplicates (multiple existing rows without an Item ID);
        -- only enforce uniqueness once a value is set.
        ALTER TABLE inventory_items
            ADD UNIQUE KEY uq_inv_sku (sku),
            ADD KEY idx_inv_status (status),
            ADD KEY idx_inv_check  (last_stock_check);
    END IF;

    -- Backfill status from the legacy is_active flag.
    UPDATE inventory_items SET status = 'disposed'
    WHERE  status = 'active' AND is_active = 0;

    -- Remap legacy category codes to the master labels.
    UPDATE inventory_items SET category = 'Montessori Material' WHERE category = 'montessori';
    UPDATE inventory_items SET category = 'Stationery'          WHERE category = 'stationery';
    UPDATE inventory_items SET category = 'Art & Craft'         WHERE category = 'art';
    UPDATE inventory_items SET category = 'Books'               WHERE category = 'books';
    UPDATE inventory_items SET category = 'Toys'                WHERE category = 'toys';
    UPDATE inventory_items SET category = 'Cleaning Supplies'   WHERE category = 'cleaning';
    UPDATE inventory_items SET category = 'Furniture'           WHERE category = 'furniture';
    UPDATE inventory_items SET category = 'Electronics'         WHERE category = 'electronics';
END //
DELIMITER ;
CALL pr_lg_inventory_extras();
DROP PROCEDURE pr_lg_inventory_extras;
