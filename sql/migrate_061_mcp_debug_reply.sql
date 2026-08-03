-- ============================================================================
-- migrate_061_mcp_debug_reply.sql
--
-- Two things the recorder should have captured from the start.
--
-- `path` was VARCHAR(255), which is shorter than a real OAuth authorize URL.
-- The tail of one — where `state` lives — was being cut off, and a truncated
-- state looks exactly like a state the server mangled. That cost an hour of
-- chasing a bug that did not exist. 1000 covers the longest request this
-- server ever receives, with room to spare.
--
-- `reply` is new, and is the field that actually matters. A status code alone
-- cannot tell a successful authorization from a refusal: both are 302. What
-- separates them is the Location header — `?code=…` or `?error=…` — and
-- without it the log says a redirect happened and nothing about which way it
-- went. The code and token values in it are masked before storage; what is
-- kept is the shape.
--
-- Idempotent — guards on column existence.
-- ============================================================================

SET NAMES utf8mb4;
SET sql_mode = 'STRICT_TRANS_TABLES,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION';

DROP PROCEDURE IF EXISTS pr_lg_mcp_debug_reply;
DELIMITER //
CREATE PROCEDURE pr_lg_mcp_debug_reply()
BEGIN
    IF EXISTS (SELECT 1 FROM information_schema.tables
               WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='mcp_debug_log') THEN

        IF EXISTS (SELECT 1 FROM information_schema.columns
                   WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='mcp_debug_log'
                     AND COLUMN_NAME='path' AND CHARACTER_MAXIMUM_LENGTH < 1000) THEN
            ALTER TABLE mcp_debug_log MODIFY path VARCHAR(1000) NOT NULL DEFAULT '';
        END IF;

        IF NOT EXISTS (SELECT 1 FROM information_schema.columns
                       WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='mcp_debug_log'
                         AND COLUMN_NAME='reply') THEN
            ALTER TABLE mcp_debug_log ADD COLUMN reply VARCHAR(1000) NULL AFTER status;
        END IF;
    END IF;
END //
DELIMITER ;
CALL pr_lg_mcp_debug_reply();
DROP PROCEDURE pr_lg_mcp_debug_reply;
