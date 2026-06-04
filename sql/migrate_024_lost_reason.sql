-- ============================================================================
-- migrate_024_lost_reason.sql
--
-- Capture WHY an inquiry was lost. A "lost" status without a reason loses
-- the most useful data for the funnel report — was it cost, distance, no
-- response, picked another school, etc. The pipeline now prompts for a
-- reason whenever a card lands in the Lost column.
--
-- One short code per row; the human label lives in includes/crm.php so the
-- list can be extended without another migration.
--
-- Idempotent.
-- ============================================================================

SET NAMES utf8mb4;

DROP PROCEDURE IF EXISTS pr_lg_lost_reason;
DELIMITER //
CREATE PROCEDURE pr_lg_lost_reason()
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM information_schema.columns
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'inquiry_families'
          AND COLUMN_NAME = 'lost_reason'
    ) THEN
        ALTER TABLE inquiry_families
            ADD COLUMN lost_reason VARCHAR(40) NULL AFTER status,
            ADD KEY idx_inq_lost_reason (lost_reason);
    END IF;
END //
DELIMITER ;
CALL pr_lg_lost_reason();
DROP PROCEDURE pr_lg_lost_reason;
