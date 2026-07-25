-- ============================================================================
-- migrate_047_staff_form_tokens.sql
--
-- Powers a public, per-staff "application form" link. An admin generates a
-- link on a staff member's profile and shares it; the staff member opens it
-- (no school login) to fill in their personal details and upload their photo,
-- ID proofs, certificates and experience letter.
--
-- The token in the URL is the sole credential — mirrors student_form_tokens
-- (migrate_028). Stays valid until an admin revokes it.
--
-- Idempotent — CREATE TABLE IF NOT EXISTS.
-- ============================================================================

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS staff_form_tokens (
    id                  INT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id             INT UNSIGNED NOT NULL,
    token               CHAR(64)     NOT NULL,
    created_by_user_id  INT UNSIGNED NOT NULL,
    created_at          DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    last_accessed_at    DATETIME     NULL,
    last_saved_at       DATETIME     NULL,
    revoked_at          DATETIME     NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_sftk_token (token),
    KEY        idx_sftk_user (user_id, revoked_at),
    CONSTRAINT fk_sftk_user    FOREIGN KEY (user_id)            REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT fk_sftk_creator FOREIGN KEY (created_by_user_id) REFERENCES users(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
