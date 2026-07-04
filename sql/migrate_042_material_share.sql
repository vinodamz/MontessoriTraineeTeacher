-- ============================================================================
-- migrate_042_material_share.sql
--
-- Shareable read-only link for a month's material audit (for Kreedo) with a
-- view log:
--
--   mm_share_links   one row per issued link. Token is the URL secret;
--                    is_active lets the school revoke a link instantly.
--   mm_share_views   one row per page view: the viewer's self-given name
--                    (asked once, kept in a cookie), IP and user agent —
--                    "who all has seen this".
--
-- Idempotent (CREATE TABLE IF NOT EXISTS).
-- ============================================================================

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS mm_share_links (
    id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
    token       CHAR(64)     NOT NULL,
    period      CHAR(7)      NOT NULL,
    label       VARCHAR(120) NOT NULL DEFAULT '',
    is_active   TINYINT(1)   NOT NULL DEFAULT 1,
    created_by_user_id INT UNSIGNED NULL,
    created_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_mm_share_token (token),
    KEY idx_mm_share_period (period),
    CONSTRAINT fk_mm_share_user FOREIGN KEY (created_by_user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS mm_share_views (
    id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
    share_id    INT UNSIGNED NOT NULL,
    viewer_name VARCHAR(120) NOT NULL DEFAULT '',
    ip          VARCHAR(45)  NOT NULL DEFAULT '',
    user_agent  VARCHAR(255) NOT NULL DEFAULT '',
    viewed_at   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_mm_share_views (share_id, viewed_at),
    CONSTRAINT fk_mm_shareview_link FOREIGN KEY (share_id) REFERENCES mm_share_links(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
