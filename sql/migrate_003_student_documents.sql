-- ============================================================================
-- migrate_003_student_documents.sql
--
-- Adds `student_documents` for per-student file attachments
-- (birth certificate, vaccination record, ID proof, etc.).
--
-- Files themselves are stored on the filesystem under
--   /uploads/student_docs/<sha>.<ext>
-- with directory web-access blocked by .htaccess. This table only carries
-- metadata + a relative path the download endpoint dereferences.
--
-- Idempotent — uses information_schema guards. Re-running is safe.
-- ============================================================================

SET NAMES utf8mb4;
SET sql_mode = 'STRICT_TRANS_TABLES,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION';

DROP PROCEDURE IF EXISTS pr_lg_student_documents;
DELIMITER //
CREATE PROCEDURE pr_lg_student_documents()
BEGIN
    IF NOT EXISTS (SELECT 1 FROM information_schema.tables
                   WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='student_documents') THEN
        CREATE TABLE student_documents (
            id                  INT UNSIGNED NOT NULL AUTO_INCREMENT,
            student_id          INT UNSIGNED NOT NULL,
            category            ENUM('birth_certificate','vaccination','id_proof','medical','school','other')
                                NOT NULL DEFAULT 'other',
            title               VARCHAR(160) NOT NULL,
            original_filename   VARCHAR(255) NOT NULL,
            stored_filename     VARCHAR(80)  NOT NULL,         -- random; the on-disk filename
            mime_type           VARCHAR(120) NOT NULL,
            size_bytes          INT UNSIGNED NOT NULL,
            uploaded_by_user_id INT UNSIGNED NOT NULL,
            uploaded_at         DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_sd_student (student_id, uploaded_at),
            UNIQUE KEY uq_stored_filename (stored_filename),
            CONSTRAINT fk_sd_student  FOREIGN KEY (student_id)          REFERENCES students(id) ON DELETE CASCADE,
            CONSTRAINT fk_sd_uploader FOREIGN KEY (uploaded_by_user_id) REFERENCES users(id)    ON DELETE RESTRICT
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    END IF;
END //
DELIMITER ;
CALL pr_lg_student_documents();
DROP PROCEDURE pr_lg_student_documents;
