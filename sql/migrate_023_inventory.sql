-- ============================================================================
-- migrate_023_inventory.sql
--
-- Adds the Inventory module: track stock of Montessori materials,
-- stationery, cleaning supplies, food, furniture, first-aid, etc.
--
--   inventory_items     — one row per item, carrying the live quantity,
--                         reorder level, unit, location, cost, supplier.
--   inventory_movements — append-only stock ledger: every in / out /
--                         adjustment with reason, who, and balance after.
--
-- The item's quantity is kept in sync with movements inside a transaction
-- (movements also record balance_after for an auditable trail).
--
-- Adds 'inventory' to users.modules SET. Idempotent.
-- ============================================================================

SET NAMES utf8mb4;
SET sql_mode = 'STRICT_TRANS_TABLES,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION';

DROP PROCEDURE IF EXISTS pr_lg_inventory;
DELIMITER //
CREATE PROCEDURE pr_lg_inventory()
BEGIN
    IF EXISTS (
        SELECT 1 FROM information_schema.columns
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users'
          AND COLUMN_NAME = 'modules' AND DATA_TYPE = 'set'
          AND COLUMN_TYPE NOT LIKE '%inventory%'
    ) THEN
        ALTER TABLE users
            MODIFY modules
              SET('tasks','montessori','students','crm','recruitment','staff','expenses','fees','logbook','inventory')
              NOT NULL DEFAULT '';
    END IF;

    IF NOT EXISTS (SELECT 1 FROM information_schema.tables
                   WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='inventory_items') THEN
        CREATE TABLE inventory_items (
            id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
            name          VARCHAR(160) NOT NULL,
            category      VARCHAR(40)  NOT NULL DEFAULT 'other',
            sku           VARCHAR(60)  NULL,
            unit          VARCHAR(20)  NOT NULL DEFAULT 'pcs',
            quantity      DECIMAL(10,2) NOT NULL DEFAULT 0,
            reorder_level DECIMAL(10,2) NOT NULL DEFAULT 0,
            location      VARCHAR(80)  NULL,
            unit_cost     DECIMAL(10,2) NULL,
            supplier      VARCHAR(120) NULL,
            notes         TEXT         NULL,
            is_active     TINYINT(1)   NOT NULL DEFAULT 1,
            created_by    INT UNSIGNED NULL,
            created_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_inv_category (category),
            KEY idx_inv_active (is_active),
            KEY idx_inv_name (name),
            CONSTRAINT fk_inv_by FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    END IF;

    IF NOT EXISTS (SELECT 1 FROM information_schema.tables
                   WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='inventory_movements') THEN
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
    END IF;
END //
DELIMITER ;
CALL pr_lg_inventory();
DROP PROCEDURE pr_lg_inventory;
