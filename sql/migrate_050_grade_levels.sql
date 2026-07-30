-- ============================================================================
-- migrate_050_grade_levels.sql
--
-- Makes the grade list configurable instead of hard-coded, and adds Daycare.
--
-- Before this, the five-ish grade names lived in three ENUM columns and in
-- ~20 PHP files (validators, dropdowns, FIELD() sort orders, counter buckets).
-- Adding one grade meant editing all of them, which is exactly the change this
-- migration is meant to be the last of.
--
-- Two parts:
--   1. New grade_levels table — the single source of truth. Admins manage it at
--      /grades.php: name, display label, order, whether it's active, and which
--      grade it promotes to at the June year-end rollover.
--   2. The three columns that stored a grade become VARCHAR. They were ENUMs,
--      which would have made a config page pointless — adding a grade in the UI
--      would fail on insert because the ENUM wouldn't know the new value.
--
-- ENUM -> VARCHAR keeps the stored text as-is (values convert to their labels),
-- so no data is rewritten. Sort order now comes from grade_levels.sort_order via
-- FIELD() rather than from the ENUM's internal ordering.
--
-- Idempotent — information_schema guards throughout.
-- ============================================================================

SET NAMES utf8mb4;
SET sql_mode = 'STRICT_TRANS_TABLES,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION';

CREATE TABLE IF NOT EXISTS grade_levels (
    id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
    name        VARCHAR(40)  NOT NULL,          -- the value stored on students.grade
    label       VARCHAR(80)  NOT NULL,          -- what people see
    sort_order  INT          NOT NULL DEFAULT 0,
    promotes_to VARCHAR(40)  NULL,              -- grade at year-end; NULL = stays put / graduates
    is_active   TINYINT(1)   NOT NULL DEFAULT 1,
    created_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_grade_name (name),
    KEY idx_grade_order (sort_order)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

DROP PROCEDURE IF EXISTS pr_lg_grade_levels;
DELIMITER //
CREATE PROCEDURE pr_lg_grade_levels()
BEGIN
    -- ---- 1. Seed the existing ladder plus Daycare ------------------------
    -- Daycare sits first (youngest) and promotes to nothing: daycare children
    -- stay put at the rollover rather than being swept into Playgroup.
    IF NOT EXISTS (SELECT 1 FROM grade_levels) THEN
        INSERT INTO grade_levels (name, label, sort_order, promotes_to, is_active) VALUES
            ('Daycare',   'Daycare',   10, NULL,        1),
            ('Playgroup', 'Playgroup', 20, 'Nursery',   1),
            ('Nursery',   'Nursery',   30, 'LKG',       1),
            ('LKG',       'LKG',       40, 'UKG',       1),
            ('UKG',       'UKG',       50, NULL,        1);
    ELSEIF NOT EXISTS (SELECT 1 FROM grade_levels WHERE name = 'Daycare') THEN
        -- Table already populated (re-run after a manual edit): just add Daycare.
        INSERT INTO grade_levels (name, label, sort_order, promotes_to, is_active)
        VALUES ('Daycare', 'Daycare', 10, NULL, 1);
    END IF;

    -- ---- 2. ENUM -> VARCHAR so new grades can actually be stored ---------
    IF EXISTS (SELECT 1 FROM information_schema.columns
               WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='students'
                 AND COLUMN_NAME='grade' AND DATA_TYPE='enum') THEN
        ALTER TABLE students MODIFY grade VARCHAR(40) NOT NULL;
    END IF;

    IF EXISTS (SELECT 1 FROM information_schema.columns
               WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='skill_indicators'
                 AND COLUMN_NAME='grade' AND DATA_TYPE='enum') THEN
        ALTER TABLE skill_indicators MODIFY grade VARCHAR(40) NOT NULL;
    END IF;

    IF EXISTS (SELECT 1 FROM information_schema.tables
               WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='inquiry_children') THEN
        IF EXISTS (SELECT 1 FROM information_schema.columns
                   WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='inquiry_children'
                     AND COLUMN_NAME='target_grade' AND DATA_TYPE='enum') THEN
            ALTER TABLE inquiry_children MODIFY target_grade VARCHAR(40) NULL;
        END IF;
    END IF;
END //
DELIMITER ;
CALL pr_lg_grade_levels();
DROP PROCEDURE pr_lg_grade_levels;
