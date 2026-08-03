-- ============================================================================
-- migrate_060_mcp_debug_log.sql
--
-- A short-lived recorder for whatever actually arrives at /mcp.php.
--
-- The MCP audit log only records calls that got as far as running a tool, so
-- it is silent about every request that fails before authentication — which
-- is exactly the class of failure that leaves a client saying "couldn't
-- connect to the server" with nothing to go on at either end.
--
-- This table answers the only question that matters when a client will not
-- connect: did the request arrive at all, and what did it look like? Method,
-- headers, body, and the status we sent back.
--
-- Recording is OFF unless an admin turns it on, and it turns itself off
-- again: `mcp_debug_until` in app_settings holds an expiry timestamp, not a
-- boolean, so a debugging session that somebody forgets about stops on its
-- own rather than quietly logging request bodies for a year.
--
-- The Authorization header value is never stored — only whether one was
-- present and how long it was. A debug log that captures live credentials is
-- a worse problem than the one it was opened to solve.
--
-- Idempotent — guards on table existence.
-- ============================================================================

SET NAMES utf8mb4;
SET sql_mode = 'STRICT_TRANS_TABLES,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION';

DROP PROCEDURE IF EXISTS pr_lg_mcp_debug_log;
DELIMITER //
CREATE PROCEDURE pr_lg_mcp_debug_log()
BEGIN
    IF NOT EXISTS (SELECT 1 FROM information_schema.tables
                   WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='mcp_debug_log') THEN
        CREATE TABLE mcp_debug_log (
            id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
            method      VARCHAR(10)  NOT NULL DEFAULT '',
            path        VARCHAR(255) NOT NULL DEFAULT '',
            headers     TEXT         NULL,
            body        TEXT         NULL,
            status      SMALLINT     NOT NULL DEFAULT 0,
            note        VARCHAR(255) NULL,
            ip_hash     CHAR(64)     NULL,
            created_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_mdl_time (created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    END IF;
END //
DELIMITER ;
CALL pr_lg_mcp_debug_log();
DROP PROCEDURE pr_lg_mcp_debug_log;
