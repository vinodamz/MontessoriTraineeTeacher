-- ============================================================================
-- migrate_019_crm_tags.sql
--
-- Adds a tagging system to the admissions pipeline + rule-based
-- probability auto-calculation.
--
-- Tags: short labels (e.g. "Nearby", "Fee agreed", "Visit confirmed",
-- "Speech delay", "Sibling") that the team attaches to inquiries. Each
-- inquiry can carry multiple tags. Tags are visible on kanban cards as
-- small colored pills and filterable on the leads list.
--
-- Probability rules: admin-defined logic like "if inquiry has tags
-- [nearby, fee_agreed, visit_confirmed] → probability = 85%". Rules
-- are evaluated in priority order when tags change; the highest-
-- priority matching rule's target_probability is applied automatically.
-- If no rule matches the inquiry's probability is left untouched.
--
-- Idempotent — all guarded on information_schema.
-- ============================================================================

SET NAMES utf8mb4;
SET sql_mode = 'STRICT_TRANS_TABLES,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION';

DROP PROCEDURE IF EXISTS pr_lg_crm_tags;
DELIMITER //
CREATE PROCEDURE pr_lg_crm_tags()
BEGIN
    -- 1. crm_tags — the tag library managed from /crm/tags.php.
    IF NOT EXISTS (SELECT 1 FROM information_schema.tables
                   WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='crm_tags') THEN
        CREATE TABLE crm_tags (
            id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
            name          VARCHAR(40)  NOT NULL,
            color         VARCHAR(7)   NOT NULL DEFAULT '#6b7280',
            display_order INT          NOT NULL DEFAULT 0,
            is_active     TINYINT(1)   NOT NULL DEFAULT 1,
            created_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uq_tag_name (name),
            KEY idx_tag_order (display_order)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

        INSERT INTO crm_tags (name, color, display_order) VALUES
            ('Nearby',           '#2563eb', 10),
            ('Fee agreed',       '#16a34a', 20),
            ('Visit confirmed',  '#9333ea', 30),
            ('Speech delay',     '#ea580c', 40),
            ('Sibling',          '#0891b2', 50),
            ('Not interested',   '#dc2626', 60),
            ('Callback later',   '#ca8a04', 70);
    END IF;

    -- 2. inquiry_family_tags — many-to-many junction.
    IF NOT EXISTS (SELECT 1 FROM information_schema.tables
                   WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='inquiry_family_tags') THEN
        CREATE TABLE inquiry_family_tags (
            family_id   INT UNSIGNED NOT NULL,
            tag_id      INT UNSIGNED NOT NULL,
            tagged_at   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (family_id, tag_id),
            KEY idx_ift_tag (tag_id),
            CONSTRAINT fk_ift_family FOREIGN KEY (family_id) REFERENCES inquiry_families(id) ON DELETE CASCADE,
            CONSTRAINT fk_ift_tag    FOREIGN KEY (tag_id)    REFERENCES crm_tags(id)          ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    END IF;

    -- 3. crm_probability_rules — admin-managed rules for auto-setting probability.
    --    required_tag_ids is a comma-separated list of tag IDs that must ALL be
    --    present on the inquiry for the rule to fire.
    IF NOT EXISTS (SELECT 1 FROM information_schema.tables
                   WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='crm_probability_rules') THEN
        CREATE TABLE crm_probability_rules (
            id                INT UNSIGNED    NOT NULL AUTO_INCREMENT,
            name              VARCHAR(80)     NOT NULL,
            required_tag_ids  VARCHAR(200)    NOT NULL,
            target_probability TINYINT UNSIGNED NOT NULL DEFAULT 50,
            display_order     INT             NOT NULL DEFAULT 0,
            is_active         TINYINT(1)      NOT NULL DEFAULT 1,
            created_at        DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_cpr_order (display_order)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    END IF;
END //
DELIMITER ;
CALL pr_lg_crm_tags();
DROP PROCEDURE pr_lg_crm_tags;
