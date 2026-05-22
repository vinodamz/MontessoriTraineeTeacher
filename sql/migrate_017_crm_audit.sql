-- ============================================================================
-- migrate_017_crm_audit.sql
--
-- Adds inquiry_audit — an append-only activity log for the admissions
-- module. Every action that affects an inquiry is recorded here:
--
--   Server-side (PHP):
--     inquiry_created   inquiry_updated   inquiry_deleted
--     status_changed    lead_qualified    enrolled
--     touchpoint_added  touchpoint_deleted
--
--   Client-side (sendBeacon → /crm/log_action.php):
--     phone_call_initiated  whatsapp_initiated
--
-- Visible only to admins via:
--   - "Activity log" card on /crm/view.php (per-family feed)
--   - /crm/audit.php (global feed, filterable)
--
-- Idempotent — guarded on information_schema. Re-runnable.
-- ============================================================================

SET NAMES utf8mb4;
SET sql_mode = 'STRICT_TRANS_TABLES,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION';

DROP PROCEDURE IF EXISTS pr_lg_crm_audit;
DELIMITER //
CREATE PROCEDURE pr_lg_crm_audit()
BEGIN
    IF NOT EXISTS (SELECT 1 FROM information_schema.tables
                   WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='inquiry_audit') THEN
        CREATE TABLE inquiry_audit (
            id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
            family_id   INT UNSIGNED NULL,
            user_id     INT UNSIGNED NULL,
            action      VARCHAR(40)  NOT NULL,
            target_type VARCHAR(40)  NULL,
            target_id   INT UNSIGNED NULL,
            meta_json   TEXT         NULL,
            ip_address  VARCHAR(45)  NULL,
            user_agent  VARCHAR(255) NULL,
            created_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_audit_family (family_id, created_at),
            KEY idx_audit_user   (user_id,   created_at),
            KEY idx_audit_action (action),
            CONSTRAINT fk_audit_family FOREIGN KEY (family_id) REFERENCES inquiry_families(id) ON DELETE SET NULL,
            CONSTRAINT fk_audit_user   FOREIGN KEY (user_id)   REFERENCES users(id)            ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    END IF;
END //
DELIMITER ;
CALL pr_lg_crm_audit();
DROP PROCEDURE pr_lg_crm_audit;
