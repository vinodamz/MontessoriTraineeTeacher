-- ============================================================================
-- migrate_043_remember_me.sql
--
-- "Remember this device" tokens so teachers stop being asked to log in every
-- ~24 minutes (PHP session GC on shared hosting) or every time the phone
-- browser is closed. Selector/validator pattern: the cookie carries
-- selector + plaintext validator; the DB stores only the validator's
-- sha256, so a leaked table can't impersonate anyone. Validator rotates on
-- every use; tokens live 30 days from last use, capped per user.
--
-- Idempotent (CREATE TABLE IF NOT EXISTS).
-- ============================================================================

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS auth_tokens (
    id             INT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id        INT UNSIGNED NOT NULL,
    selector       CHAR(24)     NOT NULL,
    validator_hash CHAR(64)     NOT NULL,
    expires_at     DATETIME     NOT NULL,
    created_at     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    last_used_at   DATETIME     NULL,
    user_agent     VARCHAR(190) NOT NULL DEFAULT '',
    PRIMARY KEY (id),
    UNIQUE KEY uq_auth_selector (selector),
    KEY idx_auth_user (user_id),
    KEY idx_auth_expiry (expires_at),
    CONSTRAINT fk_auth_tokens_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
