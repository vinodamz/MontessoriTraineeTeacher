-- ============================================================================
-- migrate_056_oauth.sql
--
-- OAuth 2.1 for the MCP server: authorization code + PKCE, so a person signs
-- in with their own PIN instead of a shared token being pasted into a config
-- file (or, as happened, into a chat window).
--
--   oauth_clients    — one row per MCP client that has registered itself.
--                      Registration is dynamic (RFC 7591): clients arrive
--                      unannounced and describe themselves, because there is
--                      no way to pre-share an id with software the school has
--                      not installed yet.
--
--                      Public clients (a desktop app that cannot keep a
--                      secret) have secret_hash NULL and are held together by
--                      PKCE alone. Confidential clients store only the hash.
--
--   oauth_auth_codes — the short-lived code handed back through the browser
--                      redirect. Sixty seconds, single use, and bound to the
--                      PKCE challenge, the client and the redirect URI it was
--                      issued for. `used_at` is kept rather than deleted so a
--                      replayed code is *detected* rather than merely failing
--                      to match.
--
--   oauth_tokens     — access and refresh tokens, both stored as SHA-256.
--                      An access token lives an hour; the refresh token
--                      rotates on every use, and `replaced_by` chains the
--                      generations so a stolen-and-reused refresh token is
--                      visible as a fork in the chain.
--
-- mcp_audit gains user_id: with OAuth the log can finally name the person who
-- ran a delete rather than the label somebody typed on a token.
--
-- Idempotent — guards on table and column existence.
-- ============================================================================

SET NAMES utf8mb4;
SET sql_mode = 'STRICT_TRANS_TABLES,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION';

DROP PROCEDURE IF EXISTS pr_lg_oauth;
DELIMITER //
CREATE PROCEDURE pr_lg_oauth()
BEGIN
    IF NOT EXISTS (SELECT 1 FROM information_schema.tables
                   WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='oauth_clients') THEN
        CREATE TABLE oauth_clients (
            id             INT UNSIGNED NOT NULL AUTO_INCREMENT,
            client_id      CHAR(32)     NOT NULL,
            secret_hash    CHAR(64)     NULL,
            client_name    VARCHAR(120) NOT NULL DEFAULT '',
            redirect_uris  TEXT         NOT NULL,
            created_at     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
            last_used_at   DATETIME     NULL,
            disabled_at    DATETIME     NULL,
            PRIMARY KEY (id),
            UNIQUE KEY uq_oauth_clients_cid (client_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    END IF;

    IF NOT EXISTS (SELECT 1 FROM information_schema.tables
                   WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='oauth_auth_codes') THEN
        CREATE TABLE oauth_auth_codes (
            id             INT UNSIGNED NOT NULL AUTO_INCREMENT,
            code_hash      CHAR(64)     NOT NULL,
            client_id      CHAR(32)     NOT NULL,
            user_id        INT UNSIGNED NOT NULL,
            redirect_uri   VARCHAR(500) NOT NULL DEFAULT '',
            code_challenge VARCHAR(200) NOT NULL DEFAULT '',
            scope          VARCHAR(200) NOT NULL DEFAULT '',
            resource       VARCHAR(500) NULL,
            expires_at     DATETIME     NOT NULL,
            used_at        DATETIME     NULL,
            created_at     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uq_oauth_codes_hash (code_hash),
            KEY idx_oauth_codes_exp (expires_at),
            CONSTRAINT fk_oauth_code_user FOREIGN KEY (user_id)
                REFERENCES users(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    END IF;

    IF NOT EXISTS (SELECT 1 FROM information_schema.tables
                   WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='oauth_tokens') THEN
        CREATE TABLE oauth_tokens (
            id                 INT UNSIGNED NOT NULL AUTO_INCREMENT,
            access_hash        CHAR(64)     NOT NULL,
            refresh_hash       CHAR(64)     NULL,
            client_id          CHAR(32)     NOT NULL,
            user_id            INT UNSIGNED NOT NULL,
            scope              VARCHAR(200) NOT NULL DEFAULT '',
            expires_at         DATETIME     NOT NULL,
            refresh_expires_at DATETIME     NULL,
            revoked_at         DATETIME     NULL,
            replaced_by        INT UNSIGNED NULL,
            last_used_at       DATETIME     NULL,
            created_at         DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uq_oauth_tok_access  (access_hash),
            UNIQUE KEY uq_oauth_tok_refresh (refresh_hash),
            KEY idx_oauth_tok_user (user_id, revoked_at),
            KEY idx_oauth_tok_exp  (expires_at),
            CONSTRAINT fk_oauth_tok_user FOREIGN KEY (user_id)
                REFERENCES users(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    END IF;

    -- Who did it, not just which credential did it.
    IF NOT EXISTS (SELECT 1 FROM information_schema.columns
                   WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='mcp_audit'
                     AND COLUMN_NAME='user_id') THEN
        ALTER TABLE mcp_audit ADD COLUMN user_id INT UNSIGNED NULL AFTER token_id;
        ALTER TABLE mcp_audit ADD KEY idx_mcp_audit_user (user_id, created_at);
    END IF;

    IF NOT EXISTS (SELECT 1 FROM information_schema.columns
                   WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='mcp_audit'
                     AND COLUMN_NAME='oauth_token_id') THEN
        ALTER TABLE mcp_audit ADD COLUMN oauth_token_id INT UNSIGNED NULL AFTER user_id;
    END IF;
END //
DELIMITER ;
CALL pr_lg_oauth();
DROP PROCEDURE pr_lg_oauth;
