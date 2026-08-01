-- ============================================================================
-- migrate_055_mcp_api.sql
--
-- MCP server: the credentials that open the door, and the log of everything
-- that came through it.
--
--   mcp_tokens — one row per issued bearer token. Only the SHA-256 of the
--                token is stored, never the token itself: minting shows it
--                once and it is unrecoverable afterwards, so a database dump
--                does not hand over live API access. `label` is what the
--                admin page shows ("Vinod's laptop"), because a list of
--                indistinguishable hashes is impossible to revoke safely.
--
--                Revocation is a timestamp, not a DELETE — a revoked token's
--                audit rows must keep pointing at the credential that made
--                them.
--
--   mcp_audit  — one row per tool call. This server has full read/write over
--                every table, so the log is the only way to answer "what did
--                it do, and when". It records the tool, the arguments, the
--                outcome, and for writes the BEFORE image of every row
--                touched, which is what makes an accidental UPDATE reversible.
--
--                `before_image` is LONGTEXT JSON rather than the JSON type,
--                matching migrate_054's reasoning: nothing queries inside it,
--                and LONGTEXT works on every MySQL/MariaDB version this app
--                might land on.
--
-- The audit table is deliberately NOT reachable by the generic write tools
-- (enforced in includes/mcp.php, not here) — a log the audited party can
-- erase is not a log.
--
-- Idempotent — guards on table existence.
-- ============================================================================

SET NAMES utf8mb4;
SET sql_mode = 'STRICT_TRANS_TABLES,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION';

DROP PROCEDURE IF EXISTS pr_lg_mcp_api;
DELIMITER //
CREATE PROCEDURE pr_lg_mcp_api()
BEGIN
    IF NOT EXISTS (SELECT 1 FROM information_schema.tables
                   WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='mcp_tokens') THEN
        CREATE TABLE mcp_tokens (
            id           INT UNSIGNED NOT NULL AUTO_INCREMENT,
            label        VARCHAR(80)  NOT NULL DEFAULT '',
            token_hash   CHAR(64)     NOT NULL,
            created_by   INT UNSIGNED NULL,
            created_at   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
            last_used_at DATETIME     NULL,
            revoked_at   DATETIME     NULL,
            PRIMARY KEY (id),
            UNIQUE KEY uq_mcp_tokens_hash (token_hash),
            KEY idx_mcp_tokens_live (revoked_at),
            CONSTRAINT fk_mcp_token_creator FOREIGN KEY (created_by)
                REFERENCES users(id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    END IF;

    IF NOT EXISTS (SELECT 1 FROM information_schema.tables
                   WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='mcp_audit') THEN
        CREATE TABLE mcp_audit (
            id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
            token_id      INT UNSIGNED NULL,
            tool          VARCHAR(60)  NOT NULL DEFAULT '',
            arguments     LONGTEXT     NULL,
            ok            TINYINT(1)   NOT NULL DEFAULT 0,
            error         VARCHAR(500) NULL,
            rows_affected INT          NOT NULL DEFAULT 0,
            before_image  LONGTEXT     NULL,
            ip_hash       CHAR(64)     NULL,
            created_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_mcp_audit_time  (created_at),
            KEY idx_mcp_audit_token (token_id, created_at),
            KEY idx_mcp_audit_tool  (tool),
            CONSTRAINT fk_mcp_audit_token FOREIGN KEY (token_id)
                REFERENCES mcp_tokens(id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    END IF;
END //
DELIMITER ;
CALL pr_lg_mcp_api();
DROP PROCEDURE pr_lg_mcp_api;
