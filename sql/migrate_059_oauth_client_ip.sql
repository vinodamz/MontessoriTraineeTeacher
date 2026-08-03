-- ============================================================================
-- migrate_059_oauth_client_ip.sql
--
-- Records which address registered an OAuth client, so the flood guard on
-- /oauth/register.php can do what its comment always claimed: limit per
-- caller rather than globally.
--
-- As shipped, the guard computed an IP hash, threw it away, and counted every
-- registration from everyone. One client retrying could therefore lock out
-- every other client — including itself — for an hour, and the only symptom a
-- user sees is "couldn't register with the sign-in service".
--
-- Hashed, never raw: the same treatment survey submissions and the MCP audit
-- log already give addresses. Enough to rate-limit one caller, not enough to
-- keep identifiable network data about anybody.
--
-- Idempotent — guards on column existence.
-- ============================================================================

SET NAMES utf8mb4;
SET sql_mode = 'STRICT_TRANS_TABLES,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION';

DROP PROCEDURE IF EXISTS pr_lg_oauth_client_ip;
DELIMITER //
CREATE PROCEDURE pr_lg_oauth_client_ip()
BEGIN
    IF NOT EXISTS (SELECT 1 FROM information_schema.columns
                   WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='oauth_clients'
                     AND COLUMN_NAME='ip_hash') THEN
        ALTER TABLE oauth_clients ADD COLUMN ip_hash CHAR(64) NULL AFTER redirect_uris;
        ALTER TABLE oauth_clients ADD KEY idx_oauth_clients_ip (ip_hash, created_at);
    END IF;
END //
DELIMITER ;
CALL pr_lg_oauth_client_ip();
DROP PROCEDURE pr_lg_oauth_client_ip;
