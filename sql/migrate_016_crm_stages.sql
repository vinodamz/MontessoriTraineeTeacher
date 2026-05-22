-- ============================================================================
-- migrate_016_crm_stages.sql
--
-- Moves the admissions pipeline stages from a hardcoded PHP array +
-- ENUM column into a configurable crm_stages table that an admin can
-- edit at runtime (rename labels, change order, toggle which are "open"
-- in the funnel, deactivate the ones they don't use).
--
-- Steps:
--   1. Create crm_stages with the existing 8 codes seeded so behavior
--      is identical the moment this migration finishes.
--   2. Add two new stages that match Odoo's pipeline:
--        details_shared    (between new and tour_scheduled)
--        school_visited    (after tour_scheduled, before offered)
--   3. Change inquiry_families.status from ENUM to VARCHAR(40) so any
--      future stage codes admins create just work — no schema change.
--   4. Re-map already-imported Odoo families to the two new codes:
--        Odoo "Details Shared" rows currently sit on 'tour_scheduled'
--        Odoo "School Visited" rows currently sit on 'offered'
--      so the pipeline mirrors the Odoo workflow the team is used to.
--
-- Idempotent — every step is gated on information_schema / row presence.
-- ============================================================================

SET NAMES utf8mb4;
SET sql_mode = 'STRICT_TRANS_TABLES,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION';

DROP PROCEDURE IF EXISTS pr_lg_crm_stages;
DELIMITER //
CREATE PROCEDURE pr_lg_crm_stages()
BEGIN
    -- 1. crm_stages — admin-editable pipeline stages.
    IF NOT EXISTS (SELECT 1 FROM information_schema.tables
                   WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='crm_stages') THEN
        CREATE TABLE crm_stages (
            id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
            code          VARCHAR(40)  NOT NULL,
            label         VARCHAR(60)  NOT NULL,
            display_order INT          NOT NULL DEFAULT 0,
            probability   TINYINT UNSIGNED NOT NULL DEFAULT 20,
            is_open       TINYINT(1)   NOT NULL DEFAULT 1,
            is_active     TINYINT(1)   NOT NULL DEFAULT 1,
            created_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP
                          ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uq_stage_code  (code),
            KEY        idx_stage_order (display_order)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    END IF;

    -- 2. Seed the 8 existing codes if missing, then the 2 new ones.
    --    INSERT IGNORE works because (code) is UNIQUE.
    INSERT IGNORE INTO crm_stages (code, label, display_order, probability, is_open) VALUES
        ('lead',                  'Leads',                 10,  10, 1),
        ('new',                   'New inquiry',           20,  20, 1),
        ('details_shared',        'Details shared',        30,  35, 1),
        ('tour_scheduled',        'Tour scheduled',        40,  45, 1),
        ('school_visited',        'School visited',        50,  60, 1),
        ('application_submitted', 'Application submitted', 60,  70, 1),
        ('offered',               'Offered',               70,  85, 1),
        ('enrolled',              'Enrolled',              80, 100, 0),
        ('waitlisted',            'Waitlisted',            90,  25, 1),
        ('lost',                  'Lost',                 100,   0, 0);

    -- 3. Loosen inquiry_families.status from ENUM to VARCHAR(40) — only
    --    if it's still an ENUM. This widens the type so an admin can
    --    add new stage codes from the UI without a schema change.
    IF EXISTS (
        SELECT 1 FROM information_schema.columns
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME   = 'inquiry_families'
          AND COLUMN_NAME  = 'status'
          AND DATA_TYPE    = 'enum'
    ) THEN
        ALTER TABLE inquiry_families
            MODIFY status VARCHAR(40) NOT NULL DEFAULT 'new';
    END IF;

    -- 4. Re-map already-imported Odoo families onto the new stage codes
    --    by reading the breadcrumb the importer left in the notes field.
    --    The breadcrumb format is: 'stage "<odoo stage label>"'.
    UPDATE inquiry_families
    SET    status = 'details_shared'
    WHERE  status = 'tour_scheduled'
      AND  notes LIKE '%stage "Details Shared"%';

    UPDATE inquiry_families
    SET    status = 'school_visited'
    WHERE  status = 'offered'
      AND  notes LIKE '%stage "School Visited"%';
END //
DELIMITER ;
CALL pr_lg_crm_stages();
DROP PROCEDURE pr_lg_crm_stages;
