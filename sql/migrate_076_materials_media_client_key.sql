-- ============================================================================
-- migrate_076_materials_media_client_key.sql
--
-- The staff app saves a photo or video on the phone first, then uploads it
-- in the background. If the phone retries after a timeout, client_key is the
-- idempotency id: the same key returns the existing mm_daily_media row
-- instead of storing a second file. Website uploads leave it NULL.
--
-- Idempotent.
-- ============================================================================

SET NAMES utf8mb4;
SET sql_mode = 'STRICT_TRANS_TABLES,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION';

DROP PROCEDURE IF EXISTS pr_lg_materials_media_client_key;
DELIMITER //
CREATE PROCEDURE pr_lg_materials_media_client_key()
BEGIN
    IF EXISTS (SELECT 1 FROM information_schema.tables
               WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'mm_daily_media')
       AND NOT EXISTS (SELECT 1 FROM information_schema.columns
                       WHERE TABLE_SCHEMA = DATABASE()
                         AND TABLE_NAME = 'mm_daily_media'
                         AND COLUMN_NAME = 'client_key') THEN
        ALTER TABLE mm_daily_media
            ADD COLUMN client_key VARCHAR(80) NULL AFTER uploaded_by_user_id,
            ADD UNIQUE KEY uq_mm_daily_media_client (client_key);
    END IF;
END //
DELIMITER ;
CALL pr_lg_materials_media_client_key();
DROP PROCEDURE pr_lg_materials_media_client_key;
