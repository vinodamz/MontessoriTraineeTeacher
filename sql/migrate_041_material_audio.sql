-- ============================================================================
-- migrate_041_material_audio.sql
--
-- Voice memos on material condition checks: extend mm_condition_media.kind
-- to accept 'audio' so a teacher can record a spoken note ("mould along the
-- left edge, second board") instead of typing. Files ride the same upload,
-- serving and ZIP-export paths as photos/videos.
--
-- Idempotent: only rewrites the column when 'audio' isn't already a member.
-- ============================================================================

SET NAMES utf8mb4;

DROP PROCEDURE IF EXISTS pr_lg_material_audio;
DELIMITER //
CREATE PROCEDURE pr_lg_material_audio()
BEGIN
    IF (
        SELECT LOCATE('''audio''', COLUMN_TYPE) = 0
        FROM information_schema.columns
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = 'mm_condition_media' AND COLUMN_NAME = 'kind'
    ) THEN
        ALTER TABLE mm_condition_media
            MODIFY COLUMN kind ENUM('photo','video','audio') NOT NULL DEFAULT 'photo';
    END IF;
END //
DELIMITER ;
CALL pr_lg_material_audio();
DROP PROCEDURE pr_lg_material_audio;
