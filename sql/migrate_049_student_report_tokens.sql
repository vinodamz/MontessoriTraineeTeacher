-- ============================================================================
-- migrate_049_student_report_tokens.sql
--
-- Powers a public, per-child "detailed progress report" link. A teacher or
-- admin generates the link on the report page and shares it with the family;
-- parents open it with no school login to read the full report.
--
-- Read-only by design — the token grants viewing of one child's report and
-- nothing else. Mirrors student_form_tokens (migrate_028) / staff_form_tokens
-- (migrate_047). Stays valid until revoked.
--
-- Idempotent — CREATE TABLE IF NOT EXISTS.
-- ============================================================================

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS student_report_tokens (
    id                  INT UNSIGNED NOT NULL AUTO_INCREMENT,
    student_id          INT UNSIGNED NOT NULL,
    token               CHAR(64)     NOT NULL,
    created_by_user_id  INT UNSIGNED NOT NULL,
    created_at          DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    last_accessed_at    DATETIME     NULL,
    view_count          INT UNSIGNED NOT NULL DEFAULT 0,
    revoked_at          DATETIME     NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_srt_token   (token),
    KEY        idx_srt_student (student_id, revoked_at),
    CONSTRAINT fk_srt_student FOREIGN KEY (student_id)         REFERENCES students(id) ON DELETE CASCADE,
    CONSTRAINT fk_srt_creator FOREIGN KEY (created_by_user_id) REFERENCES users(id)    ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
